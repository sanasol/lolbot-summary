<?php

namespace App\Services;

/**
 * Centralized logging service for the bot
 */
class LoggerService
{
    private string $logPath;

    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
    }

    /**
     * Log a message to a specific log file
     *
     * @param string $message The message to log
     * @param string $category The log category (used in the prefix)
     * @param string $logType The log file type (webhook, error, etc.)
     * @param bool $alsoLogToErrorLog Whether to also log to PHP's error_log
     */
    public function log(string $message, string $category = 'General', string $logType = 'webhook', bool $alsoLogToErrorLog = false): void
    {
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] [{$category}] ";
        $logFile = $this->logPath . '/' . $logType . '_' . date('Y-m-d') . '.log';
        
        $fullMessage = $logPrefix . $message;
        file_put_contents($logFile, $fullMessage . PHP_EOL, FILE_APPEND);
        
        if ($alsoLogToErrorLog) {
            error_log($fullMessage);
        }
    }

    /**
     * Log an error message to both the error log and webhook log
     *
     * @param string $message The error message
     * @param string $category The error category
     * @param \Throwable|null $exception Optional exception to include stack trace
     */
    public function logError(string $message, string $category = 'Error', \Throwable $exception = null): void
    {
        $fullMessage = $message;
        
        if ($exception) {
            $fullMessage .= "\nException: " . $exception->getMessage();
            $fullMessage .= "\nStack Trace:\n" . $exception->getTraceAsString();
        }
        
        // Log to webhook log
        $this->log($fullMessage, $category, 'webhook', true);
        
        // Also log to error log file
        $this->log($fullMessage, $category, 'error', true);
    }

    /**
     * Log a webhook event
     *
     * @param string $message The message to log
     */
    public function logWebhook(string $message): void
    {
        $this->log($message, 'Webhook', 'webhook');
    }

    /**
     * Log a command event
     *
     * @param string $message The message to log
     * @param string $command The command name
     */
    public function logCommand(string $message, string $command): void
    {
        $this->log($message, "Command:{$command}", 'webhook');
    }

    /**
     * Log a bot mention event
     *
     * @param string $message The message to log
     */
    public function logBotMention(string $message): void
    {
        $this->log($message, 'Bot Mention', 'webhook');
    }

    /**
     * Log a settings change
     *
     * @param string $message The message to log
     */
    public function logSettings(string $message): void
    {
        $this->log($message, 'Settings', 'webhook');
    }
}