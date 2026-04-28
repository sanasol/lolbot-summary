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
use App\Services\PeriodicTaskRunner;
use Dotenv\Dotenv;

function envFlag(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

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

// Initialize usage tracker data path
\App\Services\UsageTracker::setDataPath($config['log_path'] ?? __DIR__ . '/../data');

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

$consumeWebhooks = envFlag('WORKER_CONSUME_WEBHOOKS', true);
$periodicEnabled = envFlag('WORKER_PERIODIC_ENABLED', true);
$queueWaitMs = max(
    100,
    (int)(getenv('WORKER_QUEUE_WAIT_MS') ?: ($periodicEnabled ? 250 : 1000))
);

if (!$consumeWebhooks && !$periodicEnabled) {
    error_log('Queue Worker Error: both WORKER_CONSUME_WEBHOOKS and WORKER_PERIODIC_ENABLED are disabled.');
    exit(1);
}

// Initialize the queue service only for webhook consumers. This lets us scale
// webhook workers without also duplicating periodic jobs.
$queueService = $consumeWebhooks ? new QueueService() : null;

// Initialize the bot
$bot = new Bot($config);
$periodic = $periodicEnabled ? new PeriodicTaskRunner($bot, 5) : null;

$mode = sprintf(
    'consume_webhooks=%s periodic=%s queue_wait_ms=%d',
    $consumeWebhooks ? 'on' : 'off',
    $periodicEnabled ? 'on' : 'off',
    $queueWaitMs
);
echo "Queue worker started ({$mode}).\n";

// Log worker start
$logPrefix = "[" . date('Y-m-d H:i:s') . "] [Queue Worker] ";
$logFile = $config['log_path'] . '/queue_worker_' . date('Y-m-d') . '.log';
file_put_contents($logFile, $logPrefix . "Queue worker started ({$mode})" . PHP_EOL, FILE_APPEND);

// Process messages continuously
while (true) {
    try {
        if ($consumeWebhooks && $queueService !== null) {
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
            }, $queueWaitMs);
        }

        // Periodic tasks
        if ($periodicEnabled && $periodic !== null) {
            try {
                $periodic->tick();
            } catch (\Throwable $e) {
                $errorMessage = $logPrefix . "Periodic tick error: " . $e->getMessage();
                file_put_contents($logFile, $errorMessage . PHP_EOL, FILE_APPEND);
            }
        }

        // Sleep for a short time to prevent CPU overuse
        usleep($consumeWebhooks ? 100000 : 500000);
    } catch (\Throwable $e) {
        // Log error
        $errorMessage = $logPrefix . "Queue worker error: " . $e->getMessage() . "\n" . $e->getTraceAsString();
        file_put_contents($logFile, $errorMessage . PHP_EOL, FILE_APPEND);
        error_log($errorMessage);

        // Sleep for a bit longer after an error
        sleep(1);
    }
}
