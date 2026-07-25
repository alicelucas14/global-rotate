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

// Load cached check results
$checks = rotator_checks_load();

// Normalise URL to the key used by checker.php (strip scheme + www)
$key1 = rtrim(preg_replace('#^https?://(?:www\.)?#i', '', $url), '/'); // no scheme, no www
$key2 = rtrim(preg_replace('#^https?://#i',            '', $url), '/'); // no scheme, with www

$info = $checks[$key1] ?? $checks[$key2] ?? null;
$age  = $info ? (int)(time() - (int)($info['checked_at'] ?? 0)) : PHP_INT_MAX;

if ($info && $age < $STALE_SEC) {
    // Return fresh cached result — no live check needed
    $status = (string)($info['status'] ?? 'unknown');
    echo json_encode([
        'ok'     => ($status === 'clean'),
        'status' => $status,
        'age'    => $age,
    ]);
    exit;
}

// Cache is stale or missing — do a fast live HEAD request
if (!function_exists('curl_init')) {
    echo json_encode(['ok' => false, 'status' => 'no_curl']);
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 4,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_NOBODY         => true,          // HEAD only — fast
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => ['Accept: */*', 'Accept-Language: id,en;q=0.9'],
]);
curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errno    = curl_errno($ch);
curl_close($ch);

if ($errno !== 0 || $httpCode === 0) {
    echo json_encode(['ok' => false, 'status' => 'down', 'http' => $httpCode, 'curl_errno' => $errno]);
    exit;
}

// Any HTTP response (200, 301, 302, 403, 503…) means the server IS reachable.
// We only mark as blocked if the checks data explicitly says so.
$ok = ($httpCode >= 100 && $httpCode < 600);
echo json_encode([
    'ok'     => $ok,
    'status' => $ok ? 'clean' : 'error',
    'http'   => $httpCode,
    'age'    => null,
]);
