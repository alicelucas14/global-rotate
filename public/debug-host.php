<?php
/**
 * Temporary debug file - DELETE after diagnosing the host matching issue.
 * Access: https://polaslot88.in/debug-host.php
 */
require __DIR__ . '/rotator_lib.php';

$data       = rotator_load();
$host       = $_SERVER['HTTP_HOST'] ?? '(none)';
$matchedRule = rotator_match_host($data, $host);
$firstRule   = rotator_first_enabled($data);
$candidates  = [];

if ($matchedRule) {
    $targets = rotator_rule_targets($matchedRule);
    $candidates = $targets;
}

header('Content-Type: application/json');
echo json_encode([
    'HTTP_HOST'         => $host,
    'matched_rule'      => $matchedRule ? ($matchedRule['label'] ?? '?') : null,
    'matched_rule_hosts'=> $matchedRule ? ($matchedRule['hosts'] ?? []) : null,
    'first_enabled_rule'=> $firstRule  ? ($firstRule['label']  ?? '?') : null,
    'candidates'        => $candidates,
    'all_rules_summary' => array_map(function($r) {
        return [
            'label'   => $r['label']   ?? '?',
            'enabled' => !empty($r['enabled']),
            'hosts'   => $r['hosts']   ?? [],
            'targets' => $r['targets'] ?? [],
        ];
    }, $data['rules'] ?? []),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
