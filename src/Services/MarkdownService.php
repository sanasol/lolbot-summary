<?php

namespace App\Services;

use WPSocio\TelegramFormatText\HtmlConverter;

/**
 * Service for handling Markdown conversions for Telegram
 */
class MarkdownService
{
    /**
     * Convert HTML to Telegram's MarkdownV2 format
     *
     * @param string $html The HTML text to convert
     * @return string The text formatted for Telegram's MarkdownV2 parse mode
     */
    public function htmlToTelegramMarkdown(string $html): string
    {
        // Use the HTML converter to get MarkdownV2 format
        $options = [
            'format_to' => 'MarkdownV2',
        ];
        $converter = new HtmlConverter($options);

        // Convert HTML to MarkdownV2
        $telegramMarkdown = $converter->convert($html);

        // The converter should handle the escaping of special characters
        return $telegramMarkdown;
    }
}
