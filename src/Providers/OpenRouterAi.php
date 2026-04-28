<?php

namespace App\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HasGuzzleClient;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAI\HandleChat;
use NeuronAI\Providers\OpenAI\HandleStream;
use NeuronAI\Providers\OpenAI\HandleStructured;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ToolInterface;

class OpenRouterAi implements AIProviderInterface
{
    use HasGuzzleClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    /**
     * The http client.
     */
    protected Client $client;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri = 'https://openrouter.ai/api/v1';

    protected ?MessageMapperInterface $messageMapper = null;
    protected ?ToolPayloadMapperInterface $toolPayloadMapper = null;

    /**
     * System instructions.
     * https://platform.openai.com/docs/api-reference/chat/create
     */
    protected ?string $system = null;
    protected int $httpTimeout = 120;

    /**
     * Cumulative token usage across all API calls (including tool call rounds)
     */
    protected int $totalInputTokens = 0;
    protected int $totalOutputTokens = 0;
    protected int $apiCallCount = 0;
    protected array $serverToolUsage = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
    ) {
        if (isset($this->parameters['http_timeout']) && is_numeric($this->parameters['http_timeout'])) {
            $this->httpTimeout = max(1, (int)$this->parameters['http_timeout']);
            unset($this->parameters['http_timeout']);
        }

        $this->client = new Client([
            'base_uri' => \trim($this->baseUri, '/').'/',
            'timeout' => $this->httpTimeout,
            'connect_timeout' => min(15, $this->httpTimeout),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]
        ]);
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        // Use custom mapper that strips reasoning_details to prevent API errors when replaying history
        return $this->messageMapper ?? $this->messageMapper = new OpenRouterMessageMapper();
    }

    public function toolPayloadMapper(): ToolPayloadMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new OpenRouterToolPayloadMapper();
    }

    /**
     * Override chatAsync to support OpenRouter reasoning_details preservation and extra_body reasoning.
     */
    public function chatAsync(array $messages): PromiseInterface
    {
        // Include the system prompt
        if (isset($this->system)) {
            \array_unshift($messages, new \NeuronAI\Chat\Messages\Message(\NeuronAI\Chat\Enums\MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->generateToolsPayload();
            $json = $this->applyReasoningConfig($json);
        }

        return $this->client->postAsync('chat/completions', ['json' => $json])
            ->then(function (\Psr\Http\Message\ResponseInterface $response) {
                $result = \json_decode($response->getBody()->getContents(), true);

                $choice = $result['choices'][0] ?? [];
                $msg = $choice['message'] ?? [];
                $finish = $choice['finish_reason'] ?? null;

                // Detect tool calls robustly across providers/models
                $hasToolCalls = isset($msg['tool_calls']) && \is_array($msg['tool_calls']) && !empty($msg['tool_calls']);
                if ($hasToolCalls || $finish === 'tool_calls' || $finish === 'tool_use') {
                    $responseMessage = $this->createToolCallMessage($msg);
                } else {
                    $responseMessage = new \NeuronAI\Chat\Messages\AssistantMessage($msg['content'] ?? '');
                }

                if (isset($msg['images']) && \is_array($msg['images'])) {
                    $responseMessage->addMetadata('images', $msg['images']);
                }

                // NOTE: We intentionally do NOT store reasoning_details in metadata.
                // These contain model-specific encrypted "thought signatures" that cannot
                // be replayed in subsequent API calls and cause errors like:
                // - "Thought signature is not valid"
                // - "function response turn comes immediately after a function call turn"

                if (\array_key_exists('usage', $result)) {
                    $usage = $result['usage'];
                    // OpenRouter models may return input_tokens/output_tokens instead
                    $promptTokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
                    $completionTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
                    if ($promptTokens !== null && $completionTokens !== null) {
                        // Accumulate across all API calls (tool call rounds)
                        $this->totalInputTokens += (int)$promptTokens;
                        $this->totalOutputTokens += (int)$completionTokens;
                        $this->apiCallCount++;

                        // Set cumulative usage so the final response has total counts
                        $responseMessage->setUsage(
                            new \NeuronAI\Chat\Messages\Usage(
                                $this->totalInputTokens,
                                $this->totalOutputTokens
                            )
                        );
                    }

                    if (isset($usage['server_tool_use']) && \is_array($usage['server_tool_use'])) {
                        foreach ($usage['server_tool_use'] as $key => $count) {
                            $this->serverToolUsage[$key] = (int)($this->serverToolUsage[$key] ?? 0) + (int)$count;
                        }
                    }
                }

                if ($this->serverToolUsage !== []) {
                    $responseMessage->addMetadata('server_tool_use', $this->serverToolUsage);
                }

                return $responseMessage;
            });
    }

    /**
     * Keep reasoning available for tool use, but exclude it from the visible
     * assistant response so models like Grok do not leak scratchpad text into
     * chat history or Telegram output.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function applyReasoningConfig(array $json): array
    {
        if (isset($json['reasoning']) && \is_array($json['reasoning'])) {
            if (!isset($json['reasoning']['exclude'])) {
                $json['reasoning']['exclude'] = true;
            }
            return $json;
        }

        if (isset($json['extra_body']) && \is_array($json['extra_body']) && isset($json['extra_body']['reasoning']) && \is_array($json['extra_body']['reasoning'])) {
            if (!isset($json['extra_body']['reasoning']['exclude'])) {
                $json['extra_body']['reasoning']['exclude'] = true;
            }
            return $json;
        }

        if (str_starts_with($this->model, 'x-ai/')) {
            $json['reasoning'] = [
                'effort' => 'medium',
                'exclude' => true,
            ];
            return $json;
        }

        $json['reasoning'] = [
            'max_tokens' => 1024,
            'exclude' => true,
        ];

        return $json;
    }

    /**
     * Get cumulative token usage across all API calls
     */
    public function getCumulativeUsage(): array
    {
        return [
            'input_tokens'  => $this->totalInputTokens,
            'output_tokens' => $this->totalOutputTokens,
            'api_calls'     => $this->apiCallCount,
        ];
    }

    public function generateToolsPayload(): array
    {
        // Delegate to the OpenAI ToolPayloadMapper for compatibility with Neuron AI current interfaces
        return $this->toolPayloadMapper()->map($this->tools);
    }

    /**
     * @param array<string, mixed> $message
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $message): Message
    {
        $tools = \array_map(
            fn (array $item): ToolInterface => $this->findTool($item['function']['name'])
                ->setInputs(
                    \json_decode((string) $item['function']['arguments'], true)
                )
                ->setCallId($item['id']),
            $message['tool_calls']
        );

        $result = new ToolCallMessage(
            $message['content'],
            $tools
        );

        $result->addMetadata('tool_calls', $message['tool_calls']);
        // NOTE: reasoning_details intentionally NOT stored - see chatAsync() comment

        return $result;
    }
}
