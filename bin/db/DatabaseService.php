<?php

namespace Bin\Db;

use PDO;
use PDOException;

class DatabaseService
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = $this->getPDO();
    }

    public function getPDO(): PDO
    {
        try {
            $pdo = new PDO(
                "pgsql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']}",
                $_ENV['DB_USER'],
                $_ENV['DB_PASSWORD']
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return $pdo;
            
        } catch (PDOException $e) {
            die("Error connecting to the database: " . $e->getMessage());
        }
    }

    public function setupDatabase()
    {
        try {
            $tableName = $_ENV['TICKER_TABLE_NAME'];

            // Check if table exists
            $stmt = $this->pdo->query("SELECT to_regclass('public.$tableName')");
            $tableExists = $stmt->fetchColumn();

            if ($tableExists) {
                echo "Table already exists.\n";
                return;
            }

            // Main table setup
            $this->createMainTable($tableName);

            // 1m view setup
            $this->createMinuteView($tableName);

            // 1h view setup
            $this->createHourView($tableName);

            echo "Database successfully initialized.\n";

        } catch (PDOException $e) {
            die("Error in database setup: " . $e->getMessage());
        }
    }

    private function createMainTable($tableName)
    {
        // Create the main table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS $tableName (
            time TIMESTAMPTZ NOT NULL,
            symbol TEXT NOT NULL,
            bid_min DECIMAL,
            bid_max DECIMAL,
            ask_min DECIMAL,
            ask_max DECIMAL
        )");

        // Convert to hypertable
        $this->pdo->exec("SELECT create_hypertable('$tableName', 'time', 
                    partitioning_column => 'symbol', 
                    number_partitions => 10, 
                    chunk_time_interval => INTERVAL '1 day',
                    if_not_exists => TRUE)");

        // Create index
        $this->pdo->exec("CREATE INDEX ON $tableName (symbol, time DESC)");

        // Add retention policy
        $this->pdo->exec("SELECT add_retention_policy('$tableName', INTERVAL '1 day')");
    }

    private function createMinuteView($tableName)
    {
        // Create materialized view for 1-minute data
        $this->pdo->exec("CREATE MATERIALIZED VIEW {$tableName}_1m
            WITH (timescaledb.continuous)
            AS SELECT time_bucket(INTERVAL '1 minute', time) AS bucket,
                    symbol,
                    MIN(bid_min) AS bid_min,
                    MAX(bid_max) AS bid_max,
                    MIN(ask_min) AS ask_min,
                    MAX(ask_max) AS ask_max
            FROM $tableName
            GROUP BY bucket, symbol
            WITH NO DATA");

        // Set continuous aggregate policy
        $this->pdo->exec("SELECT add_continuous_aggregate_policy('{$tableName}_1m',
            start_offset => INTERVAL '3 minutes',
            end_offset => INTERVAL '1 minute',
            schedule_interval => INTERVAL '1 minute',
            if_not_exists => TRUE)");

        // Add retention policy
        $this->pdo->exec("SELECT add_retention_policy('{$tableName}_1m', INTERVAL '7 days')");

        // Create index
        $this->pdo->exec("CREATE INDEX ON {$tableName}_1m (symbol, bucket DESC)");
    }

    private function createHourView($tableName)
    {
        // Create materialized view for 1-hour data
        $this->pdo->exec("CREATE MATERIALIZED VIEW {$tableName}_1h
            WITH (timescaledb.continuous)
            AS SELECT time_bucket(INTERVAL '1 hour', time) AS bucket,
                    symbol,
                    MIN(bid_min) AS bid_min,
                    MAX(bid_max) AS bid_max,
                    MIN(ask_min) AS ask_min,
                    MAX(ask_max) AS ask_max
            FROM $tableName
            GROUP BY bucket, symbol
            WITH NO DATA");

        // Set continuous aggregate policy
        $this->pdo->exec("SELECT add_continuous_aggregate_policy('{$tableName}_1h',
            start_offset => INTERVAL '3 hours',
            end_offset => INTERVAL '1 hour',
            schedule_interval => INTERVAL '1 hour',
            if_not_exists => TRUE)");

        // Create index
        $this->pdo->exec("CREATE INDEX ON {$tableName}_1h (symbol, bucket DESC)");
    }

    public function cleanupDatabase()
    {
        $tableName = $_ENV['TICKER_TABLE_NAME'];
        $minuteTable = "{$tableName}_1m";
        $hourTable = "{$tableName}_1h";

        $commands = [
            "DROP MATERIALIZED VIEW IF EXISTS $minuteTable",
            "DROP MATERIALIZED VIEW IF EXISTS $hourTable",
            "DROP TABLE IF EXISTS $tableName",
            "SELECT drop_chunks(table_name, newer_than => '-infinity'::timestamp)
             FROM timescaledb_information.hypertables
             WHERE table_name LIKE '%$tableName%'",
            "SELECT remove_retention_policy('$tableName', if_exists => true)",
            "SELECT remove_retention_policy('$minuteTable', if_exists => true)",
            "SELECT remove_retention_policy('$hourTable', if_exists => true)",
            "SELECT remove_continuous_aggregate_policy('$minuteTable', if_exists => true)",
            "SELECT remove_continuous_aggregate_policy('$hourTable', if_exists => true)"
        ];

        foreach ($commands as $command) {
            try {
                $this->pdo->exec($command);
                echo "Executed: $command\n";
            } catch (PDOException $e) {
                echo "Error executing: $command\n";
                echo "Error message: " . $e->getMessage() . "\n";
            }
        }
    }
}