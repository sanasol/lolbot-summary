<?php

namespace App\Services;

/**
 * File-backed vote tracking for community moderation.
 * Tracks active votes per chat and target (messageId + userId) and persists to disk.
 */
class VoteService
{
    /**
     * @var array<int, array<string, array>> $votes
     * Structure: [chatId => [key => [
     *   'type' => 'ban'|'mute',
     *   'initiator_id' => int,
     *   'target_user_id' => int,
     *   'target_message_id' => int,
     *   'created_at' => int,
     *   'expires_at' => int,
     *   'yes' => array<int,bool>,
     *   'no' => array<int,bool>,
     *   'announce_message_id' => int|null,
     * ]]]
     */
    private array $votes = [];

    private string $storageFile;

    public function __construct(string $storageDir = __DIR__ . '/../../data')
    {
        if (!is_dir($storageDir) && !mkdir($concurrentDirectory = $storageDir, 0777, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
        $this->storageFile = rtrim($storageDir, '/').'/votes.json';
        $this->load();
    }

    private function makeKey(int $targetUserId, int $targetMessageId): string
    {
        return $targetUserId . ':' . $targetMessageId;
    }

    private function cleanupExpired(int $chatId): void
    {
        if (!isset($this->votes[$chatId])) return;
        $now = time();
        foreach ($this->votes[$chatId] as $key => $vote) {
            if (($vote['expires_at'] ?? 0) <= $now) {
                unset($this->votes[$chatId][$key]);
            }
        }
        if (empty($this->votes[$chatId])) {
            unset($this->votes[$chatId]);
        }
    }

    private function load(): void
    {
        if (!file_exists($this->storageFile)) {
            $this->votes = [];
            return;
        }
        $raw = @file_get_contents($this->storageFile);
        $data = $raw !== false ? json_decode($raw, true) : null;
        $this->votes = is_array($data) ? $data : [];
        // Cleanup expired votes for all chats on load (used by interactive paths)
        foreach (array_keys($this->votes) as $chatId) {
            $this->cleanupExpired((int)$chatId);
        }
    }

    /**
     * Load votes without cleaning up expired ones (used by background processor so it can finalize/notify).
     */
    private function loadWithoutCleanup(): array
    {
        if (!file_exists($this->storageFile)) {
            return [];
        }
        $raw = @file_get_contents($this->storageFile);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * Return a snapshot of all active votes grouped by chat id.
     * Structure: [chatId => [key => voteArray]]
     */
    public function getAllActiveVotes(): array
    {
        // Load raw data without cleanup so the periodic processor can finalize/notify expired votes
        $this->votes = $this->loadWithoutCleanup();
        return $this->votes;
    }

    /**
     * Internal snapshot for background processing. Same as getAllActiveVotes().
     * Kept for readability/semantic clarity.
     */
    public function getAllForProcessing(): array
    {
        return $this->getAllActiveVotes();
    }

    private function save(): void
    {
        // Best-effort save, ignore errors
        $dir = dirname($this->storageFile);
        if (!is_dir($dir) && !mkdir($concurrentDirectory = $dir, 0777, true) && !is_dir($concurrentDirectory)) {
            // If we cannot create dir, skip saving silently
            return;
        }
        @file_put_contents($this->storageFile, json_encode($this->votes, JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function startVote(int $chatId, string $type, int $initiatorId, int $targetUserId, int $targetMessageId, int $durationSec): array
    {
        $this->load();
        $this->cleanupExpired($chatId);
        $key = $this->makeKey($targetUserId, $targetMessageId);
        $now = time();
        $expires = $now + max(60, $durationSec);
        $vote = [
            'type' => $type,
            'initiator_id' => $initiatorId,
            'target_user_id' => $targetUserId,
            'target_message_id' => $targetMessageId,
            'created_at' => $now,
            'expires_at' => $expires,
            'yes' => [],
            'no' => [],
            'announce_message_id' => null,
        ];
        $this->votes[$chatId][$key] = $vote;
        $this->save();
        return $vote;
    }

    public function getVote(int $chatId, int $targetUserId, int $targetMessageId): ?array
    {
        $this->load();
        $this->cleanupExpired($chatId);
        $key = $this->makeKey($targetUserId, $targetMessageId);
        return $this->votes[$chatId][$key] ?? null;
    }

    public function getAnyActiveVoteByReply(int $chatId, int $repliedUserId, int $repliedMessageId): ?array
    {
        return $this->getVote($chatId, $repliedUserId, $repliedMessageId);
    }

    public function addVote(int $chatId, int $targetUserId, int $targetMessageId, int $voterId, bool $yes): ?array
    {
        $this->load();
        $vote = $this->getVote($chatId, $targetUserId, $targetMessageId);
        if (!$vote) return null;
        // prevent multiple votes; update their vote if changed
        unset($vote['yes'][$voterId], $vote['no'][$voterId]);
        if ($yes) {
            $vote['yes'][$voterId] = true;
        } else {
            $vote['no'][$voterId] = true;
        }
        $key = $this->makeKey($targetUserId, $targetMessageId);
        $this->votes[$chatId][$key] = $vote;
        $this->save();
        return $vote;
    }

    public function finalize(int $chatId, int $targetUserId, int $targetMessageId): void
    {
        $this->load();
        $key = $this->makeKey($targetUserId, $targetMessageId);
        unset($this->votes[$chatId][$key]);
        if (empty($this->votes[$chatId])) unset($this->votes[$chatId]);
        $this->save();
    }

    public function setAnnounceMessageId(int $chatId, int $targetUserId, int $targetMessageId, int $announceMessageId): void
    {
        $this->load();
        $key = $this->makeKey($targetUserId, $targetMessageId);
        if (!isset($this->votes[$chatId][$key])) return;
        $this->votes[$chatId][$key]['announce_message_id'] = $announceMessageId;
        $this->save();
    }
}
