<?php

namespace App\Services;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Longman\TelegramBot\Request;

/**
 * Handler for detecting and managing spam in Telegram messages
 */
class AntiSpamHandler
{
    private HttpClient $httpClient;
    private LoggerService $logger;
    private array $config;

    /**
     * Constructor
     *
     * @param array $config The configuration array
     * @param LoggerService $logger The logger service
     */
    public function __construct(array $config, LoggerService $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->httpClient = new HttpClient();
    }

    /**
     * Check if a message contains spam
     *
     * @param string $messageText The message text to check
     * @param int $userId The user ID who sent the message
     * @param string $username The username of the sender
     * @param int $chatId The chat ID where the message was sent
     * @param int $messageId The message ID
     * @return bool True if the message was handled as spam, false otherwise
     */
    public function checkAndHandleSpam(string $messageText, int $userId, string $username, int $chatId, int $messageId): bool
    {
        // DISABLED: Antispam handler has been disabled as requested
        // To re-enable, remove the following return statement
        $this->logger->log("Antispam handler is disabled - skipping spam check", "Spam Check", "webhook");
        return false;

        // Original implementation below:
        try {
            // Log the spam check
            $this->logger->log("Checking message for spam: " . substr($messageText, 0, 50) . (strlen($messageText) > 50 ? '...' : ''), "Spam Check", "webhook");

            // Build the prompt for spam detection
            $spamCheckPrompt = $this->buildSpamCheckPrompt($messageText);

            // Make API request to OpenRouter
            $spamCheckResponse = $this->httpClient->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $this->config['openrouter_chat_model'],
                    'messages' => [
                        ['role' => 'user', 'content' => $spamCheckPrompt]
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'spam_detection',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'spam_score' => [
                                        'type' => 'integer',
                                        'description' => 'Spam score from 0-100 indicating how likely the message is spam'
                                    ],
                                    'reason' => [
                                        'type' => 'string',
                                        'description' => 'Brief explanation for the spam score'
                                    ],
                                    'is_spam' => [
                                        'type' => 'boolean',
                                        'description' => 'Whether the message is considered spam'
                                    ]
                                ],
                                'required' => ['spam_score', 'reason', 'is_spam'],
                                'additionalProperties' => false
                            ]
                        ]
                    ],
                    'max_tokens' => 1000,
                    'temperature' => 0.1,
                ],
                'timeout' => 30,
            ]);

            $content = $spamCheckResponse->getBody()->getContents();
            $this->logger->log("Raw spam check API response: " . $content, "Spam Check", "webhook");

            $body = json_decode($content, true);
            $this->logger->log("Decoded spam check API response: " . json_encode($body), "Spam Check", "webhook");

            // Parse the structured JSON response
            $spamScore = 0;
            $reason = '';
            $isSpam = false;

            if (isset($body['choices'][0]['message']['content'])) {
                try {
                    $responseData = json_decode($body['choices'][0]['message']['content'], true);
                    if (isset($responseData['spam_score'])) {
                        $spamScore = (int)$responseData['spam_score'];
                        $reason = $responseData['reason'] ?? '';
                        $isSpam = $responseData['is_spam'] ?? false;

                        $this->logger->log("Parsed spam score: " . $spamScore . ", Is Spam: " . ($isSpam ? 'Yes' : 'No') . ", Reason: " . $reason, "Spam Check", "webhook");
                    }
                } catch (\Exception $e) {
                    $this->logger->logError("Error parsing structured response: " . $e->getMessage(), "Spam Check", $e);
                    return false;
                }
            }

            // If the message is spam, handle it
            if ($isSpam) {
                $this->logger->log("Spam detected in message from user {$username} (ID: {$userId}) in chat {$chatId}. Score: {$spamScore}, Reason: {$reason}", "Spam Detected", "webhook");

                // Handle the spam message
                return $this->handleSpamMessage($chatId, $messageId, $userId, $username, $spamScore, $reason);
            }

            $this->logger->log("Message is not spam. Score: {$spamScore}", "Spam Check", "webhook");
            return false;

        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $this->logger->logError("API Request Exception: " . $e->getMessage() . " | Response: " . $errorResponse, "Spam Check", $e);
            return false;
        } catch (\Exception $e) {
            $this->logger->logError("Error checking for spam: " . $e->getMessage(), "Spam Check", $e);
            return false;
        }
    }

    /**
     * Build the prompt for spam detection
     *
     * @param string $messageText The message text to check
     * @return string The prompt for spam detection
     */
    private function buildSpamCheckPrompt(string $messageText): string
    {
        return <<<EOT
You are a spam detection system for a Telegram bot. Your task is to analyze the following message and determine if it contains spam.

Message:
{$messageText}

Analyze the message for the following spam indicators:
1. Unsolicited promotional content
2. Suspicious links or URLs
3. Excessive use of keywords related to money, crypto, or financial gains
4. Unnatural language patterns typical of spam
5. Mentions of financial services, trading platforms, or investment opportunities
6. Promises of unrealistic returns or financial gains
7. Urgency or pressure tactics

Provide a spam score from 0-100, where:
- 0-30: Not spam
- 31-70: Potentially spam
- 71-100: Definitely spam

Also indicate whether the message should be treated as spam (true/false) and provide a brief explanation for your decision.
EOT;
    }

    /**
     * Handle a spam message
     *
     * @param int $chatId The chat ID where the message was sent
     * @param int $messageId The message ID
     * @param int $userId The user ID who sent the spam
     * @param string $username The username of the spammer
     * @param int $spamScore The spam score
     * @param string $reason The reason for the spam classification
     * @return bool True if the spam was handled successfully, false otherwise
     */
    private function handleSpamMessage(int $chatId, int $messageId, int $userId, string $username, int $spamScore, string $reason): bool
    {
        try {
            // Check if we're in test mode
            $testMode = $this->config['test_mode'] ?? false;

            if (!$testMode) {
                // 1. Delete the spam message
                $deleteResult = Request::deleteMessage([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);

                if (!$deleteResult->isOk()) {
                    $this->logger->logError("Failed to delete spam message: " . $deleteResult->getDescription(), "Spam Handling");
                } else {
                    $this->logger->log("Successfully deleted spam message from user {$username} in chat {$chatId}", "Spam Handling", "webhook");
                }

                // 2. Ban the user from the chat
                $banResult = Request::banChatMember([
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                ]);

                if (!$banResult->isOk()) {
                    $this->logger->logError("Failed to ban spammer: " . $banResult->getDescription(), "Spam Handling");
                } else {
                    $this->logger->log("Successfully banned spammer {$username} (ID: {$userId}) from chat {$chatId}", "Spam Handling", "webhook");
                }
            } else {
                // In test mode, just log what would have happened
                $this->logger->log("TEST MODE: Would have deleted spam message from user {$username} in chat {$chatId}", "Spam Handling", "webhook");
                $this->logger->log("TEST MODE: Would have banned spammer {$username} (ID: {$userId}) from chat {$chatId}", "Spam Handling", "webhook");
            }

            // 3. Notify admins about the spam (or log it in test mode)
            $this->notifyAdminsAboutSpam($chatId, $userId, $username, $spamScore, $reason);

            // 4. Notify the group chat about the spam (or log it in test mode)
            $this->notifyGroupChatAboutSpam($chatId, $userId, $username, $spamScore, $reason);

            return true;
        } catch (\Exception $e) {
            $this->logger->logError("Error handling spam message: " . $e->getMessage(), "Spam Handling", $e);
            return false;
        }
    }

    /**
     * Notify admins about spam
     *
     * @param int $chatId The chat ID where the spam was detected
     * @param int $userId The user ID who sent the spam
     * @param string $username The username of the spammer
     * @param int $spamScore The spam score
     * @param string $reason The reason for the spam classification
     */
    private function notifyAdminsAboutSpam(int $chatId, int $userId, string $username, int $spamScore, string $reason): void
    {
        try {
            // Get admin chat ID from config
            $adminChatId = $this->config['admin_chat_id'] ?? null;

            if (!$adminChatId) {
                $this->logger->logError("Admin chat ID not configured, cannot send spam notification", "Spam Handling");
                return;
            }

            // Check if we're in test mode
            $testMode = $this->config['test_mode'] ?? false;

            // Prepare notification message
            $notificationMessage = <<<EOT
🚨 *SPAM DETECTED* 🚨

*Chat ID:* {$chatId}
*User:* {$username} (ID: {$userId})
*Spam Score:* {$spamScore}/100
*Reason:* {$reason}

✅ Message has been deleted
✅ User has been banned
EOT;

            if (!$testMode) {
                // Send notification to admin
                $sendResult = Request::sendMessage([
                    'chat_id' => $adminChatId,
                    'text' => $notificationMessage,
                    'parse_mode' => 'Markdown',
                ]);

                if (!$sendResult->isOk()) {
                    $this->logger->logError("Failed to send admin notification: " . $sendResult->getDescription(), "Spam Handling");
                } else {
                    $this->logger->log("Successfully sent spam notification to admin", "Spam Handling", "webhook");
                }
            } else {
                // In test mode, just log what would have happened
                $this->logger->log("TEST MODE: Would have sent the following notification to admin (chat ID: {$adminChatId}):\n{$notificationMessage}", "Spam Handling", "webhook");
            }
        } catch (\Exception $e) {
            $this->logger->logError("Error notifying admin about spam: " . $e->getMessage(), "Spam Handling", $e);
        }
    }

    /**
     * Notify the group chat about spam
     *
     * @param int $chatId The chat ID where the spam was detected
     * @param int $userId The user ID who sent the spam
     * @param string $username The username of the spammer
     * @param int $spamScore The spam score
     * @param string $reason The reason for the spam classification
     */
    private function notifyGroupChatAboutSpam(int $chatId, int $userId, string $username, int $spamScore, string $reason): void
    {
        try {
            // Check if we're in test mode
            $testMode = $this->config['test_mode'] ?? false;

            // Prepare notification message for the group chat
            $groupNotificationMessage = <<<EOT
🚨 *Spam message detected and removed* 🚨

A message from user *{$username}* was identified as spam and has been removed.
The user has been banned from the group.
EOT;

            if (!$testMode) {
                // Send notification to the group chat with auto-delete after 30 minutes (1800 seconds)
                $sendResult = Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $groupNotificationMessage,
                    'parse_mode' => 'Markdown',
                    'message_auto_delete_time' => 600, // 30 minutes in seconds
                ]);

                if (!$sendResult->isOk()) {
                    $this->logger->logError("Failed to send group chat notification: " . $sendResult->getDescription(), "Spam Handling");
                } else {
                    $this->logger->log("Successfully sent spam notification to group chat {$chatId} (will auto-delete after 30 minutes)", "Spam Handling", "webhook");
                }
            } else {
                // In test mode, just log what would have happened
                $this->logger->log("TEST MODE: Would have sent the following notification to group chat (chat ID: {$chatId}):\n{$groupNotificationMessage}", "Spam Handling", "webhook");
                $this->logger->log("TEST MODE: The message would auto-delete after 30 minutes", "Spam Handling", "webhook");
            }
        } catch (\Exception $e) {
            $this->logger->logError("Error notifying group chat about spam: " . $e->getMessage(), "Spam Handling", $e);
        }
    }
}
