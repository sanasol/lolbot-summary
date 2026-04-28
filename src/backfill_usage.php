<?php

/**
 * Backfill usage data from existing webhook logs.
 *
 * Parses webhook_*.log and summary_*.log files to reconstruct historical
 * usage entries. Token counts are not available in logs — stored as null.
 *
 * Run once: php src/backfill_usage.php
 * Safe to re-run: overwrites existing JSONL files for the same dates.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$dataPath = $config['log_path'] ?? __DIR__ . '/../data';
$usageDir = $dataPath . '/usage';

if (!is_dir($usageDir)) {
    mkdir($usageDir, 0755, true);
}

echo "Backfill Usage Data\n";
echo "===================\n";
echo "Data path: {$dataPath}\n\n";

// Collect all webhook log files
$logFiles = glob($dataPath . '/webhook_*.log');
sort($logFiles);

if (empty($logFiles)) {
    echo "No webhook log files found.\n";
    exit(0);
}

echo "Found " . count($logFiles) . " webhook log file(s)\n\n";

$totalEntries = 0;

foreach ($logFiles as $logFile) {
    // Extract date from filename: webhook_2026-02-18.log
    if (!preg_match('/webhook_(\d{4}-\d{2}-\d{2})\.log$/', $logFile, $m)) {
        continue;
    }
    $logDate = $m[1];
    $entries = [];

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) continue;

    echo "Processing {$logDate} (" . count($lines) . " lines)... ";

    // State tracking for MCP sessions
    $mcpStart = null;
    $mcpUser = null;

    foreach ($lines as $line) {
        // Parse timestamp: [2026-02-18 21:45:09]
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $tsMatch)) {
            continue;
        }
        $ts = $tsMatch[1];

        // --- MCP Response ---
        // [timestamp] [MCP Response] Generating MCP response for message: ...
        if (strpos($line, '[MCP Response] Generating MCP response') !== false) {
            $mcpStart = $ts;
            $mcpUser = null;
            // Try to extract username from surrounding context — not reliably in this line
            continue;
        }

        // [timestamp] [MCP Response] User 12345 subscription status: ...
        if (preg_match('/\[MCP Response\] User (\d+) subscription status/', $line, $um)) {
            $mcpUser = $um[1];
            continue;
        }

        // [timestamp] [MCP Response] Generated response: ...
        if (strpos($line, '[MCP Response] Generated response:') !== false) {
            $duration = null;
            if ($mcpStart) {
                $duration = round((strtotime($ts) - strtotime($mcpStart)), 0);
            }
            $entries[] = [
                'ts' => str_replace(' ', 'T', $ts) . '+00:00',
                'type' => 'mcp',
                'model' => null,
                'user_id' => $mcpUser ? (int)$mcpUser : null,
                'duration_s' => $duration > 0 ? $duration : null,
                'success' => true,
            ];
            $mcpStart = null;
            $mcpUser = null;
            continue;
        }

        // MCP error paths
        if (strpos($line, '[MCP Response]') !== false &&
            (strpos($line, 'Error') !== false || strpos($line, 'Exception') !== false)) {
            if ($mcpStart) {
                $entries[] = [
                    'ts' => str_replace(' ', 'T', $ts) . '+00:00',
                    'type' => 'mcp',
                    'model' => null,
                    'user_id' => $mcpUser ? (int)$mcpUser : null,
                    'duration_s' => round((strtotime($ts) - strtotime($mcpStart)), 0) ?: null,
                    'success' => false,
                    'error' => substr($line, 0, 200),
                ];
                $mcpStart = null;
                $mcpUser = null;
            }
            continue;
        }

        // --- Summary ---
        // [timestamp] [Summary] Received JSON response from OpenRouter API in 12.34s for chat 12345 (Title)
        if (preg_match('/\[Summary\] Received JSON response from OpenRouter API in ([\d.]+)s for chat (-?\d+)/', $line, $sm)) {
            $entries[] = [
                'ts' => str_replace(' ', 'T', $ts) . '+00:00',
                'type' => 'summary',
                'model' => null,
                'chat_id' => (int)$sm[2],
                'duration_s' => (float)$sm[1],
                'success' => true,
            ];
            continue;
        }

        // --- Grok/Mention Response ---
        // [timestamp] [Grok Response] Generated text response: ...
        if (strpos($line, '[Grok Response] Generated text response:') !== false) {
            $entries[] = [
                'ts' => str_replace(' ', 'T', $ts) . '+00:00',
                'type' => 'mention',
                'model' => null,
                'success' => true,
            ];
            continue;
        }

        // --- Image Generation ---
        if (strpos($line, '[Image Generation] Generating image') !== false) {
            $entries[] = [
                'ts' => str_replace(' ', 'T', $ts) . '+00:00',
                'type' => 'image',
                'model' => null,
                'success' => true,
            ];
            continue;
        }

        // --- Reaction Decision ---
        if (strpos($line, 'ReactionDecision raw:') !== false) {
            $entries[] = [
                'ts' => str_replace(' ', 'T', $ts) . '+00:00',
                'type' => 'reaction',
                'model' => null,
                'success' => true,
            ];
            continue;
        }
    }

    // Write JSONL
    if (!empty($entries)) {
        $outFile = $usageDir . '/' . $logDate . '.jsonl';
        $content = '';
        foreach ($entries as $entry) {
            // Fill defaults
            $entry += [
                'chat_id' => null, 'chat_title' => null,
                'user_id' => null, 'username' => null,
                'model' => 'unknown (backfill)',
                'input_tokens' => null, 'output_tokens' => null,
                'tool_calls' => null, 'duration_s' => null,
                'success' => true, 'error' => null,
            ];
            $content .= json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        }
        file_put_contents($outFile, $content);
        echo count($entries) . " entries written to {$logDate}.jsonl\n";
        $totalEntries += count($entries);
    } else {
        echo "0 entries found\n";
    }
}

// Also check summary logs
$summaryLogs = glob($dataPath . '/summary_*.log');
sort($summaryLogs);

if (!empty($summaryLogs)) {
    echo "\nProcessing " . count($summaryLogs) . " summary log file(s)...\n";

    foreach ($summaryLogs as $logFile) {
        if (!preg_match('/summary_(\d{4}-\d{2}-\d{2})\.log$/', $logFile, $m)) {
            continue;
        }
        $logDate = $m[1];
        $entries = [];

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) continue;

        foreach ($lines as $line) {
            if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $tsMatch)) {
                continue;
            }
            $ts = $tsMatch[1];

            if (preg_match('/Received JSON response from OpenRouter API in ([\d.]+)s for chat (-?\d+)\s*\(([^)]+)\)/', $line, $sm)) {
                $entries[] = [
                    'ts' => str_replace(' ', 'T', $ts) . '+00:00',
                    'type' => 'summary',
                    'model' => 'unknown (backfill)',
                    'chat_id' => (int)$sm[2],
                    'chat_title' => $sm[3],
                    'duration_s' => (float)$sm[1],
                    'success' => true,
                    'input_tokens' => null, 'output_tokens' => null,
                    'user_id' => null, 'username' => null,
                    'tool_calls' => null, 'error' => null,
                ];
            }
        }

        if (!empty($entries)) {
            // Append to existing JSONL (don't overwrite webhook-derived data)
            $outFile = $usageDir . '/' . $logDate . '.jsonl';
            $content = '';
            foreach ($entries as $entry) {
                $content .= json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
            }
            file_put_contents($outFile, $content, FILE_APPEND);
            echo "  {$logDate}: " . count($entries) . " summary entries appended\n";
            $totalEntries += count($entries);
        }
    }
}

echo "\nDone! Total entries: {$totalEntries}\n";
