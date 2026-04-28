<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\KnownUsersStore;
use App\Services\LoggerService;

$config = require __DIR__ . '/../config/config.php';
$dataPath = $config['log_path'] ?? (__DIR__ . '/../data');

$logger = new LoggerService($dataPath);
$store = new KnownUsersStore($dataPath, $logger);
$stats = $store->backfillFromAvailableSources(true);

echo "Known users backfill completed\n";
echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
