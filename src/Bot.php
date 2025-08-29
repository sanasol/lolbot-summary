<?php

namespace App;

use App\Services\AIService;
use App\Services\AntiSpamHandler;
use App\Services\BotMentionHandler;
use App\Services\CommandHandler;
use App\Services\LoggerService;
use App\Services\MarkdownService;
use App\Services\MessageStorage;
use App\Services\SettingsService;
use App\Services\TelegramSender;
use App\Services\WebhookProcessor;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;
use RuntimeException;

class Bot
{
    private Telegram $telegram;
    private array $config;
    private string $logPath;

    private AIService $aiService;
    private SettingsService $settingsService;
    private MarkdownService $markdownService;
    private MessageStorage $messageStorage;
    private LoggerService $logger;
    private TelegramSender $sender;
    private CommandHandler $commandHandler;
    private BotMentionHandler $mentionHandler;
    private WebhookProcessor $webhookProcessor;
    private AntiSpamHandler $antiSpamHandler;

    // For daily summary scheduling
    private $lastSummaryCheckTime = 0;

    /**
     * Get the bot configuration.
     *
     * @return array The bot configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logPath = $config['log_path'] ?? (__DIR__ . '/../data');

        // Ensure log directory exists
        if (!is_dir($this->logPath) && !mkdir($concurrentDirectory = $this->logPath, 0777, true) && !is_dir(
                $concurrentDirectory
            )) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }

        // Initialize services
        $this->initializeServices();

        try {
            // Initialize Telegram bot
            $this->telegram = new Telegram($config['telegram_bot_token'], 'newbotname2025bot'); // Replace BotUsername if needed

            // Initialize webhook processor with bot username
            $this->webhookProcessor = new WebhookProcessor(
                $this->messageStorage,
                $this->mentionHandler,
                $this->commandHandler,
                $this->logger,
                $this->aiService,
                $this->antiSpamHandler,
                $this->config,
                $this->telegram->getBotUsername()
            );

        } catch (TelegramException $e) {
            // Log error
            $this->logger->logError("Error initializing Telegram bot", "Initialization", $e);
            throw $e; // Rethrow exception
        }
    }

    /**
     * Initialize all the services used by the bot
     */
    private function initializeServices(): void
    {
        // Core services
        $this->logger = new LoggerService($this->logPath);
        $this->settingsService = new SettingsService($this->logPath);
        $this->messageStorage = new MessageStorage($this->logPath);
        $this->markdownService = new MarkdownService();

        // AI service depends on settings and logger
        $this->aiService = new AIService($this->config, $this->settingsService, $this->logger);

        // Services that depend on other services
        $this->sender = new TelegramSender(
            $this->markdownService,
            $this->logger,
            $this->config,
            $this->messageStorage
        );
        $this->mentionHandler = new BotMentionHandler(
            $this->aiService,
            $this->settingsService,
            $this->messageStorage,
            $this->logger
        );
        $this->commandHandler = new CommandHandler(
            $this->aiService,
            $this->settingsService,
            $this->messageStorage,
            $this->logger,
            $this->sender,
            $this->config
        );

        // Initialize AntiSpamHandler
        $this->antiSpamHandler = new AntiSpamHandler(
            $this->config,
            $this->logger
        );
    }

    /**
     * Run the bot (legacy method)
     */
    public function run(): void
    {
        echo "Bot started... Waiting for updates.\n";
        // This method is no longer the main loop when using cron.
        // Kept for potential direct execution or alternative modes.
        // The actual work is now in processUpdates().
        while (true) {
            echo "run() called - use processUpdates() for cron execution.\n";
            sleep(60); // Prevent tight loop if run directly
        }
    }

    /**
     * Process a webhook update from Telegram.
     * This method is called by the webhook.php file when a new update is received.
     * It delegates to AsyncWebhookHandler for asynchronous processing.
     *
     * @param string $updateJson The JSON string received from Telegram
     * @return bool Whether the update was validated successfully
     */
    public function processWebhook(string $updateJson): bool
    {
        // Delegate to AsyncWebhookHandler for asynchronous processing
        return \App\Services\AsyncWebhookHandler::processAsync($this, $updateJson);
    }

    /**
     * Process a webhook update asynchronously after the response has been sent to Telegram.
     * This method contains the actual processing logic.
     *
     * @param string $updateJson The JSON string received from Telegram
     * @return void
     */
    public function processWebhookAsync(string $updateJson): void
    {
        $this->webhookProcessor->processWebhookAsync($updateJson);
    }

    /**
     * Set the webhook URL for the bot.
     * This method should be called once to configure the webhook.
     *
     * @param string $url The full URL to your webhook.php file (must be HTTPS)
     * @param array $options Additional options for the webhook
     * @return bool Whether the webhook was set successfully
     */
    public function setWebhook(string $url, array $options = []): bool
    {
        try {
            // Default options
            $webhookOptions = [
                'url' => $url,
                'max_connections' => 40,
                'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            ];

            // Merge with custom options if provided
            if (!empty($options)) {
                $webhookOptions = array_merge($webhookOptions, $options);
            }

            // Set the webhook
            $result = $this->telegram->setWebhook($url, $webhookOptions);

            if ($result->isOk()) {
                echo "Webhook set successfully to: {$url}\n";
                return true;
            }

            $this->logger->logError("Failed to set webhook: " . $result->getDescription());
            return false;

        } catch (TelegramException $e) {
            $this->logger->logError("Error setting webhook", "Webhook Setup", $e);
            return false;
        }
    }

    /**
     * Trigger cleanup of old messages
     */
    public function triggerCleanup(): void
    {
        $this->messageStorage->cleanupOldMessages();
    }

    /**
     * Check and send daily summaries if needed
     */
    public function checkAndSendDailySummaries(): void
    {
        $this->sendDailySummaries();
    }

    /**
     * Send daily summaries to all chats that need them
     */
    protected function sendDailySummaries(): void
    {
        $currentTime = time();
        $checkInterval = 3600; // Check every hour

        // Avoid checking too frequently
        if ($currentTime - $this->lastSummaryCheckTime < $checkInterval) {
            return;
        }
        $this->lastSummaryCheckTime = $currentTime;

        // When should the summary be sent? e.g., at 8:00 AM server time
        $summaryHour = 8; // 8 AM
        $currentHour = (int)date('G'); // 24-hour format

        // Check if it's the summary hour and if we haven't sent one today for relevant chats
        if ($currentHour === $summaryHour) {
            echo "Checking chats for daily summary...\n";
            $this->triggerCleanup(); // Clean up very old messages before summarizing

            foreach ($this->messageStorage->getAllChatIds() as $chatId) {
                 // Add logic here to track if a summary was already sent today for this chat
                 // This could involve storing the last summary timestamp per chat (in memory or file/DB)
                 if ($this->shouldSendDailySummary($chatId, $currentTime)) {
                     echo "Sending daily summary to chat {$chatId}...\n";
                     $this->commandHandler->handleSummaryCommand($chatId); // Reuse the command logic
                     $this->markDailySummarySent($chatId, $currentTime); // Mark as sent
                 }
            }
        }
    }

    /**
     * Check if a daily summary should be sent to a chat
     *
     * @param int $chatId The chat ID
     * @param int $currentTime The current timestamp
     * @return bool Whether a summary should be sent
     */
    private function shouldSendDailySummary(int $chatId, int $currentTime): bool
    {
        $filePath = $this->getLastSummarySentFile($chatId);
        if (!file_exists($filePath)) {
            return true; // Never sent before
        }

        $lastSentTimestamp = (int)file_get_contents($filePath);
        $secondsSinceLastSummary = $currentTime - $lastSentTimestamp;

        // Send if more than 23 hours have passed (allows for some flexibility around the exact hour)
        return $secondsSinceLastSummary > (23 * 3600);
    }

    /**
     * Mark a daily summary as sent for a chat
     *
     * @param int $chatId The chat ID
     * @param int $currentTime The current timestamp
     */
    private function markDailySummarySent(int $chatId, int $currentTime): void
    {
        $filePath = $this->getLastSummarySentFile($chatId);
        file_put_contents($filePath, (string)$currentTime);
    }

    /**
     * Get the path to the file that stores the last summary sent time
     *
     * @param int $chatId The chat ID
     * @return string The file path
     */
    private function getLastSummarySentFile(int $chatId): string
    {
        return $this->logPath . '/' . $chatId . '_last_summary.txt';
    }
}
