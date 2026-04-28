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
     * Convert Markdown (as typically output by LLMs) to Telegram-safe HTML.
     *
     * Telegram HTML supports only: <b>, <i>, <u>, <s>, <code>, <pre>, <a>, <blockquote>, <tg-spoiler>.
     * This method converts common Markdown patterns and strips anything else.
     *
     * @param string $text Raw text that may contain Markdown and/or HTML fragments
     * @return string Telegram-safe HTML
     */
    private function convertMarkdownToTelegramHtml(string $text): string
    {
        // Strip any existing HTML tags first — we'll rebuild from Markdown
        $text = strip_tags($text);

        // Normalize line breaks
        $text = preg_replace("/\r\n|\r/", "\n", $text);

        // --- Step 1: Extract and protect code blocks (```) ---
        // Use %% placeholders that survive htmlspecialchars (null bytes get destroyed by ENT_SUBSTITUTE)
        $codeBlocks = [];
        $text = preg_replace_callback('/```(?:\w*)\n?(.*?)```/su', function ($m) use (&$codeBlocks) {
            $placeholder = '%%CODEBLOCK' . count($codeBlocks) . '%%';
            $escaped = htmlspecialchars(trim($m[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $codeBlocks[$placeholder] = '<pre>' . $escaped . '</pre>';
            return $placeholder;
        }, $text);

        // --- Step 1.5: Detect and format Markdown tables as <pre> blocks ---
        $tableBlocks = [];
        $lines = explode("\n", $text);
        $resultLines = [];
        $tableBuffer = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^\|.+\|$/', $trimmed)) {
                $tableBuffer[] = $trimmed;
            } else {
                if (count($tableBuffer) >= 2) {
                    $placeholder = '%%TABLE' . count($tableBlocks) . '%%';
                    $formatted = $this->formatTableBlock($tableBuffer);
                    $tableBlocks[$placeholder] = $formatted;
                    $resultLines[] = $placeholder;
                } elseif (count($tableBuffer) === 1) {
                    $resultLines[] = $tableBuffer[0];
                }
                $tableBuffer = [];
                $resultLines[] = $line;
            }
        }
        if (count($tableBuffer) >= 2) {
            $placeholder = '%%TABLE' . count($tableBlocks) . '%%';
            $formatted = $this->formatTableBlock($tableBuffer);
            $escaped = htmlspecialchars($formatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $tableBlocks[$placeholder] = '<pre>' . $escaped . '</pre>';
            $resultLines[] = $placeholder;
        } elseif (count($tableBuffer) === 1) {
            $resultLines[] = $tableBuffer[0];
        }
        $text = implode("\n", $resultLines);

        // --- Step 2: Extract and protect inline code (`) ---
        $inlineCodes = [];
        $text = preg_replace_callback('/`([^`\n]+)`/u', function ($m) use (&$inlineCodes) {
            $placeholder = '%%INLINECODE' . count($inlineCodes) . '%%';
            $escaped = htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $inlineCodes[$placeholder] = '<code>' . $escaped . '</code>';
            return $placeholder;
        }, $text);

        // --- Step 3: Escape HTML entities in remaining text ---
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // --- Step 4: Convert Markdown headers (### heading) → <b>heading</b> ---
        $text = preg_replace('/^#{1,6}\s*(.+)$/mu', '<b>$1</b>', $text);

        // --- Step 5: Convert **bold** → <b>bold</b> ---
        $text = preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $text);

        // --- Step 6: Convert list items (- item, * item) → • item ---
        // Must run before italic to prevent * list markers being matched as italic openers
        $text = preg_replace('/^[\-\*]\s+/mu', '• ', $text);

        // --- Step 7: Convert *italic* → <i>italic</i> (but not **) ---
        $text = preg_replace('/(?<!\*)\*([^*\n]+?)\*(?!\*)/u', '<i>$1</i>', $text);

        // --- Step 8: Remove horizontal rules (---, ***, ___) ---
        $text = preg_replace('/^[\-\*_]{3,}$/mu', '', $text);

        // --- Step 9: Convert numbered list items (1. item) → keep number with dot ---
        $text = preg_replace('/^(\d+)\.\s+/mu', '$1. ', $text);

        // --- Step 10: Collapse excessive blank lines ---
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // --- Step 11: Restore code blocks, inline code, and tables ---
        $text = str_replace(array_keys($codeBlocks), array_values($codeBlocks), $text);
        $text = str_replace(array_keys($inlineCodes), array_values($inlineCodes), $text);
        $text = str_replace(array_keys($tableBlocks), array_values($tableBlocks), $text);

        // --- Step 14: Balance unclosed HTML tags (safety net for Telegram parser) ---
        foreach (['b', 'i', 'code', 'pre'] as $tag) {
            $openCount = preg_match_all('/<' . $tag . '(?:\s[^>]*)?>/', $text);
            $closeCount = preg_match_all('/<\/' . $tag . '>/', $text);
            for ($j = $closeCount; $j < $openCount; $j++) {
                $text .= '</' . $tag . '>';
            }
        }

        return trim($text);
    }

    /**
     * Format a block of Markdown table rows into compact Telegram-safe HTML.
     * Header row is bold, no padding (keeps lines short for mobile).
     */
    private function formatTableBlock(array $rows): string
    {
        $parsed = [];

        foreach ($rows as $row) {
            // Skip separator rows (|---|---|)
            if (preg_match('/^\|[\s\-:|]+\|$/', $row)) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($row, '|')));

            // Strip Markdown bold markers
            $cells = array_map(function ($cell) {
                return preg_replace('/\*\*(.+?)\*\*/', '$1', $cell);
            }, $cells);

            $parsed[] = $cells;
        }

        if (empty($parsed)) {
            return '';
        }

        $lines = [];
        foreach ($parsed as $i => $cells) {
            // Escape HTML entities in each cell
            $escapedCells = array_map(function ($cell) {
                return htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }, $cells);

            $line = implode(' | ', $escapedCells);

            if ($i === 0 && count($parsed) > 1) {
                // Bold header row
                $lines[] = '<b>' . $line . '</b>';
            } else {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
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
        // Save raw response for web viewing
        $responseId = bin2hex(random_bytes(16));
        $responsesDir = ($this->config['log_path'] ?? __DIR__ . '/../../data') . '/responses';
        if (!is_dir($responsesDir)) {
            mkdir($responsesDir, 0755, true);
        }
        file_put_contents($responsesDir . '/' . $responseId . '.md', $html);
        $viewUrl = 'https://sum.statbate.com/r/' . $responseId;

        // Convert Markdown/mixed content to Telegram-safe HTML
        $converted = $this->convertMarkdownToTelegramHtml($html);
        $converted = preg_replace("/\r\n|\r|\n/", "\n", $converted);

        // Guard: avoid empty content which causes Telegram Bad Request
        if (trim($converted) === '') {
            $converted = '<i>Nothing to show</i>';
        }

        // Split into Telegram-safe chunks (4096 char limit) with view link on last chunk
        $hashTag = "\n\n#dataRequest\n📊 <a href=\"{$viewUrl}\">View full result</a>";
        $messages = $this->splitForTelegram($converted, $hashTag);
        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);

        $this->logger->log(
            "Sending " . count($messages) . " message(s) for HTML response",
            "Telegram SafeSend"
        );

        $result = null;

        foreach ($messages as $i => $formattedText) {
            $this->logger->log(
                "Sending part " . ($i + 1) . "/" . count($messages) . " (len=" . mb_strlen($formattedText) . ")",
                "Telegram SafeSend"
            );

            $params = [
                'chat_id' => $chatId,
                'text' => $formattedText,
                'parse_mode' => 'HTML'
            ];

            // Only reply to the original message with the first chunk
            if ($i === 0 && $replyToMessageId !== null) {
                $params['reply_to_message_id'] = $replyToMessageId;
            }

            if ($threadId !== null) {
                $params['message_thread_id'] = (int)$threadId;
            }

            // Additional params only on first message
            if ($i === 0) {
                $params = array_merge($params, $additionalParams);
            }

            $result = Request::sendMessage($params);

            // Log any error
            if (!$result->isOk()) {
                $this->logger->logError(
                    'Telegram sendMessage failed (part ' . ($i + 1) . '): '
                    . ($result->getDescription() ?? 'unknown')
                    . ' (len=' . mb_strlen($formattedText) . ')',
                    'Telegram SafeSend'
                );
            }

            // Fallback to plain text if HTML parse fails
            if (!$result->isOk() && stripos($result->getDescription() ?? '', "can't parse entities") !== false) {
                $this->logger->logError(
                    'HTML parse failed (part ' . ($i + 1) . '), retrying as plain text | snippet: '
                    . mb_substr($formattedText, 0, 500),
                    'Telegram SafeSend'
                );

                $plain = html_entity_decode(strip_tags($formattedText), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $fallbackParams = [
                    'chat_id' => $chatId,
                    'text' => $plain
                ];
                if ($i === 0 && $replyToMessageId !== null) {
                    $fallbackParams['reply_to_message_id'] = $replyToMessageId;
                }
                if ($threadId !== null) {
                    $fallbackParams['message_thread_id'] = (int)$threadId;
                }
                $result = Request::sendMessage($fallbackParams);
            }
        }

        // Store the bot's message if message storage is available and the last message was sent successfully
        if ($this->messageStorage !== null && $result !== null && $result->isOk()) {
            $resultData = $result->getResult();
            if ($resultData) {
                $timestamp = $resultData->getDate();
                $messageId = $resultData->getMessageId();
                $messageThreadId = method_exists($resultData, 'getMessageThreadId') ? $resultData->getMessageThreadId() : $threadId;
                $botUsername = $resultData->getFrom()->getUsername() ?? 'Bot';

                $this->messageStorage->storeMessage(
                    $chatId,
                    $timestamp,
                    "[BOT] " . $botUsername,
                    strip_tags($html),
                    $messageId,
                    $messageThreadId,
                    [
                        'is_bot' => true,
                        'username' => $botUsername,
                        'display_name' => '[BOT] ' . $botUsername,
                        'text' => strip_tags($html),
                        'message_type' => 'text',
                    ]
                );

                $this->logger->log("Stored bot HTML message in chat {$chatId}", "Bot Message Storage");
            }
        }

        return $result;
    }

    /**
     * Split HTML content into Telegram-safe chunks (max 4096 chars each),
     * wrapping each in a blockquote. Respects <pre> block boundaries.
     */
    private function splitForTelegram(string $content, string $hashTag = "\n\n#dataRequest"): array
    {
        $wrapOpen = '<blockquote expandable>';
        $wrapClose = '</blockquote>';
        $overhead = mb_strlen($wrapOpen . $wrapClose . $hashTag);
        $maxContent = 4096 - $overhead - 10; // safety margin

        // Fits in one message
        if (mb_strlen($content) <= $maxContent) {
            return [$wrapOpen . $content . $wrapClose . $hashTag];
        }

        // Split content into segments, keeping <pre> blocks as atomic units
        $lines = explode("\n", $content);
        $segments = [];
        $preBuffer = null;

        foreach ($lines as $line) {
            if ($preBuffer !== null) {
                $preBuffer .= "\n" . $line;
                if (strpos($line, '</pre>') !== false) {
                    $segments[] = $preBuffer;
                    $preBuffer = null;
                }
            } elseif (strpos($line, '<pre>') !== false && strpos($line, '</pre>') === false) {
                $preBuffer = $line;
            } else {
                $segments[] = $line;
            }
        }
        if ($preBuffer !== null) {
            $segments[] = $preBuffer;
        }

        // Group segments into chunks that fit within the limit
        $chunks = [];
        $currentChunk = '';

        foreach ($segments as $segment) {
            $test = $currentChunk === '' ? $segment : $currentChunk . "\n" . $segment;
            if (mb_strlen($test) > $maxContent && $currentChunk !== '') {
                $chunks[] = $currentChunk;
                $currentChunk = $segment;
            } else {
                $currentChunk = $test;
            }

            // If a single segment exceeds the limit, force-split by lines
            if (mb_strlen($currentChunk) > $maxContent) {
                $subLines = explode("\n", $currentChunk);
                $currentChunk = '';
                foreach ($subLines as $subLine) {
                    $testSub = $currentChunk === '' ? $subLine : $currentChunk . "\n" . $subLine;
                    if (mb_strlen($testSub) > $maxContent && $currentChunk !== '') {
                        $chunks[] = $currentChunk;
                        $currentChunk = $subLine;
                    } else {
                        $currentChunk = $testSub;
                    }
                }
            }
        }
        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        // Wrap each chunk with blockquote, balance tags, add hashtag to last
        $messages = [];
        $lastIndex = count($chunks) - 1;

        foreach ($chunks as $i => $chunk) {
            // Balance unclosed/unopened HTML tags
            foreach (['pre', 'code', 'b', 'i'] as $tag) {
                $opens = preg_match_all('/<' . $tag . '(?:\s[^>]*)?>/', $chunk);
                $closes = preg_match_all('/<\/' . $tag . '>/', $chunk);
                // Add missing closers at end
                for ($j = $closes; $j < $opens; $j++) {
                    $chunk .= '</' . $tag . '>';
                }
                // Add missing openers at start (for split <pre> blocks)
                for ($j = $opens; $j < $closes; $j++) {
                    $chunk = '<' . $tag . '>' . $chunk;
                }
            }

            $suffix = ($i === $lastIndex) ? $hashTag : '';
            $messages[] = $wrapOpen . $chunk . $wrapClose . $suffix;
        }

        return $messages;
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
                $messageThreadId = method_exists($resultData, 'getMessageThreadId') ? $resultData->getMessageThreadId() : $threadId;
                $botUsername = $resultData->getFrom()->getUsername() ?? 'Bot';

                // Store the message with [BOT] prefix to distinguish it
                $this->messageStorage->storeMessage(
                    $chatId,
                    $timestamp,
                    "[BOT] " . $botUsername,
                    $safeText,
                    $messageId,
                    $messageThreadId,
                    [
                        'is_bot' => true,
                        'username' => $botUsername,
                        'display_name' => '[BOT] ' . $botUsername,
                        'text' => $safeText,
                        'message_type' => 'text',
                    ]
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
                $messageThreadId = method_exists($resultData, 'getMessageThreadId') ? $resultData->getMessageThreadId() : null;
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
                    $messageId,
                    $messageThreadId,
                    [
                        'is_bot' => true,
                        'username' => $botUsername,
                        'display_name' => '[BOT] ' . $botUsername,
                        'caption' => $caption,
                        'message_type' => 'photo',
                        'has_photo' => true,
                    ]
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
     * @param int|null $messageThreadId The message thread ID for forum topics
     * @return ServerResponse The response from Telegram
     */
    public function sendChatAction(int $chatId, string $action = 'typing', ?int $messageThreadId = null): ServerResponse
    {
        $params = [
            'chat_id' => $chatId,
            'action' => $action
        ];

        if ($messageThreadId !== null) {
            $params['message_thread_id'] = $messageThreadId;
        }

        return Request::sendChatAction($params);
    }
}
