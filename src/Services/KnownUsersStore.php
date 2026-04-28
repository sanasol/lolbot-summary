<?php

namespace App\Services;

/**
 * Durable per-chat known user store with additive backfill from retained evidence.
 */
class KnownUsersStore
{
    public const STATUS_CANDIDATE = 'candidate';
    public const STATUS_KNOWN = 'known';

    private const SOURCE_SNAPSHOTS_KEY = 'source_snapshots';

    /**
     * Sources whose counts are imported from retained files and should never be decremented.
     *
     * @var string[]
     */
    private const IMPORTED_COUNT_SOURCES = [
        'messages_v2',
        'webhook_log',
        'usage',
    ];

    /**
     * Sources that force a user into known status immediately.
     *
     * @var string[]
     */
    private const FORCE_KNOWN_SOURCES = [
        'captcha_verified',
        'legacy_verified_import',
        'admin_status',
    ];

    /**
     * Source priority for the public `source` field.
     *
     * @var array<string, int>
     */
    private const SOURCE_PRIORITY = [
        'admin_status' => 100,
        'captcha_verified' => 90,
        'legacy_verified_import' => 80,
        'messages_v2' => 70,
        'runtime_accept' => 60,
        'webhook_log' => 50,
        'usage' => 40,
    ];

    private LoggerService $logger;
    private string $dataPath;
    private string $stateFile;
    private bool $deferSave = false;

    /**
     * @var array{
     *   known_users: array<string, array<string, array<string, mixed>>>,
     *   meta: array<string, mixed>
     * }
     */
    private array $state = [
        'known_users' => [],
        'meta' => [],
    ];

    public function __construct(string $dataPath, LoggerService $logger)
    {
        $this->dataPath = rtrim($dataPath, '/');
        $this->stateFile = $this->dataPath . '/known_users.json';
        $this->logger = $logger;
        $this->loadState();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRecord(int $chatId, int $userId): ?array
    {
        if ($chatId >= 0 || $userId <= 0) {
            return null;
        }

        $chatKey = (string)$chatId;
        $userKey = (string)$userId;

        return $this->state['known_users'][$chatKey][$userKey] ?? null;
    }

    public function isKnown(int $chatId, int $userId): bool
    {
        return ($this->getRecord($chatId, $userId)['status'] ?? null) === self::STATUS_KNOWN;
    }

    /**
     * @param array<string, mixed>|null $senderContext
     */
    public function markKnown(
        int $chatId,
        int $userId,
        ?array $senderContext,
        string $source,
        ?int $timestamp = null
    ): void {
        if ($chatId >= 0 || $userId <= 0) {
            return;
        }

        $timestamp ??= time();
        $record = $this->getOrCreateRecord($chatId, $userId);
        $this->applySenderContext($record, $senderContext, $timestamp);

        $sourceEvidence = $record['evidence'][$source] ?? ['count' => 0];
        $sourceEvidence['count'] = max((int)($sourceEvidence['count'] ?? 0), 2);
        $record['evidence'][$source] = $sourceEvidence;
        $record['status'] = self::STATUS_KNOWN;

        $this->recomputeRecord($record);
        $this->saveRecord($chatId, $userId, $record);
    }

    /**
     * Record one accepted non-command message for a user.
     *
     * @param array<string, mixed>|null $senderContext
     * @return array<string, mixed>
     */
    public function addAcceptedMessage(
        int $chatId,
        int $userId,
        ?array $senderContext,
        string $source = 'runtime_accept',
        ?int $timestamp = null
    ): array {
        if ($chatId >= 0 || $userId <= 0) {
            return [];
        }

        $timestamp ??= time();
        $record = $this->getOrCreateRecord($chatId, $userId);
        $this->applySenderContext($record, $senderContext, $timestamp);

        $sourceEvidence = $record['evidence'][$source] ?? ['count' => 0];
        $sourceEvidence['count'] = ((int)($sourceEvidence['count'] ?? 0)) + 1;
        $record['evidence'][$source] = $sourceEvidence;

        $this->recomputeRecord($record);
        $this->saveRecord($chatId, $userId, $record);

        return $record;
    }

    /**
     * @param array<string, mixed>|null $senderContext
     */
    public function touchSeen(int $chatId, int $userId, ?array $senderContext = null, ?int $timestamp = null): void
    {
        $record = $this->getRecord($chatId, $userId);
        if ($record === null) {
            return;
        }

        $timestamp ??= time();
        $this->applySenderContext($record, $senderContext, $timestamp);
        $this->saveRecord($chatId, $userId, $record);
    }

    /**
     * Re-scan retained sources and merge user-id-backed evidence into known users.
     *
     * @return array<string, mixed>
     */
    public function backfillFromAvailableSources(bool $force = false): array
    {
        $runtimeCleared = $this->clearRuntimeAcceptEvidence();
        $snapshots = $this->buildSourceSnapshots();
        $previousSnapshots = (array)($this->state['meta'][self::SOURCE_SNAPSHOTS_KEY] ?? []);

        if (!$force && $previousSnapshots === $snapshots) {
            if ($runtimeCleared) {
                $this->saveState();
            }
            return [
                'skipped' => true,
                'known_users' => $this->countUsersByStatus(self::STATUS_KNOWN),
                'candidate_users' => $this->countUsersByStatus(self::STATUS_CANDIDATE),
            ];
        }

        $countsBySource = [];
        $forcedKnownBySource = [];
        $stats = [
            'skipped' => false,
            'messages_v2_events' => 0,
            'webhook_events' => 0,
            'usage_events' => 0,
            'verified_imports' => 0,
            'forced_known_events' => 0,
        ];

        $this->deferSave = true;
        try {
            $this->collectMessagesV2Evidence($countsBySource, $stats);
            $this->collectVerifiedStateEvidence($forcedKnownBySource, $stats);
            $this->collectWebhookLogEvidence($countsBySource, $forcedKnownBySource, $stats);
            $this->collectUsageEvidence($countsBySource, $stats);

            foreach ($countsBySource as $source => $chatMap) {
                foreach ($chatMap as $chatId => $userMap) {
                    foreach ($userMap as $userId => $info) {
                        $this->mergeImportedCountEvidence(
                            (int)$chatId,
                            (int)$userId,
                            $info['sender_context'] ?? null,
                            $source,
                            (int)($info['count'] ?? 0),
                            (int)($info['first_seen_at'] ?? time()),
                            (int)($info['last_seen_at'] ?? time())
                        );
                    }
                }
            }

            foreach ($forcedKnownBySource as $source => $chatMap) {
                foreach ($chatMap as $chatId => $userMap) {
                    foreach ($userMap as $userId => $info) {
                        $this->mergeImportedKnownEvidence(
                            (int)$chatId,
                            (int)$userId,
                            $info['sender_context'] ?? null,
                            $source,
                            (int)($info['first_seen_at'] ?? time()),
                            (int)($info['last_seen_at'] ?? time())
                        );
                        $stats['forced_known_events']++;
                    }
                }
            }

            $this->state['meta'][self::SOURCE_SNAPSHOTS_KEY] = $snapshots;
            $this->state['meta']['last_backfill_at'] = time();
        } finally {
            $this->deferSave = false;
        }

        $this->saveState();

        $stats['known_users'] = $this->countUsersByStatus(self::STATUS_KNOWN);
        $stats['candidate_users'] = $this->countUsersByStatus(self::STATUS_CANDIDATE);

        return $stats;
    }

    private function loadState(): void
    {
        if (!is_file($this->stateFile)) {
            return;
        }

        $data = json_decode((string)file_get_contents($this->stateFile), true);
        if (!is_array($data)) {
            return;
        }

        if (isset($data['known_users']) && is_array($data['known_users'])) {
            $this->state['known_users'] = $data['known_users'];
        }
        if (isset($data['meta']) && is_array($data['meta'])) {
            $this->state['meta'] = $data['meta'];
        }
    }

    private function saveState(): void
    {
        file_put_contents($this->stateFile, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function countUsersByStatus(string $status): int
    {
        $count = 0;
        foreach ($this->state['known_users'] as $users) {
            foreach ($users as $record) {
                if (($record['status'] ?? null) === $status) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @return array<string, array<string, array{size:int, mtime:int}>>
     */
    private function buildSourceSnapshots(): array
    {
        return [
            'messages_v2' => $this->buildSnapshotsForGlob($this->dataPath . '/*_messages_v2.jsonl'),
            'webhook_logs' => $this->buildSnapshotsForGlob($this->dataPath . '/webhook_*.log'),
            'usage_logs' => $this->buildSnapshotsForGlob($this->dataPath . '/usage/*.jsonl'),
            'restriction_state' => $this->buildSnapshotsForGlob($this->dataPath . '/new_user_restrictions.json'),
        ];
    }

    /**
     * @return array<string, array{size:int, mtime:int}>
     */
    private function buildSnapshotsForGlob(string $pattern): array
    {
        $snapshots = [];
        $files = glob($pattern) ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $snapshots[basename($file)] = [
                'size' => (int)filesize($file),
                'mtime' => (int)filemtime($file),
            ];
        }

        return $snapshots;
    }

    /**
     * @param array<string, array<string, array<string, array<string, mixed>>>> $countsBySource
     * @param array<string, mixed> $stats
     */
    private function collectMessagesV2Evidence(array &$countsBySource, array &$stats): void
    {
        foreach (glob($this->dataPath . '/*_messages_v2.jsonl') ?: [] as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $record = json_decode($line, true);
                if (!is_array($record)) {
                    continue;
                }

                $chatId = (int)($record['chat_id'] ?? 0);
                $userId = (int)($record['user_id'] ?? 0);
                if ($chatId >= 0 || $userId <= 0 || (bool)($record['is_bot'] ?? false)) {
                    continue;
                }

                $senderContext = [
                    'user_id' => $userId,
                    'username' => $record['username'] ?? null,
                    'display_name' => $record['display_name'] ?? null,
                    'is_bot' => false,
                ];

                $ts = (int)($record['ts'] ?? time());
                $this->bumpAggregateCount($countsBySource, 'messages_v2', $chatId, $userId, $senderContext, $ts);
                $stats['messages_v2_events']++;
            }
        }
    }

    /**
     * @param array<string, array<string, array<string, array<string, mixed>>>> $forcedKnownBySource
     * @param array<string, mixed> $stats
     */
    private function collectVerifiedStateEvidence(array &$forcedKnownBySource, array &$stats): void
    {
        $stateFile = $this->dataPath . '/new_user_restrictions.json';
        if (!is_file($stateFile)) {
            return;
        }

        $state = json_decode((string)file_get_contents($stateFile), true);
        if (!is_array($state)) {
            return;
        }

        foreach ((array)($state['newUsers'] ?? []) as $chatId => $users) {
            if ((int)$chatId >= 0 || !is_array($users)) {
                continue;
            }

            foreach ($users as $userId => $record) {
                if (!is_array($record) || !($record['verified'] ?? false)) {
                    continue;
                }

                $joinedAt = (int)($record['joined_at'] ?? time());
                $senderContext = [
                    'user_id' => (int)$userId,
                    'username' => null,
                    'display_name' => $record['username'] ?? 'User',
                    'is_bot' => false,
                ];

                $this->bumpAggregateKnown(
                    $forcedKnownBySource,
                    'legacy_verified_import',
                    (int)$chatId,
                    (int)$userId,
                    $senderContext,
                    $joinedAt
                );
                $stats['verified_imports']++;
            }
        }
    }

    /**
     * @param array<string, array<string, array<string, array<string, mixed>>>> $countsBySource
     * @param array<string, array<string, array<string, array<string, mixed>>>> $forcedKnownBySource
     * @param array<string, mixed> $stats
     */
    private function collectWebhookLogEvidence(array &$countsBySource, array &$forcedKnownBySource, array &$stats): void
    {
        foreach (glob($this->dataPath . '/webhook_*.log') ?: [] as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }

            $currentBlock = null;
            foreach ($lines as $line) {
                if ($this->isRawUpdateStartLine($line)) {
                    if (is_array($currentBlock)) {
                        $this->finalizeWebhookBlock($currentBlock, $countsBySource, $forcedKnownBySource, $stats);
                    }

                    $currentBlock = [
                        'json_lines' => [$this->stripRawUpdatePrefix($line)],
                        'log_lines' => [],
                    ];
                    continue;
                }

                if (is_array($currentBlock) && !$this->isTimestampedLogLine($line)) {
                    $currentBlock['json_lines'][] = rtrim($line, "\r\n");
                    continue;
                }

                if (is_array($currentBlock)) {
                    $currentBlock['log_lines'][] = rtrim($line, "\r\n");
                }
            }

            if (is_array($currentBlock)) {
                $this->finalizeWebhookBlock($currentBlock, $countsBySource, $forcedKnownBySource, $stats);
            }
        }
    }

    /**
     * @param array<string, array<string, array<string, array<string, mixed>>>> $countsBySource
     * @param array<string, array<string, array<string, array<string, mixed>>>> $forcedKnownBySource
     * @param array<string, mixed> $stats
     * @param array{json_lines: array<int, string>, log_lines: array<int, string>} $block
     */
    private function finalizeWebhookBlock(array $block, array &$countsBySource, array &$forcedKnownBySource, array &$stats): void
    {
        $payload = trim(implode("\n", array_filter($block['json_lines'], static fn (string $line): bool => trim($line) !== '')));
        if ($payload === '') {
            return;
        }

        $update = json_decode($payload, true);
        if (!is_array($update)) {
            return;
        }

        $message = null;
        if (isset($update['message']) && is_array($update['message'])) {
            $message = $update['message'];
        } elseif (isset($update['edited_message']) && is_array($update['edited_message'])) {
            $message = $update['edited_message'];
        }

        if (!is_array($message)) {
            return;
        }

        $chat = (array)($message['chat'] ?? []);
        $from = (array)($message['from'] ?? []);
        $chatId = (int)($chat['id'] ?? 0);
        $userId = (int)($from['id'] ?? 0);
        $chatType = (string)($chat['type'] ?? '');

        if ($chatId >= 0 || $userId <= 0 || !in_array($chatType, ['group', 'supergroup'], true) || (bool)($from['is_bot'] ?? false)) {
            return;
        }

        $senderContext = [
            'user_id' => $userId,
            'username' => $from['username'] ?? null,
            'display_name' => StructuredMessageRecord::buildDisplayName(
                $from['first_name'] ?? null,
                $from['last_name'] ?? null,
                $from['username'] ?? null,
                $from['first_name'] ?? ($from['username'] ?? 'User')
            ),
            'is_bot' => false,
        ];

        $ts = (int)($message['date'] ?? time());

        if ($this->blockContainsCaptchaVerification($block['log_lines'], $chatId, $userId)) {
            $this->bumpAggregateKnown($forcedKnownBySource, 'captcha_verified', $chatId, $userId, $senderContext, $ts);
        }

        if ($this->blockContainsRestrictionMarker($block['log_lines'])) {
            return;
        }

        if ($this->isCommandMessagePayload($message)) {
            return;
        }

        if (!$this->blockContainsAcceptedMessageLog($block['log_lines'], $chatId, $chatType)) {
            return;
        }

        $this->bumpAggregateCount($countsBySource, 'webhook_log', $chatId, $userId, $senderContext, $ts);
        $stats['webhook_events']++;
    }

    /**
     * @param array<string, array<string, array<string, array<string, mixed>>>> $countsBySource
     * @param array<string, mixed> $stats
     */
    private function collectUsageEvidence(array &$countsBySource, array &$stats): void
    {
        foreach (glob($this->dataPath . '/usage/*.jsonl') ?: [] as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $entry = json_decode($line, true);
                if (!is_array($entry)) {
                    continue;
                }

                $chatId = (int)($entry['chat_id'] ?? 0);
                $userId = (int)($entry['user_id'] ?? 0);
                $type = (string)($entry['type'] ?? '');
                $success = (bool)($entry['success'] ?? true);
                if ($chatId >= 0 || $userId <= 0 || !$success || !in_array($type, ['mention', 'image'], true)) {
                    continue;
                }

                $timestamp = strtotime((string)($entry['ts'] ?? 'now')) ?: time();
                $senderContext = [
                    'user_id' => $userId,
                    'username' => $entry['username'] ?? null,
                    'display_name' => $entry['username'] ?? null,
                    'is_bot' => false,
                ];

                $this->bumpAggregateCount($countsBySource, 'usage', $chatId, $userId, $senderContext, $timestamp);
                $stats['usage_events']++;
            }
        }
    }

    /**
     * @param array<string, array<string, array<string, array<string, mixed>>>> $countsBySource
     * @param array<string, mixed>|null $senderContext
     */
    private function bumpAggregateCount(
        array &$countsBySource,
        string $source,
        int $chatId,
        int $userId,
        ?array $senderContext,
        int $timestamp
    ): void {
        $chatKey = (string)$chatId;
        $userKey = (string)$userId;

        if (!isset($countsBySource[$source][$chatKey][$userKey])) {
            $countsBySource[$source][$chatKey][$userKey] = [
                'count' => 0,
                'first_seen_at' => $timestamp,
                'last_seen_at' => $timestamp,
                'sender_context' => $senderContext,
            ];
        }

        $countsBySource[$source][$chatKey][$userKey]['count']++;
        $countsBySource[$source][$chatKey][$userKey]['first_seen_at'] = min(
            (int)$countsBySource[$source][$chatKey][$userKey]['first_seen_at'],
            $timestamp
        );
        $countsBySource[$source][$chatKey][$userKey]['last_seen_at'] = max(
            (int)$countsBySource[$source][$chatKey][$userKey]['last_seen_at'],
            $timestamp
        );
        if ($senderContext !== null) {
            $countsBySource[$source][$chatKey][$userKey]['sender_context'] = $senderContext;
        }
    }

    /**
     * @param array<string, array<string, array<string, array<string, mixed>>>> $forcedKnownBySource
     * @param array<string, mixed>|null $senderContext
     */
    private function bumpAggregateKnown(
        array &$forcedKnownBySource,
        string $source,
        int $chatId,
        int $userId,
        ?array $senderContext,
        int $timestamp
    ): void {
        $chatKey = (string)$chatId;
        $userKey = (string)$userId;

        if (!isset($forcedKnownBySource[$source][$chatKey][$userKey])) {
            $forcedKnownBySource[$source][$chatKey][$userKey] = [
                'first_seen_at' => $timestamp,
                'last_seen_at' => $timestamp,
                'sender_context' => $senderContext,
            ];
            return;
        }

        $forcedKnownBySource[$source][$chatKey][$userKey]['first_seen_at'] = min(
            (int)$forcedKnownBySource[$source][$chatKey][$userKey]['first_seen_at'],
            $timestamp
        );
        $forcedKnownBySource[$source][$chatKey][$userKey]['last_seen_at'] = max(
            (int)$forcedKnownBySource[$source][$chatKey][$userKey]['last_seen_at'],
            $timestamp
        );
        if ($senderContext !== null) {
            $forcedKnownBySource[$source][$chatKey][$userKey]['sender_context'] = $senderContext;
        }
    }

    /**
     * @param array<string, mixed>|null $senderContext
     */
    private function mergeImportedCountEvidence(
        int $chatId,
        int $userId,
        ?array $senderContext,
        string $source,
        int $count,
        int $firstSeenAt,
        int $lastSeenAt
    ): void {
        if ($count <= 0) {
            return;
        }

        $record = $this->getOrCreateRecord($chatId, $userId);
        $this->applySenderContext($record, $senderContext, $lastSeenAt);
        $record['first_seen_at'] = min((int)$record['first_seen_at'], $firstSeenAt);
        $record['last_seen_at'] = max((int)$record['last_seen_at'], $lastSeenAt);

        $sourceEvidence = $record['evidence'][$source] ?? ['count' => 0];
        $sourceEvidence['count'] = max((int)($sourceEvidence['count'] ?? 0), $count);
        $record['evidence'][$source] = $sourceEvidence;

        $this->recomputeRecord($record);
        $this->saveRecord($chatId, $userId, $record);
    }

    /**
     * @param array<string, mixed>|null $senderContext
     */
    private function mergeImportedKnownEvidence(
        int $chatId,
        int $userId,
        ?array $senderContext,
        string $source,
        int $firstSeenAt,
        int $lastSeenAt
    ): void {
        $record = $this->getOrCreateRecord($chatId, $userId);
        $this->applySenderContext($record, $senderContext, $lastSeenAt);
        $record['first_seen_at'] = min((int)$record['first_seen_at'], $firstSeenAt);
        $record['last_seen_at'] = max((int)$record['last_seen_at'], $lastSeenAt);

        $sourceEvidence = $record['evidence'][$source] ?? ['count' => 0];
        $sourceEvidence['count'] = max((int)($sourceEvidence['count'] ?? 0), 2);
        $record['evidence'][$source] = $sourceEvidence;
        $record['status'] = self::STATUS_KNOWN;

        $this->recomputeRecord($record);
        $this->saveRecord($chatId, $userId, $record);
    }

    /**
     * @return array<string, mixed>
     */
    private function getOrCreateRecord(int $chatId, int $userId): array
    {
        $existing = $this->getRecord($chatId, $userId);
        if ($existing !== null) {
            return $existing;
        }

        return [
            'status' => self::STATUS_CANDIDATE,
            'accepted_messages' => 0,
            'first_seen_at' => time(),
            'last_seen_at' => time(),
            'username' => null,
            'display_name' => null,
            'source' => null,
            'evidence' => [],
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $senderContext
     */
    private function applySenderContext(array &$record, ?array $senderContext, int $timestamp): void
    {
        $record['first_seen_at'] = min((int)($record['first_seen_at'] ?? $timestamp), $timestamp);
        $record['last_seen_at'] = max((int)($record['last_seen_at'] ?? $timestamp), $timestamp);

        if (!is_array($senderContext)) {
            return;
        }

        $username = trim((string)($senderContext['username'] ?? ''));
        if ($username !== '') {
            $record['username'] = ltrim($username, '@');
        }

        $displayName = trim((string)($senderContext['display_name'] ?? ''));
        if ($displayName !== '') {
            $record['display_name'] = $displayName;
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function recomputeRecord(array &$record): void
    {
        $historicalAcceptedMessages = 0;
        $runtimeAcceptedMessages = 0;
        $isForcedKnown = false;
        $preferredSource = $record['source'] ?? null;
        $preferredPriority = $preferredSource !== null ? (self::SOURCE_PRIORITY[$preferredSource] ?? 0) : -1;

        foreach ((array)($record['evidence'] ?? []) as $source => $evidence) {
            $count = (int)($evidence['count'] ?? 0);
            if ($source === 'runtime_accept') {
                $runtimeAcceptedMessages = $count;
            } else {
                $historicalAcceptedMessages = max($historicalAcceptedMessages, $count);
            }

            if (in_array($source, self::FORCE_KNOWN_SOURCES, true) && $count > 0) {
                $isForcedKnown = true;
            }

            $priority = self::SOURCE_PRIORITY[$source] ?? 0;
            if ($count > 0 && $priority > $preferredPriority) {
                $preferredSource = $source;
                $preferredPriority = $priority;
            }
        }

        $acceptedMessages = $historicalAcceptedMessages + $runtimeAcceptedMessages;
        $record['accepted_messages'] = $acceptedMessages;
        $record['source'] = $preferredSource;
        $record['status'] = ($isForcedKnown || $acceptedMessages >= 2)
            ? self::STATUS_KNOWN
            : self::STATUS_CANDIDATE;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function saveRecord(int $chatId, int $userId, array $record): void
    {
        $chatKey = (string)$chatId;
        $userKey = (string)$userId;

        $this->state['known_users'][$chatKey][$userKey] = $record;
        if (!$this->deferSave) {
            $this->saveState();
        }
    }

    private function clearRuntimeAcceptEvidence(): bool
    {
        $changed = false;
        foreach ($this->state['known_users'] as $chatId => &$users) {
            foreach ($users as $userId => &$record) {
                if (isset($record['evidence']['runtime_accept'])) {
                    unset($record['evidence']['runtime_accept']);
                    $changed = true;
                    if (empty($record['evidence'])) {
                        unset($users[$userId]);
                        continue;
                    }
                    $this->recomputeRecord($record);
                }
            }
            unset($record);
            if (empty($users)) {
                unset($this->state['known_users'][$chatId]);
                $changed = true;
            }
        }
        unset($users);

        return $changed;
    }

    private function isRawUpdateStartLine(string $line): bool
    {
        return preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\s+(?:\[FrankenPHP\]\s+)?\{"update_id":/u', $line) === 1;
    }

    private function isTimestampedLogLine(string $line): bool
    {
        return preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\s+/u', $line) === 1;
    }

    private function stripRawUpdatePrefix(string $line): string
    {
        return (string)preg_replace(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\s+(?:\[FrankenPHP\]\s+)?/u',
            '',
            $line
        );
    }

    /**
     * @param string[] $logLines
     */
    private function blockContainsRestrictionMarker(array $logLines): bool
    {
        foreach ($logLines as $line) {
            if (
                str_contains($line, '[NewUserRestriction] Message from user')
                || str_contains($line, '[NewUserRestriction] User ') && str_contains($line, ' is restricted, deleting message')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $logLines
     */
    private function blockContainsCaptchaVerification(array $logLines, int $chatId, int $userId): bool
    {
        $needle = "User {$userId} in chat {$chatId} verified with correct captcha answer";
        foreach ($logLines as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $logLines
     */
    private function blockContainsAcceptedMessageLog(array $logLines, int $chatId, string $chatType): bool
    {
        $pattern = '/\[Webhook\] Message in ' . preg_quote($chatType, '/') . ' ' . preg_quote((string)$chatId, '/') . ' from .+?: /u';
        foreach ($logLines as $line) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function isCommandMessagePayload(array $message): bool
    {
        $text = trim((string)($message['text'] ?? ''));
        if ($text !== '' && str_starts_with($text, '/')) {
            return true;
        }

        foreach ((array)($message['entities'] ?? []) as $entity) {
            if (is_array($entity) && (($entity['type'] ?? '') === 'bot_command')) {
                return true;
            }
        }

        foreach ((array)($message['caption_entities'] ?? []) as $entity) {
            if (is_array($entity) && (($entity['type'] ?? '') === 'bot_command')) {
                return true;
            }
        }

        return false;
    }
}
