<?php

namespace App\Services;

use Longman\TelegramBot\Request;

/**
 * Handles additive per-chat metadata caching for prompt context.
 */
class ChatMetadataService
{
    private const REFRESH_TTL_SECONDS = 21600;

    private string $dataPath;
    private LoggerService $logger;
    private SettingsService $settingsService;

    public function __construct(string $dataPath, LoggerService $logger, SettingsService $settingsService)
    {
        $this->dataPath = $dataPath;
        $this->logger = $logger;
        $this->settingsService = $settingsService;
    }

    public function seedFromMessage(int $chatId, ?string $title, ?string $username, ?string $type): void
    {
        try {
            $snapshot = $this->loadSnapshot($chatId) ?? new ChatMetadataSnapshot($chatId);
            $snapshot->title = $this->normalizeNullableString($title) ?? $snapshot->title;
            $snapshot->username = $this->normalizeUsername($username) ?? $snapshot->username;
            $snapshot->type = $this->normalizeNullableString($type) ?? $snapshot->type;
            $this->saveSnapshot($snapshot);
        } catch (\Throwable $e) {
            $this->logger->logError('Failed to seed chat metadata: ' . $e->getMessage(), 'ChatMetadata');
        }
    }

    public function getSnapshot(int $chatId, bool $refresh = false): ChatMetadataSnapshot
    {
        $snapshot = $this->loadSnapshot($chatId) ?? new ChatMetadataSnapshot($chatId);

        $needsRefresh = $refresh || $snapshot->lastFetchedAt === null || (time() - $snapshot->lastFetchedAt) >= self::REFRESH_TTL_SECONDS;
        if ($needsRefresh) {
            $snapshot = $this->refreshFromTelegram($snapshot);
        }

        return $snapshot;
    }

    public function buildPromptContext(int $chatId): string
    {
        $snapshot = $this->getSnapshot($chatId);
        $manualContext = $this->settingsService->getSetting($chatId, 'group_context_note', null);

        $lines = [];
        if ($snapshot->title !== null) {
            $lines[] = 'Chat title: ' . $snapshot->title;
        }
        if ($snapshot->username !== null) {
            $lines[] = 'Chat username: @' . $snapshot->username;
        }
        if ($snapshot->type !== null) {
            $lines[] = 'Chat type: ' . $snapshot->type;
        }
        if ($snapshot->description !== null) {
            $lines[] = 'Chat description: ' . $snapshot->description;
        }
        if ($snapshot->pinnedMessageExcerpt !== null) {
            $lines[] = 'Pinned message excerpt: ' . $snapshot->pinnedMessageExcerpt;
        }
        if (is_string($manualContext) && trim($manualContext) !== '') {
            $lines[] = 'Admin-provided group context: ' . trim($manualContext);
        }

        if (empty($lines)) {
            return '';
        }

        return "Chat metadata:\n" . implode("\n", $lines);
    }

    private function refreshFromTelegram(ChatMetadataSnapshot $snapshot): ChatMetadataSnapshot
    {
        try {
            $result = Request::getChat(['chat_id' => $snapshot->chatId]);
            if (!$result->isOk()) {
                $this->logger->logError(
                    'Failed to refresh chat metadata for ' . $snapshot->chatId . ': ' . $result->getDescription(),
                    'ChatMetadata'
                );
                return $snapshot;
            }

            $chat = $result->getResult();

            if ($chat !== null) {
                $snapshot->title = $this->normalizeNullableString($chat->getTitle()) ?? $snapshot->title;
                $snapshot->username = $this->normalizeUsername($chat->getUsername()) ?? $snapshot->username;
                $snapshot->type = $this->normalizeNullableString($chat->getType()) ?? $snapshot->type;

                if (method_exists($chat, 'getDescription')) {
                    $snapshot->description = $this->excerpt($chat->getDescription(), 400) ?? $snapshot->description;
                }

                if (method_exists($chat, 'getPinnedMessage')) {
                    $pinnedMessage = $chat->getPinnedMessage();
                    if ($pinnedMessage !== null) {
                        $pinnedText = null;
                        if (method_exists($pinnedMessage, 'getText')) {
                            $pinnedText = $pinnedMessage->getText();
                        }
                        if ((!is_string($pinnedText) || trim($pinnedText) === '') && method_exists($pinnedMessage, 'getCaption')) {
                            $pinnedText = $pinnedMessage->getCaption();
                        }
                        $snapshot->pinnedMessageExcerpt = $this->excerpt($pinnedText, 280) ?? $snapshot->pinnedMessageExcerpt;
                    }
                }
            }

            $snapshot->lastFetchedAt = time();
            $this->saveSnapshot($snapshot);
        } catch (\Throwable $e) {
            $this->logger->logError('Error refreshing chat metadata: ' . $e->getMessage(), 'ChatMetadata', $e);
        }

        return $snapshot;
    }

    private function loadSnapshot(int $chatId): ?ChatMetadataSnapshot
    {
        $filePath = $this->getFilePath($chatId);
        if (!is_file($filePath)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($filePath), true);
        if (!is_array($data)) {
            return null;
        }

        return ChatMetadataSnapshot::fromArray($data);
    }

    private function saveSnapshot(ChatMetadataSnapshot $snapshot): void
    {
        $filePath = $this->getFilePath($snapshot->chatId);
        @file_put_contents(
            $filePath,
            json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function getFilePath(int $chatId): string
    {
        return $this->dataPath . '/' . $chatId . '_chat_meta.json';
    }

    private function normalizeUsername(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = ltrim(trim($value), '@');
        return $value !== '' ? $value : null;
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function excerpt(?string $value, int $maxLength): ?string
    {
        $value = $this->normalizeNullableString($value);
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength - 1)) . '…';
    }
}
