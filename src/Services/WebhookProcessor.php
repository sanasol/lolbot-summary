<?php

namespace App\Services;

use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

/**
 * Processes webhook updates from Telegram.
 */
class WebhookProcessor
{
    private MessageStorage $messageStorage;
    private BotMentionHandler $mentionHandler;
    private CommandHandler $commandHandler;
    private LoggerService $logger;
    private AIService $aiService;
    private AntiSpamHandler $antiSpamHandler;
    private NewUserRestrictionService $newUserRestrictionService;
    private SettingsService $settingsService;
    private InteractionRouter $interactionRouter;
    private ChatMetadataService $chatMetadataService;
    private MemoryExtractor $memoryExtractor;
    private array $config;
    private string $botUsername;

    public function __construct(
        MessageStorage $messageStorage,
        BotMentionHandler $mentionHandler,
        CommandHandler $commandHandler,
        LoggerService $logger,
        AIService $aiService,
        AntiSpamHandler $antiSpamHandler,
        NewUserRestrictionService $newUserRestrictionService,
        SettingsService $settingsService,
        InteractionRouter $interactionRouter,
        ChatMetadataService $chatMetadataService,
        MemoryExtractor $memoryExtractor,
        array $config,
        string $botUsername
    ) {
        $this->messageStorage = $messageStorage;
        $this->mentionHandler = $mentionHandler;
        $this->commandHandler = $commandHandler;
        $this->logger = $logger;
        $this->antiSpamHandler = $antiSpamHandler;
        $this->newUserRestrictionService = $newUserRestrictionService;
        $this->settingsService = $settingsService;
        $this->interactionRouter = $interactionRouter;
        $this->chatMetadataService = $chatMetadataService;
        $this->memoryExtractor = $memoryExtractor;
        $this->config = $config;
        $this->botUsername = ltrim($botUsername, '@');
        $this->aiService = $aiService;
    }

    public function processWebhookAsync(string $updateJson): void
    {
        try {
            $decodedUpdate = json_decode($updateJson, true);
            if (empty($decodedUpdate)) {
                $this->logger->logError('Empty or invalid update received in async processing');
                return;
            }

            $update = new Update($decodedUpdate, $this->botUsername);

            $callback = $update->getCallbackQuery();
            if ($callback) {
                $data = (string)$callback->getData();
                if (str_starts_with($data, 'vote|')) {
                    $this->logger->logWebhook('Processing vote callback early (bypass thread check): ' . $data);
                    $this->commandHandler->handleVoteCallback($callback);
                    return;
                }
            }

            if ($this->hasDuplicateUpdate($update->getUpdateId())) {
                $this->logger->log(
                    'Duplicate update received: ' . json_encode($decodedUpdate),
                    'Duplicate Update'
                );
                return;
            }

            $this->handleNewMembers($update);

            $isEditedMessage = $update->getEditedMessage() !== null;
            $message = $update->getMessage() ?? $update->getEditedMessage();

            if ($isEditedMessage) {
                $this->logger->log('Skip processing edited message', 'Skip edited Processing');
                return;
            }

            if ($message && $this->isSelfAuthoredMessage($message)) {
                $chatId = $message->getChat()->getId();
                $messageId = $message->getMessageId();
                $this->logger->logWebhook(
                    "Skipping self-authored bot message {$messageId} in chat {$chatId}"
                );
                return;
            }

            $shouldProcessCommands = true;
            if ($message && ($message->getChat()->isGroupChat() || $message->getChat()->isSuperGroup())) {
                $shouldProcessCommands = $this->processGroupMessage($message);
            }

            if ($message && !$isEditedMessage && $shouldProcessCommands) {
                $this->processCommands($message);
            } elseif ($message && $isEditedMessage) {
                $chatId = $message->getChat()->getId();
                $fromUser = $this->buildDisplayNameFromTelegramUser($message->getFrom());
                $this->logger->log(
                    "Skipping command processing for edited message in chat {$chatId} by {$fromUser}",
                    'Edited Message'
                );
            }

            if ($message && $message->getText(false)) {
                $chatId = $message->getChat()->getId();
                $chatType = $message->getChat()->getType();
                $fromUser = $this->buildDisplayNameFromTelegramUser($message->getFrom());
                $messageText = $message->getText(false);

                $this->logger->logWebhook("Message in {$chatType} {$chatId} from {$fromUser}: {$messageText}");
            }
        } catch (TelegramException $e) {
            $this->logger->logError('Telegram API Error: ' . $e->getMessage(), 'Webhook Error');
        } catch (\Throwable $e) {
            $this->logger->logError('General Error: ' . $e->getMessage(), 'Webhook Error', $e);
        }
    }

    private function handleNewMembers(Update $update): void
    {
        $message = $update->getMessage();
        if (!$message) {
            return;
        }

        $newChatMembers = $message->getNewChatMembers();
        if (!$newChatMembers || empty($newChatMembers)) {
            return;
        }

        $chatId = $message->getChat()->getId();
        foreach ($newChatMembers as $member) {
            $userId = $member->getId();
            $username = $member->getUsername() ?? $member->getFirstName() ?? 'User';
            $this->logger->log("New member joined: {$username} (ID: {$userId}) in chat {$chatId}", 'NewMember');
            $this->newUserRestrictionService->handleNewMember($chatId, $userId, $username);
        }
    }

    private function processGroupMessage($message): bool
    {
        $chat = $message->getChat();
        $from = $message->getFrom();

        $chatId = $chat->getId();
        $messageText = (string)($message->getText() ?? '');
        $caption = $message->getCaption();
        $timestamp = $message->getDate();
        $userId = $from->getId();
        $messageId = $message->getMessageId();
        $messageThreadId = $message->getMessageThreadId();
        $photos = $message->getPhoto();
        $textToUse = trim((string)($messageText ?: ($caption ?: '')));
        $replyTargetUserId = $message->getReplyToMessage()?->getFrom()?->getId();

        $senderContext = $this->buildSenderContext($from);
        $displayName = $senderContext['display_name'];

        $this->chatMetadataService->seedFromMessage(
            $chatId,
            $chat->getTitle(),
            $chat->getUsername(),
            $chat->getType()
        );

        $configuredThreadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        $allowInteractionsInThisThread = !($configuredThreadId !== null && $configuredThreadId !== $messageThreadId);

        if ($messageText !== '') {
            $handledAsCaptcha = $this->newUserRestrictionService->handlePotentialCaptchaAnswer(
                $chatId,
                $userId,
                $messageText,
                $messageId,
                $senderContext
            );

            if ($handledAsCaptcha) {
                $this->logger->log("Message from user {$userId} was handled as captcha answer", 'NewUserRestriction');
                return false;
            }
        }

        $allowedCheck = ['allowed' => true, 'reason' => null];
        if ($this->shouldCheckNewUserRestriction($message, $textToUse, $photos)) {
            $allowedCheck = $this->newUserRestrictionService->checkUserAllowed($message, $senderContext);
            if (!$allowedCheck['allowed']) {
                $this->logger->log(
                    "User {$displayName} (ID: {$userId}) is restricted, deleting message",
                    'NewUserRestriction'
                );

                if ($allowedCheck['reason'] === 'waiting_period') {
                    $this->newUserRestrictionService->deleteMessageAndWarn(
                        $chatId,
                        $userId,
                        $messageId,
                        $allowedCheck['remaining_minutes']
                    );
                } elseif ($allowedCheck['reason'] === 'pending_captcha') {
                    Request::deleteMessage([
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                    ]);
                }

                return false;
            }
        }

        if ($messageText) {
            $this->logger->log("Checking message for spam in chat {$chatId} from user {$displayName}", 'Spam Check', 'webhook');
            $isSpam = $this->antiSpamHandler->checkAndHandleSpam($messageText, $userId, $displayName, $chatId, $messageId);
            if ($isSpam) {
                $this->logger->log("Message from {$displayName} in chat {$chatId} was handled as spam, skipping further processing", 'Spam Check', 'webhook');
                return false;
            }
        }

        try {
            $this->memoryExtractor->observeUser($chatId, $senderContext, $timestamp);
        } catch (\Throwable $e) {
            $this->logger->logError('User snapshot observation failed: ' . $e->getMessage(), 'MemoryExtractor', $e);
        }

        $formattedDescription = null;
        if ($messageText && empty($photos)) {
            $this->messageStorage->storeMessage(
                $chatId,
                $timestamp,
                $displayName,
                $messageText,
                $messageId,
                $messageThreadId,
                $senderContext + [
                    'text' => $messageText,
                    'thread_id' => $messageThreadId,
                    'message_type' => 'text',
                    'has_photo' => false,
                ]
            );

            if (!$this->isExplicitCommand($messageText)) {
                try {
                    $this->memoryExtractor->maybeExtractFromMessage(
                        $chatId,
                        $userId,
                        $displayName,
                        $messageText,
                        $messageId
                    );
                } catch (\Throwable $e) {
                    $this->logger->logError('Memory extraction failed: ' . $e->getMessage(), 'MemoryExtractor', $e);
                }
            }
        }

        if ($photos && !empty($photos)) {
            $formattedDescription = $this->processImage($photos, $caption);
            $this->messageStorage->storeStructuredMessage([
                'ts' => $timestamp,
                'chat_id' => $chatId,
                'thread_id' => $messageThreadId,
                'message_id' => $messageId,
                'user_id' => $userId,
                'username' => $senderContext['username'],
                'first_name' => $senderContext['first_name'],
                'last_name' => $senderContext['last_name'],
                'display_name' => $displayName,
                'is_bot' => false,
                'message_type' => 'photo',
                'text' => null,
                'caption' => $caption,
                'has_photo' => true,
                'image_summary' => $this->extractImageSummary($formattedDescription, $caption),
                'legacy_username' => $displayName,
            ]);
        }

        if (($allowedCheck['record_after_accept'] ?? false) && $messageText !== '' && !$this->isExplicitCommand($messageText)) {
            $this->newUserRestrictionService->recordAcceptedMessage(
                $chatId,
                $userId,
                $senderContext,
                $timestamp
            );
        }

        if (!$allowInteractionsInThisThread || $this->isExplicitCommand($messageText)) {
            return true;
        }

        $isReplyToBot = $this->isReplyToBot($message);
        if ($isReplyToBot) {
            $this->logger->log("Detected reply to bot message in chat {$chatId} by {$displayName}", 'Bot Reply');
        }

        if ($this->isIntentRouterEnabledForChat($chatId)) {
            $this->routeWithIntentRouter(
                $chatId,
                $textToUse,
                $displayName,
                $messageId,
                $userId,
                $messageThreadId,
                $photos,
                $formattedDescription,
                $isReplyToBot,
                $configuredThreadId !== null && $configuredThreadId === $messageThreadId,
                $replyTargetUserId
            );
            return true;
        }

        if ($photos && !empty($photos) && !$this->interactionRouter->isAddressedToBot($textToUse, $isReplyToBot)) {
            $this->logger->logWebhook(
                "Skipping legacy mention flow for passive photo upload in chat {$chatId} by {$displayName}"
            );
            return true;
        }

        if ($textToUse === '' && !$isReplyToBot) {
            return true;
        }

        $legacyDecision = $this->interactionRouter->decide($textToUse, $isReplyToBot, !empty($photos));
        $replyStyleMode = (string)$this->settingsService->getSetting($chatId, 'reply_style_mode', 'auto');

        $this->processBotMention(
            $chatId,
            $textToUse,
            $displayName,
            $messageId,
            $photos,
            $formattedDescription,
            $isReplyToBot,
            [
                'trigger' => 'legacy',
                'tone' => $this->resolveToneOverride($replyStyleMode, $legacyDecision->tone),
                'intent' => $legacyDecision->intent,
                'analytics_confidence' => $legacyDecision->analyticsConfidence,
                'image_intent' => $legacyDecision->imageIntent,
                'user_id' => $userId,
                'thread_id' => $messageThreadId,
                'reply_target_user_id' => $replyTargetUserId,
            ]
        );

        return true;
    }

    private function processCommands($message): void
    {
        $chat = $message->getChat();
        $chatId = $chat->getId();
        $chatTitle = $chat->getTitle() ?? 'Unknown';
        $fromUser = $this->buildDisplayNameFromTelegramUser($message->getFrom());
        $messageId = $message->getMessageId();
        $messageText = (string)($message->getText(false) ?? '');
        $messageThreadId = $message->getMessageThreadId();
        $replyTargetUserId = $message->getReplyToMessage()?->getFrom()?->getId();
        $handledExplicitCommand = false;

        $this->chatMetadataService->seedFromMessage(
            $chatId,
            $chat->getTitle(),
            $chat->getUsername(),
            $chat->getType()
        );

        if ($chat->isPrivateChat() && $messageText) {
            $senderContext = $this->buildSenderContext($message->getFrom());
            $this->messageStorage->storeMessage(
                $chatId,
                $message->getDate(),
                $fromUser,
                $messageText,
                $messageId,
                $messageThreadId,
                $senderContext + [
                    'text' => $messageText,
                    'thread_id' => $messageThreadId,
                    'message_type' => 'text',
                ]
            );
        }

        $configuredThreadId = $this->settingsService->getSetting($chatId, 'message_thread_id', null);
        $allowInteractionsInThisThread = !($configuredThreadId !== null && $configuredThreadId !== $messageThreadId);

        if ($allowInteractionsInThisThread && str_starts_with($messageText, '/summary')) {
            $handledExplicitCommand = true;
            $params = trim(substr($messageText, 8));
            $this->logger->logWebhook(
                "Received /summary command in chat {$chatId} ({$chatTitle}) from user {$fromUser} with params: "
                . (strlen($params) ? substr($params, 0, 50) . (strlen($params) > 50 ? '...' : '') : '[none]')
            );
            $this->commandHandler->handleSummaryCommand($chatId, $params, $messageId);
        }

        if ($allowInteractionsInThisThread && str_starts_with($messageText, '/mcp')) {
            $handledExplicitCommand = true;
            $query = trim(substr($messageText, 4));
            $this->logger->logWebhook(
                "Received /mcp command in chat {$chatId} ({$chatTitle}) from user {$fromUser} with query: "
                . substr($query, 0, 50)
                . (strlen($query) > 50 ? '...' : '')
            );
            $this->commandHandler->handleMCPCommand(
                $chatId,
                $query,
                $fromUser,
                $messageId,
                $message->getFrom()->getId(),
                $messageThreadId,
                'slash'
            );
        }

        if ($allowInteractionsInThisThread && str_starts_with($messageText, '/settings')) {
            $handledExplicitCommand = true;
            $params = trim(substr($messageText, 9));
            $this->logger->logWebhook(
                "Received /settings command in chat {$chatId} ({$chatTitle}) from user {$fromUser} with params: "
                . substr($params, 0, 50)
                . (strlen($params) > 50 ? '...' : '')
            );
            $this->commandHandler->handleSettingsCommand($chatId, $params, $fromUser, $messageId, $message);
        }

        if ($allowInteractionsInThisThread && str_starts_with($messageText, '/help')) {
            $handledExplicitCommand = true;
            $this->logger->logWebhook("Received /help command in chat {$chatId} ({$chatTitle}) from user {$fromUser}");
            $this->commandHandler->handleHelpCommand($chatId, $messageId);
        }

        if (str_starts_with($messageText, '/voteban')) {
            $handledExplicitCommand = true;
            $this->logger->logWebhook("Received /voteban in chat {$chatId} ({$chatTitle}) from {$fromUser}");
            $this->commandHandler->handleVoteStartCommand($chatId, $message, 'ban');
        }
        if (str_starts_with($messageText, '/votekick') || str_starts_with($messageText, '/votemute')) {
            $handledExplicitCommand = true;
            $this->logger->logWebhook("Received vote mute/kick in chat {$chatId} ({$chatTitle}) from {$fromUser}");
            $this->commandHandler->handleVoteStartCommand($chatId, $message, 'mute');
        }
        if (str_starts_with($messageText, '/yes')) {
            $handledExplicitCommand = true;
            $this->logger->logWebhook("Received /yes vote in chat {$chatId} ({$chatTitle}) from {$fromUser}");
            $this->commandHandler->handleVoteResponseCommand($chatId, $message, true);
        }
        if (str_starts_with($messageText, '/no')) {
            $handledExplicitCommand = true;
            $this->logger->logWebhook("Received /no vote in chat {$chatId} ({$chatTitle}) from {$fromUser}");
            $this->commandHandler->handleVoteResponseCommand($chatId, $message, false);
        }

        if ($allowInteractionsInThisThread && str_starts_with($messageText, '/account')) {
            $handledExplicitCommand = true;
            $accountIdentifier = trim(substr($messageText, 8));
            $this->logger->logWebhook(
                "Received /account command in chat {$chatId} ({$chatTitle}) from user {$fromUser} with identifier: "
                . substr($accountIdentifier, 0, 10)
                . (strlen($accountIdentifier) > 10 ? '...' : '')
            );
            $this->commandHandler->handleAccountCommand(
                $chatId,
                $accountIdentifier,
                $message->getFrom()->getId(),
                $messageId,
                $chat->isPrivateChat()
            );
        }

        if (
            $chat->isPrivateChat()
            && !$handledExplicitCommand
            && trim($messageText) !== ''
            && $this->isIntentRouterEnabledForChat($chatId)
        ) {
            $this->routeWithIntentRouter(
                $chatId,
                $messageText,
                $fromUser,
                $messageId,
                $message->getFrom()->getId(),
                $messageThreadId,
                null,
                null,
                true,
                false,
                $replyTargetUserId
            );
        }
    }

    /**
     * @param array<int, mixed>|null $photos
     * @param array<int, string>|null $imageDescription
     */
    private function routeWithIntentRouter(
        int $chatId,
        string $messageText,
        string $username,
        int $messageId,
        int $userId,
        ?int $messageThreadId,
        $photos,
        ?array $imageDescription,
        bool $isReplyToBot,
        bool $isInConfiguredBotTopic,
        ?int $replyTargetUserId
    ): void {
        $agentEnabled = $this->isAgentToolsEnabledForChat($chatId);
        $decision = $this->interactionRouter->decide(
            $messageText,
            $isReplyToBot,
            !empty($photos),
            $isInConfiguredBotTopic,
            $agentEnabled
        );
        $this->logger->logWebhook(
            "Interaction router decision for chat {$chatId}: " . json_encode($decision->toArray(), JSON_UNESCAPED_UNICODE)
        );

        if ($decision->route === InteractionDecision::ROUTE_IGNORE) {
            return;
        }

        if ($decision->route === InteractionDecision::ROUTE_MCP) {
            $query = trim($decision->cleanedPrompt) !== '' ? trim($decision->cleanedPrompt) : trim($messageText);
            $this->commandHandler->handleMCPCommand(
                $chatId,
                $query,
                $username,
                $messageId,
                $userId,
                $messageThreadId,
                'auto'
            );
            return;
        }

        $replyStyleMode = (string)$this->settingsService->getSetting($chatId, 'reply_style_mode', 'auto');
        $tone = $this->resolveToneOverride($replyStyleMode, $decision->tone);

        $this->processBotMention(
            $chatId,
            $messageText,
            $username,
            $messageId,
            $photos,
            $imageDescription,
            $isReplyToBot,
            [
                'force_response' => true,
                'trigger' => 'router',
                'route' => $decision->route,
                'tone' => $tone,
                'intent' => $decision->intent,
                'analytics_confidence' => $decision->analyticsConfidence,
                'image_intent' => $decision->imageIntent,
                'suggest_mcp' => $decision->shouldSuggestMcp(),
                'user_id' => $userId,
                'thread_id' => $messageThreadId,
                'reply_target_user_id' => $replyTargetUserId,
            ]
        );
    }

    /**
     * @param array<int, mixed>|null $photos
     * @param array<int, string>|null $imageDescription
     * @param array<string, mixed> $options
     */
    private function processBotMention(
        int $chatId,
        string $textToUse,
        string $username,
        int $messageId,
        $photos = null,
        ?array $imageDescription = null,
        bool $isReplyToBot = false,
        array $options = []
    ): void {
        $this->mentionHandler->handleBotMention($chatId, $textToUse, $username, $messageId, $photos, $imageDescription, $isReplyToBot, $options);
    }

    /**
     * Process an image in a message.
     *
     * @param array<int, mixed> $photos
     * @return array<int, string>|null
     */
    private function processImage($photos, ?string $caption): ?array
    {
        try {
            $largestPhoto = end($photos);
            $fileId = $largestPhoto->getFileId();

            $this->logger->log('Downloading image with file ID: ' . $fileId);

            $fileResult = Request::getFile(['file_id' => $fileId]);

            if ($fileResult->isOk()) {
                $filePath = $fileResult->getResult()->getFilePath();
                $imageUrl = 'https://api.telegram.org/file/bot' . $this->config['telegram_bot_token'] . '/' . $filePath;
                $tmpFile = tempnam(sys_get_temp_dir(), 'telegram_img_');
                $imageData = file_get_contents($imageUrl);

                if ($imageData !== false) {
                    file_put_contents($tmpFile, $imageData);
                    $base64Image = base64_encode($imageData);

                    $this->logger->logError('Downloaded image successfully.', 'Webhook Image Download Success');

                    $imageDescription = $this->aiService->generateImageDescription($base64Image, true, $caption);
                    @unlink($tmpFile);
                } else {
                    $this->logger->logError('Failed to download image from URL: ' . $imageUrl);
                    $imageDescription = null;
                }

                if ($imageDescription) {
                    $formattedDescription = '[IMAGE: ' . $imageDescription . ']';

                    if ($caption) {
                        $formattedDescription = $caption . ' ' . $formattedDescription;
                    }

                    $this->logger->log('Stored image with description: ' . $formattedDescription, 'Webhook Image Description');
                    return [$formattedDescription, $imageUrl];
                }
            } else {
                $this->logger->logError('Telegram API Error: ' . $fileResult->getDescription(), 'Webhook Error');
                return ['', ''];
            }
        } catch (\Exception $e) {
            $this->logger->logError('Error while processing image: ' . $e->getMessage());
            return ['', ''];
        }

        return [null, null];
    }

    private function buildTopicLink(int $chatId, int $topicId): ?string
    {
        try {
            $chatIdStr = (string)$chatId;
            $internal = str_starts_with($chatIdStr, '-100') ? substr($chatIdStr, 4) : ltrim($chatIdStr, '-');
            if (!ctype_digit($internal)) {
                return null;
            }
            return "https://t.me/c/{$internal}/{$topicId}";
        } catch (\Throwable $e) {
            $this->logger->logError('Failed to build topic link: ' . $e->getMessage(), 'Topic Link');
            return null;
        }
    }

    private function hasDuplicateUpdate(int $updateId): bool
    {
        $jsonFile = $this->config['log_path'] . '/previous_updates.json';
        $previousUpdates = [];
        if (file_exists($jsonFile)) {
            $previousUpdates = json_decode((string)file_get_contents($jsonFile), true);
        }
        if (in_array($updateId, $previousUpdates, true)) {
            return true;
        }

        $previousUpdates[] = $updateId;
        file_put_contents($jsonFile, json_encode($previousUpdates));

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSenderContext($user): array
    {
        $username = $user?->getUsername();
        $firstName = $user?->getFirstName();
        $lastName = $user?->getLastName();
        $displayName = StructuredMessageRecord::buildDisplayName($firstName, $lastName, $username, $firstName ?? $username ?? 'Unknown');

        return [
            'user_id' => $user?->getId(),
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $displayName,
            'is_bot' => (bool)($user?->getIsBot() ?? false),
        ];
    }

    private function buildDisplayNameFromTelegramUser($user): string
    {
        $context = $this->buildSenderContext($user);
        return (string)$context['display_name'];
    }

    private function isReplyToBot($message): bool
    {
        $replyToMessage = $message->getReplyToMessage();
        if (!$replyToMessage) {
            return false;
        }

        $replyFrom = $replyToMessage->getFrom();
        if (!$replyFrom) {
            return false;
        }

        $replyUsername = ltrim((string)($replyFrom->getUsername() ?? ''), '@');
        return $replyUsername !== '' && $replyUsername === $this->botUsername;
    }

    private function isSelfAuthoredMessage($message): bool
    {
        $from = $message->getFrom();
        if (!$from || !(bool)$from->getIsBot()) {
            return false;
        }

        $username = ltrim((string)($from->getUsername() ?? ''), '@');
        if ($username === '' || $this->botUsername === '') {
            return false;
        }

        return strcasecmp($username, $this->botUsername) === 0;
    }

    private function isExplicitCommand(string $messageText): bool
    {
        $trimmed = trim($messageText);
        if (!str_starts_with($trimmed, '/')) {
            return false;
        }

        $commandToken = strtolower(strtok($trimmed, " \n\r\t") ?: '');
        return in_array($commandToken, [
            '/summary',
            '/mcp',
            '/settings',
            '/help',
            '/account',
            '/voteban',
            '/votekick',
            '/votemute',
            '/yes',
            '/no',
        ], true);
    }

    private function isIntentRouterEnabledForChat(int $chatId): bool
    {
        $perChat = $this->settingsService->getSetting($chatId, 'intent_router_enabled', null);
        if ($perChat !== null) {
            return (bool)$perChat;
        }

        return (bool)($this->config['intent_router_enabled'] ?? false);
    }

    private function isAgentToolsEnabledForChat(int $chatId): bool
    {
        $perChat = $this->settingsService->getSetting($chatId, 'agent_tools_enabled', null);
        if ($perChat !== null) {
            return (bool)$perChat;
        }

        return (bool)($this->config['agent_tools_enabled'] ?? false);
    }

    private function resolveToneOverride(string $replyStyleMode, string $routerTone): string
    {
        $replyStyleMode = strtolower(trim($replyStyleMode));
        if (in_array($replyStyleMode, [InteractionDecision::TONE_NEUTRAL, InteractionDecision::TONE_WITTY], true)) {
            return $replyStyleMode;
        }

        return $routerTone;
    }

    private function shouldCheckNewUserRestriction($message, string $textToUse, $photos): bool
    {
        if ($textToUse !== '' || ($photos && !empty($photos))) {
            return true;
        }

        return $message->getDocument() !== null
            || $message->getAnimation() !== null
            || $message->getSticker() !== null
            || $message->getVideo() !== null
            || $message->getAudio() !== null
            || $message->getVoice() !== null
            || $message->getVideoNote() !== null
            || $message->getContact() !== null
            || $message->getLocation() !== null
            || $message->getVenue() !== null
            || $message->getPoll() !== null
            || $message->getForwardDate() !== null
            || $message->getForwardFrom() !== null
            || $message->getForwardFromChat() !== null
            || (bool)($message->getIsAutomaticForward() ?? false);
    }

    /**
     * @param array<int, string>|null $formattedDescription
     */
    private function extractImageSummary(?array $formattedDescription, ?string $caption): ?string
    {
        $formatted = trim((string)($formattedDescription[0] ?? ''));
        if ($formatted === '') {
            return null;
        }

        if ($caption) {
            $caption = trim($caption);
            if ($caption !== '' && str_starts_with($formatted, $caption)) {
                $formatted = trim(substr($formatted, strlen($caption)));
            }
        }

        if (str_starts_with($formatted, '[IMAGE:') && str_ends_with($formatted, ']')) {
            $inner = trim(substr($formatted, 7, -1));
            return $inner !== '' ? $inner : null;
        }

        return $formatted !== '' ? $formatted : null;
    }
}
