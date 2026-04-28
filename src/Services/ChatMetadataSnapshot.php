<?php

namespace App\Services;

/**
 * Cached Telegram chat metadata used as additive prompt context.
 */
class ChatMetadataSnapshot
{
    public int $chatId;
    public ?string $title;
    public ?string $username;
    public ?string $type;
    public ?string $description;
    public ?string $pinnedMessageExcerpt;
    public ?int $lastFetchedAt;

    public function __construct(
        int $chatId,
        ?string $title = null,
        ?string $username = null,
        ?string $type = null,
        ?string $description = null,
        ?string $pinnedMessageExcerpt = null,
        ?int $lastFetchedAt = null
    ) {
        $this->chatId = $chatId;
        $this->title = $this->normalizeNullableString($title);
        $this->username = $this->normalizeUsername($username);
        $this->type = $this->normalizeNullableString($type);
        $this->description = $this->normalizeNullableString($description);
        $this->pinnedMessageExcerpt = $this->normalizeNullableString($pinnedMessageExcerpt);
        $this->lastFetchedAt = $lastFetchedAt;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int)($data['chat_id'] ?? 0),
            $data['title'] ?? null,
            $data['username'] ?? null,
            $data['type'] ?? null,
            $data['description'] ?? null,
            $data['pinned_message_excerpt'] ?? null,
            isset($data['last_fetched_at']) ? (int)$data['last_fetched_at'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'chat_id' => $this->chatId,
            'title' => $this->title,
            'username' => $this->username,
            'type' => $this->type,
            'description' => $this->description,
            'pinned_message_excerpt' => $this->pinnedMessageExcerpt,
            'last_fetched_at' => $this->lastFetchedAt,
        ];
    }

    private function normalizeUsername(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = ltrim(trim($value), '@');
        return $value !== '' ? $value : null;
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        return $value !== '' ? $value : null;
    }
}
