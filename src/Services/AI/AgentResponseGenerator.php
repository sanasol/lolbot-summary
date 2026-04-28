<?php

namespace App\Services\AI;

use App\Providers\OpenRouterAi;
use App\Services\AgentTaskStore;
use App\Services\AgentToolRegistry;
use App\Services\BotIdentityContext;
use App\Services\ChatMemoryStore;
use App\Services\LoggerService;
use App\Services\UsageTracker;
use NeuronAI\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Tool-enabled conversational agent path for web search, datetime, image generation,
 * per-chat memory, and scheduled tasks.
 */
class AgentResponseGenerator
{
    use HttpClientTrait;

    private const PUBLIC_IMAGES_BASE_URL = 'https://sum.statbate.com/src/images/';
    private array $config;
    private PromptBuilder $promptBuilder;
    private ResponseFormatter $formatter;
    private LoggerService $logger;
    private ChatMemoryStore $chatMemoryStore;
    private AgentTaskStore $taskStore;
    private ?BotIdentityContext $botIdentityContext;
    private ?ImageProcessor $imageProcessor;

    public function __construct(
        array $config,
        PromptBuilder $promptBuilder,
        ResponseFormatter $formatter,
        LoggerService $logger,
        ChatMemoryStore $chatMemoryStore,
        AgentTaskStore $taskStore,
        ?BotIdentityContext $botIdentityContext = null,
        ?ImageProcessor $imageProcessor = null
    ) {
        $this->config = $config;
        $this->promptBuilder = $promptBuilder;
        $this->formatter = $formatter;
        $this->logger = $logger;
        $this->chatMemoryStore = $chatMemoryStore;
        $this->taskStore = $taskStore;
        $this->botIdentityContext = $botIdentityContext;
        $this->imageProcessor = $imageProcessor;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(
        string $messageText,
        string $username,
        string $chatContext = '',
        int $chatId = 0,
        ?int $userId = null,
        ?int $threadId = null,
        array $options = []
    ): ?array {
        $startTime = microtime(true);
        $scheduled = (bool)($options['scheduled'] ?? false);
        $chatLanguage = 'en';
        $defaultTimezone = trim((string)($this->config['agent_default_timezone'] ?? 'Europe/Belgrade')) ?: 'Europe/Belgrade';
        if (isset($this->config['settingsService']) && $this->config['settingsService'] !== null) {
            $chatLanguage = $this->config['settingsService']->getSetting($chatId, 'language', 'en');
        }
        $cleanedForLanguage = $this->botIdentityContext?->stripLeadingAddress($messageText) ?? $messageText;
        $cleanedForLanguage = trim($cleanedForLanguage, " \t\n\r\0\x0B,.:;!?");
        $language = $this->detectReplyLanguage($cleanedForLanguage !== '' ? $cleanedForLanguage : $messageText);

        $registry = new AgentToolRegistry(
            $this->config,
            $this->logger,
            $this->chatMemoryStore,
            $this->taskStore,
            [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'username' => $username,
                'thread_id' => $threadId,
                'message_id' => $options['message_id'] ?? null,
                'reply_target_user_id' => $options['reply_target_user_id'] ?? null,
                'scheduled' => $scheduled,
                'default_timezone' => (string)($this->config['agent_default_timezone'] ?? 'Europe/Belgrade'),
            ]
        );

        $provider = new OpenRouterAi(
            key: $this->config['openrouter_key'],
            model: $this->config['openrouter_chat_model'] ?? $this->config['openrouter_tool_model'],
            parameters: [
                'http_timeout' => 45,
            ]
        );

        $memoryContext = $this->chatMemoryStore->buildPromptContext(
            $chatId,
            $userId,
            isset($options['reply_target_user_id']) ? (int)$options['reply_target_user_id'] : null,
            100,
            true
        );

        $systemPrompt = $this->buildSystemPrompt(
            $language,
            $chatLanguage,
            $chatContext,
            $memoryContext,
            $scheduled,
            $defaultTimezone
        );

        try {
            $agent = new Agent();
            $agent
                ->setAiProvider($provider)
                ->setInstructions($systemPrompt)
                ->withChatHistory(new InMemoryChatHistory(40000))
                ->toolMaxTries(6)
                ->addTool($registry->buildTools());

            $response = $agent->chat(UserMessage::make($messageText));
            $normalized = $this->normalizeAgentResponse($response->getContent(), $response->getMetadata('images'), $messageText);
            $stats = $registry->getStats();
            $serverToolUsage = $response->getMetadata('server_tool_use');
            if (is_array($serverToolUsage)) {
                foreach ($serverToolUsage as $counter => $count) {
                    if ((int)$count <= 0) {
                        continue;
                    }

                    $stats['tools_used'][] = match ($counter) {
                        'web_search_requests' => 'openrouter:web_search',
                        'datetime_requests' => 'openrouter:datetime',
                        'image_generation_requests' => 'openrouter:image_generation',
                        default => $counter,
                    };
                }
            }

            $stats['tools_used'] = array_values(array_unique($stats['tools_used']));
            $fallback = $this->maybeHandleScheduleFallback(
                $messageText,
                $chatId,
                $threadId,
                $defaultTimezone,
                $scheduled,
                $options,
                $stats
            );
            if ($fallback !== null) {
                $stats['tools_used'] = array_values(array_unique($stats['tools_used']));
            }
            if ($fallback === null) {
                $fallback = $this->maybeHandleMemoryFallback(
                    $messageText,
                    $chatId,
                    $userId,
                    $scheduled,
                    $options,
                    $stats
                );
                if ($fallback !== null) {
                    $stats['tools_used'] = array_values(array_unique($stats['tools_used']));
                }
            }
            $toolCount = count($stats['tools_used']) + count($stats['tool_errors']);
            $usage = $response->getUsage();

            UsageTracker::track([
                'chat_id' => $chatId,
                'user_id' => $userId,
                'username' => $username,
                'type' => 'agent',
                'model' => $this->config['openrouter_chat_model'] ?? $this->config['openrouter_tool_model'] ?? 'unknown',
                'input_tokens' => $usage?->inputTokens,
                'output_tokens' => $usage?->outputTokens,
                'tool_calls' => $toolCount,
                'duration_s' => round(microtime(true) - $startTime, 2),
                'success' => true,
                'tools_used' => $stats['tools_used'],
                'tool_count' => $toolCount,
                'tool_errors' => $stats['tool_errors'],
                'memory_read_count' => $stats['memory_reads'],
                'memory_write_count' => $stats['memory_writes'],
                'memory_delete_count' => $stats['memory_deletes'] ?? 0,
                'task_writes' => $stats['task_writes'],
                'task_failures' => $stats['task_failures'] ?? 0,
                'task_operation' => $stats['task_operation'],
            ]);

            if ($fallback !== null) {
                return $fallback;
            }

            if ($normalized !== null) {
                $memoryFinalized = $this->maybeFinalizeMemoryResponse(
                    $normalized,
                    $messageText,
                    $chatId,
                    $userId,
                    $options,
                    $stats
                );
                if ($memoryFinalized !== null) {
                    return $memoryFinalized;
                }

                if (($options['image_intent'] ?? null) === \App\Services\InteractionDecision::IMAGE_INTENT_GENERATE_OR_EDIT) {
                    $isImageResponse = (($normalized['type'] ?? null) === 'image') && !empty($normalized['image_url']);
                    if (!$isImageResponse) {
                        $legacyImage = $this->generateLegacyImageFallback(
                            $messageText,
                            isset($options['input_image_url']) ? (string)$options['input_image_url'] : null
                        );
                        if ($legacyImage !== null) {
                            return $legacyImage;
                        }
                    }
                }

                return $normalized;
            }

            if (($options['image_intent'] ?? null) === \App\Services\InteractionDecision::IMAGE_INTENT_GENERATE_OR_EDIT) {
                $legacyImage = $this->generateLegacyImageFallback(
                    $messageText,
                    isset($options['input_image_url']) ? (string)$options['input_image_url'] : null
                );
                if ($legacyImage !== null) {
                    return $legacyImage;
                }

                return $this->formatter->formatTextResponse('I tried to generate an image, but the image tool did not return a usable result.');
            }

            return $this->formatter->formatTextResponse('I could not produce a useful agent response for that request.');
        } catch (\Throwable $e) {
            $this->logger->logError('Agent response generation failed: ' . $e->getMessage(), 'Agent Response', $e);
            UsageTracker::track([
                'chat_id' => $chatId,
                'user_id' => $userId,
                'username' => $username,
                'type' => 'agent',
                'model' => $this->config['openrouter_chat_model'] ?? $this->config['openrouter_tool_model'] ?? 'unknown',
                'duration_s' => round(microtime(true) - $startTime, 2),
                'success' => false,
                'error' => $e->getMessage(),
            ]);

            if (($options['image_intent'] ?? null) === \App\Services\InteractionDecision::IMAGE_INTENT_GENERATE_OR_EDIT) {
                $legacyImage = $this->generateLegacyImageFallback(
                    $messageText,
                    isset($options['input_image_url']) ? (string)$options['input_image_url'] : null
                );
                if ($legacyImage !== null) {
                    return $legacyImage;
                }

                return $this->formatter->formatTextResponse('Image generation failed: ' . $e->getMessage());
            }

            return $this->formatter->formatTextResponse('Agent tools failed for this request: ' . $e->getMessage());
        }
    }

    private function generateLegacyImageFallback(string $messageText, ?string $inputImageUrl): ?array
    {
        if ($this->imageProcessor === null) {
            return null;
        }

        try {
            $this->logger->logWebhook('Agent image request falling back to legacy image processor.');
            $imageResult = $this->imageProcessor->generateImage($messageText, $inputImageUrl);
            if (!is_array($imageResult) || empty($imageResult['url'])) {
                return null;
            }

            $imageUrl = (string)$imageResult['url'];
            if (str_starts_with($imageUrl, 'data:image/')) {
                $imageUrl = $this->saveDataUriImage($imageUrl);
            }

            return $this->formatter->formatImageResponse(
                $imageUrl,
                !empty($imageResult['text_response']) ? (string)$imageResult['text_response'] : null,
                $imageResult['prompt'] ?? null,
                $imageResult['revised_prompt'] ?? null
            );
        } catch (\Throwable $e) {
            $this->logger->logError('Legacy image fallback failed: ' . $e->getMessage(), 'Agent Response', $e);
            return null;
        }
    }

    private function buildSystemPrompt(string $language, string $chatLanguage, string $chatContext, string $memoryContext, bool $scheduled, string $defaultTimezone): string
    {
        $languageInstruction = $language === 'ru'
            ? 'Respond in Russian unless the user clearly asks for another language.'
            : 'Respond in English unless the user clearly asks for another language.';
        $chatLanguageNote = $chatLanguage !== $language
            ? ($chatLanguage === 'ru'
                ? 'The group default language is Russian, but this specific reply should follow the language of the current user request.'
                : 'The group default language is English, but this specific reply should follow the language of the current user request.')
            : '';

        $identityContext = $this->botIdentityContext?->buildPromptContext() ?? '';
        $modeInstruction = $scheduled
            ? 'You are executing a previously scheduled task for this chat. Use only the tools available to produce the requested update and keep the result concise and useful.'
            : 'You are a tool-using Telegram group bot assistant. Use tools when they materially improve the answer.';

        $toolInstruction = $scheduled
            ? 'Available tools may include web search, datetime, and reading chat memory. Do not try to write memory or generate images in scheduled mode.'
            : 'Use web_search for current information, datetime for date/time questions, image_generation for explicit draw/generate image requests, schedule_task for reminders/recurring jobs, get_chat_memory to recall group/user context, get_user_profile for questions about a participant, set_chat_memory for explicit remember requests or very stable low-sensitivity facts, and forget_chat_memory when a stored fact is wrong or should be forgotten.';

        $sections = [
            'You are Apollo, an entertainment-first Telegram group bot with an agent mode.',
            $languageInstruction,
            $modeInstruction,
            $toolInstruction,
            'Your default behavior is to engage with any safe addressed message. Do not hide behind a feature list.',
            'Default timezone for scheduling is ' . $defaultTimezone . '. If the user gives a clear time but no timezone, use this default and create the task without asking a follow-up question.',
            'For reminder or recurring task requests, prefer calling schedule_task directly. Only ask a follow-up question if the user did not provide enough information to determine any schedule at all.',
            'For requests like "in one minute", "через минуту", or similar relative delays including hours, days, weeks, months, and years, create a one-time task immediately using a relative delay or a computed run_at_utc. Do not just promise the reminder in plain text.',
            'When creating a simple reminder task, set delivery_mode=direct and make execution_prompt be the exact reminder text to send later.',
            'Simple reminder tasks will mention the requester when they fire, so keep execution_prompt as the reminder body itself.',
            'For simple reminders, avoid inventing nicknames or changing the user name. If unsure, omit the name and keep the reminder neutral.',
            'When the user asks about existing reminders, due times, or scheduled tasks, call schedule_task with operation=list before answering.',
            'When the user asks what you know about a participant, use get_user_profile. If the message replies to a user, prefer that replied user profile.',
            'Memory context may include all known participant facts. Use it as working context for better answers; do not dump the whole memory unless the user asks to see it.',
            'If the user explicitly asks to show all facts, everything you know, everything you remember, or the full memory for a person/group, enumerate the full relevant fact set compactly instead of summarizing only the top few. Use one bullet per stored fact and do not merge multiple stored facts into one.',
            'When the user says a remembered fact is wrong, false, a quote, news, a joke, or not about them, call forget_chat_memory for the matching fact before answering. If they provide a corrected stable fact, then call set_chat_memory for the replacement.',
            'When you answer from memory, do not dump raw keys, category names, storage-like value lists, or machine labels. Rewrite remembered facts into natural language in the language of the current user request, even if the stored facts were saved in another language.',
            'For participant profiles, synthesize the facts into a short readable mini-profile instead of listing raw fragments, except when the user explicitly asks for all facts.',
            'For group participant overviews, provide a compact, well-formatted digest with several participants and 1-2 useful facts for each one.',
            'Prefer natural phrasing like mini-profiles, not raw bullet dumps copied from memory.',
            'Capabilities and tools describe special integrations, not a ban on normal text composition. You may synthesize labels, titles, tags, one-word descriptors, short lists, rewrites, jokes, examples, and concise creative text when the user asks.',
            'For everyday legal, medical, financial, or safety-adjacent questions, provide a high-level non-professional overview with a brief caveat. Do not present it as professional advice, but do not refuse the whole question just because it touches a regulated topic.',
            'For casual questions like whether memes are punishable, answer practically: a meme itself is not automatically punishable, but risks can appear around extremism, threats, defamation, hate speech, banned symbols, targeted harassment, or other unlawful context; for real risk ask a lawyer.',
            'Do not refuse just because the answer creates new text. Refuse only for unsafe requests, truly unavailable external actions, or missing required information.',
            'Never use self-limiting boilerplate like "as an AI", "I lack legal expertise", "my functions do not include this", "I can only summarize/analyze", or "give me concrete tasks".',
            'If the user complains that the bot is boring, too restricted, or over-instructed, acknowledge it lightly and recover with a useful answer or banter, not a capabilities pitch.',
            'For memory requests that ask you to turn remembered facts into names, titles, tags, labels, or one-word descriptors, read the memory/profile context and synthesize the requested wording. This is allowed.',
            'Never invent tool results. If a tool fails, say so briefly and continue if possible.',
            'Be concise, practical, and chat-friendly.',
        ];

        if ($chatLanguageNote !== '') {
            $sections[] = $chatLanguageNote;
        }
        if ($identityContext !== '') {
            $sections[] = $identityContext;
        }
        if ($memoryContext !== '') {
            $sections[] = $memoryContext;
        }
        if ($chatContext !== '') {
            $sections[] = $chatContext;
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $stats
     */
    private function maybeHandleScheduleFallback(
        string $messageText,
        int $chatId,
        ?int $threadId,
        string $defaultTimezone,
        bool $scheduled,
        array $options,
        array &$stats
    ): ?array {
        if ($scheduled) {
            return null;
        }

        if (($options['intent'] ?? null) !== 'schedule_task') {
            return null;
        }

        if (($stats['task_writes'] ?? 0) > 0) {
            return null;
        }

        if (in_array('schedule_task', $stats['tools_used'] ?? [], true) && (int)($stats['task_failures'] ?? 0) <= 0) {
            return null;
        }

        $cleaned = $this->botIdentityContext?->stripLeadingAddress($messageText) ?? $messageText;
        $cleaned = trim($cleaned, " \t\n\r\0\x0B,.:;!?");
        $language = $this->detectReplyLanguage($cleaned);
        $parsed = $this->interpretScheduleFallback($cleaned, $defaultTimezone);
        if ($parsed === null) {
            return null;
        }

        $operation = (string)($parsed['operation'] ?? 'none');
        if ($operation === 'list') {
            $stats['tools_used'][] = 'schedule_task:fallback_list';
            $stats['task_operation'] = 'list';
            return $this->formatTaskListResponse($chatId, $defaultTimezone, $language);
        }

        if ($operation === 'status') {
            $stats['tools_used'][] = 'schedule_task:fallback_status';
            $stats['task_operation'] = 'list';
            return $this->formatTaskStatusResponse(
                $chatId,
                $defaultTimezone,
                $language,
                trim((string)($parsed['query'] ?? ''))
            );
        }

        if ($operation !== 'create') {
            return null;
        }

        $delaySeconds = (int)($parsed['delay_seconds'] ?? 0);
        $deliveryText = trim((string)($parsed['delivery_text'] ?? ''));
        $title = trim((string)($parsed['title'] ?? ''));
        if ($delaySeconds <= 0 || $deliveryText === '') {
            return null;
        }

        $task = $this->taskStore->createTask([
            'title' => $title !== '' ? $title : mb_substr($deliveryText, 0, 60),
            'execution_prompt' => $deliveryText,
            'delivery_mode' => 'direct',
            'requester_user_id' => $options['user_id'] ?? null,
            'requester_label' => $options['requester_label'] ?? ($options['username'] ?? null),
            'schedule' => [
                'type' => 'delay',
                'delay_seconds' => $delaySeconds,
            ],
            'timezone' => $defaultTimezone,
            'target_chat_id' => $chatId,
            'target_thread_id' => $threadId,
            'enabled' => true,
        ]);

        if ($task === null) {
            return null;
        }

        $stats['tools_used'][] = 'schedule_task:fallback_create';
        $stats['task_writes'] = (int)($stats['task_writes'] ?? 0) + 1;
        $stats['task_operation'] = 'create';

        $confirmation = $language === 'ru'
            ? sprintf(
                'Окей, напомню %s.',
                $this->describeDelayRu($delaySeconds)
            )
            : sprintf(
                'Okay, I will remind you %s.',
                $this->describeDelayEn($delaySeconds)
            );

        return $this->formatter->formatTextResponse($confirmation);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $stats
     */
    private function maybeHandleMemoryFallback(
        string $messageText,
        int $chatId,
        ?int $userId,
        bool $scheduled,
        array $options,
        array &$stats
    ): ?array {
        if ($scheduled) {
            return null;
        }

        if (($options['intent'] ?? null) !== 'memory_read') {
            return null;
        }

        $usedTools = $stats['tools_used'] ?? [];
        if (($stats['memory_reads'] ?? 0) > 0 || in_array('get_chat_memory', $usedTools, true) || in_array('get_user_profile', $usedTools, true)) {
            return null;
        }

        $cleaned = $this->botIdentityContext?->stripLeadingAddress($messageText) ?? $messageText;
        $cleaned = trim($cleaned, " \t\n\r\0\x0B,.:;!?");
        $language = $this->detectReplyLanguage($cleaned);
        $parsed = $this->interpretMemoryFallback(
            $cleaned,
            isset($options['reply_target_user_id']) ? (int)$options['reply_target_user_id'] : null
        );

        if ($parsed === null) {
            return null;
        }

        $operation = (string)($parsed['operation'] ?? 'none');
        if ($operation === 'none') {
            return null;
        }

        if ($operation === 'chat_memory') {
            $facts = $this->chatMemoryStore->getFacts('chat', $chatId, null, null, 100);
            $stats['tools_used'][] = 'memory_fallback:chat';
            $stats['memory_reads'] = (int)($stats['memory_reads'] ?? 0) + 1;
            return $this->formatChatMemoryFallbackResponse($facts, $language);
        }

        if ($operation === 'participants_overview') {
            $stats['tools_used'][] = 'memory_fallback:participants';
            $stats['memory_reads'] = (int)($stats['memory_reads'] ?? 0) + 1;
            return $this->formatParticipantsOverviewFallbackResponse($chatId, $language, $cleaned);
        }

        $profiles = [];
        if ($operation === 'self_profile' && $userId !== null) {
            $profiles = $this->chatMemoryStore->getUserProfiles($chatId, $userId, null, 100);
        } elseif ($operation === 'reply_profile' && isset($options['reply_target_user_id']) && $options['reply_target_user_id'] !== null) {
            $profiles = $this->chatMemoryStore->getUserProfiles($chatId, (int)$options['reply_target_user_id'], null, 100);
        } elseif ($operation === 'user_profile') {
            $profiles = $this->chatMemoryStore->getUserProfiles(
                $chatId,
                null,
                trim((string)($parsed['query'] ?? '')),
                100
            );
        }

        if ($profiles === []) {
            $stats['tools_used'][] = 'memory_fallback:user_lookup';
            $stats['memory_reads'] = (int)($stats['memory_reads'] ?? 0) + 1;
            return $this->formatter->formatTextResponse(
                $language === 'ru'
                    ? 'Я пока не нашёл сохранённый профиль по этому участнику.'
                    : 'I could not find a stored profile for that participant yet.'
            );
        }

        $stats['tools_used'][] = 'memory_fallback:user_lookup';
        $stats['memory_reads'] = (int)($stats['memory_reads'] ?? 0) + 1;
        return $this->formatUserProfileFallbackResponse($profiles, $language, $operation === 'self_profile');
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $options
     * @param array<string, mixed> $stats
     */
    private function maybeFinalizeMemoryResponse(
        array $response,
        string $messageText,
        int $chatId,
        ?int $userId,
        array $options,
        array &$stats
    ): ?array {
        if (($options['intent'] ?? null) !== 'memory_read') {
            return null;
        }

        $cleaned = $this->botIdentityContext?->stripLeadingAddress($messageText) ?? $messageText;
        $cleaned = trim($cleaned, " \t\n\r\0\x0B,.:;!?");
        $language = $this->detectReplyLanguage($cleaned);
        $parsed = $this->interpretMemoryFallback(
            $cleaned,
            isset($options['reply_target_user_id']) ? (int)$options['reply_target_user_id'] : null
        );
        if ($parsed === null) {
            return null;
        }

        $operation = (string)($parsed['operation'] ?? 'none');
        if ($operation === 'participants_overview') {
            $stats['tools_used'][] = 'memory_finalize:participants';
            return $this->formatParticipantsOverviewFallbackResponse($chatId, $language, $cleaned);
        }

        $content = trim((string)($response['content'] ?? ''));
        if (!$this->looksLikeWeakMemoryResponse($content)) {
            return null;
        }

        $stats['tools_used'][] = 'memory_finalize:repair';
        if ($operation === 'chat_memory') {
            $facts = $this->chatMemoryStore->getFacts('chat', $chatId, null, null, 100);
            return $this->formatChatMemoryFallbackResponse($facts, $language);
        }

        $profiles = [];
        if ($operation === 'self_profile' && $userId !== null) {
            $profiles = $this->chatMemoryStore->getUserProfiles($chatId, $userId, null, 100);
        } elseif ($operation === 'reply_profile' && isset($options['reply_target_user_id']) && $options['reply_target_user_id'] !== null) {
            $profiles = $this->chatMemoryStore->getUserProfiles($chatId, (int)$options['reply_target_user_id'], null, 100);
        } elseif ($operation === 'user_profile') {
            $profiles = $this->chatMemoryStore->getUserProfiles($chatId, null, trim((string)($parsed['query'] ?? '')), 100);
        }

        if ($profiles === []) {
            return null;
        }

        return $this->formatUserProfileFallbackResponse($profiles, $language, $operation === 'self_profile');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function interpretScheduleFallback(string $messageText, string $defaultTimezone): ?array
    {
        $model = (string)($this->config['openrouter_chat_model'] ?? '');
        if ($model === '') {
            return null;
        }

        $params = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->buildScheduleFallbackPrompt($defaultTimezone),
                ],
                [
                    'role' => 'user',
                    'content' => $messageText,
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'schedule_fallback',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'operation' => [
                                'type' => 'string',
                                'enum' => ['create', 'list', 'status', 'none'],
                            ],
                            'delay_seconds' => [
                                'type' => ['integer', 'null'],
                            ],
                            'delivery_text' => [
                                'type' => ['string', 'null'],
                            ],
                            'title' => [
                                'type' => ['string', 'null'],
                            ],
                            'query' => [
                                'type' => ['string', 'null'],
                            ],
                            'language' => [
                                'type' => 'string',
                                'enum' => ['en', 'ru', 'other'],
                            ],
                            'reason' => [
                                'type' => 'string',
                            ],
                        ],
                        'required' => [
                            'operation',
                            'delay_seconds',
                            'delivery_text',
                            'title',
                            'query',
                            'language',
                            'reason',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 220,
        ];

        $body = $this->makeOpenRouterRequest($this->config, $params, 'AgentScheduleFallback', 20);
        if (!isset($body['choices'][0]['message']['content'])) {
            return null;
        }

        $decoded = json_decode((string)$body['choices'][0]['message']['content'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function interpretMemoryFallback(string $messageText, ?int $replyTargetUserId): ?array
    {
        $model = (string)($this->config['openrouter_chat_model'] ?? '');
        if ($model === '') {
            return null;
        }

        $params = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->buildMemoryFallbackPrompt($replyTargetUserId !== null),
                ],
                [
                    'role' => 'user',
                    'content' => $messageText,
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'memory_fallback',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'operation' => [
                                'type' => 'string',
                                'enum' => ['self_profile', 'user_profile', 'reply_profile', 'chat_memory', 'participants_overview', 'none'],
                            ],
                            'query' => [
                                'type' => ['string', 'null'],
                            ],
                            'reason' => [
                                'type' => 'string',
                            ],
                        ],
                        'required' => ['operation', 'query', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 180,
        ];

        $body = $this->makeOpenRouterRequest($this->config, $params, 'AgentMemoryFallback', 20);
        if (!isset($body['choices'][0]['message']['content'])) {
            return null;
        }

        $decoded = json_decode((string)$body['choices'][0]['message']['content'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private function formatTaskListResponse(int $chatId, string $defaultTimezone, string $language): array
    {
        $tasks = array_values(array_filter(
            $this->taskStore->listTasks($chatId),
            static fn (array $task): bool => (bool)($task['enabled'] ?? false)
        ));

        if ($tasks === []) {
            return $this->formatter->formatTextResponse(
                $language === 'ru'
                    ? 'Сейчас активных запланированных задач нет.'
                    : 'There are no active scheduled tasks right now.'
            );
        }

        usort($tasks, static function (array $a, array $b): int {
            return ((int)($a['next_run_at'] ?? PHP_INT_MAX)) <=> ((int)($b['next_run_at'] ?? PHP_INT_MAX));
        });

        $lines = [];
        foreach (array_slice($tasks, 0, 5) as $task) {
            $when = $this->formatTaskTime($task, $defaultTimezone);
            $title = trim((string)($task['title'] ?? 'Scheduled task'));
            $lines[] = '• ' . $title . ($when !== '' ? ' — ' . $when : '');
        }

        $prefix = $language === 'ru'
            ? 'Сейчас запланировано:'
            : 'Currently scheduled:';

        return $this->formatter->formatTextResponse($prefix . "\n" . implode("\n", $lines));
    }

    private function formatTaskStatusResponse(int $chatId, string $defaultTimezone, string $language, string $messageText): array
    {
        $tasks = array_values(array_filter(
            $this->taskStore->listTasks($chatId),
            static fn (array $task): bool => (bool)($task['enabled'] ?? false)
        ));

        if ($tasks === []) {
            return $this->formatter->formatTextResponse(
                $language === 'ru'
                    ? 'Активных напоминаний сейчас нет.'
                    : 'There are no active reminders right now.'
            );
        }

        $queryTokens = $this->extractTaskQueryTokens($messageText);
        usort($tasks, static function (array $a, array $b): int {
            return ((int)($a['next_run_at'] ?? PHP_INT_MAX)) <=> ((int)($b['next_run_at'] ?? PHP_INT_MAX));
        });

        $bestTask = $tasks[0];
        $bestScore = -1;
        foreach ($tasks as $task) {
            $haystack = mb_strtolower(trim(((string)($task['title'] ?? '')) . ' ' . ((string)($task['execution_prompt'] ?? ''))));
            $score = 0;
            foreach ($queryTokens as $token) {
                if ($token !== '' && str_contains($haystack, $token)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTask = $task;
            }
        }

        $when = $this->formatTaskTime($bestTask, $defaultTimezone);
        $text = $language === 'ru'
            ? 'Следующее подходящее напоминание: ' . trim((string)($bestTask['title'] ?? 'задача')) . ($when !== '' ? ' — ' . $when : '')
            : 'The closest matching reminder is: ' . trim((string)($bestTask['title'] ?? 'scheduled task')) . ($when !== '' ? ' — ' . $when : '');

        return $this->formatter->formatTextResponse($text);
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     */
    private function formatChatMemoryFallbackResponse(array $facts, string $language): array
    {
        if ($facts === []) {
            return $this->formatter->formatTextResponse(
                $language === 'ru'
                    ? 'Пока у меня нет сохранённых устойчивых фактов об этой группе.'
                    : 'I do not have any stored durable facts about this group yet.'
            );
        }

        $rendered = $this->renderMemoryNarrative(
            $language,
            'chat_memory',
            ['facts' => $facts]
        );
        if ($rendered !== null) {
            return $this->formatter->formatTextResponse($rendered);
        }

        $lines = [];
        foreach ($facts as $fact) {
            $humanized = $this->humanizeFact($fact, $language);
            if ($humanized !== null) {
                $lines[] = '• ' . $humanized;
            }
        }

        if ($lines === []) {
            $lines[] = '• ' . ($language === 'ru' ? 'есть сохранённые факты, но они пока плохо сформулированы' : 'there are stored facts, but they are not phrased well yet');
        }

        $prefix = $language === 'ru'
            ? 'Вот что я помню об этой группе:'
            : 'Here is what I remember about this group:';

        return $this->formatter->formatTextResponse($prefix . "\n" . implode("\n", $lines));
    }

    /**
     * @param array<int, array<string, mixed>> $profiles
     */
    private function formatUserProfileFallbackResponse(array $profiles, string $language, bool $selfProfile): array
    {
        if ($profiles === []) {
            return $this->formatter->formatTextResponse(
                $language === 'ru'
                    ? 'Пока у меня нет устойчивых фактов по этому профилю.'
                    : 'I do not have any durable facts for that profile yet.'
            );
        }

        $profile = $profiles[0];
        $meta = is_array($profile['profile'] ?? null) ? $profile['profile'] : [];
        $facts = is_array($profile['facts'] ?? null) ? $profile['facts'] : [];
        $displayName = trim((string)($meta['display_name'] ?? $meta['first_name'] ?? 'this user'));

        if ($facts === []) {
            return $this->formatter->formatTextResponse(
                $language === 'ru'
                    ? ($selfProfile ? 'Я вижу твой профиль в чате, но пока не накопил о тебе устойчивых фактов.' : 'Я вижу профиль этого участника, но пока не накопил о нём устойчивых фактов.')
                    : ($selfProfile ? 'I can see your profile in the chat, but I have not accumulated durable facts about you yet.' : 'I can see this participant in the chat, but I have not accumulated durable facts about them yet.')
            );
        }

        $rendered = $this->renderMemoryNarrative(
            $language,
            $selfProfile ? 'self_profile' : 'user_profile',
            [
                'display_name' => $displayName,
                'facts' => $facts,
            ]
        );
        if ($rendered !== null) {
            return $this->formatter->formatTextResponse($rendered);
        }

        $lines = [];
        foreach ($facts as $fact) {
            $humanized = $this->humanizeFact($fact, $language);
            if ($humanized === null) {
                continue;
            }
            $lines[] = '• ' . $humanized;
        }

        if ($lines === []) {
            $lines[] = '• ' . ($language === 'ru' ? 'есть профиль, но без читаемых фактов' : 'profile exists but has no readable facts yet');
        }

        $prefix = $language === 'ru'
            ? ($selfProfile ? 'Вот что я помню о тебе:' : 'Вот что я помню о ' . $displayName . ':')
            : ($selfProfile ? 'Here is what I remember about you:' : 'Here is what I remember about ' . $displayName . ':');

        return $this->formatter->formatTextResponse($prefix . "\n" . implode("\n", $lines));
    }

    private function formatParticipantsOverviewFallbackResponse(int $chatId, string $language, string $request = ''): array
    {
        $memory = $this->chatMemoryStore->getMemory($chatId);
        $profiles = [];

        foreach (($memory['user_facts'] ?? []) as $userId => $facts) {
            if (!is_array($facts) || $facts === []) {
                continue;
            }

            $presentableFacts = [];
            foreach ($facts as $fact) {
                if (!is_array($fact)) {
                    continue;
                }

                $humanized = $this->humanizeFact($fact, $language);
                if ($humanized !== null) {
                    $presentableFacts[] = [
                        'humanized' => $humanized,
                        'category' => $fact['category'] ?? null,
                        'key' => $fact['key'] ?? null,
                        'value' => $fact['value'] ?? null,
                    ];
                }
            }

            if ($presentableFacts === []) {
                continue;
            }

            $directory = is_array($memory['user_directory'][$userId] ?? null) ? $memory['user_directory'][$userId] : [];
            $profiles[] = [
                'user_id' => (int)$userId,
                'display_name' => trim((string)($directory['display_name'] ?? $directory['first_name'] ?? $userId)),
                'facts' => $presentableFacts,
                'fact_count' => count($presentableFacts),
                'last_seen_at' => (int)($directory['last_seen_at'] ?? 0),
            ];
        }

        if ($profiles === []) {
            return $this->formatter->formatTextResponse(
                $language === 'ru'
                    ? 'Пока у меня нет достаточно качественных сохранённых профилей участников этой группы.'
                    : 'I do not have enough good saved participant profiles for this group yet.'
            );
        }

        usort($profiles, static function (array $a, array $b): int {
            if (($a['fact_count'] ?? 0) === ($b['fact_count'] ?? 0)) {
                return ((int)($b['last_seen_at'] ?? 0)) <=> ((int)($a['last_seen_at'] ?? 0));
            }

            return ((int)($b['fact_count'] ?? 0)) <=> ((int)($a['fact_count'] ?? 0));
        });

        $rendered = $this->renderMemoryNarrative(
            $language,
            'participants_overview',
            [
                'request' => $request,
                'participants' => $profiles,
            ]
        );
        if ($rendered !== null) {
            return $this->formatter->formatTextResponse($rendered);
        }

        $lines = [];
        foreach ($profiles as $profile) {
            $factTexts = [];
            foreach (($profile['facts'] ?? []) as $fact) {
                $factTexts[] = trim((string)($fact['humanized'] ?? ''));
            }
            $factTexts = array_values(array_filter($factTexts, static fn (string $text): bool => $text !== ''));
            if ($factTexts === []) {
                continue;
            }

            $lines[] = '• ' . ($profile['display_name'] ?? 'participant') . ': ' . implode('; ', array_slice($factTexts, 0, 2));
        }

        $prefix = $language === 'ru'
            ? 'Вот что я помню об участниках группы:'
            : 'Here is what I remember about the group participants:';

        return $this->formatter->formatTextResponse($prefix . "\n" . implode("\n", $lines));
    }

    private function extractTaskQueryTokens(string $messageText): array
    {
        $rawTokens = $this->tokenizeLookupText($messageText);
        $stopwords = [
            'бот', 'apollo', 'когда', 'пора', 'делать', 'будет', 'готов', 'напомни', 'покажи',
            'when', 'time', 'show', 'list', 'scheduled', 'task', 'tasks', 'reminder', 'reminders',
        ];

        $tokens = [];
        foreach ($rawTokens as $token) {
            if (mb_strlen($token) < 3 || in_array($token, $stopwords, true)) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeLookupText(string $messageText): array
    {
        $normalized = mb_strtolower($messageText);
        $normalized = str_replace([
            "\n", "\r", "\t", ',', '.', ':', ';', '!', '?', '"', "'", '(', ')', '[', ']', '{', '}',
            '<', '>', '/', '\\', '-', '_', '—', '–', '«', '»',
        ], ' ', $normalized);

        while (str_contains($normalized, '  ')) {
            $normalized = str_replace('  ', ' ', $normalized);
        }

        $parts = explode(' ', trim($normalized));
        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function detectReplyLanguage(string $text): string
    {
        $length = mb_strlen($text);
        for ($index = 0; $index < $length; $index++) {
            $char = mb_substr($text, $index, 1);
            $codepoint = mb_ord($char, 'UTF-8');
            if (($codepoint >= 0x0400 && $codepoint <= 0x04FF) || $codepoint === 0x0451 || $codepoint === 0x0401) {
                return 'ru';
            }
        }

        return 'en';
    }

    private function looksLikeWeakMemoryResponse(string $content): bool
    {
        if ($content === '') {
            return true;
        }

        $normalized = mb_strtolower($content);
        $markers = [
            'у меня нет сохран',
            'пока у меня нет',
            'не нашёл сохран',
            'не нашел сохран',
            'не могу сгенер',
            'не могу придум',
            'креативного контента',
            'нового контента',
            'за рамки моих возможностей',
            'i do not have any stored',
            'i do not have enough',
            'i could not find a stored',
            'i can tell you about individual participants',
            'i cannot generate',
            'i can not generate',
            'creative content',
            'new content',
            'beyond my capabilities',
        ];

        foreach ($markers as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function buildScheduleFallbackPrompt(string $defaultTimezone): string
    {
        return 'Interpret a Telegram user request about reminders or scheduled tasks. ' .
            'This is a fallback only when the main tool call was missed. ' .
            'Work across any language. ' .
            'Return operation=create only when the user clearly wants a reminder/task and gave an explicit relative delay like "in 5 minutes", "через 10 минут", "in 2 weeks", or "через год". ' .
            'Return operation=list when the user wants to see scheduled tasks/reminders. ' .
            'Return operation=status when the user asks when a specific reminder/task will happen. ' .
            'Return operation=none for anything else. ' .
            'If operation=create, compute delay_seconds for the requested relative delay, produce a short title, and produce delivery_text as the reminder body to send later. ' .
            'Do not include bot names or scheduling words in delivery_text. ' .
            'Default timezone is ' . $defaultTimezone . '.';
    }

    private function buildMemoryFallbackPrompt(bool $hasReplyTarget): string
    {
        $replyHint = $hasReplyTarget
            ? 'A replied user is available, so operation=reply_profile is allowed when the user asks about that replied person.'
            : 'There is no replied user available, so do not choose reply_profile.';

        return 'Interpret a Telegram request about stored memory, participant profiles, or what the bot knows about someone. ' .
            'Work across any language. ' .
            'Return self_profile when the user asks what you know or remember about themself. ' .
            'Return user_profile when the user asks about another participant by name, username, handle, or identifier. ' .
            'Return reply_profile when the request is about the replied user. ' .
            'Return chat_memory when the user asks what you know or remember about the group/chat. ' .
            'Return participants_overview when the user asks what you know about the members or participants of the group in general. ' .
            'Return participants_overview when the user asks to name, title, tag, label, summarize, or describe multiple users/participants from memory, including one-word descriptors. ' .
            'Return none for anything else. ' .
            $replyHint . ' ' .
            'Examples that should map to user_profile: "расскажи про roomahhka", "что ты знаешь о Roman Motovilov", "what do you know about @alice", "who is Bob here". ' .
            'Examples that should map to chat_memory: "что ты помнишь об этой группе", "what do you remember about this chat". ' .
            'Examples that should map to participants_overview: "что ты знаешь об участниках группы", "what do you know about the people here", "назови участников по памяти", "дай тайтлы участникам", "extract one word for each user from memory".';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderMemoryNarrative(string $language, string $mode, array $payload): ?string
    {
        $model = (string)($this->config['openrouter_chat_model'] ?? '');
        if ($model === '') {
            return null;
        }

        $targetLanguage = $language === 'ru' ? 'Russian' : 'English';
        $payloadJson = json_encode([
            'mode' => $mode,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $payloadJson = is_string($payloadJson) ? $payloadJson : '{}';
        $maxTokens = max(320, min(1800, 280 + intdiv(mb_strlen($payloadJson), 20)));
        $params = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Rewrite stored memory facts into a concise, natural Telegram reply. ' .
                        'Respond only in ' . $targetLanguage . '. ' .
                        'Translate facts into ' . $targetLanguage . ' when the stored memory is in another language, but preserve names, usernames, and proper nouns. ' .
                        'Never expose raw internal keys, categories, or machine-oriented formatting. ' .
                        'Ground the reply in the provided facts. You may synthesize wording, labels, titles, tags, and one-word descriptors from those facts when the request asks for that. ' .
                        'Do not refuse just because the answer creates new text. ' .
                        'If the request asks for all facts, everything known, everything remembered, or full memory, include every relevant fact from the payload in compact natural-language bullets. Use one bullet per stored fact and do not merge several stored facts into one. ' .
                        'Prefer a short natural mini-profile over raw fragments. ' .
                        'If the mode is participants_overview and the request asks for titles, tags, labels, names, or one-word descriptors, provide one concise title/tag/label per participant plus a very short rationale when useful. ' .
                        'If the mode is participants_overview and no label-style request is present, provide a compact overview of several participants with 1-2 useful facts each, formatted for easy scanning in Telegram.',
                ],
                [
                    'role' => 'user',
                    'content' => $payloadJson,
                ],
            ],
            'temperature' => 0.2,
            'max_tokens' => $maxTokens,
        ];

        $body = $this->makeOpenRouterRequest($this->config, $params, 'MemoryNarrativeRender', 20);
        if (!isset($body['choices'][0]['message']['content'])) {
            return null;
        }

        $content = trim((string)$body['choices'][0]['message']['content']);
        return $content !== '' ? $content : null;
    }

    /**
     * @param array<string, mixed> $fact
     */
    private function humanizeFact(array $fact, string $language): ?string
    {
        $category = strtolower(trim((string)($fact['category'] ?? '')));
        $key = strtolower(trim((string)($fact['key'] ?? '')));
        $value = trim((string)($fact['value'] ?? ''));
        if ($value === '') {
            return null;
        }

        if ($category === 'location') {
            $normalized = mb_strtolower($value);
            if (str_starts_with($normalized, 'lives in ')) {
                $place = trim(substr($value, 9));
                return $language === 'ru' ? 'живёт в ' . $place : 'lives in ' . $place;
            }
            if (str_starts_with($normalized, 'живет в ')) {
                $place = trim(substr($value, strlen('живет в ')));
                return $language === 'ru' ? 'живёт в ' . $place : 'lives in ' . $place;
            }
            return $language === 'ru' ? 'живёт в ' . $value : 'lives in ' . $value;
        }

        if ($category === 'language_pref') {
            $normalized = mb_strtolower($value);
            return match ($normalized) {
                'ru', 'russian' => $language === 'ru' ? 'предпочитает русский язык' : 'prefers Russian',
                'en', 'english' => $language === 'ru' ? 'предпочитает английский язык' : 'prefers English',
                default => $language === 'ru' ? 'предпочитает язык: ' . $value : 'prefers language: ' . $value,
            };
        }

        if ($category === 'name_pref') {
            return $language === 'ru' ? 'предпочитает имя ' . $value : 'goes by ' . $value;
        }

        if ($category === 'interest') {
            if (in_array(mb_strtolower($value), ['true', 'false'], true)) {
                $topic = $this->humanizeSlug($key, 'interest_');
                return $topic !== '' ? ($language === 'ru' ? 'интересуется ' . $topic : 'is interested in ' . $topic) : null;
            }

            return $language === 'ru' ? 'интересуется ' . $value : 'is interested in ' . $value;
        }

        if ($category === 'expertise') {
            if (in_array(mb_strtolower($value), ['true', 'false'], true)) {
                $topic = $this->humanizeSlug($key);
                if ($topic === '') {
                    return null;
                }
                if (str_starts_with($topic, 'not ')) {
                    $topic = trim(substr($topic, 4));
                    return $language === 'ru' ? 'не считает себя экспертом в ' . $topic : 'does not consider themselves an expert in ' . $topic;
                }
                return $language === 'ru' ? 'разбирается в ' . $topic : 'knows about ' . $topic;
            }

            return $language === 'ru' ? 'разбирается в ' . $value : 'knows about ' . $value;
        }

        if ($category === 'background') {
            if ($key === 'os_preference') {
                return $language === 'ru' ? 'предпочитает ' . $value : 'prefers ' . $value;
            }

            return $language === 'ru' ? 'упоминал, что ' . $this->translateCommonMemoryValue($value, $language) : 'mentioned that ' . $this->translateCommonMemoryValue($value, $language);
        }

        if ($category === 'group_purpose' || $category === 'group_rule') {
            if (in_array(mb_strtolower($value), ['true', 'false'], true)) {
                $topic = $this->humanizeSlug($key, $category === 'group_purpose' ? 'topic_' : 'rule_');
                return $topic !== '' ? $topic : null;
            }

            return $value;
        }

        if (in_array(mb_strtolower($value), ['true', 'false'], true)) {
            $topic = $this->humanizeSlug($key);
            return $topic !== '' ? $topic : null;
        }

        return $this->translateCommonMemoryValue($value, $language);
    }

    private function humanizeSlug(string $value, string $prefixToTrim = ''): string
    {
        $value = trim($value);
        if ($prefixToTrim !== '' && str_starts_with($value, $prefixToTrim)) {
            $value = substr($value, strlen($prefixToTrim));
        }

        $value = str_replace('_', ' ', $value);
        return trim($value);
    }

    private function translateCommonMemoryValue(string $value, string $language): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        if ($language !== 'ru') {
            return $trimmed;
        }

        $lower = mb_strtolower($trimmed);
        return match (true) {
            $lower === 'worked two jobs simultaneously for 3-4 months' => 'работал на двух работах одновременно 3-4 месяца',
            $lower === 'android' => 'Android',
            $lower === 'ios' => 'iOS',
            $lower === 'english' => 'английский',
            $lower === 'russian' => 'русский',
            str_contains($lower, 'worked two jobs simultaneously') => 'работал на двух работах одновременно',
            str_contains($lower, 'for 3-4 months') => 'в течение 3-4 месяцев',
            str_contains($lower, 'lives in ') => 'живёт в ' . trim(substr($trimmed, 9)),
            default => $trimmed,
        };
    }

    private function formatTaskTime(array $task, string $defaultTimezone): string
    {
        $nextRunAt = isset($task['next_run_at']) ? (int)$task['next_run_at'] : null;
        if ($nextRunAt === null || $nextRunAt <= 0) {
            return '';
        }

        $tzName = trim((string)($task['timezone'] ?? $defaultTimezone)) ?: $defaultTimezone;
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new \DateTimeZone($defaultTimezone);
        }

        return (new \DateTimeImmutable('@' . $nextRunAt))
            ->setTimezone($tz)
            ->format('Y-m-d H:i');
    }

    private function describeDelayRu(int $delaySeconds): string
    {
        if ($delaySeconds % 3600 === 0) {
            $hours = (int)($delaySeconds / 3600);
            return $hours === 1 ? 'через час' : "через {$hours} ч.";
        }

        if ($delaySeconds % 60 === 0) {
            $minutes = (int)($delaySeconds / 60);
            return $minutes === 1 ? 'через минуту' : "через {$minutes} минут";
        }

        return "через {$delaySeconds} сек.";
    }

    private function describeDelayEn(int $delaySeconds): string
    {
        if ($delaySeconds % 3600 === 0) {
            $hours = (int)($delaySeconds / 3600);
            return $hours === 1 ? 'in one hour' : "in {$hours} hours";
        }

        if ($delaySeconds % 60 === 0) {
            $minutes = (int)($delaySeconds / 60);
            return $minutes === 1 ? 'in one minute' : "in {$minutes} minutes";
        }

        return "in {$delaySeconds} seconds";
    }

    /**
     * @param array<int, mixed>|string|int|float|null $content
     * @param mixed $images
     */
    private function normalizeAgentResponse(array|string|int|float|null $content, mixed $images, string $messageText = ''): ?array
    {
        $text = $this->extractText($content);
        $imageUrl = $this->extractImageUrl($images, $content);

        if ($imageUrl !== null) {
            if (str_starts_with($imageUrl, 'data:image/')) {
                try {
                    $imageUrl = $this->saveDataUriImage($imageUrl);
                } catch (\Throwable $e) {
                    $this->logger->logError('Failed to persist agent-generated image: ' . $e->getMessage(), 'Agent Response', $e);
                    return $this->formatter->formatTextResponse('Image generation succeeded, but I could not save the image for Telegram delivery.');
                }
            }
            return $this->formatter->formatImageResponse($imageUrl, $text !== '' ? $text : null, null, null);
        }

        if ($text !== '') {
            $repairedText = $this->repairSelfLimitingResponse($messageText, $text);
            if ($repairedText !== null) {
                $text = $repairedText;
            }
            return $this->formatter->formatTextResponse($text);
        }

        return null;
    }

    private function repairSelfLimitingResponse(string $messageText, string $response): ?string
    {
        if (!$this->looksLikeSelfLimitingNonAnswer($response)) {
            return null;
        }

        $language = $this->detectReplyLanguage($messageText);
        $targetLanguage = $language === 'ru' ? 'Russian' : 'English';
        $params = [
            'model' => $this->config['openrouter_chat_model'] ?? $this->config['openrouter_tool_model'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Rewrite a failed Telegram bot agent reply into an engaged entertainment group-chat answer. ' .
                        'Respond only in ' . $targetLanguage . '. ' .
                        'Do not mention capabilities, functions, lack of expertise, or "as an AI". ' .
                        'If the user asked a legal/medical/financial/safety-adjacent question, give a brief high-level non-professional overview and a tiny caveat instead of refusing. ' .
                        'If the user complained that the bot is boring or too restricted, answer with light banter and recover. ' .
                        'Keep it concise, 1-3 sentences.',
                ],
                [
                    'role' => 'user',
                    'content' => "Original user request:\n{$messageText}\n\nBad bot reply to rewrite:\n{$response}",
                ],
            ],
            'temperature' => 0.45,
            'max_tokens' => 320,
        ];

        $body = $this->makeOpenRouterRequest($this->config, $params, 'Agent Response Repair', 20);
        $content = $this->extractContentFromResponse($body, 'Agent Response Repair');
        if ($content === null || $this->looksLikeSelfLimitingNonAnswer($content)) {
            return null;
        }

        $this->logger->log('Repaired self-limiting agent response', 'Agent Response Repair', 'webhook');
        return $content;
    }

    private function looksLikeSelfLimitingNonAnswer(string $response): bool
    {
        $normalized = mb_strtolower(trim($response));
        if ($normalized === '') {
            return false;
        }

        $markers = [
            'как ии',
            'как искусственный интеллект',
            'as an ai',
            'не обладаю юридической экспертизой',
            'не могу давать консультации',
            'мои функции не включают',
            'не предназначен для анализа',
            'не входит в мои функции',
            'могу помочь сделать чат интереснее',
            'дайте мне конкретные задачи',
            'i lack legal expertise',
            'i cannot provide legal advice',
            'my functions do not include',
            'beyond my capabilities',
            'give me concrete tasks',
        ];

        foreach ($markers as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed>|string|int|float|null $content
     */
    private function extractText(array|string|int|float|null $content): string
    {
        if (is_string($content) || is_int($content) || is_float($content)) {
            return trim((string)$content);
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_string($item)) {
                $parts[] = $item;
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'text' && isset($item['text'])) {
                $parts[] = (string)$item['text'];
                continue;
            }

            if (isset($item['content']) && is_string($item['content'])) {
                $parts[] = $item['content'];
            }
        }

        return trim(implode("\n", array_filter(array_map('trim', $parts))));
    }

    /**
     * @param mixed $images
     * @param array<int, mixed>|string|int|float|null $content
     */
    private function extractImageUrl(mixed $images, array|string|int|float|null $content): ?string
    {
        $candidates = [];
        $textContent = $this->extractText($content);

        if (is_array($images)) {
            foreach ($images as $image) {
                $candidates[] = $image;
            }
        }

        if (is_array($content)) {
            foreach ($content as $item) {
                if (is_array($item) && (($item['type'] ?? null) === 'image_url' || isset($item['image_url']) || isset($item['url']))) {
                    $candidates[] = $item;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }

            if (!is_array($candidate)) {
                continue;
            }

            if (isset($candidate['url']) && is_string($candidate['url']) && trim($candidate['url']) !== '') {
                return trim($candidate['url']);
            }

            if (isset($candidate['image_url']['url']) && is_string($candidate['image_url']['url']) && trim($candidate['image_url']['url']) !== '') {
                return trim($candidate['image_url']['url']);
            }
        }

        $markdownImageUrl = $this->extractMarkdownImageUrl($textContent);
        if ($markdownImageUrl !== null) {
            return $markdownImageUrl;
        }

        $dataUri = $this->extractDataUriFromText($textContent);
        if ($dataUri !== null) {
            return $dataUri;
        }

        return null;
    }

    private function saveDataUriImage(string $dataUri): string
    {
        $normalized = strtolower($dataUri);
        if (!str_starts_with($normalized, 'data:image/')) {
            throw new \RuntimeException('Unsupported data URI format');
        }

        $separatorPos = strpos($normalized, ';base64,');
        if ($separatorPos === false) {
            throw new \RuntimeException('Unsupported data URI format');
        }

        $ext = strtolower(substr($normalized, strlen('data:image/'), $separatorPos - strlen('data:image/')));
        if (!in_array($ext, ['png', 'jpeg', 'jpg', 'gif', 'webp'], true)) {
            throw new \RuntimeException('Unsupported data URI format');
        }

        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $base64 = substr($dataUri, strpos($dataUri, ',') + 1);
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            throw new \RuntimeException('Failed to decode data URI image');
        }

        $dir = __DIR__ . '/../../images';
        if (!is_dir($dir) && !mkdir($concurrentDirectory = $dir, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

        $filename = 'agent_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = $dir . '/' . $filename;
        if (file_put_contents($path, $binary) === false) {
            throw new \RuntimeException('Failed to write image file');
        }

        return self::PUBLIC_IMAGES_BASE_URL . $filename;
    }

    private function extractMarkdownImageUrl(string $text): ?string
    {
        $markerStart = strpos($text, '![');
        if ($markerStart === false) {
            return null;
        }

        $openParen = strpos($text, '](', $markerStart);
        if ($openParen === false) {
            return null;
        }

        $urlStart = $openParen + 2;
        $closeParen = strpos($text, ')', $urlStart);
        if ($closeParen === false) {
            return null;
        }

        $url = trim(substr($text, $urlStart, $closeParen - $urlStart));
        return $url !== '' ? $url : null;
    }

    private function extractDataUriFromText(string $text): ?string
    {
        $start = stripos($text, 'data:image/');
        if ($start === false) {
            return null;
        }

        $end = strlen($text);
        $separators = ["\n", "\r", "\t", ' '];
        foreach ($separators as $separator) {
            $pos = strpos($text, $separator, $start);
            if ($pos !== false) {
                $end = min($end, $pos);
            }
        }

        $candidate = trim(substr($text, $start, $end - $start));
        return str_starts_with(strtolower($candidate), 'data:image/') ? $candidate : null;
    }
}
