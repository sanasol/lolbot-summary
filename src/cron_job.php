<?php
// src/cron_job.php

// Purpose: This script is intended to be run by a cron job.
// It focuses on tasks that need periodic execution, like sending daily summaries.

require_once __DIR__ . '/../vendor/autoload.php';

use App\Bot;
use Dotenv\Dotenv;

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
if (empty($config['deepseek_api_key']) || $config['deepseek_api_key'] === 'YOUR_DEEPSEEK_API_KEY') {
    error_log('CRON ERROR: DeepSeek API Key is not configured.');
    exit(1);
}
if (empty($config['xai_api_key']) || $config['xai_api_key'] === 'YOUR_XAI_API_KEY') {
    error_log('CRON ERROR: X.AI API Key is not configured.');
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
