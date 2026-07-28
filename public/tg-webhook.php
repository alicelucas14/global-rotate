<?php
/**
 * Telegram Webhook Receiver — Komdigi Block Alerts
 *
 * Telegram calls this endpoint (via HTTPS POST) the instant a message
 * arrives in your alert group.  We parse the domain out of the message,
 * search every brand target list, and mark matching URLs as blocked
 * in rotator-checks.json — so the gateway stops routing visitors there
 * immediately.
 *
 * SETUP (one-time, run in your browser after adding credentials):
 *
 *   https://your-domain.com/tg-webhook.php?setup=1&key=<CHECK_KEY>
 *
 * That URL calls Telegram to register this file as the webhook endpoint.
 */

require __DIR__ . '/admin_config.php';
require __DIR__ . '/rotator_lib.php';

/* ------------------------------------------------------------------ */
/* Load Telegram credentials — admin panel JSON takes priority,        */
/* then fall back to constants in admin_config.php.                    */
/* ------------------------------------------------------------------ */
(function () {
    $p = __DIR__ . '/../rotator-tg.json';
    if (!file_exists($p)) return;
    $d = json_decode(@file_get_contents($p), true);
    if (!is_array($d)) return;
    if (!empty($d['token'])   && !defined('TG_BOT_TOKEN')) define('TG_BOT_TOKEN', $d['token']);
    if (!empty($d['chat_id']) && !defined('TG_CHAT_ID'))   define('TG_CHAT_ID',   $d['chat_id']);
    if (!empty($d['secret'])  && !defined('TG_SECRET'))    define('TG_SECRET',    $d['secret']);
})();

/* ------------------------------------------------------------------ */
/* Convenience: register the webhook via ?setup=1&key=CHECK_KEY        */
/* ------------------------------------------------------------------ */
if (isset($_GET['setup'])) {
    $authorized = defined('CHECK_KEY')
        && isset($_GET['key'])
        && hash_equals(CHECK_KEY, (string)$_GET['key']);

    if (!$authorized) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        exit;
    }

    if (!defined('TG_BOT_TOKEN') || TG_BOT_TOKEN === '') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Bot Token not configured. Go to Admin → Telegram settings and save it first.']);
        exit;
    }

    $secret  = defined('TG_SECRET') && TG_SECRET !== '' ? TG_SECRET : '';
    $hookUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST']
             . strtok($_SERVER['REQUEST_URI'], '?');

    $apiUrl = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/setWebhook'
            . '?url=' . urlencode($hookUrl)
            . '&allowed_updates=' . urlencode('["message","channel_post"]')
            . ($secret !== '' ? '&secret_token=' . urlencode($secret) : '');

    $res = @file_get_contents($apiUrl);
    header('Content-Type: application/json');
    echo $res ?: json_encode(['error' => 'Could not reach Telegram API']);
    exit;
}

/* ------------------------------------------------------------------ */
/* Main webhook path: Telegram POST                                    */
/* ------------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Validate the secret token header (prevents strangers from calling this).
if (defined('TG_SECRET') && TG_SECRET !== '') {
    $incoming = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals(TG_SECRET, $incoming)) {
        http_response_code(403);
        exit;
    }
}

$raw    = file_get_contents('php://input');
$update = json_decode($raw, true);

// Always 200 so Telegram does not retry.
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

// Flush response immediately so Telegram does not wait for our processing.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (ob_get_level()) {
    ob_end_flush();
    flush();
}

/* ------------------------------------------------------------------ */
/* Parse the update                                                    */
/* ------------------------------------------------------------------ */

$msg = $update['message'] ?? $update['channel_post'] ?? null;
if (!$msg) exit;

// Restrict to the configured group/channel (if TG_CHAT_ID is set).
if (defined('TG_CHAT_ID') && TG_CHAT_ID !== '') {
    $chatId = (string)($msg['chat']['id'] ?? '');
    if ($chatId !== (string)TG_CHAT_ID) exit;
}

$text = $msg['text'] ?? $msg['caption'] ?? '';
if ($text === '') exit;

/* ------------------------------------------------------------------ */
/* 1. Ignore replacement, addition, subscription, & command messages */
/* ------------------------------------------------------------------ */
$ignorePatterns = [
    '/\b(replace|replaced|adding|added|new domain|subscription|cekipos id|renewal date|active domains)\b/i',
    '/^\s*\/[a-z0-9_]+/i', // Bot commands like /replace, /info, /help
];
foreach ($ignorePatterns as $ip) {
    if (preg_match($ip, $text)) {
        exit; // Ignore silently
    }
}

/* ------------------------------------------------------------------ */
/* 2. Require message to be an actual block alert                      */
/* ------------------------------------------------------------------ */
$isBlockAlert = preg_match('/(komdigi|internet\s*positif|trust\s*positif|pemblokiran|diblokir|shows\s+blocked|block\s*alert)/i', $text);
if (!$isBlockAlert) {
    exit; // Not a block alert message
}

/* ------------------------------------------------------------------ */
/* Extract blocked domain from the Komdigi alert message              */
/*                                                                     */
/* Handled formats:                                                    */
/*   "❌ gamegold888.sbs Komdigi Alert! ❌"                           */
/*   "🚫 Domain gamegold888.sbs shows blocked status by Komdigi."     */
/* ------------------------------------------------------------------ */

$domain = null;

// Pattern 1: "Domain <domain> shows blocked"
if (preg_match('/Domain\s+([\w][\w.-]*\.[a-z]{2,})\s+shows\s+blocked/i', $text, $m)) {
    $domain = strtolower(trim($m[1]));
}

// Pattern 2: domain directly preceding/following "Komdigi Alert" or "Komdigi"
if (!$domain && preg_match('/([\w][\w.-]*\.[a-z]{2,})\s+(?:Komdigi\s+Alert|Komdigi)/i', $text, $m)) {
    $domain = strtolower(trim($m[1]));
}

// Pattern 3: domain adjacent to block alert keywords
if (!$domain && preg_match('/\b([\w][\w-]*\.[a-z]{2,}(?:\.[a-z]{2})?)\b(?=.*(?:komdigi|blocked|diblokir))/i', $text, $m)) {
    $candidate = strtolower($m[1]);
    $ignore    = ['t.me', 'telegram.org', 'bit.ly', 'tinyurl.com', 'komdigi.go.id', 'trust.id'];
    if (!in_array($candidate, $ignore, true)) {
        $domain = $candidate;
    }
}

if (!$domain) {
    $logPath = __DIR__ . '/../tg-webhook.log';
    $chatId  = (string)($msg['chat']['id'] ?? 'unknown');
    $logLine = gmdate('c') . "\tchat_id=" . $chatId . "\t[no domain]\t0 URL(s) marked\t" . trim(preg_replace('/\s+/', ' ', $text)) . PHP_EOL;
    @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
    exit;
}

/* ------------------------------------------------------------------ */
/* Mark the domain blocked across all matching brand targets           */
/* ------------------------------------------------------------------ */

$count = rotator_mark_blocked($domain);

/* ------------------------------------------------------------------ */
/* Append to local log (for admin debugging)                          */
/* ------------------------------------------------------------------ */

$logPath = __DIR__ . '/../tg-webhook.log';
$chatId  = (string)($msg['chat']['id'] ?? 'unknown');
$logLine = gmdate('c') . "\tchat_id=" . $chatId . "\t" . $domain . "\t" . $count . " URL(s) marked\t" . trim(preg_replace('/\s+/', ' ', $text)) . PHP_EOL;

// Cap log at ~200 KB.
if (@file_exists($logPath) && @filesize($logPath) > 200 * 1024) {
    $lines = @file($logPath);
    if ($lines) {
        @file_put_contents($logPath, implode('', array_slice($lines, -400)));
    }
}
@file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
