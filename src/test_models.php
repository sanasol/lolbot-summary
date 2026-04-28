#!/usr/bin/env php
<?php
/**
 * Model Benchmark Script for ClickhouseAgent
 *
 * Tests multiple AI models in PARALLEL on the same complex /mcp query to compare:
 * - Response time
 * - Cumulative token usage across ALL API calls (including tool call rounds)
 * - Tool calls count
 * - Actual cost calculated from cumulative tokens × model pricing
 * - Response quality (output for manual review)
 *
 * Usage:
 *   php src/test_models.php
 *   php src/test_models.php --models=moonshotai/kimi-k2.5,google/gemini-3-flash-preview
 *   php src/test_models.php --sequential
 */

// Suppress deprecation warnings (curl_close, setAccessible on PHP 8.5)
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../vendor/autoload.php';

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    die("Error: Configuration file not found at {$configPath}\n");
}
$config = require $configPath;

// ── Models to benchmark ──────────────────────────────────────────────
$models = [
    'moonshotai/kimi-k2.5',
    'minimax/minimax-m2.5',
    'z-ai/glm-5',
    'google/gemini-3-flash-preview',
    'anthropic/claude-sonnet-4.5',
    'anthropic/claude-opus-4.6',
    'arcee-ai/trinity-large-preview:free',
    'x-ai/grok-code-fast-1',
    'anthropic/claude-sonnet-4.6',
];

// ── Test query ───────────────────────────────────────────────────────
$testQuery = 'Find the top 25 all-time tippers of Chaturbate model princess_sofiee. '
    . 'For each tipper, find on what day they talked for the first time in her room, '
    . 'and on what day they first tipped her. Report the information in a table with columns: '
    . 'tipper name, date of first message, date of first tip';

// ── Parse CLI args ───────────────────────────────────────────────────
$opts = getopt('', ['models:', 'timeout:', 'query:', 'sequential']);
if (!empty($opts['models'])) {
    $models = array_map('trim', explode(',', $opts['models']));
}
if (!empty($opts['query'])) {
    $testQuery = $opts['query'];
}
$parallel = !isset($opts['sequential']);

// ── Results directory ────────────────────────────────────────────────
$resultsDir = __DIR__ . '/../data/benchmark_' . date('Y-m-d_His');
if (!mkdir($resultsDir, 0755, true) && !is_dir($resultsDir)) {
    die("Error: Could not create results directory\n");
}

// ── Fetch live pricing from OpenRouter API ───────────────────────────
echo "Fetching model pricing from OpenRouter API...\n";
$pricing = [];
try {
    $client = new GuzzleHttp\Client(['timeout' => 15]);
    $resp = $client->get('https://openrouter.ai/api/v1/models', [
        'headers' => [
            'Authorization' => 'Bearer ' . $config['openrouter_key'],
            'Accept' => 'application/json',
        ],
    ]);
    $modelsData = json_decode($resp->getBody()->getContents(), true);
    foreach ($modelsData['data'] ?? [] as $m) {
        $id = $m['id'] ?? '';
        // OpenRouter returns price per token, convert to per million
        $pricing[$id] = [
            'input'  => (float)($m['pricing']['prompt'] ?? 0) * 1_000_000,
            'output' => (float)($m['pricing']['completion'] ?? 0) * 1_000_000,
        ];
    }
    echo "  Loaded pricing for " . count($pricing) . " models\n";
    foreach ($models as $m) {
        if (isset($pricing[$m])) {
            printf("  %-45s \$%.4f/M in, \$%.4f/M out\n", $m, $pricing[$m]['input'], $pricing[$m]['output']);
        } else {
            echo "  {$m}: pricing not found\n";
        }
    }
} catch (Throwable $e) {
    echo "  Warning: Could not fetch pricing: " . $e->getMessage() . "\n";
}

// Save pricing for child processes
file_put_contents("{$resultsDir}/_pricing.json", json_encode($pricing));

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo "║           ClickhouseAgent Model Benchmark                   ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";
echo "Query:    " . substr($testQuery, 0, 80) . "...\n";
echo "Models:   " . count($models) . "\n";
echo "Mode:     " . ($parallel ? "PARALLEL" : "sequential") . "\n";
echo "Tokens:   Cumulative (all API calls including tool rounds)\n";
echo "Cost:     tokens × OpenRouter pricing (live)\n";
echo "Results:  {$resultsDir}\n";
echo str_repeat('─', 64) . "\n\n";

/**
 * Run a single model benchmark.
 * Token usage is CUMULATIVE across all API calls thanks to OpenRouterAi::getCumulativeUsage().
 */
function benchmarkModel(string $model, string $testQuery, array $config, array $pricing, string $resultsDir): array
{
    $testConfig = $config;
    $testConfig['openrouter_tool_model'] = $model;

    $result = [
        'model'          => $model,
        'status'         => 'error',
        'time_seconds'   => 0,
        'input_tokens'   => 0,
        'output_tokens'  => 0,
        'total_tokens'   => 0,
        'tool_calls'     => 0,
        'cost_usd'       => 0,
        'error'          => null,
        'content_length' => 0,
        'content_preview' => '',
        'pricing_input'  => $pricing[$model]['input'] ?? 0,
        'pricing_output' => $pricing[$model]['output'] ?? 0,
    ];

    $startTime = microtime(true);

    try {
        $userMessage = new NeuronAI\Chat\Messages\Message(
            NeuronAI\Chat\Enums\MessageRole::USER,
            $testQuery
        );

        $agent = App\Services\ClickhouseAgent::make($testConfig, true);
        $response = $agent->chat($userMessage);

        $elapsed = microtime(true) - $startTime;

        $content = $response->getContent();

        // Get CUMULATIVE token usage from the provider (all API calls summed)
        $usage = $response->getUsage();
        $inputTokens = $usage?->inputTokens ?? 0;
        $outputTokens = $usage?->outputTokens ?? 0;

        // Extract tool calls count (protected property, accessible in PHP 8.1+)
        $toolCalls = (new ReflectionProperty(App\Services\ClickhouseAgent::class, 'toolCalls'))->getValue($agent);

        // Calculate cost from cumulative tokens (accumulated by OpenRouterAi provider)
        $mp = $pricing[$model] ?? ['input' => 0, 'output' => 0];
        $cost = ($inputTokens / 1_000_000) * $mp['input']
              + ($outputTokens / 1_000_000) * $mp['output'];

        $result['status']         = !empty($content) ? 'success' : 'empty_response';
        $result['time_seconds']   = round($elapsed, 2);
        $result['input_tokens']   = $inputTokens;
        $result['output_tokens']  = $outputTokens;
        $result['total_tokens']   = $inputTokens + $outputTokens;
        $result['tool_calls']     = $toolCalls;
        $result['cost_usd']       = round($cost, 6);
        $result['content_length'] = strlen($content ?? '');
        $result['content_preview'] = substr($content ?? '', 0, 200);

        // Save full response
        $safe = str_replace(['/', ':'], '_', $model);
        file_put_contents("{$resultsDir}/{$safe}.txt", $content ?? '');

    } catch (Throwable $e) {
        $elapsed = microtime(true) - $startTime;
        $result['time_seconds'] = round($elapsed, 2);
        $result['error'] = substr($e->getMessage(), 0, 300);

        $safe = str_replace(['/', ':'], '_', $model);
        file_put_contents("{$resultsDir}/{$safe}_error.txt",
            get_class($e) . ": " . $e->getMessage() . "\n\n" . $e->getTraceAsString());
    }

    return $result;
}

// ── Execute benchmarks ───────────────────────────────────────────────
if ($parallel && function_exists('pcntl_fork')) {
    // ── PARALLEL MODE ────────────────────────────────────────────────
    echo "Launching " . count($models) . " models in parallel...\n\n";

    $children = [];
    foreach ($models as $model) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            // Fork failed, run in-process
            $result = benchmarkModel($model, $testQuery, $config, $pricing, $resultsDir);
            $safe = str_replace(['/', ':'], '_', $model);
            file_put_contents("{$resultsDir}/{$safe}_result.json", json_encode($result));
        } elseif ($pid === 0) {
            // ── Child process ────────────────────────────────────────
            $result = benchmarkModel($model, $testQuery, $config, $pricing, $resultsDir);
            $safe = str_replace(['/', ':'], '_', $model);
            file_put_contents("{$resultsDir}/{$safe}_result.json", json_encode($result));

            $icon = $result['status'] === 'success' ? '✓' : '✗';
            fwrite(STDERR, sprintf(
                "[%s] %-42s %6.1fs | %6din/%6dout | tools:%d | \$%.4f | %d chars%s\n",
                $icon, $model, $result['time_seconds'],
                $result['input_tokens'], $result['output_tokens'],
                $result['tool_calls'], $result['cost_usd'], $result['content_length'],
                $result['error'] ? ' | ERR: ' . substr($result['error'], 0, 60) : ''
            ));
            exit(0);
        } else {
            $children[$pid] = $model;
            echo "  Forked PID {$pid} for {$model}\n";
        }
    }

    echo "\nWaiting for all models to complete...\n\n";
    while (count($children) > 0) {
        $pid = pcntl_wait($status);
        if ($pid > 0 && isset($children[$pid])) {
            unset($children[$pid]);
            echo "  " . count($children) . " remaining...\n";
        }
    }

    // Collect results
    $results = [];
    foreach ($models as $model) {
        $safe = str_replace(['/', ':'], '_', $model);
        $path = "{$resultsDir}/{$safe}_result.json";
        if (file_exists($path)) {
            $results[] = json_decode(file_get_contents($path), true);
        } else {
            $results[] = [
                'model' => $model, 'status' => 'error', 'time_seconds' => 0,
                'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
                'tool_calls' => 0, 'cost_usd' => 0,
                'error' => 'No result file (process crashed)',
                'content_length' => 0, 'content_preview' => '',
                'pricing_input' => 0, 'pricing_output' => 0,
            ];
        }
    }
} else {
    // ── SEQUENTIAL MODE ──────────────────────────────────────────────
    if ($parallel) {
        echo "pcntl not available, running sequentially\n\n";
    }

    $results = [];
    foreach ($models as $index => $model) {
        $num = $index + 1;
        echo "[{$num}/" . count($models) . "] {$model} (started " . date('H:i:s') . ")\n";

        $result = benchmarkModel($model, $testQuery, $config, $pricing, $resultsDir);
        $safe = str_replace(['/', ':'], '_', $model);
        file_put_contents("{$resultsDir}/{$safe}_result.json", json_encode($result));

        $icon = $result['status'] === 'success' ? '✓' : '✗';
        printf(
            "  %s %6.1fs | %din/%dout | tools:%d | \$%.4f | %d chars\n",
            $icon, $result['time_seconds'],
            $result['input_tokens'], $result['output_tokens'],
            $result['tool_calls'], $result['cost_usd'], $result['content_length']
        );
        if ($result['error']) {
            echo "    Error: " . substr($result['error'], 0, 120) . "\n";
        }
        echo "\n";

        $results[] = $result;
    }
}

// ── Summary Table ────────────────────────────────────────────────────
echo "\n" . str_repeat('═', 140) . "\n";
echo "BENCHMARK RESULTS — " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('═', 155) . "\n";

$header = sprintf(
    "%-42s │ %-5s │ %7s │ %10s │ %10s │ %10s │ %5s │ %10s │ %6s",
    "Model", "OK?", "Time", "In Tokens", "Out Tokens", "Total Tok", "Tools", "Cost", "Chars"
);
echo $header . "\n";
echo str_repeat('─', 140) . "\n";

// Sort: successful first, then by time
usort($results, function ($a, $b) {
    if ($a['status'] === 'success' && $b['status'] !== 'success') return -1;
    if ($a['status'] !== 'success' && $b['status'] === 'success') return 1;
    return $a['time_seconds'] <=> $b['time_seconds'];
});

foreach ($results as $r) {
    $icon = match($r['status']) { 'success' => '✓', 'empty_response' => '○', default => '✗' };

    echo sprintf(
        "%-42s │ %-5s │ %6.1fs │ %10s │ %10s │ %10s │ %5d │ %10s │ %6s",
        substr($r['model'], 0, 42),
        $icon,
        $r['time_seconds'],
        number_format($r['input_tokens']),
        number_format($r['output_tokens']),
        number_format($r['total_tokens'] ?? ($r['input_tokens'] + $r['output_tokens'])),
        $r['tool_calls'],
        '$' . number_format($r['cost_usd'], 4),
        number_format($r['content_length'])
    ) . "\n";
}

echo str_repeat('─', 140) . "\n";

// ── Winners ──────────────────────────────────────────────────────────
$successful = array_filter($results, fn($r) => $r['status'] === 'success');
$totalCost = array_sum(array_column($results, 'cost_usd'));

if (!empty($successful)) {
    $avgTime = array_sum(array_column($successful, 'time_seconds')) / count($successful);
    $fastest = min(array_column($successful, 'time_seconds'));
    $fastestModel = '';
    foreach ($successful as $r) {
        if ($r['time_seconds'] == $fastest) { $fastestModel = $r['model']; break; }
    }

    $cheapestModel = '';
    $cheapestCost = PHP_FLOAT_MAX;
    foreach ($successful as $r) {
        if ($r['cost_usd'] < $cheapestCost) {
            $cheapestCost = $r['cost_usd'];
            $cheapestModel = $r['model'];
        }
    }

    // Best value: lowest (cost × time)
    $bestValueModel = '';
    $bestValueScore = PHP_FLOAT_MAX;
    foreach ($successful as $r) {
        $score = max($r['cost_usd'], 0.0001) * $r['time_seconds'];
        if ($score < $bestValueScore) {
            $bestValueScore = $score;
            $bestValueModel = $r['model'];
        }
    }

    echo "\n";
    echo "🏆 Fastest:     {$fastestModel} ({$fastest}s)\n";
    echo "💰 Cheapest:    {$cheapestModel} (\$" . number_format($cheapestCost, 4) . ")\n";
    echo "⚡ Best value:  {$bestValueModel} (cost×time)\n";
    echo "📊 Avg time:    " . round($avgTime, 2) . "s\n";
    echo "✅ Success:     " . count($successful) . "/" . count($results) . "\n";
    echo "💳 Total cost:  \$" . number_format($totalCost, 4) . " (all models combined)\n";
}

// ── Save results ─────────────────────────────────────────────────────
$jsonPath = "{$resultsDir}/results.json";
file_put_contents($jsonPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$mdPath = "{$resultsDir}/results.md";
$md = "# Model Benchmark Results\n\n";
$md .= "**Date:** " . date('Y-m-d H:i:s') . "\n";
$md .= "**Mode:** " . ($parallel ? "Parallel" : "Sequential") . "\n";
$md .= "**Query:** {$testQuery}\n\n";
$md .= "| # | Model | Status | Time | In Tok | Out Tok | Total | Tools | Cost | Chars |\n";
$md .= "|---|-------|--------|------|--------|---------|-------|-------|------|-------|\n";
foreach ($results as $i => $r) {
    $md .= sprintf("| %d | %s | %s | %.1fs | %s | %s | %s | %d | \$%.4f | %s |\n",
        $i + 1, $r['model'], $r['status'], $r['time_seconds'],
        number_format($r['input_tokens']), number_format($r['output_tokens']),
        number_format($r['total_tokens'] ?? 0),
        $r['tool_calls'], $r['cost_usd'], number_format($r['content_length'])
    );
}
if (!empty($successful)) {
    $md .= "\n### Winners\n";
    $md .= "- **Fastest:** {$fastestModel} ({$fastest}s)\n";
    $md .= "- **Cheapest:** {$cheapestModel} (\$" . number_format($cheapestCost, 4) . ")\n";
    $md .= "- **Best value:** {$bestValueModel}\n";
    $md .= "- **Total cost:** \$" . number_format($totalCost, 4) . "\n";
}
file_put_contents($mdPath, $md);

echo "\n📁 Results: {$resultsDir}/\n";
echo "   results.json — full data\n";
echo "   results.md   — markdown summary\n";
echo "   *.txt        — individual model responses\n";
