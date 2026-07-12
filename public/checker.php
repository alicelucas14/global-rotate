<?php
/**
 * Runs automatic health/block checks on every brand's target URLs.
 *
 * Access:
 *   - When logged into the admin (session), or
 *   - With ?key=CHECK_KEY (for the aaPanel cron job), or
 *   - From the command line (php checker.php).
 *
 * aaPanel cron (every 5-10 min), Shell script:
 *   curl -s "https://domains-rotate.store/checker.php?key=YOUR_CHECK_KEY" > /dev/null
 */
require __DIR__ . '/admin_config.php';
require __DIR__ . '/rotator_lib.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) session_start();

$authorized = $cli
    || (!empty($_SESSION['rotator_admin']))
    || (defined('CHECK_KEY') && isset($_GET['key']) && hash_equals(CHECK_KEY, (string)$_GET['key']));

if (!$authorized) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"error":"forbidden"}';
    exit;
}

$checks = rotator_run_checks();

if (!$cli) header('Content-Type: application/json; charset=utf-8');
echo json_encode($checks, JSON_UNESCAPED_SLASHES);
