<?php

namespace App\Services;

/**
 * Durable per-chat and per-user memory facts store.
 */
class ChatMemoryStore
{
    private const DEFAULT_FACT_LIMIT = 100;
    private const DEFAULT_PARTICIPANT_LIMIT = 40;

    private const ALLOWED_CATEGORIES = [
        'identity',
        'group_purpose',
        'group_rule',
        'language_pref',
        'reply_style_pref',
        'communication_pref',
        'name_pref',
        'location',
        'role',
        'expertise',
        'interest',
        'background',
        'goal',
        'availability',
        'recurring_request',
        'subscription_context',
    ];

    private string $basePath;
    private LoggerService $logger;

    public function __construct(string $dataPath, LoggerService $logger)
    {
        $this->basePath = rtrim($dataPath, '/') . '/chat_memory';
        $this->logger = $logger;

        if (!is_dir($this->basePath)) {
            @mkdir($this->basePath, 0775, true);
        }
    }

    /**
     * @return array{chat_facts: array<int, array<string, mixed>>, user_facts: array<string, array<int, array<string, mixed>>>, user_directory: array<string, array<string, mixed>>, updated_at: int|null}
     */
    public function getMemory(int $chatId): array
    {
        $data = $this->load($chatId);
        $changed = $this->pruneExpiredFacts($data);
        if ($changed) {
            $this->save($chatId, $data);
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFacts(string $scope, int $chatId, ?int $userId = null, ?string $query = null, int $limit = self::DEFAULT_FACT_LIMIT): array
    {
        $data = $this->getMemory($chatId);
        $facts = [];

        if ($scope === 'chat') {
            $facts = $data['chat_facts'] ?? [];
        } elseif ($scope === 'user' && $userId !== null) {
            $facts = $data['user_facts'][(string)$userId] ?? [];
        }

        if ($query !== null && trim($query) !== '') {
            $needle = mb_strtolower(trim($query));
            $facts = array_values(array_filter($facts, static function (array $fact) use ($needle): bool {
                $haystacks = [
                    (string)($fact['category'] ?? ''),
                    (string)($fact['key'] ?? ''),
                    (string)($fact['value'] ?? ''),
                ];

                foreach ($haystacks as $haystack) {
                    if (str_contains(mb_strtolower($haystack), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        usort($facts, static function (array $a, array $b): int {
            $aConfidence = (float)($a['confidence'] ?? 0);
            $bConfidence = (float)($b['confidence'] ?? 0);
            if ($aConfidence === $bConfidence) {
                return (int)($b['updated_at'] ?? 0) <=> (int)($a['updated_at'] ?? 0);
            }

            return $bConfidence <=> $aConfidence;
        });

        return array_slice($facts, 0, max(1, $limit));
    }

    /**
     * @param array<string, mixed> $senderContext
     */
    public function recordUserSnapshot(int $chatId, array $senderContext, ?int $timestamp = null): bool
    {
        $userId = isset($senderContext['user_id']) ? (int)$senderContext['user_id'] : 0;
        if ($userId <= 0 || (bool)($senderContext['is_bot'] ?? false)) {
            return false;
        }

        $data = $this->getMemory($chatId);
        $bucketKey = (string)$userId;
        $now = $timestamp ?? time();
        $existing = is_array($data['user_directory'][$bucketKey] ?? null) ? $data['user_directory'][$bucketKey] : [];

        $usernames = $this->mergeUniqueStrings(
            $existing['usernames'] ?? [],
            isset($senderContext['username']) ? [(string)$senderContext['username']] : []
        );
        $displayNames = $this->mergeUniqueStrings(
            $existing['display_names'] ?? [],
            isset($senderContext['display_name']) ? [(string)$senderContext['display_name']] : []
        );

        $snapshot = [
            'user_id' => $userId,
            'username' => isset($senderContext['username']) && $senderContext['username'] !== null ? (string)$senderContext['username'] : ($existing['username'] ?? null),
            'first_name' => isset($senderContext['first_name']) && $senderContext['first_name'] !== null ? (string)$senderContext['first_name'] : ($existing['first_name'] ?? null),
            'last_name' => isset($senderContext['last_name']) && $senderContext['last_name'] !== null ? (string)$senderContext['last_name'] : ($existing['last_name'] ?? null),
            'display_name' => isset($senderContext['display_name']) && $senderContext['display_name'] !== null ? (string)$senderContext['display_name'] : ($existing['display_name'] ?? null),
            'usernames' => $usernames,
            'display_names' => $displayNames,
            'first_seen_at' => isset($existing['first_seen_at']) ? (int)$existing['first_seen_at'] : $now,
            'last_seen_at' => $now,
        ];

        $changed = !isset($data['user_directory'][$bucketKey]) || json_encode($data['user_directory'][$bucketKey], JSON_UNESCAPED_UNICODE) !== json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        if (!$changed) {
            return false;
        }

        $data['user_directory'][$bucketKey] = $snapshot;
        $data['updated_at'] = time();
        $this->save($chatId, $data);
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchUsers(int $chatId, string $query, int $limit = 5): array
    {
        $needle = $this->normalizeLookupString($query);
        if ($needle === '') {
            return [];
        }

        $data = $this->getMemory($chatId);
        $results = [];

        foreach ($data['user_directory'] ?? [] as $userId => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $score = $this->scoreUserProfileMatch($profile, $needle);
            if ($score <= 0) {
                continue;
            }

            $profile['match_score'] = $score;
            $results[] = $profile;
        }

        usort($results, static function (array $a, array $b): int {
            return ((int)($b['match_score'] ?? 0)) <=> ((int)($a['match_score'] ?? 0));
        });

        return array_slice($results, 0, max(1, $limit));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUserProfiles(int $chatId, ?int $userId = null, ?string $query = null, int $limit = self::DEFAULT_FACT_LIMIT): array
    {
        $data = $this->getMemory($chatId);
        $profiles = [];

        if ($userId !== null && isset($data['user_directory'][(string)$userId])) {
            $profiles[] = $this->buildUserProfilePayload($chatId, (int)$userId, $data['user_directory'][(string)$userId], $limit);
            return $profiles;
        }

        if ($query !== null && trim($query) !== '') {
            foreach ($this->searchUsers($chatId, $query, $limit) as $profile) {
                $profiles[] = $this->buildUserProfilePayload($chatId, (int)($profile['user_id'] ?? 0), $profile, $limit);
            }
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $fact
     */
    public function setFact(int $chatId, string $scope, array $fact, ?int $userId = null): bool
    {
        $fact = $this->normalizeFact($fact);
        if ($fact === null) {
            return false;
        }

        $data = $this->getMemory($chatId);
        $updated = false;

        if ($scope === 'chat') {
            $data['chat_facts'] = $this->upsertFactList($data['chat_facts'] ?? [], $fact, $updated);
        } elseif ($scope === 'user' && $userId !== null) {
            $bucketKey = (string)$userId;
            $data['user_facts'][$bucketKey] = $this->upsertFactList($data['user_facts'][$bucketKey] ?? [], $fact, $updated);
        } else {
            return false;
        }

        if ($updated) {
            $data['updated_at'] = time();
            $this->save($chatId, $data);
        }

        return $updated;
    }

    /**
     * @return array{deleted: int, deleted_facts: array<int, array<string, mixed>>}
     */
    public function deleteFacts(
        string $scope,
        int $chatId,
        ?int $userId = null,
        ?string $query = null,
        ?string $category = null,
        ?string $key = null,
        int $limit = 10
    ): array {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['chat', 'user'], true)) {
            return ['deleted' => 0, 'deleted_facts' => []];
        }

        if ($scope === 'user' && $userId === null) {
            return ['deleted' => 0, 'deleted_facts' => []];
        }

        $query = $query !== null ? trim($query) : '';
        $category = $category !== null ? strtolower(trim($category)) : '';
        $key = $key !== null ? strtolower(trim($key)) : '';
        if (($query === '' && $key === '') || ($category === '' && $key !== '')) {
            return ['deleted' => 0, 'deleted_facts' => []];
        }

        $data = $this->getMemory($chatId);
        $deletedFacts = [];
        $limit = max(1, $limit);

        if ($scope === 'chat') {
            $data['chat_facts'] = $this->filterDeletedFacts(
                $data['chat_facts'] ?? [],
                $query,
                $category,
                $key,
                $limit,
                $deletedFacts
            );
        } else {
            $bucketKey = (string)$userId;
            $data['user_facts'][$bucketKey] = $this->filterDeletedFacts(
                $data['user_facts'][$bucketKey] ?? [],
                $query,
                $category,
                $key,
                $limit,
                $deletedFacts
            );

            if (($data['user_facts'][$bucketKey] ?? []) === []) {
                unset($data['user_facts'][$bucketKey]);
            }
        }

        if ($deletedFacts !== []) {
            $data['updated_at'] = time();
            $this->save($chatId, $data);
        }

        return [
            'deleted' => count($deletedFacts),
            'deleted_facts' => $deletedFacts,
        ];
    }

    public function buildPromptContext(
        int $chatId,
        ?int $currentUserId = null,
        ?int $relatedUserId = null,
        int $limit = self::DEFAULT_FACT_LIMIT,
        bool $includeParticipantProfiles = true,
        int $participantLimit = self::DEFAULT_PARTICIPANT_LIMIT
    ): string {
        $sections = [];

        $chatFacts = $this->getFacts('chat', $chatId, null, null, $limit);
        if ($chatFacts !== []) {
            $lines = array_map(
                static fn (array $fact): string => '- ' . ($fact['category'] ?? 'fact') . '/' . ($fact['key'] ?? 'value') . ': ' . ($fact['value'] ?? ''),
                $chatFacts
            );
            $sections[] = "Shared chat memory:\n" . implode("\n", $lines);
        }

        if ($currentUserId !== null) {
            $profileContext = $this->buildSingleUserPromptContext($chatId, $currentUserId, 'Memory about the current user', $limit);
            if ($profileContext !== '') {
                $sections[] = $profileContext;
            }
        }

        if ($relatedUserId !== null && $relatedUserId !== $currentUserId) {
            $profileContext = $this->buildSingleUserPromptContext($chatId, $relatedUserId, 'Memory about the replied/related user', $limit);
            if ($profileContext !== '') {
                $sections[] = $profileContext;
            }
        }

        if ($includeParticipantProfiles) {
            $profileContext = $this->buildOtherParticipantsPromptContext(
                $chatId,
                array_values(array_filter([$currentUserId, $relatedUserId], static fn ($value): bool => $value !== null)),
                $limit,
                $participantLimit
            );
            if ($profileContext !== '') {
                $sections[] = $profileContext;
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return string[]
     */
    public static function getAllowedCategories(): array
    {
        return self::ALLOWED_CATEGORIES;
    }

    /**
     * @return array{chat_facts: array<int, array<string, mixed>>, user_facts: array<string, array<int, array<string, mixed>>>, user_directory: array<string, array<string, mixed>>, updated_at: int|null}
     */
    private function load(int $chatId): array
    {
        $file = $this->getFilePath($chatId);
        if (!is_file($file)) {
            return [
                'chat_facts' => [],
                'user_facts' => [],
                'user_directory' => [],
                'updated_at' => null,
            ];
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            return [
                'chat_facts' => [],
                'user_facts' => [],
                'user_directory' => [],
                'updated_at' => null,
            ];
        }

        return [
            'chat_facts' => array_values($data['chat_facts'] ?? []),
            'user_facts' => is_array($data['user_facts'] ?? null) ? $data['user_facts'] : [],
            'user_directory' => is_array($data['user_directory'] ?? null) ? $data['user_directory'] : [],
            'updated_at' => isset($data['updated_at']) ? (int)$data['updated_at'] : null,
        ];
    }

    /**
     * @param array{chat_facts: array<int, array<string, mixed>>, user_facts: array<string, array<int, array<string, mixed>>>, user_directory: array<string, array<string, mixed>>, updated_at: int|null} $data
     */
    private function save(int $chatId, array $data): void
    {
        @file_put_contents(
            $this->getFilePath($chatId),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function getFilePath(int $chatId): string
    {
        return $this->basePath . '/' . $chatId . '.json';
    }

    /**
     * @param array<string, mixed> $fact
     * @return array<string, mixed>|null
     */
    private function normalizeFact(array $fact): ?array
    {
        $category = trim((string)($fact['category'] ?? ''));
        $key = trim((string)($fact['key'] ?? ''));
        $value = trim((string)($fact['value'] ?? ''));
        $sensitivity = strtolower(trim((string)($fact['sensitivity'] ?? 'low')));
        $confidence = (float)($fact['confidence'] ?? 0);

        if ($category === '' || $key === '' || $value === '') {
            return null;
        }

        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            return null;
        }

        [$category, $key, $value] = $this->canonicalizeFact($category, $key, $value);

        if (!in_array($sensitivity, ['low', 'medium', 'high'], true)) {
            $sensitivity = 'low';
        }

        $expiresAt = null;
        if (isset($fact['expires_at']) && $fact['expires_at'] !== null) {
            $expiresAt = (int)$fact['expires_at'];
        } elseif (isset($fact['ttl_days']) && is_numeric($fact['ttl_days']) && (int)$fact['ttl_days'] > 0) {
            $expiresAt = time() + ((int)$fact['ttl_days'] * 86400);
        }

        return [
            'category' => $category,
            'key' => $key,
            'value' => $value,
            'confidence' => max(0.0, min(1.0, $confidence > 1 ? $confidence / 100 : $confidence)),
            'source_message_id' => isset($fact['source_message_id']) && $fact['source_message_id'] !== null ? (int)$fact['source_message_id'] : null,
            'source_user_id' => isset($fact['source_user_id']) && $fact['source_user_id'] !== null ? (int)$fact['source_user_id'] : null,
            'updated_at' => isset($fact['updated_at']) ? (int)$fact['updated_at'] : time(),
            'expires_at' => $expiresAt,
            'sensitivity' => $sensitivity,
        ];
    }

    /**
     * @return array{string,string,string}
     */
    private function canonicalizeFact(string $category, string $key, string $value): array
    {
        $category = strtolower(trim($category));
        $key = strtolower(trim($key));

        return match ($category) {
            'identity' => [$category, $key !== '' ? $key : 'identity', $value],
            'name_pref' => [$category, in_array($key, ['name', 'nickname', 'preferred_name'], true) ? $key : 'name', $value],
            'language_pref' => [$category, 'language', mb_strtolower($value)],
            'reply_style_pref' => [$category, 'reply_style', mb_strtolower($value)],
            'communication_pref' => [$category, $key !== '' ? $key : 'communication', $value],
            'location' => [$category, in_array($key, ['city', 'residence', 'location'], true) ? $key : 'residence', $value],
            'role' => [$category, 'role', $value],
            'expertise' => [$category, $key !== '' ? $key : 'expertise', $value],
            'interest' => [$category, $this->slugifyKey($key !== '' ? $key : $value, 'interest'), $value],
            'background' => [$category, $key !== '' ? $key : 'background', $value],
            'goal' => [$category, $key !== '' ? $key : 'goal', $value],
            'availability' => [$category, $key !== '' ? $key : 'availability', $value],
            'group_purpose' => [$category, $key !== '' ? $key : 'purpose', $value],
            'group_rule' => [$category, $this->slugifyKey($key !== '' ? $key : $value, 'rule'), $value],
            'recurring_request' => [$category, $this->slugifyKey($key !== '' ? $key : $value, 'habit'), $value],
            'subscription_context' => [$category, $key !== '' ? $key : 'subscription', $value],
            default => [$category, $key, $value],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @param array<string, mixed> $fact
     * @return array<int, array<string, mixed>>
     */
    private function upsertFactList(array $facts, array $fact, bool &$updated): array
    {
        $updated = true;

        foreach ($facts as $index => $existing) {
            if (($existing['category'] ?? null) === $fact['category'] && ($existing['key'] ?? null) === $fact['key']) {
                $facts[$index] = $fact;
                return array_values($facts);
            }
        }

        $facts[] = $fact;
        return array_values($facts);
    }

    /**
     * @param array{chat_facts: array<int, array<string, mixed>>, user_facts: array<string, array<int, array<string, mixed>>>, user_directory: array<string, array<string, mixed>>, updated_at: int|null} $data
     */
    private function pruneExpiredFacts(array &$data): bool
    {
        $now = time();
        $changed = false;

        $data['chat_facts'] = array_values(array_filter($data['chat_facts'] ?? [], static function (array $fact) use ($now, &$changed): bool {
            $expiresAt = isset($fact['expires_at']) ? (int)$fact['expires_at'] : null;
            $keep = $expiresAt === null || $expiresAt > $now;
            if (!$keep) {
                $changed = true;
            }
            return $keep;
        }));

        foreach ($data['user_facts'] ?? [] as $userId => $facts) {
            $filtered = array_values(array_filter($facts, static function (array $fact) use ($now, &$changed): bool {
                $expiresAt = isset($fact['expires_at']) ? (int)$fact['expires_at'] : null;
                $keep = $expiresAt === null || $expiresAt > $now;
                if (!$keep) {
                    $changed = true;
                }
                return $keep;
            }));

            if ($filtered === []) {
                unset($data['user_facts'][$userId]);
                $changed = true;
                continue;
            }

            $data['user_facts'][$userId] = $filtered;
        }

        return $changed;
    }

    /**
     * @param array<int, mixed> $existing
     * @param array<int, string> $add
     * @return array<int, string>
     */
    private function mergeUniqueStrings(array $existing, array $add): array
    {
        $values = [];
        foreach (array_merge($existing, $add) as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $values[$value] = true;
        }

        return array_keys($values);
    }

    private function normalizeLookupString(string $value): string
    {
        return mb_strtolower(ltrim(trim($value), '@'));
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function scoreUserProfileMatch(array $profile, string $needle): int
    {
        $score = 0;
        $fields = [];

        foreach (['display_name', 'first_name', 'last_name', 'username'] as $field) {
            if (!empty($profile[$field])) {
                $fields[] = (string)$profile[$field];
            }
        }

        foreach (($profile['display_names'] ?? []) as $displayName) {
            $fields[] = (string)$displayName;
        }

        foreach (($profile['usernames'] ?? []) as $username) {
            $fields[] = (string)$username;
        }

        foreach ($fields as $field) {
            $normalized = $this->normalizeLookupString($field);
            if ($normalized === '') {
                continue;
            }

            if ($normalized === $needle) {
                $score += 100;
                continue;
            }

            if (str_contains($normalized, $needle) || str_contains($needle, $normalized)) {
                $score += 40;
            }
        }

        return $score;
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @param array<int, array<string, mixed>> $deletedFacts
     * @return array<int, array<string, mixed>>
     */
    private function filterDeletedFacts(
        array $facts,
        string $query,
        string $category,
        string $key,
        int $limit,
        array &$deletedFacts
    ): array {
        $kept = [];

        foreach ($facts as $fact) {
            if (
                is_array($fact)
                && count($deletedFacts) < $limit
                && $this->factMatchesDeleteRequest($fact, $query, $category, $key)
            ) {
                $deletedFacts[] = $fact;
                continue;
            }

            $kept[] = $fact;
        }

        return array_values($kept);
    }

    /**
     * @param array<string, mixed> $fact
     */
    private function factMatchesDeleteRequest(array $fact, string $query, string $category, string $key): bool
    {
        $factCategory = strtolower(trim((string)($fact['category'] ?? '')));
        $factKey = strtolower(trim((string)($fact['key'] ?? '')));

        if ($category !== '' && $factCategory !== $category) {
            return false;
        }

        if ($key !== '') {
            if ($factKey === $key) {
                return true;
            }

            if ($this->countTokenMatches($this->buildDeleteQueryTokens($key), $this->buildDeleteHaystack($fact)) >= 2) {
                return true;
            }
        }

        if ($query === '') {
            return false;
        }

        $needle = mb_strtolower($query);
        $haystack = $this->buildDeleteHaystack($fact);

        if ($needle !== '' && str_contains($haystack, $needle)) {
            return true;
        }

        $tokens = $this->buildDeleteQueryTokens($query);
        if ($tokens === []) {
            return false;
        }

        $matches = $this->countTokenMatches($tokens, $haystack);
        $threshold = count($tokens) <= 2 ? count($tokens) : min(4, max(2, (int)ceil(count($tokens) * 0.35)));

        return $matches >= $threshold;
    }

    /**
     * @param array<string, mixed> $fact
     */
    private function buildDeleteHaystack(array $fact): string
    {
        return mb_strtolower(trim(
            (string)($fact['category'] ?? '') . ' ' .
            (string)($fact['key'] ?? '') . ' ' .
            (string)($fact['value'] ?? '')
        ));
    }

    /**
     * @return array<int, string>
     */
    private function buildDeleteQueryTokens(string $query): array
    {
        $rawTokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
        $tokens = [];
        foreach ($rawTokens as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 3) {
                continue;
            }

            foreach ($this->expandDeleteToken($token) as $expanded) {
                $tokens[$expanded] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @return array<int, string>
     */
    private function expandDeleteToken(string $token): array
    {
        $map = [
            'объединил' => ['объединил', 'consolidated', 'merged', 'unified'],
            'объединили' => ['объединили', 'consolidated', 'merged', 'unified'],
            'объединяли' => ['объединяли', 'consolidated', 'merged', 'unified'],
            'объединение' => ['объединение', 'consolidation', 'merger', 'unification'],
            'консолидация' => ['консолидация', 'consolidation'],
            'проекта' => ['проекта', 'project', 'projects'],
            'проектов' => ['проектов', 'project', 'projects'],
            'проекты' => ['проекты', 'project', 'projects'],
            'шаблон' => ['шаблон', 'template'],
            'шаблона' => ['шаблона', 'template'],
            'унифицированного' => ['унифицированного', 'unified'],
            'унифицированный' => ['унифицированный', 'unified'],
            'база' => ['база', 'database'],
            'базу' => ['базу', 'database'],
            'базы' => ['базы', 'database'],
            'удалил' => ['удалил', 'deleted', 'removed'],
            'удалили' => ['удалили', 'deleted', 'removed'],
            'удалял' => ['удалял', 'deleted', 'removed'],
            'удаление' => ['удаление', 'deletion', 'delete'],
            'цитата' => ['цитата', 'quote'],
            'цитаты' => ['цитаты', 'quote'],
            'новостей' => ['новостей', 'news'],
            'новость' => ['новость', 'news'],
        ];

        return $map[$token] ?? [$token];
    }

    /**
     * @param array<int, string> $tokens
     */
    private function countTokenMatches(array $tokens, string $haystack): int
    {
        $matches = 0;
        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($haystack, $token)) {
                $matches++;
            }
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function buildUserProfilePayload(int $chatId, int $userId, array $profile, int $limit): array
    {
        return [
            'user_id' => $userId,
            'profile' => [
                'display_name' => $profile['display_name'] ?? null,
                'first_name' => $profile['first_name'] ?? null,
                'last_name' => $profile['last_name'] ?? null,
                'username' => $profile['username'] ?? null,
                'usernames' => $profile['usernames'] ?? [],
                'display_names' => $profile['display_names'] ?? [],
                'first_seen_at' => $profile['first_seen_at'] ?? null,
                'last_seen_at' => $profile['last_seen_at'] ?? null,
            ],
            'facts' => $this->getFacts('user', $chatId, $userId, null, $limit),
        ];
    }

    private function buildSingleUserPromptContext(int $chatId, int $userId, string $header, int $limit): string
    {
        $profiles = $this->getUserProfiles($chatId, $userId, null, $limit);
        if ($profiles === []) {
            return '';
        }

        $profile = $profiles[0];
        $meta = $profile['profile'] ?? [];
        $facts = $profile['facts'] ?? [];
        if (!is_array($facts) || $facts === []) {
            return '';
        }

        $displayName = trim((string)($meta['display_name'] ?? $meta['first_name'] ?? 'user'));
        $lines = ["{$header} ({$displayName}):"];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $lines[] = '- ' . ($fact['category'] ?? 'fact') . '/' . ($fact['key'] ?? 'value') . ': ' . ($fact['value'] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, int|null> $excludedUserIds
     */
    private function buildOtherParticipantsPromptContext(int $chatId, array $excludedUserIds, int $limit, int $participantLimit): string
    {
        $memory = $this->getMemory($chatId);
        $excluded = [];
        foreach ($excludedUserIds as $userId) {
            if ($userId !== null) {
                $excluded[(string)$userId] = true;
            }
        }

        $profiles = [];
        foreach (($memory['user_facts'] ?? []) as $userId => $facts) {
            if (isset($excluded[(string)$userId]) || !is_array($facts) || $facts === []) {
                continue;
            }

            $directory = is_array($memory['user_directory'][(string)$userId] ?? null)
                ? $memory['user_directory'][(string)$userId]
                : [];

            $profiles[] = [
                'user_id' => (int)$userId,
                'display_name' => trim((string)($directory['display_name'] ?? $directory['first_name'] ?? $userId)),
                'fact_count' => count($facts),
                'last_seen_at' => (int)($directory['last_seen_at'] ?? 0),
            ];
        }

        if ($profiles === []) {
            return '';
        }

        usort($profiles, static function (array $a, array $b): int {
            if (($a['fact_count'] ?? 0) === ($b['fact_count'] ?? 0)) {
                return ((int)($b['last_seen_at'] ?? 0)) <=> ((int)($a['last_seen_at'] ?? 0));
            }

            return ((int)($b['fact_count'] ?? 0)) <=> ((int)($a['fact_count'] ?? 0));
        });

        $lines = ['Memory about other group participants (full profiles for context; do not dump unless asked):'];
        foreach (array_slice($profiles, 0, max(1, $participantLimit)) as $profile) {
            $facts = $this->getFacts('user', $chatId, (int)$profile['user_id'], null, $limit);
            if ($facts === []) {
                continue;
            }

            $lines[] = 'Participant ' . ($profile['display_name'] ?? $profile['user_id']) . ' [user_id=' . (int)$profile['user_id'] . ']:';
            foreach ($facts as $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $lines[] = '- ' . ($fact['category'] ?? 'fact') . '/' . ($fact['key'] ?? 'value') . ': ' . ($fact['value'] ?? '');
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private function slugifyKey(string $value, string $prefix): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '_', $value) ?? $value;
        $value = trim($value, '_');

        if ($value === $prefix || str_starts_with($value, $prefix . '_')) {
            return $value;
        }

        return $value !== '' ? $prefix . '_' . $value : $prefix;
    }
}
