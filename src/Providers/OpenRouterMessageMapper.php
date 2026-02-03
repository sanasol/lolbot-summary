<?php

namespace App\Providers;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\OpenAI\MessageMapper;

/**
 * Custom message mapper for OpenRouter that strips reasoning_details
 * from messages to prevent API errors when replaying chat history.
 *
 * Google Gemini's reasoning_details contain encrypted "thought signatures"
 * that are request-specific and cannot be reused in subsequent API calls.
 */
class OpenRouterMessageMapper extends MessageMapper
{
    /**
     * Keys to strip from message metadata when sending to API.
     * These contain model-specific data that can't be replayed.
     */
    private const STRIPPED_METADATA_KEYS = [
        'reasoning_details',
        'reasoning',
    ];

    /**
     * @throws \NeuronAI\Exceptions\ProviderException
     */
    protected function mapMessage(Message $message): void
    {
        $payload = $message->jsonSerialize();

        // Strip problematic metadata keys
        foreach (self::STRIPPED_METADATA_KEYS as $key) {
            unset($payload[$key]);
        }

        if (\array_key_exists('usage', $payload)) {
            unset($payload['usage']);
        }

        $attachments = $message->getAttachments();

        if (\is_string($payload['content']) && $attachments) {
            $payload['content'] = [
                [
                    'type' => 'text',
                    'text' => $payload['content'],
                ],
            ];
        }

        foreach ($attachments as $attachment) {
            if ($attachment->type === \NeuronAI\Chat\Enums\AttachmentType::DOCUMENT) {
                if ($attachment->contentType === \NeuronAI\Chat\Enums\AttachmentContentType::URL) {
                    throw new \NeuronAI\Exceptions\ProviderException('This provider does not support URL document attachments.');
                }
                $payload['content'][] = $this->mapDocumentAttachment($attachment);
            } elseif ($attachment->type === \NeuronAI\Chat\Enums\AttachmentType::IMAGE) {
                $payload['content'][] = $this->mapImageAttachment($attachment);
            }
        }

        unset($payload['attachments']);

        $this->mapping[] = $payload;
    }

    /**
     * Override mapToolCall to also strip reasoning_details from tool call messages.
     */
    protected function mapToolCall(\NeuronAI\Chat\Messages\ToolCallMessage $message): void
    {
        $message = $message->jsonSerialize();

        // Strip problematic metadata keys
        foreach (self::STRIPPED_METADATA_KEYS as $key) {
            unset($message[$key]);
        }

        if (\array_key_exists('usage', $message)) {
            unset($message['usage']);
        }

        unset($message['type']);
        unset($message['tools']);

        $this->mapping[] = $message;
    }
}
