<?php

namespace App\Services;

use GuzzleHttp\Client as HttpClient;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Builds local and provider-backed tools for agent turns and records run stats.
 */
class AgentToolRegistry
{
    private array $config;
    private LoggerService $logger;
    private ChatMemoryStore $chatMemoryStore;
    private AgentTaskStore $taskStore;

    /** @var array<string, mixed> */
    private array $context;

    /** @var string[] */
    private array $toolsUsed = [];

    /** @var string[] */
    private array $toolErrors = [];

    private int $memoryReads = 0;
    private int $memoryWrites = 0;
    private int $memoryDeletes = 0;
    private int $taskWrites = 0;
    private int $taskFailures = 0;
    private ?string $taskOperation = null;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        array $config,
        LoggerService $logger,
        ChatMemoryStore $chatMemoryStore,
        AgentTaskStore $taskStore,
        array $context
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->chatMemoryStore = $chatMemoryStore;
        $this->taskStore = $taskStore;
        $this->context = $context;
    }

    /**
     * @return array<int, mixed>
     */
    public function buildTools(): array
    {
        $tools = [
            ProviderTool::make('openrouter:web_search', null, [
                'parameters' => ['max_results' => 5],
            ]),
            ProviderTool::make('openrouter:datetime'),
            $this->makeNowTool(),
            $this->makeWebSearchTool('web_search'),
            $this->makeWebSearchTool('search'),
            $this->makeGetChatMemoryTool(),
            $this->makeGetUserProfileTool(),
        ];

        if (($this->context['scheduled'] ?? false) !== true) {
            $tools[] = ProviderTool::make('openrouter:image_generation');
            $tools[] = $this->makeImageGenerationTool('image_generation');
            $tools[] = $this->makeImageGenerationTool('generate_image');
            $tools[] = $this->makeSetChatMemoryTool();
            $tools[] = $this->makeForgetChatMemoryTool();
            $tools[] = $this->makeScheduleTaskTool();
        }

        return $tools;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'tools_used' => array_values(array_unique($this->toolsUsed)),
            'tool_errors' => array_values($this->toolErrors),
            'memory_reads' => $this->memoryReads,
            'memory_writes' => $this->memoryWrites,
            'memory_deletes' => $this->memoryDeletes,
            'task_writes' => $this->taskWrites,
            'task_failures' => $this->taskFailures,
            'task_operation' => $this->taskOperation,
        ];
    }

    private function makeGetChatMemoryTool(): Tool
    {
        return Tool::make(
            'get_chat_memory',
            'Read stored chat or user memory facts relevant to the current conversation. Defaults to returning the full available profile context up to 100 facts.'
        )
            ->addProperty(new ToolProperty('scope', PropertyType::STRING, 'chat or user', true, ['chat', 'user']))
            ->addProperty(new ToolProperty('user_id', PropertyType::INTEGER, 'Optional user id when scope=user', false))
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Optional keyword filter', false))
            ->addProperty(new ToolProperty('limit', PropertyType::INTEGER, 'Maximum number of facts to return; use 100 when the user asks for everything or full context', false))
            ->setCallable(function (string $scope, ?int $user_id = null, ?string $query = null, ?int $limit = null): array {
                $this->markToolUsed('get_chat_memory');
                $this->memoryReads++;

                $chatId = (int)($this->context['chat_id'] ?? 0);
                $targetUserId = $scope === 'user'
                    ? ($user_id ?? (isset($this->context['user_id']) ? (int)$this->context['user_id'] : null))
                    : null;

                $facts = $this->chatMemoryStore->getFacts($scope, $chatId, $targetUserId, $query, max(1, $limit ?? 100));

                return [
                    'scope' => $scope,
                    'chat_id' => $chatId,
                    'user_id' => $targetUserId,
                    'facts' => $facts,
                ];
            });
    }

    private function makeGetUserProfileTool(): Tool
    {
        return Tool::make(
            'get_user_profile',
            'Look up a participant profile from chat memory using a reply target, user id, or a name/query string. Defaults to returning the full available profile facts up to 100 facts.'
        )
            ->addProperty(new ToolProperty('user_id', PropertyType::INTEGER, 'Optional exact Telegram user id', false))
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Optional participant name, username, or text query', false))
            ->addProperty(new ToolProperty('limit', PropertyType::INTEGER, 'Maximum number of facts per matched profile; use 100 when the user asks for full context', false))
            ->setCallable(function (?int $user_id = null, ?string $query = null, ?int $limit = null): array {
                $this->markToolUsed('get_user_profile');
                $this->memoryReads++;

                $chatId = (int)($this->context['chat_id'] ?? 0);
                $targetUserId = $user_id;
                if ($targetUserId === null && isset($this->context['reply_target_user_id']) && $this->context['reply_target_user_id'] !== null) {
                    $targetUserId = (int)$this->context['reply_target_user_id'];
                }

                $profiles = $this->chatMemoryStore->getUserProfiles(
                    $chatId,
                    $targetUserId,
                    $query,
                    max(1, $limit ?? 100)
                );

                return [
                    'chat_id' => $chatId,
                    'user_id' => $targetUserId,
                    'query' => $query,
                    'profiles' => $profiles,
                ];
            });
    }

    private function makeNowTool(): Tool
    {
        return Tool::make(
            'now',
            'Get the current date and time.'
        )->setCallable(function (): array {
            $this->markToolUsed('now');

            $tzName = trim((string)($this->context['default_timezone'] ?? 'Europe/Belgrade')) ?: 'Europe/Belgrade';
            $tz = new \DateTimeZone($tzName);
            $now = new \DateTimeImmutable('now', $tz);

            return [
                'timezone' => $tzName,
                'iso8601' => $now->format(\DateTimeInterface::ATOM),
                'date' => $now->format('Y-m-d'),
                'time' => $now->format('H:i:s'),
                'weekday' => $now->format('l'),
            ];
        });
    }

    private function makeWebSearchTool(string $toolName): Tool
    {
        return Tool::make(
            $toolName,
            'Search the web using OpenRouter web_search server tool.'
        )
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true))
            ->setCallable(function (string $query) use ($toolName): array {
                $this->markToolUsed($toolName);
                $response = $this->callOpenRouterServerTool(
                    [['type' => 'openrouter:web_search', 'parameters' => ['max_results' => 5]]],
                    $query
                );

                if ($response === null) {
                    $this->toolErrors[] = 'OpenRouter web_search alias failed.';
                    return ['ok' => false, 'error' => 'web_search failed'];
                }

                return [
                    'ok' => true,
                    'query' => $query,
                    'result' => $response['text'],
                ];
            });
    }

    private function makeImageGenerationTool(string $toolName): Tool
    {
        return Tool::make(
            $toolName,
            'Generate an image using OpenRouter image_generation server tool.'
        )
            ->addProperty(new ToolProperty('prompt', PropertyType::STRING, 'Image generation prompt', true))
            ->setCallable(function (string $prompt) use ($toolName): array {
                $this->markToolUsed($toolName);
                $response = $this->callOpenRouterServerTool(
                    [['type' => 'openrouter:image_generation']],
                    $prompt
                );

                if ($response === null) {
                    $this->toolErrors[] = 'OpenRouter image_generation alias failed.';
                    return ['ok' => false, 'error' => 'image_generation failed'];
                }

                return [
                    'ok' => true,
                    'prompt' => $prompt,
                    'image_url' => $response['image_url'],
                    'result' => $response['text'],
                ];
            });
    }

    private function makeSetChatMemoryTool(): Tool
    {
        return Tool::make(
            'set_chat_memory',
            'Store a durable non-sensitive fact about the chat or a user. Use this for explicit remember/запомни requests or very stable preferences.'
        )
            ->addProperty(new ToolProperty('scope', PropertyType::STRING, 'chat or user', true, ['chat', 'user']))
            ->addProperty(new ToolProperty('user_id', PropertyType::INTEGER, 'Optional user id when scope=user', false))
            ->addProperty(new ToolProperty('category', PropertyType::STRING, 'Fact category', true, ChatMemoryStore::getAllowedCategories()))
            ->addProperty(new ToolProperty('key', PropertyType::STRING, 'Short machine-friendly key', true))
            ->addProperty(new ToolProperty('value', PropertyType::STRING, 'Fact value', true))
            ->addProperty(new ToolProperty('confidence', PropertyType::NUMBER, 'Confidence between 0 and 1', true))
            ->addProperty(new ToolProperty('ttl_days', PropertyType::INTEGER, 'Optional expiration in days', false))
            ->addProperty(new ToolProperty('source_message_id', PropertyType::INTEGER, 'Source message id', false))
            ->addProperty(new ToolProperty('sensitivity', PropertyType::STRING, 'low, medium, high', true, ['low', 'medium', 'high']))
            ->setCallable(function (
                string $scope,
                ?int $user_id = null,
                string $category = '',
                string $key = '',
                string $value = '',
                float $confidence = 0.0,
                ?int $ttl_days = null,
                ?int $source_message_id = null,
                string $sensitivity = 'low'
            ): array {
                $this->markToolUsed('set_chat_memory');

                $scope = strtolower(trim($scope));
                $sensitivity = strtolower(trim($sensitivity));
                if ($sensitivity !== 'low') {
                    $error = 'Only low-sensitivity facts can be stored.';
                    $this->toolErrors[] = $error;
                    return ['ok' => false, 'error' => $error];
                }

                if ($this->containsSensitiveContent($value)) {
                    $error = 'Refusing to store sensitive or contact-like information.';
                    $this->toolErrors[] = $error;
                    return ['ok' => false, 'error' => $error];
                }

                $chatId = (int)($this->context['chat_id'] ?? 0);
                $targetUserId = $scope === 'user'
                    ? ($user_id ?? (isset($this->context['user_id']) ? (int)$this->context['user_id'] : null))
                    : null;

                $ok = $this->chatMemoryStore->setFact(
                    $chatId,
                    $scope,
                    [
                        'category' => $category,
                        'key' => $key,
                        'value' => $value,
                        'confidence' => $confidence,
                        'ttl_days' => $ttl_days,
                        'source_message_id' => $source_message_id ?? ($this->context['message_id'] ?? null),
                        'source_user_id' => $this->context['user_id'] ?? null,
                        'updated_at' => time(),
                        'sensitivity' => 'low',
                    ],
                    $targetUserId
                );

                if ($ok) {
                    $this->memoryWrites++;
                }

                return [
                    'ok' => $ok,
                    'scope' => $scope,
                    'chat_id' => $chatId,
                    'user_id' => $targetUserId,
                    'category' => $category,
                    'key' => $key,
                    'value' => $value,
                ];
            });
    }

    private function makeForgetChatMemoryTool(): Tool
    {
        return Tool::make(
            'forget_chat_memory',
            'Delete stored memory facts that the user says are wrong, obsolete, quoted from someone else, or should be forgotten. Prefer exact category+key from memory context when available.'
        )
            ->addProperty(new ToolProperty('scope', PropertyType::STRING, 'chat or user', true, ['chat', 'user']))
            ->addProperty(new ToolProperty('user_id', PropertyType::INTEGER, 'Optional user id when scope=user; defaults to current user', false))
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Optional text to match against category, key, or value', false))
            ->addProperty(new ToolProperty('category', PropertyType::STRING, 'Optional exact fact category from memory context', false, ChatMemoryStore::getAllowedCategories()))
            ->addProperty(new ToolProperty('key', PropertyType::STRING, 'Optional exact fact key from memory context; use with category for precise deletion', false))
            ->addProperty(new ToolProperty('limit', PropertyType::INTEGER, 'Maximum matching facts to delete; keep small unless the user explicitly asks for broad cleanup', false))
            ->setCallable(function (
                string $scope,
                ?int $user_id = null,
                ?string $query = null,
                ?string $category = null,
                ?string $key = null,
                ?int $limit = null
            ): array {
                $this->markToolUsed('forget_chat_memory');

                $scope = strtolower(trim($scope));
                $chatId = (int)($this->context['chat_id'] ?? 0);
                $targetUserId = $scope === 'user'
                    ? ($user_id ?? (isset($this->context['user_id']) ? (int)$this->context['user_id'] : null))
                    : null;

                $result = $this->chatMemoryStore->deleteFacts(
                    $scope,
                    $chatId,
                    $targetUserId,
                    $query,
                    $category,
                    $key,
                    max(1, $limit ?? 5)
                );

                if ((int)($result['deleted'] ?? 0) === 0 && ($category !== null || $key !== null) && trim((string)$query) !== '') {
                    $result = $this->chatMemoryStore->deleteFacts(
                        $scope,
                        $chatId,
                        $targetUserId,
                        $query,
                        null,
                        null,
                        max(1, $limit ?? 5)
                    );
                }

                $deleted = (int)($result['deleted'] ?? 0);
                if ($deleted > 0) {
                    $this->memoryDeletes += $deleted;
                } else {
                    $this->toolErrors[] = 'No matching memory facts were deleted.';
                }

                $this->logger->logWebhook(
                    'Memory delete request: scope=' . $scope
                    . ', user_id=' . ($targetUserId !== null ? (string)$targetUserId : 'null')
                    . ', category=' . trim((string)$category)
                    . ', key=' . trim((string)$key)
                    . ', query=' . mb_substr(trim((string)$query), 0, 120)
                    . ', deleted=' . $deleted
                );

                return [
                    'ok' => $deleted > 0,
                    'scope' => $scope,
                    'chat_id' => $chatId,
                    'user_id' => $targetUserId,
                    'deleted' => $deleted,
                    'deleted_facts' => $result['deleted_facts'] ?? [],
                ];
            });
    }

    private function makeScheduleTaskTool(): Tool
    {
        return Tool::make(
            'schedule_task',
            'Create, update, delete, pause, resume, or list scheduled tasks for this chat.'
        )
            ->addProperty(new ToolProperty('operation', PropertyType::STRING, 'create, update, delete, pause, resume, list', true, ['create', 'update', 'delete', 'pause', 'resume', 'list']))
            ->addProperty(new ToolProperty('task_id', PropertyType::STRING, 'Task id for non-create operations', false))
            ->addProperty(new ToolProperty('title', PropertyType::STRING, 'Human-readable title', false))
            ->addProperty(new ToolProperty('execution_prompt', PropertyType::STRING, 'Prompt to execute when the task runs', false))
            ->addProperty(new ToolProperty('delivery_mode', PropertyType::STRING, 'direct for plain reminder delivery, agent for richer generated task execution', false, ['direct', 'agent']))
            ->addProperty(new ToolProperty('schedule', PropertyType::OBJECT, 'Schedule object. Supported forms include {type: once, run_at_utc}, {type: once, run_at_local}, {type: once, date_local, time_local}, {type: daily, time_local}, {type: weekly, time_local, weekdays}, {type: interval, interval_hours|interval_minutes}, {type: delay, delay_seconds|delay_minutes|delay_hours|delay_days|delay_weeks|delay_months|delay_years}, plus aliases like run_in_days or {type: delay, amount: 1, unit: year}.', false))
            ->addProperty(new ToolProperty('timezone', PropertyType::STRING, 'IANA timezone', false))
            ->addProperty(new ToolProperty('target_chat_id', PropertyType::INTEGER, 'Optional target chat id', false))
            ->addProperty(new ToolProperty('target_thread_id', PropertyType::INTEGER, 'Optional target thread id', false))
            ->addProperty(new ToolProperty('enabled', PropertyType::BOOLEAN, 'Whether the task is enabled', false))
            ->setCallable(function (
                string $operation,
                ?string $task_id = null,
                ?string $title = null,
                ?string $execution_prompt = null,
                ?string $delivery_mode = null,
                ?array $schedule = null,
                ?string $timezone = null,
                ?int $target_chat_id = null,
                ?int $target_thread_id = null,
                ?bool $enabled = null
            ): array {
                $this->markToolUsed('schedule_task');
                $this->taskOperation = $operation;
                $chatId = $target_chat_id ?? (int)($this->context['chat_id'] ?? 0);
                $threadId = $target_thread_id ?? ($this->context['thread_id'] ?? null);
                $defaultTimezone = trim((string)($this->context['default_timezone'] ?? 'Europe/Belgrade')) ?: 'Europe/Belgrade';
                $timezone = trim((string)($timezone ?? $defaultTimezone)) ?: $defaultTimezone;

                return match ($operation) {
                    'create' => $this->wrapTaskMutation($this->taskStore->createTask([
                        'title' => $title ?? 'Scheduled task',
                        'execution_prompt' => $execution_prompt ?? '',
                        'delivery_mode' => $delivery_mode,
                        'requester_user_id' => $this->context['user_id'] ?? null,
                        'requester_label' => $this->context['username'] ?? null,
                        'schedule' => $schedule ?? [],
                        'timezone' => $timezone,
                        'target_chat_id' => $chatId,
                        'target_thread_id' => $threadId,
                        'enabled' => $enabled ?? true,
                    ])),
                    'update' => $this->wrapTaskMutation($task_id ? $this->taskStore->updateTask($task_id, array_filter([
                        'title' => $title,
                        'execution_prompt' => $execution_prompt,
                        'delivery_mode' => $delivery_mode,
                        'schedule' => $schedule,
                        'timezone' => $timezone,
                        'target_chat_id' => $chatId,
                        'target_thread_id' => $threadId,
                        'enabled' => $enabled,
                    ], static fn ($value) => $value !== null)) : null),
                    'delete' => $this->wrapDeleteTask($task_id),
                    'pause' => $this->wrapTaskMutation($task_id ? $this->taskStore->pauseTask($task_id) : null),
                    'resume' => $this->wrapTaskMutation($task_id ? $this->taskStore->resumeTask($task_id) : null),
                    'list' => [
                        'ok' => true,
                        'operation' => 'list',
                        'tasks' => $this->taskStore->listTasks($chatId),
                    ],
                    default => ['ok' => false, 'error' => 'Unsupported task operation'],
                };
            });
    }

    /**
     * @param array<string, mixed>|null $task
     * @return array<string, mixed>
     */
    private function wrapTaskMutation(?array $task): array
    {
        if ($task === null) {
            $this->taskFailures++;
            $this->toolErrors[] = 'Task mutation failed or task was not found.';
            return ['ok' => false, 'error' => 'Task mutation failed or task was not found.'];
        }

        $this->taskWrites++;
        return ['ok' => true, 'task' => $task];
    }

    /**
     * @return array<string, mixed>
     */
    private function wrapDeleteTask(?string $taskId): array
    {
        $ok = $taskId ? $this->taskStore->deleteTask($taskId) : false;
        if ($ok) {
            $this->taskWrites++;
        } else {
            $this->taskFailures++;
            $this->toolErrors[] = 'Task delete failed or task was not found.';
        }

        return [
            'ok' => $ok,
            'operation' => 'delete',
            'task_id' => $taskId,
        ];
    }

    private function markToolUsed(string $toolName): void
    {
        $this->toolsUsed[] = $toolName;
        $this->logger->logWebhook("Agent tool executed: {$toolName}");
    }

    private function containsSensitiveContent(string $value): bool
    {
        return preg_match('/@\w{3,}|https?:\/\/|www\.|t\.me\/|token|password|api key|wallet|iban|card/iu', $value) === 1;
    }

    /**
     * @param array<int, array<string, mixed>> $tools
     * @return array{text: string, image_url: ?string}|null
     */
    private function callOpenRouterServerTool(array $tools, string $query): ?array
    {
        try {
            $client = new HttpClient(['timeout' => 35, 'connect_timeout' => 10]);
            $response = $client->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->config['openrouter_chat_model'] ?? $this->config['openrouter_tool_model'],
                    'messages' => [
                        ['role' => 'system', 'content' => 'Use the available built-in tool to help answer the request.'],
                        ['role' => 'user', 'content' => $query],
                    ],
                    'tools' => $tools,
                ],
            ]);

            $body = json_decode((string)$response->getBody()->getContents(), true);
            if (!is_array($body)) {
                return null;
            }

            $message = $body['choices'][0]['message'] ?? [];
            $text = '';
            if (isset($message['content']) && is_string($message['content'])) {
                $text = trim($message['content']);
            }

            $imageUrl = null;
            if (is_array($message['images'] ?? null)) {
                foreach ($message['images'] as $image) {
                    if (is_string($image) && trim($image) !== '') {
                        $imageUrl = trim($image);
                        break;
                    }
                    if (isset($image['url']) && is_string($image['url']) && trim($image['url']) !== '') {
                        $imageUrl = trim($image['url']);
                        break;
                    }
                }
            }

            if ($imageUrl === null && preg_match('/!\[[^\]]*\]\(([^)]+)\)/u', $text, $matches) === 1) {
                $imageUrl = trim((string)$matches[1]);
            }

            return [
                'text' => $text,
                'image_url' => $imageUrl,
            ];
        } catch (\Throwable $e) {
            $this->logger->logError('OpenRouter server-tool bridge failed: ' . $e->getMessage(), 'AgentToolRegistry', $e);
            return null;
        }
    }
}
