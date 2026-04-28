<?php

namespace App\Services\AI;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\Message;

/**
 * File-backed MCP chat history that sanitizes broken assistant turns before
 * they are persisted or replayed into future requests.
 */
class SafeMcpChatHistory extends FileChatHistory
{
    private McpResponseSanitizer $sanitizer;

    public function __construct(
        string $directory,
        string $key,
        int $contextWindow = 50000,
        string $prefix = 'neuron_',
        string $ext = '.chat',
        ?McpResponseSanitizer $sanitizer = null
    ) {
        $this->sanitizer = $sanitizer ?? new McpResponseSanitizer();
        parent::__construct($directory, $key, $contextWindow, $prefix, $ext);
    }

    protected function load(): void
    {
        if (\is_file($this->getFilePath())) {
            $messages = \json_decode(\file_get_contents($this->getFilePath()), true) ?? [];
            $this->history = $this->deserializeMessages($messages);

            if ($this->sanitizeHistoryMessages()) {
                $this->setMessages($this->history);
            }
        }
    }

    public function addMessage(Message $message): ChatHistoryInterface
    {
        return parent::addMessage($this->sanitizeMessage($message));
    }

    private function sanitizeHistoryMessages(): bool
    {
        $changed = false;

        foreach ($this->history as $index => $message) {
            $originalContent = $message->getContent();
            $sanitized = $this->sanitizeMessage($message);
            if ($sanitized->getContent() !== $originalContent) {
                $changed = true;
            }
            $this->history[$index] = $sanitized;
        }

        return $changed;
    }

    private function sanitizeMessage(Message $message): Message
    {
        $role = $message->getRole();
        if (!\in_array($role, [MessageRole::ASSISTANT->value, MessageRole::MODEL->value], true)) {
            return $message;
        }

        $content = $message->getContent();
        if (!\is_string($content) || trim($content) === '') {
            return $message;
        }

        $sanitized = $this->sanitizer->sanitize($content);
        if ($sanitized === '') {
            $sanitized = '[Previous MCP response omitted because the original output looked corrupted.]';
        }

        if ($sanitized !== $content) {
            $message->setContent($sanitized);
        }

        return $message;
    }
}
