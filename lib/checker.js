/**
 * Blocking / reachability detection.
 *
 * Two independent signals are used:
 *
 * 1. Direct reachability  — can THIS server fetch the domain at all?
 *    (catches dead deployments, DNS failures, 5xx.)
 *
 * 2. Indonesia block check — is the domain reachable from Indonesian
 *    network vantage points? Uses the free check-host.net API, which has
 *    nodes inside Indonesia (id1.node.check-host.net ...). If every
 *    Indonesian node fails but the site is up globally, it is very likely
 *    blocked by Indonesian ISPs / Kominfo (TrustPositif / Nawala).
 *
 * check-host.net is best-effort: node availability changes and it is
 * rate-limited. The client-side gateway (public/index.html) is the most
 * reliable detector because it runs from the visitor's real Indonesian ISP.
 */

const CHECK_HOST = 'https://check-host.net';
const ID_NODE_PREFIXES = ['id', 'id1', 'id2']; // Indonesian nodes

/** Fetch with a hard timeout. */
async function fetchWithTimeout(url, opts = {}, ms = 8000) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), ms);
  try {
    return await fetch(url, { ...opts, signal: ctrl.signal });
  } finally {
    clearTimeout(t);
  }
}

/** Can this server reach the URL directly? */
export async function checkDirect(url) {
  try {
    const res = await fetchWithTimeout(
      url,
      { method: 'GET', redirect: 'follow', headers: { 'User-Agent': 'GlobalRotate/1.0' } },
      8000
    );
    return { ok: res.ok || (res.status >= 200 && res.status < 400), status: res.status };
  } catch (err) {
    return { ok: false, status: 0, error: String(err && err.message || err) };
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Ask check-host.net to test the URL over HTTP from all nodes, then read
 * back only the Indonesian nodes. Returns:
 *   { available: boolean|null, idNodesUp: n, idNodesTotal: n }
 * available === null means the check itself could not be completed.
 */
export async function checkIndonesia(url) {
  try {
    const host = encodeURIComponent(url);
    const start = await fetchWithTimeout(
      `${CHECK_HOST}/check-http?host=${host}&max_nodes=40`,
      { headers: { Accept: 'application/json' } },
      8000
    );
    const started = await start.json();
    const reqId = started && started.request_id;
    if (!reqId || !started.nodes) return { available: null, idNodesUp: 0, idNodesTotal: 0 };

    // Which returned nodes are Indonesian?
    const idNodes = Object.keys(started.nodes).filter((node) =>
      ID_NODE_PREFIXES.some((p) => node.toLowerCase().startsWith(p + '.') || node.toLowerCase().startsWith(p + '-'))
    );
    if (idNodes.length === 0) return { available: null, idNodesUp: 0, idNodesTotal: 0 };

    // Poll for results (checks take a few seconds).
    let results = {};
    for (let i = 0; i < 6; i++) {
      await sleep(2000);
      const res = await fetchWithTimeout(
        `${CHECK_HOST}/check-result/${reqId}`,
        { headers: { Accept: 'application/json' } },
        8000
      );
      results = await res.json();
      const done = idNodes.every((n) => results[n] !== null && results[n] !== undefined);
      if (done) break;
    }

    let up = 0;
    for (const n of idNodes) {
      const r = results[n];
      // check-host http result shape: [ [1, time, "OK", statusText, ip] ] on success
      if (Array.isArray(r) && Array.isArray(r[0]) && r[0][0] === 1) up++;
    }

    return {
      available: up > 0,
      idNodesUp: up,
      idNodesTotal: idNodes.length
    };
  } catch (err) {
    return { available: null, idNodesUp: 0, idNodesTotal: 0, error: String(err && err.message || err) };
  }
}

/**
 * Full status for one URL: combines direct + Indonesia signals.
 * status is one of: ACTIVE | BLOCKED | DOWN | UNKNOWN
 */
export async function evaluate(url) {
  const direct = await checkDirect(url);
  const id = await checkIndonesia(url);

  let status = 'UNKNOWN';
  if (!direct.ok) {
    status = 'DOWN'; // site itself is unreachable everywhere
  } else if (id.available === false) {
    status = 'BLOCKED'; // up globally, dead from Indonesia => blocked
  } else if (id.available === true) {
    status = 'ACTIVE';
  } else {
    // Indonesia check inconclusive; trust direct reachability.
    status = direct.ok ? 'ACTIVE' : 'DOWN';
  }

  return {
    url,
    status,
    direct,
    indonesia: id,
    checked_at: new Date().toISOString()
  };
}
