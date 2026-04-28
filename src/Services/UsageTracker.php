<?php

namespace App\Services;

/**
 * Tracks AI API usage to daily JSONL files for cost analysis.
 * All methods are static and silently catch errors to never break bot operation.
 */
class UsageTracker
{
    private static ?string $dataPath = null;
    private static array $requestContext = [];

    /**
     * Set the base data path (normally config['log_path'])
     */
    public static function setDataPath(string $path): void
    {
        self::$dataPath = $path;
    }

    /**
     * Set request-level context that auto-merges into every track() call.
     * Fields set here act as defaults — explicit values in track() take priority.
     */
    public static function setContext(array $ctx): void
    {
        self::$requestContext = $ctx;
    }

    public static function clearContext(): void
    {
        self::$requestContext = [];
    }

    /**
     * Track an AI API request.
     *
     * @param array $data Associative array with keys:
     *   - chat_id (int|null)
     *   - chat_title (string|null)
     *   - user_id (int|null)
     *   - username (string|null)
     *   - type (string) one of: mcp, summary, mention, image, antispam, reaction, agent
     *   - model (string)
     *   - input_tokens (int|null)
     *   - output_tokens (int|null)
     *   - tool_calls (int|null)
     *   - duration_s (float|null)
     *   - success (bool)
     *   - error (string|null)
     */
    public static function track(array $data): void
    {
        try {
            // Merge request context as defaults (explicit $data values win)
            $data = array_merge(self::$requestContext, $data);

            $basePath = self::$dataPath ?: (__DIR__ . '/../../data');
            $dir = $basePath . '/usage';

            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $entry = [
                'ts' => date('c'),
                'chat_id' => $data['chat_id'] ?? null,
                'chat_title' => $data['chat_title'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'username' => $data['username'] ?? null,
                'type' => $data['type'] ?? 'unknown',
                'model' => $data['model'] ?? 'unknown',
                'input_tokens' => $data['input_tokens'] ?? null,
                'output_tokens' => $data['output_tokens'] ?? null,
                'tool_calls' => $data['tool_calls'] ?? null,
                'duration_s' => $data['duration_s'] ?? null,
                'trigger' => $data['trigger'] ?? null,
                'route' => $data['route'] ?? null,
                'tone' => $data['tone'] ?? null,
                'intent' => $data['intent'] ?? null,
                'analytics_confidence' => $data['analytics_confidence'] ?? null,
                'image_intent' => $data['image_intent'] ?? null,
                'tools_used' => $data['tools_used'] ?? null,
                'tool_count' => $data['tool_count'] ?? null,
                'tool_errors' => $data['tool_errors'] ?? null,
                'memory_read_count' => $data['memory_read_count'] ?? null,
                'memory_write_count' => $data['memory_write_count'] ?? null,
                'task_writes' => $data['task_writes'] ?? null,
                'task_failures' => $data['task_failures'] ?? null,
                'task_operation' => $data['task_operation'] ?? null,
                'success' => $data['success'] ?? true,
                'error' => $data['error'] ?? null,
            ];

            $file = $dir . '/' . date('Y-m-d') . '.jsonl';
            @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Silently ignore — must never break bot operation
        }
    }
}
