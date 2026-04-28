<?php

namespace App\Services;

/**
 * Additive structured message record written to messages_v2 JSONL sidecars.
 */
class StructuredMessageRecord
{
    public int $ts;
    public int $chatId;
    public ?int $threadId;
    public ?int $messageId;
    public ?int $userId;
    public ?string $username;
    public ?string $firstName;
    public ?string $lastName;
    public ?string $displayName;
    public bool $isBot;
    public string $messageType;
    public ?string $text;
    public ?string $caption;
    public bool $hasPhoto;
    public ?string $imageSummary;
    public ?string $legacyUsername;

    public function __construct(
        int $ts,
        int $chatId,
        ?int $threadId = null,
        ?int $messageId = null,
        ?int $userId = null,
        ?string $username = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $displayName = null,
        bool $isBot = false,
        string $messageType = 'text',
        ?string $text = null,
        ?string $caption = null,
        bool $hasPhoto = false,
        ?string $imageSummary = null,
        ?string $legacyUsername = null
    ) {
        $this->ts = $ts;
        $this->chatId = $chatId;
        $this->threadId = $threadId;
        $this->messageId = $messageId;
        $this->userId = $userId;
        $this->username = $this->normalizeUsername($username);
        $this->firstName = $this->normalizeNullableString($firstName);
        $this->lastName = $this->normalizeNullableString($lastName);
        $this->displayName = $this->normalizeNullableString($displayName)
            ?? self::buildDisplayName($firstName, $lastName, $username, $legacyUsername);
        $this->isBot = $isBot;
        $this->messageType = $messageType;
        $this->text = $this->normalizeNullableString($text);
        $this->caption = $this->normalizeNullableString($caption);
        $this->hasPhoto = $hasPhoto;
        $this->imageSummary = $this->normalizeNullableString($imageSummary);
        $this->legacyUsername = $this->normalizeNullableString($legacyUsername);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int)($data['ts'] ?? time()),
            (int)($data['chat_id'] ?? 0),
            isset($data['thread_id']) ? (int)$data['thread_id'] : null,
            isset($data['message_id']) ? (int)$data['message_id'] : null,
            isset($data['user_id']) ? (int)$data['user_id'] : null,
            $data['username'] ?? null,
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
            $data['display_name'] ?? null,
            (bool)($data['is_bot'] ?? false),
            (string)($data['message_type'] ?? 'text'),
            $data['text'] ?? null,
            $data['caption'] ?? null,
            (bool)($data['has_photo'] ?? false),
            $data['image_summary'] ?? null,
            $data['legacy_username'] ?? null,
        );
    }

    public static function buildDisplayName(?string $firstName, ?string $lastName, ?string $username, ?string $fallback = null): string
    {
        $fullName = trim(trim((string)$firstName) . ' ' . trim((string)$lastName));
        $normalizedUsername = self::normalizeUsernameStatic($username);

        if ($fullName !== '' && $normalizedUsername !== null) {
            return $fullName . ' (@' . $normalizedUsername . ')';
        }

        if ($fullName !== '') {
            return $fullName;
        }

        if ($normalizedUsername !== null) {
            return '@' . $normalizedUsername;
        }

        $fallback = trim((string)$fallback);
        return $fallback !== '' ? $fallback : 'Unknown';
    }

    public function toArray(): array
    {
        return [
            'ts' => $this->ts,
            'chat_id' => $this->chatId,
            'thread_id' => $this->threadId,
            'message_id' => $this->messageId,
            'user_id' => $this->userId,
            'username' => $this->username,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'display_name' => $this->displayName,
            'is_bot' => $this->isBot,
            'message_type' => $this->messageType,
            'text' => $this->text,
            'caption' => $this->caption,
            'has_photo' => $this->hasPhoto,
            'image_summary' => $this->imageSummary,
            'legacy_username' => $this->legacyUsername,
        ];
    }

    public function getContextSpeaker(): string
    {
        return $this->displayName
            ?? self::buildDisplayName($this->firstName, $this->lastName, $this->username, $this->legacyUsername);
    }

    public function getComparableSpeaker(): string
    {
        return $this->legacyUsername ?? $this->getContextSpeaker();
    }

    public function getContextContent(): string
    {
        $parts = [];
        $text = trim((string)$this->text);
        $caption = trim((string)$this->caption);
        $imageSummary = trim((string)$this->imageSummary);

        if ($text !== '') {
            $parts[] = $text;
        } elseif ($caption !== '') {
            $parts[] = $caption;
        }

        if ($this->hasPhoto && $imageSummary !== '') {
            $parts[] = '[IMAGE: ' . $imageSummary . ']';
        } elseif ($this->hasPhoto && empty($parts)) {
            $parts[] = '[PHOTO]';
        }

        $content = trim(implode(' ', array_filter($parts, static fn ($part) => trim((string)$part) !== '')));

        return $content !== '' ? $content : '[EMPTY]';
    }

    public function dedupeKey(): string
    {
        if ($this->messageId !== null) {
            return 'id:' . $this->messageId;
        }

        return 'ts:' . $this->ts . '|speaker:' . $this->getComparableSpeaker() . '|text:' . sha1($this->getContextContent());
    }

    private function normalizeUsername(?string $username): ?string
    {
        return self::normalizeUsernameStatic($username);
    }

    private static function normalizeUsernameStatic(?string $username): ?string
    {
        if ($username === null) {
            return null;
        }

        $username = ltrim(trim($username), '@');
        return $username !== '' ? $username : null;
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
