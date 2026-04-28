<?php

/**
 * Script to extract all /mcp command requests from logs in data/webhook_date.log
 * Only extracts from log entries starting with {"update_id":, ignoring other logs as duplicated or concatenated.
 *
 * Usage: php src/extract_mcp_commands.php [logfile|date|all]
 *
 * If no argument is specified, it will process the most recent log file in the data directory.
 * If a date is specified in YYYY-MM-DD format, it will process the log file for that date.
 * If "all" is specified, it will process all log files in the data directory.
 * If a specific logfile is specified, it will process that file.
 *
 * Examples:
 * php src/extract_mcp_commands.php
 * php src/extract_mcp_commands.php 2025-06-05
 * php src/extract_mcp_commands.php all
 * php src/extract_mcp_commands.php webhook_2025-06-05.log
 */

// Load configuration
$config = require __DIR__ . '/../config/config.php';
$logPath = $config['log_path'] ?? __DIR__ . '/../data';

// Define patterns to match
//$jsonPattern = '/"text":"\/mcp\s+([^"]+)"/';
$jsonPattern = '/"text":"([^"]+)"/';
// Only extract from logs starting with {"update_id":
$updateIdPattern = '/^\{"update_id":/';

/**
 * Process a single log file and extract MCP commands
 *
 * @param string $logFile Path to the log file
 * @return array Array of extracted MCP commands
 */
function processLogFile($logFile) {
    global $jsonPattern, $updateIdPattern;

    echo "Processing log file: {$logFile}\n";

    // Initialize array to store extracted commands
    $mcpCommands = [];

    // Read the log file line by line
    $handle = fopen($logFile, 'r');
    if (!$handle) {
        echo "Error: Unable to open log file: {$logFile}\n";
        return [];
    }

    $lineNumber = 0;
    while (($line = fgets($handle)) !== false) {
        $lineNumber++;
        $timestamp = '';

        // Extract timestamp if present
        if (preg_match('/^\[([\d-]+ [\d:]+)\]/', $line, $matches)) {
            $timestamp = $matches[1];
        }

        // Only process lines that start with {"update_id":
        if (preg_match($jsonPattern, $line, $matches)) {
            // Extract the command text
            $commandText = $matches[1];

            // Try to extract chat ID and username from the JSON
            $chatId = 'unknown';
            $chatsId = 'unknown';
            $username = 'unknown';

            if (preg_match('/"title":"([^"]+)"/', $line, $chatMatches)) {
                $chatId = $chatMatches[1];
            }

            if (preg_match('/"chat":{"id":(-\d+)/', $line, $chatsMatches)) {
                $chatsId = $chatsMatches[1];
            }

            if (preg_match('/"first_name":"([^"]+)"/', $line, $nameMatches)) {
                $username = $nameMatches[1];

                // If there's a last_name, append it
                if (preg_match('/"last_name":"([^"]+)"/', $line, $lastNameMatches)) {
                    $username .= ' ' . $lastNameMatches[1];
                }
            } elseif (preg_match('/"username":"([^"]+)"/', $line, $userMatches)) {
                $username = $userMatches[1];
            }

            $mcpCommands[] = [
                'timestamp' => $timestamp,
                'chat_id' => $chatId,
                'chats_id' => $chatsId,
                'username' => $username,
                'command' => $commandText,
                'line' => $lineNumber,
                'type' => 'json',
                'source_file' => basename($logFile)
            ];
        }
    }

    fclose($handle);

    echo "Found " . count($mcpCommands) . " MCP commands in the log file.\n";

    return $mcpCommands;
}

// Determine which log files to process
$logFiles = [];
$processAll = false;

if (isset($argv[1])) {
    if (strtolower($argv[1]) === 'all') {
        // Process all log files
        $processAll = true;
        $logFiles = glob($logPath . '/webhook_*.log');
        if (empty($logFiles)) {
            die("Error: No log files found in {$logPath}\n");
        }

        // Sort by modification time (newest first)
        usort($logFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) {
        // Process log file for specific date
        $date = $argv[1];
        $logFile = $logPath . '/webhook_' . $date . '.log';
        if (!file_exists($logFile)) {
            die("Error: Log file not found for date {$date}: {$logFile}\n");
        }
        $logFiles[] = $logFile;
    } else {
        // Process specified log file
        $logFile = $argv[1];
        if (!file_exists($logFile)) {
            $logFile = $logPath . '/' . $logFile;
            if (!file_exists($logFile)) {
                die("Error: Log file not found: {$argv[1]}\n");
            }
        }
        $logFiles[] = $logFile;
    }
} else {
    // Process the most recent log file
    $allLogFiles = glob($logPath . '/webhook_*.log');
    if (empty($allLogFiles)) {
        die("Error: No log files found in {$logPath}\n");
    }

    // Sort by modification time (newest first)
    usort($allLogFiles, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $logFiles[] = $allLogFiles[0];
}

// Initialize array to store all extracted commands
$allMcpCommands = [];

// Process each log file
foreach ($logFiles as $logFile) {
    $mcpCommands = processLogFile($logFile);
    // More efficient than array_merge in a loop
    foreach ($mcpCommands as $cmd) {
        $allMcpCommands[] = $cmd;
    }
}

// Output the results
$totalCommands = count($allMcpCommands);
echo "\nTotal MCP commands found across all processed log files: {$totalCommands}\n\n";
$chatIds = [];
if ($totalCommands > 0) {
    if (!$processAll && count($logFiles) === 1) {
        // Display detailed output only when processing a single file
        echo "=== MCP Commands ===\n\n";

        foreach ($allMcpCommands as $index => $cmd) {
            echo "Command #" . ($index + 1) . ":\n";
            echo "Timestamp: " . $cmd['timestamp'] . "\n";
            echo "Chat ID: " . $cmd['chat_id'] . "\n";
            echo "Chats ID: " . $cmd['chats_id'] . "\n";
            if (isset($cmd['chat_name']) && !empty($cmd['chat_name'])) {
                echo "Chat Name: " . $cmd['chat_name'] . "\n";
            }
            echo "Username: " . $cmd['username'] . "\n";
            echo "Command: " . $cmd['command'] . "\n";
            echo "Line: " . $cmd['line'] . "\n";
            echo "Type: " . $cmd['type'] . "\n";
            echo "Source File: " . $cmd['source_file'] . "\n";
            echo "\n";
        }
    } else {
        // For multiple files, just show a summary
        echo "Processed " . count($logFiles) . " log files.\n";
        echo "Commands have been extracted and saved to CSV.\n";
    }

    // Save to CSV file
    $csvFile = $logPath . '/mcp_commands_' . date('Y-m-d_H-i-s') . '.csv';
    $fp = fopen($csvFile, 'w');

    // Write CSV header
    fputcsv($fp, ['Timestamp', 'Chat title', 'Chat ID', 'Chat Name', 'Username', 'Command', 'Line', 'Type', 'Source File']);

    // Write data
    foreach ($allMcpCommands as $cmd) {

        $chatIds[$cmd['chat_id']] = $cmd['chats_id'];
        $row = [
            $cmd['timestamp'],
            $cmd['chat_id'],
            $cmd['chats_id'],
            $cmd['chat_name'] ?? '',
            $cmd['username'],
            $cmd['command'],
            $cmd['line'],
            $cmd['type'],
            $cmd['source_file']
        ];
        fputcsv($fp, $row);
    }

    fclose($fp);
    echo "MCP commands have been saved to: {$csvFile}\n";
}

var_dump($chatIds);
