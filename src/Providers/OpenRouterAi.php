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
use NeuronAI\Providers\OpenAI\MessageMapper;
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

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
    ) {
        $this->client = new Client([
            'base_uri' => \trim($this->baseUri, '/').'/',
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
        return $this->messageMapper ?? $this->messageMapper = new MessageMapper();
    }

    public function toolPayloadMapper(): ToolPayloadMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new \NeuronAI\Providers\OpenAI\ToolPayloadMapper();
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

            // Ensure we request reasoning blocks so they can be preserved across tool calls
            // Respect explicitly provided extra_body in $this->parameters, otherwise set a sensible default
            if (!isset($json['extra_body'])) {
                $json['extra_body'] = ['reasoning' => ['max_tokens' => 2000]];
            } else {
                // Do not override user's config, but if reasoning key missing, add a default
                if (is_array($json['extra_body']) && !isset($json['extra_body']['reasoning'])) {
                    $json['extra_body']['reasoning'] = ['max_tokens' => 2000];
                }
            }
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

                // Preserve reasoning_details if present (for reasoning models)
                if (isset($msg['reasoning_details'])) {
                    $responseMessage->addMetadata('reasoning_details', $msg['reasoning_details']);
                } elseif (isset($choice['reasoning_details'])) {
                    $responseMessage->addMetadata('reasoning_details', $choice['reasoning_details']);
                } elseif (isset($result['reasoning_details'])) {
                    // Some providers (e.g., Gemini via OpenRouter) may surface reasoning_details at top-level
                    $responseMessage->addMetadata('reasoning_details', $result['reasoning_details']);
                }

                if (\array_key_exists('usage', $result)) {
                    $usage = $result['usage'];
                    // OpenRouter models may return input_tokens/output_tokens instead
                    $promptTokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
                    $completionTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
                    if ($promptTokens !== null && $completionTokens !== null) {
                        $responseMessage->setUsage(
                            new \NeuronAI\Chat\Messages\Usage($promptTokens, $completionTokens)
                        );
                    }
                }

                return $responseMessage;
            });
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
        // Preserve reasoning_details so the caller can pass them back unchanged on the next request
        if (isset($message['reasoning_details'])) {
            $result->addMetadata('reasoning_details', $message['reasoning_details']);
        }

        return $result;
    }
}
