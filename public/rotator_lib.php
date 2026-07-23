<?php
/**
 * Shared helpers for the rotator gateway + admin panel.
 *
 * Data is stored in rotator-data.json ONE LEVEL ABOVE the web root
 * (i.e. outside /public), so it is never directly downloadable.
 */

function rotator_data_path() {
    return __DIR__ . '/../rotator-data.json';
}

function rotator_load() {
    $path = rotator_data_path();
    if (!file_exists($path)) {
        return ['rules' => []];
    }
    $raw = @file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['rules']) || !is_array($data['rules'])) {
        return ['rules' => [], 'pool' => []];
    }
    if (!isset($data['pool']) || !is_array($data['pool'])) {
        $data['pool'] = [];
    }
    return $data;

}

function rotator_save($data) {
    $path = rotator_data_path();
    $tmp  = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, $path);
}

function rotator_norm_host($h) {
    $h = strtolower(trim((string)$h));
    $h = preg_replace('/:\d+$/', '', $h); // strip port
    return $h;
}

function rotator_norm_url($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    if (!preg_match('#^https?://#i', $u)) {
        $u = 'https://' . $u;
    }
    // Basic sanity: must look like a URL, block javascript: etc.
    if (!preg_match('~^https?://[^\s/$.?#].[^\s]*$~i', $u)) {
        return '';
    }
    return rtrim($u, '/');
}

/**
 * Find the rule whose "hosts" list contains the current request host.
 * Falls back to the first enabled rule if nothing matches.
 */
function rotator_match_rule($data, $host) {
    $host      = rotator_norm_host($host);
    $hostNoWww = preg_replace('/^www\./', '', $host);
    $fallback  = null;

    foreach ($data['rules'] as $rule) {
        if (empty($rule['enabled'])) continue;
        if ($fallback === null) $fallback = $rule;

        $hosts = (isset($rule['hosts']) && is_array($rule['hosts'])) ? $rule['hosts'] : [];
        foreach ($hosts as $rh) {
            $rh      = rotator_norm_host($rh);
            $rhNoWww = preg_replace('/^www\./', '', $rh);
            if ($rh !== '' && ($rh === $host || $rhNoWww === $hostNoWww)) {
                return $rule;
            }
        }
    }
    return $fallback;
}

/** Return clean, priority-ordered target URLs for a rule. */
function rotator_rule_targets($rule) {
    $out = [];
    if ($rule && !empty($rule['targets']) && is_array($rule['targets'])) {
        foreach ($rule['targets'] as $t) {
            $n = rotator_norm_url($t);
            if ($n !== '') $out[] = $n;
        }
    }
    return $out;
}

/** Turn a label/name into a URL-safe slug, e.g. "Wings 365" -> "wings365". */
function rotator_slug($s) {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s));
}

/** Exact enabled host match, or null. */
function rotator_match_host($data, $host) {
    $host      = rotator_norm_host($host);
    $hostNoWww = preg_replace('/^www\./', '', $host);
    foreach ($data['rules'] as $rule) {
        if (empty($rule['enabled'])) continue;
        $hosts = (isset($rule['hosts']) && is_array($rule['hosts'])) ? $rule['hosts'] : [];
        foreach ($hosts as $rh) {
            $rh      = rotator_norm_host($rh);
            $rhNoWww = preg_replace('/^www\./', '', $rh);
            if ($rh !== '' && ($rh === $host || $rhNoWww === $hostNoWww)) {
                return $rule;
            }
        }
    }
    return null;
}

/** Enabled rule whose slug matches (from ?b= or the URL path), or null. */
function rotator_match_slug($data, $slug) {
    $slug = rotator_slug($slug);
    if ($slug === '') return null;
    foreach ($data['rules'] as $rule) {
        if (empty($rule['enabled'])) continue;
        $rs = rotator_slug($rule['slug'] ?? $rule['label'] ?? '');
        if ($rs !== '' && $rs === $slug) return $rule;
    }
    return null;
}

/** First enabled rule (used as the default), or null. */
function rotator_first_enabled($data) {
    foreach ($data['rules'] as $rule) {
        if (!empty($rule['enabled'])) return $rule;
    }
    return null;
}

/* ---- Live status stats (what visitors' browsers actually saw) ---- */

function rotator_stats_path() {
    return __DIR__ . '/../rotator-stats.json';
}

function rotator_stats_load() {
    $p = rotator_stats_path();
    if (!file_exists($p)) return [];
    $d = json_decode(@file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

function rotator_stats_save($d) {
    $p = rotator_stats_path();
    return @file_put_contents($p, json_encode($d, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

/* ---- Automatic health/block checks (run by cron or on demand) ---- */

function rotator_checks_path() {
    return __DIR__ . '/../rotator-checks.json';
}
function rotator_checks_load() {
    $p = rotator_checks_path();
    if (!file_exists($p)) return [];
    $d = json_decode(@file_get_contents($p), true);
    return is_array($d) ? $d : [];
}
function rotator_checks_save($d) {
    return @file_put_contents(rotator_checks_path(), json_encode($d, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

/**
 * Check a single URL and classify it:
 *   clean   - reachable and not a block page
 *   blocked - reached an Internet Positif / Trust+ notice, or connection blocked
 *   down    - DNS doesn't resolve, or the site returns an error
 * Returns ['status'=>..., 'reason'=>..., 'http'=>int].
 */
function rotator_check_url($url) {
    $url = rotator_norm_url($url);
    if ($url === '') return ['status' => 'down', 'reason' => 'Invalid URL', 'http' => 0];

    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return ['status' => 'down', 'reason' => 'Invalid URL', 'http' => 0];

    // 1. DNS resolution
    $ips = @gethostbynamel($host);
    if (!$ips) {
        return ['status' => 'down', 'reason' => 'Domain does not resolve (DNS / NXDOMAIN)', 'http' => 0];
    }

    if (!function_exists('curl_init')) {
        // Fallback: DNS resolved, assume reachable.
        return ['status' => 'clean', 'reason' => 'Resolves (no HTTP check available)', 'http' => 0];
    }

    // 2. HTTP fetch (follow redirects, grab a body sample)
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: id-ID,id;q=0.9,en;q=0.8',
        ],
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($errno) {
        $reason = ($errno === 28)
            ? 'Connection timed out (often an ISP block or the site is offline)'
            : 'Connection failed (reset/refused — possible block)';
        return ['status' => 'blocked', 'reason' => $reason, 'http' => 0];
    }

    // 3. Block-page detection (Internet Positif / Trust+ / ISP notices)
    $hay = strtolower($final . ' ' . substr((string)$body, 0, 5000));
    $markers = [
        'internetpositif', 'internet positif', 'trustpositif', 'trust+positif',
        'aduankonten', 'diblokir', 'pemblokiran', 'komdigi', 'kominfo', 'nawala',
        'laman ini diblokir', 'situs yang anda kunjungi', 'newkominfo', 'positifme'
    ];
    foreach ($markers as $m) {
        if (strpos($hay, $m) !== false) {
            return ['status' => 'blocked', 'reason' => 'Redirected to an Internet Positif / Trust+ block notice', 'http' => $http];
        }
    }

    if ($http >= 200 && $http < 400) {
        return ['status' => 'clean', 'reason' => 'Reachable and clean (HTTP ' . $http . ')', 'http' => $http];
    }
    if (in_array($http, [401, 403, 406, 429, 503], true)) {
        return ['status' => 'clean', 'reason' => 'Reachable, but protected by anti-bot / WAF (HTTP ' . $http . ') — not blocked', 'http' => $http];
    }
    if ($http === 404 || $http === 410) {
        return ['status' => 'down', 'reason' => 'Page not found (HTTP ' . $http . ')', 'http' => $http];
    }
    if ($http >= 500) {
        return ['status' => 'down', 'reason' => 'Site error (HTTP ' . $http . ')', 'http' => $http];
    }
    if ($http > 0) {
        return ['status' => 'clean', 'reason' => 'Reachable (HTTP ' . $http . ')', 'http' => $http];
    }
    return ['status' => 'down', 'reason' => 'No response from server', 'http' => $http];
}

/** Check every enabled brand's target URLs and persist the results. */
function rotator_run_checks() {
    $data   = rotator_load();
    $checks = [];
    foreach ($data['rules'] as $rule) {
        if (empty($rule['enabled'])) continue;
        $slug = rotator_slug($rule['slug'] ?? $rule['label'] ?? '');
        if ($slug === '') continue;
        $checks[$slug] = [];
        foreach (($rule['targets'] ?? []) as $t) {
            $u = rotator_norm_url($t);
            if ($u === '') continue;
            $r = rotator_check_url($u);
            $r['ts'] = gmdate('c');
            $checks[$slug][$u] = $r;
        }
    }
    rotator_checks_save($checks);
    return $checks;
}

/* ---- Global domain pool (shared fallback candidates for the gateway) ---- */

/** Return the ordered list of all domains in the global pool. */
function rotator_pool_load() {
    $data = rotator_load();
    return $data['pool'] ?? [];
}

/**
 * Persist the global domain pool.
 * URLs are normalised and deduped; order is preserved (priority order).
 */
function rotator_pool_save($urls) {
    $data = rotator_load();
    $clean = [];
    $seen  = [];
    foreach ($urls as $u) {
        $n = rotator_norm_url(trim((string)$u));
        if ($n !== '' && !in_array($n, $seen, true)) {
            $clean[] = $n;
            $seen[]  = $n;
        }
    }
    $data['pool'] = $clean;
    return rotator_save($data);
}

