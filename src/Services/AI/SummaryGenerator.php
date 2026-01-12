<?php

namespace App\Services\AI;

use App\Services\LoggerService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

/**
 * Class for generating chat summaries
 */
class SummaryGenerator
{
    use HttpClientTrait;

    private array $config;
    private PromptBuilder $promptBuilder;
    private ResponseFormatter $formatter;
    private HttpClient $httpClient;
    private LoggerService $logger;

    /**
     * JSON Schema for structured summary output
     */
    private const SUMMARY_JSON_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'chat_title' => ['type' => 'string', 'description' => 'Title of the chat'],
            'time_window' => ['type' => 'string', 'description' => 'Time window covered by this summary (e.g., "Last 24 Hours, UTC")'],
            'brief_intro' => ['type' => 'string', 'description' => 'One sentence summary of the overall chat theme'],
            'topics' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Topic title'],
                        'description' => ['type' => 'string', 'description' => 'Brief description of the discussion'],
                        'message_links' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Array of message URLs related to this topic'
                        ]
                    ],
                    'required' => ['title', 'description', 'message_links']
                ],
                'description' => 'Main topics discussed in the chat'
            ],
            'active_users' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'username' => ['type' => 'string', 'description' => 'Username of the active user'],
                        'message_count' => ['type' => 'integer', 'description' => 'Number of messages sent'],
                        'word_count' => ['type' => 'integer', 'description' => 'Approximate number of words sent'],
                        'symbol_count' => ['type' => 'integer', 'description' => 'Approximate number of symbols/characters sent']
                    ],
                    'required' => ['username', 'message_count', 'word_count', 'symbol_count']
                ],
                'description' => 'List of most active users with their statistics'
            ],
            'statistics' => [
                'type' => 'object',
                'properties' => [
                    'total_messages' => ['type' => 'integer', 'description' => 'Total number of messages in the period'],
                    'total_words' => ['type' => 'integer', 'description' => 'Total words sent'],
                    'total_symbols' => ['type' => 'integer', 'description' => 'Total symbols/characters sent'],
                    'time_spent_minutes' => ['type' => 'integer', 'description' => 'Approximate time spent typing in minutes']
                ],
                'required' => ['total_messages', 'total_words', 'total_symbols', 'time_spent_minutes']
            ]
        ],
        'required' => ['chat_title', 'time_window', 'brief_intro', 'topics', 'active_users', 'statistics']
    ];

    /**
     * Constructor
     *
     * @param array $config The configuration array
     * @param PromptBuilder $promptBuilder The prompt builder
     * @param ResponseFormatter $formatter The response formatter
     * @param LoggerService $logger The logger service
     */
    public function __construct(array $config, PromptBuilder $promptBuilder, ResponseFormatter $formatter, LoggerService $logger)
    {
        $this->config = $config;
        $this->promptBuilder = $promptBuilder;
        $this->formatter = $formatter;
        $this->logger = $logger;
        $this->httpClient = new HttpClient();
    }

    /**
     * Generate a chat summary
     *
     * @param array $messages Array of messages to summarize
     * @param int|null $chatId The chat ID (optional)
     * @param string|null $chatTitle The chat title (optional)
     * @param string|null $chatUsername The chat username (optional)
     * @param string|null $windowLabel The time window label (optional)
     * @return string|null The generated summary or null if generation failed
     */
    public function generate(array $messages, ?int $chatId = null, ?string $chatTitle = null, ?string $chatUsername = null, ?string $windowLabel = null): ?string
    {
        // Create a chat identifier for logging
        $chatIdentifier = $chatId ? "chat $chatId" : "unknown chat";
        if ($chatTitle) {
            $chatIdentifier .= " ($chatTitle)";
        }

        // Check if we have enough messages to summarize
        if (count($messages) < 5) {
            $this->logger->log("Not enough messages to summarize for $chatIdentifier", "Summary", "webhook");
            $this->logger->log("Not enough messages to summarize for $chatIdentifier", "Summary", "summary");
            return null;
        }

        // Log message count
        $messageCount = count($messages);
        $this->logger->log("Processing $messageCount messages for $chatIdentifier", "Summary", "webhook");
        $this->logger->log("Processing $messageCount messages for $chatIdentifier", "Summary", "summary");

        // Build chat information
        $chatInfo = "";
        if ($chatId) {
            $chatInfo .= "Chat ID: $chatId\n";
        }
        if ($chatTitle) {
            $chatInfo .= "Chat Title: $chatTitle\n";
        }
        if ($chatUsername) {
            $chatInfo .= "Chat Username: $chatUsername\n";
        }

        // Get language setting for the chat if available
        $language = 'en'; // Default language
        if (isset($this->config['settingsService']) && $this->config['settingsService'] !== null && $chatId !== null) {
            $language = $this->config['settingsService']->getSetting($chatId, 'language', 'en');
        }

        // Log the language being used
        $this->logger->log("Using language setting: {$language} for {$chatIdentifier}", "Summary", "webhook");
        $this->logger->log("Using language setting: {$language} for {$chatIdentifier}", "Summary", "summary");
        if ($windowLabel !== null && $windowLabel !== '') {
            $this->logger->log("Using time window: {$windowLabel} (UTC) for {$chatIdentifier}", "Summary", "summary");
        }

        // Build the prompt for JSON structured output
        $prompt = $this->promptBuilder->buildSummaryJsonPrompt($messages, $language, $chatInfo, $windowLabel);

        // Build the system prompt for JSON structured output
        $systemPrompt = $this->promptBuilder->buildSummaryJsonSystemPrompt($language);

        try {
            // Log API request
            $this->logger->log("Sending structured JSON request to OpenRouter API for $chatIdentifier", "Summary", "webhook");
            $this->logger->log("Sending structured JSON request to OpenRouter API for $chatIdentifier", "Summary", "summary");

            $startTime = microtime(true);

            $response = $this->httpClient->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $this->config['openrouter_summary_model'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => 2000,
                    'temperature' => 0.3,
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'chat_summary',
                            'strict' => true,
                            'schema' => self::SUMMARY_JSON_SCHEMA
                        ]
                    ]
                ],
                'timeout' => 90,
            ]);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            // Log successful API response
            $this->logger->log("Received JSON response from OpenRouter API in {$duration}s for $chatIdentifier", "Summary", "webhook");
            $this->logger->log("Received JSON response from OpenRouter API in {$duration}s for $chatIdentifier", "Summary", "summary");

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['choices'][0]['message']['content'])) {
                $jsonContent = trim($body['choices'][0]['message']['content']);

                // Log raw JSON for debugging
                $this->logger->log("Raw JSON response: " . substr($jsonContent, 0, 500) . "...", "Summary", "summary");

                // Parse the JSON response
                $summaryData = json_decode($jsonContent, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->log("Failed to parse JSON response: " . json_last_error_msg(), "Summary", "webhook", true);
                    $this->logger->log("Failed to parse JSON response: " . json_last_error_msg(), "Summary", "summary");
                    return null;
                }

                // Format the summary from JSON data
                $formattedSummary = $this->formatSummaryFromJson($summaryData, $language);
                $summaryLength = strlen($formattedSummary);

                // Log successful summary generation
                $this->logger->log("Successfully generated formatted summary ($summaryLength chars) for $chatIdentifier", "Summary", "webhook");
                $this->logger->log("Successfully generated formatted summary ($summaryLength chars) for $chatIdentifier", "Summary", "summary");

                return $formattedSummary . "\n\n<i>model: " . htmlspecialchars($this->config['openrouter_summary_model']) . "</i>";
            }

            // Log unexpected API response format
            $this->logger->log("OpenRouter API Response format unexpected for $chatIdentifier: " . json_encode($body), "Summary", "webhook", true);
            $this->logger->log("OpenRouter API Response format unexpected for $chatIdentifier: " . json_encode($body), "Summary", "summary");

            return null;
        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';

            // Log request exception
            $this->logger->logError("OpenRouter API Request Exception for $chatIdentifier: " . $e->getMessage() . " | Response: " . $errorResponse, "Summary", $e);

            return null;
        } catch (\Exception $e) {
            // Log general exception
            $this->logger->logError("Error generating summary for $chatIdentifier: " . $e->getMessage(), "Summary", $e);

            return null;
        }
    }

    /**
     * Format the summary from structured JSON data
     *
     * @param array $data The parsed JSON summary data
     * @param string $language The language for formatting
     * @return string The formatted HTML summary for Telegram
     */
    private function formatSummaryFromJson(array $data, string $language): string
    {
        $isRussian = ($language === 'ru');

        // Build the formatted summary
        $output = [];

        // Header
        $chatTitle = htmlspecialchars($data['chat_title'] ?? 'Unknown Chat');
        $timeWindow = htmlspecialchars($data['time_window'] ?? 'Last 24 Hours, UTC');

        $headerLabel = $isRussian ? 'Сводка группы Telegram' : 'Summary of Telegram Group Chat';
        $output[] = "<b>{$headerLabel}: {$chatTitle}</b>";
        $output[] = "<i>({$timeWindow})</i>";
        $output[] = "";

        // Brief intro
        if (!empty($data['brief_intro'])) {
            $output[] = htmlspecialchars($data['brief_intro']);
            $output[] = "";
        }

        // Main Topics
        $topicsLabel = $isRussian ? 'Основные темы' : 'Main Topics';
        $output[] = "<b>{$topicsLabel}:</b>";

        if (!empty($data['topics']) && is_array($data['topics'])) {
            $topicNum = 1;
            foreach ($data['topics'] as $topic) {
                $title = htmlspecialchars($topic['title'] ?? '');
                $description = htmlspecialchars($topic['description'] ?? '');

                $output[] = "{$topicNum}. <b>{$title}</b>: {$description}";

                // Add message links if available
                if (!empty($topic['message_links']) && is_array($topic['message_links'])) {
                    $links = [];
                    foreach ($topic['message_links'] as $link) {
                        $links[] = '<a href="' . htmlspecialchars($link) . '">link</a>';
                    }
                    if (!empty($links)) {
                        $output[] = "   " . implode(", ", $links);
                    }
                }

                $topicNum++;
            }
        }
        $output[] = "";

        // Active Users Statistics
        $activeUsersLabel = $isRussian ? 'Статистика активных пользователей' : 'Statistics of Most Active Users';
        $output[] = "<b>{$activeUsersLabel}:</b>";

        if (!empty($data['active_users']) && is_array($data['active_users'])) {
            foreach ($data['active_users'] as $user) {
                $username = htmlspecialchars($user['username'] ?? 'Unknown');
                $msgCount = (int)($user['message_count'] ?? 0);
                $wordCount = (int)($user['word_count'] ?? 0);
                $symbolCount = (int)($user['symbol_count'] ?? 0);

                $msgLabel = $isRussian ? 'сообщ.' : 'msgs';
                $wordLabel = $isRussian ? 'слов' : 'words';
                $symbolLabel = $isRussian ? 'симв.' : 'chars';

                $output[] = "- <b>{$username}</b>: {$msgCount} {$msgLabel}, ~{$wordCount} {$wordLabel}, ~{$symbolCount} {$symbolLabel}";
            }
        }
        $output[] = "";

        // Total Chat Statistics
        $totalStatsLabel = $isRussian ? 'Общая статистика чата' : 'Total Chat Statistics';
        $output[] = "<b>{$totalStatsLabel}:</b>";

        if (!empty($data['statistics']) && is_array($data['statistics'])) {
            $stats = $data['statistics'];

            $totalMsgs = (int)($stats['total_messages'] ?? 0);
            $totalWords = (int)($stats['total_words'] ?? 0);
            $totalSymbols = (int)($stats['total_symbols'] ?? 0);
            $timeSpent = (int)($stats['time_spent_minutes'] ?? 0);

            if ($isRussian) {
                $output[] = "- Всего сообщений: {$totalMsgs}";
                $output[] = "- Всего слов: ~{$totalWords}";
                $output[] = "- Всего символов: ~{$totalSymbols}";

                $hours = floor($timeSpent / 60);
                $minutes = $timeSpent % 60;
                $timeStr = $hours > 0 ? "~{$hours}ч {$minutes}мин" : "~{$timeSpent} мин";
                $output[] = "- Примерное время в чате (вместо работы): {$timeStr}";
            } else {
                $output[] = "- Total Messages: {$totalMsgs}";
                $output[] = "- Total Words: ~{$totalWords}";
                $output[] = "- Total Symbols: ~{$totalSymbols}";

                $hours = floor($timeSpent / 60);
                $minutes = $timeSpent % 60;
                $timeStr = $hours > 0 ? "~{$hours}h {$minutes}min" : "~{$timeSpent} min";
                $output[] = "- Time Spent in Chat (instead of work, haha): {$timeStr}";
            }
        }

        return implode("\n", $output);
    }
}
