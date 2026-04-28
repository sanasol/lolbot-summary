<?php

namespace App\Services\AI;

/**
 * Removes model scratchpad/transcript leakage from MCP responses.
 *
 * Some reasoning-capable models occasionally append internal monologue,
 * repeated END/STOP loops, or duplicate "final answer" blocks after a valid
 * user-facing summary. This sanitizer keeps the first clean answer and trims
 * the obvious garbage tail.
 */
class McpResponseSanitizer
{
    /**
     * Markers that indicate the model switched from user-facing output into
     * transcript / scratchpad / replay mode.
     */
    private const HARD_CUT_MARKERS = [
        "\nAssistant:",
        "\nFrom the last function:",
        "\nMy role is",
        "\nThe last human message has timestamp",
        "\nThe new request is for",
        "\nThe prompt ends here",
        "\nThe loop is endless",
        "\nTo avoid replication, stop here",
        "\nHistory ignored",
        "\nThe system is shutting down",
        "\nThe system shuts down",
        "\nNo further requests",
        "\nFinal Answer",
        "\n**Final Answer**",
        "\n\\boxed{",
        "\nRaw function",
        "\nTool response:",
    ];

    public function sanitize(string $content): string
    {
        $content = $this->normalize($content);
        if ($content === '') {
            return '';
        }

        $content = preg_replace('/<\|[^>\n]+\|>/u', '', $content) ?? $content;
        $content = preg_replace('/\\\\boxed\s*\{\s*\}/u', '', $content) ?? $content;

        $content = $this->cutAtMarkers($content);
        $content = $this->cutAtDuplicateSummary($content);
        $content = $this->trimNoiseTail($content);

        return $this->normalize($content);
    }

    public function looksCorrupted(string $content): bool
    {
        $content = $this->normalize($content);
        if ($content === '') {
            return false;
        }

        if (preg_match('/<\|[^>\n]+\|>/u', $content) === 1) {
            return true;
        }

        if (substr_count($content, '### Summary') > 1) {
            return true;
        }

        $markers = [
            'Assistant: First, the user message is',
            'From the last function:',
            'The system shuts down',
            'The system is shutting down',
            'The prompt ends here',
            'The loop is endless',
            'History ignored',
        ];

        foreach ($markers as $marker) {
            if (stripos($content, $marker) !== false) {
                return true;
            }
        }

        $noiseLines = 0;
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            if ($this->isNoiseLine($line)) {
                $noiseLines++;
            }
        }

        return $noiseLines >= 8;
    }

    private function normalize(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace("/\n{3,}/u", "\n\n", $content) ?? $content;

        return trim($content);
    }

    private function cutAtMarkers(string $content): string
    {
        $cutAt = null;

        foreach (self::HARD_CUT_MARKERS as $marker) {
            $pos = mb_stripos($content, trim($marker));
            if ($pos === false || $pos < 80) {
                continue;
            }

            if ($cutAt === null || $pos < $cutAt) {
                $cutAt = $pos;
            }
        }

        $finalAnswerPos = mb_stripos($content, "\nFinal Answer");
        if ($finalAnswerPos !== false && $finalAnswerPos > 200) {
            $cutAt = $cutAt === null ? $finalAnswerPos : min($cutAt, $finalAnswerPos);
        }

        if ($cutAt !== null) {
            $content = mb_substr($content, 0, $cutAt);
        }

        return $content;
    }

    private function cutAtDuplicateSummary(string $content): string
    {
        if (preg_match_all('/^###\s+Summary\b.*$/mi', $content, $matches, PREG_OFFSET_CAPTURE) >= 2) {
            $second = $matches[0][1][1] ?? null;
            if (is_int($second) && $second > 100) {
                return mb_substr($content, 0, $second);
            }
        }

        return $content;
    }

    private function trimNoiseTail(string $content): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            if (!$this->isNoiseLine($lines[$i])) {
                continue;
            }

            $window = array_slice($lines, $i, min(8, $lineCount - $i));
            $noiseCount = 0;
            foreach ($window as $line) {
                if ($this->isNoiseLine($line)) {
                    $noiseCount++;
                }
            }

            if ($noiseCount >= max(4, count($window) - 1)) {
                $lines = array_slice($lines, 0, $i);
                break;
            }
        }

        return implode("\n", $lines);
    }

    private function isNoiseLine(string $line): bool
    {
        $line = trim(strip_tags($line));
        if ($line === '') {
            return false;
        }

        $normalized = trim($line, "*`_ \t\n\r\0\x0B");

        if (preg_match('/^(END|STOP|COMPLETE|FINAL|FINAL BOX|FINAL BOXED ANSWER|FINAL ANSWER|THE END|DONE|FIN|YES\.?)$/iu', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(The )?(conversation|system|simulation).*(concluded|ends|ended|shuts down|shutdown)/iu', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(The box contains|The boxed summary|The boxed text|The output is the boxed)/iu', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(No more processing|This is it|The response is ready)\.?$/iu', $normalized) === 1) {
            return true;
        }

        return false;
    }
}
