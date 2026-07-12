/**
 * Domain pool configuration.
 *
 * List your candidate domains / Vercel URLs in PRIORITY order.
 * The rotator always sends visitors to the FIRST one that is reachable.
 *
 * - `label`  : short name for dashboards / logs
 * - `url`    : full https URL (mirror domain or vercel.app deployment)
 *
 * You can also override this list at runtime with the DOMAINS env var
 * (comma-separated URLs), which is handy on Vercel without redeploying code.
 */

const FALLBACK_POOL = [
  { label: 'wings365', url: 'https://wings365fun.skin' }
  // Add backup mirrors below (priority order) so the rotator has somewhere to
  // send visitors when the primary is blocked in Indonesia, e.g.:
  // { label: 'mirror-1', url: 'https://your-mirror-1.skin' },
  // { label: 'mirror-2', url: 'https://your-mirror-2.com' }
];

/** Normalize a URL: force https, strip trailing slashes. */
export function normalizeUrl(raw) {
  let u = String(raw || '').trim();
  if (!u) return '';
  if (!/^https?:\/\//i.test(u)) u = 'https://' + u;
  return u.replace(/\/+$/, '');
}

/** Returns the ordered candidate pool (env override wins). */
export function getPool() {
  const env = process.env.DOMAINS;
  if (env && env.trim()) {
    return env
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)
      .map((url, i) => ({ label: `d${i + 1}`, url: normalizeUrl(url) }));
  }
  return FALLBACK_POOL.map((d) => ({ ...d, url: normalizeUrl(d.url) }));
}

/** Just the URLs, in priority order. */
export function getPoolUrls() {
  return getPool().map((d) => d.url);
}
