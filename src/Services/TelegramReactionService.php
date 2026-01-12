<?php

namespace App\Services;

/**
 * Sends reactions to Telegram messages using setMessageReaction endpoint
 */
class TelegramReactionService
{
    private LoggerService $logger;
    private array $config;

    public function __construct(LoggerService $logger, array $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Send a reaction to a specific message using Telegram Bot API setMessageReaction.
     *
     * @param int $chatId
     * @param int $messageId
     * @param string $emoji Emoji to react with
     * @param bool $isBig Whether to send big reaction animation
     * @return bool
     */
    public function sendReaction(int $chatId, int $messageId, string $emoji = '👍', bool $isBig = false): bool
    {
        try {
            $token = getenv('TELEGRAM_BOT_TOKEN') ?: ($this->config['telegram_bot_token'] ?? null);
            if (!$token) {
                throw new \RuntimeException('Telegram bot token is not configured');
            }

            $url = "https://api.telegram.org/bot{$token}/setMessageReaction";
            $payload = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reaction' => [
                    [
                        'type' => 'emoji',
                        'emoji' => $emoji,
                    ],
                ],
                'is_big' => $isBig,
            ];

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'timeout' => 10,
                ],
            ]);

            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                $this->logger->logBotMention("setMessageReaction HTTP error for chat {$chatId}, message {$messageId}");
                return false;
            }
            $decoded = json_decode($result, true);
            $ok = is_array($decoded) && ($decoded['ok'] ?? false) === true;
            if (!$ok) {
                $this->logger->logBotMention("setMessageReaction returned not ok: " . substr($result, 0, 200));
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->logger->logBotMention("Exception while sending reaction: " . $e->getMessage());
            return false;
        }
    }
}
