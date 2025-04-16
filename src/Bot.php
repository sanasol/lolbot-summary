<?php

namespace App;

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

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logPath = $config['log_path'] ?? (__DIR__ . '/../data');

        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0777, true);
        }

        $this->httpClient = new HttpClient();

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

        $summary = $this->generateSummary($messages, $chatId, $chatTitle, $chatUsername);

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

    private function generateSummary(array $messages, int $chatId = null, ?string $chatTitle = null, ?string $chatUsername = null): ?string
    {
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Summary] ";
        $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';
        $summaryLogFile = $this->config['log_path'] . '/summary_' . date('Y-m-d') . '.log';

        // Log start of summary generation
        $chatIdentifier = $chatId ? "Chat ID: $chatId" : "Unknown chat";
        $chatTitleInfo = $chatTitle ? ", Title: $chatTitle" : "";
        $chatUsernameInfo = $chatUsername ? ", Username: $chatUsername" : "";
        $logMessage = $logPrefix . "Starting summary generation for $chatIdentifier$chatTitleInfo$chatUsernameInfo";

        file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
        file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);

        if (empty($messages)) {
            $logMessage = $logPrefix . "No messages to summarize for $chatIdentifier";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            return null;
        }

        $conversation = implode("\n", $messages);

        // Log message count
        $messageCount = count($messages);
        $logMessage = $logPrefix . "Processing $messageCount messages for $chatIdentifier";
        file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
        file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);

        // Build prompt with chat information
        $chatInfo = "";
        if ($chatId) {
            $chatInfo .= "Chat ID: $chatId\n";
        }
        if ($chatTitle) {
            $chatInfo .= "Chat Title: $chatTitle\n";
        }
        if ($chatUsername) {
            $chatInfo .= "Chat Username: $chatUsername\n";
        }

        $prompt = "Summarize the following conversation that happened in a Telegram group chat over the last 24 hours. Summary language must be language mostly used in messages. Keep it concise and capture the main topics.\n\n";
        if (!empty($chatInfo)) {
            $prompt .= "Chat Information:\n$chatInfo\n";
        }
        $prompt .= "Conversation:\n" . $conversation;

        try {
            // Log API request
            $logMessage = $logPrefix . "Sending request to DeepSeek API for $chatIdentifier";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $startTime = microtime(true);

            $response = $this->httpClient->post($this->config['deepseek_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['deepseek_api_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $this->config['deepseek_model'],
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a helpful assistant that summarizes Telegram group chats. Summary language must be language mostly used in messages, preferably Russian. Keep it concise and capture the main topics. 

If Chat Username is provided, create links to messages using the format: https://t.me/[username]/[message_id] where [username] is the Chat Username without @ and [message_id] is a message ID you can reference from the conversation.

If only Chat ID is provided (no username), create link using the format: https://t.me/c/[channel_id]/[message_id] where [channel_id] is a channel ID you can reference from the conversation. Remove -100 from the beginning of the Channel ID if exists.

When formatting your responses for Telegram, please use these special formatting conventions:
use only this list of tags, dont use any other html tags
<b>bold</b>, <strong>bold</strong>
<i>italic</i>, <em>italic</em>
<u>underline</u>, <ins>underline</ins>
<s>strikethrough</s>, <strike>strikethrough</strike>, <del>strikethrough</del>
<span class="tg-spoiler">spoiler</span>, <tg-spoiler>spoiler</tg-spoiler>
<b>bold <i>italic bold <s>italic bold strikethrough <span class="tg-spoiler">italic bold strikethrough spoiler</span></s> <u>underline italic bold</u></i> bold</b>
<a href="http://www.example.com/">inline URL</a>
<a href="tg://user?id=123456789">inline mention of a user</a>
<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>
<code>inline fixed-width code</code>
<pre>pre-formatted fixed-width code block</pre>
<pre><code class="language-python">pre-formatted fixed-width code block written in the Python programming language</code></pre>
<blockquote>Block quotation started\nBlock quotation continued\nThe last line of the block quotation</blockquote>
<blockquote expandable>Expandable block quotation started\nExpandable block quotation continued\nExpandable block quotation continued\nHidden by default part of the block quotation started\nExpandable block quotation continued\nThe last line of the block quotation</blockquote>
'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => 1000, // Adjust as needed
                    'temperature' => 0.5, // Adjust for creativity vs factualness
                ],
                'timeout' => 60, // Increase timeout for potentially long API calls
            ]);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            // Log successful API response
            $logMessage = $logPrefix . "Received response from DeepSeek API in {$duration}s for $chatIdentifier";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['choices'][0]['message']['content'])) {
                $summary = trim($body['choices'][0]['message']['content']);
                $summaryLength = strlen($summary);

                // Log successful summary generation
                $logMessage = $logPrefix . "Successfully generated summary ($summaryLength chars) for $chatIdentifier";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                return $summary;
            }

            // Log unexpected API response format
            $logMessage = $logPrefix . "DeepSeek API Response format unexpected for $chatIdentifier: " . json_encode($body);
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return "Error: Could not extract summary from API response\.";

        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';

            // Log request exception
            $logMessage = $logPrefix . "DeepSeek API Request Exception for $chatIdentifier: " . $e->getMessage() . " | Response: " . $errorResponse;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return "Error: Failed to communicate with the summarization service\.";
        } catch (\Exception $e) {
            // Log general exception
            $logMessage = $logPrefix . "Error generating summary for $chatIdentifier: " . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return "Error: An unexpected error occurred during summarization\.";
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
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Grok Response] ";
        $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

        try {
            // Log API request
            $logMessage = $logPrefix . "Generating Grok response for message: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : '');
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Check if the message is requesting an image generation or if user provided an image
            $isImageRequest = $this->isImageGenerationRequest($messageText, $webhookLogFile, $logPrefix);

            if ($isImageRequest) {
                $logMessage = $logPrefix . "Detected " . ($inputImageUrl ? "image input" : "image generation request") . " from " . $username;
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                // Generate image using Grok API
                $imageResult = $this->generateGrokImage($messageText, $webhookLogFile, $logPrefix, $inputImageUrl);

                if ($imageResult) {
                    return [
                        'type' => 'image',
                        'image_url' => $imageResult['url'],
                        'content' => null, // No text content for image responses
                        'prompt' => $imageResult['prompt'],
                        'revised_prompt' => $imageResult['revised_prompt']
                    ];
                }

                // If image generation fails, fall back to text response
                $logMessage = $logPrefix . "Image generation failed, falling back to text response";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            }

            // First, check if we should respond at all
            $shouldRespondPrompt = "You are an assistant that determines if a message mentioning a bot requires a response." .
                "Analyze this message and determine if it's asking the bot to do something, talking about the bot, or just mentioning it in passing. Respond only if bot mentioned in the message. Example mentions: bot, железяка, бот, ботик, Аполон, Аполлон" .
                "Only respond with number from 0 to 100. Higher number means need more likely to respond it chance that the message needs a response." .
                "Otherwise respond with 0.\n\nMessage: \"" . $messageText . "\"";

            $shouldRespondResponse = $this->httpClient->post('https://api.x.ai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['xai_api_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => 'grok-3-beta',
                    'messages' => [
                        ['role' => 'user', 'content' => $shouldRespondPrompt]
                    ],
                    'max_tokens' => 10,
                    'temperature' => 0.1,
                ],
                'timeout' => 30,
            ]);

            $content = $shouldRespondResponse->getBody()->getContents();
            $logMessage = $logPrefix . "Raw shouldRespond API response: " . $content;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($content, true);
            $logMessage = $logPrefix . "Decoded shouldRespond API response: " . json_encode($body);
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $shouldRespond = isset($body['choices'][0]['message']['content']) ?
                (int)$body['choices'][0]['message']['content'] : 0;

            if ($shouldRespond < 50) {
                $logMessage = $logPrefix . "Decided not to respond to message";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                return null;
            }

            // If we should respond with text, generate a response using Grok
            $logMessage = $logPrefix . "Generating Grok text response for message from " . $username;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Add chat context to the prompt if available
            $systemPrompt = "You are a witty, sarcastic bot that responds to mentions with funny memes, jokes, or clever comebacks. " .
                "Keep your response short (1-2 sentences max), funny, and appropriate for a group chat. Don't use quotes, answer from the perspective of the bot but act as the person. " .
                "Response with medium length response up to 5 sentences if message is asking something specific." .
                "Use emojis if you feel it's needed.";

            if (!empty($chatContext)) {
                $systemPrompt .= "\n\n" . $chatContext;
            }

            $params = [
                'model' => 'grok-3-beta',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Respond to this message: \"" . $messageText . "\" from user " . $username]
                ],
                'max_tokens' => 200,
                'temperature' => 0.5, // Higher temperature for more creative responses
            ];

            file_put_contents($webhookLogFile, json_encode($params).PHP_EOL, FILE_APPEND);

            $response = $this->httpClient->post('https://api.x.ai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['xai_api_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => $params,
                'timeout' => 30,
            ]);

            $responseContent = $response->getBody()->getContents();
            $logMessage = $logPrefix . "Raw API response: " . $responseContent;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($responseContent, true);

            if (isset($body['choices'][0]['message']['content'])) {
                $grokResponse = trim($body['choices'][0]['message']['content']);

                // Log successful response generation
                $logMessage = $logPrefix . "Generated text response: " . $grokResponse;
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                return [
                    'type' => 'text',
                    'content' => $grokResponse,
                    'image_url' => null
                ];
            }

            // Log unexpected API response format
            $logMessage = $logPrefix . "X.AI Grok API Response format unexpected: " . json_encode($body);
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;

        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';

            // Log request exception
            $logMessage = $logPrefix . "X.AI Grok API Request Exception: " . $e->getMessage() . " | Response: " . $errorResponse;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;
        } catch (\Exception $e) {
            // Log general exception
            $logMessage = $logPrefix . "Error generating Grok response: " . $e->getMessage();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;
        }
    }

    /**
     * Determine if a message is requesting image generation
     *
     * @param string $messageText The message text to analyze
     * @param string $logFile Path to the log file
     * @param string $logPrefix Prefix for log messages
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return bool Whether the message is requesting image generation
     */
    private function isImageGenerationRequest(string $messageText, string $logFile, string $logPrefix): bool
    {
        try {
            $logMessage = $logPrefix . "Checking if message is requesting image generation";
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            $prompt = "You are an assistant that determines if a message is requesting image generation. " .
                "Analyze this message and determine if it's asking for an image, meme, picture, drawing, or visual content. " .
                "Only respond with 'YES' if the message is clearly requesting image generation, or 'NO' if it's not. " .
                "Examples of image requests: 'generate a picture of...', 'create a meme about...', 'draw me...', 'show me an image of...', " .
                "'make a picture of...', 'нарисуй...', 'покажи картинку...', 'сделай мем...'\n\n";

            $prompt .= "Message: \"" . $messageText . "\"";

            $response = $this->httpClient->post('https://api.x.ai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['xai_api_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => 'grok-3-beta',
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => 10,
                    'temperature' => 0.1,
                ],
                'timeout' => 30,
            ]);

            $responseContent = $response->getBody()->getContents();
            $logMessage = $logPrefix . "Raw API response: " . $responseContent;
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($responseContent, true);

            if (isset($body['choices'][0]['message']['content'])) {
                $answer = strtoupper(trim($body['choices'][0]['message']['content']));
                $isImageRequest = ($answer === 'YES');

                $logMessage = $logPrefix . "Image generation request detection result: " . ($isImageRequest ? 'YES' : 'NO');
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

                return $isImageRequest;
            }

            return false;
        } catch (\Exception $e) {
            $logMessage = $logPrefix . "Error detecting image request: " . $e->getMessage();
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return false;
        }
    }

    /**
     * Generate an image using the Grok API
     *
     * @param string $messageText The message text to use as prompt
     * @param string $logFile Path to the log file
     * @param string $logPrefix Prefix for log messages
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return array|null The generated image data or null if generation failed
     */
    private function generateGrokImage(string $messageText, string $logFile, string $logPrefix, ?string $inputImageUrl = null): ?array
    {
        try {
            $logMessage = $logPrefix . "Generating image with Grok API";
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Extract the image prompt from the message
            $prompt = $this->extractImagePrompt($messageText, $logFile, $logPrefix, $inputImageUrl);

            if (!$prompt) {
                $prompt = $messageText; // Use the original message if extraction fails
            }

            $logMessage = $logPrefix . "Using image prompt: " . $prompt;
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Prepare API request parameters
            $requestParams = [
                'model' => 'grok-2-image-latest',
                'prompt' => $prompt,
                'n' => 1,
                'response_format' => 'url'
            ];

            // If user provided an image, include it in the request
            if ($inputImageUrl) {
                $logMessage = $logPrefix . "Including user-provided image in request: " . $inputImageUrl;
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

                // Note: Grok-2-Vision can analyze the image, but Grok-2-Image doesn't directly support image-to-image generation
                // We're using the prompt extracted by Grok-2-Vision which should include details about the image

                // Enhance the prompt to be more specific about the image transformation
                if (strpos($prompt, 'based on the image') === false &&
                    strpos($prompt, 'from the image') === false &&
                    strpos($prompt, 'in the image') === false) {
                    // If the prompt doesn't already reference the image, add a reference
                    $requestParams['prompt'] = "Based on the user's image: " . $prompt;
                } else {
                    $requestParams['prompt'] = $prompt;
                }

                $logMessage = $logPrefix . "Enhanced image prompt: " . $requestParams['prompt'];
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            }

            // Call the Grok image generation API
            $response = $this->httpClient->post('https://api.x.ai/v1/images/generations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['xai_api_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => $requestParams,
                'timeout' => 60, // Image generation might take longer
            ]);

            $responseContent = $response->getBody()->getContents();
            $logMessage = $logPrefix . "Raw API response: " . $responseContent;
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($responseContent, true);

            if (isset($body['data'][0]['url'])) {
                $imageUrl = $body['data'][0]['url'];
                $revisedPrompt = $body['data'][0]['revised_prompt'] ?? $prompt;

                $logMessage = $logPrefix . "Successfully generated image: " . $imageUrl;
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

                return [
                    'url' => $imageUrl,
                    'prompt' => $prompt,
                    'revised_prompt' => $revisedPrompt
                ];
            }

            $logMessage = $logPrefix . "Failed to generate image, unexpected response: " . json_encode($body);
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;
        } catch (\Exception $e) {
            $logMessage = $logPrefix . "Error generating image: " . $e->getMessage();
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;
        }
    }

    /**
     * Extract the image prompt from a message
     *
     * @param string $messageText The message text
     * @param string $logFile Path to the log file
     * @param string $logPrefix Prefix for log messages
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return string|null The extracted prompt or null if extraction failed
     */
    private function extractImagePrompt(string $messageText, string $logFile, string $logPrefix, ?string $inputImageUrl = null): ?string
    {
        try {
            $logMessage = $logPrefix . "Extracting image prompt from message";
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            if ($inputImageUrl) {
                $logMessage = $logPrefix . "User provided an image: " . $inputImageUrl;
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            }

            $promptBase = "You are an assistant that extracts image generation prompts from messages. " .
                "Given a message that is requesting an image, extract only the description of what should be in the image. " .
                "Just return the clean description of what should be in the image. ";

            if ($inputImageUrl) {
                $promptBase .= "The user has also sent an image. Analyze the image in detail and create a prompt that incorporates both the text request and the content of the image. " .
                    "For example, if the message is 'Make this funnier' with an image of a cat, you might respond with 'Make a funnier version of the cat in the image, exaggerating its features and adding humorous elements'. " .
                    "Be specific about the objects, people, scenes, colors, and other details you can identify in the image. ";
            }

            $promptBase .= "For example, if the message is 'Hey bot, can you draw a cat sitting on a moon?', " .
                "you should respond with 'a cat sitting on a moon'.\n\n" .
                "Message: \"" . $messageText . "\"";

            if ($inputImageUrl) {
                $promptBase .= " (with an attached image)";
            }

            $prompt = $promptBase;

            // Prepare the request based on whether we have an image or not
            if ($inputImageUrl) {
                $imageData = null;
                $mimeType = null;
                try {
                    // 1. Download the image
                    $imageResponse = $this->httpClient->get($inputImageUrl, ['timeout' => 10]); // Add timeout for download
                    if ($imageResponse->getStatusCode() === 200) {
                        $imageData = $imageResponse->getBody()->getContents();

                        // 2. Verify MIME type
                        $finfo = new \finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->buffer($imageData);

                        $supportedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
                        if (!in_array($mimeType, $supportedTypes)) {
                            $logMessage = $logPrefix . "Unsupported image type downloaded: " . $mimeType . " from URL: " . $inputImageUrl;
                            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
                            return null; // Unsupported type
                        }

                        // 3. Encode as Base64
                        $base64Image = base64_encode($imageData);
                        $dataUri = "data:" . $mimeType . ";base64," . $base64Image;

                        // Log successful download and encoding
                        $logMessage = $logPrefix . "Successfully downloaded and base64 encoded image (" . $mimeType . ") from: " . $inputImageUrl;
                        file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

                    } else {
                        $logMessage = $logPrefix . "Failed to download image. Status code: " . $imageResponse->getStatusCode() . " from URL: " . $inputImageUrl;
                        file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
                        return null;
                    }
                } catch (RequestException $e) {
                     $errorMessage = $logPrefix . "HTTP Request failed during image download: " . $e->getMessage();
                     if ($e->hasResponse()) {
                         $errorMessage .= " | Response: " . $e->getResponse()->getBody()->getContents();
                     }
                     file_put_contents($logFile, $errorMessage . PHP_EOL, FILE_APPEND);
                     return null;
                } catch (\Exception $e) {
                     $errorMessage = $logPrefix . "Error processing image for prompt extraction: " . $e->getMessage();
                     file_put_contents($logFile, $errorMessage . PHP_EOL, FILE_APPEND);
                     return null;
                }

                // Ensure we have the data URI before proceeding
                if (!$dataUri) {
                     $logMessage = $logPrefix . "Could not obtain image data URI. Aborting prompt extraction.";
                     file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
                     return null;
                }

                // 4. Format the request according to Grok API documentation using base64 data URI
                $response = $this->httpClient->post('https://api.x.ai/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['xai_api_key'],
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model' => 'grok-2-vision-latest',
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => [
                                    [
                                        'type' => 'image_url',
                                        'image_url' => [
                                            'url' => $dataUri, // Send base64 data URI
                                            // 'detail' => 'high' // Detail might not be applicable or needed for data URIs
                                        ]
                                    ],
                                    [
                                        'type' => 'text',
                                        'text' => $prompt
                                    ]
                                ]
                            ]
                        ],
                        'temperature' => 0.1,
                        // 'max_tokens' => 150, // Optional: Add max_tokens if needed for vision
                    ],
                    'timeout' => 60, // Keep increased timeout
                ]);

                // Log the request type
                $logMessage = $logPrefix . "Sent image understanding request to Grok API (/chat/completions) with Base64 image data.";
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            } else {
                // Use the standard /chat/completions endpoint for text-only as well
                $response = $this->httpClient->post('https://api.x.ai/v1/chat/completions', [ // Changed endpoint
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['xai_api_key'],
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model' => 'grok-3-beta',
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt]
                        ],
                        'max_tokens' => 100,
                        'temperature' => 0.1,
                    ],
                    'timeout' => 30,
                ]);
                // Log the request for debugging
                $logMessage = $logPrefix . "Sent text-only request to Grok API (/chat/completions)";
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            }

            $responseContent = $response->getBody()->getContents();
            $logMessage = $logPrefix . "Raw API response: " . $responseContent;
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($responseContent, true);
            // Log the decoded body to help debug if parsing fails
            $logMessage = $logPrefix . "Decoded API response: " . json_encode($body);
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Standard response parsing for /chat/completions
            if (isset($body['choices'][0]['message']['content'])) {
                $extractedPrompt = trim($body['choices'][0]['message']['content']);
                $logMessage = $logPrefix . "Extracted prompt: " . $extractedPrompt;
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
                return $extractedPrompt;
            } else {
                // Log the structure if the expected path is not found
                $logMessage = $logPrefix . "Failed to extract prompt. Response structure might be different. Body: " . json_encode($body);
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
                return null; // Indicate failure
            }

        } catch (RequestException $e) {
            // Log Guzzle request exceptions
            $errorMessage = $logPrefix . "HTTP Request failed: " . $e->getMessage();
            if ($e->hasResponse()) {
                $errorMessage .= " | Response: " . $e->getResponse()->getBody()->getContents();
            }
            file_put_contents($logFile, $errorMessage . PHP_EOL, FILE_APPEND);
            return null;
        } catch (\Exception $e) {
            // Log any other exceptions
            $errorMessage = $logPrefix . "Error extracting image prompt: " . $e->getMessage();
            file_put_contents($logFile, $errorMessage . PHP_EOL, FILE_APPEND);
            return null;
        }
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
                    'reply_to_message_id' => $replyToMessageId
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
