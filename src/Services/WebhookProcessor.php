<?php

namespace App\Services;

use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

/**
 * Processes webhook updates from Telegram
 */
class WebhookProcessor
{
    private MessageStorage $messageStorage;
    private BotMentionHandler $mentionHandler;
    private CommandHandler $commandHandler;
    private LoggerService $logger;
    private AIService $aiService;
    private AntiSpamHandler $antiSpamHandler;
    private array $config;
    private string $botUsername;

    public function __construct(
        MessageStorage $messageStorage,
        BotMentionHandler $mentionHandler,
        CommandHandler $commandHandler,
        LoggerService $logger,
        AIService $aiService,
        AntiSpamHandler $antiSpamHandler,
        array $config,
        string $botUsername
    ) {
        $this->messageStorage = $messageStorage;
        $this->mentionHandler = $mentionHandler;
        $this->commandHandler = $commandHandler;
        $this->logger = $logger;
        $this->antiSpamHandler = $antiSpamHandler;
        $this->config = $config;
        $this->botUsername = $botUsername;
        $this->aiService = $aiService;
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

                // Check for spam in text messages
                if ($messageText) {
                    $this->logger->log("Checking message for spam in chat {$chatId} from user {$username}", "Spam Check", "webhook");

                    // Check if the message is spam
                    $isSpam = $this->antiSpamHandler->checkAndHandleSpam($messageText, $userId, $username, $chatId, $messageId);

                    // If the message was handled as spam, skip further processing
                    if ($isSpam) {
                        $this->logger->log("Message from {$username} in chat {$chatId} was handled as spam, skipping further processing", "Spam Check", "webhook");
                        return;
                    }
                }

                if ($messageText || $photos) {
                    // Process and store text message (only if there are no photos)
                    if ($messageText && empty($photos)) {
                        $this->messageStorage->storeMessage($chatId, $timestamp, $username, $messageText, $messageId);
                    }

                    // Process images if present
                    $formattedDescription = null;
                    if ($photos && !empty($photos)) {
                        $formattedDescription = $this->processImage($photos, $caption, $this);
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

                        $this->processBotMention($chatId, $messageText ?: ($caption ?: ''), $username, $messageId, $photos, $formattedDescription ?? null, $isReplyToBot);
                    } else if ($isEditedMessage) {
                        // Log that we're skipping mention processing for edited message
                        $this->logger->log(
                            "Skipping mention processing for edited message in chat {$chatId} by {$username}",
                            'Edited Message'
                        );
                    }
                } else {
                    $this->logger->log(
                        'Skip processing empty message 1',
                        'Mention'
                    );
                }
            } else {
                $this->logger->log(
                    'Skip processing empty message 2',
                    'Mention'
                );
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

                    // Get the user ID for subscription checking
                    $userId = $message->getFrom()->getId();

                    $this->commandHandler->handleMCPCommand($chatId, $query, $fromUser, $messageId, $userId);
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

                // Handle /account command
                if (str_starts_with($messageText, '/account')) {
                    // Extract the account identifier part after the command
                    $accountIdentifier = trim(substr($messageText, 8));

                    $this->logger->logWebhook(
                        "Received /account command in chat {$chatId} ({$chatTitle}) from user {$fromUser} with identifier: " .
                        substr($accountIdentifier, 0, 10) . (strlen($accountIdentifier) > 10 ? '...' : '')
                    );

                    // Check if this is a private chat
                    $isPrivateChat = $message->getChat()->isPrivateChat();
                    $userId = $message->getFrom()->getId();

                    $this->commandHandler->handleAccountCommand($chatId, $accountIdentifier, $userId, $messageId, $isPrivateChat);
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
    private function processImage($photos, ?string $caption): ?string
    {
        try {
            // Get the largest photo (last in the array)
            $largestPhoto = end($photos);

            // Get the file ID from the PhotoSize object
            $fileId = $largestPhoto->getFileId();

            $this->logger->log("Downloading image with file ID: " . $fileId);

            // Get the file path from Telegram
            $fileResult = Request::getFile(['file_id' => $fileId]);

            if ($fileResult->isOk()) {
                $filePath = $fileResult->getResult()->getFilePath();

                // Construct the full URL to the file
                $imageUrl = 'https://api.telegram.org/file/bot' . $this->config['telegram_bot_token'] . '/' . $filePath;

                // Create a temporary file to store the image
                $tmpFile = tempnam(sys_get_temp_dir(), 'telegram_img_');

                // Download the image to the temporary file
                $imageData = file_get_contents($imageUrl);
                if ($imageData !== false) {
                    file_put_contents($tmpFile, $imageData);

                    // Convert image to base64
                    $base64Image = base64_encode($imageData);

                    $this->logger->logError('Downloaded image successfully.', 'Webhook Image Download Success');

                    // Generate description for the image using base64
                    $imageDescription = $this->aiService->generateImageDescription($base64Image, true, $caption);

                    // Clean up the temporary file
                    @unlink($tmpFile);
                } else {
                    $this->logger->logError("Failed to download image from URL: " . $imageUrl);
                    $imageDescription = null;
                }

                if ($imageDescription) {
                    // Format the image description with caption if available
                    $formattedDescription = "[IMAGE: " . $imageDescription . "]";

                    // Add caption to the description if available
                    if ($caption) {
                        $formattedDescription = $caption . " " . $formattedDescription;
                    }

                    $this->logger->log("Stored image with description: " . $formattedDescription, 'Webhook Image Description');
                    return $formattedDescription;
                }
            } else {
                $this->logger->logError("Telegram API Error: " . $fileResult->getDescription(), "Webhook Error");
                return '';
            }
        } catch (\Exception $e) {
            $this->logger->logError("Error while processing image: " . $e->getMessage());
            return '';
        }

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
