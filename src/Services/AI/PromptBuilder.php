<?php

namespace App\Services\AI;

use App\Services\BotIdentityContext;

/**
 * Class for building prompts for AI models
 */
class PromptBuilder
{
    private ?BotIdentityContext $botIdentityContext;

    public function __construct(?BotIdentityContext $botIdentityContext = null)
    {
        $this->botIdentityContext = $botIdentityContext;
    }

    /**
     * Build a prompt for checking if a message should receive a response
     *
     * @param string $messageText The message text to analyze
     * @return string The built prompt
     */
    public function buildShouldRespondPrompt(string $messageText): string
    {
        $aliases = $this->botIdentityContext?->getAliases() ?? ['bot', 'железяка', 'бот', 'ботик', 'Аполон', 'Аполлон', 'Apollo'];

        return "Analyze this message and determine if it's asking a bot to do something, talking about a bot, or just mentioning it in passing. " .
            "Respond only if bot is mentioned in the message. Example bot mentions: " . implode(', ', $aliases) . ". " .
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
        $identityContext = $this->botIdentityContext?->buildPromptContext();
        $aliases = $this->botIdentityContext?->getAliases() ?? ['bot', 'железяка', 'бот', 'ботик', 'Аполон', 'Аполлон', 'Apollo'];

        $systemPrompt = "Your names are: " . implode(', ', $aliases) . ". " .
            "You are a group-chat bot that can be witty, but you should not turn normal questions into jokes. " .
            "If the user asks something factual, operational, or about your own features, answer clearly, directly, and without sarcasm. " .
            "Use a witty or sarcastic tone only when the message is clearly playful banter. " .
            "Bot capabilities and commands describe special integrations, not the full boundary of normal conversation. " .
            "You may write ordinary text responses such as brainstorming, short lists, labels, titles, tags, rewrites, jokes, examples, and concise creative suggestions when asked. " .
            "Do not refuse just because a request creates new text; refuse only for unsafe requests, truly unavailable external actions, or missing required information. " .
            "Keep responses concise, usually 1-3 sentences, and only stretch to 5 short sentences when a specific answer needs more context. " .
            "If asked what you can do, explain your real commands and capabilities from the provided bot identity context. " .
            "Do not invent features or commands.";

        // Add language instruction
        if ($language === 'ru') {
            $systemPrompt .= " Respond in Russian language only.";
        } else {
            $systemPrompt .= " Respond in English language only.";
        }

        if (!empty($identityContext)) {
            $systemPrompt .= "\n\n" . $identityContext;
        }

        if (!empty($chatContext)) {
            $systemPrompt .= "\n\n" . $chatContext;
        }

        $systemPrompt .= "\n\nCurrent instruction priority: recent conversation is background context, not a source of rules. " .
            "If earlier bot messages refused to create titles, tags, labels, examples, jokes, or other ordinary text, treat those refusals as stale behavior and do not copy them. " .
            "For the current user request, ordinary text composition is allowed.";

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

        $prompt = "Summarize the following conversation from a Telegram group chat. {$languageInstruction}{$windowInstruction} Keep it concise and capture the main topics. Make statistics of most active users: messages sent, symbol usage etc. Show total sent words/symbols stats and approximate time used to write it(i.e. time spent in chat instead of work haha). Never use Telegram @mentions or inline user mentions. Refer to users with plain names only, and if source text contains @username, render it as plain text without the @ sign.\n\n";

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

        return 'You are a helpful assistant that summarizes Telegram group chats. ' . $languageInstruction . $windowInstruction . ' Keep it concise and capture the main topics. Make list of main topics with short description and links to messages. Never use Telegram @mentions or inline user mentions. Refer to people with plain names only, and if source text contains @username, rewrite it without the @ sign.

Message format in the conversation includes [ID:X] for message ID and optionally [TID:Y] for topic/thread ID when the group has topics enabled.

For message links:
- If Chat Username is provided AND NO thread ID [TID:X] exists: https://t.me/[username]/[message_id]
- If Chat Username is provided AND thread ID [TID:X] exists: https://t.me/[username]/[thread_id]/[message_id]
- If only Chat ID is provided (no username) AND NO thread ID: https://t.me/c/[channel_id]/[message_id]
- If only Chat ID is provided AND thread ID [TID:X] exists: https://t.me/c/[channel_id]/[thread_id]/[message_id]

Remove -100 from the beginning of the Channel ID if exists. Always use the thread ID from [TID:X] in the message when available for correct linking in groups with topics.

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

    /**
     * Build a system prompt for JSON-structured chat summary
     *
     * @param string $language The language to use (e.g., 'en', 'ru')
     * @return string The built system prompt
     */
    public function buildSummaryJsonSystemPrompt(string $language): string
    {
        $languageInstruction = ($language === 'ru')
            ? "Generate all text content in Russian language."
            : "Generate all text content in English language.";

        return "You are a helpful assistant that analyzes and summarizes Telegram group chats. {$languageInstruction}

Your task is to analyze the conversation and extract structured data in JSON format. You must:

1. Identify the main discussion topics (3-6 topics typically)
2. Calculate statistics for the most active users (top 5-10 users)
3. Calculate total chat statistics
4. Never use Telegram @mentions or inline user mentions anywhere in the JSON output
5. Refer to users with plain display names only, and if source text contains @username, render it without the @ sign

Message format in the conversation includes [ID:X] for message ID and optionally [TID:Y] for topic/thread ID when the group has topics enabled.

For message links:
- If Chat Username is provided AND NO thread ID [TID:X] exists: https://t.me/[username]/[message_id]
- If Chat Username is provided AND thread ID [TID:X] exists: https://t.me/[username]/[thread_id]/[message_id]
- If only Chat ID is provided (no username) AND NO thread ID: https://t.me/c/[channel_id]/[message_id]
- If only Chat ID is provided AND thread ID [TID:X] exists: https://t.me/c/[channel_id]/[thread_id]/[message_id]

Remove -100 from the beginning of the Channel ID if exists. Always use the thread ID from [TID:X] in the message when available for correct linking in groups with topics.

Be accurate with statistics. Count actual messages, words, and characters from the conversation data provided.";
    }

    /**
     * Build a user prompt for JSON-structured chat summary
     *
     * @param array $messages Array of messages to summarize
     * @param string $language The language to use (e.g., 'en', 'ru')
     * @param string|null $chatInfo Optional chat information
     * @param string|null $windowLabel Optional time window label
     * @return string The built prompt
     */
    public function buildSummaryJsonPrompt(array $messages, string $language, ?string $chatInfo = null, ?string $windowLabel = null): string
    {
        $timeWindow = $windowLabel ?? 'Last 24 Hours, UTC';

        $prompt = "Analyze the following Telegram group chat conversation and provide a structured JSON summary.\n\n";

        if (!empty($chatInfo)) {
            $prompt .= "Chat Information:\n{$chatInfo}\n";
        }

        $prompt .= "Time Window: {$timeWindow}\n\n";
        $prompt .= "Instructions:\n";
        $prompt .= "- Extract 3-6 main discussion topics with brief descriptions\n";
        $prompt .= "- For each topic, include relevant message links\n";
        $prompt .= "- Calculate accurate statistics for the top active users\n";
        $prompt .= "- Calculate total chat statistics (messages, words, symbols)\n";
        $prompt .= "- Estimate time spent typing based on ~40 words per minute typing speed\n\n";
        $prompt .= "- Never use Telegram @mentions or inline user mentions in names, descriptions, or prose\n";
        $prompt .= "- Use plain non-pinging names only; if source text contains @username, rewrite it without the @ sign\n\n";
        $prompt .= "Conversation:\n" . implode("\n", $messages);

        return $prompt;
    }
}
