<?php

/**
 * Test script for Summary Generator with JSON structured output
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AI\SummaryGenerator;
use App\Services\AI\PromptBuilder;
use App\Services\AI\ResponseFormatter;
use App\Services\LoggerService;

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    $configDistPath = __DIR__ . '/../config/config.php.dist';
    if (file_exists($configDistPath)) {
        copy($configDistPath, $configPath);
        echo "Configuration file created at {$configPath}. Update it if needed.\n";
    } else {
        die('Error: Configuration file not found.');
    }
}
$config = require $configPath;

// Load messages from JSON file
$messagesFilePath = __DIR__ . '/../data/-1003162515299_messages.json';
if (!file_exists($messagesFilePath)) {
    die("Error: Messages file not found at {$messagesFilePath}\n");
}

$messagesJson = file_get_contents($messagesFilePath);
$messagesData = json_decode($messagesJson, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Failed to parse messages JSON: " . json_last_error_msg() . "\n");
}

echo "=== Summary Generator Test ===\n\n";
echo "Loaded " . count($messagesData) . " messages from file\n\n";

// Convert messages to the format expected by the summary generator
// Take only the last 100 messages for testing
$messages = array_slice(array_values($messagesData), -100);

echo "Using last " . count($messages) . " messages for summary\n\n";

// Create logger using LoggerService
$logPath = __DIR__ . '/../logs';
if (!is_dir($logPath)) {
    mkdir($logPath, 0755, true);
}
$logger = new LoggerService($logPath);

// Create dependencies
$promptBuilder = new PromptBuilder();
$formatter = new ResponseFormatter();

// Create summary generator
$summaryGenerator = new SummaryGenerator($config, $promptBuilder, $formatter, $logger);

// Generate summary
echo "\n=== Generating Summary ===\n\n";

$chatId = -1003162515299;
$chatTitle = "Statbate - Webcam Community";
$chatUsername = "statbate";
$windowLabel = "Last 24 Hours, UTC";

$summary = $summaryGenerator->generate(
    $messages,
    $chatId,
    $chatTitle,
    $chatUsername,
    $windowLabel
);

echo "\n=== Generated Summary ===\n\n";

if ($summary !== null) {
    // Output the summary (strip HTML for CLI readability)
    $cliSummary = strip_tags($summary);
    echo $cliSummary . "\n";

    echo "\n=== Raw HTML Output ===\n\n";
    echo $summary . "\n";
} else {
    echo "Failed to generate summary\n";
}
