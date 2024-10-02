<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();

$dbService = new \Bin\Db\DatabaseService();
$dbService->setupDatabase();
$pdo = $dbService->getPDO();

// Make sure DB timezone matches PHP timezone, or timescaledb's 'continuous aggregates' won't trigger
// date_default_timezone_set('Asia/Seoul');
// echo "Default timezone: " . date_default_timezone_get() . "\n";

$prevData = [];
$currentData = [];
$lastSavedTimestamp = 0;

$dataForDb = [];
$lastDbInsertTimestamp = 0;

$exchangeName = $_ENV['EXCHANGE'];

$redis = new Redis();
$redis->connect($_ENV['REDIS_IP'], $_ENV['REDIS_PORT']);

function processData($tickers) {
    global $currentData, $lastSavedTimestamp;

    $firstTicker = reset($tickers);
    if ($firstTicker === false) {
        return;
    }

    $currentTimestamp = $firstTicker['timestamp'];
    if ($currentTimestamp - $lastSavedTimestamp >= $_ENV['TICK_INTERVAL']) {
        saveAggregatedData($currentTimestamp);
        $lastSavedTimestamp = $currentTimestamp;
    }

    foreach ($tickers as $symbol => $ticker) {
        if (!isset($currentData[$symbol])) {
            $currentData[$symbol] = [
                'bid_min' => PHP_FLOAT_MAX,
                'bid_max' => 0,
                'ask_min' => PHP_FLOAT_MAX,
                'ask_max' => 0,
            ];
        }

        $currentData[$symbol]['bid_min'] = min($currentData[$symbol]['bid_min'], $ticker['bid']);
        $currentData[$symbol]['bid_max'] = max($currentData[$symbol]['bid_max'], $ticker['bid']);
        $currentData[$symbol]['ask_min'] = min($currentData[$symbol]['ask_min'], $ticker['ask']);
        $currentData[$symbol]['ask_max'] = max($currentData[$symbol]['ask_max'], $ticker['ask']);
    }
}

function saveToDatabaseBatch($pdo, $data) {
    $placeholders = rtrim(str_repeat('(?,?,?,?,?,?),', count($data)), ',');
    $sql = "INSERT INTO ticker_data 
                (time, symbol, bid_min, bid_max, ask_min, ask_max) 
            VALUES " . $placeholders;

    $values = [];
    foreach ($data as $row) {
        $values[] = $row['datetime'];
        $values[] = $row['symbol'];
        $values[] = $row['bid_min'];
        $values[] = $row['bid_max'];
        $values[] = $row['ask_min'];
        $values[] = $row['ask_max'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function saveAggregatedData($timestamp) {
    global $prevData, $currentData, $dataForDb, $lastDbInsertTimestamp, $pdo;

    if (!empty($currentData)) {
        $datetime = date('Y-m-d H:i:s', intval($timestamp / 1000));

        foreach ($currentData as $symbol => $data) {

            if (
                !isset($prevData[$symbol])
                || $data['bid_min'] != $prevData[$symbol]['bid_min']
                || $data['bid_max'] != $prevData[$symbol]['bid_max']
                || $data['ask_min'] != $prevData[$symbol]['ask_min']
                || $data['ask_max'] != $prevData[$symbol]['ask_max']
            ) {
                // echo "Saving aggregated data for $symbol at $datetime\n";
                $dataForDb[] = [
                    'symbol' => $symbol,
                    'datetime' => $datetime,
                    ...$data
                ];

                $prevData[$symbol] = $data;
            }
        }

        if (
            !empty($dataForDb)
            && ($timestamp - $lastDbInsertTimestamp >= $_ENV['DB_BATCH_INSERT_INTERVAL'])
        ) {
            echo "Saving to DB at $datetime\n";
            saveToDatabaseBatch($pdo, $dataForDb);
            $dataForDb = [];
            $lastDbInsertTimestamp = $timestamp;
        }

        $currentData = [];
    }
}


echo "Starting aggregating {$exchangeName}...\n";
$redis->subscribe(["tickers:$exchangeName"], function ($pattern, $channel, $message) {
    processData(json_decode($message, true)['tickers']);
});
