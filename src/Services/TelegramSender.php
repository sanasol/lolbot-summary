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
    private ?MessageStorage $messageStorage;
    private SettingsService $settingsService;

    public function __construct(
        MarkdownService $markdownService,
        LoggerService $logger,
        array $config,
        SettingsService $settingsService,
        ?MessageStorage $messageStorage = null
    ) {
        $this->markdownService = $markdownService;
        $this->logger = $logger;
        $this->config = $config;
        $this->settingsService = $settingsService;
        $this->messageStorage = $messageStorage;
    }

    /**
     * Send a response produced as HTML-like/text safely to Telegram using HTML parse_mode.
     * The input can be arbitrary text (may include Markdown or HTML fragments). We sanitize it
     * to avoid Telegram "can't parse entities" errors and wrap into a collapsible blockquote.
     *
     * @param int $chatId The chat ID to send the message to
     * @param string $html The text/HTML content to send
     * @param int|null $replyToMessageId Optional message ID to reply to
     * @param array $additionalParams Additional parameters for the sendMessage request
     * @return ServerResponse The response from Telegram
     */
    public function sendHtmlAsMarkdownMessage(int $chatId, string $html, ?int $replyToMessageId = null, array $additionalParams = []): ServerResponse
    {
        // 1) Sanitize to safe HTML: drop any tags to avoid malformed entities, then escape
        $textOnly = strip_tags($html);
        $escaped = htmlspecialchars($textOnly, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Normalize line breaks into <br/>
        $escaped = preg_replace("/\r\n|\r|\n/", "\n", $escaped);
        $escapedWithBreaks = nl2br($escaped, false);

        // Guard: avoid empty content which causes Telegram Bad Request
        if (trim($escaped) === '') {
            $escapedWithBreaks = '<i>Nothing to show</i>';
        }

        // Wrap in Telegram-supported blockquote
        $formattedText = '<blockquote expandable>' . $escapedWithBreaks . '</blockquote>' . "\n\n#dataRequest";

        // Log the formatted text before sending (trim to avoid log bloat)
        $this->logger->log(
            "Prepared safe HTML for Telegram (len=" . strlen($formattedText) . ")",
            "Telegram SafeSend"
        );

        // Prepare the request parameters
        $params = [
            'chat_id' => $chatId,
            'text' => $formattedText,
            'parse_mode' => 'HTML'
        ];

        // Add reply_to_message_id if provided
        if ($replyToMessageId !== null) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }

        // Respect configured topic/thread if set for this chat
        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) {
            $params['message_thread_id'] = (int)$threadId;
        }

        // Add any additional parameters
        $params = array_merge($params, $additionalParams);

        // Send the message
        $result = Request::sendMessage($params);

        // If Telegram complains about entities, retry with plain text (no parse_mode)
        if (!$result->isOk() && stripos($result->getDescription() ?? '', "can't parse entities") !== false) {
            $this->logger->logError(
                'HTML parse failed, retrying as plain text: ' . $result->getDescription(),
                'Telegram SafeSend'
            );

            $plain = html_entity_decode($escaped, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $fallbackParams = [
                'chat_id' => $chatId,
                'text' => $plain
            ];
            if ($replyToMessageId !== null) {
                $fallbackParams['reply_to_message_id'] = $replyToMessageId;
            }
            if ($threadId !== null) {
                $fallbackParams['message_thread_id'] = (int)$threadId;
            }
            $result = Request::sendMessage($fallbackParams);
        }

        // Store the bot's message if message storage is available and the message was sent successfully
        if ($this->messageStorage !== null && $result->isOk()) {
            $resultData = $result->getResult();
            if ($resultData) {
                $timestamp = $resultData->getDate();
                $messageId = $resultData->getMessageId();
                $botUsername = $resultData->getFrom()->getUsername() ?? 'Bot';

                // Store plain text version in history
                $this->messageStorage->storeMessage(
                    $chatId,
                    $timestamp,
                    "[BOT] " . $botUsername,
                    strip_tags($html),
                    $messageId
                );

                $this->logger->log("Stored bot HTML message in chat {$chatId}", "Bot Message Storage");
            }
        }

        return $result;
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
        // Guard: avoid empty content which causes Telegram Bad Request
        $safeText = trim($text);
        if ($safeText === '') {
            $safeText = 'Sorry, I could not generate a response for this request.';
        }

        $params = [
            'chat_id' => $chatId,
            'text' => $safeText
        ];

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        if ($replyToMessageId !== null) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }

        // Respect configured topic/thread if set for this chat
        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) {
            $params['message_thread_id'] = (int)$threadId;
        }

        // Send the message
        $result = Request::sendMessage($params);

        // Store the bot's message if message storage is available and the message was sent successfully
        if ($this->messageStorage !== null && $result->isOk()) {
            $resultData = $result->getResult();
            if ($resultData) {
                $timestamp = $resultData->getDate();
                $messageId = $resultData->getMessageId();
                $botUsername = $resultData->getFrom()->getUsername() ?? 'Bot';

                // Store the message with [BOT] prefix to distinguish it
                $this->messageStorage->storeMessage(
                    $chatId,
                    $timestamp,
                    "[BOT] " . $botUsername,
                    $safeText,
                    $messageId
                );

                $this->logger->log("Stored bot message in chat {$chatId}", "Bot Message Storage");
            }
        }

        return $result;
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

        // Send the photo
        $result = Request::sendPhoto($params);

        // Store the bot's message if message storage is available and the message was sent successfully
        if ($this->messageStorage !== null && $result->isOk()) {
            $resultData = $result->getResult();
            if ($resultData) {
                $timestamp = $resultData->getDate();
                $messageId = $resultData->getMessageId();
                $botUsername = $resultData->getFrom()->getUsername() ?? 'Bot';

                // Store the message with [BOT] prefix and [PHOTO] indicator
                $messageText = "[PHOTO]";
                if ($caption) {
                    $messageText .= " " . $caption;
                }

                $this->messageStorage->storeMessage(
                    $chatId,
                    $timestamp,
                    "[BOT] " . $botUsername,
                    $messageText,
                    $messageId
                );

                $this->logger->log("Stored bot photo message in chat {$chatId}", "Bot Message Storage");
            }
        }

        return $result;
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
