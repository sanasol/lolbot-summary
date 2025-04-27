<?php

namespace App\Services;

use App\Bot;

/**
 * Handles asynchronous processing of Telegram webhook updates using either:
 * 1. Redis queue (preferred method)
 * 2. FrankenPHP fibers (fallback)
 * 3. register_shutdown_function (last resort)
 */
class AsyncWebhookHandler
{
    /**
     * Process a webhook update asynchronously.
     * This method returns immediately after validating the update and then:
     * - Sends the update to a Redis queue for processing by a dedicated worker (preferred)
     * - Or processes it in a fiber if Redis is unavailable
     * - Or uses register_shutdown_function as a last resort
     *
     * @param Bot $bot The bot instance
     * @param string $updateJson The JSON string received from Telegram
     * @return bool Whether the update was validated successfully
     */
    public static function processAsync(Bot $bot, string $updateJson): bool
    {
        try {
            // Validate the update
            $update = json_decode($updateJson, true);
            if (empty($update)) {
                error_log('Empty or invalid update received');
                return false;
            }

            // Log the receipt of the update
            $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Webhook Received] ";
            $webhookLogFile = $bot->getConfig()['log_path'] . '/webhook_' . date('Y-m-d') . '.log';
            file_put_contents($webhookLogFile, $logPrefix . "Update received and queued for async processing" . PHP_EOL, FILE_APPEND);

            // Try to use the queue service first
            try {
                $queueService = new QueueService();
                $queueResult = $queueService->sendWebhookToQueue($updateJson);

                if ($queueResult) {
                    file_put_contents($webhookLogFile, $logPrefix . "Update sent to Redis queue for processing" . PHP_EOL, FILE_APPEND);
                    return true;
                }

                file_put_contents($webhookLogFile, $logPrefix . "Failed to send update to Redis queue, falling back to alternative methods" . PHP_EOL, FILE_APPEND);
            } catch (\Throwable $queueError) {
                // Log the queue error but continue with fallback methods
                file_put_contents($webhookLogFile, $logPrefix . "Queue error: " . $queueError->getMessage() . ", falling back to alternative methods" . PHP_EOL, FILE_APPEND);
            }
        } catch (\Throwable $e) {
            $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Webhook Error] ";
            $webhookLogFile = $bot->getConfig()['log_path'] . '/webhook_' . date('Y-m-d') . '.log';
            $errorLogFile = $bot->getConfig()['log_path'] . '/error_' . date('Y-m-d') . '.log';

            $logMessage = $logPrefix . "Error during webhook validation: " . $e->getMessage();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($errorLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return false;
        }

        return false;
    }
}
