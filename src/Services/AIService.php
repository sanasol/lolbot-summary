<?php

namespace App\Services;

use App\Services\AI\AIService as AIServiceImplementation;
use App\Services\AI\AIServiceInterface;

/**
 * Main AI service class that delegates to the implementation
 */
class AIService implements AIServiceInterface
{
    private AIServiceImplementation $implementation;

    /**
     * Constructor
     *
     * @param array $config The configuration array
     * @param SettingsService|null $settingsService The settings service
     */
    public function __construct(array $config, ?SettingsService $settingsService = null)
    {
        $this->implementation = new AIServiceImplementation($config, $settingsService);
    }

    /**
     * Set the settings service
     *
     * @param SettingsService $settingsService
     * @return void
     */
    public function setSettingsService(SettingsService $settingsService): void
    {
        $this->implementation->setSettingsService($settingsService);
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
    public function generateMCPResponse(string $messageText, string $username, string $chatContext = ''): array
    {
        return $this->implementation->generateMCPResponse($messageText, $username, $chatContext);
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
    public function generateMentionResponse(string $messageText, string $username, string $chatContext = '', ?string $inputImageUrl = null, bool $isBase64 = false, int $chatId = 0): ?array
    {
        return $this->implementation->generateMentionResponse($messageText, $username, $chatContext, $inputImageUrl, $isBase64, $chatId);
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
        return $this->implementation->generateImageDescription($imageData, $isBase64, $caption);
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
        return $this->implementation->generateChatSummary($messages, $chatId, $chatTitle, $chatUsername);
    }
}