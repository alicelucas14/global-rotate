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
        return ['rules' => []];
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
