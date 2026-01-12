<?php

namespace App\Services;

use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Request;

/**
 * Periodic task runner for background maintenance:
 * - finalize/expire community votes and update their messages
 * - unmute users whose mute period has ended
 */
class PeriodicTaskRunner
{
    private \App\Bot $bot;
    private int $lastTick = 0;
    private int $tickIntervalSec;

    public function __construct(\App\Bot $bot, int $tickIntervalSec = 5)
    {
        $this->bot = $bot;
        $this->tickIntervalSec = max(1, $tickIntervalSec);
    }

    public function tick(): void
    {
        $now = time();
        if ($now - $this->lastTick < $this->tickIntervalSec) {
            return;
        }
        $this->lastTick = $now;

        $this->processVotes($now);
        $this->processMutes($now);
        $this->processNewUserRestrictions($now);
    }

    private function processVotes(int $now): void
    {
        $voteService = $this->bot->getVoteService();
        $logger = $this->bot->getLoggerService();
        $settings = $this->bot->getSettingsService();
        $cmd = $this->bot->getCommandHandler();

        $all = $voteService->getAllActiveVotes();
        foreach ($all as $chatId => $byKey) {
            foreach ($byKey as $key => $vote) {
                $type = $vote['type'] ?? 'ban';
                $targetUserId = (int)($vote['target_user_id'] ?? 0);
                $targetMessageId = (int)($vote['target_message_id'] ?? 0);
                $announceId = $vote['announce_message_id'] ?? null;
                $yesCount = isset($vote['yes']) && is_array($vote['yes']) ? count($vote['yes']) : 0;
                $noCount = isset($vote['no']) && is_array($vote['no']) ? count($vote['no']) : 0;
                $expiresAt = (int)($vote['expires_at'] ?? 0);

                $threshold = (int)$settings->getSetting((int)$chatId, $type === 'ban' ? 'vote_threshold_ban' : 'vote_threshold_mute', $type === 'ban' ? 5 : 3);

                // If threshold reached, apply action and finalize
                if ($yesCount >= $threshold) {
                    try {
                        $cmd->applyVoteAction((int)$chatId, $type, $targetUserId, $targetMessageId, $announceId ? (int)$announceId : null);
                    } catch (\Throwable $e) {
                        $logger->logError('Periodic: error applying vote action: ' . $e->getMessage(), 'Periodic');
                    }
                    $voteService->finalize((int)$chatId, $targetUserId, $targetMessageId);
                    $mention = '[user](tg://user?id=' . $targetUserId . ')';
                    $text = "📣 Vote to " . ($type==='ban' ? 'ban' : 'mute') . " {$mention}.\n".
                            "Yes: {$yesCount} | No: {$noCount} | Needed YES: {$threshold}\n\n".
                            "✅ Action applied.";
                    try {
                        if ($announceId) {
                            Request::editMessageText([
                                'chat_id' => (int)$chatId,
                                'message_id' => (int)$announceId,
                                'text' => $text,
                                'parse_mode' => 'Markdown',
                                'reply_markup' => new InlineKeyboard([]),
                            ]);
                        } else {
                            // Fallback notification when we don't have the announce message id
                            Request::sendMessage([
                                'chat_id' => (int)$chatId,
                                'text' => $text,
                                'parse_mode' => 'Markdown',
                                'reply_to_message_id' => $targetMessageId,
                                'allow_sending_without_reply' => true,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $logger->logError('Periodic: failed to finalize successful vote message: ' . $e->getMessage(), 'Periodic');
                    }
                    continue;
                }

                // If expired, finalize and update message as expired
                if ($expiresAt > 0 && $now >= $expiresAt) {
                    $voteService->finalize((int)$chatId, $targetUserId, $targetMessageId);
                    $mention = '[user](tg://user?id=' . $targetUserId . ')';
                    $text = "📣 Vote to " . ($type==='ban' ? 'ban' : 'mute') . " {$mention}.\n".
                            "Yes: {$yesCount} | No: {$noCount}\n\n".
                            "⌛️ Vote expired. Decision: not applied.";
                    try {
                        if ($announceId) {
                            Request::editMessageText([
                                'chat_id' => (int)$chatId,
                                'message_id' => (int)$announceId,
                                'text' => $text,
                                'parse_mode' => 'Markdown',
                                'reply_markup' => new InlineKeyboard([]),
                            ]);
                        } else {
                            // Fallback notification when we don't have the announce message id
                            Request::sendMessage([
                                'chat_id' => (int)$chatId,
                                'text' => $text,
                                'parse_mode' => 'Markdown',
                                'reply_to_message_id' => $targetMessageId,
                                'allow_sending_without_reply' => true,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $logger->logError('Periodic: failed to finalize expired vote message: ' . $e->getMessage(), 'Periodic');
                    }
                    continue;
                }

                // Otherwise, keep message updated with remaining time (optional light update)
                if ($announceId) {
                    $left = max(0, $expiresAt - $now);
                    $yesCb = "vote|{$chatId}|{$type}|{$targetUserId}|{$targetMessageId}|yes";
                    $noCb  = "vote|{$chatId}|{$type}|{$targetUserId}|{$targetMessageId}|no";
                    $keyboard = new InlineKeyboard([
                        ['text' => '✅ Yes', 'callback_data' => $yesCb],
                        ['text' => '❌ No',  'callback_data' => $noCb],
                    ]);
                    $mention = '[user](tg://user?id=' . $targetUserId . ')';
                    $text = "📣 Vote to " . ($type==='ban' ? 'ban' : 'mute') . " {$mention}.\n".
                            "Yes: {$yesCount} | No: {$noCount} | Needed YES: {$threshold}\n".
                            "Time left: {$left}s";
                    Request::editMessageText([
                        'chat_id' => (int)$chatId,
                        'message_id' => (int)$announceId,
                        'text' => $text,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => $keyboard,
                    ]);
                }
            }
        }
    }

    private function processMutes(int $now): void
    {
        $muteService = $this->bot->getMuteService();
        $logger = $this->bot->getLoggerService();
        $all = $muteService->getAll();
        $settings = $this->bot->getSettingsService();
        foreach ($all as $chatId => $users) {
            foreach ($users as $userId => $entry) {
                $until = (int)($entry['until'] ?? 0);
                if ($until > 0 && $now >= $until) {
                    try {
                        // Lift restrictions by allowing common permissions
                        $res = Request::restrictChatMember([
                            'chat_id' => (int)$chatId,
                            'user_id' => (int)$userId,
                            'permissions' => [
                                'can_send_messages' => true,
                                'can_send_audios' => false,
                                'can_send_documents' => false,
                                'can_send_photos' => true,
                                'can_send_videos' => true,
                                'can_send_video_notes' => true,
                                'can_send_voice_notes' => true,
                                'can_send_polls' => false,
                                'can_send_other_messages' => true,
                                'can_add_web_page_previews' => false,
                                'can_change_info' => false,
                                'can_invite_users' => true,
                                'can_pin_messages' => false,
                            ],
                        ]);
                        if (!$res->isOk()) {
                            $logger->logError('Periodic: failed to unmute user: ' . $res->getDescription(), 'Periodic');
                        } else {
                            // Notify chat about unmute
                            $params = [
                                'chat_id' => (int)$chatId,
                                'text' => '🔈 User has been unmuted: [user](tg://user?id=' . (int)$userId . ')',
                                'parse_mode' => 'Markdown',
                            ];
                            $threadId = $settings->getSetting((int)$chatId, 'message_thread_id', null);
                            if ($threadId !== null) {
                                $params['message_thread_id'] = (int)$threadId;
                            }
                            Request::sendMessage($params);

                            // Remove mute record
                            $muteService->removeMute((int)$chatId, (int)$userId);
                        }
                    } catch (\Throwable $e) {
                        $logger->logError('Periodic: error unmuting user: ' . $e->getMessage(), 'Periodic', $e);
                    }
                }
            }
        }
    }

    private function processNewUserRestrictions(int $now): void
    {
        $service = $this->bot->getNewUserRestrictionService();

        // Process scheduled message deletions
        $service->processScheduledDeletions();

        // Clean up old user records (run less frequently)
        static $lastCleanup = 0;
        if ($now - $lastCleanup > 300) { // Every 5 minutes
            $service->cleanupOldUsers();
            $lastCleanup = $now;
        }
    }
}
