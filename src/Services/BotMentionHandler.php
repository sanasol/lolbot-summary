<?php

namespace App\Services;

use Longman\TelegramBot\Request;

/**
 * Handles bot mentions in messages
 */
class BotMentionHandler
{
    private AIService $aiService;
    private SettingsService $settingsService;
    private MessageStorage $messageStorage;
    private LoggerService $logger;

    public function __construct(
        AIService $aiService,
        SettingsService $settingsService,
        MessageStorage $messageStorage,
        LoggerService $logger
    ) {
        $this->aiService = $aiService;
        $this->settingsService = $settingsService;
        $this->messageStorage = $messageStorage;
        $this->logger = $logger;
    }

    /**
     * Handle a bot mention in a message
     *
     * @param int $chatId The chat ID
     * @param string $messageText The message text
     * @param string $username The username of the sender
     * @param int $replyToMessageId The message ID to reply to
     * @param array|null $photos Optional photos in the message
     * @param string|null $imageDescription Optional image description
     * @return bool Whether the mention was handled successfully
     */
    public function handleBotMention(int $chatId, string $messageText, string $username, int $replyToMessageId, $photos = null, ?string $imageDescription = null): bool
    {
        // Check if bot mentions are enabled for this chat
        $mentionsEnabled = $this->settingsService->getSetting($chatId, 'bot_mentions_enabled', true);
        if (!$mentionsEnabled) {
            $this->logger->logBotMention("Bot mentions are disabled for chat {$chatId}, ignoring mention");
            return false;
        }

        // Log the message or caption being processed
        $messageSource = empty($messageText) ? "with empty text" : "with text";
        if ($photos && !empty($photos)) {
            $messageSource = empty($messageText) ? "with photo only" : "with photo and caption";
        }

        $this->logger->logBotMention("Bot mentioned in chat {$chatId} by {$username} {$messageSource}: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : ''));

        // If we already have an image description from the caller, use it
        $inputImageUrl = null;
        if ($imageDescription) {
            $this->logger->logBotMention("Using provided image description: " . $imageDescription);

            // Add image description to the message text for better context
            if (!empty($messageText)) {
                $messageText .= "\n\n" . $imageDescription;
            } else {
                $messageText = $imageDescription;
            }
        }

        // Get recent messages from the chat for context (last 10 messages or from the last 30 minutes)
        $recentMessages = $this->messageStorage->getRecentChatContext($chatId);
        $chatContext = '';

        if (!empty($recentMessages)) {
            $chatContext = "Recent conversation in the chat:\n" . implode("\n", $recentMessages) . "\n\n";
            $this->logger->logBotMention("Added " . count($recentMessages) . " recent messages as context");
        }

        // Use AI service for responses with added context
        $response = $this->generateMentionResponse($messageText, $username, $chatContext, $inputImageUrl, $chatId);

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
                $this->logger->logBotMention("Image generated with prompt: " . $response['prompt']);
                $this->logger->logBotMention("Revised prompt: " . ($response['revised_prompt'] ?? 'N/A'));
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
                $this->logger->logBotMention("Successfully sent {$responseType} response to chat {$chatId}");
                return true;
            }

            $this->logger->logBotMention("Failed to send {$responseType} response to chat {$chatId}: " . $sendResult->getDescription());
            return false;
        }

        return false;
    }

    /**
     * Generate a response using AI service
     *
     * @param string $messageText The original message text
     * @param string $username The username of the message sender
     * @param string $chatContext Optional context from recent chat messages
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @param int $chatId The chat ID
     * @return array|null The generated response or null if generation failed
     */
    private function generateMentionResponse(string $messageText, string $username, string $chatContext = '', ?string $inputImageUrl = null, int $chatId = 0): ?array
    {
        // If inputImageUrl is provided, it's a base64-encoded image
        $isBase64 = $inputImageUrl !== null;
        return $this->aiService->generateMentionResponse($messageText, $username, $chatContext, $inputImageUrl, $isBase64, $chatId);
    }
}