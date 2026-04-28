<?php

/**
 * Dynamic OG image generator using ImageMagick + Pango for emoji support.
 * URL: /r/{id}/og.png (rewritten by Caddy)
 */

$id = $_GET['id'] ?? '';
if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
    http_response_code(404);
    exit;
}

$filePath = __DIR__ . '/../data/responses/' . $id . '.md';
if (!file_exists($filePath)) {
    http_response_code(404);
    exit;
}

// Extract title from markdown
$markdown = file_get_contents($filePath);
$plainText = strip_tags($markdown);
$title = 'Data Analysis Result';
if (preg_match('/^\s*#{1,6}\s+(.+)$/mu', $plainText, $m)) {
    $title = trim(preg_replace('/\*\*(.+?)\*\*/', '$1', $m[1]));
}

// Cache
$cacheDir = __DIR__ . '/../data/responses/og';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cachePath = $cacheDir . '/' . $id . '.png';
if (file_exists($cachePath) && filemtime($cachePath) >= filemtime($filePath)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cachePath);
    exit;
}

// --- Dimensions ---
$w = 1200;
$h = 630;
$pad = 60;

// --- Build image with ImageMagick CLI ---
// Base: light bg + green top bar + dark bottom strip
$cmd = 'magick -size ' . $w . 'x' . $h . ' xc:"#f8fafc"';
// Green top bar
$cmd .= ' -fill "#4ade80" -draw "rectangle 0,0 ' . $w . ',5"';
// Dark bottom strip
$cmd .= ' -fill "#1a1a2e" -draw "rectangle 0,' . ($h - 60) . ' ' . $w . ',' . $h . '"';
// Green bottom accent
$cmd .= ' -fill "#4ade80" -draw "rectangle 0,' . ($h - 4) . ' ' . $w . ',' . $h . '"';

// Logo overlay
$logoPath = file_exists(__DIR__ . '/../data/logo.png') ? __DIR__ . '/../data/logo.png' : __DIR__ . '/../logo.png';
$contentTop = $pad + 10;
if (file_exists($logoPath)) {
    $logoInfo = @getimagesize($logoPath);
    if ($logoInfo) {
        $targetH = 44;
        $targetW = (int)($logoInfo[0] * ($targetH / $logoInfo[1]));
        $cmd .= ' \\( ' . escapeshellarg($logoPath) . ' -resize ' . $targetW . 'x' . $targetH . ' \\)';
        $cmd .= ' -gravity NorthWest -geometry +' . $pad . '+' . $pad . ' -composite';
        $contentTop = $pad + $targetH + 60;
    }
}

// Write base image to temp file
$tmpBase = tempnam(sys_get_temp_dir(), 'og_base_') . '.png';
exec($cmd . ' ' . escapeshellarg($tmpBase) . ' 2>&1', $output, $ret);

// --- Render title with Pango (emoji support) ---
$titleEscaped = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
$titleSize = 48;
$maxTitleW = $w - $pad * 2;

// Pango markup — bold DejaVu Sans, word-wrapped by Pango
$pangoMarkup = '<span font="DejaVu Sans Bold ' . $titleSize . '" foreground="#0f172a">' . $titleEscaped . '</span>';
$tmpTitle = tempnam(sys_get_temp_dir(), 'og_title_') . '.png';
exec('magick -background none -size ' . $maxTitleW . 'x pango:' . escapeshellarg($pangoMarkup) . ' ' . escapeshellarg($tmpTitle) . ' 2>&1');

// Composite title onto base
$cmd2 = 'magick ' . escapeshellarg($tmpBase);
if (file_exists($tmpTitle)) {
    $cmd2 .= ' ' . escapeshellarg($tmpTitle) . ' -gravity NorthWest -geometry +' . $pad . '+' . $contentTop . ' -composite';
    // Get title image height for positioning subtitle
    $titleInfo = @getimagesize($tmpTitle);
    $titleH = $titleInfo ? $titleInfo[1] : 120;
} else {
    $titleH = 120;
}

// --- Subtitle: green pill + text ---
$subTop = $contentTop + $titleH + 25;
$pillText = 'DATA ANALYSIS';
$pillFont = '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf';
$regFont = '/usr/share/fonts/dejavu/DejaVuSans.ttf';

// Green pill background
$pillW = 175;
$pillH = 30;
$cmd2 .= ' -fill "#dcfce7" -draw "roundrectangle ' . $pad . ',' . $subTop . ' ' . ($pad + $pillW) . ',' . ($subTop + $pillH) . ' 4,4"';
// Pill text
$cmd2 .= ' -font ' . escapeshellarg($pillFont) . ' -pointsize 16 -fill "#16a34a"';
$cmd2 .= ' -annotate +' . ($pad + 14) . '+' . ($subTop + 21) . ' ' . escapeshellarg($pillText);
// "powered by Statbate Bot"
$cmd2 .= ' -font ' . escapeshellarg($regFont) . ' -pointsize 16 -fill "#64748b"';
$cmd2 .= ' -annotate +' . ($pad + $pillW + 16) . '+' . ($subTop + 21) . ' ' . escapeshellarg('powered by Statbate Bot');

// --- Footer text ---
$cmd2 .= ' -font ' . escapeshellarg($regFont) . ' -pointsize 16 -fill white';
$cmd2 .= ' -annotate +' . $pad . '+' . ($h - 24) . ' ' . escapeshellarg('sum.statbate.com');
// Green dot + plus.statbate.com
$plusX = $w - $pad - 155;
$cmd2 .= ' -fill "#4ade80" -draw "circle ' . ($plusX - 14) . ',' . ($h - 30) . ' ' . ($plusX - 10) . ',' . ($h - 26) . '"';
$cmd2 .= ' -font ' . escapeshellarg($regFont) . ' -pointsize 16 -fill "#4ade80"';
$cmd2 .= ' -annotate +' . $plusX . '+' . ($h - 24) . ' ' . escapeshellarg('plus.statbate.com');

// Output final image
$cmd2 .= ' ' . escapeshellarg($cachePath);
exec($cmd2 . ' 2>&1', $output2, $ret2);

// Cleanup temp files
@unlink($tmpBase);
@unlink($tmpTitle);

// Serve
if (file_exists($cachePath)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cachePath);
} else {
    http_response_code(500);
    echo 'Image generation failed';
}
