<?php

namespace App\Services;

use Longman\TelegramBot\Request;

/**
 * Executes due agent-created tasks from the background worker.
 */
class AgentTaskRunner
{
    private AgentTaskStore $taskStore;
    private AIService $aiService;
    private TelegramSender $sender;
    private LoggerService $logger;

    public function __construct(
        AgentTaskStore $taskStore,
        AIService $aiService,
        TelegramSender $sender,
        LoggerService $logger
    ) {
        $this->taskStore = $taskStore;
        $this->aiService = $aiService;
        $this->sender = $sender;
        $this->logger = $logger;
    }

    public function runDueTasks(int $now): void
    {
        $tasks = $this->taskStore->claimDueTasks($now);
        foreach ($tasks as $task) {
            $taskId = (string)($task['task_id'] ?? '');
            if ($taskId === '') {
                continue;
            }

            try {
                $chatId = (int)($task['target_chat_id'] ?? 0);
                $threadId = isset($task['target_thread_id']) && $task['target_thread_id'] !== null ? (int)$task['target_thread_id'] : null;
                $prompt = trim((string)($task['execution_prompt'] ?? ''));
                if ($prompt === '' || $chatId === 0) {
                    $this->taskStore->finishTask($taskId, false, null, 'Invalid task payload', time());
                    continue;
                }

                $content = null;
                if ($this->shouldDeliverDirectly($task, $prompt)) {
                    $content = $this->normalizeDirectDeliveryText($prompt);
                } else {
                    $response = $this->aiService->generateAgentResponse(
                        $prompt,
                        'scheduled-task',
                        '',
                        $chatId,
                        null,
                        $threadId,
                        [
                            'scheduled' => true,
                            'task_id' => $taskId,
                        ]
                    );

                    if (!is_array($response) || empty($response['content'])) {
                        $this->taskStore->finishTask($taskId, false, null, 'No agent response produced for scheduled task.', time());
                        continue;
                    }

                    $content = (string)$response['content'];
                }

                $sendParams = $this->buildSendParams($task, $chatId, $threadId, $content);

                $sendResult = Request::sendMessage($sendParams);

                if (!$sendResult->isOk()) {
                    $this->taskStore->finishTask($taskId, false, null, $sendResult->getDescription(), time());
                    continue;
                }

                $excerpt = mb_substr(strip_tags($content), 0, 280);
                $this->taskStore->finishTask($taskId, true, $excerpt, null, time());
                $this->logger->logWebhook("Scheduled agent task {$taskId} executed successfully for chat {$chatId}");
            } catch (\Throwable $e) {
                $this->logger->logError('Scheduled task execution failed: ' . $e->getMessage(), 'AgentTaskRunner', $e);
                $this->taskStore->finishTask($taskId, false, null, $e->getMessage(), time());
            }
        }
    }

    /**
     * Plain reminder tasks should be delivered as-is instead of being reinterpreted by the model.
     *
     * @param array<string, mixed> $task
     */
    private function shouldDeliverDirectly(array $task, string $prompt): bool
    {
        $deliveryMode = strtolower(trim((string)($task['delivery_mode'] ?? '')));
        if ($deliveryMode === 'direct') {
            return true;
        }

        if ($deliveryMode === 'agent') {
            return $this->looksLikeReminderText($prompt);
        }

        return $this->looksLikeReminderText($prompt);
    }

    private function looksLikeReminderText(string $prompt): bool
    {
        $normalized = mb_strtolower(trim($prompt));
        if ($normalized === '') {
            return false;
        }

        $agentLikePatterns = [
            '/\b(search|find|look up|check|summarize|analyze|compare|report|fetch|browse|read the web)\b/ui',
            '/\b(найди|поищи|проверь|суммируй|сделай сводку|проанализируй|сравни|подготовь отч[её]т)\b/ui',
        ];
        foreach ($agentLikePatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return false;
            }
        }

        $reminderPatterns = [
            '/\b(reminder:?|time to|don\'t forget|dont forget|the .+ will be ready|it is time|remember to)\b/ui',
            '/\b(пора|напоминаю|не забудь|готов|будет готов|время|через)\b/ui',
        ];
        foreach ($reminderPatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDirectDeliveryText(string $prompt): string
    {
        $text = trim($prompt);
        if ($text === '') {
            return $text;
        }

        if (preg_match('/^\s*([@\p{L}][\p{L}\p{N}_-]{1,31})\s*,\s*(.+)$/u', $text, $matches) === 1) {
            $text = trim((string)$matches[2]);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function buildSendParams(array $task, int $chatId, ?int $threadId, string $content): array
    {
        $sendParams = [
            'chat_id' => $chatId,
            'text' => mb_substr($content, 0, 4000),
        ];

        if ($threadId !== null) {
            $sendParams['message_thread_id'] = $threadId;
        }

        $deliveryMode = strtolower(trim((string)($task['delivery_mode'] ?? '')));
        $requesterUserId = isset($task['requester_user_id']) ? (int)$task['requester_user_id'] : 0;
        $requesterLabel = trim((string)($task['requester_label'] ?? ''));

        if ($deliveryMode !== 'direct' || $requesterUserId <= 0) {
            return $sendParams;
        }

        $requesterLabel = $this->sanitizeRequesterLabel($requesterLabel);
        $escapedLabel = htmlspecialchars($requesterLabel !== '' ? $requesterLabel : 'Reminder', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedContent = htmlspecialchars(mb_substr($content, 0, 3800), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $sendParams['text'] = '<a href="tg://user?id=' . $requesterUserId . '">' . $escapedLabel . '</a>, ' . $escapedContent;
        $sendParams['parse_mode'] = 'HTML';

        return $sendParams;
    }

    private function sanitizeRequesterLabel(string $label): string
    {
        $label = preg_replace('/\s*\(@[^)]+\)\s*$/u', '', $label) ?? $label;
        $label = ltrim(trim($label), '@');

        return $label !== '' ? mb_substr($label, 0, 64) : '';
    }
}
