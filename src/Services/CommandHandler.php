<?php

namespace App\Services;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\CallbackQuery;
use App\Services\MuteService;

/**
 * Handles bot commands
 */
class CommandHandler
{
    private const VOTE_TYPES = ['ban','mute'];
    private AIService $aiService;
    private SettingsService $settingsService;
    private MessageStorage $messageStorage;
    private LoggerService $logger;
    private TelegramSender $sender;
    private VoteService $voteService;
    private MuteService $muteService;
    private array $config;

    public function __construct(
        AIService $aiService,
        SettingsService $settingsService,
        MessageStorage $messageStorage,
        LoggerService $logger,
        TelegramSender $sender,
        VoteService $voteService,
        MuteService $muteService,
        array $config
    ) {
        $this->aiService = $aiService;
        $this->settingsService = $settingsService;
        $this->messageStorage = $messageStorage;
        $this->logger = $logger;
        $this->sender = $sender;
        $this->voteService = $voteService;
        $this->muteService = $muteService;
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

            // Ensure fallback message is sent into configured topic/thread if set
            $disabledParams = [
                'chat_id' => $chatId,
                'text' => '❌ Summaries are currently disabled for this chat. An administrator can enable them using `/settings summary on`.',
                'parse_mode' => 'Markdown'
            ];
            try {
                $configuredThread = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
                if ($configuredThread !== null) {
                    $disabledParams['message_thread_id'] = (int)$configuredThread;
                }
            } catch (\Throwable $e) {
                $this->logger->logError('Failed to read message_thread_id setting: ' . $e->getMessage(), 'Command:summary');
            }
            Request::sendMessage($disabledParams);

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
                // Ensure fallback message is sent into configured topic/thread if set
                $noMsgsParams = [
                    'chat_id' => $chatId,
                    'text' => "No messages found for the requested window: {$windowLabel} (UTC).",
                    'reply_to_message_id' => $replyToMessageId
                ];
                try {
                    $configuredThread = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
                    if ($configuredThread !== null) {
                        $noMsgsParams['message_thread_id'] = (int)$configuredThread;
                    }
                } catch (\Throwable $e) {
                    $this->logger->logError('Failed to read message_thread_id setting: ' . $e->getMessage(), 'Command:summary');
                }
                Request::sendMessage($noMsgsParams);
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
            // Send summary into a configured topic/thread if set
            $sendParams = [
                'chat_id' => $chatId,
                'text' => $summaryWithBlockquote,
                'parse_mode' => 'HTML'
            ];

            try {
                $configuredThread = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
                if ($configuredThread !== null) {
                    $sendParams['message_thread_id'] = (int)$configuredThread;
                }
            } catch (\Throwable $e) {
                // If fetching setting fails, just proceed without thread restriction
                $this->logger->logError('Failed to read message_thread_id setting: ' . $e->getMessage(), 'Command:summary');
            }

            $sendResult = Request::sendMessage($sendParams);

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

            $params = [
                'chat_id' => $chatId,
                'text' => '⚠️ Only group administrators can change settings.',
                'reply_to_message_id' => $messageId
            ];
            $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
            if ($threadId !== null) {
                $params['message_thread_id'] = (int)$threadId;
            }
            Request::sendMessage($params);

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

            case 'voting':
            case 'votes':
            case 'moderation':
                $enabled = $parts[1] ?? '';
                $this->setVotingEnabled($chatId, $enabled, $messageId);
                break;

            case 'voteban':
            case 'vote_threshold_ban':
                $num = $parts[1] ?? '';
                $this->setVoteThresholdBan($chatId, $num, $messageId);
                break;

            case 'votemute':
            case 'vote_threshold_mute':
                $num = $parts[1] ?? '';
                $this->setVoteThresholdMute($chatId, $num, $messageId);
                break;

            case 'voteduration':
            case 'vote_duration':
                $arg = $parts[1] ?? '';
                $this->setVoteDuration($chatId, $arg, $messageId);
                break;

            case 'muteduration':
            case 'vote_mute_duration':
                $arg = $parts[1] ?? '';
                $this->setVoteMuteDuration($chatId, $arg, $messageId);
                break;

            case 'time':
            case 'summary_time':
                $hour = $parts[1] ?? '';
                $this->setSummaryHour($chatId, $hour, $messageId);
                break;

            case 'topic':
            case 'thread':
                $arg = $parts[1] ?? '';
                $this->setMessageThread($chatId, $arg, $messageId, $message);
                break;

            case 'newuser':
            case 'new_user_restriction':
            case 'antispam':
                $enabled = $parts[1] ?? '';
                $this->setNewUserRestrictionEnabled($chatId, $enabled, $messageId);
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
        $votingEnabled = ($settings['vote_moderation_enabled'] ?? true) ? '✅ Enabled' : '❌ Disabled';
        $newUserRestrictionEnabled = ($settings['new_user_restriction_enabled'] ?? false) ? '✅ Enabled' : '❌ Disabled';

        $summaryHourUtc = $settings['summary_hour_utc'] ?? 8;
        $topicId = $settings['message_thread_id'] ?? null;
        $topicInfo = '—';
        if ($topicId !== null) {
            $link = $this->buildTopicLink($chatId, (int)$topicId);
            $topicInfo = $link ? $link : (string)$topicId;
        }

        $vb = (int)($settings['vote_threshold_ban'] ?? 5);
        $vm = (int)($settings['vote_threshold_mute'] ?? 3);
        $vd = (int)($settings['vote_duration_sec'] ?? 300);
        $vmd = (int)($settings['vote_mute_duration_sec'] ?? 3600);

        $message = "📊 *Current Settings*\n\n" .
            "🌐 *Language*: {$languageName}\n" .
            "📝 *Summary*: {$summaryEnabled}\n" .
            "⏰ *Summary Time (UTC)*: {$summaryHourUtc}:00\n" .
            "🤖 *Bot Mentions*: {$mentionsEnabled}\n" .
            "🗳️ *Community Voting*: {$votingEnabled}\n" .
            "   • YES needed to ban: {$vb}\n" .
            "   • YES needed to mute: {$vm}\n" .
            "   • Vote duration: " . $this->formatSeconds($vd) . " ({$vd}s)\n" .
            "   • Mute duration: " . $this->formatSeconds($vmd) . " ({$vmd}s)\n" .
            "🛡️ *New User Anti-Spam*: {$newUserRestrictionEnabled}\n" .
            "🧵 *Topic restriction*: " . ($topicId !== null ? "Enabled → " . $topicInfo : "Disabled") . "\n\n" .
            "Use `/settings help` to see available commands.";

        $params = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_to_message_id' => $messageId
        ];
        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) {
            $params['message_thread_id'] = (int)$threadId;
        }
        Request::sendMessage($params);
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
            "• `/settings voting [on/off]` - Enable/disable community vote moderation\n" .
            "• `/settings voteban [n]` - Set YES votes required to ban (1-100). Example: `/settings voteban 5`\n" .
            "• `/settings votemute [n]` - Set YES votes required to mute (1-100). Example: `/settings votemute 3`\n" .
            "• `/settings voteduration [value]` - Set how long a vote stays open. Accepts seconds or `5m`, `1h`, `1d`.\n" .
            "   Example: `/settings voteduration 10m`\n" .
            "• `/settings muteduration [value]` - Set mute duration after successful mute vote. Accepts seconds or `10m`, `2h`, `1d`.\n" .
            "   Example: `/settings muteduration 1h`\n" .
            "• `/settings newuser [on/off]` - Enable/disable new user anti-spam (captcha or 10 min wait)\n" .
            "• `/settings time [0-23]` - Set daily summary hour (UTC). Default is 8.\n" .
            "• `/settings topic here` - Restrict bot replies to the current topic/thread\n" .
            "• `/settings topic clear` - Remove topic restriction\n" .
            "• `/settings topic <id>` - Restrict to topic by ID (advanced)\n" .
            "• `/settings help` - Show this help message\n\n" .
            "Only group administrators can change settings.";

        $params = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_to_message_id' => $messageId
        ];
        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) {
            $params['message_thread_id'] = (int)$threadId;
        }
        Request::sendMessage($params);
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
            "• `/account [token]` — Link your Statbate+ account in a private chat.\n\n" .
            "Community moderation (reply to a message):\n" .
            "• `/voteban` — start a vote to delete the message and ban its author.\n" .
            "• `/votemute` or `/votekick` — start a vote to temporarily mute the author.\n" .
            "• `/yes` or `/no` — cast your vote by replying to the same message.\n\n" .
            "Anti-spam:\n" .
            "• Admins can enable new user restrictions via `/settings newuser on` to require new members to solve a captcha or wait 10 minutes before sending messages.\n\n" .
            "Admins can configure thresholds and durations via `/settings voteban`, `/settings votemute`, `/settings voteduration`, `/settings muteduration`.\n";

        $params = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_to_message_id' => $messageId
        ];
        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) {
            $params['message_thread_id'] = (int)$threadId;
        }
        Request::sendMessage($params);
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
     * Enable or disable community vote moderation for a chat
     *
     * @param int $chatId The chat ID
     * @param string $enabled Whether voting is enabled ('on', 'off', 'true', 'false')
     * @param int $messageId Message ID to reply to
     * @return void
     */
    private function setVotingEnabled(int $chatId, string $enabled, int $messageId): void
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

        $this->settingsService->updateSetting($chatId, 'vote_moderation_enabled', $value);

        $status = $value ? 'enabled' : 'disabled';
        $emoji = $value ? '✅' : '❌';
        $message = "{$emoji} Community voting is now *{$status}* in this chat.\nReply to a message with /voteban or /votemute to start a vote.";

        Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_to_message_id' => $messageId
        ]);
    }

    /**
     * Enable or disable new user restriction (anti-spam) for a chat
     *
     * @param int $chatId The chat ID
     * @param string $enabled Whether new user restriction is enabled ('on', 'off', 'true', 'false')
     * @param int $messageId Message ID to reply to
     * @return void
     */
    private function setNewUserRestrictionEnabled(int $chatId, string $enabled, int $messageId): void
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

        $this->settingsService->updateSetting($chatId, 'new_user_restriction_enabled', $value);

        $status = $value ? 'enabled' : 'disabled';
        $emoji = $value ? '✅' : '❌';
        $message = "{$emoji} New user anti-spam is now *{$status}* in this chat.\n" .
                   ($value ? "New members will need to solve a captcha or wait 10 minutes before sending messages." : "");

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
     * Format seconds into a human-friendly string (e.g., 90 -> 1m 30s)
     */
    private function formatSeconds(int $seconds): string
    {
        if ($seconds <= 0) return '0s';
        $parts = [];
        $days = intdiv($seconds, 86400); $seconds %= 86400;
        $hours = intdiv($seconds, 3600); $seconds %= 3600;
        $mins = intdiv($seconds, 60); $seconds %= 60;
        if ($days) $parts[] = $days . 'd';
        if ($hours) $parts[] = $hours . 'h';
        if ($mins) $parts[] = $mins . 'm';
        if ($seconds && !$days) $parts[] = $seconds . 's'; // omit seconds if days shown to keep concise
        return implode(' ', $parts) ?: '0s';
    }

    /**
     * Parse duration strings like 300, 30s, 5m, 2h, 1d into seconds
     */
    private function parseDurationToSeconds(string $arg): ?int
    {
        $arg = trim(strtolower($arg));
        if ($arg === '') return null;
        if (ctype_digit($arg)) return (int)$arg;
        if (!preg_match('/^(\d+)([smhd])$/', $arg, $m)) {
            return null;
        }
        $n = (int)$m[1];
        return match ($m[2]) {
            's' => $n,
            'm' => $n * 60,
            'h' => $n * 3600,
            'd' => $n * 86400,
            default => null,
        };
    }

    /**
     * Set number of YES votes needed to ban
     */
    private function setVoteThresholdBan(int $chatId, string $num, int $messageId): void
    {
        if ($num === '' || !ctype_digit($num)) {
            $current = (int)$this->settingsService->getSetting($chatId, 'vote_threshold_ban', 5);
            $msg = "⚠️ Please provide an integer between 1 and 100.\nCurrent value: {$current}";
            $params = ['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown','reply_to_message_id'=>$messageId];
            $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
            if ($threadId !== null) $params['message_thread_id'] = (int)$threadId;
            Request::sendMessage($params);
            return;
        }
        $val = max(1, min(100, (int)$num));
        $this->settingsService->updateSetting($chatId, 'vote_threshold_ban', $val);
        $msg = "✅ YES votes required to ban set to *{$val}*.";
        $params = ['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown','reply_to_message_id'=>$messageId];
        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) $params['message_thread_id'] = (int)$threadId;
        Request::sendMessage($params);
    }

    /**
     * Set number of YES votes needed to mute
     */
    private function setVoteThresholdMute(int $chatId, string $num, int $messageId): void
    {
        if ($num === '' || !ctype_digit($num)) {
            $current = (int)$this->settingsService->getSetting($chatId, 'vote_threshold_mute', 3);
            $msg = "⚠️ Please provide an integer between 1 and 100.\nCurrent value: {$current}";
            $params = ['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown','reply_to_message_id'=>$messageId];
            $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
            if ($threadId !== null) $params['message_thread_id'] = (int)$threadId;
            Request::sendMessage($params);
            return;
        }
        $val = max(1, min(100, (int)$num));
        $this->settingsService->updateSetting($chatId, 'vote_threshold_mute', $val);
        $msg = "✅ YES votes required to mute set to *{$val}*.";
        $params = ['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown','reply_to_message_id'=>$messageId];
        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) $params['message_thread_id'] = (int)$threadId;
        Request::sendMessage($params);
    }

    /**
     * Set vote duration (how long a vote remains open)
     */
    private function setVoteDuration(int $chatId, string $arg, int $messageId): void
    {
        $secs = $this->parseDurationToSeconds($arg ?? '');
        if ($secs === null) {
            $current = (int)$this->settingsService->getSetting($chatId, 'vote_duration_sec', 300);
            $msg = "⚠️ Provide duration like `300`, `5m`, `1h`, `1d`.\nCurrent: " . $this->formatSeconds($current) . " ({$current}s)";
            $params = ['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown','reply_to_message_id'=>$messageId];
            $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
            if ($threadId !== null) $params['message_thread_id'] = (int)$threadId;
            Request::sendMessage($params);
            return;
        }
        // clamp to allowed range
        if ($secs < 30) $secs = 30; if ($secs > 604800) $secs = 604800;
        $this->settingsService->updateSetting($chatId, 'vote_duration_sec', $secs);
        $msg = "✅ Vote duration set to *" . $this->formatSeconds($secs) . "* ({$secs}s).";
        $params = ['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown','reply_to_message_id'=>$messageId];
        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) $params['message_thread_id'] = (int)$threadId;
        Request::sendMessage($params);
    }

    /**
     * Set mute duration applied when a vote to mute succeeds
     */
    private function setVoteMuteDuration(int $chatId, string $arg, int $messageId): void
    {
        $secs = $this->parseDurationToSeconds($arg ?? '');
        if ($secs === null) {
            $current = (int)$this->settingsService->getSetting($chatId, 'vote_mute_duration_sec', 3600);
            $msg = "⚠️ Provide duration like `600`, `10m`, `2h`, `1d`.\nCurrent: " . $this->formatSeconds($current) . " ({$current}s)";
            $params = ['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown','reply_to_message_id'=>$messageId];
            $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
            if ($threadId !== null) $params['message_thread_id'] = (int)$threadId;
            Request::sendMessage($params);
            return;
        }
        if ($secs < 60) $secs = 60; if ($secs > 2592000) $secs = 2592000;
        $this->settingsService->updateSetting($chatId, 'vote_mute_duration_sec', $secs);
        $msg = "✅ Mute duration set to *" . $this->formatSeconds($secs) . "* ({$secs}s).";
        $params = ['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown','reply_to_message_id'=>$messageId];
        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) $params['message_thread_id'] = (int)$threadId;
        Request::sendMessage($params);
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

    // ================== Community Vote Moderation ==================

    /**
     * Start a vote by replying to a user's message.
     */
    public function handleVoteStartCommand(int $chatId, Message $message, string $type): void
    {
        // Check enabled
        $enabled = $this->settingsService->getSetting($chatId, 'vote_moderation_enabled', true);
        if (!$enabled) {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ Community voting is disabled in this chat.',
                'reply_to_message_id' => $message->getMessageId()
            ]);
            return;
        }
        if (!in_array($type, self::VOTE_TYPES, true)) $type = 'ban';

        $reply = $message->getReplyToMessage();
        if (!$reply) {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Reply to the message you want to report with /voteban or /votemute.',
                'reply_to_message_id' => $message->getMessageId()
            ]);
            return;
        }
        $targetUser = $reply->getFrom();
        if (!$targetUser) {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Unable to determine the target user for this vote.',
                'reply_to_message_id' => $message->getMessageId()
            ]);
            return;
        }
        $initiatorId = $message->getFrom()->getId();
        $targetUserId = $targetUser->getId();
        $targetMessageId = $reply->getMessageId();

        // Prevent self-target or targeting admins? Allow targeting anyone except creators/admins by default to reduce abuse.
        if ($this->isUserAdmin($chatId, $targetUserId)) {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ You cannot start a vote against an admin.',
                'reply_to_message_id' => $message->getMessageId()
            ]);
            return;
        }

        $duration = (int)$this->settingsService->getSetting($chatId, 'vote_duration_sec', 600);
        $existing = $this->voteService->getAnyActiveVoteByReply($chatId, $targetUserId, $targetMessageId);
        if ($existing) {
            $yes = count($existing['yes']);
            $no = count($existing['no']);
            $left = max(0, $existing['expires_at'] - time());
            $mention = '[' . ($targetUser->getFirstName() ?: ($targetUser->getUsername() ? '@' . $targetUser->getUsername() : 'user')) . '](tg://user?id=' . $targetUserId . ')';
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "A vote is already in progress to " . ($existing['type']==='ban' ? 'ban' : 'mute') . " {$mention}. Yes: {$yes}, No: {$no}. Time left: {$left}s. Reply /yes or /no.",
                'parse_mode' => 'Markdown',
                'reply_to_message_id' => $message->getMessageId()
            ]);
            return;
        }

        $this->voteService->startVote($chatId, $type, $initiatorId, $targetUserId, $targetMessageId, $duration);
        // Auto-count initiator as YES
        $vote = $this->voteService->addVote($chatId, $targetUserId, $targetMessageId, $initiatorId, true);

        $threshold = (int)$this->settingsService->getSetting($chatId, $type === 'ban' ? 'vote_threshold_ban' : 'vote_threshold_mute', $type === 'ban' ? 5 : 3);

        $targetDisplay = $targetUser->getFirstName() ?: ($targetUser->getUsername() ? '@' . $targetUser->getUsername() : 'user');
        $targetMention = '[' . $targetDisplay . '](tg://user?id=' . $targetUserId . ')';
        $initialYes = count($vote['yes']);
        $initialNo = count($vote['no']);
        $text = "📣 Vote started to " . ($type === 'ban' ? 'ban' : 'mute') . " {$targetMention}.\n" .
            "Tap a button below to vote. (/yes and /no also work)\n" .
            "Yes: {$initialYes} | No: {$initialNo} | Needed YES: {$threshold}\n" .
            "Time left: " . $duration . "s";

        $yesCb = "vote|{$chatId}|{$type}|{$targetUserId}|{$targetMessageId}|yes";
        $noCb  = "vote|{$chatId}|{$type}|{$targetUserId}|{$targetMessageId}|no";
        // Two buttons on a single row
        $keyboard = new InlineKeyboard([
            ['text' => '✅ Yes', 'callback_data' => $yesCb],
            ['text' => '❌ No',  'callback_data' => $noCb],
        ]);

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_to_message_id' => $targetMessageId,
            'allow_sending_without_reply' => true,
            'reply_markup' => $keyboard,
        ];
        // Ensure vote announcement is posted in the same topic as the reported message (not forced to configured topic)
        $replyThreadId = $reply->getMessageThreadId();
        if ($replyThreadId !== null) {
            $params['message_thread_id'] = (int)$replyThreadId;
        }

        // Try to send the vote announcement with fallbacks if Telegram refuses the reply/thread combination
        try {
            $res = Request::sendMessage($params);
        } catch (\Throwable $e) {
            $this->logger->logError('Vote announce send exception (initial): ' . $e->getMessage(), 'Vote');
            $res = null;
        }

        if (!$res || !$res->isOk()) {
            $desc = $res ? $res->getDescription() : 'no response';
            $this->logger->logError('Vote announce send failed (initial): ' . $desc, 'Vote');
            // Retry without reply_to_message_id
            $paramsNoReply = $params;
            unset($paramsNoReply['reply_to_message_id']);
            try {
                $res = Request::sendMessage($paramsNoReply);
            } catch (\Throwable $e) {
                $this->logger->logError('Vote announce send exception (no reply): ' . $e->getMessage(), 'Vote');
                $res = null;
            }

            if (!$res || !$res->isOk()) {
                $desc2 = $res ? $res->getDescription() : 'no response';
                $this->logger->logError('Vote announce send failed (no reply): ' . $desc2, 'Vote');
                // Retry without thread context as last resort
                $paramsNoThread = $paramsNoReply;
                unset($paramsNoThread['message_thread_id']);
                try {
                    $res = Request::sendMessage($paramsNoThread);
                } catch (\Throwable $e) {
                    $this->logger->logError('Vote announce send exception (no thread): ' . $e->getMessage(), 'Vote');
                    $res = null;
                }

                if (!$res || !$res->isOk()) {
                    $desc3 = $res ? $res->getDescription() : 'no response';
                    $this->logger->logError('Vote announce send failed (no thread): ' . $desc3, 'Vote');
                }
            }
        }

        if ($res && $res->isOk()) {
            $sent = $res->getResult();
            $announceId = method_exists($sent, 'getMessageId') ? (int)$sent->getMessageId() : null;
            if ($announceId) {
                $this->voteService->setAnnounceMessageId($chatId, $targetUserId, $targetMessageId, $announceId);
            }
        }
    }

    /**
     * Handle /yes or /no vote response, must be reply to the same target message.
     */
    public function handleVoteResponseCommand(int $chatId, Message $message, bool $yes): void
    {
        $reply = $message->getReplyToMessage();
        if (!$reply) {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Please reply with /yes or /no to the reported message.',
                'reply_to_message_id' => $message->getMessageId()
            ]);
            return;
        }
        $targetUser = $reply->getFrom();
        if (!$targetUser) return;
        $targetUserId = $targetUser->getId();
        $targetMessageId = $reply->getMessageId();
        $voterId = $message->getFrom()->getId();

        $active = $this->voteService->getAnyActiveVoteByReply($chatId, $targetUserId, $targetMessageId);
        if (!$active) {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'No active vote for this message.',
                'reply_to_message_id' => $message->getMessageId()
            ]);
            return;
        }

        $vote = $this->voteService->addVote($chatId, $targetUserId, $targetMessageId, $voterId, $yes);
        if (!$vote) return;

        $yesCount = count($vote['yes']);
        $noCount = count($vote['no']);
        $type = $vote['type'];
        $threshold = (int)$this->settingsService->getSetting($chatId, $type === 'ban' ? 'vote_threshold_ban' : 'vote_threshold_mute', $type === 'ban' ? 5 : 3);
        $left = max(0, ($vote['expires_at'] ?? time()) - time());

        // If we have an announcement message, update it to reflect current state
        $announceId = $vote['announce_message_id'] ?? null;
        if ($announceId) {
            $yesCb = "vote|{$chatId}|{$type}|{$targetUserId}|{$targetMessageId}|yes";
            $noCb  = "vote|{$chatId}|{$type}|{$targetUserId}|{$targetMessageId}|no";
            $keyboard = new InlineKeyboard([
                ['text' => '✅ Yes', 'callback_data' => $yesCb],
                ['text' => '❌ No',  'callback_data' => $noCb],
            ]);
            $mention = '[user](tg://user?id=' . $targetUserId . ')';
            $newText = "📣 Vote to " . ($type === 'ban' ? 'ban' : 'mute') . " {$mention}.\n" .
                "Yes: {$yesCount} | No: {$noCount} | Needed YES: {$threshold}\n" .
                "Time left: {$left}s";
            Request::editMessageText([
                'chat_id' => $chatId,
                'message_id' => $announceId,
                'text' => $newText,
                'parse_mode' => 'Markdown',
                'reply_markup' => $keyboard,
            ]);
        } else {
            // Fallback announce progress in chat if we don't track the announcement message id
            $mention = '[user](tg://user?id=' . $targetUserId . ')';
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => ($yes ? '✅' : '❌') . " Vote registered for {$mention}. Yes: {$yesCount}, No: {$noCount}. Needed YES: {$threshold}.",
                'parse_mode' => 'Markdown',
                'reply_to_message_id' => $targetMessageId
            ]);
        }

        if ($yesCount >= $threshold) {
            // Finalize vote: apply action
            $this->applyVoteAction($chatId, $type, $targetUserId, $targetMessageId);
            $this->voteService->finalize($chatId, $targetUserId, $targetMessageId);
            // After applying, update announcement to show completion and disable buttons
            if ($announceId) {
                Request::editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $announceId,
                    'text' => (isset($newText) ? $newText : "Vote completed.") . "\n\n✅ Action applied.",
                    'parse_mode' => 'Markdown',
                    'reply_markup' => new InlineKeyboard([]),
                ]);
            }
        }
    }

    public function applyVoteAction(int $chatId, string $type, int $targetUserId, int $targetMessageId, ?int $announceMessageId = null): void
    {
        // Try delete the original offending message first
        try {
            Request::deleteMessage(['chat_id' => $chatId, 'message_id' => $targetMessageId]);
        } catch (\Throwable $e) {
            $this->logger->logError('Failed to delete message on vote action: ' . $e->getMessage(), 'Vote');
        }

        if ($type === 'ban') {
            try {
                $res = Request::banChatMember(['chat_id' => $chatId, 'user_id' => $targetUserId]);
                if (!$res->isOk()) {
                    $this->logger->logError('banChatMember failed: ' . $res->getDescription(), 'Vote');
                } else {
                    $mention = '[user](tg://user?id=' . $targetUserId . ')';
                    // Prefer replying to the vote announcement message so the result stays in the vote thread
                    $replyToId = $announceMessageId ?: $targetMessageId;
                    Request::sendMessage([
                        'chat_id' => $chatId,
                        'text' => '🚫 ' . $mention . ' has been banned by community vote.',
                        'parse_mode' => 'Markdown',
                        'reply_to_message_id' => $replyToId,
                        'allow_sending_without_reply' => true,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->logError('Error banning user: ' . $e->getMessage(), 'Vote', $e);
            }
        } else {
            // mute by restrictChatMember with no permissions for duration
            $duration = (int)$this->settingsService->getSetting($chatId, 'vote_mute_duration_sec', 3600);
            $until = time() + max(60, $duration);
            try {
                $res = Request::restrictChatMember([
                    'chat_id' => $chatId,
                    'user_id' => $targetUserId,
                    'permissions' => [
                        'can_send_messages' => false,
                        'can_send_audios' => false,
                        'can_send_documents' => false,
                        'can_send_photos' => false,
                        'can_send_videos' => false,
                        'can_send_video_notes' => false,
                        'can_send_voice_notes' => false,
                        'can_send_polls' => false,
                        'can_send_other_messages' => false,
                        'can_add_web_page_previews' => false,
                        'can_change_info' => false,
                        'can_invite_users' => false,
                        'can_pin_messages' => false,
                    ],
                    'until_date' => $until,
                ]);
                if (!$res->isOk()) {
                    $this->logger->logError('restrictChatMember failed: ' . $res->getDescription(), 'Vote');
                } else {
                    // persist mute so we can unmute later if Telegram did not auto-unmute
                    $this->muteService->addMute($chatId, $targetUserId, $until);
                    $mention = '[user](tg://user?id=' . $targetUserId . ')';
                    // Prefer replying to the vote announcement message so the result stays in the vote thread
                    $replyToId = $announceMessageId ?: $targetMessageId;
                    Request::sendMessage([
                        'chat_id' => $chatId,
                        'text' => '🔇 ' . $mention . ' has been muted by community vote.',
                        'parse_mode' => 'Markdown',
                        'reply_to_message_id' => $replyToId,
                        'allow_sending_without_reply' => true,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->logError('Error muting user: ' . $e->getMessage(), 'Vote', $e);
            }
        }
    }

    /**
     * Handle inline button callback for votes
     */
    public function handleVoteCallback(CallbackQuery $callback): void
    {
        try {
            $data = (string)$callback->getData();
            if (strpos($data, 'vote|') !== 0) {
                return;
            }
            $parts = explode('|', $data);
            if (count($parts) !== 6) {
                Request::answerCallbackQuery(['callback_query_id' => $callback->getId(), 'text' => 'Invalid vote data', 'show_alert' => false]);
                return;
            }
            [, $chatIdStr, $type, $targetUserIdStr, $targetMsgIdStr, $ans] = $parts;
            $chatId = (int)$chatIdStr;
            $targetUserId = (int)$targetUserIdStr;
            $targetMessageId = (int)$targetMsgIdStr;
            $yes = ($ans === 'yes');

            // Prefer chat_id from the actual callback message to avoid any mismatch with topics/threads
            $cbMsg = $callback->getMessage();
            if ($cbMsg && $cbMsg->getChat()) {
                $chatId = (int)$cbMsg->getChat()->getId();
            }

            $from = $callback->getFrom();
            $voterId = $from ? $from->getId() : null;
            if (!$voterId) {
                Request::answerCallbackQuery(['callback_query_id' => $callback->getId(), 'text' => 'Cannot identify voter', 'show_alert' => false]);
                return;
            }

            // Acknowledge immediately to stop Telegram spinner
            Request::answerCallbackQuery(['callback_query_id' => $callback->getId(), 'text' => $yes ? 'Voted YES' : 'Voted NO', 'show_alert' => false]);

            $vote = $this->voteService->addVote($chatId, $targetUserId, $targetMessageId, $voterId, $yes);
            if (!$vote) {
                // Try to notify user vote expired
                return;
            }

            $yesCount = count($vote['yes']);
            $noCount = count($vote['no']);
            $threshold = (int)$this->settingsService->getSetting($chatId, $vote['type'] === 'ban' ? 'vote_threshold_ban' : 'vote_threshold_mute', $vote['type'] === 'ban' ? 5 : 3);
            $left = max(0, ($vote['expires_at'] ?? time()) - time());

            // Update the vote announcement message with counts
            $announceId = $vote['announce_message_id'] ?? null;
            $mention = '[user](tg://user?id=' . $targetUserId . ')';
            $newText = "📣 Vote to " . ($vote['type']==='ban' ? 'ban' : 'mute') . " {$mention}.\n" .
                "Yes: {$yesCount} | No: {$noCount} | Needed YES: {$threshold}\n" .
                "Time left: {$left}s";

            $yesCb = "vote|{$chatId}|{$vote['type']}|{$targetUserId}|{$targetMessageId}|yes";
            $noCb  = "vote|{$chatId}|{$vote['type']}|{$targetUserId}|{$targetMessageId}|no";
            // Place two buttons on a single row
            $keyboard = new InlineKeyboard([
                ['text' => '✅ Yes', 'callback_data' => $yesCb],
                ['text' => '❌ No',  'callback_data' => $noCb],
            ]);

            // Fallback: if announce_message_id is not set yet, edit the callback message itself
            $callbackMsg = $callback->getMessage();
            $fallbackMessageId = $callbackMsg ? $callbackMsg->getMessageId() : null;
            $fallbackChatId = $callbackMsg && $callbackMsg->getChat() ? $callbackMsg->getChat()->getId() : $chatId;

            if ($announceId) {
                Request::editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $announceId,
                    'text' => $newText,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => $keyboard,
                ]);
            } elseif ($fallbackMessageId) {
                Request::editMessageText([
                    'chat_id' => $fallbackChatId,
                    'message_id' => $fallbackMessageId,
                    'text' => $newText,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => $keyboard,
                ]);
            }

            // Finalize if threshold reached
            if ($yesCount >= $threshold) {
                $this->applyVoteAction($chatId, $vote['type'], $targetUserId, $targetMessageId, $announceId ?? null);
                $this->voteService->finalize($chatId, $targetUserId, $targetMessageId);
                $finalText = $newText . "\n\n✅ Action applied.";
                if ($announceId) {
                    Request::editMessageText([
                        'chat_id' => $chatId,
                        'message_id' => $announceId,
                        'text' => $finalText,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => new InlineKeyboard([]),
                    ]);
                } elseif ($fallbackMessageId) {
                    Request::editMessageText([
                        'chat_id' => $fallbackChatId,
                        'message_id' => $fallbackMessageId,
                        'text' => $finalText,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => new InlineKeyboard([]),
                    ]);
                }
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->logError('Error handling vote callback: ' . $e->getMessage(), 'Vote', $e);
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

    /**
     * Set the message thread/topic restriction for this chat
     */
    private function setMessageThread(int $chatId, string $arg, int $messageId, Message $message): void
    {
        $argLower = strtolower(trim($arg));

        // Determine desired value
        if ($argLower === '' || $argLower === 'here') {
            $threadId = method_exists($message, 'getMessageThreadId') ? $message->getMessageThreadId() : null;
            if ($threadId === null) {
                $reply = "This message is not in a topic/thread. Send this command inside the desired topic or use `/settings topic <id>`.";
                $params = [
                    'chat_id' => $chatId,
                    'text' => $reply,
                    'reply_to_message_id' => $messageId,
                    'parse_mode' => 'Markdown'
                ];
                $currentThread = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
                if ($currentThread !== null) {
                    $params['message_thread_id'] = (int)$currentThread;
                }
                Request::sendMessage($params);
                return;
            }
            $value = (int)$threadId;
        } elseif (in_array($argLower, ['clear','none','null'])) {
            $value = null;
        } elseif (ctype_digit($argLower)) {
            $value = (int)$argLower;
        } else {
            $reply = "Invalid argument. Use `here`, `clear`, or a numeric topic id.";
            $params = [
                'chat_id' => $chatId,
                'text' => $reply,
                'reply_to_message_id' => $messageId,
                'parse_mode' => 'Markdown'
            ];
            $currentThread = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
            if ($currentThread !== null) {
                $params['message_thread_id'] = (int)$currentThread;
            }
            Request::sendMessage($params);
            return;
        }

        // Validate and store
        if (!$this->settingsService->isValidSetting('message_thread_id', $value)) {
            $reply = "Invalid topic id.";
            $params = [
                'chat_id' => $chatId,
                'text' => $reply,
                'reply_to_message_id' => $messageId
            ];
            $currentThread = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
            if ($currentThread !== null) {
                $params['message_thread_id'] = (int)$currentThread;
            }
            Request::sendMessage($params);
            return;
        }

        $formatted = $this->settingsService->formatSettingValue('message_thread_id', $value);
        $this->settingsService->updateSetting($chatId, 'message_thread_id', $formatted);

        if ($formatted === null) {
            $reply = "🧵 Topic restriction disabled. Bot will reply in the whole chat.";
            $params = [
                'chat_id' => $chatId,
                'text' => $reply,
                'reply_to_message_id' => $messageId
            ];
            Request::sendMessage($params);
            return;
        }

        $link = $this->buildTopicLink($chatId, (int)$formatted);
        $reply = "🧵 Topic restriction enabled. All bot replies will be sent in: " . ($link ?: ('topic #' . (int)$formatted)) . ".";
        $params = [
            'chat_id' => $chatId,
            'text' => $reply,
            'reply_to_message_id' => $messageId,
            'disable_web_page_preview' => true
        ];
        // Send confirmation directly in configured topic
        $params['message_thread_id'] = (int)$formatted;
        Request::sendMessage($params);
    }

    /**
     * Build a link to a forum topic in a supergroup
     */
    private function buildTopicLink(int $chatId, int $topicId): ?string
    {
        try {
            $chatIdStr = (string)$chatId;
            if (str_starts_with($chatIdStr, '-100')) {
                $internal = substr($chatIdStr, 4);
            } else {
                $internal = ltrim($chatIdStr, '-');
            }
            if (!ctype_digit($internal)) {
                return null;
            }
            return "https://t.me/c/{$internal}/{$topicId}";
        } catch (\Throwable $e) {
            $this->logger->logError('Failed to build topic link: ' . $e->getMessage(), 'Topic Link');
            return null;
        }
    }
}
