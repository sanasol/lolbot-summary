<?php

namespace App\Services;

/**
 * Service for managing group-specific settings
 */
class SettingsService
{
    private string $settingsPath;
    private array $settings = [];
    private array $defaultSettings = [
        'language' => 'en',  // Default language for summaries and bot responses
        'summary_enabled' => true,  // Whether summaries are enabled for this group
        'bot_mentions_enabled' => true,  // Whether bot should respond to mentions
        'summary_hour_utc' => 8, // Default summary sending hour in UTC (0-23)
        'message_thread_id' => null, // Topic/thread ID in supergroup to restrict bot replies
        // Community moderation via voting
        'vote_moderation_enabled' => true, // Enable /voteban and /votemute feature
        'vote_threshold_ban' => 5, // Number of YES votes needed to ban
        'vote_threshold_mute' => 3, // Number of YES votes needed to mute
        'vote_duration_sec' => 300, // Vote expires in seconds (default 10 minutes)
        'vote_mute_duration_sec' => 3600, // Mute duration in seconds (default 1 hour)
        // New user restriction
        'new_user_restriction_enabled' => false, // Require new users to solve captcha or wait 10 minutes
    ];

    /**
     * Constructor
     *
     * @param string $settingsPath Path to store settings files
     */
    public function __construct(string $settingsPath)
    {
        $this->settingsPath = $settingsPath;

        // Create settings directory if it doesn't exist
        if (!is_dir($this->settingsPath) && !mkdir($concurrentDirectory = $this->settingsPath, 0777, true) && !is_dir(
                $concurrentDirectory
            )) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
    }

    /**
     * Get settings for a specific chat
     *
     * @param int $chatId The chat ID
     * @return array The settings for the chat
     */
    public function getSettings(int $chatId): array
    {
        // Return from cache if available
        if (isset($this->settings[$chatId])) {
            return $this->settings[$chatId];
        }

        // Load settings from file
        $settings = $this->loadSettingsFromFile($chatId);

        // Cache settings
        $this->settings[$chatId] = $settings;

        return $settings;
    }

    /**
     * Update a specific setting for a chat
     *
     * @param int $chatId The chat ID
     * @param string $key The setting key
     * @param mixed $value The setting value
     * @return bool Whether the update was successful
     */
    public function updateSetting(int $chatId, string $key, $value): bool
    {
        // Get current settings
        $settings = $this->getSettings($chatId);

        // Update the setting
        $settings[$key] = $value;

        // Save settings
        $this->settings[$chatId] = $settings;
        return $this->saveSettingsToFile($chatId, $settings);
    }

    /**
     * Update multiple settings for a chat
     *
     * @param int $chatId The chat ID
     * @param array $newSettings The new settings
     * @return bool Whether the update was successful
     */
    public function updateSettings(int $chatId, array $newSettings): bool
    {
        // Get current settings
        $settings = $this->getSettings($chatId);

        // Update settings
        foreach ($newSettings as $key => $value) {
            $settings[$key] = $value;
        }

        // Save settings
        $this->settings[$chatId] = $settings;
        return $this->saveSettingsToFile($chatId, $settings);
    }

    /**
     * Get a specific setting for a chat
     *
     * @param int $chatId The chat ID
     * @param string $key The setting key
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed The setting value
     */
    public function getSetting(int $chatId, string $key, $default = null)
    {
        $settings = $this->getSettings($chatId);
        return $settings[$key] ?? $default;
    }

    /**
     * Load settings from file
     *
     * @param int $chatId The chat ID
     * @return array The settings
     */
    private function loadSettingsFromFile(int $chatId): array
    {
        $filePath = $this->getSettingsFilePath($chatId);

        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $settings = json_decode($content, true);

            if (is_array($settings)) {
                // Merge with default settings to ensure all keys exist
                return array_merge($this->defaultSettings, $settings);
            }
        }

        // Return default settings if file doesn't exist or is invalid
        return $this->defaultSettings;
    }

    /**
     * Save settings to file
     *
     * @param int $chatId The chat ID
     * @param array $settings The settings to save
     * @return bool Whether the save was successful
     */
    private function saveSettingsToFile(int $chatId, array $settings): bool
    {
        $filePath = $this->getSettingsFilePath($chatId);
        $content = json_encode($settings, JSON_PRETTY_PRINT);

        return file_put_contents($filePath, $content) !== false;
    }

    /**
     * Get the path to the settings file for a chat
     *
     * @param int $chatId The chat ID
     * @return string The file path
     */
    private function getSettingsFilePath(int $chatId): string
    {
        return $this->settingsPath . '/' . $chatId . '_settings.json';
    }

    /**
     * Get available languages
     *
     * @return array List of available languages
     */
    public function getAvailableLanguages(): array
    {
        return [
            'en' => 'English',
            'ru' => 'Russian',
        ];
    }

    /**
     * Check if a setting is valid
     *
     * @param string $key The setting key
     * @param mixed $value The setting value
     * @return bool Whether the setting is valid
     */
    public function isValidSetting(string $key, $value): bool
    {
        switch ($key) {
            case 'language':
                return in_array($value, array_keys($this->getAvailableLanguages()));
            case 'summary_enabled':
            case 'bot_mentions_enabled':
            case 'bot_mentions_react_when_no_reply':
            case 'bot_mentions_reaction_allow_ai_emoji':
            case 'bot_mentions_reaction_allow_big':
            case 'vote_moderation_enabled':
            case 'new_user_restriction_enabled':
                return is_bool($value) || (is_string($value) && in_array(strtolower($value), ['true', 'false', '1', '0']));
            case 'summary_hour_utc':
                if (is_numeric($value)) {
                    $int = (int)$value;
                    return $int >= 0 && $int <= 23;
                }
                if (is_string($value)) {
                    // allow formats like "08" or "8"
                    if (ctype_digit($value)) {
                        $int = (int)$value;
                        return $int >= 0 && $int <= 23;
                    }
                }
                return false;
            case 'message_thread_id':
                if ($value === null) return true;
                if (is_numeric($value)) return true;
                if (is_string($value)) {
                    $v = strtolower(trim($value));
                    if ($v === 'null' || $v === 'clear' || $v === 'none') return true;
                    if (ctype_digit($value)) return true;
                }
                return false;
            case 'bot_mentions_reaction_min_confidence':
                if (is_numeric($value)) {
                    $int = (int)$value;
                    return $int >= 0 && $int <= 100;
                }
                if (is_string($value) && ctype_digit($value)) {
                    $int = (int)$value;
                    return $int >= 0 && $int <= 100;
                }
                return false;
            case 'vote_threshold_ban':
            case 'vote_threshold_mute':
                if (is_numeric($value)) {
                    $int = (int)$value;
                    return $int >= 1 && $int <= 100;
                }
                if (is_string($value) && ctype_digit($value)) {
                    $int = (int)$value;
                    return $int >= 1 && $int <= 100;
                }
                return false;
            case 'vote_duration_sec':
                if (is_numeric($value)) {
                    $int = (int)$value;
                    return $int >= 30 && $int <= 604800; // 30s to 7 days
                }
                if (is_string($value) && ctype_digit($value)) {
                    $int = (int)$value;
                    return $int >= 30 && $int <= 604800;
                }
                return false;
            case 'vote_mute_duration_sec':
                if (is_numeric($value)) {
                    $int = (int)$value;
                    return $int >= 60 && $int <= 2592000; // 1 minute to 30 days
                }
                if (is_string($value) && ctype_digit($value)) {
                    $int = (int)$value;
                    return $int >= 60 && $int <= 2592000;
                }
                return false;
            default:
                return false;
        }
    }

    /**
     * Format setting value for storage
     *
     * @param string $key The setting key
     * @param mixed $value The setting value
     * @return mixed The formatted value
     */
    public function formatSettingValue(string $key, $value)
    {
        switch ($key) {
            case 'summary_enabled':
            case 'bot_mentions_enabled':
            case 'bot_mentions_react_when_no_reply':
            case 'bot_mentions_reaction_allow_ai_emoji':
            case 'bot_mentions_reaction_allow_big':
            case 'vote_moderation_enabled':
            case 'new_user_restriction_enabled':
                if (is_string($value)) {
                    return in_array(strtolower($value), ['true', '1', 'on']);
                }
                return (bool)$value;
            case 'summary_hour_utc':
                $int = (int)$value;
                if ($int < 0) $int = 0;
                if ($int > 23) $int = 23;
                return $int;
            case 'message_thread_id':
                if ($value === null) return null;
                if (is_string($value)) {
                    $v = strtolower(trim($value));
                    if (in_array($v, ['null','clear','none'])) return null;
                    if (ctype_digit($value)) return (int)$value;
                }
                if (is_numeric($value)) return (int)$value;
                return null;
            case 'bot_mentions_reaction_min_confidence':
                $int = (int)$value;
                if ($int < 0) $int = 0;
                if ($int > 100) $int = 100;
                return $int;
            case 'vote_threshold_ban':
            case 'vote_threshold_mute':
                $int = (int)$value;
                if ($int < 1) $int = 1;
                if ($int > 100) $int = 100;
                return $int;
            case 'vote_duration_sec':
                $int = (int)$value;
                if ($int < 30) $int = 30;
                if ($int > 604800) $int = 604800; // 7 days
                return $int;
            case 'vote_mute_duration_sec':
                $int = (int)$value;
                if ($int < 60) $int = 60;
                if ($int > 2592000) $int = 2592000; // 30 days
                return $int;
            default:
                return $value;
        }
    }
}
