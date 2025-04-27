<?php

namespace App\Services;

use Enqueue\Redis\RedisConnectionFactory;
use Interop\Queue\Context;
use Interop\Queue\Queue;

/**
 * Service for handling queue operations using Redis.
 */
class QueueService
{
    private Context $context;
    private Queue $webhookQueue;

    /**
     * QueueService constructor.
     */
    public function __construct()
    {
        // Get Redis connection details from environment or use defaults
        $redisHost = getenv('REDIS_HOST') ?: 'redis';
        $redisPort = getenv('REDIS_PORT') ?: 6379;

        // Create a connection factory
        $factory = new RedisConnectionFactory([
            'host' => $redisHost,
            'port' => $redisPort,
        ]);

        // Create a context
        $this->context = $factory->createContext();

        // Create a queue for webhook processing
        // Note: Redis doesn't need explicit queue declaration like RabbitMQ does
        $this->webhookQueue = $this->context->createQueue('webhook_queue');
    }

    /**
     * Send a webhook update to the queue for processing.
     *
     * @param string $updateJson The JSON string received from Telegram
     * @return bool Whether the message was sent successfully
     */
    public function sendWebhookToQueue(string $updateJson): bool
    {
        try {
            // Create a message
            $message = $this->context->createMessage($updateJson);

            // Get a producer
            $producer = $this->context->createProducer();

            // Send the message
            $producer->send($this->webhookQueue, $message);

            return true;
        } catch (\Throwable $e) {
            error_log('Error sending webhook to queue: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Consume messages from the webhook queue.
     *
     * @param callable $callback The callback to process the message
     * @param int $timeout Timeout in milliseconds
     * @return void
     */
    public function consumeWebhookQueue(callable $callback, int $timeout = 1000): void
    {
        // Create a consumer
        $consumer = $this->context->createConsumer($this->webhookQueue);

        // Receive a message with a timeout
        $message = $consumer->receive($timeout);

        if ($message !== null) {
            try {
                // Process the message
                $result = $callback($message->getBody());

                // Acknowledge the message if processed successfully
                if ($result) {
                    $consumer->acknowledge($message);
                } else {
                    // Reject the message if processing failed
                    $consumer->reject($message);
                }
            } catch (\Throwable $e) {
                error_log('Error processing webhook from queue: ' . $e->getMessage());
                // Reject the message if an exception occurred
                $consumer->reject($message);
            }
        }
    }
}
