<?php
/**
 * Telegram Bot Webhook Handler
 *
 * This file receives and processes webhook updates from the Telegram API.
 * Set your webhook URL to point to this file, e.g., https://yourdomain.com/webhook.php
 *
 * This implementation uses FrankenPHP's features for asynchronous processing.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Bot;
use Dotenv\Dotenv;

// Check if running under FrankenPHP
$isFrankenPHP = function_exists('frankenphp_handle_request');

// Load environment variables from .env file if it exists
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    error_log('Webhook Error: Configuration file not found.');
    exit('Configuration error');
}

$config = require $configPath;

// Validate essential configuration
if (empty($config['telegram_bot_token']) || $config['telegram_bot_token'] === 'YOUR_TELEGRAM_BOT_TOKEN') {
    http_response_code(500);
    error_log('Webhook Error: Telegram Bot Token is not configured.');
    exit('Configuration error');
}
if (empty($config['openrouter_key']) || $config['openrouter_key'] === 'YOUR_OPENROUTER_KEY') {
    http_response_code(500);
    error_log('Webhook Error: OpenRouter API Key is not configured.');
    exit('Configuration error');
}

try {
    // Get the webhook input
    $content = file_get_contents('php://input');

    // Log the incoming webhook (optional, for debugging)
    if (!empty($content)) {
        file_put_contents(
            $config['log_path'] . '/webhook_' . date('Y-m-d') . '.log',
            '[' . date('Y-m-d H:i:s') . '] ' . ($isFrankenPHP ? '[FrankenPHP] ' : '') . $content . PHP_EOL,
            FILE_APPEND
        );
    }

    // Instantiate the bot
    $bot = new Bot($config);

    // Process the webhook update - this will return immediately and process in the background
    $result = $bot->processWebhook($content);

    if ($result) {
        // Return a 200 OK response to Telegram immediately
        http_response_code(200);

        // If running under FrankenPHP, use its features to optimize the response
        if ($isFrankenPHP && function_exists('frankenphp_finish_request')) {
            // Send the response immediately and continue processing in the background
            echo 'OK';
            frankenphp_finish_request();

            // Log that we're using FrankenPHP's finish_request
            file_put_contents(
                $config['log_path'] . '/webhook_' . date('Y-m-d') . '.log',
                '[' . date('Y-m-d H:i:s') . '] [FrankenPHP] Using frankenphp_finish_request for async processing' . PHP_EOL,
                FILE_APPEND
            );
        } else {
            exit('OK');
        }
    } else {
        // If there was a validation error, return a 400 Bad Request
        http_response_code(400);
        exit('Invalid webhook data');
    }

} catch (\Throwable $e) {
    // Log the error
    error_log('Webhook Error: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString());

    if (!empty($config['log_path'])) {
        file_put_contents(
            $config['log_path'] . '/error_' . date('Y-m-d') . '.log',
            '[' . date('Y-m-d H:i:s') . '] Webhook Error: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL,
            FILE_APPEND
        );
    }

    // Return a 500 error response
    http_response_code(500);
    exit('Error processing webhook');
}
