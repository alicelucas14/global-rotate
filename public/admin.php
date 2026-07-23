<?php
require __DIR__ . '/admin_config.php';
require __DIR__ . '/rotator_lib.php';

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

$data  = rotator_load();
$rules = $data['rules'];

// Seed the three brands the first time (when there is no data yet).
if (empty($rules)) {
    $rules = [
        ['id' => 'gold888',    'label' => 'Gold888',    'slug' => 'gold888',    'hosts' => [], 'targets' => [], 'enabled' => true],
        ['id' => 'polaslot88', 'label' => 'Polaslot88', 'slug' => 'polaslot88', 'hosts' => [], 'targets' => [], 'enabled' => true],
        ['id' => 'wings365',   'label' => 'Wings365',   'slug' => 'wings365',   'hosts' => [], 'targets' => [], 'enabled' => true],
    ];
}

$token = csrf_token();
$current_user = rotator_auth_user();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title>Rotator Admin</title>
<style>
  /* Dark is the default token set; light overrides it via [data-theme="light"].
     The inline script in <head> stamps data-theme before first paint. */
  :root {
    color-scheme: dark;
    --bg:#0b1020; --text:#e7ecf5;
    --surface:#111a33; --surface-2:#182444;
    --border:#1e2b4d; --border-soft:#1c2848;
    --text-2:#cdd8f0; --text-3:#a9b6ce; --muted:#6f7d97; --muted-2:#8492ad;
    --accent:#3b7bff; --accent-fg:#fff;
    --input-bg:#0d1530; --input-border:#2a3a63;
    --link:#7fa8ff;
    --ghost-bg:#1e2b4d; --ghost-fg:#cdd8f0;
    --ok:#7bd88f; --ok-bg:rgba(80,200,120,.2);
    --ok-msg-bg:rgba(80,200,120,.15); --ok-msg-border:rgba(80,200,120,.4);
    --err-bg:rgba(255,80,80,.15); --err-border:rgba(255,80,80,.4);
    --danger-fg:#ffb3b3; --danger-bg:rgba(255,80,80,.16);
    --danger-btn-bg:rgba(255,80,80,.18); --danger-solid:#c0392b; --danger-dot:#ff6b6b;
    --badge-blk-bg:rgba(255,80,80,.2); --badge-blk-fg:#ff9b9b;
    --badge-wait-bg:#1e2b4d; --badge-wait-fg:#9fb0d0;
    --badge-use-bg:rgba(91,157,255,.2); --badge-use-fg:#9cc0ff;
    --warn:#f0c674;
  }
  :root[data-theme="light"] {
    color-scheme: light;
    --bg:#f4f6fb; --text:#131a2a;
    --surface:#ffffff; --surface-2:#e9eefa;
    --border:#d7dfee; --border-soft:#e7ecf6;
    --text-2:#26304a; --text-3:#4f5b73; --muted:#78849a; --muted-2:#626e86;
    --accent:#2f6fe4; --accent-fg:#fff;
    --input-bg:#ffffff; --input-border:#c7d2e6;
    --link:#1f5fd0;
    --ghost-bg:#e6ecf8; --ghost-fg:#26304a;
    --ok:#17864a; --ok-bg:rgba(23,134,74,.14);
    --ok-msg-bg:rgba(23,134,74,.12); --ok-msg-border:rgba(23,134,74,.35);
    --err-bg:rgba(192,57,43,.10); --err-border:rgba(192,57,43,.32);
    --danger-fg:#a8231b; --danger-bg:rgba(192,57,43,.12);
    --danger-btn-bg:rgba(192,57,43,.12); --danger-solid:#c0392b; --danger-dot:#d64533;
    --badge-blk-bg:rgba(192,57,43,.14); --badge-blk-fg:#a8231b;
    --badge-wait-bg:#e3e9f5; --badge-wait-fg:#4f5b73;
    --badge-use-bg:rgba(47,111,228,.14); --badge-use-fg:#1f5fd0;
    --warn:#b8860b;
  }
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
    background:var(--bg); color:var(--text); }
  header { display:flex; align-items:center; justify-content:space-between;
    padding:14px 20px; background:var(--surface); border-bottom:1px solid var(--border); }
  header h1 { font-size:1.05rem; margin:0; }
  .head-actions { display:flex; align-items:center; gap:10px; }
  .theme-btn { background:var(--ghost-bg); color:var(--ghost-fg); padding:10px 14px;
    display:inline-flex; align-items:center; gap:7px; line-height:1; }
  .theme-btn .ico { font-size:1rem; }
  .login-theme { position:fixed; top:16px; right:16px; }
  main { max-width:1040px; margin:0 auto; padding:22px 16px 60px; }
  .layout { display:flex; gap:18px; align-items:flex-start; }
  .sidebar { flex:0 0 210px; background:var(--surface); border:1px solid var(--border);
    border-radius:14px; padding:10px; position:sticky; top:16px; }
  .side-title { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em;
    color:var(--muted); padding:6px 8px; }
  .side-item { display:flex; align-items:center; justify-content:space-between;
    gap:8px; padding:10px 12px; border-radius:10px; cursor:pointer; font-weight:600;
    font-size:.9rem; color:var(--text-2); margin-bottom:2px; }
  .side-item:hover { background:var(--surface-2); }
  .side-item.active { background:var(--accent); color:var(--accent-fg); }
  .side-item .dot { width:8px; height:8px; border-radius:50%; background:var(--ok); flex:0 0 auto; }
  .side-item.off .dot { background:var(--muted); }
  .side-item.alert { background:var(--danger-bg); color:var(--danger-fg); }
  .side-item.alert.active { background:var(--danger-solid); color:#fff; }
  .side-item.alert .dot { background:var(--danger-dot); }
  .side-item .dot.warn { background:var(--warn); }
  .editor { flex:1 1 auto; min-width:0; }
  .panel { display:none; }
  .panel.active { display:block; }
  .side-add { width:100%; margin-top:8px; }
  @media (max-width:720px){ .layout{ flex-direction:column; } .sidebar{ position:static; width:100%; flex-basis:auto; } }
  .msg { padding:10px 14px; border-radius:10px; margin:0 0 16px; font-size:.9rem; }
  .err { background:var(--err-bg); border:1px solid var(--err-border); color:var(--danger-fg); }
  .ok  { background:var(--ok-msg-bg); border:1px solid var(--ok-msg-border); }
  .login { max-width:340px; margin:12vh auto 0; background:var(--surface);
    border:1px solid var(--border); border-radius:16px; padding:26px; }
  .login .sub { font-size:.82rem; color:var(--text-3); margin:0 0 8px; }
  label { display:block; font-size:.8rem; color:var(--text-3); margin:12px 0 5px; }
  input[type=text], input[type=password], textarea {
    width:100%; padding:10px 12px; border-radius:10px;
    border:1px solid var(--input-border); background:var(--input-bg); color:var(--text); font-size:.9rem; }
  textarea { resize:vertical; min-height:64px; font-family:ui-monospace,monospace; }
  textarea.f-hosts { min-height:46px; height:46px; }
  textarea.f-targets { min-height:200px; }
  button { cursor:pointer; border:0; border-radius:10px; padding:10px 16px;
    font-weight:600; font-size:.9rem; }
  .primary { background:var(--accent); color:var(--accent-fg); }
  .ghost { background:var(--ghost-bg); color:var(--ghost-fg); }
  .danger { background:var(--danger-btn-bg); color:var(--danger-fg); }
  .rule { background:var(--surface); border:1px solid var(--border); border-radius:14px;
    padding:16px; margin:0 0 16px; }
  .rule .row { display:flex; gap:14px; flex-wrap:wrap; }
  .rule .col { flex:1 1 260px; }
  .rule .head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; }
  .toggle { display:flex; align-items:center; gap:7px; font-size:.82rem; color:var(--text-3); }
  .bar { display:flex; gap:10px; margin-top:18px; }
  .hint { font-size:.72rem; color:var(--muted); margin-top:4px; }
  .status-head { font-size:.82rem; color:var(--text-3); margin-top:16px; font-weight:600; }
  .status-list { margin-top:6px; }
  .st-row { display:flex; align-items:flex-start; gap:10px; padding:8px 0; border-top:1px solid var(--border-soft); font-size:.85rem; }
  .st-mid { flex:1 1 auto; min-width:0; }
  .st-url { color:var(--text-2); word-break:break-all; }
  .st-reason { font-size:.72rem; color:var(--muted-2); margin-top:2px; }
  .st-when { color:var(--muted); font-size:.74rem; flex:0 0 auto; }
  .badge { font-size:.66rem; font-weight:700; letter-spacing:.04em; padding:3px 9px; border-radius:20px; flex:0 0 auto; }
  .badge.act { background:var(--ok-bg); color:var(--ok); }
  .badge.blk { background:var(--badge-blk-bg); color:var(--badge-blk-fg); }
  .badge.wait { background:var(--badge-wait-bg); color:var(--badge-wait-fg); }
  .badge.use { background:var(--badge-use-bg); color:var(--badge-use-fg); }
  a.link { color:var(--link); }
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
<?php if (!is_logged_in()): ?>
  <button type="button" class="ghost theme-btn login-theme" data-theme-toggle>
    <span class="ico"></span><span class="txt"></span>
  </button>
  <div class="login">
    <h1 style="margin:0 0 4px;">Rotator Admin</h1>
    <p class="sub">Sign in to manage redirects.</p>
    <?php if ($error): ?><div class="msg err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login" />
      <label>Username</label>
      <input type="text" name="username" autocomplete="username" autofocus />
      <label>Password</label>
      <input type="password" name="password" autocomplete="current-password" />
      <div style="margin-top:16px;"><button class="primary" style="width:100%;" type="submit">Sign in</button></div>
    </form>
  </div>
<?php else: ?>
  <header>
    <h1>Rotator Admin</h1>
    <div class="head-actions">
      <button type="button" class="ghost theme-btn" data-theme-toggle>
        <span class="ico"></span><span class="txt"></span>
      </button>
      <form method="post" style="margin:0;">
        <input type="hidden" name="action" value="logout" />
        <button class="ghost" type="submit">Log out</button>
      </form>
    </div>
  </header>
  <main>
    <?php if ($error): ?><div class="msg err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="msg ok"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

    <div class="layout">
      <aside class="sidebar">
        <div class="side-title">Brands</div>
        <div id="sideList"></div>
        <button type="button" class="ghost side-add" id="addRule">+ Add</button>
        <div class="side-title" style="margin-top:14px;">Settings</div>
        <div class="side-item" id="sideAccount">
          <span style="display:flex;align-items:center;gap:8px;"><span class="dot warn"></span><span>Account</span></span>
        </div>
      </aside>

      <section class="editor">
        <form method="post" id="rulesForm">
          <input type="hidden" name="action" value="save" />
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>" />
          <input type="hidden" name="payload" id="payload" />
          <div id="panels"></div>
          <div class="bar">
            <button type="submit" class="primary" id="saveBtn">Save all</button>
            <button type="button" class="ghost" id="checkBtn">Run check now</button>
            <button type="button" class="ghost" id="refreshBtn">&#8635; Refresh status</button>
          </div>
        </form>

        <form method="post" id="accountForm" class="rule panel" data-key="account">
          <input type="hidden" name="action" value="account" />
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>" />
          <h2 style="margin:0 0 4px;font-size:1rem;">Admin account</h2>
          <p class="hint" style="margin:0 0 12px;">Change the username and password you use to log in to this panel.</p>
          <label>Username</label>
          <input type="text" name="new_user" value="<?php echo htmlspecialchars($current_user); ?>" style="max-width:340px;" autocomplete="username" />
          <label>Current password</label>
          <input type="password" name="current" style="max-width:340px;" autocomplete="current-password" />
          <label>New password (at least 6 characters)</label>
          <input type="password" name="new_pass" style="max-width:340px;" autocomplete="new-password" />
          <label>Confirm new password</label>
          <input type="password" name="confirm" style="max-width:340px;" autocomplete="new-password" />
          <div class="bar"><button type="submit" class="primary">Update account</button></div>
        </form>
      </section>
    </div>
  </main>

  <template id="ruleTpl">
    <div class="rule panel" data-id="">
      <div class="head">
        <input type="text" class="f-label" placeholder="Brand name" style="max-width:320px;" />
        <div style="display:flex;align-items:center;gap:12px;">
          <label class="toggle"><input type="checkbox" class="f-enabled" checked /> Enabled</label>
          <button type="button" class="danger f-remove">Remove</button>
        </div>
      </div>
      <div class="row">
        <div class="col">
          <label>Link players open (your entry domain)</label>
          <textarea class="f-hosts" placeholder="yourlink.com"></textarea>
          <div class="hint">The stable link you hand to players. Must point to this server. Leave empty to use the built-in link below.</div>
        </div>
        <div class="col">
          <label>Backup game domains — rotates when blocked (top = first choice)</label>
          <textarea class="f-targets" placeholder="site1.com&#10;site2.com&#10;site3.com"></textarea>
          <div class="hint">Players are sent to the first one that works; blocked ones are skipped automatically.</div>
        </div>
      </div>
      <div style="margin-top:14px;">
        <label>Player link (share this)</label>
        <div style="display:flex;gap:8px;max-width:560px;">
          <input type="text" class="f-link" readonly style="cursor:text;" />
          <button type="button" class="ghost f-copy" style="flex:0 0 auto;">Copy</button>
        </div>
        <div class="hint">Give this link to players for this brand. Or point a dedicated entry domain at it using the Entry domains box above-left.</div>
      </div>
      <div class="status-head" style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <span>Live status (auto-checked + real visitors)</span>
        <button type="button" class="ghost f-rotate" style="padding:6px 10px;font-size:.78rem;">&#8635; Force rotate to next</button>
      </div>
      <div class="status-list"></div>
      <div class="hint">CLEAN = reachable &middot; BLOCKED = blocked in Indonesia &middot; DOWN = dead/error &middot; IN USE = currently serving players. The system checks automatically; click “Run check now” for an instant check.</div>
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

    var STATS = <?php echo json_encode(rotator_stats_load(), JSON_UNESCAPED_SLASHES); ?>;
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

    var sideList = document.getElementById('sideList');
    var panels = document.getElementById('panels');
    var tpl = document.getElementById('ruleTpl');
    var rulesForm = document.getElementById('rulesForm');
    var accountForm = document.getElementById('accountForm');
    var sideAccount = document.getElementById('sideAccount');
    var seq = 0;

    function selectPanel(key) {
      var isAccount = (key === 'account');
      rulesForm.style.display = isAccount ? 'none' : 'block';
      panels.querySelectorAll('.panel').forEach(function (p) {
        p.classList.toggle('active', !isAccount && p.dataset.key === key);
      });
      accountForm.classList.toggle('active', isAccount);
      sideList.querySelectorAll('.side-item').forEach(function (s) {
        s.classList.toggle('active', s.dataset.key === key);
      });
      sideAccount.classList.toggle('active', isAccount);
    }

    sideAccount.addEventListener('click', function () { selectPanel('account'); });

    var refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) refreshBtn.addEventListener('click', function(){
      refreshBtn.textContent = 'Refreshing…';
      fetch('status.php', { cache:'no-store' }).then(function(r){ return r.json(); }).then(function(j){
        STATS = (j && j.stats) || {};
        CHECKS = (j && j.checks) || {};
        renderers.forEach(function(fn){ fn(); });
      }).catch(function(){}).then(function(){ refreshBtn.innerHTML = '&#8635; Refresh status'; });
    });

    var checkBtn = document.getElementById('checkBtn');
    if (checkBtn) checkBtn.addEventListener('click', function(){
      checkBtn.textContent = 'Checking…';
      fetch('checker.php', { cache:'no-store' }).then(function(r){ return r.json(); }).then(function(j){
        CHECKS = j || {};
        renderers.forEach(function(fn){ fn(); });
      }).catch(function(){}).then(function(){ checkBtn.textContent = 'Run check now'; });
    });

    // Auto-dismiss the "Saved successfully" / error banners.
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
      node.dataset.id = r.id || '';
      node.dataset.key = key;
      var labelInput = node.querySelector('.f-label');
      var enabledInput = node.querySelector('.f-enabled');
      labelInput.value = r.label || '';
      node.querySelector('.f-hosts').value = r.hosts || '';
      node.querySelector('.f-targets').value = r.targets || '';
      enabledInput.checked = !!r.enabled;
      panels.appendChild(node);

      var item = document.createElement('div');
      item.className = 'side-item' + (r.enabled ? '' : ' off');
      item.dataset.key = key;
      var left = document.createElement('span');
      left.style.display = 'flex'; left.style.alignItems = 'center'; left.style.gap = '8px';
      var dot = document.createElement('span'); dot.className = 'dot';
      var name = document.createElement('span'); name.className = 'nm';
      name.textContent = r.label || 'Untitled';
      left.appendChild(dot); left.appendChild(name);
      item.appendChild(left);
      item.addEventListener('click', function () { selectPanel(key); });
      sideList.appendChild(item);

      var linkInput = node.querySelector('.f-link');
      function slugify(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]/g,''); }
      function updateLink(){ linkInput.value = location.protocol + '//' + location.host + '/?b=' + slugify(labelInput.value); }
      updateLink();
      node.querySelector('.f-copy').addEventListener('click', function(){
        linkInput.select();
        try { navigator.clipboard.writeText(linkInput.value); } catch(e){ try { document.execCommand('copy'); } catch(_){} }
      });

      var statusBox = node.querySelector('.status-list');
      function renderStatus(){
        var slug = slugify(labelInput.value);
        var vs = (STATS && STATS[slug]) || {};
        var ck = (CHECKS && CHECKS[slug]) || {};
        var targets = (node.querySelector('.f-targets').value || '').split(/\r?\n/).map(norm).filter(Boolean);
        statusBox.innerHTML = '';
        if (!targets.length){ statusBox.innerHTML = '<div class="hint">No targets yet — add some and Save.</div>'; item.classList.remove('alert'); return; }
        var firstKey = null;
        targets.forEach(function(u, idx){
          var c = ck[u]; var v = vs[u];
          var key='unknown', cls='wait', txt='NOT CHECKED', reason='Not checked yet', when='';
          // Server check is primary (clean = domain resolves and responds). A single
          // visitor timeout is unreliable for Cloudflare sites, so it can't override a
          // clean check — this prevents false "blocked" alarms.
          if (c && c.status==='clean'){ key='clean'; cls='act'; txt='CLEAN'; reason=c.reason||''; when='checked '+timeAgo(c.ts); }
          else if (c && c.status==='blocked'){ key='blocked'; cls='blk'; txt='BLOCKED'; reason=c.reason||''; when='checked '+timeAgo(c.ts); }
          else if (c && c.status==='down'){ key='down'; cls='wait'; txt='DOWN'; reason=c.reason||''; when='checked '+timeAgo(c.ts); }
          else if (v && v.status==='active'){ key='clean'; cls='act'; txt='CLEAN'; reason='Serving players'; when=timeAgo(v.ts); }
          else if (v && v.status==='blocked'){ key='blocked'; cls='blk'; txt='BLOCKED'; reason='Reported blocked by a visitor'; when=timeAgo(v.ts); }
          var inUse = (v && v.status==='active');
          if (idx===0) firstKey = key;
          var row=document.createElement('div'); row.className='st-row';
          var badge=document.createElement('span'); badge.className='badge '+cls; badge.textContent=txt;
          var mid=document.createElement('div'); mid.className='st-mid';
          var urlEl=document.createElement('div'); urlEl.className='st-url'; urlEl.textContent=u.replace(/^https?:\/\//,'');
          var reasonEl=document.createElement('div'); reasonEl.className='st-reason'; reasonEl.textContent = reason + (when?(' · '+when):'');
          mid.appendChild(urlEl); mid.appendChild(reasonEl);
          row.appendChild(badge); row.appendChild(mid);
          if (inUse){ var tag=document.createElement('span'); tag.className='badge use'; tag.textContent='IN USE'; row.appendChild(tag); }
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
        var ta = node.querySelector('.f-targets');
        var lines = ta.value.split(/\r?\n/).map(function (x) { return x.trim(); }).filter(Boolean);
        if (lines.length < 2) { alert('Add at least 2 backup domains before rotating.'); return; }
        if (!confirm('Force rotate "' + (labelInput.value || 'this brand') + '" to the next domain now?')) return;
        var first = lines.shift(); lines.push(first);
        ta.value = lines.join('\n');
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
          id: n.dataset.id || '',
          label: n.querySelector('.f-label').value,
          hosts: n.querySelector('.f-hosts').value,
          targets: n.querySelector('.f-targets').value,
          enabled: n.querySelector('.f-enabled').checked
        });
      });
      document.getElementById('payload').value = JSON.stringify(out);
    });
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
        btns[i].querySelector('.ico').textContent = dark ? '☀' : '☾';
        btns[i].querySelector('.txt').textContent = dark ? 'Light' : 'Dark';
        btns[i].setAttribute('title', dark ? 'Switch to light theme' : 'Switch to dark theme');
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
</body>
</html>
