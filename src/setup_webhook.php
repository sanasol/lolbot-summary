<?php
/**
 * Telegram Bot Webhook Setup Script
 * 
 * This script sets up the webhook for your Telegram bot.
 * Run this script once to configure the webhook URL.
 */

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
    die('Error: Configuration file not found.' . PHP_EOL);
}

$config = require $configPath;

// Validate essential configuration
if (empty($config['telegram_bot_token']) || $config['telegram_bot_token'] === 'YOUR_TELEGRAM_BOT_TOKEN') {
    die('Error: Telegram Bot Token is not configured. Please set TELEGRAM_BOT_TOKEN environment variable or update config/config.php.' . PHP_EOL);
}

// Get the webhook URL from command line argument or prompt for it
$webhookUrl = $argv[1] ?? null;

if (empty($webhookUrl)) {
    echo "Please enter the full HTTPS URL to your webhook (e.g., https://yourdomain.com/src/webhook.php): ";
    $webhookUrl = trim(fgets(STDIN));
}

if (empty($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
    die('Error: Invalid webhook URL. The URL must start with https:// and be publicly accessible.' . PHP_EOL);
}

// Validate that the URL uses HTTPS (required by Telegram)
if (strpos($webhookUrl, 'https://') !== 0) {
    die('Error: Webhook URL must use HTTPS. Telegram requires secure webhooks.' . PHP_EOL);
}

try {
    echo "Setting up webhook at: {$webhookUrl}" . PHP_EOL;
    
    // Instantiate the bot
    $bot = new Bot($config);
    
    // Set the webhook
    $success = $bot->setWebhook($webhookUrl);
    
    if ($success) {
        echo "Webhook successfully set!" . PHP_EOL;
        echo "Your bot will now receive updates via webhook instead of polling." . PHP_EOL;
    } else {
        echo "Failed to set webhook. Check the logs for more details." . PHP_EOL;
    }
    
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// Optionally, you can also add a command to remove the webhook if needed
if (isset($argv[1]) && $argv[1] === '--remove') {
    try {
        $result = $bot->telegram->deleteWebhook();
        if ($result->isOk()) {
            echo "Webhook removed successfully!" . PHP_EOL;
        } else {
            echo "Failed to remove webhook: " . $result->getDescription() . PHP_EOL;
        }
    } catch (\Throwable $e) {
        echo "Error removing webhook: " . $e->getMessage() . PHP_EOL;
    }
}