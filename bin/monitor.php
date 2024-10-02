<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();

use React\Async;
use ccxt\Precise;

$pairsFile = __DIR__ . '/../pairs.json';
$pairs = json_decode(file_get_contents($pairsFile), true);

$exchangeName = $_ENV['EXCHANGE'];
$exchangeClass = "\\ccxt\\pro\\$exchangeName";
if (!class_exists($exchangeClass)) {
    throw new \Exception("Unsupported exchange: $exchangeName");
}
$exchange = new $exchangeClass(array());

$redis = new Redis();
$redis->connect($_ENV['REDIS_IP'], $_ENV['REDIS_PORT']);

function monitorPairs($exchangeName, $exchange, $pairs, $redis) {
    return Async\async(function () use ($exchangeName, $exchange, $pairs, $redis) {

        $supportedPairs = Async\await($exchange->load_markets());
        $validPairs = array_filter($pairs, function($pair) use ($supportedPairs) {
            return isset($supportedPairs[$pair]);
        });

        if (empty($validPairs)) {
            echo "No valid pairs found for $exchangeName\n";
            return;
        }

        echo "Monitoring " . count($validPairs) . " pairs for $exchangeName\n";
        $validPairs = array_values($validPairs);

        while (true) {
            $tickers = Async\await($exchange->watch_tickers($validPairs));

            $redis->publish("tickers:$exchangeName", json_encode(
                [
                    "exchange" => $exchangeName,
                    "tickers" => $tickers
                ]
            )); // can be improved by using messagePack instead of json

            // var_dump($tickers);
            // var_dump('//');
            // $btcEx = isset($tickers['BTC/USDT']);
            // $ethEx = isset($tickers['ETH/USDT']);
            // var_dump("BTC: {$btcEx}");
            // var_dump("ETH: {$ethEx}");
        }
    })();
}

echo "Starting monitoring {$exchangeName}...\n";
Async\await(monitorPairs($exchangeName, $exchange, $pairs, $redis));
