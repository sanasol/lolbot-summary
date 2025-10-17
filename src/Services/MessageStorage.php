<?php

namespace App\Services;

/**
 * Handles storage and retrieval of chat messages
 */
class MessageStorage
{
    private array $chatMessages = []; // In-memory store for messages [chat_id => [timestamp => message_text]]
    private string $logPath;

    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
        $this->loadAllMessagesFromFiles();
    }

    /**
     * Load all message files from the data directory
     */
    public function loadAllMessagesFromFiles(): void
    {
        $files = glob($this->logPath . '/*_messages.json');
        foreach ($files as $file) {
            if (preg_match('/(-?\d+)_messages\.json$/', $file, $matches)) {
                $chatId = (int)$matches[1];
                $this->loadMessagesFromFile($chatId);
            }
        }
    }

    /**
     * Store a message in memory and persist to file
     *
     * @param int $chatId The chat ID
     * @param int $timestamp The message timestamp
     * @param string $username The username of the sender
     * @param string $messageText The message text
     * @param int|null $messageId Optional message ID
     */
    public function storeMessage(int $chatId, int $timestamp, string $username, string $messageText, int $messageId = null): void
    {
        // Simple in-memory storage - consider a database or file for persistence across restarts
        if (!isset($this->chatMessages[$chatId])) {
            $this->chatMessages[$chatId] = [];
        }

        // Format with message ID if available
        if ($messageId) {
            $this->chatMessages[$chatId][$timestamp] = sprintf("[%s] [ID:%d] %s: %s", date('H:i', $timestamp), $messageId, $username, $messageText);
        } else {
            $this->chatMessages[$chatId][$timestamp] = sprintf("[%s] %s: %s", date('H:i', $timestamp), $username, $messageText);
        }

        // Persist to file immediately
        $this->saveMessagesToFile($chatId);
    }

    /**
     * Get recent messages for a chat
     *
     * @param int $chatId The chat ID
     * @param int $hours How far back to look for messages in hours
     * @return array Array of recent messages
     */
    public function getRecentMessages(int $chatId, int $hours = 24): array
    {
        // Make sure we have the latest messages from file
        $this->loadMessagesFromFile($chatId);

        $cutoff = time() - ($hours * 3600);
        $recentMessages = [];

        if (isset($this->chatMessages[$chatId])) {
            // Ensure messages are sorted by time
            ksort($this->chatMessages[$chatId]);
            foreach ($this->chatMessages[$chatId] as $timestamp => $message) {
                if ($timestamp >= $cutoff) {
                    $recentMessages[] = $message;
                }
            }
        }

        return $recentMessages;
    }

    /**
     * Get messages in a specific time range [start, end]
     *
     * @param int $chatId
     * @param int $startTs inclusive UNIX timestamp
     * @param int $endTs inclusive UNIX timestamp
     * @return array
     */
    public function getMessagesInRange(int $chatId, int $startTs, int $endTs): array
    {
        // Make sure we have the latest messages from file
        $this->loadMessagesFromFile($chatId);

        $messages = [];
        if ($startTs > $endTs) {
            [$startTs, $endTs] = [$endTs, $startTs];
        }

        if (isset($this->chatMessages[$chatId])) {
            ksort($this->chatMessages[$chatId]);
            foreach ($this->chatMessages[$chatId] as $timestamp => $message) {
                if ($timestamp >= $startTs && $timestamp <= $endTs) {
                    $messages[] = $message;
                }
            }
        }
        return $messages;
    }

    /**
     * Get recent chat messages for context
     *
     * @param int $chatId The chat ID
     * @param int $maxMessages Maximum number of messages to include (default: 10)
     * @param int $minutes How far back to look for messages in minutes (default: 30)
     * @return array Array of recent messages
     */
    public function getRecentChatContext(int $chatId, int $maxMessages = 10, int $minutes = 30): array
    {
        // Make sure we have the latest messages from file
        $this->loadMessagesFromFile($chatId);

        $cutoff = time() - ($minutes * 60);
        $contextMessages = [];

        if (isset($this->chatMessages[$chatId])) {
            // Ensure messages are sorted by time
            ksort($this->chatMessages[$chatId]);

            // Get recent messages
            $recentMessages = [];
            foreach ($this->chatMessages[$chatId] as $timestamp => $message) {
                if ($timestamp >= $cutoff) {
                    $recentMessages[$timestamp] = $message;
                }
            }

            // Take the most recent messages up to the maximum
            $recentMessages = array_slice($recentMessages, -$maxMessages, $maxMessages, true);

            foreach ($recentMessages as $message) {
                $contextMessages[] = $message;
            }
        }

        return $contextMessages;
    }

    /**
     * Clean up old messages
     */
    public function cleanupOldMessages(): void
    {
        $cutoff = time() - (24*7 * 3600); // Keep slightly more than 24 hours
        $modified = false;

        foreach ($this->chatMessages as $chatId => &$messages) {
            $chatModified = false;
            foreach ($messages as $timestamp => $message) {
                if ($timestamp < $cutoff) {
                    unset($messages[$timestamp]);
                    $chatModified = true;
                    $modified = true;
                }
            }

            if (empty($messages)) {
                unset($this->chatMessages[$chatId]);
                // Remove the file if it exists
                $filePath = $this->getChatLogFile($chatId);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            } elseif ($chatModified) {
                // Save the updated messages to file
                $this->saveMessagesToFile($chatId);
            }
        }
        unset($messages); // Unset reference
    }

    /**
     * Get all chat IDs with stored messages
     *
     * @return array Array of chat IDs
     */
    public function getAllChatIds(): array
    {
        return array_keys($this->chatMessages);
    }

    /**
     * Get the path to a chat's log file
     *
     * @param int $chatId The chat ID
     * @return string The file path
     */
    private function getChatLogFile(int $chatId): string
    {
        return $this->logPath . '/' . $chatId . '_messages.json';
    }

    /**
     * Save messages to file for a chat
     *
     * @param int $chatId The chat ID
     */
    private function saveMessagesToFile(int $chatId): void
    {
        $filePath = $this->getChatLogFile($chatId);
        if (isset($this->chatMessages[$chatId])) {
            file_put_contents($filePath, json_encode($this->chatMessages[$chatId]));
        }
    }

    /**
     * Load messages from file for a chat
     *
     * @param int $chatId The chat ID
     */
    private function loadMessagesFromFile(int $chatId): void
    {
        $filePath = $this->getChatLogFile($chatId);
        if (file_exists($filePath)) {
            $data = json_decode(file_get_contents($filePath), true);
            if (is_array($data)) {
                // Initialize chat messages array if it doesn't exist
                if (!isset($this->chatMessages[$chatId])) {
                    $this->chatMessages[$chatId] = [];
                }

                // Merge file data with in-memory data
                // For timestamps that exist in both, keep the in-memory version (likely newer)
                foreach ($data as $timestamp => $message) {
                    if (!isset($this->chatMessages[$chatId][$timestamp])) {
                        $this->chatMessages[$chatId][$timestamp] = $message;
                    }
                }

                // Ensure messages are sorted by timestamp
                ksort($this->chatMessages[$chatId]);
            }
        }
    }
}
