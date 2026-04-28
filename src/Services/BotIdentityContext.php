<?php

namespace App\Services;

/**
 * Shared bot identity, aliases, capabilities, and lightweight mention helpers.
 */
class BotIdentityContext
{
    private const CANONICAL_NAME = 'Apollo';
    private const SEPARATORS = [
        ' ', "\n", "\r", "\t",
        ',', '.', ':', ';', '!', '?',
        '"', "'", '(', ')', '[', ']', '{', '}',
        '<', '>', '/', '\\', '-', '_',
        '—', '–', '«', '»',
    ];

    /**
     * @var string[]
     */
    private array $baseAliases = [
        'Apollo',
        'Apolon',
        'Аполон',
        'Аполлон',
        'bot',
        'бот',
        'ботик',
        'железяка',
    ];

    private ?string $telegramUsername;

    public function __construct(?string $telegramUsername = null)
    {
        $this->telegramUsername = $this->normalizeTelegramUsername($telegramUsername);
    }

    public function setTelegramUsername(?string $telegramUsername): void
    {
        $this->telegramUsername = $this->normalizeTelegramUsername($telegramUsername);
    }

    public function getCanonicalName(): string
    {
        return self::CANONICAL_NAME;
    }

    public function getTelegramUsername(): ?string
    {
        return $this->telegramUsername;
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        $aliases = $this->baseAliases;

        if ($this->telegramUsername !== null && $this->telegramUsername !== '') {
            $aliases[] = $this->telegramUsername;
            $aliases[] = '@' . $this->telegramUsername;
        }

        $aliases = array_values(array_filter(array_unique($aliases), static fn ($alias) => trim((string)$alias) !== ''));

        usort($aliases, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $aliases;
    }

    /**
     * @return string[]
     */
    public function getCapabilities(): array
    {
        return [
            'Summarize recent chat activity with /summary and time windows.',
            'Run Statbate and ClickHouse analytics with /mcp for member, model, room, tip, and trend questions.',
            'Explain available commands, settings, and group moderation behavior.',
            'Link a Statbate Plus token in private chat with /account.',
            'Generate or edit images only when the user explicitly asks for it.',
            'When agent tools are enabled for a chat, search the web, answer time/date questions, remember low-sensitivity group facts, and create scheduled reminders/tasks.',
            'React to messages, follow topic restrictions, and participate in vote-based moderation.',
        ];
    }

    /**
     * @return string[]
     */
    public function getCommandHelpSnippets(): array
    {
        return [
            '/summary [window] for chat summaries',
            '/mcp <query> for analytics/data lookups',
            '/settings ... for admin-only group configuration',
            '/help for command overview',
            '/account <token> in private chat to link Statbate Plus',
            '/voteban, /votemute, /yes, /no for community moderation',
            'Natural-language agent actions may be available when agent tools are enabled for the chat',
        ];
    }

    public function buildPromptContext(): string
    {
        $lines = [
            'Bot identity:',
            '- Canonical name: ' . $this->getCanonicalName(),
            '- Aliases: ' . implode(', ', $this->getAliases()),
            '- Capabilities: ' . implode(' | ', $this->getCapabilities()),
            '- Commands: ' . implode(' | ', $this->getCommandHelpSnippets()),
            ...$this->getConversationPolicyLines(),
            '- If asked what you can do, answer using the capabilities and commands above.',
            '- Do not pretend to support commands or integrations that are not listed above.',
        ];

        return implode("\n", $lines);
    }

    /**
     * @return string[]
     */
    public function getConversationPolicyLines(): array
    {
        return [
            '- Capabilities are extra tools and integrations, not the boundary of normal group-chat conversation.',
            '- For ordinary safe questions, banter, hypotheticals, jokes, rewrites, opinions, ratings, social commentary, and general knowledge, answer normally even when no command or tool is involved.',
            '- Subjective rating requests are allowed. Pick a number or stance and give a short reason when the user asks for a scale, tier, vibe check, or opinion.',
            '- Legal, medical, financial, political, and safety-adjacent questions are allowed at a high level. Add a brief non-professional caveat when useful, then still answer the practical question.',
            '- Refuse only when the user asks for clearly unsafe help such as violence, wrongdoing, evading law enforcement, private data abuse, or targeted harassment. Do not refuse merely because a topic is sensitive, political, creative, or subjective.',
            '- Do not frame normal conversation as outside your functions. Tools are additive; conversation is always available.',
        ];
    }

    public function isAddressedIn(string $messageText): bool
    {
        $messageText = trim($messageText);
        if ($messageText === '') {
            return false;
        }

        $tokens = $this->tokenize($messageText);
        if ($tokens === []) {
            return false;
        }

        $aliases = $this->getNormalizedAliases();
        foreach ($tokens as $token) {
            if (isset($aliases[$token])) {
                return true;
            }
        }

        return false;
    }

    public function stripLeadingAddress(string $messageText): string
    {
        $cleaned = trim($messageText);
        if ($cleaned === '') {
            return '';
        }

        $tokenInfo = $this->readLeadingToken($cleaned);
        if ($tokenInfo === null) {
            return $cleaned;
        }

        $aliases = $this->getNormalizedAliases();
        if (!isset($aliases[$tokenInfo['token']])) {
            return $cleaned;
        }

        $remainder = trim((string)mb_substr($cleaned, $tokenInfo['next_offset']));
        return $remainder !== '' ? $remainder : $cleaned;
    }

    private function normalizeTelegramUsername(?string $telegramUsername): ?string
    {
        if ($telegramUsername === null) {
            return null;
        }

        $telegramUsername = ltrim(trim($telegramUsername), '@');

        return $telegramUsername !== '' ? $telegramUsername : null;
    }

    /**
     * @return array<string, true>
     */
    private function getNormalizedAliases(): array
    {
        $result = [];
        foreach ($this->getAliases() as $alias) {
            $normalized = $this->normalizeToken($alias);
            if ($normalized !== '') {
                $result[$normalized] = true;
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $tokens = [];
        $current = '';
        $length = mb_strlen($text);

        for ($index = 0; $index < $length; $index++) {
            $char = mb_substr($text, $index, 1);
            if ($this->isSeparator($char) || $char === '@') {
                $token = $this->normalizeToken($current);
                if ($token !== '') {
                    $tokens[] = $token;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $tail = $this->normalizeToken($current);
        if ($tail !== '') {
            $tokens[] = $tail;
        }

        return $tokens;
    }

    /**
     * @return array{token:string,next_offset:int}|null
     */
    private function readLeadingToken(string $text): ?array
    {
        $length = mb_strlen($text);
        $index = 0;

        while ($index < $length) {
            $char = mb_substr($text, $index, 1);
            if (!$this->isSeparator($char) && $char !== '@') {
                break;
            }
            $index++;
        }

        if ($index >= $length) {
            return null;
        }

        $token = '';
        while ($index < $length) {
            $char = mb_substr($text, $index, 1);
            if ($this->isSeparator($char) || $char === '@') {
                break;
            }
            $token .= $char;
            $index++;
        }

        if ($token === '') {
            return null;
        }

        while ($index < $length) {
            $char = mb_substr($text, $index, 1);
            if (!$this->isSeparator($char) && $char !== '@') {
                break;
            }
            $index++;
        }

        return [
            'token' => $this->normalizeToken($token),
            'next_offset' => $index,
        ];
    }

    private function normalizeToken(string $value): string
    {
        return mb_strtolower(ltrim(trim($value), '@'));
    }

    private function isSeparator(string $char): bool
    {
        if ($char === '') {
            return true;
        }

        if (trim($char) === '') {
            return true;
        }

        return in_array($char, self::SEPARATORS, true);
    }
}
