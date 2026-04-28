<?php

/**
 * Message Viewer — View stored chat messages for a group.
 * URL: /messages?token=xxx&chat_id=-1234567
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

$chatId = $_GET['chat_id'] ?? '';
if ($chatId === '') {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><title>Bad Request</title></head><body><h1>400 - Missing chat_id</h1></body></html>';
    exit;
}

$dataPath = $config['log_path'] ?? __DIR__ . '/../data';
$file = $dataPath . '/' . $chatId . '_messages.json';

$messages = [];
$error = null;

if (!file_exists($file)) {
    $error = 'No message file found for this chat.';
} else {
    $raw = file_get_contents($file);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $error = 'Failed to parse message file.';
    } else {
        // Sort by timestamp key (ascending — oldest first)
        ksort($decoded);
        foreach ($decoded as $ts => $line) {
            $parsed = parseMessage($ts, $line);
            if ($parsed) {
                $messages[] = $parsed;
            }
        }
    }
}

function parseMessage(string $ts, string $line): ?array
{
    // Format: [HH:MM] [ID:xxx] username: text
    // or:     [HH:MM] [ID:xxx] [BOT] botname: text
    // or:     [HH:MM] [ID:xxx] [TID:yyy] username: text
    // or:     [HH:MM] [ID:xxx] [TID:yyy] [BOT] botname: text
    $isBot = false;
    $username = '';
    $text = '';
    $msgId = '';

    if (preg_match('/^\[(\d{2}:\d{2})\]\s*\[ID:(\d+)\]\s*(?:\[TID:\d+\]\s*)?(?:\[BOT\]\s*)?(\S+?):\s*(.*)/s', $line, $m)) {
        $msgId = $m[2];
        $username = $m[3];
        $text = $m[4];
        $isBot = str_contains($line, '[BOT]');
    } else {
        // Fallback: show raw line
        $text = $line;
    }

    return [
        'ts' => (int)$ts,
        'datetime' => date('Y-m-d H:i:s', (int)$ts),
        'date' => date('Y-m-d', (int)$ts),
        'time' => date('H:i', (int)$ts),
        'msg_id' => $msgId,
        'username' => $username,
        'text' => $text,
        'is_bot' => $isBot,
    ];
}

// Stats
$totalMessages = count($messages);
$dateRange = '';
if ($totalMessages > 0) {
    $first = $messages[0]['date'];
    $last = $messages[$totalMessages - 1]['date'];
    $dateRange = $first === $last ? $first : "$first — $last";
}

// Group messages by date for date separators
$byDate = [];
foreach ($messages as $msg) {
    $byDate[$msg['date']][] = $msg;
}

// Simple hash-based color for usernames
function usernameColor(string $name): string
{
    $colors = ['#e11d48','#7c3aed','#2563eb','#0891b2','#059669','#ca8a04','#ea580c','#be123c','#6d28d9','#0d9488'];
    $hash = crc32($name);
    return $colors[abs($hash) % count($colors)];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages — <?= htmlspecialchars($chatId) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.5;
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
            position: sticky;
            top: 0;
            z-index: 100;
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
        .top-bar a { color: #94a3b8; text-decoration: none; font-size: 0.85rem; }
        .top-bar a:hover { color: #fff; }

        .container { max-width: 900px; margin: 0 auto; padding: 1.5rem 1rem; }

        .info-bar {
            background: #fff;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: center;
        }
        .info-item { font-size: 0.85rem; color: #64748b; }
        .info-item strong { color: #0f172a; }

        .date-separator {
            text-align: center;
            margin: 1.5rem 0 0.75rem;
            position: relative;
        }
        .date-separator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e2e8f0;
        }
        .date-separator span {
            position: relative;
            background: #f1f5f9;
            padding: 0 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .msg {
            background: #fff;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            margin-bottom: 2px;
            display: flex;
            gap: 0.5rem;
            align-items: flex-start;
            border-left: 3px solid transparent;
            transition: background 0.1s;
        }
        .msg:hover { background: #f8fafc; }
        .msg.bot {
            background: #f0fdf4;
            border-left-color: #4ade80;
        }
        .msg.bot:hover { background: #e8fbe8; }
        .msg-time {
            font-size: 0.72rem;
            color: #94a3b8;
            font-family: 'SF Mono', 'Fira Code', Consolas, monospace;
            white-space: nowrap;
            padding-top: 0.15rem;
            min-width: 36px;
        }
        .msg-body { flex: 1; min-width: 0; }
        .msg-user {
            font-weight: 700;
            font-size: 0.82rem;
            margin-right: 0.4rem;
        }
        .msg-text {
            font-size: 0.85rem;
            white-space: pre-wrap;
            word-break: break-word;
            color: #334155;
        }
        .msg.bot .msg-user { color: #16a34a !important; }

        .msg-text-collapsed {
            max-height: 120px;
            overflow: hidden;
            position: relative;
        }
        .msg-text-collapsed::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 30px;
            background: linear-gradient(transparent, #fff);
        }
        .msg.bot .msg-text-collapsed::after {
            background: linear-gradient(transparent, #f0fdf4);
        }
        .expand-btn {
            font-size: 0.75rem;
            color: #2563eb;
            cursor: pointer;
            margin-top: 0.25rem;
            display: inline-block;
            background: none;
            border: none;
            padding: 0;
            font-family: inherit;
        }
        .expand-btn:hover { text-decoration: underline; }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #94a3b8;
            font-size: 1rem;
        }

        .page-footer {
            text-align: center;
            padding: 1.5rem 1rem;
            color: #94a3b8;
            font-size: 0.75rem;
        }

        @media (max-width: 640px) {
            .container { padding: 1rem 0.5rem; }
            .msg { padding: 0.4rem 0.5rem; }
            .info-bar { padding: 0.75rem 1rem; gap: 0.75rem; }
        }
    </style>
</head>
<body>

<div class="top-bar">
    <div class="brand">
        <div class="brand-icon">&#x1F4AC;</div>
        <div class="brand-text"><span>Message</span> Viewer</div>
    </div>
    <a href="/dashboard?token=<?= urlencode($token) ?>">&#8592; Dashboard</a>
</div>

<div class="container">

    <div class="info-bar">
        <div class="info-item"><strong>Chat ID:</strong> <?= htmlspecialchars($chatId) ?></div>
        <div class="info-item"><strong>Messages:</strong> <?= number_format($totalMessages) ?></div>
        <?php if ($dateRange): ?>
            <div class="info-item"><strong>Period:</strong> <?= $dateRange ?></div>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="empty-state"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($totalMessages === 0): ?>
        <div class="empty-state">No messages stored for this chat.</div>
    <?php else: ?>

        <?php foreach ($byDate as $date => $dayMessages): ?>
            <div class="date-separator"><span><?= date('l, M j, Y', strtotime($date)) ?></span></div>
            <?php foreach ($dayMessages as $msg): ?>
                <?php $longText = mb_strlen($msg['text']) > 500; ?>
                <div class="msg <?= $msg['is_bot'] ? 'bot' : '' ?>">
                    <div class="msg-time"><?= $msg['time'] ?></div>
                    <div class="msg-body">
                        <span class="msg-user" style="color: <?= $msg['is_bot'] ? '#16a34a' : usernameColor($msg['username']) ?>"><?= htmlspecialchars($msg['username'] ?: '???') ?></span>
                        <div class="msg-text <?= $longText ? 'msg-text-collapsed' : '' ?>" <?= $longText ? 'data-full="1"' : '' ?>><?= htmlspecialchars($msg['text']) ?></div>
                        <?php if ($longText): ?>
                            <button class="expand-btn" onclick="toggleExpand(this)">Show more</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

<footer class="page-footer">
    Statbate Bot — Message Viewer
</footer>

<script>
function toggleExpand(btn) {
    const textEl = btn.previousElementSibling;
    if (textEl.classList.contains('msg-text-collapsed')) {
        textEl.classList.remove('msg-text-collapsed');
        btn.textContent = 'Show less';
    } else {
        textEl.classList.add('msg-text-collapsed');
        btn.textContent = 'Show more';
    }
}
</script>

</body>
</html>
