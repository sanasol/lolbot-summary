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
    public function handleSummaryCommand(int $chatId, ?string $window = null, ?int $replyToMessageId = null): void
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

        if ($chatId > 0) {
            $this->logger->logCommand("Summaries are disabled for chat {$chatId}", "summary");
            return;
        }

        // Determine time window
        [$startTs, $endTs, $windowLabel] = $this->parseSummaryWindow($window);
        $this->logger->logCommand("Using summary window {$windowLabel} (" . gmdate('c', $startTs) . " to " . gmdate('c', $endTs) . ") for chat {$chatId}", "summary");

        // Fetch messages in the window
        $messages = $this->messageStorage->getMessagesInRange($chatId, $startTs, $endTs);
        $messageCount = count($messages);
        $this->logger->logCommand("Retrieved {$messageCount} messages for chat {$chatId}", "summary");

        if (empty($messages)) {
            $this->logger->logCommand("No messages found to summarize for chat {$chatId}", "summary");
            if ($replyToMessageId !== null) {
                Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "No messages found for the requested window: {$windowLabel} (UTC).",
                    'reply_to_message_id' => $replyToMessageId
                ]);
            }
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

        $summary = $this->aiService->generateChatSummary($messages, $chatId, $chatTitle, $chatUsername, $windowLabel);

        if ($summary) {
            $summaryLength = strlen($summary);
            $this->logger->logCommand("Sending summary ({$summaryLength} chars) to chat {$chatId}", "summary");
            $this->logger->log($summary, "Summary Content", "webhook");

            $summaryWithBlockquote = "<blockquote expandable>" . $summary . "</blockquote>

#dailySummary";
            $sendResult = Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $summaryWithBlockquote,
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

                // Fallback: send as plain text without HTML if parsing failed
                try {
                    $this->logger->logCommand("Attempting plain-text fallback for chat {$chatId}", "summary");

                    $fallbackText = $this->stripHtmlToPlainText($summaryWithBlockquote);

                    $fallbackSendResult = Request::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $fallbackText,
                        'disable_web_page_preview' => true,
                    ]);

                    if ($fallbackSendResult->isOk()) {
                        $this->logger->logCommand("Plain-text fallback summary successfully sent to chat {$chatId}", "summary");

                        // Try to pin the message sent via fallback
                        try {
                            $fallbackMessageId = $fallbackSendResult->getResult()->getMessageId();
                            if ($fallbackMessageId) {
                                $pinResult = $this->sender->pinChatMessage($chatId, $fallbackMessageId);
                                if ($pinResult->isOk()) {
                                    $this->logger->logCommand("Fallback summary message successfully pinned in chat {$chatId}", "summary");
                                } else {
                                    $this->logger->logError(
                                        "Failed to pin fallback summary message in chat {$chatId}: " . $pinResult->getDescription(),
                                        "Command:summary"
                                    );
                                }
                            } else {
                                $this->logger->logError("Cannot pin fallback message in chat {$chatId}: Invalid message ID", "Command:summary");
                            }
                        } catch (\Exception $e) {
                            $this->logger->logError("Exception when pinning fallback message in chat {$chatId}", "Command:summary", $e);
                        }
                    } else {
                        $this->logger->logError(
                            "Fallback plain-text send also failed for chat {$chatId}: " . $fallbackSendResult->getDescription(),
                            "Command:summary"
                        );
                    }
                } catch (\Throwable $e) {
                    $this->logger->logError("Exception during fallback send for chat {$chatId}: " . $e->getMessage(), "Command:summary", $e);
                }
            }
        } else {
            $this->logger->logError("Failed to generate summary for chat {$chatId}", "Command:summary");

//            Request::sendMessage([
//                'chat_id' => $chatId,
//                'text' => 'Sorry, I couldn\'t generate a summary at this time\.',
//                'parse_mode' => 'MarkdownV2'
//            ]);
        }
    }

    /**
     * Handle the /mcp command
     *
     * @param int $chatId The chat ID
     * @param string $messageText The message text after the command
     * @param string $username The username of the message sender
     * @param int $messageId The message ID to reply to
     * @param int|null $userId The user ID for checking subscription status
     * @return bool Whether the command was handled successfully
     */
    public function handleMCPCommand(int $chatId, string $messageText, string $username, int $messageId, ?int $userId = null): bool
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
            $response = $this->generateMCPResponse($messageText, $username, $chatContext, $userId);

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

            case 'time':
            case 'summary_time':
                $hour = $parts[1] ?? '';
                $this->setSummaryHour($chatId, $hour, $messageId);
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

        $summaryHourUtc = $settings['summary_hour_utc'] ?? 8;
        $message = "📊 *Current Settings*\n\n" .
            "🌐 *Language*: {$languageName}\n" .
            "📝 *Summary*: {$summaryEnabled}\n" .
            "⏰ *Summary Time (UTC)*: {$summaryHourUtc}:00\n" .
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
            "• `/settings time [0-23]` - Set daily summary hour (UTC). Default is 8.\n" .
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
     * Show general help with command overview and /summary time windows
     *
     * @param int $chatId The chat ID
     * @param int $messageId Message ID to reply to
     * @return void
     */
    public function handleHelpCommand(int $chatId, int $messageId): void
    {
        $message = "🆘 Help\n\n" .
            "• `/summary [window]` — Generate a chat summary. If no window is provided, uses the last 24h.\n" .
            "  Supported time windows (UTC):\n" .
            "  • `Nh`, `Nm`, `Nd` — last N hours/minutes/days (e.g., `2h`, `30m`, `1d`)\n" .
            "  • `today` — from 00:00 UTC today to now\n" .
            "  • `yesterday` — full previous UTC day (00:00–23:59:59)\n" .
            "  • `YYYY-MM-DD` — a specific UTC date (full day)\n" .
            "  • `HH:MM-HH:MM` — a time range today in UTC (can cross midnight, e.g., `23:00-01:00`)\n" .
            "  Note: maximum window is 7 days; longer ranges will be capped.\n\n" .
            "Other commands:\n" .
            "• `/settings` — Show or change group settings (admins only).\n" .
            "• `/mcp [query]` — Ask the bot to answer using recent chat context.\n" .
            "• `/account [token]` — Link your Statbate+ account in a private chat.\n";

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
     * Set daily summary hour (UTC) for a chat
     *
     * @param int $chatId The chat ID
     * @param string $hour The hour string (0-23)
     * @param int $messageId Message ID to reply to
     * @return void
     */
    private function setSummaryHour(int $chatId, string $hour, int $messageId): void
    {
        if ($hour === '') {
            $current = (int)$this->settingsService->getSetting($chatId, 'summary_hour_utc', 8);
            $message = "⚠️ Please provide an hour between 0 and 23 (UTC).\nCurrent value: {$current}:00 UTC";
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'reply_to_message_id' => $messageId
            ]);
            return;
        }

        // Accept forms like "8" or "08"
        if (!ctype_digit($hour)) {
            $message = "⚠️ Invalid value. Use an integer hour between 0 and 23 (UTC). Example: `/settings time 8`";
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'reply_to_message_id' => $messageId
            ]);
            return;
        }

        $int = (int)$hour;
        if ($int < 0 || $int > 23) {
            $message = "⚠️ Invalid hour. Please use a value between 0 and 23 (UTC).";
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'reply_to_message_id' => $messageId
            ]);
            return;
        }

        $this->settingsService->updateSetting($chatId, 'summary_hour_utc', $int);

        $padded = str_pad((string)$int, 2, '0', STR_PAD_LEFT);
        $message = "✅ Daily summary time set to *{$padded}:00 UTC*.";
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
     * @param int|null $userId The user ID for checking subscription status
     * @return array The generated response or error information
     */
    private function generateMCPResponse(string $messageText, string $username, string $chatContext = '', ?int $userId = null): array
    {
        return $this->aiService->generateMCPResponse($messageText, $username, $chatContext, $userId);
    }

    /**
     * Handle the /account command to save external user account identifier
     * This command only works in private messages to the bot
     *
     * @param int $chatId The chat ID
     * @param string $accountIdentifier The account identifier to save
     * @param int $userId The user ID
     * @param int $messageId The message ID to reply to
     * @param bool $isPrivateChat Whether this is a private chat
     * @return bool Whether the command was handled successfully
     */
    public function handleAccountCommand(int $chatId, string $accountIdentifier, int $userId, int $messageId, bool $isPrivateChat): bool
    {
        try {
            // Log the command
            $this->logger->logCommand(
                "Received /account command in chat {$chatId} with identifier: " . substr($accountIdentifier, 0, 10) . (strlen($accountIdentifier) > 10 ? '...' : ''),
                "account"
            );

            // Check if this is a private chat
            if (!$isPrivateChat) {
                $this->logger->logCommand("Account command rejected - not a private chat", "account");

                Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => '⚠️ This command can only be used in private messages to the bot for security reasons.',
                    'reply_to_message_id' => $messageId,
                ]);

                return false;
            }

            // If no account identifier provided, send usage instructions
            if (empty(trim($accountIdentifier))) {
                $helpText = "Please provide your account identifier after the /account command. For example:\n/account your_account_identifier";

                $sendResult = Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $helpText,
                    'reply_to_message_id' => $messageId,
                ]);

                return $sendResult->isOk();
            }

            // Verify the account identifier by making an API call
            $isValid = $this->verifyAccountIdentifier($accountIdentifier);

            if (!$isValid) {
                $this->logger->logCommand("Invalid account identifier provided: {$accountIdentifier}", "account");

                Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ The account identifier you provided is invalid or has no active subscription. Please check and try again.',
                    'reply_to_message_id' => $messageId,
                ]);

                return false;
            }

            // Save the account identifier in user settings
            $this->settingsService->updateSetting($userId, 'account_identifier', $accountIdentifier);

            $this->logger->logCommand("Successfully saved account identifier for user {$userId}", "account");

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => '✅ Your account identifier has been successfully saved and verified. You now have access to extended data queries beyond the 30-day limitation.',
                'reply_to_message_id' => $messageId,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->logError("Error handling account command", "Command:account", $e);

            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Sorry, an error occurred while processing your request.',
                'reply_to_message_id' => $messageId,
            ]);

            return false;
        }
    }

    /**
     * Verify an account identifier by making an API call
     *
     * @param string $accountIdentifier The account identifier to verify
     * @return bool Whether the account identifier is valid and has an active subscription
     */
    private function verifyAccountIdentifier(string $accountIdentifier): bool
    {
        try {
            $client = $this->getHttpClient();

            $response = $client->request('GET', 'https://plus.statbate.com/api/me/simple', [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $accountIdentifier
                ]
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $data = json_decode($response->getBody(), true);

                // Check if the user has an active subscription
                if (isset($data['subscription']) && isset($data['subscription']['is_active']) && $data['subscription']['is_active'] === true) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->logError("Error verifying account identifier: " . $e->getMessage(), "Account Verification", $e);
            return false;
        }
    }

    /**
     * Get HTTP client for API requests
     *
     * @return \GuzzleHttp\Client
     */
    private function getHttpClient(): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client([
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);
    }

    /**
     * Parse summary window string into [startTs, endTs, label]. All times in UTC.
     * Supported examples:
     * - "2h", "30m", "1d"
     * - "today", "yesterday"
     * - "YYYY-MM-DD" (UTC day)
     * - "HH:MM-HH:MM" (today, UTC)
     * If invalid or empty, defaults to last 24h.
     */
    private function parseSummaryWindow(?string $window): array
    {
        $now = time();
        $endTs = $now;
        $label = 'last 24h';
        $startTs = $endTs - 24 * 3600;

        $w = trim((string)$window);
        if ($w === '') {
            return [$startTs, $endTs, $label];
        }
        $wLower = strtolower($w);

        // Duration formats: Nh / Nm / Nd
        if (preg_match('/^(\d{1,3})\s*([hmd])$/i', $wLower, $m)) {
            $n = (int)$m[1];
            $unit = strtolower($m[2]);
            $seconds = $unit === 'h' ? $n * 3600 : ($unit === 'm' ? $n * 60 : $n * 86400);
            $endTs = $now;
            $startTs = $endTs - $seconds;
            $label = "last {$n}{$unit}";
            return $this->capWindow([$startTs, $endTs, $label]);
        }

        // today
        if ($wLower === 'today') {
            $y = (int)gmdate('Y', $now);
            $m = (int)gmdate('m', $now);
            $d = (int)gmdate('d', $now);
            $startTs = gmmktime(0, 0, 0, $m, $d, $y);
            $endTs = $now;
            $label = 'today';
            return [$startTs, $endTs, $label];
        }

        // yesterday
        if ($wLower === 'yesterday') {
            $y = (int)gmdate('Y', $now);
            $m = (int)gmdate('m', $now);
            $d = (int)gmdate('d', $now);
            $todayStart = gmmktime(0, 0, 0, $m, $d, $y);
            $startTs = $todayStart - 86400;
            $endTs = $todayStart - 1;
            $label = 'yesterday';
            return [$startTs, $endTs, $label];
        }

        // Date format YYYY-MM-DD (UTC)
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $wLower, $m)) {
            $startTs = gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
            $endTs = $startTs + 86400 - 1;
            $label = $wLower;
            return [$startTs, $endTs, $label];
        }

        // Time range today: HH:MM-HH:MM (UTC)
        if (preg_match('/^(\d{2}):(\d{2})\s*-\s*(\d{2}):(\d{2})$/', $wLower, $m)) {
            $y = (int)gmdate('Y', $now);
            $mon = (int)gmdate('m', $now);
            $d = (int)gmdate('d', $now);
            $startTs = gmmktime((int)$m[1], (int)$m[2], 0, $mon, $d, $y);
            $endTs = gmmktime((int)$m[3], (int)$m[4], 59, $mon, $d, $y);
            if ($endTs < $startTs) {
                // Assume crossing midnight -> add a day to end
                $endTs += 86400;
            }
            $label = $wLower . ' today';
            return $this->capWindow([$startTs, $endTs, $label]);
        }

        // Fallback: return default last 24h
        return [$startTs, $endTs, $label];
    }

    /**
     * Ensure the window is not larger than 7 days.
     * If it is, cap to last 7 days ending at endTs and annotate label.
     */
    private function capWindow(array $triple): array
    {
        [$startTs, $endTs, $label] = $triple;
        $max = 7 * 86400;
        if (($endTs - $startTs) > $max) {
            $startTs = $endTs - $max;
            $label .= ' (capped to 7d)';
        }
        return [$startTs, $endTs, $label];
    }

    /**
     * Convert possibly-HTML summary into plain text suitable for Telegram without parse_mode
     */
    private function stripHtmlToPlainText(string $html): string
    {
        // Replace common HTML structures with text-friendly equivalents before stripping tags
        $patterns = [
            '/<\s*br\s*\/?\s*>/i',
            '/<\s*\/p\s*>/i',
            '/<\s*p\s*>/i',
            '/<\s*li\s*>/i',
            '/<\s*\/li\s*>/i',
            '/<\s*\/ul\s*>/i',
            '/<\s*ul\s*>/i',
            '/<\s*\/ol\s*>/i',
            '/<\s*ol\s*>/i',
            '/<\s*blockquote[^>]*>/i',
            '/<\s*\/blockquote\s*>/i',
        ];
        $replacements = [
            "\n",
            "\n\n",
            '',
            "• ",
            "\n",
            "\n",
            "\n",
            "\n",
            "\n",
            '',
            "\n",
        ];

        $text = preg_replace($patterns, $replacements, $html);
        if ($text === null) {
            $text = $html; // fallback if preg_replace fails
        }

        // Strip any remaining tags and decode entities
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace/newlines
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);

        // Ensure the hashtag is present at the end for discoverability
        if ($text !== '' && !str_contains($text, '#dailySummary')) {
            $text .= "\n\n#dailySummary";
        }

        return $text;
    }
}
