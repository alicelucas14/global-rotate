<?php
require __DIR__ . '/rotator_lib.php';

$data = rotator_load();

// Decide which brand this visitor belongs to:
//   1. a dedicated entry domain (host) wins,
//   2. else a brand slug from ?b=slug or the URL path (/slug),
//   3. else the first enabled brand as a default.
$rule = rotator_match_host($data, $_SERVER['HTTP_HOST'] ?? '');
if (!$rule) {
    $slug = isset($_GET['b']) ? $_GET['b'] : '';
    if ($slug === '') {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $slug = trim((string)$path, '/');
    }
    $rule = rotator_match_slug($data, $slug);
}
if (!$rule) {
    $rule = rotator_first_enabled($data);
}

$targets = rotator_rule_targets($rule);
$ruleSlug = $rule ? rotator_slug($rule['slug'] ?? $rule['label'] ?? '') : '';
?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Menghubungkan…</title>
  <style>
    :root { color-scheme: dark; }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; display: grid; place-items: center;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      background: radial-gradient(1200px 600px at 50% -10%, #1b2a4a, #0b1020);
      color: #e7ecf5;
    }
    .card {
      text-align: center; padding: 32px 28px; max-width: 420px; width: 92%;
      background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
      border-radius: 18px; backdrop-filter: blur(6px);
    }
    .spinner {
      width: 46px; height: 46px; margin: 6px auto 18px;
      border: 4px solid rgba(255,255,255,.15); border-top-color: #5b9dff;
      border-radius: 50%; animation: spin 0.9s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    h1 { font-size: 1.15rem; margin: 0 0 6px; font-weight: 600; }
    p  { margin: 4px 0; font-size: .92rem; color: #a9b6ce; }
    .links { margin-top: 18px; display: none; }
    .links a {
      display: block; margin: 8px 0; padding: 11px 14px; border-radius: 12px;
      text-decoration: none; color: #dbe6ff; background: rgba(91,157,255,.14);
      border: 1px solid rgba(91,157,255,.35); font-weight: 600;
    }
    .small { font-size: .78rem; color: #6f7d97; margin-top: 14px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="spinner" id="spinner"></div>
    <h1 id="title">Menghubungkan ke server tercepat…</h1>
    <p id="subtitle">Mohon tunggu sebentar.</p>
    <div class="links" id="links">
      <p>Jika tidak dialihkan otomatis, pilih link di bawah:</p>
    </div>
    <div class="small">Anda akan dialihkan secara otomatis.</div>
  </div>

  <script>
  (function () {
    // Targets for THIS entry domain, injected by PHP from the admin panel.
    var CANDIDATES = <?php echo json_encode($targets, JSON_UNESCAPED_SLASHES); ?>;
    var BRAND = <?php echo json_encode($ruleSlug, JSON_UNESCAPED_SLASHES); ?>;

    // Tell the admin which target we used and which we had to skip.
    function report(activeUrl, blockedList) {
      if (!BRAND) return;
      try {
        var d = new URLSearchParams();
        d.set('b', BRAND);
        if (activeUrl) d.set('active', activeUrl);
        (blockedList || []).forEach(function (u) { d.append('blocked', u); });
        if (navigator.sendBeacon) navigator.sendBeacon('/report.php', d);
        else fetch('/report.php', { method: 'POST', body: d, keepalive: true });
      } catch (e) {}
    }

    var TIMEOUT_MS = 6000;
    var LAST_GOOD_KEY = 'rotator_last_good_' + location.hostname;

    function norm(u) { return String(u || '').trim().replace(/\/+$/, ''); }

    // probe -> 'ok'      : reachable
    //          'reject'  : DNS/connection failure (real block or dead domain)
    //          'timeout' : no answer in time (ambiguous: slow site / Cloudflare)
    function probe(url) {
      var target = norm(url) + '/favicon.ico?_r=' + Date.now();
      if (typeof fetch === 'function' && typeof AbortController === 'function') {
        var ctrl = new AbortController();
        var aborted = false;
        var timer = setTimeout(function () { aborted = true; ctrl.abort(); }, TIMEOUT_MS);
        return fetch(target, { mode: 'no-cors', cache: 'no-store', signal: ctrl.signal })
          .then(function () { clearTimeout(timer); return 'ok'; })
          .catch(function () { clearTimeout(timer); return aborted ? 'timeout' : 'reject'; });
      }
      return new Promise(function (resolve) {
        var done = false;
        var img = new Image();
        var timer = setTimeout(function () { if (!done) { done = true; resolve('timeout'); } }, TIMEOUT_MS);
        img.onload = function () { if (!done) { done = true; clearTimeout(timer); resolve('ok'); } };
        img.onerror = function () { if (!done) { done = true; clearTimeout(timer); resolve('reject'); } };
        img.src = target;
      });
    }

    function go(url) {
      try { localStorage.setItem(LAST_GOOD_KEY, url); } catch (e) {}
      window.location.replace(url);
    }

    function showManual(list) {
      document.getElementById('spinner').style.display = 'none';
      document.getElementById('title').textContent = list.length ? 'Pilih link untuk melanjutkan' : 'Belum ada tujuan';
      document.getElementById('subtitle').style.display = 'none';
      var box = document.getElementById('links');
      box.style.display = 'block';
      if (!list.length) {
        box.querySelector('p').textContent = 'Konfigurasi belum diatur.';
        return;
      }
      list.forEach(function (u) {
        var a = document.createElement('a');
        a.href = u; a.textContent = u.replace(/^https?:\/\//, '');
        a.rel = 'noopener';
        box.appendChild(a);
      });
    }

    async function pickAndGo(list) {
      list = list.map(norm).filter(Boolean);
      if (!list.length) return showManual(list);

      var last = null;
      try { last = localStorage.getItem(LAST_GOOD_KEY); } catch (e) {}
      if (last && list.indexOf(last) !== -1) {
        if (await probe(last) === 'ok') { report(last, []); return go(last); }
      }
      // Only DEFINITIVE failures (DNS/connection reject) are reported as blocked.
      // Timeouts are ambiguous (slow / Cloudflare) so we skip them for the redirect
      // but do NOT report them as blocked, to avoid false "blocked" alarms.
      var rejected = [];
      for (var i = 0; i < list.length; i++) {
        // eslint-disable-next-line no-await-in-loop
        var st = await probe(list[i]);
        if (st === 'ok') { report(list[i], rejected); return go(list[i]); }
        if (st === 'reject') rejected.push(list[i]);
      }
      report('', rejected);
      showManual(list);
    }

    pickAndGo(CANDIDATES);
  })();
  </script>
</body>
</html>
