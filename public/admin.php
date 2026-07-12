<?php
require __DIR__ . '/admin_config.php';
require __DIR__ . '/rotator_lib.php';

session_start();

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
    if (hash_equals(ADMIN_USER, $u) && hash_equals(ADMIN_PASS, $p)) {
        session_regenerate_id(true);
        $_SESSION['rotator_admin'] = true;
    } else {
        usleep(400000); // small delay on failure
        $error = 'Invalid username or password.';
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
$token = csrf_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title>Rotator Admin</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
    background:#0b1020; color:#e7ecf5; }
  header { display:flex; align-items:center; justify-content:space-between;
    padding:14px 20px; background:#111a33; border-bottom:1px solid #1e2b4d; }
  header h1 { font-size:1.05rem; margin:0; }
  main { max-width:920px; margin:0 auto; padding:22px 16px 60px; }
  .msg { padding:10px 14px; border-radius:10px; margin:0 0 16px; font-size:.9rem; }
  .err { background:rgba(255,80,80,.15); border:1px solid rgba(255,80,80,.4); }
  .ok  { background:rgba(80,200,120,.15); border:1px solid rgba(80,200,120,.4); }
  .login { max-width:340px; margin:12vh auto 0; background:#111a33;
    border:1px solid #1e2b4d; border-radius:16px; padding:26px; }
  label { display:block; font-size:.8rem; color:#a9b6ce; margin:12px 0 5px; }
  input[type=text], input[type=password], textarea {
    width:100%; padding:10px 12px; border-radius:10px;
    border:1px solid #2a3a63; background:#0d1530; color:#e7ecf5; font-size:.9rem; }
  textarea { resize:vertical; min-height:64px; font-family:ui-monospace,monospace; }
  button { cursor:pointer; border:0; border-radius:10px; padding:10px 16px;
    font-weight:600; font-size:.9rem; }
  .primary { background:#3b7bff; color:#fff; }
  .ghost { background:#1e2b4d; color:#cdd8f0; }
  .danger { background:rgba(255,80,80,.18); color:#ffb3b3; }
  .rule { background:#111a33; border:1px solid #1e2b4d; border-radius:14px;
    padding:16px; margin:0 0 16px; }
  .rule .row { display:flex; gap:14px; flex-wrap:wrap; }
  .rule .col { flex:1 1 260px; }
  .rule .head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; }
  .toggle { display:flex; align-items:center; gap:7px; font-size:.82rem; color:#a9b6ce; }
  .bar { display:flex; gap:10px; margin-top:18px; }
  .hint { font-size:.72rem; color:#6f7d97; margin-top:4px; }
  a.link { color:#7fa8ff; }
</style>
</head>
<body>
<?php if (!is_logged_in()): ?>
  <div class="login">
    <h1 style="margin:0 0 4px;">Rotator Admin</h1>
    <p style="font-size:.82rem;color:#a9b6ce;margin:0 0 8px;">Sign in to manage redirects.</p>
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
    <form method="post" style="margin:0;">
      <input type="hidden" name="action" value="logout" />
      <button class="ghost" type="submit">Log out</button>
    </form>
  </header>
  <main>
    <?php if ($error): ?><div class="msg err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="msg ok"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

    <p style="font-size:.86rem;color:#a9b6ce;">
      Each <b>rule</b> maps one or more <b>entry domains</b> (the links you hand out) to a
      priority list of <b>target URLs</b>. Visitors to an entry domain are sent to the first
      reachable target and skip any that are blocked.
    </p>

    <form method="post" id="rulesForm">
      <input type="hidden" name="action" value="save" />
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>" />
      <input type="hidden" name="payload" id="payload" />
      <div id="rules"></div>
      <div class="bar">
        <button type="button" class="ghost" id="addRule">+ Add rule</button>
        <button type="submit" class="primary" id="saveBtn">Save all</button>
      </div>
    </form>
  </main>

  <template id="ruleTpl">
    <div class="rule" data-id="">
      <div class="head">
        <input type="text" class="f-label" placeholder="Rule name (e.g. Wings365)" style="max-width:320px;" />
        <div style="display:flex;align-items:center;gap:12px;">
          <label class="toggle"><input type="checkbox" class="f-enabled" checked /> Enabled</label>
          <button type="button" class="danger f-remove">Remove</button>
        </div>
      </div>
      <div class="row">
        <div class="col">
          <label>Entry domains (one per line — the links you give out)</label>
          <textarea class="f-hosts" placeholder="domains-rotate.store"></textarea>
          <div class="hint">Leave empty to make this the default for any unmatched domain.</div>
        </div>
        <div class="col">
          <label>Target URLs (priority order, one per line)</label>
          <textarea class="f-targets" placeholder="https://site-a.com&#10;https://site-b.net"></textarea>
          <div class="hint">First reachable one wins; blocked ones are skipped.</div>
        </div>
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

    var rulesEl = document.getElementById('rules');
    var tpl = document.getElementById('ruleTpl');

    function addRule(r) {
      r = r || { id:'', label:'', hosts:'', targets:'', enabled:true };
      var node = tpl.content.firstElementChild.cloneNode(true);
      node.dataset.id = r.id || '';
      node.querySelector('.f-label').value = r.label || '';
      node.querySelector('.f-hosts').value = r.hosts || '';
      node.querySelector('.f-targets').value = r.targets || '';
      node.querySelector('.f-enabled').checked = !!r.enabled;
      node.querySelector('.f-remove').addEventListener('click', function () { node.remove(); });
      rulesEl.appendChild(node);
    }

    INITIAL.forEach(addRule);
    if (!INITIAL.length) addRule();

    document.getElementById('addRule').addEventListener('click', function () { addRule(); });

    document.getElementById('rulesForm').addEventListener('submit', function () {
      var out = [];
      rulesEl.querySelectorAll('.rule').forEach(function (n) {
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
</body>
</html>
