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
        if (!is_dir($this->settingsPath)) {
            mkdir($this->settingsPath, 0777, true);
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
                return is_bool($value) || (is_string($value) && in_array(strtolower($value), ['true', 'false', '1', '0']));
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
                if (is_string($value)) {
                    return in_array(strtolower($value), ['true', '1']);
                }
                return (bool)$value;
            default:
                return $value;
        }
    }
}
