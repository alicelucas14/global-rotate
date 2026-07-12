<?php
/**
 * Admin credentials.
 *
 * Change ADMIN_PASS to your own password ON THE SERVER, then keep this file.
 * (PHP files are executed, not shown, so the password is not web-exposed.)
 */
define('ADMIN_USER', 'Global-101');
define('ADMIN_PASS', 'ChangeThisPassword!'); // <-- change me on the server

// Secret key for the automatic checker cron (change this on the server).
define('CHECK_KEY', 'change-this-cron-key');
