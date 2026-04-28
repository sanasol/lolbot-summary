<?php

/**
 * Usage Dashboard — AI API cost tracking and analytics.
 * URL: /dashboard?token=xxx&period=day|week|month
 */

$config = require __DIR__ . '/../config/config.php';

// Auth check
$token = $_GET['token'] ?? '';
$dashboardToken = $config['dashboard_token'] ?? '';
if ($dashboardToken === '' || !hash_equals($dashboardToken, $token)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Forbidden</title></head><body><h1>403 - Forbidden</h1></body></html>';
    exit;
}

// Period
$period = $_GET['period'] ?? 'day';
if (!in_array($period, ['day', 'week', 'month'], true)) {
    $period = 'day';
}

$dataPath = $config['log_path'] ?? __DIR__ . '/../data';
$usageDir = $dataPath . '/usage';

// Determine date range
$today = new DateTime('now', new DateTimeZone('UTC'));
switch ($period) {
    case 'week':
        $startDate = (clone $today)->modify('-6 days');
        break;
    case 'month':
        $startDate = (clone $today)->modify('-29 days');
        break;
    default:
        $startDate = clone $today;
        break;
}

// Pricing lookup (per million tokens)
$pricing = [
    'moonshotai/kimi-k2.5' => ['input' => 0.23, 'output' => 3.0],
    'x-ai/grok-3-beta' => ['input' => 3.0, 'output' => 15.0],
    'google/gemini-2.5-flash' => ['input' => 0.015, 'output' => 0.06],
    'google/gemini-2.5-flash-preview:thinking' => ['input' => 0.015, 'output' => 0.06],
    'x-ai/grok-code-fast-1' => ['input' => 0.2, 'output' => 1.5],
    'google/gemini-3-pro-preview' => ['input' => 1.25, 'output' => 10.0],
    'google/gemini-3-pro-image-preview' => ['input' => 1.25, 'output' => 10.0],
    'openai/o4-mini-high' => ['input' => 1.10, 'output' => 4.40],
    'anthropic/claude-sonnet-4' => ['input' => 3.0, 'output' => 15.0],
    'anthropic/claude-sonnet-4.5' => ['input' => 3.0, 'output' => 15.0],
];

// Fixed cost per successful image generation (OpenRouter charges flat rate on top of tokens)
$imageFixedCost = 0.137;

function calcCost(array $pricing, string $model, ?int $inputTokens, ?int $outputTokens, string $type = '', bool $success = true): float
{
    global $imageFixedCost;
    // Image generation: use fixed cost for successful requests (token-based math undercounts)
    if ($type === 'image' && $success) {
        return $imageFixedCost;
    }
    $p = $pricing[$model] ?? null;
    if (!$p) return 0.0;
    $in = ($inputTokens ?? 0) / 1_000_000 * $p['input'];
    $out = ($outputTokens ?? 0) / 1_000_000 * $p['output'];
    return $in + $out;
}

// Read JSONL files for the period
$entries = [];
$current = clone $startDate;
while ($current <= $today) {
    $file = $usageDir . '/' . $current->format('Y-m-d') . '.jsonl';
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
    }
    $current->modify('+1 day');
}

// Aggregation
$totalRequests = count($entries);
$totalCost = 0.0;
$totalInputTokens = 0;
$totalOutputTokens = 0;

$byType = [];
$byGroup = [];
$byUser = [];
$byModel = [];
$byDay = [];

foreach ($entries as $e) {
    $type = $e['type'] ?? 'unknown';
    $model = $e['model'] ?? 'unknown';
    $chatId = $e['chat_id'] ?? 'unknown';
    $chatTitle = $e['chat_title'] ?? ('Chat ' . $chatId);
    $userId = $e['user_id'] ?? null;
    $username = $e['username'] ?? 'system';
    $inTok = $e['input_tokens'] ?? 0;
    $outTok = $e['output_tokens'] ?? 0;
    $success = $e['success'] ?? true;
    $cost = calcCost($pricing, $model, $inTok, $outTok, $type, $success);
    $day = substr($e['ts'] ?? '', 0, 10);

    $totalCost += $cost;
    $totalInputTokens += $inTok;
    $totalOutputTokens += $outTok;

    // By type
    if (!isset($byType[$type])) $byType[$type] = ['count' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0];
    $byType[$type]['count']++;
    $byType[$type]['input_tokens'] += $inTok;
    $byType[$type]['output_tokens'] += $outTok;
    $byType[$type]['cost'] += $cost;

    // By group
    $gKey = (string)$chatId;
    if (!isset($byGroup[$gKey])) $byGroup[$gKey] = ['title' => $chatTitle, 'count' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0];
    if ($chatTitle !== ('Chat ' . $chatId)) $byGroup[$gKey]['title'] = $chatTitle; // prefer real title
    $byGroup[$gKey]['count']++;
    $byGroup[$gKey]['input_tokens'] += $inTok;
    $byGroup[$gKey]['output_tokens'] += $outTok;
    $byGroup[$gKey]['cost'] += $cost;

    // By user
    if ($userId !== null) {
        $uKey = (string)$userId;
        if (!isset($byUser[$uKey])) $byUser[$uKey] = ['username' => $username, 'count' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0, 'types' => []];
        if ($username !== 'system') $byUser[$uKey]['username'] = $username;
        $byUser[$uKey]['count']++;
        $byUser[$uKey]['input_tokens'] += $inTok;
        $byUser[$uKey]['output_tokens'] += $outTok;
        $byUser[$uKey]['cost'] += $cost;
        $byUser[$uKey]['types'][$type] = ($byUser[$uKey]['types'][$type] ?? 0) + 1;
    }

    // By model
    if (!isset($byModel[$model])) $byModel[$model] = ['count' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0];
    $byModel[$model]['count']++;
    $byModel[$model]['input_tokens'] += $inTok;
    $byModel[$model]['output_tokens'] += $outTok;
    $byModel[$model]['cost'] += $cost;

    // By day
    if ($day) {
        if (!isset($byDay[$day])) $byDay[$day] = ['count' => 0, 'cost' => 0.0];
        $byDay[$day]['count']++;
        $byDay[$day]['cost'] += $cost;
    }
}

// Scan message files for group message counts and members
$groupMessages = []; // chatId => ['count' => int, 'members' => [username => count]]
$msgFiles = glob($dataPath . '/*_messages.json');
foreach ($msgFiles as $mf) {
    if (preg_match('/(-?\d+)_messages\.json$/', $mf, $mm)) {
        $gid = $mm[1];
        $raw = @file_get_contents($mf);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) continue;
        $members = [];
        foreach ($data as $line) {
            if (preg_match('/^\[\d{2}:\d{2}\]\s*\[ID:\d+\]\s*(?:\[TID:\d+\]\s*)?(?:\[BOT\]\s*)?(\S+?):/', $line, $um)) {
                $u = $um[1];
                $members[$u] = ($members[$u] ?? 0) + 1;
            }
        }
        arsort($members);
        $groupMessages[$gid] = ['count' => count($data), 'members' => $members];
    }
}

// Sort
uasort($byGroup, fn($a, $b) => $b['cost'] <=> $a['cost']);
uasort($byUser, fn($a, $b) => $b['cost'] <=> $a['cost']);
uasort($byModel, fn($a, $b) => $b['cost'] <=> $a['cost']);
uasort($byType, fn($a, $b) => $b['cost'] <=> $a['cost']);
ksort($byDay);

$periodLabel = match($period) {
    'week' => 'Last 7 Days',
    'month' => 'Last 30 Days',
    default => 'Today',
};

function fmtTokens(int $n): string {
    if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M';
    if ($n >= 1_000) return round($n / 1_000, 1) . 'K';
    return (string)$n;
}

function fmtCost(float $c): string {
    if ($c < 0.01) return '$' . number_format($c, 4);
    return '$' . number_format($c, 2);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usage Dashboard — Statbate Bot</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.65;
            color: #1e293b;
            background: #f1f5f9;
            min-height: 100vh;
        }
        .top-bar {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #fff;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 12px rgba(0,0,0,0.2);
        }
        .top-bar .brand { display: flex; align-items: center; gap: 0.6rem; }
        .top-bar .brand-icon {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, #4ade80, #22d3ee);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
        }
        .top-bar .brand-text { font-size: 1.1rem; font-weight: 700; }
        .top-bar .brand-text span { color: #4ade80; }
        .container { max-width: 1200px; margin: 0 auto; padding: 1.5rem 1rem; }

        /* Period selector */
        .period-bar { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; align-items: center; }
        .period-bar a {
            padding: 0.45rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            background: #fff;
            border: 1px solid #e2e8f0;
            transition: all 0.15s;
        }
        .period-bar a:hover { background: #eef2ff; border-color: #818cf8; color: #4338ca; }
        .period-bar a.active { background: #1e293b; color: #fff; border-color: #1e293b; }
        .period-label { font-size: 0.8rem; color: #94a3b8; margin-left: 0.5rem; }

        /* Cards */
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04);
            margin-bottom: 1.5rem;
        }
        .card h2 {
            font-size: 1.1rem;
            color: #0f172a;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        /* Overview stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
        .stat-box {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        .stat-box .stat-value { font-size: 1.5rem; font-weight: 700; color: #0f172a; }
        .stat-box .stat-label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.25rem; }

        /* Tables */
        .table-wrap { overflow-x: auto; border-radius: 8px; border: 1px solid #e2e8f0; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        thead { background: #1e293b; color: #fff; }
        th { padding: 0.6rem 0.8rem; text-align: left; font-weight: 600; white-space: nowrap; }
        td { padding: 0.5rem 0.8rem; border-bottom: 1px solid #f1f5f9; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody tr:hover { background: #eef2ff; }
        .text-right { text-align: right; }
        .text-mono { font-family: 'SF Mono', 'Fira Code', Consolas, monospace; font-size: 0.82rem; }
        .cost-cell { color: #059669; font-weight: 600; }

        /* Day chart (simple bar) */
        .day-bars { display: flex; gap: 2px; align-items: flex-end; height: 80px; margin-top: 0.5rem; }
        .day-bar {
            flex: 1;
            background: linear-gradient(180deg, #4ade80, #22c55e);
            border-radius: 3px 3px 0 0;
            min-width: 4px;
            position: relative;
        }
        .day-bar:hover { opacity: 0.8; }
        .day-bar .day-tip {
            display: none;
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: #fff;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            white-space: nowrap;
            z-index: 10;
        }
        .day-bar:hover .day-tip { display: block; }
        .day-labels { display: flex; gap: 2px; margin-top: 0.25rem; }
        .day-labels span { flex: 1; text-align: center; font-size: 0.6rem; color: #94a3b8; overflow: hidden; }

        .empty-state { text-align: center; padding: 2rem; color: #94a3b8; }

        /* Type pills */
        .pill { display: inline-block; padding: 0.1rem 0.45rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; margin: 1px 2px; white-space: nowrap; }
        .pill-mcp { background: #dbeafe; color: #1d4ed8; }
        .pill-mention { background: #dcfce7; color: #15803d; }
        .pill-reaction { background: #ffedd5; color: #c2410c; }
        .pill-summary { background: #f3e8ff; color: #7e22ce; }
        .pill-image { background: #fce7f3; color: #be185d; }
        .pill-antispam { background: #fef9c3; color: #a16207; }
        .pill-unknown { background: #f1f5f9; color: #475569; }

        /* Group link */
        .group-link { color: inherit; text-decoration: none; }
        .group-link:hover { text-decoration: underline; color: #2563eb; }

        .page-footer {
            text-align: center;
            padding: 1.5rem 1rem;
            color: #94a3b8;
            font-size: 0.75rem;
        }

        @media (max-width: 640px) {
            .container { padding: 1rem 0.75rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .card { padding: 1rem; }
        }
    </style>
</head>
<body>

<div class="top-bar">
    <div class="brand">
        <div class="brand-icon">&#x1F4CA;</div>
        <div class="brand-text"><span>Usage</span> Dashboard</div>
    </div>
    <div style="font-size:0.75rem;color:#94a3b8;"><?= date('Y-m-d H:i') ?> UTC</div>
</div>

<div class="container">

    <!-- Period Selector -->
    <div class="period-bar">
        <?php foreach (['day' => 'Today', 'week' => 'Week', 'month' => 'Month'] as $p => $label): ?>
            <a href="?token=<?= urlencode($token) ?>&period=<?= $p ?>" class="<?= $period === $p ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
        <span class="period-label"><?= $periodLabel ?> (<?= $startDate->format('M j') ?><?= $period !== 'day' ? ' — ' . $today->format('M j') : '' ?>)</span>
    </div>

    <?php if ($totalRequests === 0): ?>
        <div class="card"><div class="empty-state">No usage data for this period.</div></div>
    <?php else: ?>

    <!-- Overview -->
    <div class="card">
        <h2>Overview</h2>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-value"><?= number_format($totalRequests) ?></div>
                <div class="stat-label">Total Requests</div>
            </div>
            <div class="stat-box">
                <div class="stat-value cost-cell"><?= fmtCost($totalCost) ?></div>
                <div class="stat-label">Estimated Cost</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?= fmtTokens($totalInputTokens) ?></div>
                <div class="stat-label">Input Tokens</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?= fmtTokens($totalOutputTokens) ?></div>
                <div class="stat-label">Output Tokens</div>
            </div>
        </div>

        <?php if ($period !== 'day' && !empty($byDay)): ?>
        <!-- Day chart -->
        <?php
            $maxDayCost = max(array_column($byDay, 'cost'));
            if ($maxDayCost <= 0) $maxDayCost = 1;
        ?>
        <div style="margin-top:1rem;">
            <div style="font-size:0.8rem;color:#64748b;margin-bottom:0.25rem;">Daily cost</div>
            <div class="day-bars">
                <?php foreach ($byDay as $day => $d): ?>
                    <div class="day-bar" style="height: <?= max(2, round($d['cost'] / $maxDayCost * 100)) ?>%">
                        <div class="day-tip"><?= $day ?>: <?= fmtCost($d['cost']) ?> (<?= $d['count'] ?> req)</div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="day-labels">
                <?php foreach ($byDay as $day => $d): ?>
                    <span><?= substr($day, 8, 2) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- By Type -->
    <div class="card">
        <h2>By Request Type</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Type</th><th class="text-right">Requests</th><th class="text-right">Avg Input</th><th class="text-right">Avg Output</th><th class="text-right">Total Cost</th></tr></thead>
                <tbody>
                <?php foreach ($byType as $type => $d): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($type) ?></strong></td>
                        <td class="text-right text-mono"><?= number_format($d['count']) ?></td>
                        <td class="text-right text-mono"><?= $d['count'] > 0 ? fmtTokens((int)($d['input_tokens'] / $d['count'])) : '—' ?></td>
                        <td class="text-right text-mono"><?= $d['count'] > 0 ? fmtTokens((int)($d['output_tokens'] / $d['count'])) : '—' ?></td>
                        <td class="text-right text-mono cost-cell"><?= fmtCost($d['cost']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- By Model -->
    <div class="card">
        <h2>By Model</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Model</th><th class="text-right">Requests</th><th class="text-right">Input Tokens</th><th class="text-right">Output Tokens</th><th class="text-right">Total Cost</th></tr></thead>
                <tbody>
                <?php foreach ($byModel as $model => $d): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($model) ?></strong></td>
                        <td class="text-right text-mono"><?= number_format($d['count']) ?></td>
                        <td class="text-right text-mono"><?= fmtTokens($d['input_tokens']) ?></td>
                        <td class="text-right text-mono"><?= fmtTokens($d['output_tokens']) ?></td>
                        <td class="text-right text-mono cost-cell"><?= fmtCost($d['cost']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- By Group -->
    <div class="card">
        <h2>By Group</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Group</th><th class="text-right">Messages</th><th>Members</th><th class="text-right">Requests</th><th class="text-right">Tokens (in/out)</th><th class="text-right">Total Cost</th></tr></thead>
                <tbody>
                <?php foreach ($byGroup as $gKey => $d):
                    $gm = $groupMessages[$gKey] ?? null;
                ?>
                    <tr>
                        <td><a class="group-link" href="/messages?token=<?= urlencode($token) ?>&chat_id=<?= urlencode($gKey) ?>"><strong><?= htmlspecialchars($d['title']) ?></strong></a><br><span style="font-size:0.75rem;color:#94a3b8;"><?= htmlspecialchars($gKey) ?></span></td>
                        <td class="text-right text-mono"><?= $gm ? number_format($gm['count']) : '—' ?></td>
                        <td><?php if ($gm && !empty($gm['members'])):
                            $shown = 0;
                            foreach ($gm['members'] as $mName => $mCount) {
                                if ($shown >= 8) { echo '<span style="font-size:0.7rem;color:#94a3b8;"> +' . (count($gm['members']) - 8) . ' more</span>'; break; }
                                echo '<span style="font-size:0.75rem;white-space:nowrap;">' . htmlspecialchars($mName) . '<sup style="color:#94a3b8;font-size:0.6rem;">' . $mCount . '</sup></span> ';
                                $shown++;
                            }
                        else: echo '<span style="color:#94a3b8;">—</span>'; endif; ?></td>
                        <td class="text-right text-mono"><?= number_format($d['count']) ?></td>
                        <td class="text-right text-mono"><?= fmtTokens($d['input_tokens']) ?> / <?= fmtTokens($d['output_tokens']) ?></td>
                        <td class="text-right text-mono cost-cell"><?= fmtCost($d['cost']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- By User -->
    <div class="card">
        <h2>By User</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>User</th><th class="text-right">Requests</th><th>Breakdown</th><th class="text-right">Tokens (in/out)</th><th class="text-right">Total Cost</th></tr></thead>
                <tbody>
                <?php foreach ($byUser as $uKey => $d): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($d['username']) ?></strong><br><span style="font-size:0.75rem;color:#94a3b8;"><?= htmlspecialchars($uKey) ?></span></td>
                        <td class="text-right text-mono"><?= number_format($d['count']) ?></td>
                        <td><?php
                            $pillClasses = ['mcp' => 'pill-mcp', 'mention' => 'pill-mention', 'reaction' => 'pill-reaction', 'summary' => 'pill-summary', 'image' => 'pill-image', 'antispam' => 'pill-antispam'];
                            arsort($d['types']);
                            foreach ($d['types'] as $t => $cnt) {
                                $cls = $pillClasses[$t] ?? 'pill-unknown';
                                echo '<span class="pill ' . $cls . '">' . htmlspecialchars($t) . ': ' . $cnt . '</span>';
                            }
                        ?></td>
                        <td class="text-right text-mono"><?= fmtTokens($d['input_tokens']) ?> / <?= fmtTokens($d['output_tokens']) ?></td>
                        <td class="text-right text-mono cost-cell"><?= fmtCost($d['cost']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

</div>

<footer class="page-footer">
    Statbate Bot Usage Dashboard
</footer>

</body>
</html>
