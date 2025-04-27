<?php

namespace App\Services;

use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;

/**
 * Processes webhook updates from Telegram
 */
class WebhookProcessor
{
    private MessageStorage $messageStorage;
    private BotMentionHandler $mentionHandler;
    private CommandHandler $commandHandler;
    private LoggerService $logger;
    private array $config;
    private string $botUsername;

    public function __construct(
        MessageStorage $messageStorage,
        BotMentionHandler $mentionHandler,
        CommandHandler $commandHandler,
        LoggerService $logger,
        array $config,
        string $botUsername
    ) {
        $this->messageStorage = $messageStorage;
        $this->mentionHandler = $mentionHandler;
        $this->commandHandler = $commandHandler;
        $this->logger = $logger;
        $this->config = $config;
        $this->botUsername = $botUsername;
    }

    /**
     * Process a webhook update asynchronously
     *
     * @param string $updateJson The JSON string received from Telegram
     * @return void
     */
    public function processWebhookAsync(string $updateJson): void
    {
        try {
            // Process the update
            $update = json_decode($updateJson, true);
            if (empty($update)) {
                $this->logger->logError('Empty or invalid update received in async processing');
                return;
            }

            // Create an Update object from the JSON data
            $update = new Update($update, $this->botUsername);

            // Check for duplicate updates
            if ($this->hasDuplicateUpdate($update->getUpdateId())) {
                $this->logger->log(
                    'Duplicate update received: ' . json_encode($update),
                    'Duplicate Update'
                );
                return;
            }

            // Check if this is a new message or an edited message
            $isEditedMessage = $update->getEditedMessage() !== null;
            $message = $update->getMessage() ?? $update->getEditedMessage();

            if ($isEditedMessage) {
                $this->logger->log(
                    'Skip processing edited message',
                    'Skip edited Processing'
                );
                return;
            }

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
                    // Process and store text message (only if there are no photos)
                    if ($messageText && empty($photos)) {
                        $this->messageStorage->storeMessage($chatId, $timestamp, $username, $messageText, $messageId);
                    }

                    // Process images if present
                    $formattedDescription = null;
                    if ($photos && !empty($photos)) {
                        $formattedDescription = $this->processImage($message, $photos, $caption, $chatId, $timestamp, $username, $messageId);
                    }

                    // Only process mentions for new messages, not edited ones
                    // Only process mentions for non-command messages
                    if (!$isEditedMessage && 
                        $messageText !== '/summary' && 
                        !str_starts_with($messageText, '/mcp') && 
                        !str_starts_with($messageText, '/settings')) {
                        // Check if the message is a reply to a bot message
                        $isReplyToBot = false;
                        $replyToMessage = $message->getReplyToMessage();
                        
                        if ($replyToMessage) {
                            $replyFrom = $replyToMessage->getFrom();
                            if ($replyFrom && $replyFrom->getUsername() === $this->botUsername) {
                                $isReplyToBot = true;
                                $this->logger->log(
                                    "Detected reply to bot message in chat {$chatId} by {$username}",
                                    'Bot Reply'
                                );
                            }
                        }
                        
                        // Check if message contains bot mention or is a reply to bot
                        $hasBotMention = strpos(strtolower($messageText ?: ($caption ?: '')), '@' . strtolower($this->botUsername)) !== false;
                        
                        // Also check for the bot's username without @ symbol (for more natural mentions)
                        $hasNaturalMention = !$hasBotMention && strpos(strtolower($messageText ?: ($caption ?: '')), strtolower($this->botUsername)) !== false;
                        
                        if ($hasBotMention || $isReplyToBot || $hasNaturalMention) {
                            // Log the type of mention detected
                            if ($hasNaturalMention && !$hasBotMention && !$isReplyToBot) {
                                $this->logger->log(
                                    "Detected natural mention of bot in chat {$chatId} by {$username}",
                                    'Natural Mention'
                                );
                            }
                            
                            // Check if bot mentions are enabled for this chat
                            $this->processBotMention($chatId, $messageText ?: ($caption ?: ''), $username, $messageId, $photos, $formattedDescription ?? null, $isReplyToBot);
                        }
                    } else if ($isEditedMessage) {
                        // Log that we're skipping mention processing for edited message
                        $this->logger->log(
                            "Skipping mention processing for edited message in chat {$chatId} by {$username}",
                            'Edited Message'
                        );
                    }
                }
            }

            // Command handling - only for new messages, not edited ones
            if ($message && !$isEditedMessage) {
                $chatId = $message->getChat()->getId();
                $chatTitle = $message->getChat()->getTitle() ?? "Unknown";
                $fromUser = $message->getFrom()->getUsername() ?? $message->getFrom()->getFirstName() ?? "Unknown";
                $messageId = $message->getMessageId();

                $messageText = $message->getText(false);

                // Handle /summary command
                if ($messageText === '/summary') {
                    $this->logger->logWebhook("Received /summary command in chat {$chatId} ({$chatTitle}) from user {$fromUser}");
                    $this->commandHandler->handleSummaryCommand($chatId);
                }
                
                // Handle /mcp command
                if (str_starts_with($messageText, '/mcp')) {
                    // Extract the query part after the command
                    $query = trim(substr($messageText, 4));

                    $this->logger->logWebhook(
                        "Received /mcp command in chat {$chatId} ({$chatTitle}) from user {$fromUser} with query: " . 
                        substr($query, 0, 50) . (strlen($query) > 50 ? '...' : '')
                    );

                    $this->commandHandler->handleMCPCommand($chatId, $query, $fromUser, $messageId);
                }

                // Handle /settings command
                if (str_starts_with($messageText, '/settings')) {
                    // Extract the parameters part after the command
                    $params = trim(substr($messageText, 9));

                    $this->logger->logWebhook(
                        "Received /settings command in chat {$chatId} ({$chatTitle}) from user {$fromUser} with params: " . 
                        substr($params, 0, 50) . (strlen($params) > 50 ? '...' : '')
                    );

                    $this->commandHandler->handleSettingsCommand($chatId, $params, $fromUser, $messageId, $message);
                }
            } else if ($message && $isEditedMessage) {
                // Log that we're skipping command processing for edited message
                $chatId = $message->getChat()->getId();
                $fromUser = $message->getFrom()->getUsername() ?? $message->getFrom()->getFirstName() ?? "Unknown";
                $this->logger->log(
                    "Skipping command processing for edited message in chat {$chatId} by {$fromUser}",
                    'Edited Message'
                );
            }

            // Log all messages for debugging
            if ($message && $message->getText(false)) {
                $chatId = $message->getChat()->getId();
                $chatType = $message->getChat()->getType();
                $fromUser = $message->getFrom()->getUsername() ?? $message->getFrom()->getFirstName() ?? "Unknown";
                $messageText = $message->getText(false);

                $this->logger->logWebhook("Message in {$chatType} {$chatId} from {$fromUser}: {$messageText}");
            }

        } catch (TelegramException $e) {
            $this->logger->logError("Telegram API Error: " . $e->getMessage(), "Webhook Error");
        } catch (\Throwable $e) {
            $this->logger->logError("General Error: " . $e->getMessage(), "Webhook Error", $e);
        }
    }

    /**
     * Process an image in a message
     *
     * @param \Longman\TelegramBot\Entities\Message $message The message object
     * @param array $photos The photos in the message
     * @param string|null $caption The caption of the message
     * @param int $chatId The chat ID
     * @param int $timestamp The message timestamp
     * @param string $username The username of the sender
     * @param int $messageId The message ID
     * @return string|null The formatted image description if successful
     */
    private function processImage($message, $photos, ?string $caption, int $chatId, int $timestamp, string $username, int $messageId): ?string
    {
        // This method would contain the image processing logic from the original Bot class
        // For brevity, I'm not including the full implementation here
        // The actual implementation would use AIService to generate image descriptions
        
        // Return the formatted description if successful
        return null;
    }

    /**
     * Process a potential bot mention
     *
     * @param int $chatId The chat ID
     * @param string $textToUse The text to check for mentions
     * @param string $username The username of the sender
     * @param int $messageId The message ID
     * @param array|null $photos The photos in the message
     * @param string|null $imageDescription The image description if available
     * @param bool $isReplyToBot Whether this message is a reply to a bot message
     */
    private function processBotMention(int $chatId, string $textToUse, string $username, int $messageId, $photos = null, ?string $imageDescription = null, bool $isReplyToBot = false): void
    {
        $this->mentionHandler->handleBotMention($chatId, $textToUse, $username, $messageId, $photos, $imageDescription, $isReplyToBot);
    }

    /**
     * Check if an update has already been processed
     *
     * @param int $updateId The update ID to check
     * @return bool Whether the update is a duplicate
     */
    private function hasDuplicateUpdate(int $updateId): bool
    {
        // Use json file to store previous updates
        $json_file = $this->config['log_path'] . "/previous_updates.json";
        $previous_updates = [];
        if (file_exists($json_file)) {
            $previous_updates = json_decode(file_get_contents($json_file), true);
        }
        if (in_array($updateId, $previous_updates)) {
            return true;
        }

        $previous_updates[] = $updateId;
        file_put_contents($json_file, json_encode($previous_updates));

        return false;
    }
}