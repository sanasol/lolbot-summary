<?php

namespace App\Services;

use Longman\TelegramBot\Request;

/**
 * Handles restrictions for new users joining the chat:
 * - Tracks users who joined recently
 * - Requires new users to solve a captcha or wait 10 minutes
 * - Deletes messages from restricted users
 * - Auto-deletes captcha-related messages
 */
class NewUserRestrictionService
{
    private LoggerService $logger;
    private SettingsService $settingsService;
    private array $config;

    // Tracks new users: chat_id => [user_id => ['joined_at' => timestamp, 'verified' => bool, 'captcha' => ['answer' => int, 'messages' => [msg_ids]]]]
    private array $newUsers = [];

    // Messages to auto-delete: [['chat_id' => int, 'message_id' => int, 'delete_at' => timestamp]]
    private array $messagesToDelete = [];

    private const WAIT_TIME_SECONDS = 600; // 10 minutes
    private const CAPTCHA_TIMEOUT_SECONDS = 120; // 2 minutes to answer
    private const AUTO_DELETE_DELAY_SECONDS = 30; // Delete captcha messages after 30 seconds

    public function __construct(
        LoggerService $logger,
        SettingsService $settingsService,
        array $config
    ) {
        $this->logger = $logger;
        $this->settingsService = $settingsService;
        $this->config = $config;
        $this->loadState();
    }

    /**
     * Handle a new user joining the chat
     */
    public function handleNewMember(int $chatId, int $userId, string $username): void
    {
        // Check if feature is enabled for this chat
        if (!$this->isEnabledForChat($chatId)) {
            return;
        }

        $now = time();

        if (!isset($this->newUsers[$chatId])) {
            $this->newUsers[$chatId] = [];
        }

        // Generate captcha challenge
        $num1 = rand(1, 10);
        $num2 = rand(1, 10);
        $answer = $num1 + $num2;

        $this->newUsers[$chatId][$userId] = [
            'joined_at' => $now,
            'verified' => false,
            'username' => $username,
            'captcha' => [
                'num1' => $num1,
                'num2' => $num2,
                'answer' => $answer,
                'messages' => [], // Will store message IDs to delete
                'expires_at' => $now + self::CAPTCHA_TIMEOUT_SECONDS
            ]
        ];

        $this->saveState();

        // Send captcha message
        $this->sendCaptcha($chatId, $userId, $username, $num1, $num2);

        $this->logger->log("New user {$username} (ID: {$userId}) joined chat {$chatId}, captcha sent", "NewUserRestriction");
    }

    /**
     * Check if a user is allowed to send messages
     * Returns array: ['allowed' => bool, 'reason' => string|null]
     */
    public function checkUserAllowed(int $chatId, int $userId, int $messageId, ?string $username = null): array
    {
        // Check if feature is enabled for this chat
        if (!$this->isEnabledForChat($chatId)) {
            return ['allowed' => true, 'reason' => null];
        }

        // If user is not tracked, check if they're a new member via API
        if (!isset($this->newUsers[$chatId][$userId])) {
            $isNewMember = $this->checkIfNewMemberViaApi($chatId, $userId, $username);
            if (!$isNewMember) {
                // User is an established member, allow
                return ['allowed' => true, 'reason' => null];
            }
            // User was just registered as new, continue with restriction check
        }

        $user = $this->newUsers[$chatId][$userId];
        $now = time();

        // If already verified, allow
        if ($user['verified']) {
            return ['allowed' => true, 'reason' => null];
        }

        // Check if captcha answer (handle this separately)
        if (isset($user['captcha']) && $user['captcha']['expires_at'] > $now) {
            // This might be a captcha answer, we'll check in handlePotentialCaptchaAnswer
            return ['allowed' => false, 'reason' => 'pending_captcha', 'message_id' => $messageId];
        }

        // Check if wait time has passed
        $timeSinceJoin = $now - $user['joined_at'];
        if ($timeSinceJoin >= self::WAIT_TIME_SECONDS) {
            // Auto-verify after wait time
            $this->newUsers[$chatId][$userId]['verified'] = true;
            $this->saveState();
            $this->logger->log("User {$userId} in chat {$chatId} auto-verified after wait time", "NewUserRestriction");
            return ['allowed' => true, 'reason' => null];
        }

        // User is restricted
        $remainingTime = self::WAIT_TIME_SECONDS - $timeSinceJoin;
        $minutes = floor($remainingTime / 60);
        return [
            'allowed' => false,
            'reason' => 'waiting_period',
            'remaining_minutes' => $minutes,
            'message_id' => $messageId
        ];
    }

    /**
     * Check if a message is a captcha answer and verify it
     * Returns true if message was handled as captcha answer
     */
    public function handlePotentialCaptchaAnswer(int $chatId, int $userId, string $messageText, int $messageId): bool
    {
        if (!isset($this->newUsers[$chatId][$userId])) {
            return false;
        }

        $user = $this->newUsers[$chatId][$userId];
        $now = time();

        // Check if user has pending captcha
        if (!isset($user['captcha']) || $user['verified']) {
            return false;
        }

        // Check if captcha expired
        if ($user['captcha']['expires_at'] <= $now) {
            // Captcha expired, send new one
            $num1 = rand(1, 10);
            $num2 = rand(1, 10);
            $answer = $num1 + $num2;

            $this->newUsers[$chatId][$userId]['captcha'] = [
                'num1' => $num1,
                'num2' => $num2,
                'answer' => $answer,
                'messages' => [],
                'expires_at' => $now + self::CAPTCHA_TIMEOUT_SECONDS
            ];

            $this->saveState();
            $this->sendCaptcha($chatId, $userId, $user['username'], $num1, $num2);

            // Delete the old answer attempt
            $this->deleteMessageSafely($chatId, $messageId);
            return true;
        }

        // Check if the answer is correct
        $trimmedText = trim($messageText);
        if (is_numeric($trimmedText) && (int)$trimmedText === $user['captcha']['answer']) {
            // Correct answer! Verify user
            $this->newUsers[$chatId][$userId]['verified'] = true;
            $this->saveState();

            // Delete the answer message
            $this->deleteMessageSafely($chatId, $messageId);

            // Schedule deletion of captcha messages
            if (isset($user['captcha']['messages'])) {
                foreach ($user['captcha']['messages'] as $msgId) {
                    $this->deleteMessageSafely($chatId, $msgId);
                }
            }

            // Send success message
            $params = [
                'chat_id' => $chatId,
                'text' => "✅ Welcome! You can now send messages.",
                'parse_mode' => 'Markdown'
            ];

            $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
            if ($threadId !== null) {
                $params['message_thread_id'] = (int)$threadId;
            }

            $result = Request::sendMessage($params);

            // Schedule deletion of success message
            if ($result->isOk()) {
                $successMsgId = $result->getResult()->getMessageId();
                $this->scheduleMessageDeletion($chatId, $successMsgId, self::AUTO_DELETE_DELAY_SECONDS);
            }

            $this->logger->log("User {$userId} in chat {$chatId} verified with correct captcha answer", "NewUserRestriction");
            return true;
        } else {
            // Wrong answer, delete and send warning
            $this->deleteMessageSafely($chatId, $messageId);

            $params = [
                'chat_id' => $chatId,
                'text' => "❌ Incorrect answer. Please try again or wait " .
                         floor((self::WAIT_TIME_SECONDS - (time() - $user['joined_at'])) / 60) .
                         " more minutes to send messages.",
                'parse_mode' => 'Markdown'
            ];

            $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
            if ($threadId !== null) {
                $params['message_thread_id'] = (int)$threadId;
            }

            $result = Request::sendMessage($params);

            // Schedule deletion of warning message
            if ($result->isOk()) {
                $warningMsgId = $result->getResult()->getMessageId();
                $this->scheduleMessageDeletion($chatId, $warningMsgId, self::AUTO_DELETE_DELAY_SECONDS);
            }

            return true;
        }
    }

    /**
     * Delete a user's message and send warning
     */
    public function deleteMessageAndWarn(int $chatId, int $userId, int $messageId, int $remainingMinutes): void
    {
        // Delete the user's message
        $this->deleteMessageSafely($chatId, $messageId);

        // Send warning message
        $params = [
            'chat_id' => $chatId,
            'text' => "⚠️ You need to complete the captcha or wait {$remainingMinutes} more minute(s) before you can send messages.",
            'parse_mode' => 'Markdown'
        ];

        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) {
            $params['message_thread_id'] = (int)$threadId;
        }

        $result = Request::sendMessage($params);

        // Schedule deletion of warning message
        if ($result->isOk()) {
            $warningMsgId = $result->getResult()->getMessageId();
            $this->scheduleMessageDeletion($chatId, $warningMsgId, self::AUTO_DELETE_DELAY_SECONDS);
        }
    }

    /**
     * Send captcha challenge to user
     */
    private function sendCaptcha(int $chatId, int $userId, string $username, int $num1, int $num2): void
    {
        $userMention = "[{$username}](tg://user?id={$userId})";
        $text = "👋 Welcome {$userMention}!\n\n" .
                "To prevent spam, please solve this simple math problem:\n\n" .
                "**{$num1} + {$num2} = ?**\n\n" .
                "Send just the number as your answer, or wait 10 minutes to send messages freely.";

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];

        $threadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        if ($threadId !== null) {
            $params['message_thread_id'] = (int)$threadId;
        }

        $result = Request::sendMessage($params);

        if ($result->isOk()) {
            $captchaMessageId = $result->getResult()->getMessageId();

            // Store captcha message ID for later deletion
            if (isset($this->newUsers[$chatId][$userId]['captcha'])) {
                $this->newUsers[$chatId][$userId]['captcha']['messages'][] = $captchaMessageId;
                $this->saveState();
            }

            // Schedule deletion of captcha message after timeout
            $this->scheduleMessageDeletion($chatId, $captchaMessageId, self::CAPTCHA_TIMEOUT_SECONDS + self::AUTO_DELETE_DELAY_SECONDS);
        }
    }

    /**
     * Schedule a message for deletion
     */
    private function scheduleMessageDeletion(int $chatId, int $messageId, int $delaySeconds): void
    {
        $this->messagesToDelete[] = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'delete_at' => time() + $delaySeconds
        ];
        $this->saveState();
    }

    /**
     * Process scheduled message deletions (called by PeriodicTaskRunner)
     */
    public function processScheduledDeletions(): void
    {
        $now = time();
        $remaining = [];

        foreach ($this->messagesToDelete as $item) {
            if ($now >= $item['delete_at']) {
                $this->deleteMessageSafely($item['chat_id'], $item['message_id']);
            } else {
                $remaining[] = $item;
            }
        }

        if (count($remaining) !== count($this->messagesToDelete)) {
            $this->messagesToDelete = $remaining;
            $this->saveState();
        }
    }

    /**
     * Clean up old verified users (called by PeriodicTaskRunner)
     */
    public function cleanupOldUsers(): void
    {
        $now = time();
        $changed = false;

        foreach ($this->newUsers as $chatId => $users) {
            foreach ($users as $userId => $user) {
                $timeSinceJoin = $now - $user['joined_at'];

                // Remove users who joined more than 24 hours ago
                if ($timeSinceJoin > 86400) {
                    unset($this->newUsers[$chatId][$userId]);
                    $changed = true;
                    $this->logger->log("Cleaned up old user record: {$userId} in chat {$chatId}", "NewUserRestriction");
                }
            }

            // Remove empty chat entries
            if (empty($this->newUsers[$chatId])) {
                unset($this->newUsers[$chatId]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->saveState();
        }
    }

    /**
     * Delete a message safely (catches exceptions)
     */
    private function deleteMessageSafely(int $chatId, int $messageId): void
    {
        try {
            $result = Request::deleteMessage([
                'chat_id' => $chatId,
                'message_id' => $messageId
            ]);

            if (!$result->isOk()) {
                $this->logger->log(
                    "Failed to delete message {$messageId} in chat {$chatId}: " . $result->getDescription(),
                    "NewUserRestriction"
                );
            }
        } catch (\Throwable $e) {
            $this->logger->logError(
                "Error deleting message {$messageId} in chat {$chatId}: " . $e->getMessage(),
                "NewUserRestriction"
            );
        }
    }

    /**
     * Check if a user is a new member via Telegram API
     * If they joined recently (within WAIT_TIME_SECONDS), track them as a new user
     * Returns true if user is new and needs restriction, false if established member
     */
    private function checkIfNewMemberViaApi(int $chatId, int $userId, ?string $username): bool
    {
        try {
            $result = Request::getChatMember([
                'chat_id' => $chatId,
                'user_id' => $userId
            ]);

            if (!$result->isOk()) {
                $this->logger->log(
                    "Failed to get chat member info for user {$userId} in chat {$chatId}: " . $result->getDescription(),
                    "NewUserRestriction"
                );
                // On API error, allow the user (fail open)
                return false;
            }

            $member = $result->getResult();
            $status = $member->getStatus();

            // Admins and creators are never restricted
            if (in_array($status, ['administrator', 'creator'])) {
                return false;
            }

            // Check if we can get the join date (available in some cases)
            // getChatMember returns ChatMember which may have joined_date for restricted/member status
            $joinedDate = null;

            // Try to get the user object to check for join date
            // The ChatMember object has different properties based on status
            $rawData = $member->getRawData();

            // For restricted users, there might be an 'until_date' or we can check by status
            // For regular members in supergroups, there's no direct join date API
            // So we use a heuristic: if we don't have them tracked and they're sending their first message,
            // treat them as potentially new

            // Check if there's a 'date' field (some API versions include this)
            if (isset($rawData['date'])) {
                $joinedDate = $rawData['date'];
            }

            $now = time();

            // If we have a join date and it's recent, or if we can't determine, apply restriction
            if ($joinedDate !== null) {
                $timeSinceJoin = $now - $joinedDate;
                if ($timeSinceJoin > self::WAIT_TIME_SECONDS) {
                    // User joined more than 10 minutes ago, they're established
                    $this->logger->log(
                        "User {$userId} in chat {$chatId} is an established member (joined {$timeSinceJoin}s ago)",
                        "NewUserRestriction"
                    );
                    return false;
                }
            }

            // User is potentially new - track them and send captcha
            // Use current time as join time since we don't know the actual join time
            $displayName = $username ?? "User";
            $this->handleNewMember($chatId, $userId, $displayName);

            $this->logger->log(
                "User {$userId} ({$displayName}) detected as new member on first message in chat {$chatId}",
                "NewUserRestriction"
            );

            return true;

        } catch (\Throwable $e) {
            $this->logger->logError(
                "Error checking member status for user {$userId} in chat {$chatId}: " . $e->getMessage(),
                "NewUserRestriction"
            );
            // On error, allow the user (fail open)
            return false;
        }
    }

    /**
     * Check if the feature is enabled for a chat
     */
    private function isEnabledForChat(int $chatId): bool
    {
        return (bool)$this->settingsService->getSetting($chatId, 'new_user_restriction_enabled', false);
    }

    /**
     * Load state from file
     */
    private function loadState(): void
    {
        $stateFile = $this->config['log_path'] . '/new_user_restrictions.json';

        if (file_exists($stateFile)) {
            $data = json_decode(file_get_contents($stateFile), true);
            if (is_array($data)) {
                $this->newUsers = $data['newUsers'] ?? [];
                $this->messagesToDelete = $data['messagesToDelete'] ?? [];
            }
        }
    }

    /**
     * Save state to file
     */
    private function saveState(): void
    {
        $stateFile = $this->config['log_path'] . '/new_user_restrictions.json';

        $data = [
            'newUsers' => $this->newUsers,
            'messagesToDelete' => $this->messagesToDelete
        ];

        file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT));
    }
}
