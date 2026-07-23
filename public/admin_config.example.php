<?php
/**
 * Template for admin_config.php — copy, don't edit this file.
 *
 *   cp public/admin_config.example.php public/admin_config.php
 *
 * admin_config.php itself is gitignored, so the copy on each server keeps its
 * own values and `git pull` never overwrites them. This template is the only
 * version in the repo, and it must never contain real credentials.
 *
 * PHP files are executed rather than served, so these values are not exposed
 * to the web — but they are exposed to anyone who can read the repo, which is
 * why the real ones live only on the server.
 */

// Fallback login for the admin panel. Used only until you change the
// username/password from inside the panel (Settings -> Account), which writes
// a hashed credential to rotator-auth.json and takes precedence from then on.
define('ADMIN_USER', 'change-this-user');
define('ADMIN_PASS', 'change-this-password');

// Shared secret for the automatic checker cron. There is no UI for this one —
// set it here, and use the same value in the cron command:
//
//   curl -s "https://your-domain.example/checker.php?key=YOUR_CHECK_KEY" > /dev/null
//
// Generate something unguessable, e.g.  openssl rand -hex 24
define('CHECK_KEY', 'change-this-cron-key');
