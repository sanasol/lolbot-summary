<?php

namespace App;

use App\Services\AIService;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

class Bot
{
    private Telegram $telegram;
    private array $config;
    private HttpClient $httpClient;
    private string $logPath;
    private array $chatMessages = []; // In-memory store for messages [chat_id => [timestamp => message_text]]
    private string $botUsername = ''; // Will be set in constructor

    private AIService $aiService;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logPath = $config['log_path'] ?? (__DIR__ . '/../data');

        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0777, true);
        }

        $this->httpClient = new HttpClient();
        $this->aiService = new AIService($config);

        try {
            $this->telegram = new Telegram($config['telegram_bot_token'], 'newbotname2025bot'); // Replace BotUsername if needed

            // Store the bot username for mention detection
            $this->botUsername = $this->telegram->getBotUsername();

            // Set webhook or use getUpdates
            // For production, using a webhook is recommended.
            // The webhook URL should be set using the setWebhook method or via the Telegram API
            // See the setWebhook method below for usage

            // Add commands path (optional, if you add commands)
            // $this->telegram->addCommandsPath(__DIR__ . '/Commands/');

            // Enable admin users (optional)
            // $this->telegram->enableAdmin(ADMIN_USER_ID);

            // Logging (optional)
            // TelegramLog::initErrorLog($this->logPath . '/' . $this->telegram->getBotUsername() . '_error.log');
            // TelegramLog::initDebugLog($this->logPath . '/' . $this->telegram->getBotUsername() . '_debug.log');
            // TelegramLog::initUpdateLog($this->logPath . '/' . $this->telegram->getBotUsername() . '_update.log');

            // Load existing messages from files
            $this->loadAllMessagesFromFiles();

        } catch (TelegramException $e) {
            // Log error
            error_log($e->getMessage());
            throw $e; // Rethrow exception
        }
    }

    /**
     * Load all message files from the data directory
     */
    private function loadAllMessagesFromFiles(): void
    {
        $files = glob($this->logPath . '/*_messages.json');
        foreach ($files as $file) {
            if (preg_match('/(\-?\d+)_messages\.json$/', $file, $matches)) {
                $chatId = (int)$matches[1];
                $this->loadMessagesFromFile($chatId);
            }
        }
    }

    public function run(): void
    {
        echo "Bot started... Waiting for updates.\n";
        // This method is no longer the main loop when using cron.
        // Kept for potential direct execution or alternative modes.
        // The actual work is now in processUpdates().
        while (true) {
            echo "run() called - use processUpdates() for cron execution.\n";
            sleep(60); // Prevent tight loop if run directly
        }
    }

    private function storeMessage(int $chatId, int $timestamp, string $username, string $messageText, int $messageId = null): void
    {
        // Simple in-memory storage - consider a database or file for persistence across restarts
        if (!isset($this->chatMessages[$chatId])) {
            $this->chatMessages[$chatId] = [];
        }

        // Format with message ID if available
        if ($messageId) {
            $this->chatMessages[$chatId][$timestamp] = sprintf("[%s] [ID:%d] %s: %s", date('H:i', $timestamp), $messageId, $username, $messageText);
        } else {
            $this->chatMessages[$chatId][$timestamp] = sprintf("[%s] %s: %s", date('H:i', $timestamp), $username, $messageText);
        }

        // Optional: Persist to file immediately (can be inefficient)
        $this->saveMessagesToFile($chatId);
    }

    /**
     * Process one batch of updates from Telegram.
     * Intended to be called periodically (e.g., by cron).
     */
    public function processUpdates(): void
    {
        try {
            $server_response = $this->telegram->handleGetUpdates();

            if ($server_response->isOk()) {
                $update_count = count($server_response->getResult());

                $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Updates] ";
                $updatesLogFile = $this->config['log_path'] . '/updates_' . date('Y-m-d') . '.log';

                if ($update_count > 0) {
                    $logMessage = $logPrefix . "Processing {$update_count} updates";
                    file_put_contents($updatesLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                    echo date('Y-m-d H:i:s') . ' - Processing ' . $update_count . " updates\n";
                } else {
                    $logMessage = $logPrefix . "No new updates to process";
                    file_put_contents($updatesLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                }

                /** @var Update $update */
                foreach ($server_response->getResult() as $update) {
                    $message = $update->getMessage() ?? $update->getEditedMessage();
                    if ($message && ($message->getChat()->isGroupChat() || $message->getChat()->isSuperGroup())) {
                        $chatId = $message->getChat()->getId();
                        $messageText = $message->getText();
                        $timestamp = $message->getDate();
                        $userId = $message->getFrom()->getId();
                        $username = $message->getFrom()->getUsername() ?? $message->getFrom()->getFirstName();
                        $messageId = $message->getMessageId();

                        // Get photos from the message if any
                        $photos = $message->getPhoto();

                        // Get caption if available (for photos)
                        $caption = $message->getCaption();

                        if ($messageText || $photos) {
                            if ($messageText) {
                                $this->storeMessage($chatId, $timestamp, $username, $messageText, $messageId);
                            } else if ($caption) {
                                // Store caption as message if there's no text but there's a caption
                                $this->storeMessage($chatId, $timestamp, $username, $caption, $messageId);
                            }

                            // Check if the bot is mentioned in the message
                            if ($messageText !== '/summary') {
                                // Use caption as message text if there's no text but there's a caption
                                $textToUse = $messageText ?: ($caption ?: '');
                                $this->handleBotMention($chatId, $textToUse, $username, $messageId, $photos ? $photos : null);
                            }
                        }
                    }
                    // Basic command handling example
                    if ($message && $message->getText(true) === '/summary') {
                        $this->handleSummaryCommand($message->getChat()->getId());
                    }
                    // Add handling for other commands or message types here if needed
                }
            } else {
                $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Updates] ";
                $updatesLogFile = $this->config['log_path'] . '/updates_' . date('Y-m-d') . '.log';
                $errorLogFile = $this->config['log_path'] . '/error_' . date('Y-m-d') . '.log';

                $logMessage = $logPrefix . "Failed to fetch updates: " . $server_response->getDescription();
                file_put_contents($updatesLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                file_put_contents($errorLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                error_log($logMessage);
                // Don't sleep here; cron controls the interval
            }
            // Note: Daily summary sending is handled by the separate cron job calling checkAndSendDailySummaries()

        } catch (TelegramException $e) {
            $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Updates Error] ";
            $updatesLogFile = $this->config['log_path'] . '/updates_' . date('Y-m-d') . '.log';
            $errorLogFile = $this->config['log_path'] . '/error_' . date('Y-m-d') . '.log';

            $logMessage = $logPrefix . "Telegram API Error: " . $e->getMessage();
            file_put_contents($updatesLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($errorLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            // Throw the exception so the cron job can log it and exit with error
            throw $e;
        } catch (\Throwable $e) {
            $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Updates Error] ";
            $updatesLogFile = $this->config['log_path'] . '/updates_' . date('Y-m-d') . '.log';
            $errorLogFile = $this->config['log_path'] . '/error_' . date('Y-m-d') . '.log';

            $logMessage = $logPrefix . "General Error: " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString();
            file_put_contents($updatesLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($errorLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            // Throw the exception
            throw $e;
        }
    }

    private function getRecentMessages(int $chatId, int $hours = 24): array
    {
        // Make sure we have the latest messages from file
        $this->loadMessagesFromFile($chatId);

        $cutoff = time() - ($hours * 3600);
        $recentMessages = [];

        if (isset($this->chatMessages[$chatId])) {
            // Ensure messages are sorted by time
            ksort($this->chatMessages[$chatId]);
            foreach ($this->chatMessages[$chatId] as $timestamp => $message) {
                if ($timestamp >= $cutoff) {
                    $recentMessages[] = $message;
                }
            }
        }

        return $recentMessages;
    }

    /**
     * Get recent chat messages for context
     *
     * @param int $chatId The chat ID
     * @param int $maxMessages Maximum number of messages to include (default: 10)
     * @param int $minutes How far back to look for messages in minutes (default: 30)
     * @return array Array of recent messages
     */
    private function getRecentChatContext(int $chatId, int $maxMessages = 10, int $minutes = 30): array
    {
        // Make sure we have the latest messages from file
        $this->loadMessagesFromFile($chatId);

        $cutoff = time() - ($minutes * 60);
        $contextMessages = [];

        if (isset($this->chatMessages[$chatId])) {
            // Ensure messages are sorted by time
            ksort($this->chatMessages[$chatId]);

            // Get recent messages
            $recentMessages = [];
            foreach ($this->chatMessages[$chatId] as $timestamp => $message) {
                if ($timestamp >= $cutoff) {
                    $recentMessages[$timestamp] = $message;
                }
            }

            // Take the most recent messages up to the maximum
            $recentMessages = array_slice($recentMessages, -$maxMessages, $maxMessages, true);

            foreach ($recentMessages as $message) {
                $contextMessages[] = $message;
            }
        }

        return $contextMessages;
    }

    protected function cleanupOldMessages(): void
    {
        $cutoff = time() - (25 * 3600); // Keep slightly more than 24 hours
        $modified = false;

        foreach ($this->chatMessages as $chatId => &$messages) {
            $chatModified = false;
            foreach ($messages as $timestamp => $message) {
                if ($timestamp < $cutoff) {
                    unset($messages[$timestamp]);
                    $chatModified = true;
                    $modified = true;
                }
            }

            if (empty($messages)) {
                unset($this->chatMessages[$chatId]);
                // Remove the file if it exists
                $filePath = $this->getChatLogFile($chatId);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            } elseif ($chatModified) {
                // Save the updated messages to file
                $this->saveMessagesToFile($chatId);
            }
        }
        unset($messages); // Unset reference
    }

    public function triggerCleanup(): void
    {
        $this->cleanupOldMessages();
    }

    /**
     * Process a webhook update from Telegram.
     * This method is called by the webhook.php file when a new update is received.
     *
     * @param string $updateJson The JSON string received from Telegram
     * @return bool Whether the update was processed successfully
     */
    public function processWebhook(string $updateJson): bool
    {
        try {
            // Process the update
            $update = json_decode($updateJson, true);
            if (empty($update)) {
                error_log('Empty or invalid update received');
                return false;
            }

            // Create an Update object from the JSON data
            $update = new Update($update, $this->telegram->getBotUsername());

            // Process the update similar to how we do in processUpdates()
            $message = $update->getMessage() ?? $update->getEditedMessage();
            if ($message && ($message->getChat()->isGroupChat() || $message->getChat()->isSuperGroup())) {
                $chatId = $message->getChat()->getId();
                $messageText = $message->getText();
                $timestamp = $message->getDate();
                $userId = $message->getFrom()->getId();
                $username = $message->getFrom()->getUsername() ?? $message->getFrom()->getFirstName();
                $messageId = $message->getMessageId();

                // Get photos from the message if any
                $photos = $message->getPhoto();

                // Get caption if available (for photos)
                $caption = $message->getCaption();

                if ($messageText || $photos) {
                    if ($messageText) {
                        $this->storeMessage($chatId, $timestamp, $username, $messageText, $messageId);
                    } else if ($caption) {
                        // Store caption as message if there's no text but there's a caption
                        $this->storeMessage($chatId, $timestamp, $username, $caption, $messageId);
                    }

                    if ($messageText !== '/summary') {
                        // Check if the bot is mentioned in the message
                        // Use caption as message text if there's no text but there's a caption
                        $textToUse = $messageText ?: ($caption ?: '');
                        $this->handleBotMention($chatId, $textToUse, $username, $messageId, $photos ? $photos : null);
                    }
                }
            }

            // Basic command handling example
            if ($message && $message->getText(false) === '/summary') {
                $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Webhook] ";
                $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

                $chatId = $message->getChat()->getId();
                $chatTitle = $message->getChat()->getTitle() ?? "Unknown";
                $fromUser = $message->getFrom()->getUsername() ?? $message->getFrom()->getFirstName() ?? "Unknown";

                $logMessage = $logPrefix . "Received /summary command in chat {$chatId} ({$chatTitle}) from user {$fromUser}";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                $this->handleSummaryCommand($chatId);
            }

            // Log all messages for debugging
            if ($message && $message->getText(false)) {
                $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Webhook] ";
                $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

                $chatId = $message->getChat()->getId();
                $chatType = $message->getChat()->getType();
                $fromUser = $message->getFrom()->getUsername() ?? $message->getFrom()->getFirstName() ?? "Unknown";
                $messageText = $message->getText(false);

                $logMessage = $logPrefix . "Message in {$chatType} {$chatId} from {$fromUser}: {$messageText}";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            }

            return true;

        } catch (TelegramException $e) {
            $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Webhook Error] ";
            $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';
            $errorLogFile = $this->config['log_path'] . '/error_' . date('Y-m-d') . '.log';

            $logMessage = $logPrefix . "Telegram API Error: " . $e->getMessage();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($errorLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return false;
        } catch (\Throwable $e) {
            $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Webhook Error] ";
            $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';
            $errorLogFile = $this->config['log_path'] . '/error_' . date('Y-m-d') . '.log';

            $logMessage = $logPrefix . "General Error: " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($errorLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return false;
        }
    }

    /**
     * Set the webhook URL for the bot.
     * This method should be called once to configure the webhook.
     *
     * @param string $url The full URL to your webhook.php file (must be HTTPS)
     * @param array $options Additional options for the webhook
     * @return bool Whether the webhook was set successfully
     */
    public function setWebhook(string $url, array $options = []): bool
    {
        try {
            // Default options
            $webhookOptions = [
                'url' => $url,
                'max_connections' => 40,
                'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            ];

            // Merge with custom options if provided
            if (!empty($options)) {
                $webhookOptions = array_merge($webhookOptions, $options);
            }

            // Set the webhook
            $result = $this->telegram->setWebhook($url, $webhookOptions);

            if ($result->isOk()) {
                echo "Webhook set successfully to: {$url}\n";
                return true;
            } else {
                error_log("Failed to set webhook: " . $result->getDescription());
                return false;
            }

        } catch (TelegramException $e) {
            error_log("Error setting webhook: " . $e->getMessage());
            return false;
        }
    }

    private function handleSummaryCommand(int $chatId): void
    {
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Command] ";
        $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

        $logMessage = $logPrefix . "Handling /summary command for chat {$chatId}";
        file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
        echo "Handling /summary command for chat {$chatId}\n";

        $messages = $this->getRecentMessages($chatId, 24);
        $messageCount = count($messages);
        $logMessage = $logPrefix . "Retrieved {$messageCount} messages for chat {$chatId}";
        file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

        if (empty($messages)) {
            $logMessage = $logPrefix . "No messages found to summarize for chat {$chatId}";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'No messages found in the last 24 hours to summarize\.',
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        // Get chat information
        $chatInfo = null;
        try {
            $logMessage = $logPrefix . "Fetching chat info for chat {$chatId}";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $result = Request::getChat(['chat_id' => $chatId]);
            if ($result->isOk()) {
                $chatInfo = $result->getResult();
                $logMessage = $logPrefix . "Successfully retrieved chat info for chat {$chatId}";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            } else {
                $errorDesc = $result->getDescription();
                $logMessage = $logPrefix . "Failed to get chat info: " . $errorDesc;
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                error_log($logMessage);
            }
        } catch (\Exception $e) {
            $logMessage = $logPrefix . "Error getting chat info: " . $e->getMessage();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);
        }

        // Extract chat details
        $chatTitle = $chatInfo ? $chatInfo->getTitle() : null;
        $chatUsername = $chatInfo ? $chatInfo->getUsername() : null;

        $logMessage = $logPrefix . "Generating summary for chat {$chatId}" .
            ($chatTitle ? ", Title: {$chatTitle}" : "") .
            ($chatUsername ? ", Username: {$chatUsername}" : "");
        file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

        $summary = $this->aiService->generateChatSummary($messages, $chatId, $chatTitle, $chatUsername);

        if ($summary) {
            $summaryLength = strlen($summary);
            $logMessage = $logPrefix . "Sending summary ({$summaryLength} chars) to chat {$chatId}";
            file_put_contents($webhookLogFile, $summary . PHP_EOL, FILE_APPEND);
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $sendResult = Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $summary,
                'parse_mode' => 'HTML'
            ]);

            if ($sendResult->isOk()) {
                $logMessage = $logPrefix . "Summary successfully sent to chat {$chatId}";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                // Try to pin the message
                try {
                    $messageId = $sendResult->getResult()->getMessageId();

                    if (!$messageId) {
                        $logMessage = $logPrefix . "Cannot pin message in chat {$chatId}: Invalid message ID";
                        file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                        error_log($logMessage);
                    } else {
                        $pinResult = Request::pinChatMessage([
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'disable_notification' => true // Set to false if you want a notification when pinned
                        ]);

                        if ($pinResult->isOk()) {
                            $logMessage = $logPrefix . "Summary message successfully pinned in chat {$chatId}";
                            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                        } else {
                            $logMessage = $logPrefix . "Failed to pin summary message in chat {$chatId}: " . $pinResult->getDescription();
                            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                            error_log($logMessage);
                        }
                    }
                } catch (TelegramException $e) {
                    $logMessage = $logPrefix . "Telegram exception when pinning message in chat {$chatId}: " . $e->getMessage();
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                    error_log($logMessage);
                } catch (\Exception $e) {
                    $logMessage = $logPrefix . "Exception when pinning message in chat {$chatId}: " . $e->getMessage();
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                    error_log($logMessage);
                }
            } else {
                $logMessage = $logPrefix . "Failed to send summary to chat {$chatId}: " . $sendResult->getDescription();
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                error_log($logMessage);
            }
        } else {
            $logMessage = $logPrefix . "Failed to generate summary for chat {$chatId}";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Sorry, I couldn\'t generate a summary at this time\.',
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    }

    // --- Daily Summary Logic (Placeholder) ---
    private $lastSummaryCheckTime = 0;

    protected function sendDailySummaries(): void
    {
        $currentTime = time();
        $checkInterval = 3600; // Check every hour

        // Avoid checking too frequently
        if ($currentTime - $this->lastSummaryCheckTime < $checkInterval) {
            return;
        }
        $this->lastSummaryCheckTime = $currentTime;

        // When should the summary be sent? e.g., at 8:00 AM server time
        $summaryHour = 8; // 8 AM
        $currentHour = (int)date('G'); // 24-hour format

        // Check if it's the summary hour and if we haven't sent one today for relevant chats
        if ($currentHour === $summaryHour) {
            echo "Checking chats for daily summary...\n";
            $this->cleanupOldMessages(); // Clean up very old messages before summarizing

            foreach (array_keys($this->chatMessages) as $chatId) {
                 // Add logic here to track if a summary was already sent today for this chat
                 // This could involve storing the last summary timestamp per chat (in memory or file/DB)
                 if ($this->shouldSendDailySummary($chatId, $currentTime)) {
                     echo "Sending daily summary to chat {$chatId}...\n";
                     $this->handleSummaryCommand($chatId); // Reuse the command logic
                     $this->markDailySummarySent($chatId, $currentTime); // Mark as sent
                 }
            }
        }
    }

    public function checkAndSendDailySummaries(): void
    {
        $this->sendDailySummaries();
    }

    // --- Persistence/Tracking for Daily Summary (Example using simple file storage) ---
    private function getLastSummarySentFile(int $chatId): string
    {
        return $this->logPath . '/' . $chatId . '_last_summary.txt';
    }

    private function shouldSendDailySummary(int $chatId, int $currentTime): bool
    {
        $filePath = $this->getLastSummarySentFile($chatId);
        if (!file_exists($filePath)) {
            return true; // Never sent before
        }

        $lastSentTimestamp = (int)file_get_contents($filePath);
        $secondsSinceLastSummary = $currentTime - $lastSentTimestamp;

        // Send if more than 23 hours have passed (allows for some flexibility around the exact hour)
        return $secondsSinceLastSummary > (23 * 3600);
    }

    private function markDailySummarySent(int $chatId, int $currentTime): void
    {
        $filePath = $this->getLastSummarySentFile($chatId);
        file_put_contents($filePath, (string)$currentTime);
    }

     private function getChatLogFile(int $chatId): string {
         return $this->logPath . '/' . $chatId . '_messages.json';
     }

     private function saveMessagesToFile(int $chatId): void {
         $filePath = $this->getChatLogFile($chatId);
         if (isset($this->chatMessages[$chatId])) {
             file_put_contents($filePath, json_encode($this->chatMessages[$chatId]));
         }
     }

     private function loadMessagesFromFile(int $chatId): void {
         $filePath = $this->getChatLogFile($chatId);
         if (file_exists($filePath)) {
             $data = json_decode(file_get_contents($filePath), true);
             if (is_array($data)) {
                 // Initialize chat messages array if it doesn't exist
                 if (!isset($this->chatMessages[$chatId])) {
                     $this->chatMessages[$chatId] = [];
                 }

                 // Merge file data with in-memory data
                 // For timestamps that exist in both, keep the in-memory version (likely newer)
                 foreach ($data as $timestamp => $message) {
                     if (!isset($this->chatMessages[$chatId][$timestamp])) {
                         $this->chatMessages[$chatId][$timestamp] = $message;
                     }
                 }

                 // Ensure messages are sorted by timestamp
                 ksort($this->chatMessages[$chatId]);
             }
         }
     }

    /**
     * Generate a response using X.AI Grok API
     *
     * @param string $messageText The original message text
     * @param string $username The username of the message sender
     * @param string $chatContext Optional context from recent chat messages
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return array|null The generated response or null if generation failed.
     *                   Format: ['type' => 'text|image', 'content' => string, 'image_url' => string|null]
     */
    private function generateGrokResponse(string $messageText, string $username, string $chatContext = '', ?string $inputImageUrl = null): ?array
    {
        return $this->aiService->generateGrokResponse($messageText, $username, $chatContext, $inputImageUrl);
    }

    /**
     * Handle a bot mention in a message
     *
     * @param int $chatId The chat ID
     * @param string $messageText The message text
     * @param string $username The username of the message sender
     * @param int $replyToMessageId The message ID to reply to
     * @param mixed $photos Photos from the message, if any
     * @return bool Whether the mention was handled successfully
     */
    private function handleBotMention(int $chatId, string $messageText, string $username, int $replyToMessageId, $photos = null): bool
    {
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Bot Mention] ";
        $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

        // Log the message or caption being processed
        $messageSource = empty($messageText) ? "with empty text" : "with text";
        if ($photos && !empty($photos)) {
            $messageSource = empty($messageText) ? "with photo only" : "with photo and caption";
        }

        $logMessage = $logPrefix . "Bot mentioned in chat {$chatId} by {$username} {$messageSource}: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : '');
        file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

        // Check if the message contains a photo
        $inputImageUrl = null;
        if ($photos && !empty($photos)) {
            try {
                // Log photo information for debugging
                $logMessage = $logPrefix . "Photo object type: " . gettype($photos);
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                // Get the largest photo (last in the array)
                $largestPhoto = end($photos);

                // Get the file ID from the PhotoSize object
                $fileId = $largestPhoto->getFileId();

                $logMessage = $logPrefix . "Using file ID: " . $fileId;
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                // Get the file path from Telegram
                $fileResult = Request::getFile(['file_id' => $fileId]);

                if ($fileResult->isOk()) {
                    $filePath = $fileResult->getResult()->getFilePath();

                    // Construct the full URL to the file
                    $inputImageUrl = 'https://api.telegram.org/file/bot' . $this->config['telegram_bot_token'] . '/' . $filePath;

                    $logMessage = $logPrefix . "Found image in message: " . $inputImageUrl;
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                } else {
                    $logMessage = $logPrefix . "Failed to get file path for photo: " . $fileResult->getDescription();
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                }
            } catch (\Exception $e) {
                $logMessage = $logPrefix . "Error processing photo: " . $e->getMessage();
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                error_log($logMessage);
            }
        }

        // Get recent messages from the chat for context (last 10 messages or from the last 30 minutes)
        $recentMessages = $this->getRecentChatContext($chatId);
        $chatContext = '';

        if (!empty($recentMessages)) {
            $chatContext = "Recent conversation in the chat:\n" . implode("\n", $recentMessages) . "\n\n";
            $logMessage = $logPrefix . "Added " . count($recentMessages) . " recent messages as context";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
        }

        // Use Grok for responses with added context
        $response = $this->generateGrokResponse($messageText, $username, $chatContext, $inputImageUrl);

        // Fallback to DeepSeek if Grok fails
//        if (!$response) {
//            $logMessage = $logPrefix . "Grok response failed, falling back to DeepSeek";
//            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
//            $response = $this->generateMemeResponse($messageText, $username, $chatContext);
//        }

        if ($response) {
            // Check if this is a text or image response
            if ($response['type'] === 'text') {
                // Send text response
                $sendResult = Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $response['content'],
                    'reply_to_message_id' => $replyToMessageId,
                ]);

                $responseType = 'text';
            } else if ($response['type'] === 'image' && !empty($response['image_url'])) {
                // Send image response with caption
                $sendResult = Request::sendPhoto([
                    'chat_id' => $chatId,
                    'photo' => $response['image_url'],
                    'caption' => $response['content'] ?? null, // Use content if available
                    'reply_to_message_id' => $replyToMessageId
                ]);

                $responseType = 'image';

                // Log the image generation details
                $logMessage = $logPrefix . "Image generated with prompt: " . $response['prompt'];
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                $logMessage = $logPrefix . "Revised prompt: " . ($response['revised_prompt'] ?? 'N/A');
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            } else {
                // Fallback to text response if image URL is missing
                $sendResult = Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $response['content'] ?? 'Sorry, I couldn\'t generate an image for that request.',
                    'reply_to_message_id' => $replyToMessageId
                ]);

                $responseType = 'fallback';
            }

            if ($sendResult->isOk()) {
                $logMessage = $logPrefix . "Successfully sent {$responseType} response to chat {$chatId}";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                return true;
            } else {
                $logMessage = $logPrefix . "Failed to send {$responseType} response to chat {$chatId}: " . $sendResult->getDescription();
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                error_log($logMessage);
                return false;
            }
        }

        return false;
    }
}
