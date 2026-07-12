<?php
/**
 * Returns the live status stats as JSON. Admin-only.
 * Used by admin.php to show which target is currently in use per brand.
 */
require __DIR__ . '/admin_config.php';
require __DIR__ . '/rotator_lib.php';
session_start();

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['rotator_admin'])) {
    http_response_code(403);
    echo '{}';
    exit;
}
echo json_encode(rotator_stats_load(), JSON_UNESCAPED_SLASHES);
