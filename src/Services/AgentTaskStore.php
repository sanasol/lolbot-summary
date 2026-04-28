<?php

namespace App\Services;

/**
 * File-backed recurring task storage for agent-created reminders/jobs.
 */
class AgentTaskStore
{
    private string $filePath;
    private LoggerService $logger;
    private string $defaultTimezone;

    public function __construct(string $dataPath, LoggerService $logger, string $defaultTimezone = 'Europe/Belgrade')
    {
        $this->filePath = rtrim($dataPath, '/') . '/agent_tasks.json';
        $this->logger = $logger;
        $this->defaultTimezone = trim($defaultTimezone) !== '' ? trim($defaultTimezone) : 'Europe/Belgrade';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTasks(?int $chatId = null): array
    {
        $data = $this->load();
        $tasks = array_values($data['tasks'] ?? []);

        if ($chatId !== null) {
            $tasks = array_values(array_filter($tasks, static fn (array $task): bool => (int)($task['target_chat_id'] ?? 0) === $chatId));
        }

        usort($tasks, static fn (array $a, array $b): int => strcmp((string)($a['task_id'] ?? ''), (string)($b['task_id'] ?? '')));

        return $tasks;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function createTask(array $payload): ?array
    {
        $data = $this->load();
        $taskId = 'task_' . bin2hex(random_bytes(6));
        $now = time();
        $timezone = trim((string)($payload['timezone'] ?? $this->defaultTimezone)) ?: $this->defaultTimezone;
        $schedule = $this->normalizeSchedule($payload['schedule'] ?? [], $timezone, $now);
        $nextRunAt = $this->computeNextRunAt($schedule, $timezone, $now, null);

        $task = [
            'task_id' => $taskId,
            'title' => trim((string)($payload['title'] ?? 'Scheduled task')),
            'execution_prompt' => trim((string)($payload['execution_prompt'] ?? '')),
            'delivery_mode' => $this->normalizeDeliveryMode($payload['delivery_mode'] ?? null),
            'requester_user_id' => isset($payload['requester_user_id']) && $payload['requester_user_id'] !== null ? (int)$payload['requester_user_id'] : null,
            'requester_label' => $this->normalizeRequesterLabel($payload['requester_label'] ?? null),
            'schedule' => $schedule,
            'timezone' => $timezone,
            'target_chat_id' => (int)($payload['target_chat_id'] ?? 0),
            'target_thread_id' => isset($payload['target_thread_id']) && $payload['target_thread_id'] !== null ? (int)$payload['target_thread_id'] : null,
            'enabled' => $payload['enabled'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
            'last_run_at' => null,
            'next_run_at' => $nextRunAt,
            'last_error' => null,
            'last_result_excerpt' => null,
            'locked_at' => null,
        ];

        if ((bool)$task['enabled'] && $task['next_run_at'] === null) {
            $this->logInvalidSchedule('create', $timezone, $schedule, $payload);
            return null;
        }

        $data['tasks'][$taskId] = $task;
        $data['updated_at'] = $now;
        $this->save($data);

        return $task;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateTask(string $taskId, array $payload): ?array
    {
        $data = $this->load();
        if (!isset($data['tasks'][$taskId])) {
            return null;
        }

        $task = $data['tasks'][$taskId];
        foreach (['title', 'execution_prompt', 'delivery_mode', 'requester_user_id', 'requester_label', 'schedule', 'timezone', 'target_chat_id', 'target_thread_id', 'enabled'] as $field) {
            if (array_key_exists($field, $payload)) {
                $task[$field] = $payload[$field];
            }
        }

        $task['timezone'] = trim((string)($task['timezone'] ?? $this->defaultTimezone)) ?: $this->defaultTimezone;
        $task['delivery_mode'] = $this->normalizeDeliveryMode($task['delivery_mode'] ?? null);
        $task['requester_user_id'] = isset($task['requester_user_id']) && $task['requester_user_id'] !== null ? (int)$task['requester_user_id'] : null;
        $task['requester_label'] = $this->normalizeRequesterLabel($task['requester_label'] ?? null);
        $task['schedule'] = $this->normalizeSchedule($task['schedule'] ?? [], $task['timezone'], time());
        $task['target_chat_id'] = (int)($task['target_chat_id'] ?? 0);
        $task['target_thread_id'] = isset($task['target_thread_id']) && $task['target_thread_id'] !== null ? (int)$task['target_thread_id'] : null;
        $task['updated_at'] = time();
        $task['next_run_at'] = $this->computeNextRunAt($task['schedule'] ?? [], $task['timezone'], time(), isset($task['last_run_at']) ? (int)$task['last_run_at'] : null);

        if ((bool)($task['enabled'] ?? false) && $task['next_run_at'] === null) {
            $this->logInvalidSchedule('update', $task['timezone'], $task['schedule'] ?? [], $payload);
            return null;
        }

        $data['tasks'][$taskId] = $task;
        $data['updated_at'] = time();
        $this->save($data);

        return $task;
    }

    public function deleteTask(string $taskId): bool
    {
        $data = $this->load();
        if (!isset($data['tasks'][$taskId])) {
            return false;
        }

        unset($data['tasks'][$taskId]);
        $data['updated_at'] = time();
        $this->save($data);
        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pauseTask(string $taskId): ?array
    {
        return $this->updateTask($taskId, ['enabled' => false]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resumeTask(string $taskId): ?array
    {
        return $this->updateTask($taskId, ['enabled' => true]);
    }

    /**
     * Claim due tasks atomically enough for our single-worker setup.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claimDueTasks(int $now): array
    {
        $data = $this->load();
        $due = [];

        foreach ($data['tasks'] ?? [] as $taskId => $task) {
            $enabled = (bool)($task['enabled'] ?? false);
            $nextRunAt = isset($task['next_run_at']) ? (int)$task['next_run_at'] : null;
            $lockedAt = isset($task['locked_at']) ? (int)$task['locked_at'] : null;

            if (!$enabled || $nextRunAt === null || $nextRunAt > $now) {
                continue;
            }

            if ($lockedAt !== null && ($now - $lockedAt) < 300) {
                continue;
            }

            $task['locked_at'] = $now;
            $data['tasks'][$taskId] = $task;
            $due[] = $task;
        }

        if ($due !== []) {
            $data['updated_at'] = $now;
            $this->save($data);
        }

        return $due;
    }

    public function finishTask(string $taskId, bool $success, ?string $resultExcerpt, ?string $error, int $completedAt): void
    {
        $data = $this->load();
        if (!isset($data['tasks'][$taskId])) {
            return;
        }

        $task = $data['tasks'][$taskId];
        $task['locked_at'] = null;
        $task['last_run_at'] = $completedAt;
        $task['last_result_excerpt'] = $resultExcerpt;
        $task['last_error'] = $error;
        $task['updated_at'] = $completedAt;

        if (!$success) {
            $task['next_run_at'] = $completedAt + 300;
        } else {
            $task['next_run_at'] = $this->computeNextRunAt(
                $task['schedule'] ?? [],
                trim((string)($task['timezone'] ?? $this->defaultTimezone)) ?: $this->defaultTimezone,
                $completedAt,
                $completedAt
            );

            if (($task['schedule']['type'] ?? null) === 'once') {
                $task['enabled'] = false;
            }
        }

        $data['tasks'][$taskId] = $task;
        $data['updated_at'] = $completedAt;
        $this->save($data);
    }

    /**
     * @return array{tasks: array<string, array<string, mixed>>, updated_at: int|null}
     */
    private function load(): array
    {
        if (!is_file($this->filePath)) {
            return [
                'tasks' => [],
                'updated_at' => null,
            ];
        }

        $data = json_decode((string)file_get_contents($this->filePath), true);
        if (!is_array($data)) {
            return [
                'tasks' => [],
                'updated_at' => null,
            ];
        }

        return [
            'tasks' => is_array($data['tasks'] ?? null) ? $data['tasks'] : [],
            'updated_at' => isset($data['updated_at']) ? (int)$data['updated_at'] : null,
        ];
    }

    /**
     * @param array{tasks: array<string, array<string, mixed>>, updated_at: int|null} $data
     */
    private function save(array $data): void
    {
        @file_put_contents($this->filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function normalizeDeliveryMode(mixed $value): string
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['direct', 'agent'], true) ? $normalized : 'agent';
    }

    private function normalizeRequesterLabel(mixed $value): ?string
    {
        $label = trim((string)$value);
        if ($label === '') {
            return null;
        }

        $label = preg_replace('/\s*\(@[^)]+\)\s*$/u', '', $label) ?? $label;
        $label = ltrim($label, '@');
        $label = trim($label);

        return $label !== '' ? mb_substr($label, 0, 64) : null;
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function computeNextRunAt(array $schedule, string $timezone, int $now, ?int $lastRunAt): ?int
    {
        $type = strtolower(trim((string)($schedule['type'] ?? '')));
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('Europe/Belgrade');
        }

        return match ($type) {
            'once' => $this->resolveAbsoluteRunAt($schedule, $timezone),
            'daily' => $this->nextDailyRun($schedule, $tz, $now),
            'weekly' => $this->nextWeeklyRun($schedule, $tz, $now),
            'interval' => $this->nextIntervalRun($schedule, $now, $lastRunAt),
            'delay' => $this->nextDelayRun($schedule, $now),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function nextDailyRun(array $schedule, \DateTimeZone $timezone, int $now): ?int
    {
        $timeLocal = trim((string)($schedule['time_local'] ?? ''));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $timeLocal)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $timeLocal));
        $dt = new \DateTimeImmutable('@' . $now);
        $dt = $dt->setTimezone($timezone)->setTime($hour, $minute, 0);
        if ($dt->getTimestamp() <= $now) {
            $dt = $dt->modify('+1 day');
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function nextWeeklyRun(array $schedule, \DateTimeZone $timezone, int $now): ?int
    {
        $timeLocal = trim((string)($schedule['time_local'] ?? ''));
        $rawWeekdays = is_array($schedule['weekdays'] ?? null) ? $schedule['weekdays'] : [];

        if ($rawWeekdays === [] || !preg_match('/^\d{1,2}:\d{2}$/', $timeLocal)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $timeLocal));
        $weekdayMap = [
            'mon' => 1, 'monday' => 1,
            'tue' => 2, 'tues' => 2, 'tuesday' => 2,
            'wed' => 3, 'wednesday' => 3,
            'thu' => 4, 'thursday' => 4,
            'fri' => 5, 'friday' => 5,
            'sat' => 6, 'saturday' => 6,
            'sun' => 7, 'sunday' => 7,
        ];
        $targetDays = [];
        foreach ($rawWeekdays as $day) {
            if (is_int($day) || (is_string($day) && preg_match('/^[1-7]$/', trim($day)) === 1)) {
                $targetDays[] = (int)$day;
                continue;
            }

            $normalizedDay = strtolower(trim((string)$day));
            if (isset($weekdayMap[$normalizedDay])) {
                $targetDays[] = $weekdayMap[$normalizedDay];
            }
        }

        $targetDays = array_values(array_unique(array_filter(
            $targetDays,
            static fn (int $day): bool => $day >= 1 && $day <= 7
        )));

        if ($targetDays === []) {
            return null;
        }

        $current = (new \DateTimeImmutable('@' . $now))->setTimezone($timezone);
        for ($offset = 0; $offset <= 7; $offset++) {
            $candidate = $current->modify("+{$offset} day")->setTime($hour, $minute, 0);
            if (in_array((int)$candidate->format('N'), $targetDays, true) && $candidate->getTimestamp() > $now) {
                return $candidate->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function nextIntervalRun(array $schedule, int $now, ?int $lastRunAt): ?int
    {
        $intervalMinutes = (int)($schedule['interval_minutes'] ?? 0);
        if ($intervalMinutes > 0) {
            $anchor = $lastRunAt ?? $now;
            return $anchor + ($intervalMinutes * 60);
        }

        $intervalHours = max(1, (int)($schedule['interval_hours'] ?? 0));
        $anchor = $lastRunAt ?? $now;
        return $anchor + ($intervalHours * 3600);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function nextDelayRun(array $schedule, int $now): ?int
    {
        $delaySeconds = (int)($schedule['delay_seconds'] ?? 0);
        if ($delaySeconds > 0) {
            return $now + $delaySeconds;
        }

        $delayMinutes = (int)($schedule['delay_minutes'] ?? 0);
        if ($delayMinutes > 0) {
            return $now + ($delayMinutes * 60);
        }

        $delayHours = (int)($schedule['delay_hours'] ?? 0);
        if ($delayHours > 0) {
            return $now + ($delayHours * 3600);
        }

        return null;
    }

    /**
     * @param mixed $rawSchedule
     * @return array<string, mixed>
     */
    private function normalizeSchedule(mixed $rawSchedule, string $timezone, int $now): array
    {
        $schedule = is_array($rawSchedule) ? $rawSchedule : [];
        if ($schedule === []) {
            return [];
        }

        $type = strtolower(trim((string)($schedule['type'] ?? '')));
        if ($type === '') {
            if (isset($schedule['run_at_utc']) || isset($schedule['run_at'])) {
                $type = 'once';
            } elseif (isset($schedule['delay_seconds']) || isset($schedule['delay_minutes']) || isset($schedule['delay_hours']) || isset($schedule['run_in_seconds']) || isset($schedule['run_in_minutes']) || isset($schedule['run_in_hours'])) {
                $type = 'delay';
            } elseif (isset($schedule['interval_minutes']) || isset($schedule['interval_hours'])) {
                $type = 'interval';
            } elseif (isset($schedule['weekdays'])) {
                $type = 'weekly';
            } elseif (isset($schedule['time_local'])) {
                $type = 'daily';
            }
        }

        if (in_array($type, ['relative', 'after', 'in'], true)) {
            $type = 'delay';
        }

        if ($type === 'once' && !isset($schedule['run_at_utc']) && isset($schedule['run_at'])) {
            $runAt = $this->resolveAbsoluteRunAt($schedule, $timezone);
            if ($runAt !== null) {
                $schedule['run_at_utc'] = gmdate('c', $runAt);
            }
        } elseif ($type === 'once' && !isset($schedule['run_at_utc'])) {
            $runAt = $this->resolveAbsoluteRunAt($schedule, $timezone);
            if ($runAt !== null) {
                $schedule['run_at_utc'] = gmdate('c', $runAt);
            }
        }

        if ($type === 'delay') {
            if (!isset($schedule['delay_seconds']) && isset($schedule['run_in_seconds'])) {
                $schedule['delay_seconds'] = (int)$schedule['run_in_seconds'];
            }
            if (!isset($schedule['delay_minutes']) && isset($schedule['run_in_minutes'])) {
                $schedule['delay_minutes'] = (int)$schedule['run_in_minutes'];
            }
            if (!isset($schedule['delay_hours']) && isset($schedule['run_in_hours'])) {
                $schedule['delay_hours'] = (int)$schedule['run_in_hours'];
            }
            if (!isset($schedule['delay_days']) && isset($schedule['run_in_days'])) {
                $schedule['delay_days'] = (int)$schedule['run_in_days'];
            }
            if (!isset($schedule['delay_weeks']) && isset($schedule['run_in_weeks'])) {
                $schedule['delay_weeks'] = (int)$schedule['run_in_weeks'];
            }
            if (!isset($schedule['delay_months']) && isset($schedule['run_in_months'])) {
                $schedule['delay_months'] = (int)$schedule['run_in_months'];
            }
            if (!isset($schedule['delay_years']) && isset($schedule['run_in_years'])) {
                $schedule['delay_years'] = (int)$schedule['run_in_years'];
            }

            if (!isset($schedule['delay_minutes']) && !isset($schedule['delay_seconds']) && !isset($schedule['delay_hours']) && isset($schedule['minutes'])) {
                $schedule['delay_minutes'] = (int)$schedule['minutes'];
            }
            if (!isset($schedule['delay_seconds']) && isset($schedule['seconds'])) {
                $schedule['delay_seconds'] = (int)$schedule['seconds'];
            }
            if (!isset($schedule['delay_hours']) && isset($schedule['hours'])) {
                $schedule['delay_hours'] = (int)$schedule['hours'];
            }
            if (!isset($schedule['delay_days']) && isset($schedule['days'])) {
                $schedule['delay_days'] = (int)$schedule['days'];
            }
            if (!isset($schedule['delay_weeks']) && isset($schedule['weeks'])) {
                $schedule['delay_weeks'] = (int)$schedule['weeks'];
            }
            if (!isset($schedule['delay_months']) && isset($schedule['months'])) {
                $schedule['delay_months'] = (int)$schedule['months'];
            }
            if (!isset($schedule['delay_years']) && isset($schedule['years'])) {
                $schedule['delay_years'] = (int)$schedule['years'];
            }

            $this->applyDelayAmountUnitAlias($schedule);

            $runAt = $this->resolveRelativeDelayTimestamp($schedule, $timezone, $now);
            if ($runAt !== null) {
                $schedule['run_at_utc'] = gmdate('c', $runAt);
                $type = 'once';
            }
        }

        if ($type === 'interval' && !isset($schedule['interval_hours']) && isset($schedule['every_hours'])) {
            $schedule['interval_hours'] = (int)$schedule['every_hours'];
        }
        if ($type === 'interval' && !isset($schedule['interval_minutes']) && isset($schedule['every_minutes'])) {
            $schedule['interval_minutes'] = (int)$schedule['every_minutes'];
        }

        $schedule['type'] = $type;
        return $schedule;
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function resolveAbsoluteRunAt(array $schedule, string $timezone): ?int
    {
        foreach (['run_at_unix', 'run_at_ts', 'run_at_timestamp', 'timestamp'] as $key) {
            $timestamp = $this->normalizeTimestampValue($schedule[$key] ?? null);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        $timestamp = $this->parseDateTimeValue($schedule['run_at_utc'] ?? null);
        if ($timestamp !== null) {
            return $timestamp;
        }

        $timestamp = $this->parseDateTimeValue($schedule['run_at'] ?? null, $timezone);
        if ($timestamp !== null) {
            return $timestamp;
        }

        foreach (['run_at_local', 'datetime_local', 'date_time_local', 'local_datetime', 'when_local'] as $key) {
            $timestamp = $this->parseDateTimeValue($schedule[$key] ?? null, $timezone);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        $dateLocal = trim((string)($schedule['date_local'] ?? $schedule['date'] ?? ''));
        $timeLocal = trim((string)($schedule['time_local'] ?? $schedule['time'] ?? ''));
        if ($dateLocal !== '' && $timeLocal !== '') {
            return $this->parseDateTimeValue($dateLocal . ' ' . $timeLocal, $timezone);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function resolveRelativeDelayTimestamp(array $schedule, string $timezone, int $now): ?int
    {
        $seconds = max(0, (int)($schedule['delay_seconds'] ?? 0));
        $minutes = max(0, (int)($schedule['delay_minutes'] ?? 0));
        $hours = max(0, (int)($schedule['delay_hours'] ?? 0));
        $days = max(0, (int)($schedule['delay_days'] ?? 0));
        $weeks = max(0, (int)($schedule['delay_weeks'] ?? 0));
        $months = max(0, (int)($schedule['delay_months'] ?? 0));
        $years = max(0, (int)($schedule['delay_years'] ?? 0));

        if (($seconds + $minutes + $hours + $days + $weeks + $months + $years) <= 0) {
            return null;
        }

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $tz = new \DateTimeZone($this->defaultTimezone);
        }

        $dt = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        foreach ([
            'year' => $years,
            'month' => $months,
            'week' => $weeks,
            'day' => $days,
            'hour' => $hours,
            'minute' => $minutes,
            'second' => $seconds,
        ] as $unit => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $modified = $dt->modify(sprintf('+%d %s%s', $amount, $unit, $amount === 1 ? '' : 's'));
            if (!$modified instanceof \DateTimeImmutable) {
                return null;
            }
            $dt = $modified;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function applyDelayAmountUnitAlias(array &$schedule): void
    {
        foreach (['value', 'amount', 'delay', 'run_in', 'delay_value', 'run_in_value', 'count', 'quantity'] as $amountKey) {
            $amount = max(0, (int)($schedule[$amountKey] ?? 0));
            if ($amount <= 0) {
                continue;
            }

            $unit = $this->normalizeDelayUnit((string)($schedule['unit'] ?? $schedule['delay_unit'] ?? $schedule['run_in_unit'] ?? ''));
            if ($unit === null) {
                return;
            }

            $field = 'delay_' . $unit;
            if (!isset($schedule[$field])) {
                $schedule[$field] = $amount;
            }
            return;
        }
    }

    private function normalizeDelayUnit(string $unit): ?string
    {
        $normalized = mb_strtolower(trim($unit));
        return match ($normalized) {
            'second', 'seconds', 'sec', 'secs', 'сек', 'секунда', 'секунду', 'секунды', 'секунд' => 'seconds',
            'minute', 'minutes', 'min', 'mins', 'минута', 'минуту', 'минуты', 'минут', 'мин' => 'minutes',
            'hour', 'hours', 'hr', 'hrs', 'час', 'часа', 'часов', 'ч' => 'hours',
            'day', 'days', 'день', 'дня', 'дней', 'сутки', 'суток' => 'days',
            'week', 'weeks', 'неделя', 'недели', 'недель' => 'weeks',
            'month', 'months', 'месяц', 'месяца', 'месяцев' => 'months',
            'year', 'years', 'год', 'года', 'лет' => 'years',
            default => null,
        };
    }

    private function normalizeTimestampValue(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $timestamp = (int)$value;
            if ($timestamp <= 0) {
                return null;
            }

            return $timestamp > 9999999999 ? (int)floor($timestamp / 1000) : $timestamp;
        }

        $string = trim((string)$value);
        if ($string === '' || preg_match('/^\d+$/', $string) !== 1) {
            return null;
        }

        $timestamp = (int)$string;
        return $timestamp > 9999999999 ? (int)floor($timestamp / 1000) : $timestamp;
    }

    private function parseDateTimeValue(mixed $value, ?string $timezone = null): ?int
    {
        $timestamp = $this->normalizeTimestampValue($value);
        if ($timestamp !== null) {
            return $timestamp;
        }

        $string = trim((string)$value);
        if ($string === '') {
            return null;
        }

        try {
            $dt = $timezone !== null
                ? new \DateTimeImmutable($string, new \DateTimeZone($timezone))
                : new \DateTimeImmutable($string);
            return $dt->getTimestamp();
        } catch (\Throwable) {
            $parsed = strtotime($string);
            return $parsed !== false ? $parsed : null;
        }
    }

    /**
     * @param array<string, mixed> $schedule
     * @param array<string, mixed> $payload
     */
    private function logInvalidSchedule(string $operation, string $timezone, array $schedule, array $payload): void
    {
        $encoded = json_encode([
            'operation' => $operation,
            'timezone' => $timezone,
            'schedule' => $schedule,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->logger->logWebhook('AgentTaskStore rejected task schedule: ' . ($encoded !== false ? $encoded : 'unencodable payload'));
    }
}
