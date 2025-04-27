<?php

namespace App\Services\AI;

use App\Services\LoggerService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

/**
 * Class for processing and generating images
 */
class ImageProcessor
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
     * Generate a description for an image using vision model
     *
     * @param string $imageData URL of the image or base64-encoded image data
     * @param bool $isBase64 Whether the image data is base64-encoded
     * @param string|null $caption Optional caption for the image
     * @return string|null The generated description or null if generation failed
     */
    public function generateImageDescription(string $imageData, bool $isBase64 = false, ?string $caption = ''): ?string
    {
        try {
            if ($isBase64) {
                $this->logger->log("Generating description for base64-encoded image", "Image Description", "webhook");
            } else {
                $this->logger->log("Generating description for image URL: " . $imageData, "Image Description", "webhook");
            }

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

            // Build the prompt
            $prompt = $this->promptBuilder->buildImageDescriptionPrompt($caption);

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
                                    'text' => $prompt
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
            $this->logger->log("Raw API response: " . $responseContent, "Image Description", "webhook");

            $body = json_decode($responseContent, true);

            // Standard response parsing for /chat/completions
            if (isset($body['choices'][0]['message']['content'])) {
                $description = trim($body['choices'][0]['message']['content']);
                $this->logger->log("Generated description: " . $description, "Image Description", "webhook");
                return $description;
            } else {
                $this->logger->log("Failed to extract description. Response structure might be different.", "Image Description", "webhook");
                return null;
            }

        } catch (RequestException $e) {
            // Log Guzzle request exceptions
            $errorMessage = "HTTP Request failed: " . $e->getMessage();
            if ($e->hasResponse()) {
                $errorMessage .= " | Response: " . $e->getResponse()->getBody()->getContents();
            }
            $this->logger->logError($errorMessage, "Image Description", $e);
            return null;
        } catch (\Exception $e) {
            // Log any other exceptions
            $this->logger->logError("Error generating image description: " . $e->getMessage(), "Image Description", $e);
            return null;
        }
    }

    /**
     * Determine if a message is requesting image generation
     *
     * @param string $messageText The message text to analyze
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return bool Whether the message is requesting image generation
     */
    public function isImageGenerationRequest(string $messageText, ?string $inputImageUrl = null): bool
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
                    $this->logger->log("Detected image generation keyword: " . $keyword, "Image Request", "webhook");
                    return true;
                }
            }

            // For more complex detection, we can use the API to analyze the intent
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

                $this->logger->log("AI detection of image request: " . ($isImageRequest ? 'YES' : 'NO'), "Image Request", "webhook");

                return $isImageRequest;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->logError("Error detecting image request: " . $e->getMessage(), "Image Request", $e);
            return false;
        }
    }

    /**
     * Extract an image prompt from a message
     *
     * @param string $messageText The message text to extract a prompt from
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return string|null The extracted prompt or null if extraction failed
     */
    public function extractImagePrompt(string $messageText, ?string $inputImageUrl = null): ?string
    {
        try {
            $this->logger->log("Extracting image prompt from message", "Image Prompt", "webhook");

            // Build the prompt
            $prompt = $this->promptBuilder->buildImageGenerationPrompt($messageText, $inputImageUrl);

            // If the user provided an image, we need to analyze it first
            if ($inputImageUrl) {
                $this->logger->log("Analyzing user-provided image: " . $inputImageUrl, "Image Prompt", "webhook");

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
                                'content' => 'You are an assistant that helps create image generation prompts. When given an image and a user message, create a detailed prompt that describes what the user wants to do with the image.'
                            ],
                            [
                                'role' => 'user',
                                'content' => [
                                    [
                                        'type' => 'text',
                                        'text' => $prompt
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
                $this->logger->log("Sent vision request to API (/chat/completions with vision model)", "Image Prompt", "webhook");
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
                                'content' => $prompt
                            ]
                        ],
                        'max_tokens' => 300,
                        'temperature' => 0.1,
                    ],
                    'timeout' => 30,
                ]);
                
                // Log the request for debugging
                $this->logger->log("Sent text-only request to API (/chat/completions)", "Image Prompt", "webhook");
            }

            $responseContent = $response->getBody()->getContents();
            $this->logger->log("Raw API response: " . $responseContent, "Image Prompt", "webhook");

            $body = json_decode($responseContent, true);
            
            // Log the decoded body to help debug if parsing fails
            $this->logger->log("Decoded API response: " . json_encode($body), "Image Prompt", "webhook");

            // Standard response parsing for /chat/completions
            if (isset($body['choices'][0]['message']['content'])) {
                $extractedPrompt = trim($body['choices'][0]['message']['content']);
                $this->logger->log("Extracted prompt: " . $extractedPrompt, "Image Prompt", "webhook");
                return $extractedPrompt;
            } else {
                // Log the structure if the expected path is not found
                $this->logger->log("Failed to extract prompt. Response structure might be different. Body: " . json_encode($body), "Image Prompt", "webhook");
                return null; // Indicate failure
            }

        } catch (RequestException $e) {
            // Log Guzzle request exceptions
            $errorMessage = "HTTP Request failed: " . $e->getMessage();
            if ($e->hasResponse()) {
                $errorMessage .= " | Response: " . $e->getResponse()->getBody()->getContents();
            }
            $this->logger->logError($errorMessage, "Image Prompt", $e);
            return null;
        } catch (\Exception $e) {
            // Log any other exceptions
            $this->logger->logError("Error extracting image prompt: " . $e->getMessage(), "Image Prompt", $e);
            return null;
        }
    }

    /**
     * Generate an image using the OpenRouter API
     *
     * @param string $messageText The message text to use as prompt
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return array|null The generated image data or null if generation failed
     */
    public function generateImage(string $messageText, ?string $inputImageUrl = null): ?array
    {
        try {
            $this->logger->log("Generating image with OpenRouter API", "Image Generation", "webhook");

            // Extract the image prompt from the message
            $prompt = $this->extractImagePrompt($messageText, $inputImageUrl);

            if (!$prompt) {
                $prompt = $messageText; // Use the original message if extraction fails
            }

            $this->logger->log("Using image prompt: " . $prompt, "Image Generation", "webhook");

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
                $this->log("Including user-provided image in request: " . $inputImageUrl, $logPrefix, $webhookLogFile);

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

                $this->log("Enhanced image prompt: " . $enhancedPrompt, $logPrefix, $webhookLogFile);
            }

            // Log the request parameters for debugging
            $this->log("Request parameters: " . json_encode($requestParams), $logPrefix, $webhookLogFile);

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
            $this->log("Raw IMAGE API response: " . $responseContent, $logPrefix, $webhookLogFile);

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
                                $this->log("Found image URL in response: " . $imageUrl, $logPrefix, $webhookLogFile);
                            } elseif ($part['type'] === 'text' && isset($part['text'])) {
                                $textResponse = $part['text'];
                                $this->log("Found text in response: " . $textResponse, $logPrefix, $webhookLogFile);
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
                    $this->log("Received text-only response: " . $textContent, $logPrefix, $webhookLogFile);

                    return [
                        'url' => null,
                        'prompt' => $prompt,
                        'revised_prompt' => null,
                        'text_response' => $textContent
                    ];
                }
            }

            $this->log("Failed to generate image, unexpected response format: " . json_encode($body), $logPrefix, $webhookLogFile, true);
            return null;
        } catch (\Exception $e) {
            $this->log("Error generating image: " . $e->getMessage(), $logPrefix, $webhookLogFile, true);
            return null;
        }
    }
}