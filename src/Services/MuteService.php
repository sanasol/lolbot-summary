<?php

namespace App\Services;

/**
 * File-backed storage to track muted users and their expiration times.
 * Structure:
 * [
 *   chatId => [ userId => [ 'until' => int ] ]
 * ]
 */
class MuteService
{
    private array $mutes = [];
    private string $storageFile;

    public function __construct(string $storageDir = __DIR__ . '/../../data')
    {
        if (!is_dir($storageDir) && !mkdir($concurrentDirectory = $storageDir, 0777, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
        $this->storageFile = rtrim($storageDir, '/').'/mutes.json';
        $this->load();
    }

    private function load(): void
    {
        if (!file_exists($this->storageFile)) {
            $this->mutes = [];
            return;
        }
        $raw = @file_get_contents($this->storageFile);
        $data = $raw !== false ? json_decode($raw, true) : null;
        $this->mutes = is_array($data) ? $data : [];
        $this->cleanupExpired();
    }

    private function save(): void
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir) && !mkdir($concurrentDirectory = $dir, 0777, true) && !is_dir($concurrentDirectory)) {
            return;
        }
        @file_put_contents($this->storageFile, json_encode($this->mutes, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function cleanupExpired(): void
    {
        $now = time();
        foreach ($this->mutes as $chatId => $users) {
            foreach ($users as $userId => $entry) {
                if (($entry['until'] ?? 0) <= $now) {
                    unset($this->mutes[$chatId][$userId]);
                }
            }
            if (empty($this->mutes[$chatId])) {
                unset($this->mutes[$chatId]);
            }
        }
    }

    public function addMute(int $chatId, int $userId, int $until): void
    {
        $this->load();
        if (!isset($this->mutes[$chatId])) $this->mutes[$chatId] = [];
        $this->mutes[$chatId][$userId] = ['until' => $until];
        $this->save();
    }

    public function removeMute(int $chatId, int $userId): void
    {
        $this->load();
        if (isset($this->mutes[$chatId][$userId])) {
            unset($this->mutes[$chatId][$userId]);
            if (empty($this->mutes[$chatId])) unset($this->mutes[$chatId]);
            $this->save();
        }
    }

    /**
     * Return a snapshot of all mutes.
     * @return array [chatId => [userId => ['until' => ts]]]
     */
    public function getAll(): array
    {
        $this->load();
        return $this->mutes;
    }
}
