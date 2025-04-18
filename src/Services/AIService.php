<?php

namespace App\Services;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Exceptions\NeuronException;
use NeuronAI\Exceptions\ProviderException;

class AIService
{
    private HttpClient $httpClient;
    private array $config;
    private string $logPath;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logPath = $config['log_path'] ?? (__DIR__ . '/../../data');
        $this->httpClient = new HttpClient();
    }

    /**
     * Generate a response using ClickhouseAgent with MCP (Multi-Content Payload) support
     *
     * @param string $messageText The original message text
     * @param string $username The username of the message sender
     * @param string $chatContext Optional context from recent chat messages
     * @param array|null $tools Optional array of tool definitions for MCP
     * @return array|null The generated response or null if generation failed.
     *                   Format: ['type' => 'text', 'content' => string, 'tool_calls' => array|null]
     */
    public function generateMCPResponse(string $messageText, string $username, string $chatContext = ''): ?array
    {
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] [MCP Response] ";
        $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

        try {
            // Log request
            $logMessage = $logPrefix . "Generating MCP response for message: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : '');
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Create a user message with the input
            $userMessage = new \NeuronAI\Chat\Messages\UserMessage("User {$username} says: {$messageText}");

            // Initialize the ClickhouseAgent
            $agent = \App\Services\ClickhouseAgent::make($this->config);
            // Log that we're sending the message to the agent
            $logMessage = $logPrefix . "Sending message to ClickhouseAgent";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Get response from the agent
            $response = $agent->chat($userMessage);

            // Extract content from the response
            $content = $response->getContent();

            // Log successful response generation
            $logMessage = $logPrefix . "Generated response: " . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : '');
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            return [
                'type' => 'text',
                'content' => $content,
            ];

        } catch (ClientException $e) {
            // Fired from AI providers and embedding providers
            $logMessage = $logPrefix . "ClientException response: " . $e->getResponse()->getBody()->getContents();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Get the request object
            $request = $e->getRequest();

            // Log request URL
            $logMessage = $logPrefix."Request URL: ".$request->getUri();
            file_put_contents($webhookLogFile, $logMessage.PHP_EOL, FILE_APPEND);

            // Log request method
            $logMessage = $logPrefix."Request Method: ".$request->getMethod();
            file_put_contents($webhookLogFile, $logMessage.PHP_EOL, FILE_APPEND);

            // Log request headers
            $logMessage = $logPrefix."Request Headers: ".json_encode($request->getHeaders());
            file_put_contents($webhookLogFile, $logMessage.PHP_EOL, FILE_APPEND);

            // Log request body
            $requestBody = $request->getBody()->getContents();
            $logMessage = $logPrefix."Request Body: ".$requestBody;
            file_put_contents($webhookLogFile, $logMessage.PHP_EOL, FILE_APPEND);

            // Try to decode and log JSON body in a more readable format if it's JSON
            try {
                $jsonBody = json_decode($requestBody, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $logMessage = $logPrefix."Request JSON Body: ".json_encode($jsonBody, JSON_PRETTY_PRINT);
                    file_put_contents($webhookLogFile, $logMessage.PHP_EOL, FILE_APPEND);
                }
            } catch (\Exception $jsonEx) {
                $logMessage = $logPrefix."Failed to decode request body as JSON: ".$jsonEx->getMessage();
                file_put_contents($logFile, $logMessage.PHP_EOL, FILE_APPEND);
            }


            return null;
        } catch (NeuronException $e) {
            // catch all the exception generated just from the agent
            $logMessage = $logPrefix . "NeuronException response: " . $e->getMessage();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            return null;
        } catch (\Exception $e) {
            // Log general exception
            $logMessage = $logPrefix . "Error generating MCP response: " . $e->getMessage();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $logMessage = $logPrefix . "jsonerr: " . get_class($e);
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            return null;
        }
    }

    /**
     * Generate a response using X.AI Grok API
     *
     * @param string $messageText The original message text
     * @param string $username The username of the message sender
     * @param string $chatContext Optional context from recent chat messages
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return array|null The generated response or null if generation failed.
     *                   Format: ['type' => 'text|image', 'content' => string, 'image_url' => string|null]
     */
    public function generateGrokResponse(string $messageText, string $username, string $chatContext = '', ?string $inputImageUrl = null, bool $isBase64 = false): ?array
    {
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Grok Response] ";
        $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

        try {
            // Log API request
            $logMessage = $logPrefix . "Generating Grok response for message: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : '');
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Check if the message is requesting an image generation or if user provided an image
//            $isImageRequest = $this->isImageGenerationRequest($messageText, $webhookLogFile, $logPrefix);
//
//            if ($isImageRequest) {
//                $logMessage = $logPrefix . "Detected " . ($inputImageUrl ? "image input" : "image generation request") . " from " . $username;
//                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
//
//                // Generate image using OpenRouter API
//                $imageResult = $this->generateGrokImage($messageText, $webhookLogFile, $logPrefix, $inputImageUrl);
//
//                if ($imageResult) {
//                    // Check if we got an image URL
//                    if ($imageResult['url']) {
//                        return [
//                            'type' => 'image',
//                            'image_url' => $imageResult['url'],
//                            'content' => $imageResult['text_response'] ?? null, // Include text response if available
//                            'prompt' => $imageResult['prompt'],
//                            'revised_prompt' => $imageResult['revised_prompt']
//                        ];
//                    }
//                    // If we only got a text response (no image), return it as a text response
//                    elseif (isset($imageResult['text_response'])) {
//                        $logMessage = $logPrefix . "No image generated, but received text response. Returning as text.";
//                        file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
//
//                        return [
//                            'type' => 'text',
//                            'content' => $imageResult['text_response'],
//                            'image_url' => null
//                        ];
//                    }
//                }
//
//                // If image generation fails, fall back to text response
//                $logMessage = $logPrefix . "Image generation failed, falling back to text response";
//                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
//            }

            // First, check if we should respond at all using structured output
            $shouldRespondPrompt = "Analyze this message and determine if it's asking a bot to do something, talking about a bot, or just mentioning it in passing. " .
                "Respond only if bot is mentioned in the message. Example bot mentions: bot, железяка, бот, ботик, Аполон, Аполлон. " .
                "Provide a confidence score from 0 to 100 indicating how likely the message needs a response. " .
                "Higher score means the message more likely needs a response.\n\nMessage: \"" . $messageText . "\"";

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
            $logMessage = $logPrefix . "Raw shouldRespond API response: " . $content;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($content, true);
            $logMessage = $logPrefix . "Decoded shouldRespond API response: " . json_encode($body);
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Parse the structured JSON response
            $shouldRespond = 0;
            $reason = '';

            if (isset($body['choices'][0]['message']['content'])) {
                try {
                    $responseData = json_decode($body['choices'][0]['message']['content'], true);
                    if (isset($responseData['confidence_score'])) {
                        $shouldRespond = (int)$responseData['confidence_score'];
                        $reason = $responseData['reason'] ?? '';

                        $logMessage = $logPrefix . "Parsed confidence score: " . $shouldRespond . ", Reason: " . $reason;
                        file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                    }
                } catch (\Exception $e) {
                    $logMessage = $logPrefix . "Error parsing structured response: " . $e->getMessage();
                    file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                }
            }

            if ($shouldRespond < 50) {
                $logMessage = $logPrefix . "Decided not to respond to message (confidence score: " . $shouldRespond . ")";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                return null;
            }

            // If we should respond with text, generate a response using Grok
            $logMessage = $logPrefix . "Generating Grok text response for message from " . $username;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Add chat context to the prompt if available
            $systemPrompt = "You are a witty, sarcastic bot that responds to mentions with funny memes, jokes, or clever comebacks. " .
                "Keep your response short (1-2 sentences max), funny, and appropriate for a group chat. Don't use quotes, answer from the perspective of the bot but act as the person. " .
                "Response with medium length response up to 5 sentences if message is asking something specific." .
                "Use emojis if you feel it's needed.";

            if (!empty($chatContext)) {
                $systemPrompt .= "\n\n" . $chatContext;
            }

            $params = [
                'model' => $this->config['openrouter_chat_model'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Respond to this message: \"" . $messageText . "\" from user " . $username]
                ],
                'max_tokens' => 200,
                'temperature' => 0.5, // Higher temperature for more creative responses
            ];

            file_put_contents($webhookLogFile, json_encode($params).PHP_EOL, FILE_APPEND);

            $response = $this->httpClient->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => $params,
                'timeout' => 30,
            ]);

            $responseContent = $response->getBody()->getContents();
            $logMessage = $logPrefix . "Raw API response: " . $responseContent;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($responseContent, true);

            if (isset($body['choices'][0]['message']['content'])) {
                $grokResponse = trim($body['choices'][0]['message']['content']);

                // Log successful response generation
                $logMessage = $logPrefix . "Generated text response: " . $grokResponse;
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                return [
                    'type' => 'text',
                    'content' => $grokResponse,
                    'image_url' => null
                ];
            }

            // Log unexpected API response format
            $logMessage = $logPrefix . "X.AI Grok API Response format unexpected: " . json_encode($body);
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;

        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';

            // Log request exception
            $logMessage = $logPrefix . "X.AI Grok API Request Exception: " . $e->getMessage() . " | Response: " . $errorResponse;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;
        } catch (\Exception $e) {
            // Log general exception
            $logMessage = $logPrefix . "Error generating Grok response: " . $e->getMessage();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;
        }
    }

    /**
     * Determine if a message is requesting image generation
     *
     * @param string $messageText The message text to analyze
     * @param string $logFile Path to the log file
     * @param string $logPrefix Prefix for log messages
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return bool Whether the message is requesting image generation
     */
    private function isImageGenerationRequest(string $messageText, string $logFile, string $logPrefix, ?string $inputImageUrl = null): bool
    {
        // If the user provided an image, we'll assume they want image processing
        if ($inputImageUrl) {
            return true;
        }

        try {
            // Check for common image generation keywords
            $imageKeywords = [
                'draw', 'generate image', 'create image', 'make image', 'show me', 'picture of',
                'image of', 'нарисуй', 'покажи', 'сделай картинку', 'сгенерируй', 'создай изображение'
            ];

            foreach ($imageKeywords as $keyword) {
                if (stripos($messageText, $keyword) !== false) {
                    $logMessage = $logPrefix . "Detected image generation keyword: " . $keyword;
                    file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
                    return true;
                }
            }

            // For more complex detection, we can use the Grok API to analyze the intent
            $response = $this->httpClient->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $this->config['openrouter_chat_model'],
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an assistant that determines if a message is requesting image generation. Respond with "YES" if the message is asking for an image to be created, drawn, or generated. Respond with "NO" otherwise.'],
                        ['role' => 'user', 'content' => $messageText]
                    ],
                    'max_tokens' => 10,
                    'temperature' => 0.1,
                ],
                'timeout' => 30,
            ]);

            $content = $response->getBody()->getContents();
            $body = json_decode($content, true);

            if (isset($body['choices'][0]['message']['content'])) {
                $answer = strtoupper(trim($body['choices'][0]['message']['content']));
                $isImageRequest = ($answer === 'YES');

                $logMessage = $logPrefix . "AI detection of image request: " . ($isImageRequest ? 'YES' : 'NO');
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

                return $isImageRequest;
            }

            return false;
        } catch (\Exception $e) {
            $logMessage = $logPrefix . "Error detecting image request: " . $e->getMessage();
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            return false;
        }
    }

    /**
     * Generate an image using the OpenRouter API
     *
     * @param string $messageText The message text to use as prompt
     * @param string $logFile Path to the log file
     * @param string $logPrefix Prefix for log messages
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return array|null The generated image data or null if generation failed
     */
    private function generateGrokImage(string $messageText, string $logFile, string $logPrefix, ?string $inputImageUrl = null): ?array
    {
        try {
            $logMessage = $logPrefix . "Generating image with OpenRouter API";
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Extract the image prompt from the message
            $prompt = $this->extractImagePrompt($messageText, $logFile, $logPrefix, $inputImageUrl);

            if (!$prompt) {
                $prompt = $messageText; // Use the original message if extraction fails
            }

            $logMessage = $logPrefix . "Using image prompt: " . $prompt;
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Prepare API request parameters according to OpenRouter API docs
            $requestParams = [
                'model' => $this->config['openrouter_image_model'],
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'modalities' => ['image', 'text'], // This is the key parameter for image generation
                'max_tokens' => 300,
                'temperature' => 0.7
            ];

            // If user provided an image, include it in the request
            if ($inputImageUrl) {
                $logMessage = $logPrefix . "Including user-provided image in request: " . $inputImageUrl;
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

                // Enhance the prompt to be more specific about the image transformation
                if (strpos($prompt, 'based on the image') === false &&
                    strpos($prompt, 'from the image') === false &&
                    strpos($prompt, 'in the image') === false) {
                    // If the prompt doesn't already reference the image, add a reference
                    $enhancedPrompt = "Based on the user's image: " . $prompt;
                } else {
                    $enhancedPrompt = $prompt;
                }

                // For image input, we need to use the multimodal format
                $requestParams['messages'] = [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $enhancedPrompt
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => $inputImageUrl
                                ]
                            ]
                        ]
                    ]
                ];

                $logMessage = $logPrefix . "Enhanced image prompt: " . $enhancedPrompt;
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            }

            // Log the request parameters for debugging
            $logMessage = $logPrefix . "Request parameters: " . json_encode($requestParams);
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Call the OpenRouter API
            $response = $this->httpClient->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => $requestParams,
                'timeout' => 60, // Image generation might take longer
            ]);

            $responseContent = $response->getBody()->getContents();
            $logMessage = $logPrefix . "Raw IMAGE API response: " . $responseContent;
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($responseContent, true);

            // Parse the response according to the OpenRouter API format
            if (isset($body['choices'][0]['message'])) {
                $message = $body['choices'][0]['message'];

                // Check if the content is an array (multimodal response)
                if (isset($message['content']) && is_array($message['content'])) {
                    $content = $message['content'];
                    $imageUrl = null;
                    $textResponse = null;

                    // Extract image URL and text from the response
                    foreach ($content as $part) {
                        if (isset($part['type'])) {
                            if ($part['type'] === 'image_url' && isset($part['image_url']['url'])) {
                                $imageUrl = $part['image_url']['url'];
                                $logMessage = $logPrefix . "Found image URL in response: " . $imageUrl;
                                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
                            } elseif ($part['type'] === 'text' && isset($part['text'])) {
                                $textResponse = $part['text'];
                                $logMessage = $logPrefix . "Found text in response: " . $textResponse;
                                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
                            }
                        }
                    }

                    // Return the result with both image and text if available
                    return [
                        'url' => $imageUrl,
                        'prompt' => $prompt,
                        'revised_prompt' => $textResponse ?? $prompt,
                        'text_response' => $textResponse
                    ];
                }
                // Check if the content is a string (text-only response)
                elseif (isset($message['content']) && is_string($message['content'])) {
                    $textContent = $message['content'];
                    $logMessage = $logPrefix . "Received text-only response: " . $textContent;
                    file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

                    return [
                        'url' => null,
                        'prompt' => $prompt,
                        'revised_prompt' => null,
                        'text_response' => $textContent
                    ];
                }
            }

            $logMessage = $logPrefix . "Failed to generate image, unexpected response format: " . json_encode($body);
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;
        } catch (\Exception $e) {
            $logMessage = $logPrefix . "Error generating image: " . $e->getMessage();
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);
            return null;
        }
    }

    /**
     * Extract an image prompt from a message
     *
     * @param string $messageText The message text to extract a prompt from
     * @param string $logFile Path to the log file
     * @param string $logPrefix Prefix for log messages
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return string|null The extracted prompt or null if extraction failed
     */
    private function extractImagePrompt(string $messageText, string $logFile, string $logPrefix, ?string $inputImageUrl = null): ?string
    {
        try {
            $logMessage = $logPrefix . "Extracting image prompt from message";
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            // If the user provided an image, we need to analyze it first
            if ($inputImageUrl) {
                $logMessage = $logPrefix . "Analyzing user-provided image: " . $inputImageUrl;
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

                // Use Grok-2-Vision to analyze the image
                $response = $this->httpClient->post($this->config['openrouter_api_url'], [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model' => $this->config['openrouter_vision_model'],
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'You are an assistant that helps create image generation prompts. When given an image and a user message, create a detailed prompt that describes what the user wants to do with the image.'
                            ],
                            [
                                'role' => 'user',
                                'content' => [
                                    [
                                        'type' => 'text',
                                        'text' => "Create a detailed image generation prompt based on this image and my request: \"" . $messageText . "\""
                                    ],
                                    [
                                        'type' => 'image_url',
                                        'image_url' => [
                                            'url' => $inputImageUrl
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'max_tokens' => 300,
                        'temperature' => 0.7,
                    ],
                    'timeout' => 30,

                ]);
                // Log the request for debugging
                $logMessage = $logPrefix . "Sent vision request to Grok API (/chat/completions with grok-2-vision)";
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            } else {
                // For text-only requests, extract a good image prompt
                $response = $this->httpClient->post($this->config['openrouter_api_url'], [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model' => $this->config['openrouter_chat_model'],
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'You are an assistant that helps create image generation prompts. When given a user message, extract or create a detailed prompt suitable for image generation.'
                            ],
                            [
                                'role' => 'user',
                                'content' => "Create a detailed image generation prompt based on this request: \"" . $messageText . "\""
                            ]
                        ],
                        'max_tokens' => 300,
                        'temperature' => 0.1,
                    ],
                    'timeout' => 30,
                ]);
                // Log the request for debugging
                $logMessage = $logPrefix . "Sent text-only request to Grok API (/chat/completions)";
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
            }

            $responseContent = $response->getBody()->getContents();
            $logMessage = $logPrefix . "Raw API response: " . $responseContent;
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($responseContent, true);
            // Log the decoded body to help debug if parsing fails
            $logMessage = $logPrefix . "Decoded API response: " . json_encode($body);
            file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Standard response parsing for /chat/completions
            if (isset($body['choices'][0]['message']['content'])) {
                $extractedPrompt = trim($body['choices'][0]['message']['content']);
                $logMessage = $logPrefix . "Extracted prompt: " . $extractedPrompt;
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
                return $extractedPrompt;
            } else {
                // Log the structure if the expected path is not found
                $logMessage = $logPrefix . "Failed to extract prompt. Response structure might be different. Body: " . json_encode($body);
                file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
                return null; // Indicate failure
            }

        } catch (RequestException $e) {
            // Log Guzzle request exceptions
            $errorMessage = $logPrefix . "HTTP Request failed: " . $e->getMessage();
            if ($e->hasResponse()) {
                $errorMessage .= " | Response: " . $e->getResponse()->getBody()->getContents();
            }
            file_put_contents($logFile, $errorMessage . PHP_EOL, FILE_APPEND);
            return null;
        } catch (\Exception $e) {
            // Log any other exceptions
            $errorMessage = $logPrefix . "Error extracting image prompt: " . $e->getMessage();
            file_put_contents($logFile, $errorMessage . PHP_EOL, FILE_APPEND);
            return null;
        }
    }

    /**
     * Generate a description for an image using vision model
     *
     * @param string $imageData URL of the image or base64-encoded image data
     * @param bool $isBase64 Whether the image data is base64-encoded
     * @return string|null The generated description or null if generation failed
     */
    public function generateImageDescription(string $imageData, bool $isBase64 = false, ?string $caption = ''): ?string
    {
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Image Description] ";
        $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';

        try {
            if ($isBase64) {
                $logMessage = $logPrefix . "Generating description for base64-encoded image";
            } else {
                $logMessage = $logPrefix . "Generating description for image URL: " . $imageData;
            }
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            // Prepare the image URL structure based on whether it's base64 or a URL
            $imageUrlStructure = [];
            if ($isBase64) {
                // For base64-encoded images
                $imageUrlStructure = [
                    'url' => "data:image/jpeg;base64," . $imageData
                ];
            } else {
                // For regular URLs
                $imageUrlStructure = [
                    'url' => $imageData
                ];
            }

            // Use vision model to analyze the image
            $response = $this->httpClient->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $this->config['openrouter_vision_model'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an assistant that provides concise, accurate descriptions of images. Describe what you see in 1-5 sentences.'
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => "Describe this image in detail but concisely. Image caption: \"$caption\"."
                                ],
                                [
                                    'type' => 'image_url',
                                    'image_url' => $imageUrlStructure
                                ]
                            ]
                        ]
                    ],
                    'max_tokens' => 1000,
                    'temperature' => 0.3,
                ],
                'timeout' => 30,
            ]);

            $responseContent = $response->getBody()->getContents();
            $logMessage = $logPrefix . "Raw API response: " . $responseContent;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($responseContent, true);

            // Standard response parsing for /chat/completions
            if (isset($body['choices'][0]['message']['content'])) {
                $description = trim($body['choices'][0]['message']['content']);
                $logMessage = $logPrefix . "Generated description: " . $description;
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                return $description;
            } else {
                $logMessage = $logPrefix . "Failed to extract description. Response structure might be different.";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                return null;
            }

        } catch (RequestException $e) {
            // Log Guzzle request exceptions
            $errorMessage = $logPrefix . "HTTP Request failed: " . $e->getMessage();
            if ($e->hasResponse()) {
                $errorMessage .= " | Response: " . $e->getResponse()->getBody()->getContents();
            }
            file_put_contents($webhookLogFile, $errorMessage . PHP_EOL, FILE_APPEND);
            return null;
        } catch (\Exception $e) {
            // Log any other exceptions
            $errorMessage = $logPrefix . "Error generating image description: " . $e->getMessage();
            file_put_contents($webhookLogFile, $errorMessage . PHP_EOL, FILE_APPEND);
            return null;
        }
    }

    /**
     * Generate a chat summary using OpenRouter API
     *
     * @param array $messages Array of messages to summarize
     * @param int|null $chatId The chat ID (optional)
     * @param string|null $chatTitle The chat title (optional)
     * @param string|null $chatUsername The chat username (optional)
     * @return string|null The generated summary or null if generation failed
     */
    public function generateChatSummary(array $messages, ?int $chatId = null, ?string $chatTitle = null, ?string $chatUsername = null): ?string
    {
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] [Summary] ";
        $webhookLogFile = $this->config['log_path'] . '/webhook_' . date('Y-m-d') . '.log';
        $summaryLogFile = $this->config['log_path'] . '/summary_' . date('Y-m-d') . '.log';

        // Create a chat identifier for logging
        $chatIdentifier = $chatId ? "chat $chatId" : "unknown chat";
        if ($chatTitle) {
            $chatIdentifier .= " ($chatTitle)";
        }

        // Check if we have enough messages to summarize
        if (count($messages) < 5) {
            $logMessage = $logPrefix . "Not enough messages to summarize for $chatIdentifier";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            return null;
        }

        $conversation = implode("\n", $messages);

        // Log message count
        $messageCount = count($messages);
        $logMessage = $logPrefix . "Processing $messageCount messages for $chatIdentifier";
        file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
        file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);

        // Build prompt with chat information
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

        $prompt = "Summarize the following conversation that happened in a Telegram group chat over the last 24 hours. Summary language must be language mostly used in messages. Keep it concise and capture the main topics.\n\n";
        if (!empty($chatInfo)) {
            $prompt .= "Chat Information:\n$chatInfo\n";
        }
        $prompt .= "Conversation:\n" . $conversation;

        try {
            // Log API request
            $logMessage = $logPrefix . "Sending request to OpenRouter API for $chatIdentifier";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $startTime = microtime(true);

            $response = $this->httpClient->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $this->config['openrouter_summary_model'],
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a helpful assistant that summarizes Telegram group chats. Summary language must be language mostly used in messages, preferably Russian. Keep it concise and capture the main topics. Make list of main topics with short description and links to messages

If Chat Username is provided, create links to messages using the format: https://t.me/[username]/[message_id] where [username] is the Chat Username without @ and [message_id] is a message ID you can reference from the conversation.

If only Chat ID is provided (no username), create link using the format: https://t.me/c/[channel_id]/[message_id] where [channel_id] is a channel ID you can reference from the conversation. Remove -100 from the beginning of the Channel ID if exists.

When formatting your responses for Telegram, please use these special formatting conventions for HTML:
use only this list of tags, dont use any other html tags
!!dont use telegram markdown!!
!!dont use telegram markdownv2!!
use HTML for telegram
<b>bold</b>, <strong>bold</strong>
<i>italic</i>, <em>italic</em>
<u>underline</u>, <ins>underline</ins>
<s>strikethrough</s>, <strike>strikethrough</strike>, <del>strikethrough</del>
<span class="tg-spoiler">spoiler</span>, <tg-spoiler>spoiler</tg-spoiler>
<b>bold <i>italic bold <s>italic bold strikethrough <span class="tg-spoiler">italic bold strikethrough spoiler</span></s> <u>underline italic bold</u></i> bold</b>
<a href="http://www.example.com/">inline URL</a>
<a href="tg://user?id=123456789">inline mention of a user</a>
<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>
<code>inline fixed-width code</code>
<pre>pre-formatted fixed-width code block</pre>
<pre><code class="language-python">pre-formatted fixed-width code block written in the Python programming language</code></pre>
<blockquote>Block quotation started\nBlock quotation continued\nThe last line of the block quotation</blockquote>
<blockquote expandable>Expandable block quotation started\nExpandable block quotation continued\nExpandable block quotation continued\nHidden by default part of the block quotation started\nExpandable block quotation continued\nThe last line of the block quotation</blockquote>
'],
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
            $logMessage = $logPrefix . "Received response from OpenRouter API in {$duration}s for $chatIdentifier";
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['choices'][0]['message']['content'])) {
                $summary = trim($body['choices'][0]['message']['content']);
                $summaryLength = strlen($summary);

                // Log successful summary generation
                $logMessage = $logPrefix . "Successfully generated summary ($summaryLength chars) for $chatIdentifier";
                file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
                file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);

                return $summary.'

model:'.$this->config['openrouter_summary_model'];
            }

            // Log unexpected API response format
            $logMessage = $logPrefix . "OpenRouter API Response format unexpected for $chatIdentifier: " . json_encode($body);
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;
        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';

            // Log request exception
            $logMessage = $logPrefix . "OpenRouter API Request Exception for $chatIdentifier: " . $e->getMessage() . " | Response: " . $errorResponse;
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;
        } catch (\Exception $e) {
            // Log general exception
            $logMessage = $logPrefix . "Error generating summary for $chatIdentifier: " . $e->getMessage();
            file_put_contents($webhookLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            file_put_contents($summaryLogFile, $logMessage . PHP_EOL, FILE_APPEND);
            error_log($logMessage);

            return null;
        }
    }
}
