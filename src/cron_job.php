<?php
// src/cron_job.php

// Purpose: This script is intended to be run by a cron job.
// It focuses on tasks that need periodic execution, like sending daily summaries.

require_once __DIR__ . '/../vendor/autoload.php';

use App\Bot;
use Dotenv\Dotenv;

// Define lock file path
$lockFile = __DIR__ . '/../data/cron_job.lock';
$lockTimeout = 3600; // 1 hour timeout for lock file (in seconds)

// Check if another instance is already running
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    $currentTime = time();

    // If the lock file is older than the timeout, it's probably a stale lock
    if ($currentTime - $lockTime < $lockTimeout) {
        echo "[" . date('Y-m-d H:i:s') . "] Another cron job instance is already running. Exiting.\n";
        exit(0); // Exit gracefully
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] Found stale lock file. Removing it.\n";
        unlink($lockFile); // Remove stale lock
    }
}

// Create lock file
file_put_contents($lockFile, date('Y-m-d H:i:s'));
echo "[" . date('Y-m-d H:i:s') . "] Lock acquired.\n";

// Register shutdown function to remove lock file when script ends
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
        echo "[" . date('Y-m-d H:i:s') . "] Lock released.\n";
    }
});

// Load environment variables from .env file if it exists
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    $configDistPath = __DIR__ . '/../config/config.php.dist';
    if (file_exists($configDistPath)) {
        copy($configDistPath, $configPath);
        echo "Configuration file created at {$configPath}. Update it if needed.\n";
    } else {
        die('Error: Configuration file not found.');
    }
}
$config = require $configPath;

// Validate essential configuration
if (empty($config['telegram_bot_token']) || $config['telegram_bot_token'] === 'YOUR_TELEGRAM_BOT_TOKEN') {
    error_log('CRON ERROR: Telegram Bot Token is not configured.');
    exit(1);
}
echo "[" . date('Y-m-d H:i:s') . "] Cron job started.\n";

try {
    // Instantiate the bot - We need access to its methods but won't run the main loop
    $bot = new Bot($config);

    // --- Execute Cron-Specific Tasks ---

    // 1. Trigger the check for sending daily summaries
    echo "[" . date('Y-m-d H:i:s') . "] Checking for daily summaries to send...\n";
    // We need to make sendDailySummaries public or create a dedicated public method
    // Let's modify Bot class slightly for this.
    $bot->checkAndSendDailySummaries(); // Assuming we add this public method

    // 2. Trigger message cleanup
    echo "[" . date('Y-m-d H:i:s') . "] Cleaning up old messages...\n";
     // Make cleanupOldMessages public or add a public trigger
    $bot->triggerCleanup(); // Assuming we add this public method

    // Note: We are NOT calling $bot->run() here as that starts the polling loop.

    echo "[" . date('Y-m-d H:i:s') . "] Cron job finished successfully.\n";

} catch (\Throwable $e) {
    error_log('CRON ERROR: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());
    echo "[" . date('Y-m-d H:i:s') . "] Cron job failed.\n";
    exit(1); // Exit with an error code
}

exit(0); // Success
