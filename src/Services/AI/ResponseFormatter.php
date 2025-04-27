<?php

namespace App\Services\AI;

/**
 * Class for formatting AI responses
 */
class ResponseFormatter
{
    /**
     * Format a text response
     *
     * @param string $content The response content
     * @param string|null $modelInfo Optional model information to append
     * @return array The formatted response
     */
    public function formatTextResponse(string $content, ?string $modelInfo = null): array
    {
        $formattedContent = $content;
        
        if ($modelInfo) {
            $formattedContent .= "\n\nmodel:" . $modelInfo;
        }
        
        return [
            'type' => 'text',
            'content' => $formattedContent,
            'image_url' => null
        ];
    }

    /**
     * Format an image response
     *
     * @param string $imageUrl The URL of the generated image
     * @param string|null $textResponse Optional text response to include
     * @param string|null $prompt The original prompt used for generation
     * @param string|null $revisedPrompt The revised prompt used for generation
     * @return array The formatted response
     */
    public function formatImageResponse(string $imageUrl, ?string $textResponse = null, ?string $prompt = null, ?string $revisedPrompt = null): array
    {
        return [
            'type' => 'image',
            'image_url' => $imageUrl,
            'content' => $textResponse,
            'prompt' => $prompt,
            'revised_prompt' => $revisedPrompt
        ];
    }

    /**
     * Format an error response
     *
     * @param string $errorMessage The error message
     * @param string $errorType The type of error
     * @return array The formatted error response
     */
    public function formatErrorResponse(string $errorMessage, string $errorType = 'general_error'): array
    {
        return [
            'type' => 'error',
            'content' => $errorMessage,
            'error_type' => $errorType
        ];
    }

    /**
     * Format a server overload error response
     *
     * @return array The formatted error response
     */
    public function formatOverloadErrorResponse(): array
    {
        return [
            'type' => 'error',
            'content' => "The AI service is currently overloaded. Please try again in a few minutes.",
            'error_type' => 'overloaded_error'
        ];
    }

    /**
     * Format a general error response
     *
     * @return array The formatted error response
     */
    public function formatGeneralErrorResponse(): array
    {
        return [
            'type' => 'error',
            'content' => "An error occurred while processing your request. Please try again later.",
            'error_type' => 'general_error'
        ];
    }

    /**
     * Format a neuron error response
     *
     * @param string $errorMessage The error message
     * @return array The formatted error response
     */
    public function formatNeuronErrorResponse(string $errorMessage): array
    {
        return [
            'type' => 'error',
            'content' => "The AI service encountered an error: " . $errorMessage,
            'error_type' => 'neuron_error'
        ];
    }
}