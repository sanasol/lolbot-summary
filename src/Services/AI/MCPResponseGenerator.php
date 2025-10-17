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
            'By default use statbate database unless otherwise specified',
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
     * @return array The generated response.
     *              Format: ['type' => 'text', 'content' => string, 'tool_calls' => array|null]
     *              Or error: ['type' => 'error', 'content' => string, 'error_type' => string]
     */
    public function generate(string $messageText, string $username, string $chatContext = '', ?int $userId = null): array
    {
        try {
            // Log request
            $this->logger->log("Generating MCP response for message: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : ''), "MCP Response", "webhook");

            // Create a user message with the input (compatible with both enum and string role implementations)
            $userMessage = new \NeuronAI\Chat\Messages\Message(\NeuronAI\Chat\Enums\MessageRole::USER, $messageText);

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

            // Initialize the ClickhouseAgent
            $agent = \App\Services\ClickhouseAgent::make($this->config, $hasActiveSubscription)
                ->observe(
                    new AgentMonitoring($inspector)
                );

            // Log that we're sending the message to the agent
            $this->logger->log("Sending message to ClickhouseAgent", "MCP Response", "webhook");

            // Get response from the agent
            $response = $agent->chat($userMessage);
            $inspector->flush();
            $content = $response->getContent();
            $usage = $response->getUsage();
            $in_tokens = $usage?->inputTokens;
            $out_tokens = $usage?->outputTokens;

            // Check for potential system prompt leakage
            if ($this->containsSystemPromptLeak($content)) {
                $this->logger->logError("Detected system prompt leakage in model output. Returning error response.", "MCP Response");
                return $this->formatter->formatErrorResponse('Request failed. Please try again.', 'system_prompt_leak');
            }


            // Log successful response generation
            $this->logger->log("Generated response: " . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : ''), "MCP Response", "webhook");

            // Add subscription footer if user has active subscription
            $subscriptionFooter = '';
            if ($hasActiveSubscription) {
                $subscriptionFooter = "\n\n<i>This request was made with an active Statbate Plus subscription without 30 day limit.</i>";
            }

            return [
                'type' => 'text',
                'content' => !empty($content) ? ($content.$subscriptionFooter.' 

model:'.$this->config['openrouter_tool_model']) : 'Something went wrong. Please try again later. model: '.$this->config['openrouter_tool_model']
            ];

        } catch (ClientException $e) {
            return $this->handleClientException($e, "MCP Response");
        } catch (NeuronException $e) {
            // catch all the exception generated just from the agent
            $this->logger->logError("NeuronException response: " . $e->getMessage(), "MCP Response", $e);
            return $this->formatter->formatNeuronErrorResponse($e->getMessage());
        } catch (\Exception $e) {
            // Log general exception
            $this->logger->logError("Error generating MCP response: " . $e->getMessage(), "MCP Response", $e);
            $this->logger->log("jsonerr: " . get_class($e), "MCP Response", "webhook");

            // Check if it's a server overload error
            $errorMessage = $e->getMessage();

            if (strpos($errorMessage, 'overloaded') !== false || strpos($errorMessage, '529') !== false) {
                return $this->formatter->formatOverloadErrorResponse();
            }

            return $this->formatter->formatGeneralErrorResponse();
        }
    }
}
