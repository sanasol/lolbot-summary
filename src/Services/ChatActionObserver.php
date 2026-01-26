<?php

namespace App\Services;

use NeuronAI\Agent;
use SplObserver;
use SplSubject;

/**
 * Observer that sends Telegram chat actions during agent execution
 * to show the bot is working on long-running queries.
 *
 * Telegram chat actions last ~5 seconds, so we send them on each event.
 */
class ChatActionObserver implements SplObserver
{
    private int $chatId;
    private ?int $threadId;
    private TelegramSender $sender;
    private int $lastActionTime = 0;
    private int $toolCallCount = 0;

    /**
     * Telegram chat actions available:
     * - typing (default)
     * - upload_photo
     * - record_video
     * - upload_video
     * - record_voice
     * - upload_voice
     * - upload_document
     * - choose_sticker
     * - find_location
     * - record_video_note
     * - upload_video_note
     */
    private const ACTIONS = [
        'tool-calling' => 'find_location',      // Searching for data
        'tool-called' => 'upload_document',     // Preparing results
        'inference-start' => 'typing',          // Thinking
        'inference-stop' => 'choose_sticker',   // Almost done
        'chat-start' => 'typing',               // Starting
        'rag-retrieving' => 'find_location',    // Searching
        'rag-retrieved' => 'upload_document',   // Got data
    ];

    /**
     * Fun status messages for logging
     */
    private const STATUS_MESSAGES = [
        'tool-calling' => 'Searching database...',
        'tool-called' => 'Processing results...',
        'inference-start' => 'Thinking...',
        'inference-stop' => 'Preparing response...',
    ];

    public function __construct(int $chatId, TelegramSender $sender, ?int $threadId = null)
    {
        $this->chatId = $chatId;
        $this->sender = $sender;
        $this->threadId = $threadId;
    }

    public function update(SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        if ($event === null) {
            return;
        }

        // Get the appropriate action for this event
        $action = self::ACTIONS[$event] ?? null;

        if ($action === null) {
            return;
        }

        // Track tool calls for variety
        if ($event === 'tool-calling') {
            $this->toolCallCount++;

            // Alternate between actions for variety on multiple tool calls
            if ($this->toolCallCount % 3 === 0) {
                $action = 'record_video';  // Processing video (metaphor for heavy computation)
            } elseif ($this->toolCallCount % 3 === 1) {
                $action = 'find_location'; // Searching
            } else {
                $action = 'upload_document'; // Working with data
            }
        }

        // Don't spam actions - minimum 2 seconds between actions
        $now = time();
        if ($now - $this->lastActionTime < 2) {
            return;
        }

        $this->lastActionTime = $now;

        // Send the chat action
        try {
            $this->sender->sendChatAction($this->chatId, $action, $this->threadId);
        } catch (\Exception $e) {
            // Silently ignore chat action failures - they're not critical
        }
    }

    /**
     * Get current tool call count
     */
    public function getToolCallCount(): int
    {
        return $this->toolCallCount;
    }
}
