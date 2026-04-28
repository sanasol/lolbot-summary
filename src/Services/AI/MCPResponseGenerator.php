<?php

namespace App\Services\AI;

use App\Services\LoggerService;
use GuzzleHttp\Exception\ClientException;
use NeuronAI\Exceptions\NeuronException;
use NeuronAI\Observability\AgentMonitoring;
use Inspector\Configuration;
use Inspector\Inspector;

/**
 * Class for generating MCP (Multi-Content Payload) responses
 */
class MCPResponseGenerator
{
    use HttpClientTrait;

    private array $config;
    private ResponseFormatter $formatter;
    private LoggerService $logger;
    private ?\App\Services\SettingsService $settingsService;
    private McpResponseSanitizer $sanitizer;

    /**
     * Detect if model response accidentally contains parts of the internal system prompt
     * to avoid leaking instructions to end users.
     */
    private function containsSystemPromptLeak(?string $content): bool
    {
        if (empty($content)) {
            return false;
        }

        $needles = [
            // Distinctive phrases from ClickhouseAgent::instructions()
            'You are an AI Agent specialized in writing summaries for data from database',
            'Database is clickhouse version',
            'database definition for clickhouse',
            'logs_v2 table available but for requests not more than 1 day',
            'Rooms gender mapping: 0=Male, 1=Female, 2=Trans, 3=Couple',
            'Messages gender mapping: f=Female, m=Male, c=Couple, s=Trans',
            'NAME MUST BE IN LOWERCASE',
            'Use the tools you have available to retrieve the requested data',
            'Provide a summary of the content',
            'Include detailed information about what queries made to DB',
            'Use html formatting for final result, dont use html tables',
            'DONT MAKE SUMMARIES THAT REQUESTED more than 30 days of data',
            'DONT QUERY any data before this date',
            'DONT ANSWER questions about before this date',
            'DONT MAKE CLICKHOUSE queries that can use return anything before this date',
            'By default use chaturbate database unless otherwise specified',
            'Data in database stored in UTC timezone',
        ];

        $haystack = mb_strtolower($content);
        foreach ($needles as $needle) {
            if (strpos($haystack, mb_strtolower($needle)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Constructor
     *
     * @param array $config The configuration array
     * @param ResponseFormatter $formatter The response formatter
     * @param LoggerService $logger The logger service
     * @param \App\Services\SettingsService|null $settingsService The settings service
     */
    public function __construct(array $config, ResponseFormatter $formatter, LoggerService $logger, ?\App\Services\SettingsService $settingsService = null)
    {
        $this->config = $config;
        $this->formatter = $formatter;
        $this->logger = $logger;
        $this->settingsService = $settingsService;
        $this->sanitizer = new McpResponseSanitizer();
    }

    /**
     * Check if a user has an active subscription
     *
     * @param int $userId The user ID
     * @return bool Whether the user has an active subscription
     */
    private function checkUserSubscription(int $userId): bool
    {
        if ($this->settingsService === null) {
            return false;
        }

        // Get the account identifier from user settings
        $accountIdentifier = $this->settingsService->getSetting($userId, 'account_identifier', null);

        if (empty($accountIdentifier)) {
            return false;
        }

        try {
            $client = $this->getHttpClient();

            $response = $client->request('GET', 'https://plus.statbate.com/api/me/simple', [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $accountIdentifier
                ]
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $data = json_decode($response->getBody(), true);

                // Check if the user has an active subscription
                if (isset($data['subscription']) && isset($data['subscription']['is_active']) && $data['subscription']['is_active'] === true) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->logError("Error checking user subscription: " . $e->getMessage(), "Subscription Check", $e);
            return false;
        }
    }

    /**
     * Get HTTP client for API requests
     *
     * @return \GuzzleHttp\Client
     */
    private function getHttpClient(): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client([
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);
    }

    /**
     * Generate a response using ClickhouseAgent with MCP (Multi-Content Payload) support
     *
     * @param string $messageText The original message text
     * @param string $username The username of the message sender
     * @param string $chatContext Optional context from recent chat messages
     * @param int|null $userId The user ID for checking subscription status
     * @param int|null $chatId The chat ID for sending status updates
     * @param \App\Services\TelegramSender|null $sender The Telegram sender for chat actions
     * @param int|null $threadId The message thread ID for forum topics
     * @return array The generated response.
     *              Format: ['type' => 'text', 'content' => string, 'tool_calls' => array|null]
     *              Or error: ['type' => 'error', 'content' => string, 'error_type' => string]
     */
    /**
     * Check if an exception is a transient connection/transport error worth retrying
     */
    private function isTransientError(\Throwable $e): bool
    {
        $message = $e->getMessage();
        $transientPatterns = [
            'cURL error 18',  // transfer closed with outstanding read data remaining
            'cURL error 28',  // connection timed out
            'cURL error 35',  // SSL connect error
            'cURL error 52',  // empty reply from server
            'cURL error 56',  // recv failure
            'Connection reset',
            'Connection refused',
            'transfer closed',
        ];

        foreach ($transientPatterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    public function generate(string $messageText, string $username, string $chatContext = '', ?int $userId = null, ?int $chatId = null, ?\App\Services\TelegramSender $sender = null, ?int $threadId = null): array
    {
        $startTime = microtime(true);
        try {
            // Log request
            $this->logger->log("Generating MCP response for message: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : ''), "MCP Response", "webhook");

            // Create a user message with explicit "current request" marker so the AI doesn't
            // confuse it with previous requests in chat history
            $currentTimestamp = date('Y-m-d H:i:s');
            $markedMessage = "[NEW REQUEST at {$currentTimestamp}] {$messageText}";
            $userMessage = new \NeuronAI\Chat\Messages\Message(\NeuronAI\Chat\Enums\MessageRole::USER, $markedMessage);

            $inspector = new Inspector(
                (new Configuration($this->config['inspector_ingestion_key']))
                    ->setTransport('curl')
            );

            // Check if user has an active subscription
            $hasActiveSubscription = false;
            if ($userId !== null) {
                $hasActiveSubscription = $this->checkUserSubscription($userId);
                $this->logger->log("User {$userId} subscription status: " . ($hasActiveSubscription ? "Active" : "Inactive"), "MCP Response", "webhook");
            }

            // Set up chat action sender for status updates during long operations
            if ($chatId !== null && $sender !== null) {
                \App\Services\ClickhouseAgent::setChatActionSender($chatId, $sender, $threadId);
            }

            // Initialize the ClickhouseAgent
            $agent = \App\Services\ClickhouseAgent::make($this->config, $hasActiveSubscription)
                ->observe(
                    new AgentMonitoring($inspector)
                );

            // Set up persistent chat history for group/thread context
            if ($chatId !== null) {
                $historyKey = $threadId !== null ? "{$chatId}_{$threadId}" : (string)$chatId;
                $historyDir = $this->config['log_path'] ?? '/tmp';
                $chatHistoryDir = $historyDir . '/chat_history';

                // Create directory if it doesn't exist
                if (!is_dir($chatHistoryDir)) {
                    @mkdir($chatHistoryDir, 0755, true);
                }

                if (is_dir($chatHistoryDir)) {
                    try {
                        // Use sanitized file-backed chat history so one broken model output
                        // cannot poison future MCP requests in the same topic.
                        $chatHistory = new SafeMcpChatHistory($chatHistoryDir, $historyKey, 30000, 'mcp_');
                        $agent->withChatHistory($chatHistory);
                        $this->logger->log("Using persistent chat history for key: {$historyKey}", "MCP Response", "webhook");
                    } catch (\Exception $e) {
                        $this->logger->logError("Failed to initialize chat history: " . $e->getMessage(), "MCP Response", $e);
                        // Continue without persistent history
                    }
                }
            }

            // Add chat action observer if chat ID and sender provided
            if ($chatId !== null && $sender !== null) {
                $chatActionObserver = new \App\Services\ChatActionObserver($chatId, $sender, $threadId);
                $agent->observe($chatActionObserver);
            }

            // Log that we're sending the message to the agent
            $this->logger->log("Sending message to ClickhouseAgent", "MCP Response", "webhook");

            // Get response from the agent with a single retry on transient connection errors
            $response = null;
            $maxRetries = 1;
            $lastException = null;

            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                try {
                    if ($attempt > 0) {
                        $this->logger->log("Retrying AI request (attempt " . ($attempt + 1) . "/" . ($maxRetries + 1) . ") after transient error", "MCP Response", "webhook");
                        // Re-create agent for clean state on retry
                        $agent = \App\Services\ClickhouseAgent::make($this->config, $hasActiveSubscription)
                            ->observe(new AgentMonitoring($inspector));
                        if (isset($chatHistory)) {
                            $agent->withChatHistory($chatHistory);
                        }
                        if ($chatId !== null && $sender !== null) {
                            $agent->observe(new \App\Services\ChatActionObserver($chatId, $sender, $threadId));
                        }
                    }
                    $response = $agent->chat($userMessage);
                    break; // Success, exit retry loop
                } catch (\Exception $retryEx) {
                    $lastException = $retryEx;
                    if ($attempt < $maxRetries && $this->isTransientError($retryEx)) {
                        $this->logger->log("Transient error detected, will retry: " . $retryEx->getMessage(), "MCP Response", "webhook");
                        sleep(2); // Brief pause before retry
                        continue;
                    }
                    throw $retryEx; // Not transient or out of retries, propagate
                }
            }

            // Clean up chat action sender
            \App\Services\ClickhouseAgent::setChatActionSender(null, null);

            $inspector->flush();
            $content = $response->getContent();
            if (!\is_string($content)) {
                $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }
            $usage = $response->getUsage();
            $in_tokens = $usage?->inputTokens;
            $out_tokens = $usage?->outputTokens;

            $sanitizedContent = $this->sanitizer->sanitize($content);
            if ($sanitizedContent !== $content) {
                $this->logger->log(
                    "Sanitized suspicious MCP response (original_len=" . mb_strlen($content) . ", cleaned_len=" . mb_strlen($sanitizedContent) . ")",
                    "MCP Response",
                    "webhook"
                );
            }

            if (trim($sanitizedContent) === '') {
                $this->logger->logError("Model response became empty after MCP sanitization", "MCP Response");
                return $this->formatter->formatErrorResponse(
                    'The AI model returned a corrupted response. Please try again.',
                    'corrupted_response'
                );
            }

            // Check for potential system prompt leakage after sanitization.
            if ($this->containsSystemPromptLeak($sanitizedContent)) {
                $this->logger->logError("Detected system prompt leakage in model output. Returning error response.", "MCP Response");
                return $this->formatter->formatErrorResponse('Request failed. Please try again.', 'system_prompt_leak');
            }

            $content = $sanitizedContent;

            // Log successful response generation
            $this->logger->log("Generated response: " . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : ''), "MCP Response", "webhook");

            // Track usage
            \App\Services\UsageTracker::track([
                'chat_id' => $chatId,
                'user_id' => $userId,
                'username' => $username,
                'type' => 'mcp',
                'model' => $this->config['openrouter_tool_model'] ?? 'unknown',
                'input_tokens' => $in_tokens,
                'output_tokens' => $out_tokens,
                'tool_calls' => null,
                'duration_s' => round(microtime(true) - $startTime, 2),
                'success' => true,
            ]);

            // Add subscription footer if user has active subscription
            $subscriptionFooter = '';
            if ($hasActiveSubscription) {
                $subscriptionFooter = "\n\n<i>This request was made with an active Statbate Plus subscription without 30 day limit.</i>";
            }

            if (!empty($content)) {
                return [
                    'type' => 'text',
                    'content' => $content . $subscriptionFooter . " \n\nmodel:" . $this->config['openrouter_tool_model']
                ];
            }

            // Empty content from model — return a clear error
            $this->logger->logError("AI model returned empty content", "MCP Response");
            return $this->formatter->formatErrorResponse(
                'The AI model returned an empty response. Please try again.',
                'empty_response'
            );

        } catch (ClientException $e) {
            \App\Services\UsageTracker::track([
                'chat_id' => $chatId, 'user_id' => $userId, 'username' => $username,
                'type' => 'mcp', 'model' => $this->config['openrouter_tool_model'] ?? 'unknown',
                'duration_s' => round(microtime(true) - $startTime, 2),
                'success' => false, 'error' => $e->getMessage(),
            ]);
            return $this->handleClientException($e, "MCP Response");
        } catch (NeuronException $e) {
            $this->logger->logError("NeuronException response: " . $e->getMessage(), "MCP Response", $e);
            \App\Services\UsageTracker::track([
                'chat_id' => $chatId, 'user_id' => $userId, 'username' => $username,
                'type' => 'mcp', 'model' => $this->config['openrouter_tool_model'] ?? 'unknown',
                'duration_s' => round(microtime(true) - $startTime, 2),
                'success' => false, 'error' => $e->getMessage(),
            ]);
            return $this->formatter->formatNeuronErrorResponse($e->getMessage());
        } catch (\Exception $e) {
            // Log general exception
            $this->logger->logError("Error generating MCP response: " . $e->getMessage(), "MCP Response", $e);
            \App\Services\UsageTracker::track([
                'chat_id' => $chatId, 'user_id' => $userId, 'username' => $username,
                'type' => 'mcp', 'model' => $this->config['openrouter_tool_model'] ?? 'unknown',
                'duration_s' => round(microtime(true) - $startTime, 2),
                'success' => false, 'error' => $e->getMessage(),
            ]);
            $this->logger->log("jsonerr: " . get_class($e), "MCP Response", "webhook");

            $errorMessage = $e->getMessage();

            // Check if it's a server overload error
            if (strpos($errorMessage, 'overloaded') !== false || strpos($errorMessage, '529') !== false) {
                return $this->formatter->formatOverloadErrorResponse();
            }

            // Provide specific error for connection/transport failures
            if ($this->isTransientError($e)) {
                return $this->formatter->formatErrorResponse(
                    'AI provider connection failed after retry. Please try again in a moment.',
                    'connection_error'
                );
            }

            return $this->formatter->formatGeneralErrorResponse();
        }
    }
}
