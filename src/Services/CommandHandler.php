<?php

namespace App\Services;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\Message;

/**
 * Handles bot commands
 */
class CommandHandler
{
    private AIService $aiService;
    private SettingsService $settingsService;
    private MessageStorage $messageStorage;
    private LoggerService $logger;
    private TelegramSender $sender;
    private array $config;

    public function __construct(
        AIService $aiService,
        SettingsService $settingsService,
        MessageStorage $messageStorage,
        LoggerService $logger,
        TelegramSender $sender,
        array $config
    ) {
        $this->aiService = $aiService;
        $this->settingsService = $settingsService;
        $this->messageStorage = $messageStorage;
        $this->logger = $logger;
        $this->sender = $sender;
        $this->config = $config;
    }

    /**
     * Handle the /summary command
     *
     * @param int $chatId The chat ID
     * @return void
     */
    public function handleSummaryCommand(int $chatId): void
    {
        $this->logger->logCommand("Handling /summary command for chat {$chatId}", "summary");
        echo "Handling /summary command for chat {$chatId}\n";

        // Check if summaries are enabled for this chat
        $summaryEnabled = $this->settingsService->getSetting($chatId, 'summary_enabled', true);
        if (!$summaryEnabled) {
            $this->logger->logCommand("Summaries are disabled for chat {$chatId}, skipping", "summary");

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Summaries are currently disabled for this chat. An administrator can enable them using `/settings summary on`.',
                'parse_mode' => 'Markdown'
            ]);

            return;
        }

        $messages = $this->messageStorage->getRecentMessages($chatId, 24);
        $messageCount = count($messages);
        $this->logger->logCommand("Retrieved {$messageCount} messages for chat {$chatId}", "summary");

        if (empty($messages)) {
            $this->logger->logCommand("No messages found to summarize for chat {$chatId}", "summary");

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
            $this->logger->logCommand("Fetching chat info for chat {$chatId}", "summary");

            $result = Request::getChat(['chat_id' => $chatId]);
            if ($result->isOk()) {
                $chatInfo = $result->getResult();
                $this->logger->logCommand("Successfully retrieved chat info for chat {$chatId}", "summary");
            } else {
                $errorDesc = $result->getDescription();
                $this->logger->logError("Failed to get chat info: " . $errorDesc, "Command:summary");
            }
        } catch (\Exception $e) {
            $this->logger->logError("Error getting chat info: " . $e->getMessage(), "Command:summary", $e);
        }

        // Extract chat details
        $chatTitle = $chatInfo ? $chatInfo->getTitle() : null;
        $chatUsername = $chatInfo ? $chatInfo->getUsername() : null;

        $this->logger->logCommand(
            "Generating summary for chat {$chatId}" .
            ($chatTitle ? ", Title: {$chatTitle}" : "") .
            ($chatUsername ? ", Username: {$chatUsername}" : ""),
            "summary"
        );

        $summary = $this->aiService->generateChatSummary($messages, $chatId, $chatTitle, $chatUsername);

        if ($summary) {
            $summaryLength = strlen($summary);
            $this->logger->logCommand("Sending summary ({$summaryLength} chars) to chat {$chatId}", "summary");
            $this->logger->log($summary, "Summary Content", "webhook");

            $sendResult = Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $summary.'

#dailySummary',
                'parse_mode' => 'HTML'
            ]);

            if ($sendResult->isOk()) {
                $this->logger->logCommand("Summary successfully sent to chat {$chatId}", "summary");

                // Try to pin the message
                try {
                    $messageId = $sendResult->getResult()->getMessageId();

                    if (!$messageId) {
                        $this->logger->logError("Cannot pin message in chat {$chatId}: Invalid message ID", "Command:summary");
                    } else {
                        $pinResult = $this->sender->pinChatMessage($chatId, $messageId);

                        if ($pinResult->isOk()) {
                            $this->logger->logCommand("Summary message successfully pinned in chat {$chatId}", "summary");
                        } else {
                            $this->logger->logError(
                                "Failed to pin summary message in chat {$chatId}: " . $pinResult->getDescription(),
                                "Command:summary"
                            );
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->logError("Exception when pinning message in chat {$chatId}", "Command:summary", $e);
                }
            } else {
                $this->logger->logError(
                    "Failed to send summary to chat {$chatId}: " . $sendResult->getDescription(),
                    "Command:summary"
                );
            }
        } else {
            $this->logger->logError("Failed to generate summary for chat {$chatId}", "Command:summary");

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Sorry, I couldn\'t generate a summary at this time\.',
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    }

    /**
     * Handle the /mcp command
     *
     * @param int $chatId The chat ID
     * @param string $messageText The message text after the command
     * @param string $username The username of the message sender
     * @param int $messageId The message ID to reply to
     * @return bool Whether the command was handled successfully
     */
    public function handleMCPCommand(int $chatId, string $messageText, string $username, int $messageId): bool
    {
        try {
            // Log the command
            $this->logger->logCommand(
                "Received MCP command in chat {$chatId} from {$username}: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : ''),
                "mcp"
            );

            // If no message text provided, send usage instructions
            if (empty(trim($messageText))) {
                $helpText = "Please provide a query after the /mcp command. For example:\n/mcp waaaaaat?";

                $sendResult = Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $helpText,
                    'reply_to_message_id' => $messageId,
                ]);

                return $sendResult->isOk();
            }

            // Send a "typing" action to indicate the bot is working
            $this->sender->sendChatAction($chatId, 'typing');

            // Get recent messages for context
            $recentMessages = $this->messageStorage->getRecentChatContext($chatId);
            $chatContext = '';

            if (!empty($recentMessages)) {
                $chatContext = "Recent conversation in the chat:\n" . implode("\n", $recentMessages) . "\n\n";
                $this->logger->logCommand("Added " . count($recentMessages) . " recent messages as context", "mcp");
            }

            // Generate response using MCP
            $response = $this->generateMCPResponse($messageText, $username, $chatContext);

            // Check if this is an error response
            if (isset($response['type']) && $response['type'] === 'error') {
                $this->logger->logError(
                    "Received error response: " . ($response['error_type'] ?? 'unknown') . " - " . ($response['content'] ?? 'No error message'),
                    "Command:mcp"
                );

                // Send the error message to the user
                $errorMessage = $response['content'] ?? 'Sorry, I was unable to process your request at this time.';

                $sendResult = Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $errorMessage,
                    'reply_to_message_id' => $messageId,
                ]);

                if ($sendResult->isOk()) {
                    $this->logger->logCommand("Successfully sent error response to chat {$chatId}", "mcp");
                } else {
                    $this->logger->logError(
                        "Failed to send error response to chat {$chatId}: " . $sendResult->getDescription(),
                        "Command:mcp"
                    );
                }

                return false;
            }

            $responseText = $response['content'];

            // Send the response
            $sendResult = $this->sender->sendHtmlAsMarkdownMessage(
                $chatId,
                $responseText,
                $messageId
            );

            if ($sendResult->isOk()) {
                $this->logger->logCommand("Successfully sent MCP response to chat {$chatId}", "mcp");
                return true;
            }

            $this->logger->logError(
                "Failed to send MCP response to chat {$chatId}: " . $sendResult->getDescription(),
                "Command:mcp"
            );

            $sendResult = Request::sendMessage([
                'chat_id' => $chatId,
                'text' => strip_tags($responseText),
                'reply_to_message_id' => $messageId,
            ]);

            if ($sendResult->isOk()) {
                $this->logger->logCommand("Fallback text response sent to chat {$chatId}", "mcp");
            } else {
                $this->logger->logError(
                    "Fallback text response also failed to send to chat {$chatId}: " . $sendResult->getDescription(),
                    "Command:mcp"
                );
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->logError("Error handling MCP command", "Command:mcp", $e);

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Sorry, an error occurred while processing your request.',
                'reply_to_message_id' => $messageId,
            ]);

            return false;
        }
    }

    /**
     * Handle the /settings command
     *
     * @param int $chatId The chat ID
     * @param string $params Command parameters
     * @param string $fromUser Username of the user who sent the command
     * @param int $messageId Message ID of the command
     * @param Message $message The message object
     * @return void
     */
    public function handleSettingsCommand(int $chatId, string $params, string $fromUser, int $messageId, Message $message): void
    {
        // Check if user is admin
        $isAdmin = $this->isUserAdmin($chatId, $message->getFrom()->getId());

        if (!$isAdmin) {
            $this->logger->logCommand("User {$fromUser} is not an admin in chat {$chatId}, denying access to settings", "settings");

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ Only group administrators can change settings.',
                'reply_to_message_id' => $messageId
            ]);

            return;
        }

        // Parse parameters
        $parts = explode(' ', $params);
        $action = $parts[0] ?? '';

        // If no parameters, show current settings
        if (empty($params)) {
            $this->showSettings($chatId, $messageId);
            return;
        }

        // Handle different actions
        switch ($action) {
            case 'language':
                $language = $parts[1] ?? '';
                $this->setLanguage($chatId, $language, $messageId);
                break;

            case 'summary':
                $enabled = $parts[1] ?? '';
                $this->setSummaryEnabled($chatId, $enabled, $messageId);
                break;

            case 'mentions':
                $enabled = $parts[1] ?? '';
                $this->setBotMentionsEnabled($chatId, $enabled, $messageId);
                break;

            case 'help':
            default:
                $this->showSettingsHelp($chatId, $messageId);
                break;
        }
    }

    /**
     * Check if a user is an admin in a chat
     *
     * @param int $chatId The chat ID
     * @param int $userId The user ID
     * @return bool Whether the user is an admin
     */
    private function isUserAdmin(int $chatId, int $userId): bool
    {
        try {
            $chatMember = Request::getChatMember([
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            if ($chatMember->isOk()) {
                $status = $chatMember->getResult()->getStatus();
                return in_array($status, ['creator', 'administrator']);
            }
        } catch (\Exception $e) {
            $this->logger->logError("Error checking admin status", "Admin Check", $e);
        }

        return false;
    }

    /**
     * Show current settings for a chat
     *
     * @param int $chatId The chat ID
     * @param int $messageId Message ID to reply to
     * @return void
     */
    private function showSettings(int $chatId, int $messageId): void
    {
        $settings = $this->settingsService->getSettings($chatId);
        $languages = $this->settingsService->getAvailableLanguages();

        $languageName = $languages[$settings['language']] ?? $settings['language'];
        $summaryEnabled = $settings['summary_enabled'] ? '✅ Enabled' : '❌ Disabled';
        $mentionsEnabled = $settings['bot_mentions_enabled'] ? '✅ Enabled' : '❌ Disabled';

        $message = "📊 *Current Settings*\n\n" .
            "🌐 *Language*: {$languageName}\n" .
            "📝 *Summary*: {$summaryEnabled}\n" .
            "🤖 *Bot Mentions*: {$mentionsEnabled}\n\n" .
            "Use `/settings help` to see available commands.";

        Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_to_message_id' => $messageId
        ]);
    }

    /**
     * Show settings help
     *
     * @param int $chatId The chat ID
     * @param int $messageId Message ID to reply to
     * @return void
     */
    private function showSettingsHelp(int $chatId, int $messageId): void
    {
        $languages = $this->settingsService->getAvailableLanguages();
        $languageOptions = [];

        foreach ($languages as $code => $name) {
            $languageOptions[] = "`{$code}` ({$name})";
        }

        $languageList = implode(', ', $languageOptions);

        $message = "⚙️ *Settings Commands*\n\n" .
            "• `/settings` - Show current settings\n" .
            "• `/settings language [code]` - Set language\n" .
            "  Available languages: {$languageList}\n" .
            "• `/settings summary [on/off]` - Enable/disable summaries\n" .
            "• `/settings mentions [on/off]` - Enable/disable bot mentions\n" .
            "• `/settings help` - Show this help message\n\n" .
            "Only group administrators can change settings.";

        Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_to_message_id' => $messageId
        ]);
    }

    /**
     * Set language for a chat
     *
     * @param int $chatId The chat ID
     * @param string $language The language code
     * @param int $messageId Message ID to reply to
     * @return void
     */
    private function setLanguage(int $chatId, string $language, int $messageId): void
    {
        $languages = $this->settingsService->getAvailableLanguages();

        if (empty($language) || !isset($languages[$language])) {
            $languageOptions = [];

            foreach ($languages as $code => $name) {
                $languageOptions[] = "`{$code}` ({$name})";
            }

            $languageList = implode(', ', $languageOptions);

            $message = "⚠️ Invalid language code.\n\nAvailable languages: {$languageList}";

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'reply_to_message_id' => $messageId
            ]);

            return;
        }

        $this->settingsService->updateSetting($chatId, 'language', $language);
        $languageName = $languages[$language];

        $message = "✅ Language set to *{$languageName}* (`{$language}`)";

        Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_to_message_id' => $messageId
        ]);
    }

    /**
     * Enable or disable summaries for a chat
     *
     * @param int $chatId The chat ID
     * @param string $enabled Whether summaries are enabled ('on', 'off', 'true', 'false')
     * @param int $messageId Message ID to reply to
     * @return void
     */
    private function setSummaryEnabled(int $chatId, string $enabled, int $messageId): void
    {
        $value = $this->parseBoolean($enabled);

        if ($value === null) {
            $message = "⚠️ Invalid value. Please use `on` or `off`.";

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'reply_to_message_id' => $messageId
            ]);

            return;
        }

        $this->settingsService->updateSetting($chatId, 'summary_enabled', $value);

        $status = $value ? 'enabled' : 'disabled';
        $emoji = $value ? '✅' : '❌';

        $message = "{$emoji} Summaries are now *{$status}* for this chat.";

        Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_to_message_id' => $messageId
        ]);
    }

    /**
     * Enable or disable bot mentions for a chat
     *
     * @param int $chatId The chat ID
     * @param string $enabled Whether bot mentions are enabled ('on', 'off', 'true', 'false')
     * @param int $messageId Message ID to reply to
     * @return void
     */
    private function setBotMentionsEnabled(int $chatId, string $enabled, int $messageId): void
    {
        $value = $this->parseBoolean($enabled);

        if ($value === null) {
            $message = "⚠️ Invalid value. Please use `on` or `off`.";

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'reply_to_message_id' => $messageId
            ]);

            return;
        }

        $this->settingsService->updateSetting($chatId, 'bot_mentions_enabled', $value);

        $status = $value ? 'enabled' : 'disabled';
        $emoji = $value ? '✅' : '❌';

        $message = "{$emoji} Bot mentions are now *{$status}* for this chat.";

        Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_to_message_id' => $messageId
        ]);
    }

    /**
     * Parse a boolean value from a string
     *
     * @param string $value The string value
     * @return bool|null The boolean value, or null if invalid
     */
    private function parseBoolean(string $value): ?bool
    {
        $value = strtolower(trim($value));

        if (in_array($value, ['on', 'true', 'yes', '1', 'enable', 'enabled'])) {
            return true;
        }

        if (in_array($value, ['off', 'false', 'no', '0', 'disable', 'disabled'])) {
            return false;
        }

        return null;
    }

    /**
     * Generate a response using MCP (Multi-Content Payload) via AIService
     *
     * @param string $messageText The message text to process
     * @param string $username The username of the message sender
     * @param string $chatContext Optional context from recent chat messages
     * @return array The generated response or error information
     */
    private function generateMCPResponse(string $messageText, string $username, string $chatContext = ''): array
    {
        return $this->aiService->generateMCPResponse($messageText, $username, $chatContext);
    }
}
