<?php
/**
 * Telegram Bot Queue Worker
 *
 * This script consumes messages from the webhook queue and processes them.
 * It runs continuously in a separate Docker container.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Bot;
use App\Services\QueueService;
use Dotenv\Dotenv;

// Load environment variables from .env file if it exists
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    error_log('Queue Worker Error: Configuration file not found.');
    exit(1);
}

$config = require $configPath;

// Validate essential configuration
if (empty($config['telegram_bot_token']) || $config['telegram_bot_token'] === 'YOUR_TELEGRAM_BOT_TOKEN') {
    error_log('Queue Worker Error: Telegram Bot Token is not configured.');
    exit(1);
}

// Create log directory if it doesn't exist
if (!is_dir($config['log_path']) && !mkdir($concurrentDirectory = $config['log_path'], 0777, true) && !is_dir(
        $concurrentDirectory
    )) {
        throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
    }

// Initialize the queue service
$queueService = new QueueService();

// Initialize the bot
$bot = new Bot($config);

echo "Queue worker started. Waiting for webhook messages...\n";

// Log worker start
$logPrefix = "[" . date('Y-m-d H:i:s') . "] [Queue Worker] ";
$logFile = $config['log_path'] . '/queue_worker_' . date('Y-m-d') . '.log';
file_put_contents($logFile, $logPrefix . "Queue worker started" . PHP_EOL, FILE_APPEND);

// Process messages continuously
while (true) {
    try {
        // Consume messages from the queue
        $queueService->consumeWebhookQueue(function (string $updateJson) use ($bot, $logPrefix, $logFile) {
            try {
                // Log the received message
                file_put_contents($logFile, $logPrefix . "Processing webhook from queue" . PHP_EOL, FILE_APPEND);

                // Process the webhook
                $bot->processWebhookAsync($updateJson);

                // Log successful processing
                file_put_contents($logFile, $logPrefix . "Webhook processed successfully" . PHP_EOL, FILE_APPEND);

                return true;
            } catch (\Throwable $e) {
                // Log error
                $errorMessage = $logPrefix . "Error processing webhook: " . $e->getMessage() . "\n" . $e->getTraceAsString();
                file_put_contents($logFile, $errorMessage . PHP_EOL, FILE_APPEND);
                error_log($errorMessage);

                return false;
            }
        });

        // Sleep for a short time to prevent CPU overuse
        usleep(100000); // 100ms
    } catch (\Throwable $e) {
        // Log error
        $errorMessage = $logPrefix . "Queue worker error: " . $e->getMessage() . "\n" . $e->getTraceAsString();
        file_put_contents($logFile, $errorMessage . PHP_EOL, FILE_APPEND);
        error_log($errorMessage);

        // Sleep for a bit longer after an error
        sleep(1);
    }
}
