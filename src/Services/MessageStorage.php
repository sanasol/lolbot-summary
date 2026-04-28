<?php

namespace App\Services;

/**
 * Handles legacy flat chat history plus additive structured sidecar history.
 */
class MessageStorage
{
    /**
     * In-memory legacy store: [chat_id => [timestamp => formatted_line]]
     *
     * @var array<int, array<int, string>>
     */
    private array $chatMessages = [];

    private string $logPath;

    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
        $this->loadAllMessagesFromFiles();
    }

    public function loadAllMessagesFromFiles(): void
    {
        $files = glob($this->logPath . '/*_messages.json');
        foreach ($files as $file) {
            if (preg_match('/(-?\d+)_messages\.json$/', $file, $matches)) {
                $this->loadMessagesFromFile((int)$matches[1]);
            }
        }
    }

    /**
     * Store a legacy flat message and append a structured sidecar record.
     *
     * @param array<string, mixed> $metadata
     */
    public function storeMessage(
        int $chatId,
        int $timestamp,
        string $username,
        string $messageText,
        ?int $messageId = null,
        ?int $threadId = null,
        array $metadata = []
    ): void {
        if (!isset($this->chatMessages[$chatId])) {
            $this->chatMessages[$chatId] = [];
        }

        if ($messageId) {
            if ($threadId) {
                $this->chatMessages[$chatId][$timestamp] = sprintf(
                    '[%s] [ID:%d] [TID:%d] %s: %s',
                    date('H:i', $timestamp),
                    $messageId,
                    $threadId,
                    $username,
                    $messageText
                );
            } else {
                $this->chatMessages[$chatId][$timestamp] = sprintf(
                    '[%s] [ID:%d] %s: %s',
                    date('H:i', $timestamp),
                    $messageId,
                    $username,
                    $messageText
                );
            }
        } else {
            $this->chatMessages[$chatId][$timestamp] = sprintf('[%s] %s: %s', date('H:i', $timestamp), $username, $messageText);
        }

        $this->saveMessagesToFile($chatId);

        $messageType = (string)($metadata['message_type'] ?? 'text');
        $caption = $metadata['caption'] ?? null;
        $hasPhoto = (bool)($metadata['has_photo'] ?? str_starts_with(trim($messageText), '[PHOTO]'));

        if ($hasPhoto && $caption === null && str_starts_with(trim($messageText), '[PHOTO]')) {
            $caption = trim((string)preg_replace('/^\[PHOTO\]\s*/', '', $messageText));
            if ($caption === '') {
                $caption = null;
            }
        }

        $record = StructuredMessageRecord::fromArray([
            'ts' => $timestamp,
            'chat_id' => $chatId,
            'thread_id' => $threadId ?? ($metadata['thread_id'] ?? null),
            'message_id' => $messageId ?? ($metadata['message_id'] ?? null),
            'user_id' => $metadata['user_id'] ?? null,
            'username' => $metadata['username'] ?? $this->extractUsername($username),
            'first_name' => $metadata['first_name'] ?? null,
            'last_name' => $metadata['last_name'] ?? null,
            'display_name' => $metadata['display_name'] ?? StructuredMessageRecord::buildDisplayName(
                $metadata['first_name'] ?? null,
                $metadata['last_name'] ?? null,
                $metadata['username'] ?? $this->extractUsername($username),
                $username
            ),
            'is_bot' => (bool)($metadata['is_bot'] ?? str_starts_with($username, '[BOT]')),
            'message_type' => $messageType,
            'text' => $metadata['text'] ?? $messageText,
            'caption' => $caption,
            'has_photo' => $hasPhoto,
            'image_summary' => $metadata['image_summary'] ?? null,
            'legacy_username' => $username,
        ]);

        $this->appendStructuredRecord($record);
    }

    /**
     * Store a structured sidecar message without touching legacy history.
     *
     * @param StructuredMessageRecord|array<string, mixed> $record
     */
    public function storeStructuredMessage(StructuredMessageRecord|array $record): void
    {
        $record = $record instanceof StructuredMessageRecord ? $record : StructuredMessageRecord::fromArray($record);
        $this->appendStructuredRecord($record);
    }

    public function getRecentMessages(int $chatId, int $hours = 24): array
    {
        $cutoff = time() - ($hours * 3600);
        return $this->getMessagesInRange($chatId, $cutoff, time());
    }

    public function getMessagesInRange(int $chatId, int $startTs, int $endTs): array
    {
        if ($startTs > $endTs) {
            [$startTs, $endTs] = [$endTs, $startTs];
        }

        return $this->mergeLegacyAndStructuredMessages($chatId, $startTs, $endTs);
    }

    /**
     * Summary-specific reader that aggregates all threads, preserves [TID:x] markers,
     * excludes Apollo-authored messages, and neutralizes @mentions in source text.
     *
     * @return array{messages: string[], thread_counts: array<string, int>, excluded_bot_messages: int}
     */
    public function getSummaryMessagesInRange(int $chatId, int $startTs, int $endTs, ?string $botUsername = null): array
    {
        if ($startTs > $endTs) {
            [$startTs, $endTs] = [$endTs, $startTs];
        }

        $messages = [];
        $threadCounts = [];
        $excludedBotMessages = 0;
        $normalizedBotUsername = $this->normalizeBotUsername($botUsername);

        foreach ($this->buildMergedEntries($chatId, $startTs, $endTs) as $entry) {
            if ($this->shouldExcludeSummaryEntry($entry, $normalizedBotUsername)) {
                $excludedBotMessages++;
                continue;
            }

            $formattedLine = $this->formatSummaryEntryLine($entry);
            if ($formattedLine === null || trim($formattedLine) === '') {
                continue;
            }

            $threadKey = $this->extractThreadKeyFromEntry($entry);
            $threadCounts[$threadKey] = ($threadCounts[$threadKey] ?? 0) + 1;
            $messages[] = $formattedLine;
        }

        if (isset($threadCounts['main'])) {
            $mainCount = $threadCounts['main'];
            unset($threadCounts['main']);
            ksort($threadCounts, SORT_NATURAL);
            $threadCounts = ['main' => $mainCount] + $threadCounts;
        } else {
            ksort($threadCounts, SORT_NATURAL);
        }

        return [
            'messages' => $messages,
            'thread_counts' => $threadCounts,
            'excluded_bot_messages' => $excludedBotMessages,
        ];
    }

    public function getRecentChatContext(int $chatId, int $maxMessages = 10, int $minutes = 30): array
    {
        $messages = $this->getMessagesInRange($chatId, time() - ($minutes * 60), time());
        if (count($messages) <= $maxMessages) {
            return $messages;
        }

        return array_slice($messages, -$maxMessages);
    }

    public function cleanupOldMessages(): void
    {
        $cutoff = time() - (24 * 7 * 3600);

        foreach ($this->chatMessages as $chatId => &$messages) {
            $chatModified = false;
            foreach ($messages as $timestamp => $message) {
                if ((int)$timestamp < $cutoff) {
                    unset($messages[$timestamp]);
                    $chatModified = true;
                }
            }

            if (empty($messages)) {
                unset($this->chatMessages[$chatId]);
                $legacyFile = $this->getChatLogFile($chatId);
                if (is_file($legacyFile)) {
                    @unlink($legacyFile);
                }
            } elseif ($chatModified) {
                $this->saveMessagesToFile($chatId);
            }

            $this->cleanupStructuredMessagesFile((int)$chatId, $cutoff);
        }
        unset($messages);

        foreach ($this->getStructuredChatIds() as $chatId) {
            if (!isset($this->chatMessages[$chatId])) {
                $this->cleanupStructuredMessagesFile($chatId, $cutoff);
            }
        }
    }

    public function getAllChatIds(): array
    {
        $chatIds = array_keys($this->chatMessages);
        foreach ($this->getStructuredChatIds() as $chatId) {
            $chatIds[] = $chatId;
        }

        $chatIds = array_values(array_unique(array_map('intval', $chatIds)));
        sort($chatIds);

        return $chatIds;
    }

    public function hasUserMessages(int $chatId, string $username): bool
    {
        return $this->countUserMessages($chatId, $username) > 0;
    }

    public function countUserMessages(int $chatId, string $username): int
    {
        $count = 0;
        $needle = trim($username);
        if ($needle === '') {
            return 0;
        }

        foreach ($this->getMessagesInRange($chatId, 0, time()) as $message) {
            if (preg_match('/^\[\d{2}:\d{2}\](?:\s+\[ID:\d+\])?(?:\s+\[TID:\d+\])?\s+(.*?)\:\s/s', $message, $matches) === 1) {
                if (($matches[1] ?? '') === $needle) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @return array<int, array{timestamp:int, line:string, parsed:?array<string, mixed>, record:?StructuredMessageRecord}>
     */
    private function buildMergedEntries(int $chatId, ?int $startTs = null, ?int $endTs = null): array
    {
        $this->loadMessagesFromFile($chatId);

        $legacyEntries = [];
        foreach ($this->chatMessages[$chatId] ?? [] as $timestamp => $line) {
            $timestamp = (int)$timestamp;
            if (($startTs !== null && $timestamp < $startTs) || ($endTs !== null && $timestamp > $endTs)) {
                continue;
            }

            $legacyEntries[] = [
                'timestamp' => $timestamp,
                'line' => $line,
                'parsed' => $this->parseLegacyMessageLine($timestamp, $line),
            ];
        }

        usort($legacyEntries, static fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

        $structuredRecords = [];
        foreach ($this->readStructuredRecords($chatId, $startTs, $endTs) as $rawRecord) {
            $structuredRecords[] = StructuredMessageRecord::fromArray($rawRecord);
        }

        $structuredByKey = [];
        foreach ($structuredRecords as $record) {
            $structuredByKey[$record->dedupeKey()] = $record;
        }

        $usedStructuredKeys = [];
        $combined = [];

        foreach ($legacyEntries as $entry) {
            $legacyKey = $this->buildLegacyDedupeKey($entry['parsed']);
            if ($legacyKey !== null && isset($structuredByKey[$legacyKey])) {
                $combined[] = [
                    'timestamp' => $entry['timestamp'],
                    'line' => $this->formatStructuredRecordLine($structuredByKey[$legacyKey]),
                    'parsed' => $entry['parsed'],
                    'record' => $structuredByKey[$legacyKey],
                ];
                $usedStructuredKeys[$legacyKey] = true;
                continue;
            }

            $combined[] = [
                'timestamp' => $entry['timestamp'],
                'line' => $entry['line'],
                'parsed' => $entry['parsed'],
                'record' => null,
            ];
        }

        foreach ($structuredRecords as $record) {
            $key = $record->dedupeKey();
            if (isset($usedStructuredKeys[$key])) {
                continue;
            }

            $combined[] = [
                'timestamp' => $record->ts,
                'line' => $this->formatStructuredRecordLine($record),
                'parsed' => null,
                'record' => $record,
            ];
        }

        usort($combined, static fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

        return $combined;
    }

    private function mergeLegacyAndStructuredMessages(int $chatId, ?int $startTs = null, ?int $endTs = null): array
    {
        return array_values(array_map(
            static fn (array $entry) => (string)$entry['line'],
            $this->buildMergedEntries($chatId, $startTs, $endTs)
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readStructuredRecords(int $chatId, ?int $startTs = null, ?int $endTs = null): array
    {
        $filePath = $this->getStructuredLogFile($chatId);
        if (!is_file($filePath)) {
            return [];
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $records = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }

            $timestamp = isset($record['ts']) ? (int)$record['ts'] : null;
            if ($timestamp === null) {
                continue;
            }

            if (($startTs !== null && $timestamp < $startTs) || ($endTs !== null && $timestamp > $endTs)) {
                continue;
            }

            $records[] = $record;
        }

        return $records;
    }

    private function appendStructuredRecord(StructuredMessageRecord $record): void
    {
        $filePath = $this->getStructuredLogFile($record->chatId);
        @file_put_contents(
            $filePath,
            json_encode($record->toArray(), JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    private function cleanupStructuredMessagesFile(int $chatId, int $cutoff): void
    {
        $filePath = $this->getStructuredLogFile($chatId);
        if (!is_file($filePath)) {
            return;
        }

        $records = $this->readStructuredRecords($chatId, $cutoff, null);
        if (empty($records)) {
            @unlink($filePath);
            return;
        }

        $payload = '';
        foreach ($records as $record) {
            $payload .= json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
        }

        @file_put_contents($filePath, $payload, LOCK_EX);
    }

    /**
     * @return int[]
     */
    private function getStructuredChatIds(): array
    {
        $chatIds = [];
        $files = glob($this->logPath . '/*_messages_v2.jsonl');
        foreach ($files as $file) {
            if (preg_match('/(-?\d+)_messages_v2\.jsonl$/', $file, $matches)) {
                $chatIds[] = (int)$matches[1];
            }
        }

        return array_values(array_unique($chatIds));
    }

    private function formatStructuredRecordLine(StructuredMessageRecord $record): string
    {
        $prefix = '[' . date('H:i', $record->ts) . ']';
        if ($record->messageId !== null) {
            $prefix .= ' [ID:' . $record->messageId . ']';
        }
        if ($record->threadId !== null) {
            $prefix .= ' [TID:' . $record->threadId . ']';
        }

        return $prefix . ' ' . $record->getContextSpeaker() . ': ' . $record->getContextContent();
    }

    /**
     * @param array{timestamp:int, line:string, parsed:?array<string, mixed>, record:?StructuredMessageRecord} $entry
     */
    private function formatSummaryEntryLine(array $entry): ?string
    {
        $record = $entry['record'] ?? null;
        if ($record instanceof StructuredMessageRecord) {
            $prefix = $this->buildLinePrefix($record->ts, $record->messageId, $record->threadId);
            $speaker = $this->neutralizeSummaryText($record->getContextSpeaker());
            $content = $this->neutralizeSummaryText($record->getContextContent());

            return $prefix . ' ' . $speaker . ': ' . $content;
        }

        $parsed = $entry['parsed'] ?? null;
        if (is_array($parsed)) {
            $prefix = $this->buildLinePrefix(
                (int)($parsed['timestamp'] ?? $entry['timestamp']),
                isset($parsed['message_id']) ? (int)$parsed['message_id'] : null,
                isset($parsed['thread_id']) ? (int)$parsed['thread_id'] : null
            );
            $speaker = $this->neutralizeSummaryText((string)($parsed['username'] ?? 'Unknown'));
            $content = $this->neutralizeSummaryText((string)($parsed['text'] ?? '[EMPTY]'));

            return $prefix . ' ' . $speaker . ': ' . $content;
        }

        $line = trim((string)($entry['line'] ?? ''));
        return $line !== '' ? $this->neutralizeSummaryText($line) : null;
    }

    private function buildLinePrefix(int $timestamp, ?int $messageId = null, ?int $threadId = null): string
    {
        $prefix = '[' . date('H:i', $timestamp) . ']';
        if ($messageId !== null) {
            $prefix .= ' [ID:' . $messageId . ']';
        }
        if ($threadId !== null) {
            $prefix .= ' [TID:' . $threadId . ']';
        }

        return $prefix;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseLegacyMessageLine(int $timestamp, string $line): ?array
    {
        $pattern = '/^\[(?<time>\d{2}:\d{2})\](?:\s+\[ID:(?<message_id>\d+)\])?(?:\s+\[TID:(?<thread_id>\d+)\])?\s+(?<username>.*?):\s(?<text>.*)$/s';
        if (preg_match($pattern, $line, $matches) !== 1) {
            return null;
        }

        return [
            'timestamp' => $timestamp,
            'message_id' => isset($matches['message_id']) && $matches['message_id'] !== '' ? (int)$matches['message_id'] : null,
            'thread_id' => isset($matches['thread_id']) && $matches['thread_id'] !== '' ? (int)$matches['thread_id'] : null,
            'username' => $matches['username'] ?? null,
            'text' => $matches['text'] ?? '',
        ];
    }

    private function buildLegacyDedupeKey(?array $parsedLegacy): ?string
    {
        if ($parsedLegacy === null) {
            return null;
        }

        if (($parsedLegacy['message_id'] ?? null) !== null) {
            return 'id:' . $parsedLegacy['message_id'];
        }

        return 'ts:' . $parsedLegacy['timestamp']
            . '|speaker:' . ($parsedLegacy['username'] ?? '')
            . '|text:' . sha1((string)($parsedLegacy['text'] ?? ''));
    }

    /**
     * @param array{timestamp:int, line:string, parsed:?array<string, mixed>, record:?StructuredMessageRecord} $entry
     */
    private function shouldExcludeSummaryEntry(array $entry, ?string $normalizedBotUsername): bool
    {
        if ($normalizedBotUsername === null) {
            return false;
        }

        $record = $entry['record'] ?? null;
        if ($record instanceof StructuredMessageRecord) {
            if (!$record->isBot) {
                return false;
            }

            $candidates = [
                $record->username,
                $record->legacyUsername,
                $record->displayName,
                $record->getComparableSpeaker(),
            ];

            foreach ($candidates as $candidate) {
                if ($this->speakerMatchesBotUsername((string)$candidate, $normalizedBotUsername)) {
                    return true;
                }
            }

            return false;
        }

        $parsed = $entry['parsed'] ?? null;
        if (!is_array($parsed)) {
            return false;
        }

        return $this->speakerMatchesBotUsername((string)($parsed['username'] ?? ''), $normalizedBotUsername);
    }

    /**
     * @param array{timestamp:int, line:string, parsed:?array<string, mixed>, record:?StructuredMessageRecord} $entry
     */
    private function extractThreadKeyFromEntry(array $entry): string
    {
        $record = $entry['record'] ?? null;
        if ($record instanceof StructuredMessageRecord && $record->threadId !== null) {
            return (string)$record->threadId;
        }

        $parsed = $entry['parsed'] ?? null;
        if (is_array($parsed) && isset($parsed['thread_id']) && $parsed['thread_id'] !== null) {
            return (string)$parsed['thread_id'];
        }

        return 'main';
    }

    private function normalizeBotUsername(?string $botUsername): ?string
    {
        if ($botUsername === null) {
            return null;
        }

        $botUsername = ltrim(trim($botUsername), '@');
        return $botUsername !== '' ? mb_strtolower($botUsername) : null;
    }

    private function speakerMatchesBotUsername(string $speaker, ?string $normalizedBotUsername): bool
    {
        if ($normalizedBotUsername === null) {
            return false;
        }

        $speaker = trim($speaker);
        if ($speaker === '') {
            return false;
        }

        $candidates = [$speaker];
        $withoutBotPrefix = preg_replace('/^\[BOT\]\s*/iu', '', $speaker, 1);
        if (is_string($withoutBotPrefix)) {
            $candidates[] = $withoutBotPrefix;
        }

        foreach (array_unique(array_filter($candidates, static fn ($candidate) => trim((string)$candidate) !== '')) as $candidate) {
            $candidate = trim((string)$candidate);
            $normalizedCandidate = mb_strtolower(ltrim($candidate, '@'));
            if ($normalizedCandidate === $normalizedBotUsername) {
                return true;
            }

            $extracted = $this->extractUsername($candidate);
            if ($extracted !== null && mb_strtolower($extracted) === $normalizedBotUsername) {
                return true;
            }
        }

        return false;
    }

    private function neutralizeSummaryText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $urlMap = [];
        $protected = preg_replace_callback(
            '~(?:https?://|tg://)[^\s<>"\']+~u',
            static function (array $matches) use (&$urlMap): string {
                $key = '__URL_PLACEHOLDER_' . count($urlMap) . '__';
                $urlMap[$key] = $matches[0];
                return $key;
            },
            $text
        );

        if (!is_string($protected)) {
            return $text;
        }

        $neutralized = preg_replace('/(?<![\pL\pN_])@([A-Za-z0-9_]{3,32})\b/u', '$1', $protected);
        if (!is_string($neutralized)) {
            $neutralized = $protected;
        }

        return strtr($neutralized, $urlMap);
    }

    private function getChatLogFile(int $chatId): string
    {
        return $this->logPath . '/' . $chatId . '_messages.json';
    }

    private function getStructuredLogFile(int $chatId): string
    {
        return $this->logPath . '/' . $chatId . '_messages_v2.jsonl';
    }

    private function saveMessagesToFile(int $chatId): void
    {
        if (!isset($this->chatMessages[$chatId])) {
            return;
        }

        @file_put_contents($this->getChatLogFile($chatId), json_encode($this->chatMessages[$chatId]));
    }

    private function loadMessagesFromFile(int $chatId): void
    {
        $filePath = $this->getChatLogFile($chatId);
        if (!is_file($filePath)) {
            return;
        }

        $data = json_decode((string)file_get_contents($filePath), true);
        if (!is_array($data)) {
            return;
        }

        if (!isset($this->chatMessages[$chatId])) {
            $this->chatMessages[$chatId] = [];
        }

        foreach ($data as $timestamp => $message) {
            if (!isset($this->chatMessages[$chatId][$timestamp])) {
                $this->chatMessages[$chatId][$timestamp] = $message;
            }
        }

        ksort($this->chatMessages[$chatId]);
    }

    private function extractUsername(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/@([A-Za-z0-9_]+)/', $value, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
