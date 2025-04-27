<?php

namespace App\Services\AI;

use App\Services\LoggerService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

/**
 * Trait for HTTP client functionality
 */
trait HttpClientTrait
{
    /**
     * Make a request to the OpenRouter API
     *
     * @param array $config The configuration array
     * @param array $params The request parameters
     * @param string $category The log category
     * @param int $timeout The request timeout in seconds
     * @return array|null The response body or null if the request failed
     */
    protected function makeOpenRouterRequest(array $config, array $params, string $category, int $timeout = 30): ?array
    {
        try {
            $httpClient = new HttpClient();
            
            $response = $httpClient->post($config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => $params,
                'timeout' => $timeout,
            ]);
            
            $responseContent = $response->getBody()->getContents();
            $this->logger->log("Raw API response: " . $responseContent, $category, "webhook");
            
            $body = json_decode($responseContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->logError("Failed to decode API response: " . json_last_error_msg(), $category);
                return null;
            }
            
            return $body;
        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $this->logger->logError("API Request Exception: " . $e->getMessage() . " | Response: " . $errorResponse, $category, $e);
            return null;
        } catch (\Exception $e) {
            $this->logger->logError("Error making API request: " . $e->getMessage(), $category, $e);
            return null;
        }
    }

    /**
     * Extract content from an OpenRouter API response
     *
     * @param array|null $body The response body
     * @param string $category The log category
     * @return string|null The extracted content or null if extraction failed
     */
    protected function extractContentFromResponse(?array $body, string $category): ?string
    {
        if (!$body) {
            return null;
        }
        
        if (isset($body['choices'][0]['message']['content'])) {
            $content = trim($body['choices'][0]['message']['content']);
            $this->logger->log("Extracted content: " . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : ''), $category, "webhook");
            return $content;
        }
        
        $this->logger->log("Failed to extract content. Response structure might be different: " . json_encode($body), $category, "webhook");
        return null;
    }

    /**
     * Handle a client exception from an API request
     *
     * @param \GuzzleHttp\Exception\ClientException $e The client exception
     * @param string $category The log category
     * @return array The error response
     */
    protected function handleClientException(\GuzzleHttp\Exception\ClientException $e, string $category): array
    {
        $responseBody = $e->getResponse()->getBody()->getContents();
        $this->logger->log("ClientException response: " . $responseBody, $category, "webhook");

        // Get the request object
        $request = $e->getRequest();

        // Log request details
        $this->logger->log("Request URL: " . $request->getUri(), $category, "webhook");
        $this->logger->log("Request Method: " . $request->getMethod(), $category, "webhook");
        $this->logger->log("Request Headers: " . json_encode($request->getHeaders()), $category, "webhook");

        // Log request body
        $requestBody = $request->getBody()->getContents();
        $this->logger->log("Request Body: " . $requestBody, $category, "webhook");

        // Try to decode and log JSON body in a more readable format if it's JSON
        try {
            $jsonBody = json_decode($requestBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->logger->log("Request JSON Body: " . json_encode($jsonBody, JSON_PRETTY_PRINT), $category, "webhook");
            }
        } catch (\Exception $jsonEx) {
            $this->logger->log("Failed to decode request body as JSON: " . $jsonEx->getMessage(), $category, "webhook");
        }

        // Try to extract a user-friendly error message from the response
        $errorMessage = "API request failed";
        $errorType = "client_error";

        try {
            $responseJson = json_decode($responseBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Extract error message from various API formats
                if (isset($responseJson['error']['message'])) {
                    $errorMessage = $responseJson['error']['message'];
                    $errorType = $responseJson['error']['type'] ?? 'api_error';
                } elseif (isset($responseJson['message'])) {
                    $errorMessage = $responseJson['message'];
                }
            }
        } catch (\Exception $jsonEx) {
            // If we can't parse the JSON, use the status code and reason
            $errorMessage = "API error: " . $e->getResponse()->getStatusCode() . " " . $e->getResponse()->getReasonPhrase();
        }

        return [
            'type' => 'error',
            'content' => "The AI service is currently experiencing issues: " . $errorMessage,
            'error_type' => $errorType
        ];
    }
}