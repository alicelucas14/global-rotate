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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
  <script src="tornado.js"></script>
  <style>
    #tornado-bg {
      position: fixed; inset: 0; width: 100%; height: 100%; z-index: 0;
      pointer-events: none; background: #11143C;
      transition: opacity 0.35s ease;
    }
    @media (prefers-color-scheme: light) {
      #tornado-bg { opacity: 0.25; }
    }
    :root {
      color-scheme: dark light;
      --font: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      --font-mono: 'JetBrains Mono', 'SF Mono', monospace;

      /* Deep Space Obsidian */
      --bg: #070b14;
      --bg-gradient: radial-gradient(ellipse 100% 80% at 50% -10%, #172554, #0b1120 50%, #070b14 100%);
      --text: #f8fafc;
      --text-2: #cbd5e1;
      --muted: #64748b;
      
      --card-bg: rgba(15, 23, 42, 0.72);
      --card-border: rgba(255, 255, 255, 0.1);
      --card-highlight: inset 0 1px 0 rgba(255, 255, 255, 0.14);
      --card-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.05);

      --track: rgba(255, 255, 255, 0.08);
      --accent: #3b82f6;
      --accent-glow: rgba(59, 130, 246, 0.4);
      --accent-2: #60a5fa;

      --badge-bg: rgba(59, 130, 246, 0.12);
      --badge-border: rgba(59, 130, 246, 0.28);
      --badge-text: #93c5fd;

      --link-fg: #93c5fd;
      --link-bg: rgba(59, 130, 246, 0.1);
      --link-border: rgba(59, 130, 246, 0.25);
      --link-hover-bg: rgba(59, 130, 246, 0.2);
      --link-hover-border: rgba(96, 165, 250, 0.45);
    }



    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: var(--font);
      min-height: 100vh; display: grid; place-items: center;
      background: var(--bg); color: var(--text); overflow: hidden;
      -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
      text-rendering: optimizeLegibility;
    }

    /* Ambient animated lighting */
    .bg {
      position: fixed; inset: 0; z-index: 0;
      background: var(--bg-gradient);
    }
    .orb {
      position: absolute; border-radius: 50%; filter: blur(80px);
      animation: drift 14s ease-in-out infinite alternate;
    }
    .orb-1 {
      width: 480px; height: 480px; top: -140px; left: -100px;
      background: radial-gradient(circle, rgba(59, 130, 246, 0.22), transparent 70%);
      animation-duration: 16s;
    }
    .orb-2 {
      width: 420px; height: 420px; bottom: -120px; right: -80px;
      background: radial-gradient(circle, rgba(99, 102, 241, 0.18), transparent 70%);
      animation-duration: 20s; animation-delay: -7s; animation-name: drift2;
    }
    .orb-3 {
      width: 320px; height: 320px; top: 45%; left: 50%;
      transform: translate(-50%, -50%);
      background: radial-gradient(circle, rgba(16, 185, 129, 0.08), transparent 70%);
      animation-duration: 12s; animation-delay: -3s;
    }
    @keyframes drift {
      from { transform: translate(0, 0) scale(1); }
      to   { transform: translate(35px, 25px) scale(1.1); }
    }
    @keyframes drift2 {
      from { transform: translate(0, 0) scale(1); }
      to   { transform: translate(-30px, -20px) scale(1.08); }
    }
    @media (prefers-reduced-motion: reduce) {
      .spinner-arc, .orb { animation: none; }
    }

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
        opacity: 0.5;
        filter: blur(20px) saturate(1.4);
        transform: scale(0.992);
      }
      50% {
        opacity: 0.9;
        filter: blur(30px) saturate(1.9);
        transform: scale(1.018);
      }
    }

    /* Gateway Card with OriginKit Pulsating Border */
    .wrap { position: relative; z-index: 1; padding: 24px; }
    .card {
      text-align: center; padding: 44px 36px; max-width: 440px; width: 92vw;
      background: var(--card-bg); border: 1px solid var(--card-border);
      border-radius: 26px; backdrop-filter: blur(28px) saturate(1.4);
      -webkit-backdrop-filter: blur(28px) saturate(1.4);
      box-shadow: var(--card-shadow), var(--card-highlight);
      position: relative;
      animation: riseIn 0.45s cubic-bezier(0.16, 1, 0.3, 1) both;
      z-index: 1;
    }
    .card > * {
      position: relative;
      z-index: 3;
    }

    /* Outer Pulsating Bloom / Smoke strictly at the edge */
    .card::before {
      content: '';
      position: absolute;
      inset: -14px;
      border-radius: inherit;
      padding: 16px;
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
      filter: blur(12px);
      opacity: 0.75;
      z-index: -2;
      pointer-events: none;
      animation: pulseBorderRotate 6s linear infinite, pulseBorderGlow 3.8s ease-in-out infinite;
    }

    /* Crisp Pulsating Border Edge Line */
    .card::after {
      content: '';
      position: absolute;
      inset: -2px;
      border-radius: inherit;
      padding: 2.5px;
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

    @keyframes riseIn {
      from { opacity: 0; transform: translateY(22px) scale(0.97); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    /* Security shield badge */
    .shield-badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 5px 12px; border-radius: 9999px;
      background: var(--badge-bg); border: 1px solid var(--badge-border);
      color: var(--badge-text); font-size: 0.7rem; font-weight: 700;
      letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 24px;
    }

    /* High-tech Gradient Spinner */
    .spinner-wrap {
      position: relative; width: 58px; height: 58px; margin: 0 auto 24px;
      transition: opacity 0.25s ease, height 0.25s ease, margin 0.25s ease;
    }
    .spinner-wrap[style*="opacity: 0"] {
      height: 0; margin-bottom: 0; overflow: hidden;
    }
    .spinner-ring {
      position: absolute; inset: 0; border-radius: 50%;
      border: 3px solid var(--track);
    }
    .spinner-arc {
      position: absolute; inset: 0; border-radius: 50%;
      border: 3px solid transparent;
      border-top-color: var(--accent);
      border-right-color: var(--accent-2);
      box-shadow: 0 0 16px var(--accent-glow);
      animation: spin 0.85s linear infinite;
    }
    .spinner-dot {
      position: absolute; width: 9px; height: 9px; border-radius: 50%;
      background: var(--accent); top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      box-shadow: 0 0 10px var(--accent-glow);
      animation: pulse 1.6s ease-in-out infinite;
    }
    @keyframes spin  { to { transform: rotate(360deg); } }
    @keyframes pulse {
      0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.9; }
      50%      { transform: translate(-50%, -50%) scale(1.4); opacity: 0.4; }
    }

    /* Typography */
    h1 {
      font-size: 1.2rem; font-weight: 700; letter-spacing: -0.018em;
      margin: 0 0 8px; line-height: 1.35;
      animation: fadeSlide 0.4s 0.15s ease both;
    }
    p {
      margin: 4px 0; font-size: 0.9rem; color: var(--text-2);
      animation: fadeSlide 0.4s 0.22s ease both; line-height: 1.5;
    }
    @keyframes fadeSlide {
      from { opacity: 0; transform: translateY(6px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* Fallback Manual Links */
    .links { margin-top: 22px; display: none; }
    .links > p { font-size: 0.82rem; color: var(--text-2); margin-bottom: 12px; }
    .links a {
      display: flex; align-items: center; justify-content: space-between;
      margin: 8px 0; padding: 13px 18px; border-radius: 14px;
      text-decoration: none; color: var(--link-fg);
      background: var(--link-bg); border: 1px solid var(--link-border);
      font-weight: 600; font-size: 0.88rem; font-family: var(--font-mono);
      transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .links a::after {
      content: '→'; font-size: 1rem; opacity: 0.7; transition: transform 0.15s ease;
    }
    .links a:hover {
      background: var(--link-hover-bg); border-color: var(--link-hover-border);
      transform: translateY(-2px); box-shadow: 0 4px 16px var(--accent-glow);
    }
    .links a:hover::after { transform: translateX(3px); opacity: 1; }

    .small {
      font-size: 0.74rem; color: var(--muted); margin-top: 20px;
      display: flex; align-items: center; justify-content: center; gap: 6px;
      animation: fadeSlide 0.4s 0.3s ease both;
    }
    .small-dot { width: 5px; height: 5px; border-radius: 50%; background: #10b981; }
  </style>
</head>
<body>
  <div id="tornado-bg">
    <canvas id="tornado-canvas"></canvas>
  </div>
  <div class="wrap">
    <div class="card">
      <div class="shield-badge">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span>Jalur Cepat &bull; Auto Failover</span>
      </div>
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
      <div class="small">
        <span class="small-dot"></span>
        <span>Anda akan dialihkan secara otomatis</span>
      </div>
    </div>
  </div>

  <script>
  (function () {
    // Targets for THIS entry domain, injected by PHP.
    // Priority: brand-specific targets first, then global pool domains as backup.
    var CANDIDATES = <?php echo json_encode($candidates, JSON_UNESCAPED_SLASHES); ?>;
    var BRAND = <?php echo json_encode($ruleSlug, JSON_UNESCAPED_SLASHES); ?>;
    // Server-side known-blocked domains (Telegram alerts + cron checks).
    // Used to skip the localStorage shortcut for a domain that has been blocked
    // since the visitor's last successful visit.
    var BLOCKED_CANDS = <?php echo json_encode(array_values($blockedCands), JSON_UNESCAPED_SLASHES); ?>;

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

    function getFinalUrl(baseUrl) {
      try {
        var dest = new URL(baseUrl);
        var path = location.pathname || '';
        if (path && path !== '/') {
          dest.pathname = (dest.pathname.replace(/\/$/, '') + '/' + path.replace(/^\//, '')).replace(/\/+$/, '');
        }
        if (location.search) {
          var incomingParams = new URLSearchParams(location.search);
          incomingParams.forEach(function (val, key) {
            if (key !== 'b') {
              dest.searchParams.set(key, val);
            }
          });
        }
        return dest.toString();
      } catch (e) {
        return baseUrl;
      }
    }

    function go(url) {
      var finalUrl = getFinalUrl(url);
      try { localStorage.setItem(LAST_GOOD_KEY, url); } catch (e) {}
      window.location.replace(finalUrl);
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
        var targetUrl = getFinalUrl(u);
        var a = document.createElement('a');
        a.href = targetUrl; a.textContent = targetUrl.replace(/^https?:\/\//, '');
        a.rel = 'noopener';
        box.appendChild(a);
      });
    }

    async function pickAndGo(list) {
      list = list.map(norm).filter(Boolean);
      if (!list.length) return showManual(list);

      var last = null;
      try { last = localStorage.getItem(LAST_GOOD_KEY); } catch (e) {}
      // Only use the localStorage shortcut if the cached domain is not in the
      // server-side blocked list. Blocked domains can still respond to HTTP from
      // outside Indonesia, so probe() alone cannot reliably rule them out.
      if (last && list.indexOf(last) !== -1 && BLOCKED_CANDS.indexOf(norm(last)) === -1) {
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
    if (window.initTornado) {
      window.initTornado("tornado-canvas", "tornado-bg");
    }
  })();
  </script>
</body>
</html>
