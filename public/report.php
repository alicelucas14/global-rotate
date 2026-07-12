<?php
/**
 * Beacon endpoint: the gateway (index.php) calls this from the visitor's
 * browser to report which target it landed on (active) and which it had to
 * skip (blocked) — so the admin can show a live status of every URL.
 *
 * Only URLs that actually belong to the brand are recorded (prevents abuse).
 */
require __DIR__ . '/rotator_lib.php';

$slug = rotator_slug($_REQUEST['b'] ?? '');
if ($slug === '') { http_response_code(400); exit; }

$data = rotator_load();
$rule = rotator_match_slug($data, $slug);
if (!$rule) { http_response_code(404); exit; }

$valid = [];
foreach (($rule['targets'] ?? []) as $t) {
    $n = rotator_norm_url($t);
    if ($n !== '') $valid[] = $n;
}

$active  = rotator_norm_url($_REQUEST['active'] ?? '');
$blocked = $_REQUEST['blocked'] ?? [];
if (!is_array($blocked)) $blocked = [$blocked];

$stats = rotator_stats_load();
if (!isset($stats[$slug]) || !is_array($stats[$slug])) $stats[$slug] = [];
$now = gmdate('c');

foreach ($blocked as $b) {
    $u = rotator_norm_url($b);
    if ($u === '' || !in_array($u, $valid, true)) continue;
    $e = $stats[$slug][$u] ?? ['hits' => 0, 'blocks' => 0];
    $e['status'] = 'blocked';
    $e['ts']     = $now;
    $e['blocks'] = ($e['blocks'] ?? 0) + 1;
    $stats[$slug][$u] = $e;
}

if ($active !== '' && in_array($active, $valid, true)) {
    $e = $stats[$slug][$active] ?? ['hits' => 0, 'blocks' => 0];
    $e['status'] = 'active';
    $e['ts']     = $now;
    $e['hits']   = ($e['hits'] ?? 0) + 1;
    $stats[$slug][$active] = $e;
}

// Drop stats for URLs no longer in the brand's target list.
foreach (array_keys($stats[$slug]) as $u) {
    if (!in_array($u, $valid, true)) unset($stats[$slug][$u]);
}

rotator_stats_save($stats);
http_response_code(204);
