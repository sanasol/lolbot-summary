<?php

namespace App\Services;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Handles sending messages to Telegram
 */
class TelegramSender
{
    private MarkdownService $markdownService;
    private LoggerService $logger;
    private array $config;

    public function __construct(MarkdownService $markdownService, LoggerService $logger, array $config)
    {
        $this->markdownService = $markdownService;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Send a message with HTML formatting converted to MarkdownV2
     *
     * @param int $chatId The chat ID to send the message to
     * @param string $html The HTML text to send
     * @param int|null $replyToMessageId Optional message ID to reply to
     * @param array $additionalParams Additional parameters for the sendMessage request
     * @return ServerResponse The response from Telegram
     */
    public function sendHtmlAsMarkdownMessage(int $chatId, string $html, ?int $replyToMessageId = null, array $additionalParams = []): ServerResponse
    {
        // Convert HTML to Telegram's MarkdownV2 format
        $formattedText = $this->markdownService->htmlToTelegramMarkdown($html);

        // Log the formatted text before sending
        $this->logger->log(
            "Converted HTML to Markdown: " . $formattedText . PHP_EOL . "Original HTML: " . $html,
            "HTML to Markdown Conversion"
        );

        // Prepare the request parameters
        $params = [
            'chat_id' => $chatId,
            'text' => $formattedText,
            'parse_mode' => 'MarkdownV2'
        ];

        // Add reply_to_message_id if provided
        if ($replyToMessageId !== null) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }

        // Add any additional parameters
        $params = array_merge($params, $additionalParams);

        // Send the message
        return Request::sendMessage($params);
    }

    /**
     * Send a simple text message
     *
     * @param int $chatId The chat ID
     * @param string $text The text to send
     * @param int|null $replyToMessageId Optional message ID to reply to
     * @param string|null $parseMode Optional parse mode (Markdown, HTML, MarkdownV2)
     * @return ServerResponse The response from Telegram
     */
    public function sendMessage(int $chatId, string $text, ?int $replyToMessageId = null, ?string $parseMode = null): ServerResponse
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text
        ];

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        if ($replyToMessageId !== null) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }

        return Request::sendMessage($params);
    }

    /**
     * Send a photo with optional caption
     *
     * @param int $chatId The chat ID
     * @param string $photoUrl The photo URL or file ID
     * @param string|null $caption Optional caption
     * @param int|null $replyToMessageId Optional message ID to reply to
     * @return ServerResponse The response from Telegram
     */
    public function sendPhoto(int $chatId, string $photoUrl, ?string $caption = null, ?int $replyToMessageId = null): ServerResponse
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photoUrl
        ];

        if ($caption !== null) {
            $params['caption'] = $caption;
        }

        if ($replyToMessageId !== null) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }

        return Request::sendPhoto($params);
    }

    /**
     * Pin a message in a chat
     *
     * @param int $chatId The chat ID
     * @param int $messageId The message ID to pin
     * @param bool $disableNotification Whether to disable the notification
     * @return ServerResponse The response from Telegram
     */
    public function pinChatMessage(int $chatId, int $messageId, bool $disableNotification = true): ServerResponse
    {
        return Request::pinChatMessage([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'disable_notification' => $disableNotification
        ]);
    }

    /**
     * Send a chat action (typing, uploading photo, etc.)
     *
     * @param int $chatId The chat ID
     * @param string $action The action to send
     * @return ServerResponse The response from Telegram
     */
    public function sendChatAction(int $chatId, string $action = 'typing'): ServerResponse
    {
        return Request::sendChatAction([
            'chat_id' => $chatId,
            'action' => $action
        ]);
    }
}