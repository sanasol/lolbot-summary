<?php

namespace App\Services;

use App\Services\AI\HttpClientTrait;

/**
 * Extracts durable low-sensitivity facts from group chat messages.
 */
class MemoryExtractor
{
    use HttpClientTrait;

    private const CONFIDENCE_THRESHOLD = 0.8;

    private array $config;
    private LoggerService $logger;
    private ChatMemoryStore $chatMemoryStore;

    public function __construct(array $config, LoggerService $logger, ChatMemoryStore $chatMemoryStore)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->chatMemoryStore = $chatMemoryStore;
    }

    /**
     * @param array<string, mixed> $senderContext
     */
    public function observeUser(int $chatId, array $senderContext, ?int $timestamp = null): void
    {
        $this->chatMemoryStore->recordUserSnapshot($chatId, $senderContext, $timestamp);
    }

    public function maybeExtractFromMessage(int $chatId, int $userId, string $username, string $messageText, int $messageId): int
    {
        $messageText = trim($messageText);
        if ($messageText === '' || $this->containsSensitiveContent($messageText)) {
            return 0;
        }

        $stored = $this->storeDeterministicFacts($chatId, $userId, $messageText, $messageId);
        if ($stored > 0 && $this->isSimpleDeterministicStatement($messageText)) {
            return $stored;
        }

        if (!$this->shouldAnalyze($messageText)) {
            return $stored;
        }

        $params = [
            'model' => $this->config['openrouter_chat_model'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->buildSystemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => "User: {$username}\nMessage: {$messageText}",
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'memory_facts',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'facts' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'scope' => ['type' => 'string', 'enum' => ['chat', 'user', 'none']],
                                        'category' => ['type' => 'string', 'enum' => ChatMemoryStore::getAllowedCategories()],
                                        'key' => ['type' => 'string'],
                                        'value' => ['type' => 'string'],
                                        'confidence' => ['type' => 'number'],
                                        'stability' => ['type' => 'string', 'enum' => ['transient', 'medium', 'durable']],
                                        'ttl_days' => ['type' => ['integer', 'null']],
                                        'sensitivity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                                    ],
                                    'required' => ['scope', 'category', 'key', 'value', 'confidence', 'stability', 'ttl_days', 'sensitivity'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['facts'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 500,
        ];

        $body = $this->makeOpenRouterRequest($this->config, $params, 'MemoryExtractor', 20);
        if (!isset($body['choices'][0]['message']['content'])) {
            return 0;
        }

        $payload = json_decode((string)$body['choices'][0]['message']['content'], true);
        if (!is_array($payload) || !is_array($payload['facts'] ?? null)) {
            return 0;
        }

        foreach ($payload['facts'] as $fact) {
            if (!is_array($fact)) {
                continue;
            }

            $scope = strtolower(trim((string)($fact['scope'] ?? 'none')));
            if (!in_array($scope, ['chat', 'user'], true)) {
                continue;
            }

            $confidence = (float)($fact['confidence'] ?? 0);
            if ($confidence < self::CONFIDENCE_THRESHOLD) {
                continue;
            }

            $stability = strtolower(trim((string)($fact['stability'] ?? 'durable')));
            if ($stability === 'transient') {
                continue;
            }

            if (($fact['sensitivity'] ?? 'low') !== 'low') {
                continue;
            }

            if ($this->containsSensitiveContent((string)($fact['value'] ?? ''))) {
                continue;
            }

            $saved = $this->chatMemoryStore->setFact(
                $chatId,
                $scope,
                [
                    'category' => $fact['category'] ?? null,
                    'key' => $fact['key'] ?? null,
                    'value' => $fact['value'] ?? null,
                    'confidence' => $confidence,
                    'ttl_days' => $fact['ttl_days'] ?? null,
                    'source_message_id' => $messageId,
                    'source_user_id' => $userId,
                    'updated_at' => time(),
                    'sensitivity' => 'low',
                ],
                $scope === 'user' ? $userId : null
            );

            if ($saved) {
                $stored++;
            }
        }

        if ($stored > 0) {
            $this->logger->logWebhook("Memory extractor stored {$stored} fact(s) for chat {$chatId}, user {$userId}");
        }

        return $stored;
    }

    /**
     * @param array<int, array{message_id:int|null, ts:int|null, text:string}> $messages
     */
    public function backfillUserProfileFromCorpus(int $chatId, int $userId, string $displayName, array $messages): int
    {
        return $this->extractFactsFromHistoricalCorpus(
            $chatId,
            'user',
            $displayName,
            $messages,
            $userId
        );
    }

    /**
     * @param array<int, array{message_id:int|null, ts:int|null, text:string}> $messages
     */
    public function backfillChatFactsFromCorpus(int $chatId, string $chatTitle, array $messages): int
    {
        return $this->extractFactsFromHistoricalCorpus(
            $chatId,
            'chat',
            $chatTitle,
            $messages,
            null
        );
    }

    private function shouldAnalyze(string $messageText): bool
    {
        $trimmed = trim($messageText);
        $normalized = mb_strtolower($trimmed);
        if (mb_strlen($normalized) < 4 || mb_strlen($normalized) > 400) {
            return false;
        }

        if (!$this->containsAlphabeticCharacters($normalized)) {
            return false;
        }

        $tokens = $this->tokenize($normalized);
        $broadSignals = [
            'i', "i'm", 'im', 'my', 'me', 'we', 'our',
            'я', 'мне', 'меня', 'мой', 'моя', 'моё', 'мое', 'мы', 'наш', 'наша',
            'this', 'group', 'chat', 'этот', 'эта', 'группа', 'чат',
            'remember', 'remind', 'call', 'prefer', 'speak', 'name',
            'запомни', 'напомни', 'зови', 'предпочитаю', 'говорю', 'зовут',
            'love', 'like', 'live', 'from', 'work', 'working', 'use', 'using',
            'люблю', 'нравится', 'живу', 'из', 'работаю', 'используем', 'занимаюсь',
        ];

        foreach ($tokens as $token) {
            if (in_array($token, $broadSignals, true)) {
                return true;
            }
        }

        return str_contains($normalized, 'this group')
            || str_contains($normalized, 'this chat')
            || str_contains($normalized, 'этот чат')
            || str_contains($normalized, 'эта группа');
    }

    private function containsSensitiveContent(string $value): bool
    {
        $normalized = mb_strtolower($value);

        if (preg_match('/@\w{3,}|https?:\/\/|www\.|t\.me\//ui', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(token|password|secret|api key|email|phone|wallet|iban|card)\b/ui', $normalized) === 1) {
            return true;
        }

        if (preg_match('/[$€₽]\s?\d+|\b\d+\s?(usd|eur|rub|per week|per day)\b/ui', $normalized) === 1) {
            return true;
        }

        return false;
    }

    private function buildSystemPrompt(): string
    {
        return 'Extract only durable, useful, low-sensitivity memory facts from the message. ' .
            'Return facts only when they are likely useful in future conversations and form a quality long-term profile of the speaker or the group. ' .
            'Allowed categories: ' . implode(', ', ChatMemoryStore::getAllowedCategories()) . '. ' .
            'Short self-identification messages like "I am Sasha" or "Я Санясол" may be treated as a name preference if they clearly look like a person name. ' .
            'Short direct self-facts like "I love hookah" / "я люблю кальян" should be treated as user interest when they are stable enough. ' .
            'Short coarse location statements like "I live in Belgrade" / "я живу в Белграде" may be treated as location with a city-level value only. ' .
            'You may also extract durable facts about role, expertise, background, goals, availability, communication style, language preference, group purpose, and group rules. ' .
            'Only extract self-reported speaker facts or stable group facts from the current message. Avoid hearsay about other people unless the message is an explicit durable group rule. ' .
            'Use stability=durable for long-term profile facts, stability=medium for useful but somewhat softer facts, and stability=transient for anything that should not be stored. ' .
            'Do not store exact addresses, apartment details, coordinates, or other precise location data. ' .
            'Never store contact handles, links, financial promises, passwords, tokens, or transient emotions. ' .
            'Use scope=user for stable facts about the speaker, scope=chat for stable facts about the group, scope=none when nothing should be stored. ' .
            'Keep keys short and machine-friendly. ' .
            'Examples: ' .
            '[scope=user, category=role, key=role, value="backend developer"]; ' .
            '[scope=user, category=expertise, key=expertise_php, value="strong with PHP"]; ' .
            '[scope=user, category=interest, key=interest_hookah, value="кальян"]; ' .
            '[scope=chat, category=group_purpose, key=purpose, value="chat for Statbate ops only"].';
    }

    /**
     * @param array<int, array{message_id:int|null, ts:int|null, text:string}> $messages
     */
    private function extractFactsFromHistoricalCorpus(
        int $chatId,
        string $scope,
        string $subjectLabel,
        array $messages,
        ?int $userId
    ): int {
        $preparedMessages = $this->prepareHistoricalCorpus($messages);
        if ($preparedMessages === []) {
            return 0;
        }

        $allowedScopes = $scope === 'chat' ? ['chat', 'none'] : ['user', 'none'];
        $corpusLines = [];
        $latestMessageId = null;

        foreach ($preparedMessages as $message) {
            $messageId = $message['message_id'] ?? null;
            if ($latestMessageId === null && $messageId !== null) {
                $latestMessageId = (int)$messageId;
            }

            $prefix = $messageId !== null ? '[msg:' . (int)$messageId . '] ' : '- ';
            $corpusLines[] = $prefix . $message['text'];
        }

        $params = [
            'model' => $this->config['openrouter_chat_model'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->buildHistoricalCorpusPrompt($scope),
                ],
                [
                    'role' => 'user',
                    'content' => "Subject: {$subjectLabel}\nScope: {$scope}\nHistorical corpus:\n" . implode("\n", $corpusLines),
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'historical_memory_facts',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'facts' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'scope' => ['type' => 'string', 'enum' => $allowedScopes],
                                        'category' => ['type' => 'string', 'enum' => ChatMemoryStore::getAllowedCategories()],
                                        'key' => ['type' => 'string'],
                                        'value' => ['type' => 'string'],
                                        'confidence' => ['type' => 'number'],
                                        'stability' => ['type' => 'string', 'enum' => ['transient', 'medium', 'durable']],
                                        'ttl_days' => ['type' => ['integer', 'null']],
                                        'sensitivity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                                    ],
                                    'required' => ['scope', 'category', 'key', 'value', 'confidence', 'stability', 'ttl_days', 'sensitivity'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['facts'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 800,
        ];

        $body = $this->makeOpenRouterRequest($this->config, $params, 'MemoryBackfill', 30);
        if (!isset($body['choices'][0]['message']['content'])) {
            return 0;
        }

        $payload = json_decode((string)$body['choices'][0]['message']['content'], true);
        if (!is_array($payload) || !is_array($payload['facts'] ?? null)) {
            return 0;
        }

        $stored = 0;
        foreach ($payload['facts'] as $fact) {
            if (!is_array($fact)) {
                continue;
            }

            $factScope = strtolower(trim((string)($fact['scope'] ?? 'none')));
            if ($factScope !== $scope) {
                continue;
            }

            $confidence = (float)($fact['confidence'] ?? 0);
            if ($confidence < self::CONFIDENCE_THRESHOLD) {
                continue;
            }

            $stability = strtolower(trim((string)($fact['stability'] ?? 'durable')));
            if ($stability === 'transient') {
                continue;
            }

            if (($fact['sensitivity'] ?? 'low') !== 'low') {
                continue;
            }

            if ($this->containsSensitiveContent((string)($fact['value'] ?? ''))) {
                continue;
            }

            $saved = $this->chatMemoryStore->setFact(
                $chatId,
                $scope,
                [
                    'category' => $fact['category'] ?? null,
                    'key' => $fact['key'] ?? null,
                    'value' => $fact['value'] ?? null,
                    'confidence' => $confidence,
                    'ttl_days' => $fact['ttl_days'] ?? null,
                    'source_message_id' => $latestMessageId,
                    'source_user_id' => $userId,
                    'updated_at' => time(),
                    'sensitivity' => 'low',
                ],
                $scope === 'user' ? $userId : null
            );

            if ($saved) {
                $stored++;
            }
        }

        if ($stored > 0) {
            $suffix = $scope === 'user' ? ", user {$userId}" : '';
            $this->logger->logWebhook("Historical memory backfill stored {$stored} {$scope} fact(s) for chat {$chatId}{$suffix}");
        }

        return $stored;
    }

    /**
     * @param array<int, array{message_id:int|null, ts:int|null, text:string}> $messages
     * @return array<int, array{message_id:int|null, ts:int|null, text:string}>
     */
    private function prepareHistoricalCorpus(array $messages): array
    {
        $prepared = [];
        $seen = [];
        $totalChars = 0;

        foreach ($messages as $message) {
            $text = trim((string)($message['text'] ?? ''));
            if ($text === '' || $this->containsSensitiveContent($text)) {
                continue;
            }

            $normalized = $this->normalizeHistoricalCorpusText($text);
            if ($normalized === '') {
                continue;
            }

            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $length = mb_strlen($text);
            if ($length > 320) {
                $text = mb_substr($text, 0, 320) . '...';
                $length = mb_strlen($text);
            }

            if ($totalChars + $length > 5500 && count($prepared) >= 12) {
                break;
            }

            $prepared[] = [
                'message_id' => isset($message['message_id']) ? (int)$message['message_id'] : null,
                'ts' => isset($message['ts']) ? (int)$message['ts'] : null,
                'text' => $text,
            ];
            $totalChars += $length;

            if (count($prepared) >= 24) {
                break;
            }
        }

        return $prepared;
    }

    private function buildHistoricalCorpusPrompt(string $scope): string
    {
        $subjectInstruction = $scope === 'chat'
            ? 'You are backfilling durable shared memory about one Telegram group from a historical corpus of messages.'
            : 'You are backfilling a durable participant profile from a historical corpus of messages written by the same Telegram user.';

        $scopeInstruction = $scope === 'chat'
            ? 'Store only stable group facts such as purpose, rules, language, or recurring shared context.'
            : 'Store only stable low-sensitivity facts about the speaker such as name preference, language preference, location at city level, role, expertise, interests, background, goals, or availability.';

        return $subjectInstruction . ' ' .
            $scopeInstruction . ' ' .
            'Allowed categories: ' . implode(', ', ChatMemoryStore::getAllowedCategories()) . '. ' .
            'Extract facts only when they are directly self-reported, repeated strongly enough, or clearly evidenced by multiple messages. ' .
            'Do not infer from one-off jokes, reposts, links, memes, current events, or opinions about third parties. ' .
            'Never store contact handles, links, exact addresses, finance amounts, credentials, passwords, tokens, or sensitive personal data. ' .
            'If there is not enough evidence, return no facts. ' .
            'Use stability=durable for long-term profile facts, medium for softer but still useful facts, transient for anything that should not be stored. ' .
            'Only return low-sensitivity facts. ' .
            'Keep keys short and machine-friendly.';
    }

    private function normalizeHistoricalCorpusText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function storeDeterministicFacts(int $chatId, int $userId, string $messageText, int $messageId): int
    {
        $facts = [];

        $interest = $this->extractInterestFact($messageText);
        if ($interest !== null) {
            $facts[] = [
                'category' => 'interest',
                'key' => $this->makeInterestKey($interest),
                'value' => $interest,
                'confidence' => 0.92,
                'source_message_id' => $messageId,
                'source_user_id' => $userId,
                'updated_at' => time(),
                'sensitivity' => 'low',
            ];
        }

        $location = $this->extractLocationFact($messageText);
        if ($location !== null) {
            $facts[] = [
                'category' => 'location',
                'key' => 'residence',
                'value' => $this->buildLocationMemoryValue($location, $messageText),
                'confidence' => 0.9,
                'source_message_id' => $messageId,
                'source_user_id' => $userId,
                'updated_at' => time(),
                'sensitivity' => 'low',
            ];
        }

        $stored = 0;
        foreach ($facts as $fact) {
            $saved = $this->chatMemoryStore->setFact($chatId, 'user', $fact, $userId);
            if ($saved) {
                $stored++;
            }
        }

        return $stored;
    }

    private function isSimpleDeterministicStatement(string $messageText): bool
    {
        return $this->extractInterestFact($messageText) !== null
            || $this->extractLocationFact($messageText) !== null;
    }

    private function extractInterestFact(string $messageText): ?string
    {
        $normalized = $this->normalizeStatement($messageText);
        $prefixes = [
            'я люблю ',
            'люблю ',
            'мне нравится ',
            'i love ',
            'i like ',
            "i'm into ",
            'im into ',
            'i am into ',
        ];

        foreach ($prefixes as $prefix) {
            if (!str_starts_with($normalized, $prefix)) {
                continue;
            }

            $value = trim(mb_substr($normalized, mb_strlen($prefix)));
            $value = $this->sanitizeFactValue($value);
            if ($value === null) {
                return null;
            }

            if ($this->looksLikeClauseNotInterest($value)) {
                return null;
            }

            return $value;
        }

        return null;
    }

    private function extractLocationFact(string $messageText): ?string
    {
        $normalized = $this->normalizeStatement($messageText);
        $prefixes = [
            'я живу в ',
            'живу в ',
            'я из ',
            'i live in ',
            "i'm in ",
            'im in ',
            'i am in ',
            "i'm from ",
            'i am from ',
        ];

        foreach ($prefixes as $prefix) {
            if (!str_starts_with($normalized, $prefix)) {
                continue;
            }

            $value = trim(mb_substr($normalized, mb_strlen($prefix)));
            $value = $this->sanitizeFactValue($value);
            if ($value === null) {
                return null;
            }

            if (!$this->looksLikeCoarseLocation($value)) {
                return null;
            }

            return $this->formatLocationValue($value);
        }

        return null;
    }

    private function normalizeStatement(string $messageText): string
    {
        $normalized = mb_strtolower(trim($messageText));
        $normalized = str_replace(
            ["\n", "\r", "\t", ',', ';', '!', '?', '"', "'", '«', '»'],
            ' ',
            $normalized
        );

        while (str_contains($normalized, '  ')) {
            $normalized = str_replace('  ', ' ', $normalized);
        }

        return trim($normalized, " .:-");
    }

    private function sanitizeFactValue(string $value): ?string
    {
        $value = trim($value, " \t\n\r\0\x0B,.!?:;\"'«»");
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) < 2 || mb_strlen($value) > 48) {
            return null;
        }

        if (str_contains($value, 'http://') || str_contains($value, 'https://') || str_contains($value, 't.me/')) {
            return null;
        }

        if (substr_count($value, ' ') > 4) {
            return null;
        }

        return $value;
    }

    private function looksLikeClauseNotInterest(string $value): bool
    {
        $prefixes = [
            'когда ', 'если ', 'что ', 'как ', 'потому ',
            'when ', 'if ', 'that ', 'because ', 'how ',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeCoarseLocation(string $value): bool
    {
        if (preg_match('/\d/', $value) === 1) {
            return false;
        }

        $wordCount = count(array_values(array_filter(explode(' ', $value), static fn (string $part): bool => $part !== '')));
        return $wordCount >= 1 && $wordCount <= 3;
    }

    private function formatLocationValue(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    private function buildLocationMemoryValue(string $location, string $sourceMessage): string
    {
        $location = $this->formatLocationValue($location);
        if ($this->detectScriptLanguage($sourceMessage) === 'ru') {
            return 'живет в ' . $location;
        }

        return 'lives in ' . $location;
    }

    private function makeInterestKey(string $value): string
    {
        $key = mb_strtolower($value);
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '_', $key) ?? $key;
        $key = trim($key, '_');

        return $key !== '' ? 'interest_' . $key : 'interest_general';
    }

    private function detectScriptLanguage(string $text): string
    {
        return preg_match('/[А-Яа-яЁё]/u', $text) === 1 ? 'ru' : 'en';
    }

    private function containsAlphabeticCharacters(string $text): bool
    {
        return preg_match('/\p{L}/u', $text) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $normalized = str_replace(
            ["\n", "\r", "\t", ',', '.', ':', ';', '!', '?', '"', "'", '«', '»', '(', ')', '[', ']', '{', '}', '/', '\\', '-', '_'],
            ' ',
            $text
        );

        while (str_contains($normalized, '  ')) {
            $normalized = str_replace('  ', ' ', $normalized);
        }

        return array_values(array_filter(explode(' ', trim($normalized)), static fn (string $part): bool => $part !== ''));
    }
}
