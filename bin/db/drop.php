<?php
require __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/../..");
$dotenv->load();

use Bin\Db\DatabaseService;

$dbService = new DatabaseService();
$dbService->cleanupDatabase();