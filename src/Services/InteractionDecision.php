<?php

namespace App\Services;

/**
 * Structured interaction routing result for non-command conversational messages.
 */
class InteractionDecision
{
    public const ROUTE_IGNORE = 'ignore';
    public const ROUTE_CHAT = 'chat';
    public const ROUTE_MCP = 'mcp';
    public const ROUTE_AGENT = 'agent';

    public const TONE_AUTO = 'auto';
    public const TONE_NEUTRAL = 'neutral';
    public const TONE_WITTY = 'witty';

    public const IMAGE_INTENT_NONE = 'none';
    public const IMAGE_INTENT_ANALYZE_ONLY = 'analyze_only';
    public const IMAGE_INTENT_GENERATE_OR_EDIT = 'generate_or_edit';

    public string $route;
    public string $tone;
    public string $intent;
    public int $confidence;
    public bool $addressedToBot;
    public int $analyticsConfidence;
    public string $imageIntent;
    public string $cleanedPrompt;
    public string $reason;

    public function __construct(
        string $route,
        string $tone,
        string $intent,
        int $confidence,
        bool $addressedToBot,
        int $analyticsConfidence,
        string $imageIntent,
        string $cleanedPrompt,
        string $reason = ''
    ) {
        $this->route = $route;
        $this->tone = $tone;
        $this->intent = $intent;
        $this->confidence = max(0, min(100, $confidence));
        $this->addressedToBot = $addressedToBot;
        $this->analyticsConfidence = max(0, min(100, $analyticsConfidence));
        $this->imageIntent = $imageIntent;
        $this->cleanedPrompt = $cleanedPrompt;
        $this->reason = $reason;
    }

    public function shouldSuggestMcp(): bool
    {
        return $this->addressedToBot
            && $this->route === self::ROUTE_CHAT
            && $this->analyticsConfidence >= 50
            && $this->analyticsConfidence < 75;
    }

    public function toArray(): array
    {
        return [
            'route' => $this->route,
            'tone' => $this->tone,
            'intent' => $this->intent,
            'confidence' => $this->confidence,
            'addressed_to_bot' => $this->addressedToBot,
            'analytics_confidence' => $this->analyticsConfidence,
            'image_intent' => $this->imageIntent,
            'cleaned_prompt' => $this->cleanedPrompt,
            'reason' => $this->reason,
            'suggest_mcp' => $this->shouldSuggestMcp(),
        ];
    }
}
