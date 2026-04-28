<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\AntiSpamHandler;
use App\Services\LoggerService;

// Example spam message from the issue description
$exampleSpamJson = <<<JSON
{
    "update_id": 93836231,
    "message": {
        "message_id": 25369,
        "from": {
            "id": 5280579538,
            "is_bot": false,
            "first_name": "Jesse White",
            "username": "JesseWhitecf"
        },
        "chat": {
            "id": -1001626942168,
            "title": "Statbate - Webcam Community",
            "username": "statbate",
            "type": "supergroup"
        },
        "date": 1753898195,
        "text": "Kathy Lien is an outstanding trainer. My portfolio has seen growth every quarter! The knowledge and tools she offers are unparalleled in the crypto space. Her Platform is what anyone needs",
        "entities": [
            {
                "offset": 0,
                "length": 10,
                "type": "text_link",
                "url": "https://t.me/m/Yku2TaR9Zjg8"
            },
            {
                "offset": 159,
                "length": 8,
                "type": "text_link",
                "url": "https://t.me/CRYPTO_STOCK_NEXUS"
            }
        ],
        "link_preview_options": {
            "is_disabled": true
        }
    }
}
JSON;

// Parse the example spam message
$update = json_decode($exampleSpamJson, true);
$message = $update['message'];

// Extract message details
$messageText = $message['text'];
$userId = $message['from']['id'];
$username = $message['from']['username'] ?? $message['from']['first_name'];
$chatId = $message['chat']['id'];
$messageId = $message['message_id'];

// Create a mock logger service
class MockLoggerService extends LoggerService {
    public function __construct() {
        // Set a dummy log path
        parent::__construct('/tmp');
    }

    public function log(string $message, string $category = 'General', string $logType = 'webhook', bool $alsoLogToErrorLog = false): void {
        echo "[LOG] [$category] $message\n";
    }

    public function logError(string $message, string $category = 'Error', ?\Throwable $exception = null): void {
        echo "[ERROR] [$category] $message\n";
        if ($exception) {
            echo "Exception: " . $exception->getMessage() . "\n";
        }
    }

    public function logWebhook(string $message): void {
        echo "[WEBHOOK] $message\n";
    }
}

// Create a mock config
$config = [
    'openrouter_api_url' => 'https://openrouter.ai/api/v1/chat/completions',
    'openrouter_key' => getenv('OPENROUTER_KEY'),
    'openrouter_chat_model' => 'anthropic/claude-3-opus-20240229',
    'admin_chat_id' => getenv('ADMIN_CHAT_ID') ?: '123456789', // Replace with your admin chat ID
    'test_mode' => true, // Set to true to disable actual Telegram API calls
];

// Check if OpenRouter key is set
if (empty($config['openrouter_key'])) {
    echo "Error: OPENROUTER_KEY environment variable is not set.\n";
    echo "Please set it using: export OPENROUTER_KEY=your_api_key\n";
    exit(1);
}

// Create a logger service
$logger = new MockLoggerService();

// Create an AntiSpamHandler
$antiSpamHandler = new AntiSpamHandler($config, $logger);

echo "Testing AntiSpamHandler with example spam message:\n";
echo "Message: $messageText\n";
echo "From: $username (ID: $userId)\n";
echo "Chat: $chatId\n";
echo "Message ID: $messageId\n\n";

// Check if the message is spam
echo "Checking if the message is spam...\n";
$isSpam = $antiSpamHandler->checkAndHandleSpam($messageText, $userId, $username, $chatId, $messageId);

echo "\nResult: " . ($isSpam ? "Message detected as SPAM" : "Message is NOT spam") . "\n";
