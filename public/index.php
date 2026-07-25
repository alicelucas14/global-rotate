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

$targets  = rotator_rule_targets($rule);
$pool     = rotator_pool_load();
// CANDIDATES = brand-specific targets if set, else fall back to the global pool.
// If the brand has targets, also append any pool domains not already in the list
// as a final safety net (so there is always somewhere to try).
$candidates = $targets;
if (empty($candidates)) {
    $candidates = $pool;
} else {
    // append pool domains not already covered by this brand's targets
    foreach ($pool as $pu) {
        if (!in_array($pu, $candidates, true)) {
            $candidates[] = $pu;
        }
    }
}
$ruleSlug = $rule ? rotator_slug($rule['slug'] ?? $rule['label'] ?? '') : '';

// Option B: strip known-blocked URLs (Telegram alerts + cron checks) from
// the candidate list before injecting into JS.  Blocked domains go to the
// very end as a last-resort fallback so visitors are never left with zero
// options if every URL happens to be blocked simultaneously.
$blockedUrls  = rotator_blocked_urls();
$cleanCands   = [];
$blockedCands = [];
foreach ($candidates as $cu) {
    if (in_array($cu, $blockedUrls, true)) {
        $blockedCands[] = $cu;
    } else {
        $cleanCands[] = $cu;
    }
}
// Keep blocked at the tail so the JS fallback manual-link list still shows them.
$candidates = array_merge($cleanCands, $blockedCands);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Menghubungkan…</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet" />
  <style>
    /* Follows the visitor's OS theme. No toggle needed — this page is on
       screen for about a second before the redirect fires. */
    :root {
      color-scheme: dark light;
      --bg-a: #0a1628; --bg-b: #060c1a; --bg-c: #0f2040;
      --text: #e8edf8; --text-2: #9ab0d4; --muted: #4e6285;
      --card-bg: rgba(15,28,60,.55); --card-border: rgba(100,150,255,.12);
      --track: rgba(255,255,255,.12); --accent: #4f8cff;
      --link-fg: #c8dcff; --link-bg: rgba(79,140,255,.14);
      --link-border: rgba(79,140,255,.32);
      --link-hover-bg: rgba(79,140,255,.24);
    }
    @media (prefers-color-scheme: light) {
      :root {
        --bg-a: #dce8fb; --bg-b: #f0f4fc; --bg-c: #c8ddf8;
        --text: #131a2e; --text-2: #4a5e7a; --muted: #7a90b0;
        --card-bg: rgba(255,255,255,.72); --card-border: rgba(47,111,228,.14);
        --track: rgba(19,26,42,.1); --accent: #2f6fe4;
        --link-fg: #1a4fb0; --link-bg: rgba(47,111,228,.1);
        --link-border: rgba(47,111,228,.28);
        --link-hover-bg: rgba(47,111,228,.18);
      }
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      min-height: 100vh; display: grid; place-items: center;
      color: var(--text); overflow: hidden;
      -webkit-font-smoothing: antialiased;
    }

    /* layered animated background */
    .bg {
      position: fixed; inset: 0; z-index: 0;
      background: radial-gradient(ellipse 120% 80% at 50% -10%, var(--bg-c), var(--bg-a) 55%, var(--bg-b));
    }
    .orb {
      position: absolute; border-radius: 50%; filter: blur(70px);
      animation: drift 12s ease-in-out infinite alternate;
    }
    .orb-1 {
      width: 420px; height: 420px; top: -120px; left: -80px;
      background: radial-gradient(circle, rgba(79,140,255,.18), transparent 70%);
      animation-duration: 14s;
    }
    .orb-2 {
      width: 360px; height: 360px; bottom: -100px; right: -60px;
      background: radial-gradient(circle, rgba(139,92,246,.14), transparent 70%);
      animation-duration: 18s; animation-delay: -6s;
    }
    .orb-3 {
      width: 280px; height: 280px; top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      background: radial-gradient(circle, rgba(79,140,255,.08), transparent 70%);
      animation-duration: 10s; animation-delay: -3s;
    }
    @keyframes drift {
      from { transform: translate(0, 0) scale(1); }
      to   { transform: translate(30px, 20px) scale(1.08); }
    }
    .orb-2 { animation-name: drift2; }
    @keyframes drift2 {
      from { transform: translate(0, 0) scale(1); }
      to   { transform: translate(-25px, -15px) scale(1.06); }
    }

    /* card */
    .wrap { position: relative; z-index: 1; }
    .card {
      text-align: center; padding: 40px 32px; max-width: 420px; width: 92vw;
      background: var(--card-bg); border: 1px solid var(--card-border);
      border-radius: 24px; backdrop-filter: blur(20px) saturate(1.3);
      -webkit-backdrop-filter: blur(20px) saturate(1.3);
      box-shadow: 0 8px 40px rgba(0,0,0,.25), 0 0 0 1px rgba(255,255,255,.04);
      animation: riseIn .5s cubic-bezier(.22,.68,0,1.2) both;
    }
    @keyframes riseIn {
      from { opacity: 0; transform: translateY(24px) scale(.96); }
      to   { opacity: 1; transform: translateY(0)    scale(1); }
    }

    /* gradient spinner */
    .spinner-wrap {
      position: relative; width: 54px; height: 54px; margin: 0 auto 22px;
    }
    .spinner-ring {
      position: absolute; inset: 0; border-radius: 50%;
      border: 3.5px solid var(--track);
    }
    .spinner-arc {
      position: absolute; inset: 0; border-radius: 50%;
      border: 3.5px solid transparent;
      border-top-color: var(--accent);
      border-right-color: rgba(79,140,255,.4);
      animation: spin .85s linear infinite;
    }
    .spinner-dot {
      position: absolute; width: 8px; height: 8px; border-radius: 50%;
      background: var(--accent); top: 50%; left: 50%;
      transform: translate(-50%,-50%);
      animation: pulse 1.7s ease-in-out infinite;
    }
    @keyframes spin  { to { transform: rotate(360deg); } }
    @keyframes pulse {
      0%,100% { transform: translate(-50%,-50%) scale(1); opacity:.8; }
      50%      { transform: translate(-50%,-50%) scale(1.5); opacity:.3; }
    }
    @media (prefers-reduced-motion: reduce) {
      .spinner-arc { animation-duration: 3s; }
      .orb { animation: none; }
    }

    /* text */
    h1 {
      font-size: 1.1rem; font-weight: 600; letter-spacing: -.01em;
      margin: 0 0 6px; animation: fadeSlide .4s .15s ease both;
    }
    p {
      margin: 4px 0; font-size: .88rem; color: var(--text-2);
      animation: fadeSlide .4s .25s ease both;
    }
    @keyframes fadeSlide {
      from { opacity: 0; transform: translateY(6px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* manual links */
    .links { margin-top: 20px; display: none; }
    .links > p { font-size: .82rem; color: var(--text-2); margin-bottom: 10px; }
    .links a {
      display: flex; align-items: center; justify-content: center;
      margin: 8px 0; padding: 12px 16px; border-radius: 14px;
      text-decoration: none; color: var(--link-fg);
      background: var(--link-bg); border: 1px solid var(--link-border);
      font-weight: 600; font-size: .88rem;
      transition: background .15s, transform .12s;
    }
    .links a:hover { background: var(--link-hover-bg); transform: translateY(-1px); }
    .small {
      font-size: .72rem; color: var(--muted); margin-top: 16px;
      animation: fadeSlide .4s .35s ease both;
    }
  </style>
</head>
<body>
  <div class="bg">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
  </div>
  <div class="wrap">
    <div class="card">
      <div class="spinner-wrap" id="spinnerWrap">
        <div class="spinner-ring"></div>
        <div class="spinner-arc"></div>
        <div class="spinner-dot"></div>
      </div>
      <h1 id="title">Menghubungkan ke server tercepat&hellip;</h1>
      <p id="subtitle">Mohon tunggu sebentar.</p>
      <div class="links" id="links">
        <p>Jika tidak dialihkan otomatis, pilih link di bawah:</p>
      </div>
      <div class="small">Anda akan dialihkan secara otomatis.</div>
    </div>
  </div>

  <script>
  (function () {
    // Targets for THIS entry domain, injected by PHP.
    // Priority: brand-specific targets first, then global pool domains as backup.
    var CANDIDATES = <?php echo json_encode($candidates, JSON_UNESCAPED_SLASHES); ?>;
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
    //
    // Strategy: call our own /probe.php (same-origin) so the check runs from
    // the server, bypassing Cloudflare Bot Fight Mode / WAF on target domains
    // that block cross-origin fetch() requests from browsers.
    // Falls back to a direct browser probe if /probe.php is unavailable.
    function probe(url) {
      var srvUrl = '/probe.php?url=' + encodeURIComponent(norm(url));
      if (typeof fetch === 'function') {
        var ctrl   = typeof AbortController === 'function' ? new AbortController() : null;
        var signal = ctrl ? ctrl.signal : undefined;
        var timer  = ctrl ? setTimeout(function () { ctrl.abort(); }, TIMEOUT_MS) : null;
        return fetch(srvUrl, { cache: 'no-store', signal: signal })
          .then(function (r) { if (timer) clearTimeout(timer); return r.ok ? r.json() : null; })
          .then(function (d) {
            if (!d) return probeDirect(url); // server error → fall back
            if (d.ok)                        return 'ok';
            if (d.status === 'blocked' || d.status === 'down') return 'reject';
            return probeDirect(url);          // unknown status → fall back
          })
          .catch(function () { if (timer) clearTimeout(timer); return probeDirect(url); });
      }
      return probeDirect(url);
    }

    // Direct browser probe — used as fallback when /probe.php is unreachable.
    function probeDirect(url) {
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
        img.onload  = function () { if (!done) { done = true; clearTimeout(timer); resolve('ok'); } };
        img.onerror = function () { if (!done) { done = true; clearTimeout(timer); resolve('reject'); } };
        img.src = target;
      });
    }

    function go(url) {
      try { localStorage.setItem(LAST_GOOD_KEY, url); } catch (e) {}
      window.location.replace(url);
    }

    function showManual(list) {
      document.getElementById('spinnerWrap').style.opacity = '0';
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
