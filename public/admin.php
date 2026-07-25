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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
<style>
  /* Dark is the default; light overrides via [data-theme="light"].
     The inline <script> in <head> stamps data-theme before first paint. */
  :root {
    color-scheme: dark;
    --font: 'Inter', system-ui, -apple-system, sans-serif;
    --bg: #080e1f;
    --surface: rgba(15,25,50,.88);
    --surface-2: rgba(20,33,65,.92);
    --border: rgba(100,140,220,.13);
    --border-soft: rgba(100,140,220,.07);
    --border-accent: rgba(80,140,255,.28);
    --text: #e8edf8;  --text-2: #c8d4ed;  --text-3: #8fa4cc;
    --muted: #5a6e94; --muted-2: #7285a8;
    --accent: #4f8cff; --accent-2: #6c9fff; --accent-fg: #fff;
    --accent-glow: rgba(79,140,255,.28);
    --input-bg: rgba(8,14,31,.8); --input-border: rgba(80,120,200,.22);
    --input-focus: rgba(79,140,255,.4);
    --ok: #34d474; --ok-bg: rgba(52,212,116,.12); --ok-border: rgba(52,212,116,.3);
    --ok-glow: rgba(52,212,116,.22);
    --ok-msg-bg: rgba(52,212,116,.1); --ok-msg-border: rgba(52,212,116,.3);
    --err-bg: rgba(255,70,70,.1); --err-border: rgba(255,70,70,.3);
    --danger: #ff5f5f; --danger-fg: #ffb3b3; --danger-bg: rgba(255,70,70,.13);
    --danger-glow: rgba(255,80,80,.22); --danger-solid: #c0392b;
    --warn: #f5a623;
    --badge-blk-bg: rgba(255,70,70,.15); --badge-blk-fg: #ff8a8a;
    --badge-wait-bg: rgba(90,110,148,.25); --badge-wait-fg: #8fa4cc;
    --badge-use-bg: rgba(79,140,255,.15); --badge-use-fg: #99c0ff;
    --ghost-bg: rgba(30,50,95,.6); --ghost-fg: #c8d4ed;
    --ghost-border: rgba(80,120,200,.16);
  }
  :root[data-theme="light"] {
    color-scheme: light;
    --bg: #f0f4fc;
    --surface: rgba(255,255,255,.88);
    --surface-2: rgba(240,245,255,.92);
    --border: rgba(100,130,200,.16);
    --border-soft: rgba(100,130,200,.09);
    --border-accent: rgba(47,111,228,.26);
    --text: #131a2e; --text-2: #2a3555; --text-3: #4f6080;
    --muted: #8090b0; --muted-2: #6878a0;
    --accent: #2f6fe4; --accent-2: #4a84f0; --accent-fg: #fff;
    --accent-glow: rgba(47,111,228,.22);
    --input-bg: rgba(255,255,255,.92); --input-border: rgba(80,120,200,.22);
    --input-focus: rgba(47,111,228,.35);
    --ok: #0a8a48; --ok-bg: rgba(10,138,72,.1); --ok-border: rgba(10,138,72,.26);
    --ok-glow: rgba(10,138,72,.16);
    --ok-msg-bg: rgba(10,138,72,.08); --ok-msg-border: rgba(10,138,72,.26);
    --err-bg: rgba(192,57,43,.08); --err-border: rgba(192,57,43,.26);
    --danger: #c0392b; --danger-fg: #8a1c14; --danger-bg: rgba(192,57,43,.1);
    --danger-glow: rgba(192,57,43,.16); --danger-solid: #b03020;
    --warn: #b07a00;
    --badge-blk-bg: rgba(192,57,43,.1); --badge-blk-fg: #9a2018;
    --badge-wait-bg: rgba(100,130,180,.15); --badge-wait-fg: #5a7090;
    --badge-use-bg: rgba(47,111,228,.1); --badge-use-fg: #2050b0;
    --ghost-bg: rgba(220,230,250,.82); --ghost-fg: #2a3555;
    --ghost-border: rgba(80,120,200,.16);
  }

  *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:var(--font); background:var(--bg); color:var(--text);
         min-height:100vh; -webkit-font-smoothing:antialiased; }

  /* ── animated mesh background ── */
  .bg-mesh {
    position:fixed; inset:0; z-index:0; pointer-events:none;
    background:
      radial-gradient(ellipse 80% 55% at 15% -5%, rgba(20,50,110,.65), transparent),
      radial-gradient(ellipse 55% 45% at 90% 105%, rgba(79,140,255,.07), transparent),
      var(--bg);
  }
  [data-theme="light"] .bg-mesh {
    background:
      radial-gradient(ellipse 80% 55% at 15% -5%, rgba(180,210,255,.55), transparent),
      radial-gradient(ellipse 55% 45% at 90% 105%, rgba(47,111,228,.06), transparent),
      var(--bg);
  }
  .page { position:relative; z-index:1; min-height:100vh; }

  /* ── topbar ── */
  .topbar {
    display:flex; align-items:center; justify-content:space-between;
    padding:0 24px; height:58px;
    background:var(--surface); border-bottom:1px solid var(--border);
    backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px);
    position:sticky; top:0; z-index:100;
  }
  .topbar-brand { display:flex; align-items:center; gap:10px; }
  .brand-icon {
    width:30px; height:30px; border-radius:8px; flex-shrink:0;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    display:flex; align-items:center; justify-content:center; font-size:14px;
  }
  .topbar-brand h1 { font-size:.95rem; font-weight:700; letter-spacing:-.01em; }
  .live-pill {
    display:flex; align-items:center; gap:5px; font-size:.68rem;
    font-weight:700; letter-spacing:.05em; color:var(--ok);
    background:var(--ok-bg); border:1px solid var(--ok-border);
    padding:3px 9px; border-radius:20px;
  }
  .live-dot-pulse {
    width:6px; height:6px; border-radius:50%; background:var(--ok);
    animation:pulseGreen 2s ease-in-out infinite;
  }
  @keyframes pulseGreen {
    0%,100%{ box-shadow:0 0 0 0 var(--ok-glow); }
    50%{ box-shadow:0 0 0 5px transparent; }
  }
  .topbar-right { display:flex; align-items:center; gap:8px; }
  .user-chip {
    font-size:.78rem; color:var(--text-3); padding:5px 10px;
    background:var(--ghost-bg); border:1px solid var(--ghost-border);
    border-radius:8px; font-weight:500;
  }

  /* ── buttons ── */
  button {
    cursor:pointer; border:none; border-radius:10px; padding:9px 16px;
    font-family:var(--font); font-weight:600; font-size:.875rem;
    transition:all .18s ease; display:inline-flex; align-items:center; gap:6px; line-height:1;
  }
  button:active { transform:scale(.97); }
  .btn-primary {
    background:linear-gradient(135deg,var(--accent),var(--accent-2));
    color:var(--accent-fg); box-shadow:0 2px 12px var(--accent-glow);
  }
  .btn-primary:hover { box-shadow:0 4px 22px var(--accent-glow); filter:brightness(1.08); }
  .btn-ghost {
    background:var(--ghost-bg); color:var(--ghost-fg); border:1px solid var(--ghost-border);
  }
  .btn-ghost:hover { background:var(--surface-2); border-color:var(--border-accent); }
  .btn-danger {
    background:var(--danger-bg); color:var(--danger-fg);
    border:1px solid rgba(255,80,80,.22);
  }
  .btn-danger:hover { background:rgba(255,80,80,.22); }
  .btn-sm { padding:6px 12px; font-size:.78rem; border-radius:8px; }
  /* spinner inside button */
  .btn-spinner {
    width:14px; height:14px; border:2px solid rgba(255,255,255,.3);
    border-top-color:#fff; border-radius:50%;
    animation:spin .7s linear infinite; display:none; flex-shrink:0;
  }
  button.loading .btn-spinner { display:block; }
  button.loading .btn-label   { opacity:.6; }
  @keyframes spin { to{ transform:rotate(360deg); } }

  /* ── flash messages ── */
  .msg {
    padding:12px 16px; border-radius:12px; font-size:.875rem; font-weight:500;
    margin:0 0 16px; display:flex; align-items:center; gap:10px;
    animation:slideDown .25s ease;
  }
  @keyframes slideDown {
    from{ opacity:0; transform:translateY(-8px); }
    to{   opacity:1; transform:translateY(0); }
  }
  .msg-ok  { background:var(--ok-msg-bg);  border:1px solid var(--ok-msg-border); color:var(--ok); }
  .msg-err { background:var(--err-bg);      border:1px solid var(--err-border);    color:var(--danger-fg); }

  /* ── login screen ── */
  .login-wrap { min-height:100vh; display:grid; place-items:center; padding:24px; }
  .login-card {
    width:100%; max-width:360px;
    background:var(--surface); border:1px solid var(--border); border-radius:20px;
    padding:32px; backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px);
    box-shadow:0 8px 40px rgba(0,0,0,.25), 0 0 0 1px var(--border-soft);
    animation:fadeUp .4s ease;
  }
  @keyframes fadeUp {
    from{ opacity:0; transform:translateY(16px); }
    to{   opacity:1; transform:translateY(0); }
  }
  .login-icon {
    width:52px; height:52px; border-radius:14px; margin:0 auto 20px;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    display:flex; align-items:center; justify-content:center; font-size:26px;
    box-shadow:0 4px 18px var(--accent-glow);
  }
  .login-title { font-size:1.3rem; font-weight:700; text-align:center; margin-bottom:4px; }
  .login-sub   { font-size:.82rem; color:var(--text-3); text-align:center; margin-bottom:22px; }
  .login-theme-btn { position:fixed; top:16px; right:16px; z-index:999; }

  /* ── form fields ── */
  .field { margin-bottom:14px; }
  .field-label {
    display:block; font-size:.72rem; font-weight:700; color:var(--text-3);
    text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px;
  }
  input[type=text], input[type=password], textarea {
    width:100%; padding:10px 13px; border-radius:10px;
    border:1px solid var(--input-border); background:var(--input-bg);
    color:var(--text); font-size:.9rem; font-family:var(--font);
    transition:border-color .15s, box-shadow .15s; outline:none;
  }
  input[type=text]:focus, input[type=password]:focus, textarea:focus {
    border-color:var(--accent); box-shadow:0 0 0 3px var(--input-focus);
  }
  textarea { resize:vertical; font-family:'SF Mono',ui-monospace,monospace; font-size:.82rem; }
  textarea.f-hosts   { min-height:44px;  height:44px;  }
  textarea.f-targets { min-height:180px; }

  /* ── main layout ── */
  .main { max-width:1100px; margin:0 auto; padding:24px 20px 80px; }
  .layout { display:flex; gap:20px; align-items:flex-start; }
  @media(max-width:760px){
    .layout { flex-direction:column; }
    .sidebar { position:static!important; width:100%; flex-basis:auto!important; }
  }

  /* ── sidebar ── */
  .sidebar {
    flex:0 0 220px; position:sticky; top:74px;
    background:var(--surface); border:1px solid var(--border); border-radius:16px;
    padding:10px; backdrop-filter:blur(16px);
  }
  .side-section-title {
    font-size:.65rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.08em; color:var(--muted); padding:6px 10px 4px;
  }
  .side-item {
    display:flex; align-items:center; justify-content:space-between;
    gap:8px; padding:9px 12px; border-radius:10px; cursor:pointer;
    font-weight:600; font-size:.875rem; color:var(--text-2);
    margin-bottom:2px; transition:all .15s ease;
  }
  .side-item:hover { background:var(--surface-2); }
  .side-item.active { background:var(--accent); color:var(--accent-fg); box-shadow:0 2px 10px var(--accent-glow); }
  .side-left { display:flex; align-items:center; gap:8px; }
  .side-dot { width:7px; height:7px; border-radius:50%; background:var(--ok); flex-shrink:0; }
  .side-item.off  .side-dot { background:var(--muted); }
  .side-item.alert .side-dot { background:var(--danger); animation:pulseRed 1.5s ease-in-out infinite; }
  @keyframes pulseRed {
    0%,100%{ box-shadow:0 0 0 0 var(--danger-glow); }
    50%{     box-shadow:0 0 0 4px transparent; }
  }
  .side-item.alert        { background:var(--danger-bg); color:var(--danger-fg); }
  .side-item.alert.active { background:var(--danger-solid); color:#fff; }
  .side-add {
    width:100%; margin-top:6px; background:transparent; color:var(--muted-2);
    border:1px dashed var(--border); font-size:.82rem; padding:8px;
    border-radius:10px; justify-content:center;
  }
  .side-add:hover { background:var(--surface-2); color:var(--text-2); border-style:solid; }
  .side-sep { height:1px; background:var(--border-soft); margin:10px 4px; }

  /* ── editor ── */
  .editor { flex:1 1 auto; min-width:0; }
  .panel { display:none; }
  .panel.active { display:block; animation:fadeIn .2s ease; }
  @keyframes fadeIn {
    from{ opacity:0; transform:translateY(4px); }
    to{   opacity:1; transform:translateY(0); }
  }

  /* ── rule card ── */
  .rule-card {
    background:var(--surface); border:1px solid var(--border); border-radius:18px;
    padding:22px 24px; backdrop-filter:blur(16px);
    position:relative; overflow:hidden;
  }
  .rule-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:2px;
    background:linear-gradient(90deg,var(--accent),#8b5cf6,var(--accent-2));
    border-radius:18px 18px 0 0;
  }
  .rule-head {
    display:flex; align-items:center; justify-content:space-between;
    gap:12px; margin-bottom:20px; flex-wrap:wrap;
  }
  .rule-head-left  { display:flex; align-items:center; gap:10px; flex:1; min-width:0; }
  .rule-head-right { display:flex; align-items:center; gap:12px; flex-shrink:0; }
  .f-label {
    font-size:1.05rem!important; font-weight:700!important;
    background:transparent!important; border:1px solid transparent!important;
    border-radius:8px!important; padding:4px 8px!important; max-width:280px;
  }
  .f-label:focus { background:var(--input-bg)!important; border-color:var(--accent)!important; }
  /* custom toggle switch */
  .toggle-label { display:flex; align-items:center; gap:7px; font-size:.78rem; font-weight:600; color:var(--text-3); cursor:pointer; user-select:none; }
  .toggle-switch { position:relative; width:34px; height:18px; flex-shrink:0; }
  .toggle-switch input { opacity:0; width:0; height:0; position:absolute; }
  .toggle-track {
    position:absolute; inset:0; border-radius:9px; background:var(--muted);
    transition:background .2s; cursor:pointer;
  }
  .toggle-track::after {
    content:''; position:absolute; width:14px; height:14px; border-radius:50%;
    background:#fff; top:2px; left:2px; transition:transform .2s;
    box-shadow:0 1px 3px rgba(0,0,0,.3);
  }
  .toggle-switch input:checked + .toggle-track              { background:var(--accent); }
  .toggle-switch input:checked + .toggle-track::after       { transform:translateX(16px); }
  .rule-cols { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
  @media(max-width:640px){ .rule-cols{ grid-template-columns:1fr; } }
  .col-label {
    display:block; font-size:.7rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.06em; color:var(--muted-2); margin-bottom:6px;
  }
  .hint { font-size:.71rem; color:var(--muted); margin-top:5px; line-height:1.55; }

  /* ── player link ── */
  .link-row { margin-top:18px; }
  .link-input-wrap { display:flex; gap:8px; max-width:560px; }
  .f-link {
    flex:1; background:var(--input-bg); border:1px solid var(--input-border);
    border-radius:10px; padding:9px 12px; font-size:.8rem; cursor:text;
    font-family:'SF Mono',ui-monospace,monospace; color:var(--text-3);
  }
  .copy-btn { flex-shrink:0; }
  .copy-btn.copied { background:var(--ok-bg)!important; color:var(--ok)!important; border-color:var(--ok-border)!important; }

  /* ── status section ── */
  .status-section { margin-top:22px; }
  .status-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
  .status-title {
    font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em;
    color:var(--muted-2); display:flex; align-items:center; gap:7px;
  }
  .live-blink { width:6px; height:6px; border-radius:50%; background:var(--accent); animation:pulseBlue 2.5s ease-in-out infinite; }
  @keyframes pulseBlue {
    0%,100%{ box-shadow:0 0 0 0 var(--accent-glow); }
    50%{     box-shadow:0 0 0 4px transparent; }
  }
  .status-list { display:flex; flex-direction:column; gap:6px; }
  .st-row {
    display:flex; align-items:flex-start; gap:10px; padding:10px 12px;
    border-radius:10px; background:var(--surface-2); border:1px solid var(--border-soft);
    transition:border-color .15s;
  }
  .st-row:hover { border-color:var(--border-accent); }
  .st-left  { display:flex; align-items:flex-start; gap:10px; flex:1; min-width:0; }
  .st-mid   { flex:1 1 auto; min-width:0; }
  .st-url   { font-size:.83rem; color:var(--text-2); word-break:break-all; font-weight:500; }
  .st-reason{ font-size:.71rem; color:var(--muted); margin-top:2px; }
  .st-when  { font-size:.69rem; color:var(--muted); flex-shrink:0; align-self:center; }
  .status-legend { font-size:.68rem; color:var(--muted); margin-top:8px; line-height:1.6; }

  /* ── badges ── */
  .badge {
    font-size:.62rem; font-weight:800; letter-spacing:.06em;
    padding:3px 9px; border-radius:20px; flex-shrink:0; white-space:nowrap;
  }
  .badge-clean   { background:var(--ok-bg);         color:var(--ok);             border:1px solid var(--ok-border);            box-shadow:0 0 8px var(--ok-glow); }
  .badge-blocked { background:var(--badge-blk-bg);   color:var(--badge-blk-fg);   border:1px solid rgba(255,80,80,.26);          box-shadow:0 0 8px var(--danger-glow); }
  .badge-down    { background:var(--badge-wait-bg);  color:var(--badge-wait-fg);  border:1px solid rgba(100,130,180,.2); }
  .badge-wait    { background:var(--badge-wait-bg);  color:var(--badge-wait-fg);  border:1px solid rgba(100,130,180,.2); }
  .badge-inuse   {
    background:var(--badge-use-bg); color:var(--badge-use-fg);
    border:1px solid rgba(79,140,255,.26);
    display:inline-flex; align-items:center; gap:5px;
  }
  .badge-inuse::before {
    content:''; width:5px; height:5px; border-radius:50%; background:var(--accent);
    flex-shrink:0; animation:pulseBlue 1.5s ease-in-out infinite;
  }

  /* ── action bar ── */
  .action-bar { display:flex; gap:10px; margin-top:22px; flex-wrap:wrap; }

  /* ── account panel ── */
  .account-card {
    background:var(--surface); border:1px solid var(--border); border-radius:18px;
    padding:22px 24px; backdrop-filter:blur(16px);
  }
  .account-title { font-size:1rem; font-weight:700; margin-bottom:4px; }
  .account-sub   { font-size:.82rem; color:var(--text-3); margin-bottom:20px; }

  a.link { color:var(--accent); }

  /* ── pool panel ── */
  .pool-card {
    background:var(--surface); border:1px solid var(--border); border-radius:18px;
    padding:22px 24px; backdrop-filter:blur(16px); position:relative; overflow:hidden;
  }
  .pool-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:2px;
    background:linear-gradient(90deg,#34d474,#4f8cff);
    border-radius:18px 18px 0 0;
  }
  .pool-title   { font-size:1rem; font-weight:700; margin-bottom:4px; }
  .pool-sub     { font-size:.82rem; color:var(--text-3); margin-bottom:20px; }
  .pool-list    { display:flex; flex-direction:column; gap:8px; margin-bottom:16px; }
  .pool-row     {
    display:flex; align-items:center; gap:8px;
    padding:6px 8px; border-radius:10px; background:var(--surface-2);
    border:1px solid var(--border-soft); transition:border-color .15s;
  }
  .pool-row.drag-over { border-color:var(--accent); background:var(--ghost-bg); }
  .pool-drag    {
    cursor:grab; color:var(--muted); font-size:1rem; padding:4px 2px;
    user-select:none; flex-shrink:0; line-height:1;
  }
  .pool-drag:active { cursor:grabbing; }
  .pool-num     {
    font-size:.68rem; font-weight:700; color:var(--muted);
    width:20px; text-align:right; flex-shrink:0; font-family:'SF Mono',ui-monospace,monospace;
  }
  .pool-input   { flex:1; border-radius:8px!important; padding:7px 10px!important; font-size:.83rem!important; }
  .pool-remove  { flex-shrink:0; }
  .pool-empty   { text-align:center; padding:24px 0; color:var(--muted); font-size:.85rem; }
  .pool-add-btn {
    width:100%; background:transparent; color:var(--muted-2); border:1px dashed var(--border);
    font-size:.82rem; padding:9px; border-radius:10px; justify-content:center;
  }
  .pool-add-btn:hover { background:var(--surface-2); color:var(--text-2); border-style:solid; }
  .side-count {
    font-size:.65rem; font-weight:700; background:var(--ghost-bg);
    color:var(--muted-2); border:1px solid var(--ghost-border);
    padding:2px 7px; border-radius:20px; min-width:22px; text-align:center;
  }
  .side-item.active .side-count { background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.2); }

  /* ── per-brand target list ── */
  .tgt-list    { display:flex; flex-direction:column; gap:6px; margin-bottom:8px; min-height:32px; }
  .tgt-row     {
    display:flex; align-items:center; gap:6px;
    padding:5px 7px; border-radius:8px; background:var(--surface-2);
    border:1px solid var(--border-soft); transition:border-color .15s;
  }
  .tgt-row.drag-over { border-color:var(--accent); background:var(--ghost-bg); }
  .tgt-drag    { cursor:grab; color:var(--muted); font-size:.9rem; padding:3px 2px; user-select:none; flex-shrink:0; }
  .tgt-drag:active { cursor:grabbing; }
  .tgt-num     { font-size:.64rem; font-weight:700; color:var(--muted); width:18px; text-align:right; flex-shrink:0; font-family:'SF Mono',ui-monospace,monospace; }
  .tgt-input   { flex:1; border-radius:7px!important; padding:5px 9px!important; font-size:.8rem!important; font-family:'SF Mono',ui-monospace,monospace!important; }
  .tgt-remove  { flex-shrink:0; }
  .tgt-empty   { font-size:.78rem; color:var(--muted); padding:8px 0; }
  .tgt-add-btn {
    width:100%; background:transparent; color:var(--muted-2); border:1px dashed var(--border);
    font-size:.78rem; padding:7px; border-radius:8px; justify-content:center; margin-top:4px;
  }
  .tgt-add-btn:hover { background:var(--surface-2); color:var(--text-2); border-style:solid; }
</style>
<script>
  // Apply the saved theme before first paint so the panel never flashes.
  // No stored choice -> follow the OS. No JS at all -> the dark defaults stand.
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
<div class="bg-mesh"></div>
<div class="page">

<?php if (!is_logged_in()): ?>
  <button type="button" class="btn-ghost btn-sm theme-btn login-theme-btn" data-theme-toggle>
    <span class="ico"></span><span class="txt"></span>
  </button>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-icon">🔄</div>
      <h1 class="login-title">Rotator Admin</h1>
      <p class="login-sub">Sign in to manage your redirect rules.</p>
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
        <div style="margin-top:20px;">
          <button class="btn-primary" style="width:100%;justify-content:center;" type="submit">
            <span class="btn-label">Sign in &rarr;</span>
          </button>
        </div>
      </form>
    </div>
  </div>

<?php else: ?>
  <header class="topbar">
    <div class="topbar-brand">
      <div class="brand-icon">🔄</div>
      <h1>Rotator Admin</h1>
      <div class="live-pill"><span class="live-dot-pulse"></span>LIVE</div>
    </div>
    <div class="topbar-right">
      <span class="user-chip">👤 <?php echo htmlspecialchars($current_user); ?></span>
      <button type="button" class="btn-ghost btn-sm theme-btn" data-theme-toggle>
        <span class="ico"></span><span class="txt"></span>
      </button>
      <form method="post" style="margin:0;">
        <input type="hidden" name="action" value="logout" />
        <button class="btn-ghost btn-sm" type="submit">Log out</button>
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
        <button type="button" class="btn-ghost side-add" id="addRule">+ Add brand</button>
        <div class="side-sep"></div>
        <div class="side-section-title">Settings</div>
        <div class="side-item" id="sideDomainPool">
          <span class="side-left">
            <span style="font-size:.9rem;">&#127760;</span>
            <span>Domain Pool</span>
          </span>
          <span class="side-count" id="poolCount"><?php echo count($pool); ?></span>
        </div>
        <div class="side-item" id="sideTelegram">
          <span class="side-left">
            <span style="font-size:.95rem;">✈️</span>
            <span>Telegram</span>
          </span>
        </div>
        <div class="side-item" id="sideAccount">
          <span class="side-left">
            <span style="font-size:.85rem;opacity:.7;">&#9881;</span>
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
            <h2 class="account-title">Admin account</h2>
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
          <div class="account-card" style="position:relative;overflow:hidden;">
            <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,#229ED9,#54A8D0,#229ED9);border-radius:18px 18px 0 0;"></div>
            <h2 class="account-title">✈️ Telegram Block Alerts</h2>
            <p class="account-sub">When a domain block alert arrives in your Telegram group, the rotator instantly skips that domain for all visitors.</p>

            <div class="field">
              <label class="field-label" for="tg_token">Bot Token</label>
              <input type="text" id="tg_token" name="tg_token"
                value="<?php echo htmlspecialchars($tgCfg['token']); ?>"
                placeholder="7123456789:AABBcc..."
                style="max-width:460px;font-family:'SF Mono',ui-monospace,monospace;font-size:.83rem;" />
              <div class="hint">Get this from @BotFather → /newbot. Looks like <code>1234567890:AABBcc...</code></div>
            </div>

            <div class="field">
              <label class="field-label" for="tg_chat_id">Group Chat ID</label>
              <input type="text" id="tg_chat_id" name="tg_chat_id"
                value="<?php echo htmlspecialchars($tgCfg['chat_id']); ?>"
                placeholder="-1001234567890"
                style="max-width:240px;font-family:'SF Mono',ui-monospace,monospace;font-size:.83rem;" />
              <div class="hint">Negative number for groups/channels (e.g. <code>-1001234567890</code>). Leave blank to accept any group — the Chat ID will appear in your log after the first message.</div>
            </div>

            <div class="field">
              <label class="field-label" for="tg_secret">Webhook Secret</label>
              <input type="text" id="tg_secret" name="tg_secret"
                value="<?php echo htmlspecialchars($tgCfg['secret']); ?>"
                placeholder="any-random-string-you-invent"
                style="max-width:340px;font-family:'SF Mono',ui-monospace,monospace;font-size:.83rem;" />
              <div class="hint">Any random string. Keeps strangers from calling your webhook. You invent it — just keep it consistent.</div>
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
              2. Click <em>Register webhook with Telegram</em> once — this tells Telegram to POST to your server whenever a message arrives in the group.<br>
              3. Done! The next time someone posts a blocked domain alert, the rotator will skip it automatically.
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
            <div class="hint" style="margin-top:12px;">These are the CANDIDATES injected into the visitor gateway. The order here is the priority order — domain #1 is tried first.</div>
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
        var firstKey = null;
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
          var inUse = (v && v.status==='active');
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
</script>

</div><!-- .page -->
</body>
</html>
