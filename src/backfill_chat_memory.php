<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\ChatMemoryStore;
use App\Services\LoggerService;
use App\Services\MemoryExtractor;

$options = getopt('', [
    'chat-id:',
    'source::',
    'title::',
    'dry-run',
    'max-users::',
]);

if (!isset($options['chat-id']) || !is_numeric((string)$options['chat-id'])) {
    fwrite(STDERR, "Usage: php src/backfill_chat_memory.php --chat-id=-100123 [--source=all|v2|logs] [--title=\"Chat title\"] [--dry-run] [--max-users=20]\n");
    exit(1);
}

$chatId = (int)$options['chat-id'];
$source = strtolower(trim((string)($options['source'] ?? 'all')));
if (!in_array($source, ['all', 'v2', 'logs'], true)) {
    fwrite(STDERR, "Invalid --source value. Use all, v2, or logs.\n");
    exit(1);
}

$dryRun = array_key_exists('dry-run', $options);
$maxUsers = isset($options['max-users']) && is_numeric((string)$options['max-users'])
    ? max(1, (int)$options['max-users'])
    : 1000;

$config = require __DIR__ . '/../config/config.php';
$dataPath = $config['log_path'] ?? (__DIR__ . '/../data');
$logger = new LoggerService($dataPath);
$memoryStore = new ChatMemoryStore($dataPath, $logger);
$extractor = new MemoryExtractor($config, $logger, $memoryStore);

$records = [];
$sourceStats = [
    'v2_records' => 0,
    'log_records' => 0,
    'deduped_records' => 0,
];

if ($source === 'all' || $source === 'v2') {
    foreach (loadRecordsFromMessagesV2($dataPath, $chatId) as $record) {
        $key = buildRecordKey($record);
        if (!isset($records[$key])) {
            $records[$key] = $record;
        }
        $sourceStats['v2_records']++;
    }
}

if ($source === 'all' || $source === 'logs') {
    foreach (loadRecordsFromWebhookLogs($dataPath, $chatId) as $record) {
        $key = buildRecordKey($record);
        if (!isset($records[$key])) {
            $records[$key] = $record;
        }
        $sourceStats['log_records']++;
    }
}

$records = array_values($records);
usort($records, static fn (array $a, array $b): int => ((int)$a['ts']) <=> ((int)$b['ts']));
$sourceStats['deduped_records'] = count($records);

$users = [];
$userCorpora = [];
$chatCorpus = [];

foreach ($records as $record) {
    $userId = (int)($record['user_id'] ?? 0);
    if ($userId <= 0 || (bool)($record['is_bot'] ?? false)) {
        continue;
    }

    $senderContext = [
        'user_id' => $userId,
        'username' => $record['username'] ?? null,
        'first_name' => $record['first_name'] ?? null,
        'last_name' => $record['last_name'] ?? null,
        'display_name' => $record['display_name'] ?? buildDisplayName($record),
        'is_bot' => false,
    ];

    $users[(string)$userId] = $senderContext;
    if (!$dryRun) {
        $extractor->observeUser($chatId, $senderContext, (int)($record['ts'] ?? time()));
    }

    $text = trim((string)($record['text'] ?? ''));
    if ($text === '' || isExplicitCommandText($text)) {
        continue;
    }

    $scored = scoreProfileCandidate($text);
    if ($scored['eligible']) {
        $userCorpora[(string)$userId][] = [
            'message_id' => isset($record['message_id']) ? (int)$record['message_id'] : null,
            'ts' => isset($record['ts']) ? (int)$record['ts'] : null,
            'text' => $text,
            'score' => $scored['score'],
        ];
    }

    $chatScore = scoreChatCandidate($text);
    if ($chatScore['eligible']) {
        $chatCorpus[] = [
            'message_id' => isset($record['message_id']) ? (int)$record['message_id'] : null,
            'ts' => isset($record['ts']) ? (int)$record['ts'] : null,
            'text' => $text,
            'score' => $chatScore['score'],
        ];
    }
}

uasort($userCorpora, static function (array $a, array $b): int {
    return count($b) <=> count($a);
});

$selectedUsers = array_slice($userCorpora, 0, $maxUsers, true);
$chatTitle = trim((string)($options['title'] ?? detectChatTitle($dataPath, $chatId) ?? ('chat ' . $chatId)));

$stats = [
    'chat_id' => $chatId,
    'chat_title' => $chatTitle,
    'dry_run' => $dryRun,
    'sources' => $sourceStats,
    'users_observed' => count($users),
    'users_with_candidate_corpus' => count($userCorpora),
    'users_processed' => 0,
    'user_facts_stored' => 0,
    'chat_facts_stored' => 0,
    'sample_users' => [],
];

foreach ($selectedUsers as $userId => $messages) {
    $selectedCorpus = selectCorpusMessages($messages);
    if ($selectedCorpus === []) {
        continue;
    }

    $stats['users_processed']++;
    $displayName = (string)($users[$userId]['display_name'] ?? $users[$userId]['first_name'] ?? ('user ' . $userId));

    if (count($stats['sample_users']) < 8) {
        $stats['sample_users'][] = [
            'user_id' => (int)$userId,
            'display_name' => $displayName,
            'candidate_messages' => count($messages),
            'selected_messages' => count($selectedCorpus),
        ];
    }

    if ($dryRun) {
        continue;
    }

    $stats['user_facts_stored'] += $extractor->backfillUserProfileFromCorpus(
        $chatId,
        (int)$userId,
        $displayName,
        $selectedCorpus
    );
}

$selectedChatCorpus = selectCorpusMessages($chatCorpus, 18, 4200);
if ($selectedChatCorpus !== []) {
    if ($dryRun) {
        $stats['chat_candidate_messages'] = count($selectedChatCorpus);
    } else {
        $stats['chat_facts_stored'] = $extractor->backfillChatFactsFromCorpus(
            $chatId,
            $chatTitle,
            $selectedChatCorpus
        );
    }
}

echo "Chat memory backfill completed\n";
echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

/**
 * @return iterable<int, array<string, mixed>>
 */
function loadRecordsFromMessagesV2(string $dataPath, int $chatId): iterable
{
    $file = rtrim($dataPath, '/') . '/' . $chatId . '_messages_v2.jsonl';
    if (!is_file($file)) {
        return;
    }

    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return;
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $record = json_decode($line, true);
        if (!is_array($record) || (int)($record['chat_id'] ?? 0) !== $chatId) {
            continue;
        }

        if ((bool)($record['is_bot'] ?? false)) {
            continue;
        }

        $text = trim((string)($record['text'] ?? $record['caption'] ?? ''));
        yield [
            'ts' => (int)($record['ts'] ?? time()),
            'message_id' => isset($record['message_id']) ? (int)$record['message_id'] : null,
            'user_id' => isset($record['user_id']) ? (int)$record['user_id'] : 0,
            'username' => $record['username'] ?? null,
            'first_name' => $record['first_name'] ?? null,
            'last_name' => $record['last_name'] ?? null,
            'display_name' => $record['display_name'] ?? buildDisplayName($record),
            'is_bot' => false,
            'text' => $text,
        ];
    }

    fclose($handle);
}

/**
 * @return iterable<int, array<string, mixed>>
 */
function loadRecordsFromWebhookLogs(string $dataPath, int $chatId): iterable
{
    $files = glob(rtrim($dataPath, '/') . '/webhook_*.log') ?: [];
    sort($files);

    foreach ($files as $file) {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            continue;
        }

        $buffer = null;
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($buffer === null) {
                $jsonStart = strpos($line, '{"update_id":');
                if ($jsonStart === false) {
                    continue;
                }

                $buffer = substr($line, $jsonStart);
            } else {
                $buffer .= $line;
            }

            $payload = json_decode($buffer, true);
            if (!is_array($payload) || !isset($payload['update_id'])) {
                if (strlen($buffer) > 500000) {
                    $buffer = null;
                }
                continue;
            }

            $buffer = null;
            if (!is_array($payload) || !isset($payload['message']) || !is_array($payload['message'])) {
                continue;
            }

            $message = $payload['message'];
            $messageChatId = (int)($message['chat']['id'] ?? 0);
            if ($messageChatId !== $chatId) {
                continue;
            }

            if (($message['chat']['type'] ?? '') !== 'group' && ($message['chat']['type'] ?? '') !== 'supergroup') {
                continue;
            }

            $from = is_array($message['from'] ?? null) ? $message['from'] : [];
            if ((bool)($from['is_bot'] ?? false)) {
                continue;
            }

            $text = trim((string)($message['text'] ?? $message['caption'] ?? ''));
            yield [
                'ts' => (int)($message['date'] ?? time()),
                'message_id' => isset($message['message_id']) ? (int)$message['message_id'] : null,
                'user_id' => isset($from['id']) ? (int)$from['id'] : 0,
                'username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name' => $from['last_name'] ?? null,
                'display_name' => buildDisplayName([
                    'username' => $from['username'] ?? null,
                    'first_name' => $from['first_name'] ?? null,
                    'last_name' => $from['last_name'] ?? null,
                ]),
                'is_bot' => false,
                'text' => $text,
            ];
        }

        fclose($handle);
    }
}

/**
 * @param array<string, mixed> $record
 */
function buildRecordKey(array $record): string
{
    $chatId = (int)($record['chat_id'] ?? $record['chatId'] ?? 0);
    $messageId = (int)($record['message_id'] ?? 0);
    $userId = (int)($record['user_id'] ?? 0);
    $ts = (int)($record['ts'] ?? 0);

    if ($messageId > 0) {
        return ($chatId !== 0 ? $chatId : 'chat') . ':' . $messageId;
    }

    return ($chatId !== 0 ? $chatId : 'chat') . ':' . $userId . ':' . $ts;
}

/**
 * @param array<string, mixed> $record
 */
function buildDisplayName(array $record): string
{
    $firstName = trim((string)($record['first_name'] ?? ''));
    $lastName = trim((string)($record['last_name'] ?? ''));
    $username = trim((string)($record['username'] ?? ''));

    $name = trim($firstName . ' ' . $lastName);
    if ($name !== '' && $username !== '') {
        return $name . ' (@' . $username . ')';
    }

    if ($name !== '') {
        return $name;
    }

    if ($username !== '') {
        return '@' . $username;
    }

    return 'Unknown user';
}

function isExplicitCommandText(string $text): bool
{
    return str_starts_with(ltrim(trim($text)), '/');
}

/**
 * @return array{eligible: bool, score: int}
 */
function scoreProfileCandidate(string $text): array
{
    $normalized = mb_strtolower(trim($text));
    if ($normalized === '' || mb_strlen($normalized) < 4 || mb_strlen($normalized) > 500) {
        return ['eligible' => false, 'score' => 0];
    }

    $score = 0;

    if (preg_match('/https?:\/\/|www\.|t\.me\//ui', $normalized) === 1) {
        $score -= 3;
    }

    if (preg_match('/\b(i|i\'m|im|my|me|я|меня|мне|мой|моя|моё|мое|мы|наш|наша)\b/ui', $normalized) === 1) {
        $score += 4;
    }

    if (preg_match('/\b(love|like|live|from|work|working|prefer|speak|call me|use|using|role|expert|background|goal|available|я люблю|люблю|живу|из |работаю|предпочитаю|говорю|зовут|занимаюсь|интересуюсь)\b/ui', $normalized) === 1) {
        $score += 5;
    }

    if (preg_match('/\b(php|python|go|rust|clickhouse|postgres|backend|frontend|devops|ai|ml|android|ios|аналитик|разработчик|бэкенд|фронтенд|кликхаус|ии)\b/ui', $normalized) === 1) {
        $score += 2;
    }

    $length = mb_strlen($normalized);
    if ($length >= 20 && $length <= 220) {
        $score += 1;
    }

    if (preg_match('/^(xd|лол|ага|нет|да|ок|ok|угу|мм+|хз|haha|lol)+$/ui', $normalized) === 1) {
        $score -= 3;
    }

    return ['eligible' => $score > 0, 'score' => $score];
}

/**
 * @return array{eligible: bool, score: int}
 */
function scoreChatCandidate(string $text): array
{
    $normalized = mb_strtolower(trim($text));
    if ($normalized === '' || mb_strlen($normalized) < 8 || mb_strlen($normalized) > 500) {
        return ['eligible' => false, 'score' => 0];
    }

    $score = 0;
    if (preg_match('/\b(this group|this chat|group|chat|этот чат|эта группа|здесь|у нас|мы используем|only for|только для|правило|нельзя|нужно)\b/ui', $normalized) === 1) {
        $score += 4;
    }

    if (preg_match('/https?:\/\/|www\.|t\.me\//ui', $normalized) === 1) {
        $score -= 2;
    }

    return ['eligible' => $score > 0, 'score' => $score];
}

/**
 * @param array<int, array{message_id:int|null, ts:int|null, text:string, score:int}> $messages
 * @return array<int, array{message_id:int|null, ts:int|null, text:string}>
 */
function selectCorpusMessages(array $messages, int $maxMessages = 24, int $maxChars = 5500): array
{
    usort($messages, static function (array $a, array $b): int {
        if (($a['score'] ?? 0) === ($b['score'] ?? 0)) {
            return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0));
        }

        return ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
    });

    $selected = [];
    $seen = [];
    $charCount = 0;

    foreach ($messages as $message) {
        $text = trim((string)($message['text'] ?? ''));
        if ($text === '') {
            continue;
        }

        $key = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $length = mb_strlen($text);
        if ($length > 320) {
            $text = mb_substr($text, 0, 320) . '...';
            $length = mb_strlen($text);
        }

        if ($charCount + $length > $maxChars && count($selected) >= 10) {
            break;
        }

        $selected[] = [
            'message_id' => isset($message['message_id']) ? (int)$message['message_id'] : null,
            'ts' => isset($message['ts']) ? (int)$message['ts'] : null,
            'text' => $text,
        ];
        $charCount += $length;

        if (count($selected) >= $maxMessages) {
            break;
        }
    }

    return $selected;
}

function detectChatTitle(string $dataPath, int $chatId): ?string
{
    $file = rtrim($dataPath, '/') . '/' . $chatId . '_chat_meta.json';
    if (!is_file($file)) {
        return null;
    }

    $payload = json_decode((string)file_get_contents($file), true);
    if (!is_array($payload)) {
        return null;
    }

    $title = trim((string)($payload['title'] ?? ''));
    return $title !== '' ? $title : null;
}
