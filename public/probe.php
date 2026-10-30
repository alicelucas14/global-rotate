<?php
/**
 * probe.php  — Same-origin probe endpoint.
 *
 * The browser calls this instead of directly fetching the target domain,
 * which bypasses Cloudflare Bot Fight Mode and WAF restrictions on targets.
 *
 * Returns JSON: { ok: bool, status: string, age: int|null }
 *
 * GET /probe.php?url=https%3A%2F%2Fwww.example.com
 */
require __DIR__ . '/rotator_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Robots-Tag: noindex');

$url = trim($_GET['url'] ?? '');
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'status' => 'invalid_url']);
    exit;
}

// Only allow http/https
$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'status' => 'invalid_scheme']);
    exit;
}

$STALE_SEC = 300; // 5 minutes — re-check if cached result is older than this

// Load cached check results.
// rotator-checks.json is a nested structure: { brandSlug: { fullUrl: { status, ts, ... } } }
// We must search all brands for a matching URL key.
$checks = rotator_checks_load();
$normUrl = rtrim($url, '/');

$info = null;
foreach ($checks as $brandChecks) {
    if (!is_array($brandChecks)) continue;
    // Try exact normalised URL first (the key used by rotator_lib.php).
    if (isset($brandChecks[$normUrl])) {
        $info = $brandChecks[$normUrl];
        break;
    }
    // Also try with trailing slash stripped from stored key variants.
    foreach ($brandChecks as $storedUrl => $entry) {
        if (rtrim((string)$storedUrl, '/') === $normUrl) {
            $info = $entry;
            break 2;
        }
    }
}
// Timestamp is stored under 'ts' (ISO 8601 string), not 'checked_at'.
$age = ($info && !empty($info['ts'])) ? (int)(time() - (int)strtotime($info['ts'])) : PHP_INT_MAX;

// Blocked domains: trust the cached verdict for 24 h even if stale.
// A server-side check from outside Indonesia will succeed even for ISP-blocked
// domains, so we must NOT let a stale live-check override a verified block.
if ($info && ($info['status'] ?? '') === 'blocked' && $age < 86400) {
    echo json_encode(['ok' => false, 'status' => 'blocked', 'age' => $age, 'cached' => true]);
    exit;
}

// If cached result is down and fresh, return down immediately
if ($info && ($info['status'] ?? '') === 'down' && $age < $STALE_SEC) {
    echo json_encode(['ok' => false, 'status' => 'down', 'age' => $age, 'reason' => $info['reason'] ?? 'down']);
    exit;
}

// If cached result is clean, only trust it if verified (meaning body check passed) and fresh
if ($info && ($info['status'] ?? '') === 'clean' && !empty($info['verified']) && $age < $STALE_SEC) {
    echo json_encode(['ok' => true, 'status' => 'clean', 'age' => $age]);
    exit;
}

// Cache is stale, missing, or unverified — perform live check using rotator_check_url()
$res = rotator_check_url($url);
$status = $res['status'] ?? 'down';
$ok = ($status === 'clean');

echo json_encode([
    'ok'     => $ok,
    'status' => $status,
    'reason' => $res['reason'] ?? '',
    'http'   => $res['http'] ?? 0,
    'age'    => null,
]);
