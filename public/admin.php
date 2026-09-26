<?php
require __DIR__ . '/admin_config.php';
require __DIR__ . '/rotator_lib.php';

/* ---- Telegram config helpers ---- */
function tg_config_path() {
    return __DIR__ . '/../rotator-tg.json';
}
function tg_config_load() {
    $p = tg_config_path();
    if (!file_exists($p)) return ['token' => '', 'chat_id' => '', 'secret' => ''];
    $d = json_decode(@file_get_contents($p), true);
    return is_array($d) ? array_merge(['token' => '', 'chat_id' => '', 'secret' => ''], $d) : ['token' => '', 'chat_id' => '', 'secret' => ''];
}
function tg_config_save($d) {
    return @file_put_contents(tg_config_path(), json_encode($d, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

session_start();

function rotator_auth_path() {
    return __DIR__ . '/../rotator-auth.json';
}
function rotator_auth_load() {
    $p = rotator_auth_path();
    if (!file_exists($p)) return null;
    $d = json_decode(@file_get_contents($p), true);
    return (is_array($d) && isset($d['user'], $d['hash'])) ? $d : null;
}
function rotator_auth_user() {
    $a = rotator_auth_load();
    return $a ? $a['user'] : ADMIN_USER;
}
function rotator_auth_verify($user, $pass) {
    $a = rotator_auth_load();
    if ($a) {
        return hash_equals($a['user'], (string)$user) && password_verify((string)$pass, $a['hash']);
    }
    return hash_equals(ADMIN_USER, (string)$user) && hash_equals(ADMIN_PASS, (string)$pass);
}
function rotator_auth_save($user, $pass) {
    $d = ['user' => $user, 'hash' => password_hash($pass, PASSWORD_DEFAULT)];
    return @file_put_contents(rotator_auth_path(), json_encode($d), LOCK_EX) !== false;
}

function is_logged_in() {
    return !empty($_SESSION['rotator_admin']);
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf() {
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}

$error  = '';
$notice = '';
$action = $_POST['action'] ?? '';

// ---- Logout ----
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ---- Login ----
if ($action === 'login') {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if (rotator_auth_verify($u, $p)) {
        session_regenerate_id(true);
        $_SESSION['rotator_admin'] = true;
        $_SESSION['rotator_user'] = $u;
    } else {
        usleep(400000); // small delay on failure
        $error = 'Invalid username or password.';
    }
}

// ---- Update admin account ----
if ($action === 'account' && is_logged_in()) {
    if (!check_csrf()) {
        $error = 'Session expired, please try again.';
    } else {
        $cur     = $_POST['current'] ?? '';
        $newUser = trim($_POST['new_user'] ?? '');
        $newPass = (string)($_POST['new_pass'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        if (!rotator_auth_verify(rotator_auth_user(), $cur)) {
            $error = 'Current password is incorrect.';
        } elseif ($newUser === '') {
            $error = 'Username cannot be empty.';
        } elseif (strlen($newPass) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($newPass !== $confirm) {
            $error = 'New passwords do not match.';
        } elseif (rotator_auth_save($newUser, $newPass)) {
            $_SESSION['rotator_user'] = $newUser;
            $notice = 'Account updated. Use your new username and password next time you log in.';
        } else {
            $error = 'Could not save account file (check permissions on the site root folder).';
        }
    }
}

// ---- Save rules ----
if ($action === 'save' && is_logged_in()) {
    if (!check_csrf()) {
        $error = 'Session expired, please try again.';
    } else {
        $payload = json_decode($_POST['payload'] ?? '[]', true);
        $rules = [];
        if (is_array($payload)) {
            foreach ($payload as $r) {
                $label   = trim((string)($r['label'] ?? ''));
                $hostsIn = (string)($r['hosts'] ?? '');
                $tgtIn   = (string)($r['targets'] ?? '');
                $enabled = !empty($r['enabled']);

                $hosts = [];
                foreach (preg_split('/[\s,]+/', $hostsIn) as $h) {
                    $h = rotator_norm_host($h);
                    if ($h !== '') $hosts[] = $h;
                }
                $targets = [];
                foreach (preg_split('/[\s,]+/', $tgtIn) as $t) {
                    $t = rotator_norm_url($t);
                    if ($t !== '') $targets[] = $t;
                }
                if ($label === '' && !$hosts && !$targets) continue;
                $rules[] = [
                    'id'      => $r['id'] ?? uniqid('r'),
                    'label'   => $label !== '' ? $label : 'Untitled',
                    'slug'    => rotator_slug($label !== '' ? $label : 'Untitled'),
                    'hosts'   => $hosts,
                    'targets' => $targets,
                    'enabled' => $enabled,
                ];
            }
        }
        if (rotator_save(['rules' => $rules])) {
            $notice = 'Saved successfully.';
        } else {
            $error = 'Could not write data file. Check folder permissions (the web root parent must be writable by the web user).';
        }
    }
}

// ---- Save global domain pool ----
if ($action === 'pool_save' && is_logged_in()) {
    if (!check_csrf()) {
        $error = 'Session expired, please try again.';
    } else {
        $raw_urls = preg_split('/[\r\n]+/', $_POST['pool_urls'] ?? '');
        if (rotator_pool_save($raw_urls)) {
            $notice = 'Domain pool saved.';
        } else {
            $error = 'Could not save domain pool (check folder permissions).';
        }
    }
}

// ---- Save Telegram config ----
if ($action === 'tg_save' && is_logged_in()) {
    if (!check_csrf()) {
        $error = 'Session expired, please try again.';
    } else {
        $tgData = [
            'token'   => trim($_POST['tg_token']   ?? ''),
            'chat_id' => trim($_POST['tg_chat_id'] ?? ''),
            'secret'  => trim($_POST['tg_secret']  ?? ''),
        ];
        if (tg_config_save($tgData)) {
            $notice = 'Telegram settings saved.';
        } else {
            $error = 'Could not save Telegram config (check folder permissions).';
        }
    }
}

// ---- Read Telegram webhook log (AJAX) ----
if ($action === 'tg_log' && is_logged_in()) {
    header('Content-Type: application/json');
    $logPath = __DIR__ . '/../tg-webhook.log';
    if (!file_exists($logPath)) { echo json_encode(['lines' => [], 'exists' => false]); exit; }
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) { echo json_encode(['lines' => [], 'exists' => true]); exit; }
    $lines   = array_reverse(array_slice($lines, -100));
    $parsed  = [];
    foreach ($lines as $line) {
        $parts    = explode("\t", $line, 5);
        $parsed[] = [
            'time'   => $parts[0] ?? '',
            'chatId' => str_replace('chat_id=', '', $parts[1] ?? ''),
            'domain' => $parts[2] ?? '',
            'count'  => $parts[3] ?? '',
            'text'   => isset($parts[4]) ? mb_substr($parts[4], 0, 140) : '',
        ];
    }
    echo json_encode(['lines' => $parsed, 'exists' => true]);
    exit;
}

$data    = rotator_load();
$rules   = $data['rules'];
$tgCfg   = tg_config_load();

// Seed the three brands the first time (when there is no data yet).
if (empty($rules)) {
    $rules = [
        ['id' => 'gold888',    'label' => 'Gold888',    'slug' => 'gold888',    'hosts' => [], 'targets' => [], 'enabled' => true],
        ['id' => 'polaslot88', 'label' => 'Polaslot88', 'slug' => 'polaslot88', 'hosts' => [], 'targets' => [], 'enabled' => true],
        ['id' => 'wings365',   'label' => 'Wings365',   'slug' => 'wings365',   'hosts' => [], 'targets' => [], 'enabled' => true],
    ];
}

$pool = rotator_pool_load();
$token = csrf_token();
$current_user = rotator_auth_user();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title>Rotator Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
<script src="tornado.js"></script>
<style>
  #tornado-bg {
    position: fixed; inset: 0; width: 100%; height: 100%; z-index: 0;
    pointer-events: none; background: #11143C;
    transition: opacity 0.35s ease;
  }
  [data-theme="light"] #tornado-bg {
    opacity: 0.22;
  }
  /* ── Design Tokens & System ── */
  :root {
    color-scheme: dark;
    --font: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --font-mono: 'JetBrains Mono', 'SF Mono', ui-monospace, Menlo, Monaco, Consolas, monospace;

    /* Obsidian Night Theme */
    --bg: #090d16;
    --bg-subtle: #0f172a;
    --surface: rgba(15, 23, 42, 0.75);
    --surface-solid: #0f172a;
    --surface-2: rgba(26, 38, 66, 0.65);
    --surface-3: rgba(30, 46, 80, 0.7);
    --surface-hover: rgba(34, 52, 90, 0.6);
    
    --border: rgba(255, 255, 255, 0.08);
    --border-soft: rgba(255, 255, 255, 0.05);
    --border-accent: rgba(99, 140, 255, 0.35);
    --border-glow: rgba(99, 140, 255, 0.18);

    --text: #f8fafc;
    --text-2: #cbd5e1;
    --text-3: #94a3b8;
    --muted: #64748b;
    --muted-2: #475569;

    --accent: #3b82f6;
    --accent-2: #60a5fa;
    --accent-gradient: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%);
    --accent-fg: #ffffff;
    --accent-glow: rgba(59, 130, 246, 0.28);
    --accent-subtle: rgba(59, 130, 246, 0.12);

    --input-bg: rgba(11, 18, 33, 0.85);
    --input-border: rgba(255, 255, 255, 0.1);
    --input-focus: rgba(59, 130, 246, 0.3);

    --ok: #10b981;
    --ok-bg: rgba(16, 185, 129, 0.12);
    --ok-border: rgba(16, 185, 129, 0.28);
    --ok-glow: rgba(16, 185, 129, 0.25);
    --ok-msg-bg: rgba(16, 185, 129, 0.1);
    --ok-msg-border: rgba(16, 185, 129, 0.28);

    --err-bg: rgba(239, 68, 68, 0.1);
    --err-border: rgba(239, 68, 68, 0.28);
    --danger: #ef4444;
    --danger-fg: #fca5a5;
    --danger-bg: rgba(239, 68, 68, 0.12);
    --danger-glow: rgba(239, 68, 68, 0.22);
    --danger-solid: #dc2626;

    --warn: #f59e0b;
    --badge-blk-bg: rgba(239, 68, 68, 0.14);
    --badge-blk-fg: #fca5a5;
    --badge-wait-bg: rgba(100, 116, 139, 0.18);
    --badge-wait-fg: #94a3b8;
    --badge-use-bg: rgba(59, 130, 246, 0.16);
    --badge-use-fg: #93c5fd;

    --ghost-bg: rgba(255, 255, 255, 0.05);
    --ghost-fg: #cbd5e1;
    --ghost-border: rgba(255, 255, 255, 0.09);

    --card-shadow: 0 12px 36px -8px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.06);
    --panel-glow: 0 0 30px rgba(59, 130, 246, 0.06);
  }

  :root[data-theme="light"] {
    color-scheme: light;
    --bg: #f8fafc;
    --bg-subtle: #f1f5f9;
    --surface: rgba(255, 255, 255, 0.88);
    --surface-solid: #ffffff;
    --surface-2: rgba(248, 250, 252, 0.95);
    --surface-3: rgba(241, 245, 249, 0.95);
    --surface-hover: rgba(241, 245, 249, 0.85);

    --border: rgba(226, 232, 240, 0.9);
    --border-soft: rgba(241, 245, 249, 0.9);
    --border-accent: rgba(37, 99, 235, 0.35);
    --border-glow: rgba(37, 99, 235, 0.15);

    --text: #0f172a;
    --text-2: #334155;
    --text-3: #64748b;
    --muted: #94a3b8;
    --muted-2: #64748b;

    --accent: #2563eb;
    --accent-2: #3b82f6;
    --accent-gradient: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
    --accent-fg: #ffffff;
    --accent-glow: rgba(37, 99, 235, 0.22);
    --accent-subtle: rgba(37, 99, 235, 0.08);

    --input-bg: #ffffff;
    --input-border: #cbd5e1;
    --input-focus: rgba(37, 99, 235, 0.25);

    --ok: #059669;
    --ok-bg: rgba(5, 150, 105, 0.08);
    --ok-border: rgba(5, 150, 105, 0.25);
    --ok-glow: rgba(5, 150, 105, 0.15);
    --ok-msg-bg: rgba(5, 150, 105, 0.08);
    --ok-msg-border: rgba(5, 150, 105, 0.25);

    --err-bg: rgba(220, 38, 38, 0.08);
    --err-border: rgba(220, 38, 38, 0.25);
    --danger: #dc2626;
    --danger-fg: #991b1b;
    --danger-bg: rgba(220, 38, 38, 0.08);
    --danger-glow: rgba(220, 38, 38, 0.15);
    --danger-solid: #dc2626;

    --warn: #d97706;
    --badge-blk-bg: rgba(220, 38, 38, 0.08);
    --badge-blk-fg: #b91c1c;
    --badge-wait-bg: rgba(148, 163, 184, 0.14);
    --badge-wait-fg: #475569;
    --badge-use-bg: rgba(37, 99, 235, 0.08);
    --badge-use-fg: #1d4ed8;

    --ghost-bg: rgba(241, 245, 249, 0.85);
    --ghost-fg: #334155;
    --ghost-border: rgba(203, 213, 225, 0.7);

    --card-shadow: 0 12px 30px -6px rgba(15, 23, 42, 0.07), 0 0 0 1px rgba(226, 232, 240, 0.8);
    --panel-glow: 0 0 30px rgba(37, 99, 235, 0.04);
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: var(--font); background: var(--bg); color: var(--text);
    min-height: 100vh; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
  }

  /* ── Ambient Background Mesh ── */
  .bg-mesh {
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background:
      radial-gradient(circle 800px at 10% -10%, rgba(37, 99, 235, 0.14), transparent),
      radial-gradient(circle 700px at 90% 110%, rgba(99, 102, 241, 0.1), transparent),
      radial-gradient(circle 500px at 50% 50%, rgba(16, 185, 129, 0.03), transparent),
      var(--bg);
  }
  [data-theme="light"] .bg-mesh {
    background:
      radial-gradient(circle 800px at 10% -10%, rgba(191, 219, 254, 0.6), transparent),
      radial-gradient(circle 700px at 90% 110%, rgba(224, 231, 255, 0.5), transparent),
      var(--bg);
  }
  .page { position: relative; z-index: 1; min-height: 100vh; }

  /* ── Top Navigation Bar ── */
  .topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 28px; height: 62px;
    background: var(--surface); border-bottom: 1px solid var(--border);
    backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  }
  .topbar-brand { display: flex; align-items: center; gap: 12px; }
  .brand-icon {
    width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
    background: var(--accent-gradient); color: #fff;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 10px var(--accent-glow);
  }
  .brand-info { display: flex; flex-direction: column; }
  .topbar-brand h1 { font-size: 0.98rem; font-weight: 700; letter-spacing: -0.015em; line-height: 1.2; }
  .brand-sub { font-size: 0.68rem; color: var(--muted); font-weight: 500; letter-spacing: 0.02em; }
  
  .live-pill {
    display: inline-flex; align-items: center; gap: 6px; font-size: 0.68rem;
    font-weight: 700; letter-spacing: 0.06em; color: var(--ok);
    background: var(--ok-bg); border: 1px solid var(--ok-border);
    padding: 3px 10px; border-radius: 9999px; margin-left: 6px;
    box-shadow: 0 0 12px var(--ok-glow);
  }
  .live-dot-pulse {
    width: 6px; height: 6px; border-radius: 50%; background: var(--ok);
    box-shadow: 0 0 0 0 var(--ok-glow);
    animation: pulseGreen 2.2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  }
  @keyframes pulseGreen {
    0% { box-shadow: 0 0 0 0 var(--ok-glow); }
    70% { box-shadow: 0 0 0 6px transparent; }
    100% { box-shadow: 0 0 0 0 transparent; }
  }

  .topbar-right { display: flex; align-items: center; gap: 10px; }
  .user-chip {
    font-size: 0.78rem; color: var(--text-2); padding: 6px 12px;
    background: var(--ghost-bg); border: 1px solid var(--ghost-border);
    border-radius: 9999px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;
    white-space: nowrap;
  }
  .user-chip svg { color: var(--muted); }

  /* ── Modern Buttons ── */
  button {
    cursor: pointer; border: none; border-radius: 10px; padding: 9px 16px;
    font-family: var(--font); font-weight: 600; font-size: 0.85rem;
    transition: all 0.16s ease; display: inline-flex; align-items: center; gap: 7px;
    line-height: 1; user-select: none;
  }
  button:active { transform: translateY(1px); }
  
  .btn-primary {
    background: var(--accent-gradient); color: var(--accent-fg);
    box-shadow: 0 2px 10px var(--accent-glow), inset 0 1px 0 rgba(255, 255, 255, 0.2);
  }
  .btn-primary:hover {
    box-shadow: 0 4px 18px var(--accent-glow), inset 0 1px 0 rgba(255, 255, 255, 0.25);
    filter: brightness(1.06); transform: translateY(-1px);
  }
  .btn-ghost {
    background: var(--ghost-bg); color: var(--ghost-fg); border: 1px solid var(--ghost-border);
  }
  .btn-ghost:hover {
    background: var(--surface-hover); border-color: var(--border-accent);
    color: var(--text); transform: translateY(-1px);
  }
  .btn-danger {
    background: var(--danger-bg); color: var(--danger-fg);
    border: 1px solid rgba(239, 68, 68, 0.25);
  }
  .btn-danger:hover {
    background: rgba(239, 68, 68, 0.22); color: #fff;
    border-color: rgba(239, 68, 68, 0.4);
  }
  .btn-sm { padding: 6px 12px; font-size: 0.78rem; border-radius: 8px; }
  
  /* Loading spinner inside button */
  .btn-spinner {
    width: 13px; height: 13px; border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: currentColor; border-radius: 50%;
    animation: spin 0.65s linear infinite; display: none; flex-shrink: 0;
  }
  button.loading .btn-spinner { display: inline-block; }
  button.loading .btn-label   { opacity: 0.7; }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* ── Flash Messages ── */
  .msg {
    padding: 12px 18px; border-radius: 12px; font-size: 0.86rem; font-weight: 500;
    margin: 0 0 20px; display: flex; align-items: center; gap: 10px;
    animation: slideDown 0.25s ease; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
  }
  @keyframes slideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .msg-ok  { background: var(--ok-msg-bg); border: 1px solid var(--ok-msg-border); color: var(--ok); }
  .msg-err { background: var(--err-bg); border: 1px solid var(--err-border); color: var(--danger-fg); }

  /* ── OriginKit Pulsating Border Keyframes ── */
  @property --pulse-angle {
    syntax: '<angle>';
    initial-value: 0deg;
    inherits: false;
  }

  @keyframes pulseBorderRotate {
    0%   { --pulse-angle: 0deg; filter: hue-rotate(0deg); }
    100% { --pulse-angle: 360deg; filter: hue-rotate(360deg); }
  }

  @keyframes pulseBorderGlow {
    0%, 100% {
      opacity: 0.45;
      filter: blur(18px) saturate(1.4);
      transform: scale(0.995);
    }
    50% {
      opacity: 0.85;
      filter: blur(28px) saturate(1.9);
      transform: scale(1.015);
    }
  }

  /* OriginKit Pulsating Border for Card Frames */
  .login-card, .rule-card, .pool-card, .account-card {
    position: relative;
    z-index: 1;
  }
  .login-card > *, .rule-card > *, .pool-card > *, .account-card > * {
    position: relative;
    z-index: 3;
  }

  /* Outer Pulsating Bloom / Smoke strictly at the edge */
  .login-card::before, .rule-card::before, .pool-card::before, .account-card::before {
    content: '';
    position: absolute;
    inset: -12px;
    border-radius: inherit;
    padding: 14px;
    background: conic-gradient(
      from var(--pulse-angle, 0deg),
      #F2244F 0%,
      transparent 15%,
      #4DA6E6 33%,
      transparent 48%,
      #379590 66%,
      transparent 82%,
      #F2244F 100%
    );
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    mask-composite: exclude;
    filter: blur(10px);
    opacity: 0.7;
    z-index: -2;
    pointer-events: none;
    animation: pulseBorderRotate 6s linear infinite, pulseBorderGlow 4s ease-in-out infinite;
  }

  /* Crisp Pulsating Border Edge Line */
  .login-card::after, .rule-card::after, .pool-card::after, .account-card::after {
    content: '';
    position: absolute;
    inset: -1.5px;
    border-radius: inherit;
    padding: 2px;
    background: conic-gradient(
      from var(--pulse-angle, 0deg),
      #F2244F 0%,
      transparent 18%,
      #4DA6E6 33%,
      transparent 51%,
      #379590 66%,
      transparent 84%,
      #F2244F 100%
    );
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    mask-composite: exclude;
    z-index: 2;
    pointer-events: none;
    animation: pulseBorderRotate 6s linear infinite;
  }

  /* ── Login Screen ── */
  .login-wrap { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
  .login-card {
    width: 100%; max-width: 380px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 22px;
    padding: 36px 32px; backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
    box-shadow: var(--card-shadow);
    animation: fadeUp 0.35s cubic-bezier(0.16, 1, 0.3, 1);
  }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(18px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
  }
  .login-icon {
    width: 54px; height: 54px; border-radius: 16px; margin: 0 auto 20px;
    background: var(--accent-gradient); color: #fff;
    display: flex; align-items: center; justify-content: center; font-size: 26px;
    box-shadow: 0 6px 20px var(--accent-glow);
  }
  .login-title { font-size: 1.35rem; font-weight: 700; text-align: center; margin-bottom: 6px; letter-spacing: -0.02em; }
  .login-sub   { font-size: 0.83rem; color: var(--text-3); text-align: center; margin-bottom: 24px; line-height: 1.5; }
  .login-theme-btn { position: fixed; top: 18px; right: 18px; z-index: 999; }

  /* ── Form Fields ── */
  .field { margin-bottom: 16px; }
  .field-label {
    display: block; font-size: 0.72rem; font-weight: 700; color: var(--text-3);
    text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 7px;
  }
  input[type=text], input[type=password], textarea {
    width: 100%; padding: 10px 14px; border-radius: 10px;
    border: 1px solid var(--input-border); background: var(--input-bg);
    color: var(--text); font-size: 0.88rem; font-family: var(--font);
    transition: border-color 0.16s, box-shadow 0.16s, background-color 0.16s; outline: none;
  }
  input[type=text]:focus, input[type=password]:focus, textarea:focus {
    border-color: var(--accent); box-shadow: 0 0 0 3px var(--input-focus);
    background: var(--surface-solid);
  }
  textarea { resize: vertical; font-family: var(--font-mono); font-size: 0.82rem; line-height: 1.5; }
  textarea.f-hosts   { min-height: 48px; height: 48px; }
  textarea.f-targets { min-height: 180px; }

  /* ── Main Layout ── */
  .main { max-width: 1240px; margin: 0 auto; padding: 28px 24px 80px; }
  .layout {
    display: grid; grid-template-columns: 250px minmax(0, 1fr);
    gap: 24px; align-items: start;
  }
  @media (max-width: 860px) {
    .layout { display: flex; flex-direction: column; }
    .sidebar { position: static !important; width: 100%; flex-basis: auto !important; }
  }

  /* ── Sidebar ── */
  .sidebar {
    position: sticky; top: 82px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 18px;
    padding: 12px; backdrop-filter: blur(16px); box-shadow: var(--card-shadow);
  }
  .side-section-title {
    font-size: 0.66rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.09em; color: var(--muted); padding: 8px 10px 6px;
  }
  .side-item {
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px; padding: 9px 12px; border-radius: 10px; cursor: pointer;
    font-weight: 600; font-size: 0.86rem; color: var(--text-2);
    margin-bottom: 3px; transition: all 0.15s ease; border: 1px solid transparent;
  }
  .side-item:hover { background: var(--surface-hover); color: var(--text); }
  .side-item.active {
    background: var(--accent-subtle); color: var(--accent-2);
    border-color: var(--border-accent);
    box-shadow: 0 2px 10px var(--accent-glow);
  }
  [data-theme="light"] .side-item.active {
    background: rgba(37, 99, 235, 0.08); color: var(--accent);
    border-color: rgba(37, 99, 235, 0.25);
  }
  .side-left { display: flex; align-items: center; gap: 9px; min-width: 0; }
  .side-left .nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .side-icon { flex-shrink: 0; opacity: 0.75; }
  
  .side-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--ok); flex-shrink: 0; }
  .side-item.off  .side-dot { background: var(--muted); opacity: 0.5; }
  .side-item.alert .side-dot { background: var(--danger); animation: pulseRed 1.5s ease-in-out infinite; }
  @keyframes pulseRed {
    0%, 100% { box-shadow: 0 0 0 0 var(--danger-glow); }
    50%      { box-shadow: 0 0 0 4px transparent; }
  }
  .side-item.alert { background: var(--danger-bg); color: var(--danger-fg); border-color: rgba(239, 68, 68, 0.2); }
  .side-item.alert.active { background: var(--danger-solid); color: #fff; }

  .side-add {
    width: 100%; margin-top: 6px; background: transparent; color: var(--text-3);
    border: 1px dashed var(--border); font-size: 0.8rem; padding: 9px;
    border-radius: 10px; justify-content: center;
  }
  .side-add:hover { background: var(--surface-hover); color: var(--text); border-color: var(--accent); }
  .side-sep { height: 1px; background: var(--border-soft); margin: 12px 6px; }
  .side-count {
    font-size: 0.68rem; font-weight: 700; background: var(--ghost-bg);
    color: var(--muted); border: 1px solid var(--ghost-border);
    padding: 2px 8px; border-radius: 9999px; min-width: 22px; text-align: center;
    font-family: var(--font-mono);
  }
  .side-item.active .side-count {
    background: var(--surface-solid); color: var(--accent-2); border-color: var(--border-accent);
  }

  /* ── Editor Container ── */
  .editor { min-width: 0; }
  .panel { display: none; }
  .panel.active { display: block; animation: fadeIn 0.2s ease; }
  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  /* ── Rule Card ── */
  .rule-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
    padding: 26px 28px; backdrop-filter: blur(16px);
    position: relative; box-shadow: var(--card-shadow);
  }
  .rule-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; margin-bottom: 22px; flex-wrap: wrap;
  }
  .rule-head-left  { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
  .rule-head-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
  .f-label {
    font-size: 1.15rem !important; font-weight: 700 !important;
    background: transparent !important; border: 1px solid transparent !important;
    border-radius: 8px !important; padding: 4px 8px !important; max-width: 320px;
    letter-spacing: -0.01em; color: var(--text) !important;
  }
  .f-label:focus {
    background: var(--input-bg) !important; border-color: var(--accent) !important;
    box-shadow: 0 0 0 3px var(--input-focus) !important;
  }

  /* Custom Toggle Switch */
  .toggle-label {
    display: flex; align-items: center; gap: 8px; font-size: 0.8rem;
    font-weight: 600; color: var(--text-2); cursor: pointer; user-select: none;
  }
  .toggle-switch { position: relative; width: 36px; height: 20px; flex-shrink: 0; }
  .toggle-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
  .toggle-track {
    position: absolute; inset: 0; border-radius: 9999px; background: var(--muted);
    transition: background 0.2s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer;
  }
  .toggle-track::after {
    content: ''; position: absolute; width: 16px; height: 16px; border-radius: 50%;
    background: #fff; top: 2px; left: 2px;
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
  }
  .toggle-switch input:checked + .toggle-track { background: var(--accent); }
  .toggle-switch input:checked + .toggle-track::after { transform: translateX(16px); }

  .rule-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
  @media (max-width: 680px) { .rule-cols { grid-template-columns: 1fr; } }
  .col-label {
    display: block; font-size: 0.72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-3); margin-bottom: 7px;
  }
  .hint { font-size: 0.73rem; color: var(--muted); margin-top: 6px; line-height: 1.55; }

  /* ── Player Link Box ── */
  .link-row {
    margin-top: 24px; padding: 18px 20px; border-radius: 14px;
    background: var(--surface-2); border: 1px solid var(--border-soft);
  }
  .link-input-wrap { display: flex; gap: 8px; max-width: 620px; margin-top: 8px; position: relative; }
  .link-prefix-icon {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    color: var(--muted); pointer-events: none;
  }
  .f-link {
    flex: 1; background: var(--input-bg); border: 1px solid var(--input-border);
    border-radius: 10px; padding: 10px 14px 10px 36px; font-size: 0.82rem; cursor: text;
    font-family: var(--font-mono); color: var(--text-2);
  }
  .copy-btn { flex-shrink: 0; }
  .copy-btn.copied {
    background: var(--ok-bg) !important; color: var(--ok) !important;
    border-color: var(--ok-border) !important;
  }

  /* ── Live Status Monitoring Section ── */
  .status-section {
    margin-top: 26px; border-top: 1px solid var(--border-soft); padding-top: 22px;
  }
  .status-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
  .status-title {
    font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--text-3); display: flex; align-items: center; gap: 8px;
  }
  .live-blink {
    width: 7px; height: 7px; border-radius: 50%; background: var(--accent);
    animation: pulseBlue 2s ease-in-out infinite;
  }
  @keyframes pulseBlue {
    0%, 100% { box-shadow: 0 0 0 0 var(--accent-glow); }
    50%      { box-shadow: 0 0 0 5px transparent; }
  }
  .status-list { display: flex; flex-direction: column; gap: 7px; }
  .st-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 11px 14px; border-radius: 12px;
    background: var(--surface-2); border: 1px solid var(--border-soft);
    transition: all 0.16s ease;
  }
  .st-row:hover { border-color: var(--border-accent); background: var(--surface-hover); }
  .st-left { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
  .st-mid { flex: 1 1 auto; min-width: 0; }
  .st-url { font-size: 0.84rem; color: var(--text); word-break: break-all; font-weight: 600; font-family: var(--font-mono); }
  .st-reason { font-size: 0.72rem; color: var(--muted); margin-top: 2px; }
  .st-when { font-size: 0.7rem; color: var(--muted); flex-shrink: 0; font-family: var(--font-mono); }
  .status-legend { font-size: 0.71rem; color: var(--muted); margin-top: 10px; line-height: 1.6; }

  /* ── Status Badges ── */
  .badge {
    font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em;
    padding: 4px 10px; border-radius: 9999px; flex-shrink: 0; white-space: nowrap;
    display: inline-flex; align-items: center; gap: 5px;
  }
  .badge-clean   { background: var(--ok-bg); color: var(--ok); border: 1px solid var(--ok-border); box-shadow: 0 0 10px var(--ok-glow); }
  .badge-blocked { background: var(--badge-blk-bg); color: var(--badge-blk-fg); border: 1px solid rgba(239, 68, 68, 0.3); box-shadow: 0 0 10px var(--danger-glow); }
  .badge-down    { background: var(--badge-wait-bg); color: var(--badge-wait-fg); border: 1px solid rgba(148, 163, 184, 0.2); }
  .badge-wait    { background: var(--badge-wait-bg); color: var(--badge-wait-fg); border: 1px solid rgba(148, 163, 184, 0.2); }
  .badge-inuse   {
    background: var(--badge-use-bg); color: var(--badge-use-fg);
    border: 1px solid rgba(59, 130, 246, 0.35); box-shadow: 0 0 12px var(--accent-glow);
  }
  .badge-inuse::before {
    content: ''; width: 5px; height: 5px; border-radius: 50%; background: var(--accent-2);
    flex-shrink: 0; animation: pulseBlue 1.6s ease-in-out infinite;
  }

  /* ── Action Bar ── */
  .action-bar { display: flex; gap: 10px; margin-top: 24px; flex-wrap: wrap; }

  /* ── Draggable Target List ── */
  .tgt-list { display: flex; flex-direction: column; gap: 7px; margin-bottom: 8px; min-height: 32px; }
  .tgt-row {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 10px; border-radius: 10px; background: var(--surface-2);
    border: 1px solid var(--border-soft); transition: all 0.16s ease;
  }
  .tgt-row:hover { border-color: var(--border-accent); }
  .tgt-row.drag-over { border-color: var(--accent); background: var(--accent-subtle); }
  .tgt-drag { cursor: grab; color: var(--muted); font-size: 0.95rem; padding: 4px 3px; user-select: none; flex-shrink: 0; }
  .tgt-drag:active { cursor: grabbing; }
  .tgt-num { font-size: 0.68rem; font-weight: 700; color: var(--muted); width: 20px; text-align: right; flex-shrink: 0; font-family: var(--font-mono); }
  .tgt-input {
    flex: 1; border-radius: 8px !important; padding: 6px 10px !important;
    font-size: 0.82rem !important; font-family: var(--font-mono) !important;
    border-color: transparent !important; background: transparent !important;
  }
  .tgt-input:focus { border-color: var(--accent) !important; background: var(--input-bg) !important; }
  .tgt-remove { flex-shrink: 0; padding: 4px 8px !important; }
  .tgt-empty { font-size: 0.8rem; color: var(--muted); padding: 12px 0; text-align: center; }
  .tgt-add-btn {
    width: 100%; background: transparent; color: var(--text-3); border: 1px dashed var(--border);
    font-size: 0.8rem; padding: 8px; border-radius: 9px; justify-content: center; margin-top: 4px;
  }
  .tgt-add-btn:hover { background: var(--surface-hover); color: var(--text); border-color: var(--accent); }

  /* ── Global Domain Pool Panel ── */
  .pool-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
    padding: 26px 28px; backdrop-filter: blur(16px); position: relative;
    box-shadow: var(--card-shadow);
  }
  .pool-title { font-size: 1.15rem; font-weight: 700; margin-bottom: 4px; letter-spacing: -0.01em; display: flex; align-items: center; gap: 8px; }
  .pool-sub   { font-size: 0.82rem; color: var(--text-3); margin-bottom: 22px; }
  .pool-list  { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
  .pool-row   {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 10px; border-radius: 10px; background: var(--surface-2);
    border: 1px solid var(--border-soft); transition: all 0.16s ease;
  }
  .pool-row:hover { border-color: var(--border-accent); }
  .pool-row.drag-over { border-color: var(--accent); background: var(--accent-subtle); }
  .pool-drag  { cursor: grab; color: var(--muted); font-size: 1rem; padding: 4px 3px; user-select: none; flex-shrink: 0; }
  .pool-drag:active { cursor: grabbing; }
  .pool-num   { font-size: 0.7rem; font-weight: 700; color: var(--muted); width: 22px; text-align: right; flex-shrink: 0; font-family: var(--font-mono); }
  .pool-input {
    flex: 1; border-radius: 8px !important; padding: 7px 10px !important;
    font-size: 0.84rem !important; font-family: var(--font-mono);
  }
  .pool-remove { flex-shrink: 0; }
  .pool-empty { text-align: center; padding: 28px 0; color: var(--muted); font-size: 0.85rem; }
  .pool-add-btn {
    width: 100%; background: transparent; color: var(--text-3); border: 1px dashed var(--border);
    font-size: 0.82rem; padding: 10px; border-radius: 10px; justify-content: center;
  }
  .pool-add-btn:hover { background: var(--surface-hover); color: var(--text); border-color: var(--accent); }

  /* ── Account Panel ── */
  .account-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
    padding: 26px 28px; backdrop-filter: blur(16px); box-shadow: var(--card-shadow);
  }
  .account-title { font-size: 1.15rem; font-weight: 700; margin-bottom: 4px; letter-spacing: -0.01em; }
  .account-sub   { font-size: 0.82rem; color: var(--text-3); margin-bottom: 22px; }

  /* ── Telegram Panel & Log Table ── */
  .tg-log-wrap { margin-top: 24px; border-top: 1px solid var(--border-soft); padding-top: 20px; }
  .tg-log-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
  .tg-log-title {
    font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--text-3); display: flex; align-items: center; gap: 8px;
  }
  .tg-log-table-wrap {
    border-radius: 12px; overflow: hidden;
    border: 1px solid var(--border-soft); background: var(--surface-2);
  }
  .tg-log-table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
  .tg-log-table th {
    padding: 10px 14px; text-align: left; font-size: 0.68rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted);
    border-bottom: 1px solid var(--border-soft); background: rgba(0, 0, 0, 0.1);
  }
  .tg-log-table td {
    padding: 10px 14px; border-bottom: 1px solid var(--border-soft);
    color: var(--text-2); vertical-align: middle;
  }
  .tg-log-table tr:last-child td { border-bottom: none; }
  .tg-log-table tr:hover td { background: var(--accent-subtle); }
  .tg-log-domain { font-weight: 600; color: var(--accent-2); font-family: var(--font-mono); }
  .tg-log-nodomain { color: var(--muted); font-style: italic; }
  .tg-log-count {
    font-weight: 700; color: var(--ok); background: var(--ok-bg);
    border: 1px solid var(--ok-border); padding: 2px 7px; border-radius: 9999px;
    font-size: 0.72rem; font-family: var(--font-mono); white-space: nowrap;
  }
  .tg-log-count.zero { color: var(--muted); background: transparent; border-color: transparent; }
  .tg-log-time { color: var(--muted); font-family: var(--font-mono); font-size: 0.72rem; white-space: nowrap; }
  .tg-log-empty { text-align: center; padding: 32px 0; color: var(--muted); font-size: 0.85rem; }
  .tg-log-loading { text-align: center; padding: 24px 0; color: var(--muted); font-size: 0.82rem; }
</style>
<script>
  // Apply saved theme before first paint
  (function () {
    var t;
    try { t = localStorage.getItem('rotator_theme'); } catch (e) {}
    if (t !== 'light' && t !== 'dark') {
      t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
    }
    document.documentElement.setAttribute('data-theme', t);
  })();
</script>
</head>
<body>
<div id="tornado-bg">
  <canvas id="tornado-canvas"></canvas>
</div>
<div class="page">

<?php if (!is_logged_in()): ?>
  <button type="button" class="btn-ghost btn-sm theme-btn login-theme-btn" data-theme-toggle>
    <span class="ico"></span><span class="txt"></span>
  </button>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-icon">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
      </div>
      <h1 class="login-title">Rotator Admin</h1>
      <p class="login-sub">Sign in to manage your high-availability redirect rules</p>
      <?php if ($error): ?><div class="msg msg-err"><span>⚠&nbsp;</span><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="login" />
        <div class="field">
          <label class="field-label" for="f-user">Username</label>
          <input type="text" id="f-user" name="username" autocomplete="username" autofocus />
        </div>
        <div class="field">
          <label class="field-label" for="f-pass">Password</label>
          <input type="password" id="f-pass" name="password" autocomplete="current-password" />
        </div>
        <div style="margin-top:22px;">
          <button class="btn-primary" style="width:100%;justify-content:center;" type="submit">
            <span class="btn-label">Sign In &rarr;</span>
          </button>
        </div>
      </form>
    </div>
  </div>

<?php else: ?>
  <header class="topbar">
    <div class="topbar-brand">
      <div class="brand-icon">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
      </div>
      <div class="brand-info">
        <h1>Rotator Admin</h1>
        <span class="brand-sub">Traffic Distribution &amp; Failover</span>
      </div>
      <div class="live-pill"><span class="live-dot-pulse"></span>LIVE</div>
    </div>
    <div class="topbar-right">
      <span class="user-chip">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span><?php echo htmlspecialchars($current_user); ?></span>
      </span>
      <button type="button" class="btn-ghost btn-sm theme-btn" data-theme-toggle>
        <span class="ico"></span><span class="txt"></span>
      </button>
      <form method="post" style="margin:0;">
        <input type="hidden" name="action" value="logout" />
        <button class="btn-ghost btn-sm" type="submit">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Log out</span>
        </button>
      </form>
    </div>
  </header>

  <main class="main">
    <?php if ($error): ?><div class="msg msg-err"><span>⚠&nbsp;</span><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="msg msg-ok"><span>✓&nbsp;</span><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

    <div class="layout">
      <aside class="sidebar">
        <div class="side-section-title">Brands</div>
        <div id="sideList"></div>
        <button type="button" class="btn-ghost side-add" id="addRule">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <span>Add brand</span>
        </button>
        <div class="side-sep"></div>
        <div class="side-section-title">Settings</div>
        <div class="side-item" id="sideDomainPool">
          <span class="side-left">
            <svg class="side-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            <span>Domain Pool</span>
          </span>
          <span class="side-count" id="poolCount"><?php echo count($pool); ?></span>
        </div>
        <div class="side-item" id="sideTelegram">
          <span class="side-left">
            <svg class="side-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            <span>Telegram</span>
          </span>
        </div>
        <div class="side-item" id="sideAccount">
          <span class="side-left">
            <svg class="side-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <span>Account</span>
          </span>
        </div>
      </aside>

      <section class="editor">
        <form method="post" id="rulesForm">
          <input type="hidden" name="action" value="save" />
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>" />
          <input type="hidden" name="payload" id="payload" />
          <div id="panels"></div>
          <div class="action-bar">
            <button type="submit" class="btn-primary" id="saveBtn">
              <span class="btn-spinner"></span>
              <span class="btn-label">&#128190; Save all</span>
            </button>
            <button type="button" class="btn-ghost" id="checkBtn">
              <span class="btn-spinner"></span>
              <span class="btn-label">&#128269; Run check now</span>
            </button>
            <button type="button" class="btn-ghost" id="refreshBtn">
              <span class="btn-label">&#8635; Refresh status</span>
            </button>
          </div>
        </form>

        <form method="post" id="accountForm" class="panel" data-key="account">
          <input type="hidden" name="action" value="account" />
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>" />
          <div class="account-card">
            <h2 class="account-title">Admin Account</h2>
            <p class="account-sub">Change the username and password you use to log in to this panel.</p>
            <div class="field">
              <label class="field-label">Username</label>
              <input type="text" name="new_user" value="<?php echo htmlspecialchars($current_user); ?>" style="max-width:340px;" autocomplete="username" />
            </div>
            <div class="field">
              <label class="field-label">Current password</label>
              <input type="password" name="current" style="max-width:340px;" autocomplete="current-password" />
            </div>
            <div class="field">
              <label class="field-label">New password (min 6 chars)</label>
              <input type="password" name="new_pass" style="max-width:340px;" autocomplete="new-password" />
            </div>
            <div class="field">
              <label class="field-label">Confirm new password</label>
              <input type="password" name="confirm" style="max-width:340px;" autocomplete="new-password" />
            </div>
            <div class="action-bar">
              <button type="submit" class="btn-primary">Update account</button>
            </div>
          </div>
        </form>

        <form method="post" id="tgForm" class="panel" data-key="telegram">
          <input type="hidden" name="action" value="tg_save" />
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>" />
          <div class="account-card">
            <h2 class="account-title">✈️ Telegram Block Alerts</h2>
            <p class="account-sub">When a domain block alert arrives in your Telegram group, the rotator instantly skips that domain for all visitors.</p>

            <div class="field">
              <label class="field-label" for="tg_token">Bot Token</label>
              <input type="text" id="tg_token" name="tg_token"
                value="<?php echo htmlspecialchars($tgCfg['token']); ?>"
                placeholder="7123456789:AABBcc..."
                style="max-width:460px;font-family:var(--font-mono);font-size:.83rem;" />
              <div class="hint">Get this from @BotFather &rarr; /newbot. Looks like <code>1234567890:AABBcc...</code></div>
            </div>

            <div class="field">
              <label class="field-label" for="tg_chat_id">Group Chat ID</label>
              <input type="text" id="tg_chat_id" name="tg_chat_id"
                value="<?php echo htmlspecialchars($tgCfg['chat_id']); ?>"
                placeholder="-1001234567890"
                style="max-width:240px;font-family:var(--font-mono);font-size:.83rem;" />
              <div class="hint">Negative number for groups/channels (e.g. <code>-1001234567890</code>). Leave blank to accept any group &mdash; the Chat ID will appear in your log after the first message.</div>
            </div>

            <div class="field">
              <label class="field-label" for="tg_secret">Webhook Secret</label>
              <input type="text" id="tg_secret" name="tg_secret"
                value="<?php echo htmlspecialchars($tgCfg['secret']); ?>"
                placeholder="any-random-string-you-invent"
                style="max-width:340px;font-family:var(--font-mono);font-size:.83rem;" />
              <div class="hint">Any random string. Keeps strangers from calling your webhook. You invent it &mdash; just keep it consistent.</div>
            </div>

            <div class="action-bar" style="flex-wrap:wrap;gap:10px;">
              <button type="submit" class="btn-primary" id="saveTgBtn">
                <span class="btn-spinner"></span>
                <span class="btn-label">&#128190; Save settings</span>
              </button>
              <button type="button" class="btn-ghost" id="registerWebhookBtn">
                <span class="btn-spinner"></span>
                <span class="btn-label">&#128279; Register webhook with Telegram</span>
              </button>
            </div>

            <div id="webhookResult" style="margin-top:14px;display:none;"></div>

            <div class="hint" style="margin-top:20px;border-top:1px solid var(--border-soft);padding-top:14px;">
              <strong>How it works:</strong><br>
              1. Fill in the fields above and click <em>Save settings</em>.<br>
              2. Click <em>Register webhook with Telegram</em> once &mdash; this tells Telegram to POST to your server whenever a message arrives in the group.<br>
              3. Done! The next time someone posts a blocked domain alert, the rotator will skip it automatically.
            </div>

            <!-- Telegram Event Log -->
            <div class="tg-log-wrap">
              <div class="tg-log-header">
                <span class="tg-log-title">
                  <span class="live-blink"></span>
                  Telegram Event Log
                </span>
                <button type="button" class="btn-ghost btn-sm" id="refreshTgLogBtn">
                  <span class="btn-spinner"></span>
                  <span class="btn-label">&#8635; Refresh</span>
                </button>
              </div>
              <div class="tg-log-table-wrap">
                <div id="tgLogBody" class="tg-log-loading">Loading…</div>
              </div>
            </div>
          </div>
        </form>

        <form method="post" id="poolForm" class="panel" data-key="pool">
          <input type="hidden" name="action" value="pool_save" />
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>" />
          <input type="hidden" name="pool_urls" id="poolUrls" />
          <div class="pool-card">
            <h2 class="pool-title">&#127760; Global Domain Pool</h2>
            <p class="pool-sub">All your mirror / backup domains in priority order. Visitors are sent to the first working one. Drag rows to reorder.</p>
            <div class="pool-list" id="poolList"></div>
            <button type="button" class="btn-ghost pool-add-btn" id="addPoolDomain">+ Add domain</button>
            <div class="action-bar">
              <button type="submit" class="btn-primary" id="savePoolBtn">
                <span class="btn-spinner"></span>
                <span class="btn-label">&#128190; Save pool</span>
              </button>
            </div>
            <div class="hint" style="margin-top:12px;">These are the CANDIDATES injected into the visitor gateway. The order here is the priority order &mdash; domain #1 is tried first.</div>
          </div>
        </form>
      </section>
    </div>
  </main>

  <template id="ruleTpl">
    <div class="rule-card panel" data-id="">
      <div class="rule-head">
        <div class="rule-head-left">
          <input type="text" class="f-label" placeholder="Brand name" />
        </div>
        <div class="rule-head-right">
          <label class="toggle-label">
            <span class="toggle-switch">
              <input type="checkbox" class="f-enabled" checked />
              <span class="toggle-track"></span>
            </span>
            Enabled
          </label>
          <button type="button" class="btn-danger btn-sm f-remove">Remove</button>
        </div>
      </div>
      <div class="rule-cols">
        <div>
          <span class="col-label">Entry domain (optional)</span>
          <textarea class="f-hosts" placeholder="yourlink.com"></textarea>
          <div class="hint">The stable link you hand to players. Must point to this server. Leave empty to use the auto-generated link below.</div>
        </div>
        <div>
          <span class="col-label">Backup game domains &mdash; priority order</span>
          <!-- hidden textarea keeps the data; the list UI below is the editor -->
          <textarea class="f-targets" style="display:none"></textarea>
          <div class="tgt-list f-tgt-list"></div>
          <button type="button" class="btn-ghost tgt-add-btn f-add-target">+ Add domain</button>
          <div class="hint" style="margin-top:6px;">Players are sent to the first working domain. Blocked ones are skipped automatically. Drag &#8801; to reorder.</div>
        </div>
      </div>
      <div class="link-row">
        <span class="col-label">Player link (share this)</span>
        <div class="link-input-wrap">
          <span class="link-prefix-icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
          </span>
          <input type="text" class="f-link" readonly />
          <button type="button" class="btn-ghost btn-sm copy-btn f-copy">Copy</button>
        </div>
        <div class="hint">Give this link to players for this brand, or point a dedicated entry domain at it.</div>
      </div>
      <div class="status-section">
        <div class="status-header">
          <span class="status-title">
            <span class="live-blink"></span>
            Live status (auto-checked + real visitors)
          </span>
          <button type="button" class="btn-ghost btn-sm f-rotate">&#8635; Force rotate to next</button>
        </div>
        <div class="status-list"></div>
        <div class="status-legend">CLEAN = reachable &middot; BLOCKED = blocked in Indonesia &middot; DOWN = dead/error &middot; IN USE = serving players now. Click &ldquo;Run check now&rdquo; for an instant check.</div>
      </div>
    </div>
  </template>

  <script>
    var INITIAL = <?php echo json_encode(array_map(function($r){
        return [
          'id'      => $r['id'] ?? '',
          'label'   => $r['label'] ?? '',
          'hosts'   => implode("\n", $r['hosts'] ?? []),
          'targets' => implode("\n", $r['targets'] ?? []),
          'enabled' => !empty($r['enabled']),
        ];
    }, $rules), JSON_UNESCAPED_SLASHES); ?>;

    var STATS  = <?php echo json_encode(rotator_stats_load(),  JSON_UNESCAPED_SLASHES); ?>;
    var CHECKS = <?php echo json_encode(rotator_checks_load(), JSON_UNESCAPED_SLASHES); ?>;
    var renderers = [];
    function norm(u){ return String(u||'').trim().replace(/\/+$/,''); }
    function timeAgo(iso){
      if(!iso) return '';
      var t = Date.parse(iso); if(isNaN(t)) return '';
      var s = Math.floor((Date.now()-t)/1000);
      if(s<60) return s+'s ago';
      if(s<3600) return Math.floor(s/60)+'m ago';
      if(s<86400) return Math.floor(s/3600)+'h ago';
      return Math.floor(s/86400)+'d ago';
    }

    var sideList    = document.getElementById('sideList');
    var panels      = document.getElementById('panels');
    var tpl         = document.getElementById('ruleTpl');
    var rulesForm   = document.getElementById('rulesForm');
    var accountForm = document.getElementById('accountForm');
    var tgForm      = document.getElementById('tgForm');
    var sideAccount = document.getElementById('sideAccount');
    var sideTelegram= document.getElementById('sideTelegram');
    var seq = 0;

    function selectPanel(key) {
      var isAccount  = (key === 'account');
      var isTelegram = (key === 'telegram');
      var isSpecial  = isAccount || isTelegram;
      rulesForm.style.display = isSpecial ? 'none' : 'block';
      panels.querySelectorAll('.panel').forEach(function (p) {
        p.classList.toggle('active', !isSpecial && p.dataset.key === key);
      });
      accountForm.classList.toggle('active', isAccount);
      if (tgForm) tgForm.classList.toggle('active', isTelegram);
      sideList.querySelectorAll('.side-item').forEach(function (s) {
        s.classList.toggle('active', s.dataset.key === key);
      });
      sideAccount.classList.toggle('active', isAccount);
      if (sideTelegram) sideTelegram.classList.toggle('active', isTelegram);
    }

    sideAccount.addEventListener('click', function () { selectPanel('account'); });
    if (sideTelegram) sideTelegram.addEventListener('click', function () { selectPanel('telegram'); });

    // ── Register Webhook button ──────────────────────────────────────────
    var regBtn = document.getElementById('registerWebhookBtn');
    if (regBtn) {
      regBtn.addEventListener('click', function () {
        var token  = document.getElementById('tg_token').value.trim();
        var secret = document.getElementById('tg_secret').value.trim();
        var result = document.getElementById('webhookResult');
        if (!token) { alert('Please enter and save your Bot Token first.'); return; }
        regBtn.classList.add('loading');
        regBtn.querySelector('.btn-label').textContent = 'Registering\u2026';
        result.style.display = 'none';
        fetch('tg-webhook.php?setup=1&key=<?php echo urlencode(CHECK_KEY); ?>', { cache: 'no-store' })
          .then(function (r) { return r.text(); })
          .then(function (txt) {
            var ok = txt.indexOf('"ok":true') !== -1;
            result.style.display = 'block';
            result.innerHTML = ok
              ? '<div class="msg msg-ok" style="margin:0;">&#10003;&nbsp; Webhook registered! Telegram will now POST to your server on every group message.</div>'
              : '<div class="msg msg-err" style="margin:0;">&#9888;&nbsp; Unexpected response: <code style="font-size:.78rem;">' + txt.replace(/</g,'&lt;') + '</code></div>';
          })
          .catch(function (e) {
            result.style.display = 'block';
            result.innerHTML = '<div class="msg msg-err" style="margin:0;">&#9888;&nbsp; Request failed: ' + e + '</div>';
          })
          .then(function () {
            regBtn.classList.remove('loading');
            regBtn.querySelector('.btn-label').innerHTML = '&#128279; Register webhook with Telegram';
          });
      });
    }

    var refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) refreshBtn.addEventListener('click', function(){
      var lbl = refreshBtn.querySelector('.btn-label');
      lbl.textContent = 'Refreshing\u2026';
      fetch('status.php', { cache:'no-store' }).then(function(r){ return r.json(); }).then(function(j){
        STATS  = (j && j.stats)  || {};
        CHECKS = (j && j.checks) || {};
        renderers.forEach(function(fn){ fn(); });
      }).catch(function(){}).then(function(){ lbl.innerHTML = '&#8635; Refresh status'; });
    });

    var checkBtn = document.getElementById('checkBtn');
    if (checkBtn) checkBtn.addEventListener('click', function(){
      checkBtn.classList.add('loading');
      var lbl = checkBtn.querySelector('.btn-label');
      lbl.textContent = 'Checking\u2026';
      fetch('checker.php', { cache:'no-store' }).then(function(r){ return r.json(); }).then(function(j){
        CHECKS = j || {};
        renderers.forEach(function(fn){ fn(); });
      }).catch(function(){}).then(function(){
        checkBtn.classList.remove('loading');
        lbl.innerHTML = '&#128269; Run check now';
      });
    });

    // Show save spinner on submit
    document.getElementById('saveBtn') && document.getElementById('rulesForm').addEventListener('submit', function(){
      document.getElementById('saveBtn').classList.add('loading');
    });

    // Auto-dismiss the flash banners after 4 s.
    setTimeout(function(){
      document.querySelectorAll('.msg').forEach(function(m){
        m.style.transition = 'opacity .4s';
        m.style.opacity = '0';
        setTimeout(function(){ if (m.parentNode) m.parentNode.removeChild(m); }, 400);
      });
    }, 4000);

    function addRule(r, select) {
      r = r || { id:'', label:'', hosts:'', targets:'', enabled:true };
      var key = 'k' + (seq++);

      var node = tpl.content.firstElementChild.cloneNode(true);
      node.dataset.id  = r.id || '';
      node.dataset.key = key;
      var labelInput   = node.querySelector('.f-label');
      var enabledInput = node.querySelector('.f-enabled');
      labelInput.value = r.label || '';
      node.querySelector('.f-hosts').value = r.hosts || '';
      enabledInput.checked = !!r.enabled;
      panels.appendChild(node);

      // ── per-brand target list editor ──────────────────────────────────
      var tgtHidden  = node.querySelector('.f-targets');   // hidden textarea
      var tgtListEl  = node.querySelector('.f-tgt-list');  // visible list
      var tgtAddBtn  = node.querySelector('.f-add-target');
      var tgtDragSrc = null;

      function tgtSerialize() {
        var urls = [];
        tgtListEl.querySelectorAll('.tgt-row input').forEach(function (inp) {
          var v = inp.value.trim(); if (v) urls.push(v);
        });
        tgtHidden.value = urls.join('\n');
        return urls;
      }

      function tgtRenumber() {
        tgtListEl.querySelectorAll('.tgt-row').forEach(function (r, i) {
          var n = r.querySelector('.tgt-num'); if (n) n.textContent = (i+1)+'.';
        });
      }

      function makeTgtRow(url) {
        var row  = document.createElement('div'); row.className = 'tgt-row'; row.draggable = true;
        var drag = document.createElement('span'); drag.className = 'tgt-drag'; drag.textContent = '\u2261'; drag.title = 'Drag to reorder';
        var num  = document.createElement('span'); num.className = 'tgt-num'; num.textContent = '1.';
        var inp  = document.createElement('input'); inp.type = 'text'; inp.className = 'tgt-input';
        inp.placeholder = 'https://yourdomain.com'; inp.value = url || '';
        var rem  = document.createElement('button'); rem.type = 'button'; rem.className = 'btn-danger btn-sm tgt-remove'; rem.textContent = '\u2715';
        rem.addEventListener('click', function () { row.remove(); tgtRenumber(); tgtSerialize(); renderStatus(); });
        inp.addEventListener('input', function () { tgtSerialize(); renderStatus(); });
        // drag events
        row.addEventListener('dragstart', function (e) {
          tgtDragSrc = row; e.dataTransfer.effectAllowed = 'move';
          setTimeout(function () { row.style.opacity = '.4'; }, 0);
        });
        row.addEventListener('dragend', function () {
          row.style.opacity = '';
          tgtListEl.querySelectorAll('.tgt-row').forEach(function (r) { r.classList.remove('drag-over'); });
          tgtRenumber(); tgtSerialize(); renderStatus();
        });
        row.addEventListener('dragover', function (e) {
          e.preventDefault(); e.dataTransfer.dropEffect = 'move';
          if (tgtDragSrc && tgtDragSrc !== row) row.classList.add('drag-over');
        });
        row.addEventListener('dragleave', function () { row.classList.remove('drag-over'); });
        row.addEventListener('drop', function (e) {
          e.preventDefault(); row.classList.remove('drag-over');
          if (!tgtDragSrc || tgtDragSrc === row) return;
          var rows = Array.from(tgtListEl.querySelectorAll('.tgt-row'));
          if (rows.indexOf(tgtDragSrc) < rows.indexOf(row)) tgtListEl.insertBefore(tgtDragSrc, row.nextSibling);
          else tgtListEl.insertBefore(tgtDragSrc, row);
          tgtRenumber(); tgtSerialize();
        });
        row.appendChild(drag); row.appendChild(num); row.appendChild(inp); row.appendChild(rem);
        return row;
      }

      function renderTgtList(urlsStr) {
        tgtListEl.innerHTML = '';
        var urls = (urlsStr || '').split(/\r?\n/).map(function(u){ return u.trim(); }).filter(Boolean);
        if (!urls.length) {
          tgtListEl.innerHTML = '<div class="tgt-empty">No domains yet — click &ldquo;+ Add domain&rdquo;.</div>';
          return;
        }
        urls.forEach(function (u) { tgtListEl.appendChild(makeTgtRow(u)); });
        tgtRenumber();
      }

      tgtAddBtn.addEventListener('click', function () {
        var empty = tgtListEl.querySelector('.tgt-empty'); if (empty) empty.remove();
        var row = makeTgtRow(''); tgtListEl.appendChild(row);
        row.querySelector('input').focus(); tgtRenumber();
      });

      renderTgtList(r.targets || '');
      tgtSerialize(); // populate the hidden textarea on initial load so renderStatus works
      // ── end target list editor ────────────────────────────────────────

      // sidebar item
      var item = document.createElement('div');
      item.className   = 'side-item' + (r.enabled ? '' : ' off');
      item.dataset.key = key;
      var leftSpan = document.createElement('span'); leftSpan.className = 'side-left';
      var dot  = document.createElement('span'); dot.className  = 'side-dot';
      var name = document.createElement('span'); name.className = 'nm';
      name.textContent = r.label || 'Untitled';
      leftSpan.appendChild(dot); leftSpan.appendChild(name);
      item.appendChild(leftSpan);
      item.addEventListener('click', function () { selectPanel(key); });
      sideList.appendChild(item);

      // player link
      var linkInput = node.querySelector('.f-link');
      function slugify(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]/g,''); }
      function updateLink(){ linkInput.value = location.protocol + '//' + location.host + '/?b=' + slugify(labelInput.value); }
      updateLink();

      // copy with animated feedback
      node.querySelector('.f-copy').addEventListener('click', function(){
        linkInput.select();
        try { navigator.clipboard.writeText(linkInput.value); } catch(e){ try { document.execCommand('copy'); } catch(_){} }
        var btn = node.querySelector('.f-copy');
        btn.textContent = '\u2713 Copied!';
        btn.classList.add('copied');
        setTimeout(function(){ btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 1800);
      });

      // status rendering
      var statusBox = node.querySelector('.status-list');
      function renderStatus(){
        var slug    = slugify(labelInput.value);
        var vs      = (STATS  && STATS[slug])  || {};
        var ck      = (CHECKS && CHECKS[slug]) || {};
        var targets = (node.querySelector('.f-targets').value || '').split(/\r?\n/).map(norm).filter(Boolean);
        statusBox.innerHTML = '';
        if (!targets.length){
          statusBox.innerHTML = '<div class="hint" style="padding:10px 0;">No targets yet \u2014 add some and Save.</div>';
          item.classList.remove('alert');
          return;
        }
        var firstCleanFound = false;
        targets.forEach(function(u, idx){
          var c = ck[u]; var v = vs[u];
          var badgeCls='badge-wait', txt='NOT CHECKED', reason='Not checked yet', when='';
          // Server check is primary (clean = domain resolves and responds). A single
          // visitor timeout is unreliable for Cloudflare sites, so it can't override a
          // clean check — this prevents false "blocked" alarms.
          if      (c && c.status==='clean'){   badgeCls='badge-clean';   txt='CLEAN';   reason=c.reason||''; when='checked '+timeAgo(c.ts); }
          else if (c && c.status==='blocked'){ badgeCls='badge-blocked'; txt='BLOCKED'; reason=c.reason||''; when='checked '+timeAgo(c.ts); }
          else if (c && c.status==='down'){    badgeCls='badge-down';    txt='DOWN';    reason=c.reason||''; when='checked '+timeAgo(c.ts); }
          else if (v && v.status==='active'){  badgeCls='badge-clean';   txt='CLEAN';   reason='Serving players'; when=timeAgo(v.ts); }
          else if (v && v.status==='blocked'){ badgeCls='badge-blocked'; txt='BLOCKED'; reason='Reported blocked by visitor'; when=timeAgo(v.ts); }
          
          // IN USE belongs exclusively to the top active clean domain currently serving players
          var inUse = false;
          if (badgeCls === 'badge-clean' && !firstCleanFound) {
            inUse = true;
            firstCleanFound = true;
          }

          if (idx===0) firstKey = (badgeCls==='badge-clean'?'clean':badgeCls==='badge-blocked'?'blocked':badgeCls==='badge-down'?'down':'unknown');

          var row   = document.createElement('div'); row.className = 'st-row';
          var left  = document.createElement('div'); left.className = 'st-left';
          var badge = document.createElement('span'); badge.className = 'badge '+badgeCls; badge.textContent = txt;
          var mid   = document.createElement('div'); mid.className = 'st-mid';
          var urlEl = document.createElement('div'); urlEl.className = 'st-url'; urlEl.textContent = u.replace(/^https?:\/\//,'');
          var rsEl  = document.createElement('div'); rsEl.className  = 'st-reason'; rsEl.textContent = reason;
          mid.appendChild(urlEl); mid.appendChild(rsEl);
          left.appendChild(badge); left.appendChild(mid);
          row.appendChild(left);
          if (when){ var whenEl=document.createElement('span'); whenEl.className='st-when'; whenEl.textContent=when; row.appendChild(whenEl); }
          if (inUse){ var ib=document.createElement('span'); ib.className='badge badge-inuse'; ib.textContent='IN USE'; row.appendChild(ib); }
          statusBox.appendChild(row);
        });
        // Side-panel red alert when the top (primary) domain is blocked or down.
        item.classList.toggle('alert', firstKey==='blocked' || firstKey==='down');
      }
      renderStatus();
      renderers.push(renderStatus);

      labelInput.addEventListener('input', function () {
        name.textContent = labelInput.value || 'Untitled';
        updateLink();
        renderStatus();
      });
      enabledInput.addEventListener('change', function () {
        item.classList.toggle('off', !enabledInput.checked);
      });
      node.querySelector('.f-remove').addEventListener('click', function () {
        var wasActive = item.classList.contains('active');
        node.remove(); item.remove();
        if (wasActive) {
          var first = sideList.querySelector('.side-item');
          if (first) selectPanel(first.dataset.key);
        }
      });
      node.querySelector('.f-rotate').addEventListener('click', function () {
        // read from the live list, not the textarea directly
        var rows = tgtListEl.querySelectorAll('.tgt-row input');
        var lines = Array.from(rows).map(function(i){ return i.value.trim(); }).filter(Boolean);
        if (lines.length < 2) { alert('Add at least 2 backup domains before rotating.'); return; }
        if (!confirm('Force rotate "' + (labelInput.value || 'this brand') + '" to the next domain now?')) return;
        // move first to last in the UI
        var firstRow = tgtListEl.querySelector('.tgt-row');
        if (firstRow) tgtListEl.appendChild(firstRow);
        tgtRenumber(); tgtSerialize();
        var f = document.getElementById('rulesForm');
        if (f.requestSubmit) f.requestSubmit(); else f.submit();
      });

      if (select) selectPanel(key);
    }

    INITIAL.forEach(function (r) { addRule(r, false); });
    if (!INITIAL.length) addRule(null, false);
    var firstItem = sideList.querySelector('.side-item');
    if (firstItem) selectPanel(firstItem.dataset.key);

    document.getElementById('addRule').addEventListener('click', function () { addRule({ enabled:true }, true); });

    document.getElementById('rulesForm').addEventListener('submit', function () {
      var out = [];
      panels.querySelectorAll('.panel').forEach(function (n) {
        out.push({
          id:      n.dataset.id || '',
          label:   n.querySelector('.f-label').value,
          hosts:   n.querySelector('.f-hosts').value,
          targets: n.querySelector('.f-targets').value,
          enabled: n.querySelector('.f-enabled').checked
        });
      });
      document.getElementById('payload').value = JSON.stringify(out);
    });
  </script>

  <script>
  // ── Telegram Event Log viewer ──────────────────────────────────────────────
  (function () {
    var logBody      = document.getElementById('tgLogBody');
    var refreshBtn   = document.getElementById('refreshTgLogBtn');
    var tgSideItem   = document.getElementById('sideTelegram');
    var loaded       = false;

    function timeAgoShort(iso) {
      if (!iso) return '';
      var t = Date.parse(iso); if (isNaN(t)) return iso;
      var s = Math.floor((Date.now() - t) / 1000);
      if (s < 60)    return s + 's ago';
      if (s < 3600)  return Math.floor(s / 60) + 'm ago';
      if (s < 86400) return Math.floor(s / 3600) + 'h ago';
      return Math.floor(s / 86400) + 'd ago';
    }

    function renderLog(data) {
      if (!data || !data.lines || !data.lines.length) {
        logBody.innerHTML = '<div class="tg-log-empty">' +
          (!data || !data.exists ? '📄 No log file yet — it will appear after the first Telegram alert arrives.' :
          '📭 Log is empty.') + '</div>';
        return;
      }
      var html = '<table class="tg-log-table"><thead><tr>' +
        '<th>Time</th><th>Chat ID</th><th>Domain</th><th>URLs Marked</th></tr></thead><tbody>';
      data.lines.forEach(function (row) {
        var domainCell = row.domain && row.domain !== '[no domain]'
          ? '<span class="tg-log-domain">' + escHtml(row.domain) + '</span>'
          : '<span class="tg-log-nodomain">[no domain]</span>';
        var countNum   = parseInt(row.count) || 0;
        var countCell  = '<span class="tg-log-count' + (countNum === 0 ? ' zero' : '') + '">' +
          escHtml(row.count) + '</span>';
        html += '<tr>' +
          '<td class="tg-log-time" title="' + escHtml(row.time) + '">' + timeAgoShort(row.time) + '</td>' +
          '<td style="font-family:monospace;font-size:.72rem;color:var(--muted)">' + escHtml(row.chatId) + '</td>' +
          '<td>' + domainCell + '</td>' +
          '<td>' + countCell + '</td>' +
          '</tr>';
      });
      html += '</tbody></table>';
      logBody.innerHTML = html;
    }

    function escHtml(s) {
      return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function loadLog() {
      if (refreshBtn) { refreshBtn.classList.add('loading'); }
      logBody.innerHTML = '<div class="tg-log-loading">Loading…</div>';
      var fd = new FormData();
      fd.append('action', 'tg_log');
      fetch('admin.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) { renderLog(data); loaded = true; })
        .catch(function () { logBody.innerHTML = '<div class="tg-log-empty">⚠ Could not load log.</div>'; })
        .finally(function () { if (refreshBtn) refreshBtn.classList.remove('loading'); });
    }

    // Load when Telegram sidebar item is clicked.
    if (tgSideItem) {
      tgSideItem.addEventListener('click', function () {
        if (!loaded) loadLog();
      });
    }

    // Refresh button.
    if (refreshBtn) {
      refreshBtn.addEventListener('click', loadLog);
    }

    // If the page loads directly on the telegram panel, load immediately.
    if (document.getElementById('tgForm') && document.getElementById('tgForm').classList.contains('active')) {
      loadLog();
    }
  })();
  </script>

  <script>
  // ── Domain Pool editor ──────────────────────────────────────────────────────
  (function () {
    var POOL_INITIAL = <?php echo json_encode($pool, JSON_UNESCAPED_SLASHES); ?>;

    var poolList     = document.getElementById('poolList');
    var poolForm     = document.getElementById('poolForm');
    var poolUrls     = document.getElementById('poolUrls');
    var poolCount    = document.getElementById('poolCount');
    var sideDomainPool = document.getElementById('sideDomainPool');
    var savePoolBtn  = document.getElementById('savePoolBtn');
    var addPoolBtn   = document.getElementById('addPoolDomain');

    // Wire sidebar item into the selectPanel system.
    if (sideDomainPool) sideDomainPool.addEventListener('click', function () { selectPanel('pool'); });
    poolForm.classList.add('panel');
    poolForm.dataset.key = 'pool';

    var dragSrc = null;

    function renumber() {
      var rows = poolList.querySelectorAll('.pool-row');
      rows.forEach(function (r, i) { var n = r.querySelector('.pool-num'); if (n) n.textContent = (i + 1) + '.'; });
      if (poolCount) poolCount.textContent = rows.length;
    }

    function makeRow(url) {
      var row   = document.createElement('div');
      row.className = 'pool-row';
      row.draggable = true;

      var drag = document.createElement('span'); drag.className = 'pool-drag'; drag.textContent = '\u2261';
      drag.title = 'Drag to reorder';
      var num  = document.createElement('span'); num.className = 'pool-num'; num.textContent = '1.';

      var inp  = document.createElement('input');
      inp.type = 'text'; inp.className = 'pool-input';
      inp.placeholder = 'https://yourdomain.com';
      inp.value = url || '';

      var rem  = document.createElement('button');
      rem.type = 'button'; rem.className = 'btn-danger btn-sm pool-remove';
      rem.textContent = 'Remove';
      rem.addEventListener('click', function () { row.remove(); renumber(); });

      // Native drag-to-reorder
      row.addEventListener('dragstart', function (e) {
        dragSrc = row;
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(function () { row.style.opacity = '.4'; }, 0);
      });
      row.addEventListener('dragend', function () {
        row.style.opacity = '';
        poolList.querySelectorAll('.pool-row').forEach(function (r) { r.classList.remove('drag-over'); });
        renumber();
      });
      row.addEventListener('dragover', function (e) {
        e.preventDefault(); e.dataTransfer.dropEffect = 'move';
        if (dragSrc && dragSrc !== row) row.classList.add('drag-over');
      });
      row.addEventListener('dragleave', function () { row.classList.remove('drag-over'); });
      row.addEventListener('drop', function (e) {
        e.preventDefault(); row.classList.remove('drag-over');
        if (!dragSrc || dragSrc === row) return;
        var rows = Array.from(poolList.querySelectorAll('.pool-row'));
        var srcIdx = rows.indexOf(dragSrc);
        var dstIdx = rows.indexOf(row);
        if (srcIdx < dstIdx) poolList.insertBefore(dragSrc, row.nextSibling);
        else poolList.insertBefore(dragSrc, row);
        renumber();
      });

      row.appendChild(drag); row.appendChild(num); row.appendChild(inp); row.appendChild(rem);
      return row;
    }

    function renderPool(list) {
      poolList.innerHTML = '';
      if (!list || !list.length) {
        poolList.innerHTML = '<div class="pool-empty">No domains yet. Click &ldquo;+ Add domain&rdquo; to start.</div>';
        if (poolCount) poolCount.textContent = '0';
        return;
      }
      list.forEach(function (u) { poolList.appendChild(makeRow(u)); });
      renumber();
    }

    addPoolBtn && addPoolBtn.addEventListener('click', function () {
      var empty = poolList.querySelector('.pool-empty');
      if (empty) empty.remove();
      var row = makeRow('');
      poolList.appendChild(row);
      row.querySelector('input').focus();
      renumber();
    });

    // Serialise before submit.
    poolForm.addEventListener('submit', function () {
      savePoolBtn && savePoolBtn.classList.add('loading');
      var urls = [];
      poolList.querySelectorAll('.pool-row input').forEach(function (inp) {
        var v = inp.value.trim();
        if (v) urls.push(v);
      });
      poolUrls.value = urls.join('\n');
    });

    // Patch selectPanel to also handle pool + telegram panels.
    var _origSelect = selectPanel;
    selectPanel = function (key) {
      var isPool = (key === 'pool');
      poolForm.classList.toggle('active', isPool);
      if (sideDomainPool) sideDomainPool.classList.toggle('active', isPool);
      if (!isPool) _origSelect(key);
      else {
        var rf = document.getElementById('rulesForm');
        var af = document.getElementById('accountForm');
        var tf = document.getElementById('tgForm');
        if (rf) rf.style.display = 'none';
        if (af) af.classList.remove('active');
        if (tf) tf.classList.remove('active');
        document.getElementById('panels') && document.getElementById('panels').querySelectorAll('.panel').forEach(function (p) { p.classList.remove('active'); });
        document.getElementById('sideList') && document.getElementById('sideList').querySelectorAll('.side-item').forEach(function (s) { s.classList.remove('active'); });
        document.getElementById('sideAccount') && document.getElementById('sideAccount').classList.remove('active');
        document.getElementById('sideTelegram') && document.getElementById('sideTelegram').classList.remove('active');
      }
    };

    renderPool(POOL_INITIAL);
  })();
  </script>
<?php endif; ?>

<script>
  // Theme toggle — shared by the login screen and the panel header.
  (function () {
    var root = document.documentElement;
    var btns = document.querySelectorAll('[data-theme-toggle]');
    if (!btns.length) return;

    function paint() {
      var dark = root.getAttribute('data-theme') !== 'light';
      // The button offers the theme you'd switch TO.
      for (var i = 0; i < btns.length; i++) {
        btns[i].querySelector('.ico').textContent = dark ? '\u2600' : '\u263e';
        btns[i].querySelector('.txt').textContent = dark ? 'Light' : 'Dark';
        btns[i].setAttribute('title',      dark ? 'Switch to light theme' : 'Switch to dark theme');
        btns[i].setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
      }
    }

    function set(theme, remember) {
      root.setAttribute('data-theme', theme);
      if (remember) { try { localStorage.setItem('rotator_theme', theme); } catch (e) {} }
      paint();
    }

    for (var i = 0; i < btns.length; i++) {
      btns[i].addEventListener('click', function () {
        set(root.getAttribute('data-theme') === 'light' ? 'dark' : 'light', true);
      });
    }

    // Until a choice is made, keep following the OS if it changes mid-session.
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: light)');
      var onChange = function (e) {
        var stored;
        try { stored = localStorage.getItem('rotator_theme'); } catch (err) {}
        if (stored !== 'light' && stored !== 'dark') set(e.matches ? 'light' : 'dark', false);
      };
      if (mq.addEventListener) mq.addEventListener('change', onChange);
      else if (mq.addListener) mq.addListener(onChange);
    }

    paint();
  })();

  if (window.initTornado) {
    window.initTornado("tornado-canvas", "tornado-bg");
  }
</script>

</div><!-- .page -->
</body>
</html>
