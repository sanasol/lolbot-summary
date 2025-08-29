<?php

namespace App\Services\AI;

use App\Services\LoggerService;
use App\Services\SettingsService;

/**
 * Main AI service that coordinates all AI-related functionality
 */
class AIService implements AIServiceInterface
{
    private array $config;
    private ?SettingsService $settingsService;
    private LoggerService $logger;

    private MCPResponseGenerator $mcpGenerator;
    private MentionResponseGenerator $mentionGenerator;
    private ImageProcessor $imageProcessor;
    private SummaryGenerator $summaryGenerator;
    private PromptBuilder $promptBuilder;
    private ResponseFormatter $formatter;

    /**
     * Constructor
     *
     * @param array $config The configuration array
     * @param LoggerService $logger The logger service
     * @param SettingsService|null $settingsService The settings service
     */
    public function __construct(array $config, LoggerService $logger, ?SettingsService $settingsService = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->settingsService = $settingsService;

        // Add settings service to config for use in generators
        if ($settingsService !== null) {
            $this->config['settingsService'] = $settingsService;
        }

        // Initialize dependencies
        $this->promptBuilder = new PromptBuilder();
        $this->formatter = new ResponseFormatter();

        // Initialize generators
        $this->mcpGenerator = new MCPResponseGenerator($this->config, $this->formatter, $this->logger, $this->settingsService);
        $this->mentionGenerator = new MentionResponseGenerator($this->config, $this->promptBuilder, $this->formatter, $this->logger);
        $this->imageProcessor = new ImageProcessor($this->config, $this->promptBuilder, $this->formatter, $this->logger);
        $this->summaryGenerator = new SummaryGenerator($this->config, $this->promptBuilder, $this->formatter, $this->logger);
    }

    /**
     * Set the settings service
     *
     * @param SettingsService $settingsService
     * @return void
     */
    public function setSettingsService(SettingsService $settingsService): void
    {
        $this->settingsService = $settingsService;
        $this->config['settingsService'] = $settingsService;
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
    public function generateMCPResponse(string $messageText, string $username, string $chatContext = '', ?int $userId = null): array
    {
        return $this->mcpGenerator->generate($messageText, $username, $chatContext, $userId);
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
    public function generateMentionResponse(string $messageText, string $username, string $chatContext = '', ?string $inputImageUrl = null, bool $isBase64 = false, int $chatId = 0, bool $isReplyToBot = false): ?array
    {
        // Check if this is an image generation request
//        if ($this->imageProcessor->isImageGenerationRequest($messageText, $inputImageUrl)) {
//            // Generate image
//            $imageResult = $this->imageProcessor->generateImage($messageText, $inputImageUrl);
//
//            if ($imageResult) {
//                // Check if we got an image URL
//                if ($imageResult['url']) {
//                    return [
//                        'type' => 'image',
//                        'image_url' => $imageResult['url'],
//                        'content' => $imageResult['text_response'] ?? null,
//                        'prompt' => $imageResult['prompt'],
//                        'revised_prompt' => $imageResult['revised_prompt']
//                    ];
//                }
//                // If we only got a text response (no image), return it as a text response
//                elseif (isset($imageResult['text_response'])) {
//                    return [
//                        'type' => 'text',
//                        'content' => $imageResult['text_response'],
//                        'image_url' => null
//                    ];
//                }
//            }
//        }

        // If not an image request or image generation failed, generate a text response
        return $this->mentionGenerator->generate($messageText, $username, $chatContext, $inputImageUrl, $isBase64, $chatId, $isReplyToBot);
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
        return $this->imageProcessor->generateImageDescription($imageData, $isBase64, $caption);
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
    public function generateChatSummary(array $messages, ?int $chatId = null, ?string $chatTitle = null, ?string $chatUsername = null): ?string
    {
        return $this->summaryGenerator->generate($messages, $chatId, $chatTitle, $chatUsername);
    }
}
