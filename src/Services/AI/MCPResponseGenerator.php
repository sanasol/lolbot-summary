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

    /**
     * Constructor
     *
     * @param array $config The configuration array
     * @param ResponseFormatter $formatter The response formatter
     * @param LoggerService $logger The logger service
     */
    public function __construct(array $config, ResponseFormatter $formatter, LoggerService $logger)
    {
        $this->config = $config;
        $this->formatter = $formatter;
        $this->logger = $logger;
    }

    /**
     * Generate a response using ClickhouseAgent with MCP (Multi-Content Payload) support
     *
     * @param string $messageText The original message text
     * @param string $username The username of the message sender
     * @param string $chatContext Optional context from recent chat messages
     * @return array The generated response.
     *              Format: ['type' => 'text', 'content' => string, 'tool_calls' => array|null]
     *              Or error: ['type' => 'error', 'content' => string, 'error_type' => string]
     */
    public function generate(string $messageText, string $username, string $chatContext = ''): array
    {
        try {
            // Log request
            $this->logger->log("Generating MCP response for message: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : ''), "MCP Response", "webhook");

            // Create a user message with the input
            $userMessage = new \NeuronAI\Chat\Messages\UserMessage("User {$username} says: {$messageText}");

            $inspector = new Inspector(
                new Configuration($this->config['inspector_ingestion_key'])
            );

            // Initialize the ClickhouseAgent
            $agent = \App\Services\ClickhouseAgent::make($this->config)
                ->observe(
                    new AgentMonitoring($inspector)
                );
                
            // Log that we're sending the message to the agent
            $this->logger->log("Sending message to ClickhouseAgent", "MCP Response", "webhook");

            // Get response from the agent
            $response = $agent->chat($userMessage);
            $content = $response->getContent();
            $usage = $response->getUsage();
            $in_tokens = $usage?->inputTokens;
            $out_tokens = $usage?->outputTokens;

            // Log successful response generation
            $this->logger->log("Generated response: " . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : ''), "MCP Response", "webhook");

            return [
                'type' => 'text',
                'content' => !empty($content) ? ($content.' 
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