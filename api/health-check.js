import { getPoolUrls } from '../lib/domains.js';
import { evaluate } from '../lib/checker.js';
import { store } from '../lib/store.js';
import { sendAlert } from '../lib/notify.js';

/**
 * Cron endpoint (runs every 10 min via vercel.json).
 * Evaluates every domain, updates the "active" pointer to the first
 * healthy one, persists status, and fires alerts ONLY when a domain's
 * status actually changes (transition-based) — prevents spam when a
 * domain stays blocked across multiple cron runs.
 *
 * Can also be triggered manually: GET /api/health-check
 */
export default async function handler(req, res) {
  const urls = getPoolUrls();
  const results = [];

  for (const url of urls) {
    // eslint-disable-next-line no-await-in-loop
    results.push(await evaluate(url));
  }

  const firstHealthy = results.find((r) => r.status === 'ACTIVE');
  const active = firstHealthy ? firstHealthy.url : urls[0];

  const prev = await store.getJSON('rotator:state', { active: null, results: [] });

  // Build a map of previous statuses per URL so we can detect transitions.
  const prevStatusMap = {};
  if (Array.isArray(prev.results)) {
    for (const r of prev.results) {
      prevStatusMap[r.url] = r.status;
    }
  }

  await store.setJSON('rotator:state', {
    active,
    results,
    updated_at: new Date().toISOString()
  });

  // ── Transition-based alerts ────────────────────────────────────────────
  // Only fire when a domain's status *changes*. This means:
  //  - A domain that was ACTIVE and is now BLOCKED/DOWN  → alert once
  //  - A domain that recovers (BLOCKED/DOWN → ACTIVE)    → alert once
  //  - A domain that stays BLOCKED across runs           → no repeat spam
  const newlyBroken = results.filter((r) => {
    const was = prevStatusMap[r.url];
    const isBad = r.status === 'BLOCKED' || r.status === 'DOWN';
    const wasBad = was === 'BLOCKED' || was === 'DOWN';
    // Alert only on fresh transitions: good→bad, or first-ever run (no prior state)
    return isBad && (!wasBad || was === undefined);
  });

  const newlyRecovered = results.filter((r) => {
    const was = prevStatusMap[r.url];
    return r.status === 'ACTIVE' && (was === 'BLOCKED' || was === 'DOWN');
  });

  // Active domain switched
  if (prev.active && prev.active !== active) {
    const summary = results.map((r) => `${r.status.padEnd(8)} ${r.url}`).join('\n');
    await sendAlert(
      `🔄 [Rotator] Active domain switched → ${active}`,
      `The rotator switched to a new active domain.\n\nOld: ${prev.active}\nNew: ${active}\n\n${summary}`
    );
  }

  // Newly blocked or down domains (transition only)
  if (newlyBroken.length > 0) {
    const lines = newlyBroken.map((r) => `${r.status}  ${r.url}`).join('\n');
    await sendAlert(
      `🚫 [Rotator] ${newlyBroken.length} domain(s) newly unavailable`,
      `The following domain(s) just became unavailable:\n\n${lines}`
    );
  }

  // Recovered domains (transition only)
  if (newlyRecovered.length > 0) {
    const lines = newlyRecovered.map((r) => `✅  ${r.url}`).join('\n');
    await sendAlert(
      `✅ [Rotator] ${newlyRecovered.length} domain(s) recovered`,
      `The following domain(s) are back online:\n\n${lines}`
    );
  }

  res.status(200).json({ active, count: results.length, results, persisted: store.enabled });
}

