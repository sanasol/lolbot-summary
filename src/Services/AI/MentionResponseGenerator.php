<?php

namespace App\Services\AI;

use App\Services\LoggerService;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client as HttpClient;

/**
 * Class for generating responses to bot mentions
 */
class MentionResponseGenerator
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
     * Generate a response for bot mentions
     *
     * @param string $messageText The original message text
     * @param string $username The username of the message sender
     * @param string $chatContext Optional context from recent chat messages
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @param bool $isBase64 Whether the image is base64 encoded
     * @param int $chatId The chat ID
     * @return array|null The generated response or null if generation failed.
     *                   Format: ['type' => 'text|image', 'content' => string, 'image_url' => string|null]
     */
    public function generate(string $messageText, string $username, string $chatContext = '', ?string $inputImageUrl = null, bool $isBase64 = false, int $chatId = 0): ?array
    {
        try {
            // Log API request
            $this->logger->log("Generating Grok response for message: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : ''), "Grok Response", "webhook");

            // First, check if we should respond at all using structured output
            $shouldRespondPrompt = $this->promptBuilder->buildShouldRespondPrompt($messageText);

            $shouldRespondResponse = $this->httpClient->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $this->config['openrouter_chat_model'],
                    'messages' => [
                        ['role' => 'user', 'content' => $shouldRespondPrompt]
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'response_confidence',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'confidence_score' => [
                                        'type' => 'integer',
                                        'description' => 'Confidence score from 0-100 indicating how likely the message needs a response'
                                    ],
                                    'reason' => [
                                        'type' => 'string',
                                        'description' => 'Brief explanation for the confidence score'
                                    ]
                                ],
                                'required' => ['confidence_score', 'reason'],
                                'additionalProperties' => false
                            ]
                        ]
                    ],
                    'max_tokens' => 100,
                    'temperature' => 0.1,
                ],
                'timeout' => 30,
            ]);

            $content = $shouldRespondResponse->getBody()->getContents();
            $this->logger->log("Raw shouldRespond API response: " . $content, "Grok Response", "webhook");

            $body = json_decode($content, true);
            $this->logger->log("Decoded shouldRespond API response: " . json_encode($body), "Grok Response", "webhook");

            // Parse the structured JSON response
            $shouldRespond = 0;
            $reason = '';

            if (isset($body['choices'][0]['message']['content'])) {
                try {
                    $responseData = json_decode($body['choices'][0]['message']['content'], true);
                    if (isset($responseData['confidence_score'])) {
                        $shouldRespond = (int)$responseData['confidence_score'];
                        $reason = $responseData['reason'] ?? '';

                        $this->logger->log("Parsed confidence score: " . $shouldRespond . ", Reason: " . $reason, "Grok Response", "webhook");
                    }
                } catch (\Exception $e) {
                    $this->logger->logError("Error parsing structured response: " . $e->getMessage(), "Grok Response", $e);
                }
            }

            if ($shouldRespond < 50) {
                $this->logger->log("Decided not to respond to message (confidence score: " . $shouldRespond . ")", "Grok Response", "webhook");
                return null;
            }

            // If we should respond with text, generate a response
            $this->logger->log("Generating text response for message from " . $username, "Grok Response", "webhook");

            // Get language setting for the chat if available
            $language = 'en'; // Default language
            if (isset($this->config['settingsService']) && $this->config['settingsService'] !== null) {
                $language = $this->config['settingsService']->getSetting($chatId, 'language', 'en');
            }

            // Build the system prompt
            $systemPrompt = $this->promptBuilder->buildMentionSystemPrompt($language, $chatContext);
            
            // Build the user prompt
            $userPrompt = $this->promptBuilder->buildMentionUserPrompt($messageText, $username);

            $params = [
                'model' => $this->config['openrouter_chat_model'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt]
                ],
                'max_tokens' => 200,
                'temperature' => 0.5, // Higher temperature for more creative responses
            ];

            $this->log(json_encode($params), $logPrefix, $webhookLogFile);

            $response = $this->httpClient->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => $params,
                'timeout' => 30,
            ]);

            $responseContent = $response->getBody()->getContents();
            $this->log("Raw API response: " . $responseContent, $logPrefix, $webhookLogFile);

            $body = json_decode($responseContent, true);

            if (isset($body['choices'][0]['message']['content'])) {
                $grokResponse = trim($body['choices'][0]['message']['content']);

                // Log successful response generation
                $this->logger->log("Generated text response: " . $grokResponse, "Grok Response", "webhook");

                return $this->formatter->formatTextResponse($grokResponse);
            }

            // Log unexpected API response format
            $this->logger->log("API Response format unexpected: " . json_encode($body), "Grok Response", "webhook", true);
            return null;

        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $this->logger->logError("API Request Exception: " . $e->getMessage() . " | Response: " . $errorResponse, "Grok Response", $e);
            return null;
        } catch (\Exception $e) {
            $this->logger->logError("Error generating response: " . $e->getMessage(), "Grok Response", $e);
            return null;
        }
    }
}