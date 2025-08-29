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
     * @return string|null The generated summary or null if generation failed
     */
    public function generate(array $messages, ?int $chatId = null, ?string $chatTitle = null, ?string $chatUsername = null): ?string
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

        $conversation = implode("\n", $messages);

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

        // Build the prompt
        $prompt = $this->promptBuilder->buildSummaryPrompt($messages, $language, $chatInfo);

        // Build the system prompt
        $systemPrompt = $this->promptBuilder->buildSummarySystemPrompt($language);

        try {
            // Log API request
            $this->logger->log("Sending request to OpenRouter API for $chatIdentifier", "Summary", "webhook");
            $this->logger->log("Sending request to OpenRouter API for $chatIdentifier", "Summary", "summary");

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
                    'max_tokens' => 1000, // Adjust as needed
                    'temperature' => 0.5, // Adjust for creativity vs factualness
                ],
                'timeout' => 60, // Increase timeout for potentially long API calls
            ]);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            // Log successful API response
            $this->logger->log("Received response from OpenRouter API in {$duration}s for $chatIdentifier", "Summary", "webhook");
            $this->logger->log("Received response from OpenRouter API in {$duration}s for $chatIdentifier", "Summary", "summary");

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['choices'][0]['message']['content'])) {
                $summary = trim($body['choices'][0]['message']['content']);
                $summaryLength = strlen($summary);

                // Log successful summary generation
                $this->logger->log("Successfully generated summary ($summaryLength chars) for $chatIdentifier", "Summary", "webhook");
                $this->logger->log("Successfully generated summary ($summaryLength chars) for $chatIdentifier", "Summary", "summary");

                return $summary."\n\nmodel:".$this->config['openrouter_summary_model'];
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
}
