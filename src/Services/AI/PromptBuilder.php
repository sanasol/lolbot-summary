<?php

namespace App\Services\AI;

/**
 * Class for building prompts for AI models
 */
class PromptBuilder
{
    /**
     * Build a prompt for checking if a message should receive a response
     *
     * @param string $messageText The message text to analyze
     * @return string The built prompt
     */
    public function buildShouldRespondPrompt(string $messageText): string
    {
        return "Analyze this message and determine if it's asking a bot to do something, talking about a bot, or just mentioning it in passing. " .
            "Respond only if bot is mentioned in the message. Example bot mentions: bot, железяка, бот, ботик, Аполон, Аполлон. " .
            "Provide a confidence score from 0 to 100 indicating how likely the message needs a response. " .
            "Higher score means the message more likely needs a response.\n\nMessage: \"" . $messageText . "\"";
    }

    /**
     * Build a system prompt for generating a mention response
     *
     * @param string $language The language to use (e.g., 'en', 'ru')
     * @param string $chatContext Optional context from recent chat messages
     * @return string The built system prompt
     */
    public function buildMentionSystemPrompt(string $language, string $chatContext = ''): string
    {
        $systemPrompt = "Your names are: bot, железяка, бот, ботик, Аполон, Аполлон, Apollo. You are a witty, sarcastic bot that responds to mentions with funny memes, jokes, or clever comebacks. " .
            "Keep your response short (1-2 sentences max), funny, and appropriate for a group chat. Don't use quotes, answer from the perspective of the bot but act as the person. " .
            "Response with medium length response up to 5 sentences if message is asking something specific. " .
            "Use emojis if you feel it's needed.";

        // Add language instruction
        if ($language === 'ru') {
            $systemPrompt .= " Respond in Russian language only.";
        } else {
            $systemPrompt .= " Respond in English language only.";
        }

        if (!empty($chatContext)) {
            $systemPrompt .= "\n\n" . $chatContext;
        }

        return $systemPrompt;
    }

    /**
     * Build a user prompt for generating a mention response
     *
     * @param string $messageText The message text to respond to
     * @param string $username The username of the message sender
     * @return string The built user prompt
     */
    public function buildMentionUserPrompt(string $messageText, string $username): string
    {
        return "Respond to this message: \"" . $messageText . "\" from user " . $username;
    }

    /**
     * Build a prompt for image generation
     *
     * @param string $messageText The message text to use as a prompt
     * @param string|null $inputImageUrl URL of an image sent by the user (if any)
     * @return string The built prompt
     */
    public function buildImageGenerationPrompt(string $messageText, ?string $inputImageUrl = null): string
    {
        if ($inputImageUrl) {
            return "Create a detailed image generation prompt based on this image and my request: \"" . $messageText . "\"";
        } else {
            return "Create a detailed image generation prompt based on this request: \"" . $messageText . "\"";
        }
    }

    /**
     * Build a prompt for image description
     *
     * @param string|null $caption Optional caption for the image
     * @return string The built prompt
     */
    public function buildImageDescriptionPrompt(?string $caption = ''): string
    {
        return "Describe this image in detail but concisely. Image caption: \"$caption\".";
    }

    /**
     * Build a prompt for chat summary
     *
     * @param array $messages Array of messages to summarize
     * @param string $language The language to use (e.g., 'en', 'ru')
     * @param string|null $chatInfo Optional chat information
     * @return string The built prompt
     */
    public function buildSummaryPrompt(array $messages, string $language, ?string $chatInfo = null, ?string $windowLabel = null): string
    {
        $languageInstruction = ($language === 'ru')
            ? "Generate the summary in Russian language."
            : "Generate the summary in English language.";

        $windowInstruction = '';
        if ($windowLabel !== null && $windowLabel !== '') {
            $windowInstruction = " The time window (UTC) for this summary is: {$windowLabel}. Only include and analyze content from this period.";
        }

        $prompt = "Summarize the following conversation from a Telegram group chat. {$languageInstruction}{$windowInstruction} Keep it concise and capture the main topics. Make statistics of most active users: messages sent, symbol usage etc. Show total sent words/symbols stats and approximate time used to write it(i.e. time spent in chat instead of work haha)\n\n";

        if (!empty($chatInfo)) {
            $prompt .= "Chat Information:\n$chatInfo\n";
        }

        $prompt .= "Conversation:\n" . implode("\n", $messages);

        return $prompt;
    }

    /**
     * Build a system prompt for chat summary
     *
     * @param string $language The language to use (e.g., 'en', 'ru')
     * @return string The built system prompt
     */
    public function buildSummarySystemPrompt(string $language, ?string $windowLabel = null): string
    {
        $languageInstruction = ($language === 'ru')
            ? "Generate the summary in Russian language."
            : "Generate the summary in English language.";

        $windowInstruction = '';
        if ($windowLabel !== null && $windowLabel !== '') {
            $windowInstruction = " The time window (UTC) for this summary is: {$windowLabel}. Only include and analyze content from this period.";
        }

        return 'You are a helpful assistant that summarizes Telegram group chats. ' . $languageInstruction . $windowInstruction . ' Keep it concise and capture the main topics. Make list of main topics with short description and links to messages

If Chat Username is provided, create links to messages using the format: https://t.me/[username]/[message_id] where [username] is the Chat Username without @ and [message_id] is a message ID you can reference from the conversation.

If only Chat ID is provided (no username), create link using the format: https://t.me/c/[channel_id]/[message_id] where [channel_id] is a channel ID you can reference from the conversation. Remove -100 from the beginning of the Channel ID if exists.

When formatting your responses for Telegram, please use these special formatting conventions for HTML:
use only this list of tags, dont use any other html tags
!!dont use telegram markdown!!
!!dont use telegram markdownv2!!
use HTML for telegram
<b>bold</b>, <strong>bold</strong>
<i>italic</i>, <em>italic</em>
<u>underline</u>, <ins>underline</ins>
<s>strikethrough</s>, <strike>strikethrough</strike>, <del>strikethrough</del>
<span class="tg-spoiler">spoiler</span>, <tg-spoiler>spoiler</tg-spoiler>
<b>bold <i>italic bold <s>italic bold strikethrough <span class="tg-spoiler">italic bold strikethrough spoiler</span></s> <u>underline italic bold</u></i> bold</b>
<a href="http://www.example.com/">inline URL</a>
<a href="tg://user?id=123456789">inline mention of a user</a>
<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>
<code>inline fixed-width code</code>
<pre>pre-formatted fixed-width code block</pre>
<pre><code class="language-python">pre-formatted fixed-width code block written in the Python programming language</code></pre>
<blockquote>Block quotation started\nBlock quotation continued\nThe last line of the block quotation</blockquote>
<blockquote expandable>Expandable block quotation started\nExpandable block quotation continued\nExpandable block quotation continued\nHidden by default part of the block quotation started\nExpandable block quotation continued\nThe last line of the block quotation</blockquote>';
    }
}
