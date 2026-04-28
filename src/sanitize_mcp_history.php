<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\AI\McpResponseSanitizer;
use App\Services\AI\SafeMcpChatHistory;

$options = getopt('', ['dir::', 'key::', 'dry-run', 'verbose']);

$chatHistoryDir = $options['dir'] ?? (__DIR__ . '/../data/chat_history');
$targetKey = isset($options['key']) ? (string)$options['key'] : null;
$dryRun = array_key_exists('dry-run', $options);
$verbose = array_key_exists('verbose', $options);

if (!is_dir($chatHistoryDir)) {
    fwrite(STDERR, "Chat history directory not found: {$chatHistoryDir}\n");
    exit(1);
}

$files = glob($chatHistoryDir . '/mcp_*.chat') ?: [];
sort($files);

$sanitizer = new McpResponseSanitizer();
$changedFiles = [];
$checkedFiles = 0;
$changedMessages = 0;

foreach ($files as $filePath) {
    $basename = basename($filePath);
    if (!preg_match('#^mcp_(.*)\.chat$#', $basename, $matches)) {
        continue;
    }

    $historyKey = (string)$matches[1];
    if ($targetKey !== null && $targetKey !== $historyKey) {
        continue;
    }

    $checkedFiles++;

    $raw = file_get_contents($filePath);
    if (!is_string($raw) || $raw === '') {
        continue;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        if ($verbose) {
            fwrite(STDERR, "Skipping unreadable chat history: {$basename}\n");
        }
        continue;
    }

    $fileChangedMessages = 0;
    foreach ($decoded as $message) {
        $content = $message['content'] ?? null;
        $role = $message['role'] ?? null;
        if (!is_string($content) || !is_string($role)) {
            continue;
        }

        if (!in_array($role, ['assistant', 'model'], true)) {
            continue;
        }

        if ($sanitizer->sanitize($content) !== trim(str_replace(["\r\n", "\r"], "\n", $content))) {
            $fileChangedMessages++;
        }
    }

    if ($fileChangedMessages === 0) {
        continue;
    }

    if (!$dryRun) {
        new SafeMcpChatHistory($chatHistoryDir, $historyKey, 30000, 'mcp_');
    }

    $changedFiles[] = [
        'file' => $basename,
        'key' => $historyKey,
        'changed_messages' => $fileChangedMessages,
    ];
    $changedMessages += $fileChangedMessages;
}

echo 'checked_files=' . $checkedFiles . PHP_EOL;
echo 'changed_files=' . count($changedFiles) . PHP_EOL;
echo 'changed_messages=' . $changedMessages . PHP_EOL;
echo 'mode=' . ($dryRun ? 'dry-run' : 'apply') . PHP_EOL;

if ($verbose && $changedFiles !== []) {
    foreach ($changedFiles as $changedFile) {
        echo $changedFile['file'] . ' changed_messages=' . $changedFile['changed_messages'] . PHP_EOL;
    }
}
