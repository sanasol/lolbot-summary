<?php

namespace App\Providers;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\HandleStream;
use NeuronAI\Providers\Anthropic\HandleStructured;
use NeuronAI\Providers\Anthropic\MessageMapper;
use NeuronAI\Providers\HandleClient;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use GuzzleHttp\Client;

class Anthropic implements AIProviderInterface
{
    use HandleClient;
    use HandleWithTools;
    use HandleStream;
    use HandleStructured;

    /**
     * The http client.
     *
     * @var Client
     */
    protected Client $client;

    /**
     * The main URL of the provider API.
     *
     * @var string
     */
    protected string $baseUri = 'https://api.anthropic.com/v1/';

    /**
     * The message mapper instance.
     *
     * @var MessageMapperInterface
     */
    protected ?MessageMapperInterface $messageMapper;

    /**
     * System instructions.
     * https://docs.anthropic.com/claude/docs/system-prompts#how-to-use-system-prompts
     *
     * @var string|null
     */
    protected ?string $system;

    /**
     * AnthropicClaude constructor.
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected string $version = '2023-06-01',
        protected int $max_tokens = 8192,
        protected array $parameters = [],
    ) {
        $this->client = new Client([
            'base_uri' => trim($this->baseUri, '/').'/',
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->key,
                'anthropic-version' => $version,
            ]
        ]);
    }

    /**
     * @inerhitDoc
     */
    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    public function generateToolsPayload(): array
    {
        $payload = \array_map(function (ToolInterface $tool) {
            $properties = \array_reduce($tool->getProperties(), function ($carry, ToolProperty $property) {
                $carry[$property->getName()] = [
                    'type' => $property->getType(),
                    'description' => $property->getDescription(),
                ];

                if (!empty($property->getEnum())) {
                    $carry[$property->getName()]['enum'] = $property->getEnum();
                }

                return $carry;
            }, []);

            return [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'input_schema' => [
                    'type' => 'object',
                    'properties' => !empty($properties) ? $properties : null,
                    'required' => $tool->getRequiredProperties(),
                ],
            ];
        }, $this->tools);

        return $payload;
    }

    public function createToolMessage(array $content): Message
    {
        $tool = $this->findTool($content['name'])
            ->setInputs((array) $content['input'])
            ->setCallId($content['id']);

        // During serialization and deserialization PHP convert the original empty object {} to empty array []
        // causing an error on the Anthropic API. If there are no inputs, we need to restore the empty JSON object.
        $content['input'] ??= (object)[];

        return new ToolCallMessage(
            [$content],
            [$tool] // Anthropic call one tool at a time. So we pass an array with one element.
        );
    }

    public function chat(array $messages): Message
    {
        $mapper = new MessageMapper($messages);

        $json = [
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (isset($this->system)) {
            $json['system'] = $this->system;
        }

        if (!empty($this->tools)) {
            $json['tools'] = $this->generateToolsPayload();
        }

        // https://docs.anthropic.com/claude/reference/messages_post
        $result = $this->client->post('messages', compact('json'))
            ->getBody()->getContents();

        $result = \json_decode($result, true);

        $content = \end($result['content']);
        if (isset($content['input']) && is_array($content['input']) && empty($content['input'])) {
            $content['input'] = (object)[];
        }
        if ($content['type'] === 'tool_use') {

            $response = $this->createToolMessage($content);
        } else {
            $response = new AssistantMessage($content['text']);
        }

        // Attach the usage for the current interaction
        if (\array_key_exists('usage', $result)) {
            $response->setUsage(
                new Usage(
                    $result['usage']['input_tokens'],
                    $result['usage']['output_tokens']
                )
            );
        }

        return $response;
    }

    public function messageMapper(): MessageMapperInterface
    {
        if (!isset($this->messageMapper)) {
            $this->messageMapper = new MessageMapper();
        }
        return $this->messageMapper;
    }

}
