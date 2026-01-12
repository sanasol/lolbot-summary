<?php

namespace App\Services\AI;

use App\Services\LoggerService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

/**
 * Decides if the bot should react instead of replying and which emoji to use.
 */
class MentionReactionDecisionGenerator
{
    use HttpClientTrait;

    private array $config;
    private LoggerService $logger;
    private HttpClient $httpClient;

    public function __construct(array $config, LoggerService $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->httpClient = new HttpClient();
    }

    /**
    * Returns decision array or null on failure:
    * [should_react=>bool, confidence=>int, emoji=>string, is_big=>bool, reason=>string]
    */
    public function decide(string $messageText, string $username, string $chatContext = '', int $chatId = 0): ?array
    {
        try {
            $model = $this->config['openrouter_reaction_model'] ?? ($this->config['openrouter_chat_model'] ?? null);
            if (!$model) {
                $this->logger->log('No reaction model configured; skip', 'ReactionDecision');
                return null;
            }

            $prompt = $this->buildPrompt($messageText, $username, $chatContext);

            $resp = $this->httpClient->post($this->config['openrouter_api_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['openrouter_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'reaction_confidence',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'should_react' => ['type' => 'boolean'],
                                    'confidence' => ['type' => 'integer'],
                                    'emoji' => ['type' => 'string'],
                                    'is_big' => ['type' => 'boolean'],
                                    'reason' => ['type' => 'string'],
                                ],
                                'required' => ['should_react', 'confidence', 'emoji', 'is_big', 'reason'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'max_tokens' => 200,
                    'temperature' => 0.1,
                ],
                'timeout' => 15,
            ]);

            $raw = $resp->getBody()->getContents();
            $this->logger->log("ReactionDecision raw: " . substr($raw, 0, 1000), 'ReactionDecision');
            $body = json_decode($raw, true);
            $content = $body['choices'][0]['message']['content'] ?? null;
            if (!is_string($content) || $content === '') {
                return null;
            }
            $data = json_decode($content, true);
            if (!is_array($data)) {
                return null;
            }
            // Normalize types
            return [
                'should_react' => (bool)($data['should_react'] ?? false),
                'confidence' => (int)($data['confidence'] ?? 0),
                'emoji' => (string)($data['emoji'] ?? ''),
                'is_big' => (bool)($data['is_big'] ?? false),
                'reason' => (string)($data['reason'] ?? ''),
            ];
        } catch (RequestException $e) {
            $errorResponse = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $this->logger->logError('ReactionDecision RequestException: ' . $e->getMessage() . ' | ' . $errorResponse, 'ReactionDecision', $e);
            return null;
        } catch (\Throwable $e) {
            $this->logger->logError('ReactionDecision Exception: ' . $e->getMessage(), 'ReactionDecision', $e);
            return null;
        }
    }

    private function buildPrompt(string $messageText, string $username, string $chatContext): string
    {
        $ctx = $chatContext ? ("Context:\n" . mb_substr($chatContext, 0, 800)) : '';
        $msg = mb_substr($messageText, 0, 500);
        return <<<PROMPT
You are deciding whether a Telegram bot should add a reaction (emoji) to a user's message instead of replying with text.
Rules:
- Only react when the message explicitly mentions the bot and a short non-intrusive acknowledgement is better than text.
- Choose a single Unicode emoji that fits the sentiment/intent.
- Return JSON only using the provided schema.
- Keep is_big true only for strong positive cases.

Reaction emoji. Currently, it can be one of "❤", "👍", "👎", "🔥", "🥰", "👏", "😁", "🤔", "🤯", "😱", "🤬", "😢", "🎉", "🤩", "🤮", "💩", "🙏", "👌", "🕊", "🤡", "🥱", "🥴", "😍", "🐳", "❤‍🔥", "🌚", "🌭", "💯", "🤣", "⚡", "🍌", "🏆", "💔", "🤨", "😐", "🍓", "🍾", "💋", "🖕", "😈", "😴", "😭", "🤓", "👻", "👨‍💻", "👀", "🎃", "🙈", "😇", "😨", "🤝", "✍", "🤗", "🫡", "🎅", "🎄", "☃", "💅", "🤪", "🗿", "🆒", "💘", "🙉", "🦄", "😘", "💊", "🙊", "😎", "👾", "🤷‍♂", "🤷", "🤷‍♀", "😡"

Inputs:
User: {$username}
Message: {$msg}
{$ctx}
PROMPT;
    }
}
