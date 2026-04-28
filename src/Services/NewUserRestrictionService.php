<?php

namespace App\Services;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;

/**
 * Handles unknown-user restrictions without relying on join events.
 */
class NewUserRestrictionService
{
    private LoggerService $logger;
    private SettingsService $settingsService;
    private ?MessageStorage $messageStorage = null;
    private KnownUsersStore $knownUsers;
    private array $config;

    /**
     * Pending challenges only: chat_id => [user_id => challenge state].
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $newUsers = [];

    /**
     * Messages to auto-delete: [['chat_id' => int, 'message_id' => int, 'delete_at' => timestamp]]
     *
     * @var array<int, array<string, int>>
     */
    private array $messagesToDelete = [];

    private const WAIT_TIME_SECONDS = 600;
    private const CAPTCHA_TIMEOUT_SECONDS = 120;
    private const AUTO_DELETE_DELAY_SECONDS = 30;
    private const CLEANUP_AFTER_SECONDS = 86400;
    private const SAFE_TEXT_MAX_LENGTH = 280;
    private const SAFE_TEXT_MAX_NON_EMPTY_LINES = 4;
    private const UNKNOWN_TEXT_SCAM_MIN_SCORE = 2;

    public function __construct(
        LoggerService $logger,
        SettingsService $settingsService,
        array $config
    ) {
        $this->logger = $logger;
        $this->settingsService = $settingsService;
        $this->config = $config;
        $this->knownUsers = new KnownUsersStore($config['log_path'] ?? (__DIR__ . '/../../data'), $logger);
        $this->loadState();
    }

    public function setMessageStorage(MessageStorage $messageStorage): void
    {
        $this->messageStorage = $messageStorage;
    }

    public function initializeKnownUsersBackfill(bool $force = false): void
    {
        $stats = $this->knownUsers->backfillFromAvailableSources($force);
        if (($stats['skipped'] ?? false) === true) {
            $this->logger->log(
                'Known users backfill skipped; source snapshots unchanged',
                'NewUserRestriction'
            );
        } else {
            $this->logger->log(
                'Known users backfill completed: '
                . json_encode($stats, JSON_UNESCAPED_UNICODE),
                'NewUserRestriction'
            );
        }

        if ($this->pruneVerifiedPendingUsers()) {
            $this->saveState();
        }
    }

    public function handleNewMember(int $chatId, int $userId, string $username): void
    {
        if (!$this->isEnabledForChat($chatId) || $chatId >= 0) {
            return;
        }

        $senderContext = [
            'user_id' => $userId,
            'username' => ltrim($username, '@'),
            'display_name' => $username,
            'is_bot' => false,
        ];

        if ($this->knownUsers->isKnown($chatId, $userId)) {
            return;
        }

        $this->beginPendingChallenge($chatId, $userId, $senderContext, 'join_event');
        $this->logger->log(
            "New member {$username} (ID: {$userId}) joined chat {$chatId}, captcha sent",
            'NewUserRestriction'
        );
    }

    /**
     * Returns array: ['allowed' => bool, 'reason' => string|null]
     *
     * @param array<string, mixed>|null $senderContext
     * @return array<string, mixed>
     */
    public function checkUserAllowed(Message $message, ?array $senderContext = null): array
    {
        $chat = $message->getChat();
        $from = $message->getFrom();
        $chatId = (int)$chat->getId();
        $userId = (int)($from?->getId() ?? 0);

        if (
            !$this->isEnabledForChat($chatId)
            || $chatId >= 0
            || $userId <= 0
            || (bool)($from?->getIsBot() ?? false)
        ) {
            return ['allowed' => true, 'reason' => null];
        }

        $senderContext ??= $this->buildSenderContextFromMessage($message);
        $displayName = (string)($senderContext['display_name'] ?? 'User');
        $messageId = (int)$message->getMessageId();
        $now = time();

        if ($this->knownUsers->isKnown($chatId, $userId)) {
            $this->knownUsers->touchSeen($chatId, $userId, $senderContext, (int)$message->getDate());
            return ['allowed' => true, 'reason' => null];
        }

        if ($this->isAdministratorOrCreator($chatId, $userId)) {
            $this->knownUsers->markKnown($chatId, $userId, $senderContext, 'admin_status', (int)$message->getDate());
            return ['allowed' => true, 'reason' => null];
        }

        $pending = $this->getPendingUser($chatId, $userId);
        if ($pending !== null && ($pending['verified'] ?? false)) {
            $this->knownUsers->markKnown($chatId, $userId, $senderContext, 'legacy_verified_import', $now);
            $this->clearPendingChallenge($chatId, $userId);
            return ['allowed' => true, 'reason' => null];
        }

        if ($pending !== null) {
            $restrictionStart = $this->getRestrictionStartAt($pending);
            if (($now - $restrictionStart) >= self::WAIT_TIME_SECONDS) {
                $this->knownUsers->markKnown($chatId, $userId, $senderContext, 'captcha_verified', $now);
                $this->clearPendingChallenge($chatId, $userId);
                $this->logger->log(
                    "User {$userId} in chat {$chatId} auto-verified after wait time",
                    'NewUserRestriction'
                );
                return ['allowed' => true, 'reason' => null];
            }

            $captcha = $pending['captcha'] ?? null;
            if (!is_array($captcha) || (int)($captcha['expires_at'] ?? 0) <= $now) {
                $this->refreshCaptcha($chatId, $userId, $senderContext, $pending);
                return [
                    'allowed' => false,
                    'reason' => 'pending_captcha',
                    'message_id' => $messageId,
                ];
            }

            return [
                'allowed' => false,
                'reason' => 'pending_captcha',
                'message_id' => $messageId,
            ];
        }

        $record = $this->knownUsers->getRecord($chatId, $userId);
        $isCandidate = ($record['status'] ?? null) === KnownUsersStore::STATUS_CANDIDATE;
        $isRisky = $this->isRiskyUnknownMessage($message);

        if ($isRisky) {
            $this->beginPendingChallenge($chatId, $userId, $senderContext, $isCandidate ? 'candidate_risky' : 'unknown_risky');
            $this->logger->log(
                "User {$displayName} (ID: {$userId}) in chat {$chatId} triggered unknown-user gate",
                'NewUserRestriction'
            );
            return [
                'allowed' => false,
                'reason' => 'pending_captcha',
                'message_id' => $messageId,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'record_after_accept' => true,
        ];
    }

    /**
     * Returns true if the message was handled as a captcha answer.
     *
     * @param array<string, mixed>|null $senderContext
     */
    public function handlePotentialCaptchaAnswer(
        int $chatId,
        int $userId,
        string $messageText,
        int $messageId,
        ?array $senderContext = null
    ): bool {
        $pending = $this->getPendingUser($chatId, $userId);
        if ($pending === null) {
            return false;
        }

        $restrictionStart = $this->getRestrictionStartAt($pending);
        if ((time() - $restrictionStart) >= self::WAIT_TIME_SECONDS) {
            return false;
        }

        $captcha = $pending['captcha'] ?? null;
        if (!is_array($captcha)) {
            return false;
        }

        if ((int)($captcha['expires_at'] ?? 0) <= time()) {
            return false;
        }

        $trimmedText = trim($messageText);
        if ($trimmedText === '' || !is_numeric($trimmedText)) {
            return false;
        }

        $senderContext ??= [
            'user_id' => $userId,
            'display_name' => $pending['display_name'] ?? ($pending['username'] ?? 'User'),
            'username' => $pending['username'] ?? null,
            'is_bot' => false,
        ];

        if ((int)$trimmedText === (int)($captcha['answer'] ?? -1)) {
            $this->knownUsers->markKnown($chatId, $userId, $senderContext, 'captcha_verified', time());

            $this->deleteMessageSafely($chatId, $messageId);
            foreach ((array)($captcha['messages'] ?? []) as $captchaMessageId) {
                $this->deleteMessageSafely($chatId, (int)$captchaMessageId);
            }

            $this->clearPendingChallenge($chatId, $userId);

            $params = [
                'chat_id' => $chatId,
                'text' => '✅ Welcome! You can now send messages.',
                'parse_mode' => 'Markdown',
            ];

            $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
            if ($threadId !== null) {
                $params['message_thread_id'] = (int)$threadId;
            }

            $result = Request::sendMessage($params);
            if ($result->isOk()) {
                $successMsgId = $result->getResult()->getMessageId();
                $this->scheduleMessageDeletion($chatId, $successMsgId, self::AUTO_DELETE_DELAY_SECONDS);
            }

            $this->logger->log(
                "User {$userId} in chat {$chatId} verified with correct captcha answer",
                'NewUserRestriction'
            );
            return true;
        }

        $this->deleteMessageSafely($chatId, $messageId);
        $remainingMinutes = max(
            1,
            (int)ceil((self::WAIT_TIME_SECONDS - (time() - $restrictionStart)) / 60)
        );
        $params = [
            'chat_id' => $chatId,
            'text' => "❌ Incorrect answer. Please try again or wait {$remainingMinutes} more minute(s) to send messages.",
            'parse_mode' => 'Markdown',
        ];

        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) {
            $params['message_thread_id'] = (int)$threadId;
        }

        $result = Request::sendMessage($params);
        if ($result->isOk()) {
            $warningMsgId = $result->getResult()->getMessageId();
            $this->scheduleMessageDeletion($chatId, $warningMsgId, self::AUTO_DELETE_DELAY_SECONDS);
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $senderContext
     * @return array<string, mixed>
     */
    public function recordAcceptedMessage(
        int $chatId,
        int $userId,
        ?array $senderContext = null,
        ?int $timestamp = null
    ): array {
        return $this->knownUsers->addAcceptedMessage($chatId, $userId, $senderContext, 'runtime_accept', $timestamp);
    }

    public function deleteMessageAndWarn(int $chatId, int $userId, int $messageId, int $remainingMinutes): void
    {
        $this->deleteMessageSafely($chatId, $messageId);

        $params = [
            'chat_id' => $chatId,
            'text' => "⚠️ You need to complete the captcha or wait {$remainingMinutes} more minute(s) before you can send messages.",
            'parse_mode' => 'Markdown',
        ];

        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) {
            $params['message_thread_id'] = (int)$threadId;
        }

        $result = Request::sendMessage($params);
        if ($result->isOk()) {
            $warningMsgId = $result->getResult()->getMessageId();
            $this->scheduleMessageDeletion($chatId, $warningMsgId, self::AUTO_DELETE_DELAY_SECONDS);
        }
    }

    public function processScheduledDeletions(): void
    {
        $now = time();
        $remaining = [];

        foreach ($this->messagesToDelete as $item) {
            if ($now >= (int)($item['delete_at'] ?? 0)) {
                $this->deleteMessageSafely((int)$item['chat_id'], (int)$item['message_id']);
            } else {
                $remaining[] = $item;
            }
        }

        if (count($remaining) !== count($this->messagesToDelete)) {
            $this->messagesToDelete = $remaining;
            $this->saveState();
        }
    }

    public function cleanupOldUsers(): void
    {
        $now = time();
        $changed = false;

        foreach ($this->newUsers as $chatId => $users) {
            foreach ($users as $userId => $user) {
                if (($user['verified'] ?? false) === true) {
                    unset($this->newUsers[$chatId][$userId]);
                    $changed = true;
                    continue;
                }

                $restrictedAt = $this->getRestrictionStartAt($user);
                if (($now - $restrictedAt) > self::CLEANUP_AFTER_SECONDS) {
                    unset($this->newUsers[$chatId][$userId]);
                    $changed = true;
                    $this->logger->log(
                        "Cleaned up stale pending challenge: {$userId} in chat {$chatId}",
                        'NewUserRestriction'
                    );
                }
            }

            if (empty($this->newUsers[$chatId])) {
                unset($this->newUsers[$chatId]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->saveState();
        }
    }

    private function beginPendingChallenge(int $chatId, int $userId, array $senderContext, string $trigger): void
    {
        $existing = $this->getPendingUser($chatId, $userId);
        $restrictedAt = $existing !== null ? $this->getRestrictionStartAt($existing) : time();
        $captcha = $this->buildCaptchaPayload($existing['captcha']['messages'] ?? []);

        $this->newUsers[(string)$chatId][(string)$userId] = [
            'restricted_at' => $restrictedAt,
            'username' => $senderContext['username'] ?? null,
            'display_name' => $senderContext['display_name'] ?? ($senderContext['username'] ?? 'User'),
            'trigger' => $trigger,
            'captcha' => $captcha,
        ];

        $this->saveState();
        $this->sendCaptcha(
            $chatId,
            $userId,
            (string)($senderContext['display_name'] ?? ($senderContext['username'] ?? 'User')),
            $captcha['num1'],
            $captcha['num2']
        );
    }

    /**
     * @param array<string, mixed> $pending
     * @param array<string, mixed>|null $senderContext
     */
    private function refreshCaptcha(int $chatId, int $userId, ?array $senderContext, array $pending): void
    {
        $senderContext ??= [
            'user_id' => $userId,
            'username' => $pending['username'] ?? null,
            'display_name' => $pending['display_name'] ?? ($pending['username'] ?? 'User'),
            'is_bot' => false,
        ];

        $captcha = $this->buildCaptchaPayload($pending['captcha']['messages'] ?? []);
        $pending['captcha'] = $captcha;
        $pending['username'] = $senderContext['username'] ?? ($pending['username'] ?? null);
        $pending['display_name'] = $senderContext['display_name'] ?? ($pending['display_name'] ?? 'User');
        $this->newUsers[(string)$chatId][(string)$userId] = $pending;
        $this->saveState();

        $this->sendCaptcha(
            $chatId,
            $userId,
            (string)($senderContext['display_name'] ?? 'User'),
            $captcha['num1'],
            $captcha['num2']
        );
    }

    /**
     * @param int[] $existingMessages
     * @return array<string, mixed>
     */
    private function buildCaptchaPayload(array $existingMessages = []): array
    {
        $num1 = random_int(1, 10);
        $num2 = random_int(1, 10);

        return [
            'num1' => $num1,
            'num2' => $num2,
            'answer' => $num1 + $num2,
            'messages' => $existingMessages,
            'expires_at' => time() + self::CAPTCHA_TIMEOUT_SECONDS,
        ];
    }

    private function sendCaptcha(int $chatId, int $userId, string $username, int $num1, int $num2): void
    {
        $userMention = "[{$username}](tg://user?id={$userId})";
        $text = "👋 Welcome {$userMention}!\n\n"
            . "To prevent spam, please solve this simple math problem:\n\n"
            . "**{$num1} + {$num2} = ?**\n\n"
            . 'Send just the number as your answer, or wait 10 minutes to send messages freely.';

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ];

        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) {
            $params['message_thread_id'] = (int)$threadId;
        }

        $result = Request::sendMessage($params);
        if ($result->isOk()) {
            $captchaMessageId = $result->getResult()->getMessageId();

            if (isset($this->newUsers[(string)$chatId][(string)$userId]['captcha']['messages'])) {
                $this->newUsers[(string)$chatId][(string)$userId]['captcha']['messages'][] = $captchaMessageId;
                $this->saveState();
            }

            $this->scheduleMessageDeletion(
                $chatId,
                $captchaMessageId,
                self::CAPTCHA_TIMEOUT_SECONDS + self::AUTO_DELETE_DELAY_SECONDS
            );
        }
    }

    private function scheduleMessageDeletion(int $chatId, int $messageId, int $delaySeconds): void
    {
        $this->messagesToDelete[] = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'delete_at' => time() + $delaySeconds,
        ];
        $this->saveState();
    }

    private function clearPendingChallenge(int $chatId, int $userId): void
    {
        unset($this->newUsers[(string)$chatId][(string)$userId]);
        if (empty($this->newUsers[(string)$chatId])) {
            unset($this->newUsers[(string)$chatId]);
        }
        $this->saveState();
    }

    private function deleteMessageSafely(int $chatId, int $messageId): void
    {
        try {
            $result = Request::deleteMessage([
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);

            if (!$result->isOk()) {
                $this->logger->log(
                    "Failed to delete message {$messageId} in chat {$chatId}: " . $result->getDescription(),
                    'NewUserRestriction'
                );
            }
        } catch (\Throwable $e) {
            $this->logger->logError(
                "Error deleting message {$messageId} in chat {$chatId}: " . $e->getMessage(),
                'NewUserRestriction'
            );
        }
    }

    private function isAdministratorOrCreator(int $chatId, int $userId): bool
    {
        try {
            $result = Request::getChatMember([
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            if (!$result->isOk()) {
                $this->logger->log(
                    "Failed to get chat member info for user {$userId} in chat {$chatId}: " . $result->getDescription(),
                    'NewUserRestriction'
                );
                return false;
            }

            $status = (string)$result->getResult()->getStatus();
            return in_array($status, ['administrator', 'creator'], true);
        } catch (\Throwable $e) {
            $this->logger->logError(
                "Error checking member status for user {$userId} in chat {$chatId}: " . $e->getMessage(),
                'NewUserRestriction'
            );
            return false;
        }
    }

    private function isEnabledForChat(int $chatId): bool
    {
        return (bool)$this->settingsService->getSetting($chatId, 'new_user_restriction_enabled', false);
    }

    private function loadState(): void
    {
        $stateFile = $this->config['log_path'] . '/new_user_restrictions.json';
        if (!file_exists($stateFile)) {
            return;
        }

        $data = json_decode((string)file_get_contents($stateFile), true);
        if (!is_array($data)) {
            return;
        }

        $this->newUsers = (array)($data['newUsers'] ?? []);
        $this->messagesToDelete = (array)($data['messagesToDelete'] ?? []);
    }

    private function saveState(): void
    {
        $stateFile = $this->config['log_path'] . '/new_user_restrictions.json';
        $data = [
            'newUsers' => $this->newUsers,
            'messagesToDelete' => $this->messagesToDelete,
        ];

        file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPendingUser(int $chatId, int $userId): ?array
    {
        return $this->newUsers[(string)$chatId][(string)$userId] ?? null;
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function getRestrictionStartAt(array $pending): int
    {
        return (int)($pending['restricted_at'] ?? $pending['joined_at'] ?? time());
    }

    /**
     * @param array<string, mixed> $message
     */
    private function isRiskyUnknownMessage(Message $message): bool
    {
        if ($message->getForwardDate() !== null || $message->getForwardFrom() !== null || $message->getForwardFromChat() !== null) {
            return true;
        }

        if ((bool)($message->getIsAutomaticForward() ?? false)) {
            return true;
        }

        if (
            $message->getPhoto() !== null
            || $message->getDocument() !== null
            || $message->getAnimation() !== null
            || $message->getSticker() !== null
            || $message->getVideo() !== null
            || $message->getAudio() !== null
            || $message->getVoice() !== null
            || $message->getVideoNote() !== null
            || $message->getContact() !== null
            || $message->getLocation() !== null
            || $message->getVenue() !== null
            || $message->getPoll() !== null
        ) {
            return true;
        }

        $text = trim((string)($message->getText() ?? ''));
        $caption = trim((string)($message->getCaption() ?? ''));
        $combinedText = trim($text !== '' ? $text : $caption);

        if ($combinedText === '') {
            return false;
        }

        if (str_starts_with($combinedText, '/')) {
            return true;
        }

        if ($this->hasUrlEntity((array)($message->getEntities() ?? [])) || $this->hasUrlEntity((array)($message->getCaptionEntities() ?? []))) {
            return true;
        }

        if (preg_match('/(?:https?:\/\/|www\.|t\.me\/)/iu', $combinedText) === 1) {
            return true;
        }

        if (mb_strlen($combinedText) > self::SAFE_TEXT_MAX_LENGTH) {
            return true;
        }

        $nonEmptyLines = array_values(array_filter(
            preg_split('/\R/u', $combinedText) ?: [],
            static fn (string $line): bool => trim($line) !== ''
        ));
        if (count($nonEmptyLines) > self::SAFE_TEXT_MAX_NON_EMPTY_LINES) {
            return true;
        }

        return $this->getUnknownTextScamScore($combinedText, $message) >= self::UNKNOWN_TEXT_SCAM_MIN_SCORE;
    }

    /**
     * @param array<int, mixed> $entities
     */
    private function hasUrlEntity(array $entities): bool
    {
        foreach ($entities as $entity) {
            if (!is_object($entity)) {
                continue;
            }

            $type = strtolower((string)($entity->getType() ?? ''));
            if (in_array($type, ['url', 'text_link'], true)) {
                return true;
            }
        }

        return false;
    }

    private function getUnknownTextScamScore(string $combinedText, Message $message): int
    {
        $score = 0;

        $textEntities = (array)($message->getEntities() ?? []);
        $captionEntities = (array)($message->getCaptionEntities() ?? []);
        if (
            $this->hasEntityType($textEntities, ['mention', 'text_mention'])
            || $this->hasEntityType($captionEntities, ['mention', 'text_mention'])
            || preg_match('/(^|[\s(])@[A-Za-z0-9_]{5,}\b/u', $combinedText) === 1
        ) {
            $score++;
        }

        if ($this->matchesAnyPattern($combinedText, [
            '/\b(?:dm|pm|message|contact|write(?:\s+to)?|text)\b/iu',
            '/\b(?:private\s+message|direct\s+message|send\s+me\s+a\s+private\s+message)\b/iu',
            '/\b(?:reply|respond)\b.{0,20}(?:\+|"\\+"|«\+»)/iu',
            '/(?:\+|"\\+"|«\+»).{0,20}\b(?:to\s+join|for\s+details|for\s+more\s+details)\b/iu',
            '/\b(?:solo\s+tienes\s+que\s+responder\s+con|responde\s+con)\b.{0,20}(?:\+|"\\+"|«\+»)/iu',
        ])) {
            $score++;
        }

        if ($this->matchesAnyPattern($combinedText, [
            '/(?:[$€£]\s?\d[\d,.]*(?:\s*[-–]\s*[$€£]?\s?\d[\d,.]*)?|\b\d[\d,.]*\s?(?:usd|eur|gbp|\$|€|£)\b)/iu',
            '/\b(?:earn|earnings|payouts?|ganancias?|ingresos?|salary|paid)\b/iu',
            '/\bper\s+(?:day|week|month)\b/iu',
            '/\ba\s+la\s+semana\b/iu',
            '/\b(?:100|200|300|400|450|1000|1400|1600|2000)\b.{0,20}\b(?:per\s+(?:day|week)|a\s+la\s+semana)\b/iu',
        ])) {
            $score++;
        }

        if ($this->matchesAnyPattern($combinedText, [
            '/\b(?:remote|fully\s+remote|work\s+from\s+home|phone[-\s]?based|flexible)\b/iu',
            '/\b(?:opportunity|join\s+a\s+small\s+remote\s+team|looking\s+for|be\s+part\s+of|private\s+online\s+community)\b/iu',
            '/\b(?:trabajo\s+a\s+distancia|a\s+distancia|equipo\s+remoto)\b/iu',
            '/\b(?:limited\s+spots?|only\s+\d+\s+spots?|available\s+this\s+week|limited\s+circle)\b/iu',
        ])) {
            $score++;
        }

        return $score;
    }

    /**
     * @param array<int, mixed> $entities
     * @param array<int, string> $types
     */
    private function hasEntityType(array $entities, array $types): bool
    {
        $types = array_map(
            static fn (string $type): string => strtolower($type),
            $types
        );

        foreach ($entities as $entity) {
            if (!is_object($entity)) {
                continue;
            }

            $type = strtolower((string)($entity->getType() ?? ''));
            if (in_array($type, $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $patterns
     */
    private function matchesAnyPattern(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSenderContextFromMessage(Message $message): array
    {
        $from = $message->getFrom();
        $username = $from?->getUsername();
        $firstName = $from?->getFirstName();
        $lastName = $from?->getLastName();

        return [
            'user_id' => $from?->getId(),
            'username' => $username,
            'display_name' => StructuredMessageRecord::buildDisplayName(
                $firstName,
                $lastName,
                $username,
                $firstName ?? ($username ?? 'Unknown')
            ),
            'is_bot' => (bool)($from?->getIsBot() ?? false),
        ];
    }

    private function pruneVerifiedPendingUsers(): bool
    {
        $changed = false;
        foreach ($this->newUsers as $chatId => $users) {
            foreach ($users as $userId => $user) {
                if (($user['verified'] ?? false) === true) {
                    unset($this->newUsers[$chatId][$userId]);
                    $changed = true;
                }
            }

            if (empty($this->newUsers[$chatId])) {
                unset($this->newUsers[$chatId]);
                $changed = true;
            }
        }

        return $changed;
    }
}
