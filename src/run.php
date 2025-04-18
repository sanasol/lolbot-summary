<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Bot;
use Dotenv\Dotenv;

// Load environment variables from .env file if it exists
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Load configuration
$configDistPath = __DIR__ . '/../config/config.php.dist';
$configPath = __DIR__ . '/../config/config.php';

if (!file_exists($configPath) && file_exists($configDistPath)) {
    copy($configDistPath, $configPath);
    echo "Configuration file created at {$configPath}. Please update it with your API keys.\n";
    // Consider exiting here if keys are mandatory for the first run
    // exit(1);
}

$config = require $configPath;

// Validate essential configuration
if (empty($config['telegram_bot_token']) || $config['telegram_bot_token'] === 'YOUR_TELEGRAM_BOT_TOKEN') {
    die('Error: Telegram Bot Token is not configured. Please set TELEGRAM_BOT_TOKEN environment variable or update config/config.php.' . PHP_EOL);
}

try {
    $bot = new Bot($config);
    // Remove the continuous run() call, as cron handles repetition.
    // We will call a method to process one batch of updates.
    echo "[" . date('Y-m-d H:i:s') . "] Polling for updates...\n";
    $bot->processUpdates(); // We need to add this method to Bot.php

} catch (\Throwable $e) {
    // Log the error
    file_put_contents('php://stderr', 'Error running bot update check: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    exit(1); // Exit with an error code
}

echo "[" . date('Y-m-d H:i:s') . "] Update check finished.\n";
exit(0); // Exit successfully after one run
