<?php

namespace App\Services;

use App\Services\AI\HttpClientTrait;

/**
 * Semantic interaction router for non-command conversational messages.
 *
 * Deterministic code keeps only the outer safety rails:
 * - addressed / reply / configured bot-topic eligibility
 * - conservative fallback when the classifier is unavailable
 *
 * Route, tone, and intent are chosen by structured model output instead of
 * keyword or regexp heuristics.
 */
class InteractionRouter
{
    use HttpClientTrait;

    private const ROUTER_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'route' => [
                'type' => 'string',
                'enum' => [
                    InteractionDecision::ROUTE_IGNORE,
                    InteractionDecision::ROUTE_CHAT,
                    InteractionDecision::ROUTE_MCP,
                    InteractionDecision::ROUTE_AGENT,
                ],
            ],
            'tone' => [
                'type' => 'string',
                'enum' => [
                    InteractionDecision::TONE_NEUTRAL,
                    InteractionDecision::TONE_WITTY,
                ],
            ],
            'intent' => [
                'type' => 'string',
                'enum' => [
                    'analytics',
                    'question',
                    'capabilities',
                    'image',
                    'banter',
                    'chat',
                    'schedule_task',
                    'memory_read',
                    'memory_write',
                    'web_search',
                    'datetime',
                ],
            ],
            'confidence' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 100,
            ],
            'analytics_confidence' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 100,
            ],
            'image_intent' => [
                'type' => 'string',
                'enum' => [
                    InteractionDecision::IMAGE_INTENT_NONE,
                    InteractionDecision::IMAGE_INTENT_ANALYZE_ONLY,
                    InteractionDecision::IMAGE_INTENT_GENERATE_OR_EDIT,
                ],
            ],
            'cleaned_prompt' => [
                'type' => 'string',
            ],
            'reason' => [
                'type' => 'string',
            ],
        ],
        'required' => [
            'route',
            'tone',
            'intent',
            'confidence',
            'analytics_confidence',
            'image_intent',
            'cleaned_prompt',
            'reason',
        ],
        'additionalProperties' => false,
    ];

    private BotIdentityContext $identityContext;
    private LoggerService $logger;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(BotIdentityContext $identityContext, LoggerService $logger, array $config)
    {
        $this->identityContext = $identityContext;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function isAddressedToBot(string $messageText, bool $isReplyToBot = false): bool
    {
        if ($isReplyToBot) {
            return true;
        }

        return $this->identityContext->isAddressedIn($messageText);
    }

    public function decide(
        string $messageText,
        bool $isReplyToBot = false,
        bool $hasPhoto = false,
        bool $allowBotTopicRouting = false,
        bool $agentEnabled = false
    ): InteractionDecision {
        $original = trim($messageText);
        $cleanedPrompt = $this->identityContext->stripLeadingAddress($original);
        $addressedToBot = $this->isAddressedToBot($original, $isReplyToBot);

        if (!$addressedToBot && !$allowBotTopicRouting) {
            return new InteractionDecision(
                InteractionDecision::ROUTE_IGNORE,
                InteractionDecision::TONE_NEUTRAL,
                'chat',
                10,
                false,
                0,
                $hasPhoto ? InteractionDecision::IMAGE_INTENT_ANALYZE_ONLY : InteractionDecision::IMAGE_INTENT_NONE,
                $cleanedPrompt !== '' ? $cleanedPrompt : $original,
                'Message is outside bot-addressed and bot-topic routing scope.'
            );
        }

        if ($original === '' && !$isReplyToBot) {
            return new InteractionDecision(
                InteractionDecision::ROUTE_IGNORE,
                InteractionDecision::TONE_NEUTRAL,
                'chat',
                10,
                $addressedToBot,
                0,
                $hasPhoto ? InteractionDecision::IMAGE_INTENT_ANALYZE_ONLY : InteractionDecision::IMAGE_INTENT_NONE,
                '',
                'Empty text without a direct reply does not need a conversational route.'
            );
        }

        if ($agentEnabled && ($addressedToBot || $allowBotTopicRouting) && $this->looksLikeMemoryMutationRequest($original)) {
            return new InteractionDecision(
                InteractionDecision::ROUTE_AGENT,
                InteractionDecision::TONE_NEUTRAL,
                'memory_write',
                90,
                $addressedToBot,
                0,
                $hasPhoto ? InteractionDecision::IMAGE_INTENT_ANALYZE_ONLY : InteractionDecision::IMAGE_INTENT_NONE,
                $cleanedPrompt !== '' ? $cleanedPrompt : $original,
                'Message is an explicit request to correct, delete, or forget stored memory.'
            );
        }

        $semanticDecision = $this->routeSemantically(
            $original,
            $cleanedPrompt,
            $addressedToBot,
            $isReplyToBot,
            $hasPhoto,
            $allowBotTopicRouting,
            $agentEnabled
        );

        if ($semanticDecision !== null) {
            return $semanticDecision;
        }

        return $this->buildConservativeFallbackDecision(
            $original,
            $cleanedPrompt,
            $addressedToBot,
            $hasPhoto,
            $agentEnabled
        );
    }

    private function routeSemantically(
        string $original,
        string $cleanedPrompt,
        bool $addressedToBot,
        bool $isReplyToBot,
        bool $hasPhoto,
        bool $allowBotTopicRouting,
        bool $agentEnabled
    ): ?InteractionDecision {
        $model = (string)($this->config['openrouter_chat_model'] ?? '');
        if ($model === '') {
            return null;
        }

        $prompt = $this->buildSemanticRouterPrompt();
        $input = [
            'original_message' => $original,
            'cleaned_guess' => $cleanedPrompt,
            'addressed_to_bot' => $addressedToBot,
            'reply_to_bot' => $isReplyToBot,
            'has_photo' => $hasPhoto,
            'bot_topic_context' => $allowBotTopicRouting,
            'agent_enabled' => $agentEnabled,
            'bot_capabilities' => $this->identityContext->getCapabilities(),
            'bot_commands' => $this->identityContext->getCommandHelpSnippets(),
        ];

        $params = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $prompt,
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'interaction_route',
                    'strict' => true,
                    'schema' => self::ROUTER_SCHEMA,
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 350,
        ];

        $body = $this->makeOpenRouterRequest($this->config, $params, 'InteractionRouter', 20);
        if (!isset($body['choices'][0]['message']['content'])) {
            return null;
        }

        $decoded = json_decode((string)$body['choices'][0]['message']['content'], true);
        if (!is_array($decoded)) {
            $this->logger->logWebhook('Semantic router returned invalid JSON payload.');
            return null;
        }

        return $this->normalizeSemanticDecision(
            $decoded,
            $original,
            $cleanedPrompt,
            $addressedToBot,
            $hasPhoto,
            $agentEnabled
        );
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function normalizeSemanticDecision(
        array $decoded,
        string $original,
        string $cleanedPrompt,
        bool $addressedToBot,
        bool $hasPhoto,
        bool $agentEnabled
    ): InteractionDecision {
        $route = (string)($decoded['route'] ?? InteractionDecision::ROUTE_CHAT);
        if (!in_array($route, [
            InteractionDecision::ROUTE_IGNORE,
            InteractionDecision::ROUTE_CHAT,
            InteractionDecision::ROUTE_MCP,
            InteractionDecision::ROUTE_AGENT,
        ], true)) {
            $route = InteractionDecision::ROUTE_CHAT;
        }

        if ($route === InteractionDecision::ROUTE_AGENT && !$agentEnabled) {
            $route = InteractionDecision::ROUTE_CHAT;
        }

        $tone = (string)($decoded['tone'] ?? InteractionDecision::TONE_NEUTRAL);
        if (!in_array($tone, [InteractionDecision::TONE_NEUTRAL, InteractionDecision::TONE_WITTY], true)) {
            $tone = InteractionDecision::TONE_NEUTRAL;
        }

        $intent = (string)($decoded['intent'] ?? 'chat');
        if ($intent === '') {
            $intent = 'chat';
        }

        $confidence = max(0, min(100, (int)($decoded['confidence'] ?? 60)));
        $analyticsConfidence = max(0, min(100, (int)($decoded['analytics_confidence'] ?? 0)));
        $imageIntent = (string)($decoded['image_intent'] ?? InteractionDecision::IMAGE_INTENT_NONE);
        if (!in_array($imageIntent, [
            InteractionDecision::IMAGE_INTENT_NONE,
            InteractionDecision::IMAGE_INTENT_ANALYZE_ONLY,
            InteractionDecision::IMAGE_INTENT_GENERATE_OR_EDIT,
        ], true)) {
            $imageIntent = $hasPhoto
                ? InteractionDecision::IMAGE_INTENT_ANALYZE_ONLY
                : InteractionDecision::IMAGE_INTENT_NONE;
        }

        if ($route === InteractionDecision::ROUTE_MCP) {
            $intent = 'analytics';
            $tone = InteractionDecision::TONE_NEUTRAL;
            $analyticsConfidence = max(75, $analyticsConfidence);
            $confidence = max(80, $confidence);
        }

        $cleaned = trim((string)($decoded['cleaned_prompt'] ?? ''));
        if ($cleaned === '') {
            $cleaned = $cleanedPrompt !== '' ? $cleanedPrompt : $original;
        }

        $reason = trim((string)($decoded['reason'] ?? 'Semantic router decision.'));
        if ($reason === '') {
            $reason = 'Semantic router decision.';
        }

        return new InteractionDecision(
            $route,
            $tone,
            $intent,
            $confidence,
            $addressedToBot,
            $analyticsConfidence,
            $imageIntent,
            $cleaned,
            $reason
        );
    }

    private function looksLikeMemoryMutationRequest(string $messageText): bool
    {
        $normalized = mb_strtolower($messageText);
        $markers = [
            'забуд',
            'удали из памяти',
            'убери из памяти',
            'сотри из памяти',
            'почисти память',
            'неправильный факт',
            'неверный факт',
            'ложный факт',
            'это неправда',
            'это неверно',
            'это была цитата',
            'это была шутка',
            'это была новость',
            'я не удалял',
            'не про меня',
            'forget',
            'delete from memory',
            'remove from memory',
            'wrong fact',
            'false fact',
            'not true',
            'that was a quote',
            'that was a joke',
        ];

        foreach ($markers as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function buildConservativeFallbackDecision(
        string $original,
        string $cleanedPrompt,
        bool $addressedToBot,
        bool $hasPhoto,
        bool $agentEnabled
    ): InteractionDecision {
        $route = $addressedToBot ? InteractionDecision::ROUTE_CHAT : InteractionDecision::ROUTE_IGNORE;
        $intent = $agentEnabled && $addressedToBot ? 'chat' : 'chat';
        $imageIntent = $hasPhoto
            ? InteractionDecision::IMAGE_INTENT_ANALYZE_ONLY
            : InteractionDecision::IMAGE_INTENT_NONE;

        return new InteractionDecision(
            $route,
            InteractionDecision::TONE_NEUTRAL,
            $intent,
            $addressedToBot ? 55 : 10,
            $addressedToBot,
            0,
            $imageIntent,
            $cleanedPrompt !== '' ? $cleanedPrompt : $original,
            'Fell back to conservative routing because semantic classification was unavailable.'
        );
    }

    private function buildSemanticRouterPrompt(): string
    {
        $capabilities = implode(' | ', $this->identityContext->getCapabilities());
        $commands = implode(' | ', $this->identityContext->getCommandHelpSnippets());

        return <<<PROMPT
You classify Telegram bot messages into a routing decision.

Return JSON only and follow the provided schema exactly.

Available routes:
- ignore: do not reply
- chat: normal conversational reply
- agent: tool-using agent path (web search, datetime, image generation, reminders/tasks, chat memory)
- mcp: analytics/data path for Statbate, ClickHouse, reporting, metrics, top lists, comparisons, counts, trends

Key routing rules:
- If agent_enabled=false, never choose agent. Use chat instead.
- Use mcp only for clear analytics or database-style questions.
- Use agent for requests that should use tools: reminders, schedules, showing tasks, memory read/write, participant profile lookup, web search, latest docs/news, date/time, explicit image generation/editing.
- Questions about what the bot knows or remembers about the current user, another participant, a replied user, a username, or the group should route to agent with intent=memory_read.
- Requests to remember, forget, delete, correct, or mark a stored memory fact as wrong should route to agent with intent=memory_write.
- Corrections like "это неверный факт", "я этого не делал", "это была цитата/шутка/новость", "forget that", or "delete this from memory" are memory_write requests when addressed to the bot.
- Requests like "what do you know about @alice", "бот расскажи про roomahhka", "что ты знаешь о Roman Motovilov", "who is roomahhka here", "what do you remember about this group" should route to agent when agent_enabled=true.
- If addressed_to_bot=false and reply_to_bot=false and bot_topic_context=true, choose agent or mcp only when the message is clearly asking the bot to do tool/analytics work. Otherwise choose ignore.
- For normal conversation, help, or bot capabilities questions, choose chat.
- Prefer tone=neutral for factual, operational, analytics, help, image, task, and memory requests.
- Use tone=witty only for clear banter/jokes/roasting.

Image intent rules:
- generate_or_edit only when the user explicitly wants a new image or wants an image transformed, redrawn, edited, restyled, or generated.
- analyze_only when a photo exists but the user did not explicitly ask to generate/edit an image.
- none otherwise.

Cleaned prompt rules:
- Remove bot-address words when obvious.
- Preserve the user's language.
- Keep the actionable request intact.

Bot capabilities:
{$capabilities}

Bot commands:
{$commands}
PROMPT;
    }
}
