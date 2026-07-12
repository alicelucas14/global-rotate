import { getPoolUrls } from '../lib/domains.js';
import { evaluate } from '../lib/checker.js';
import { store } from '../lib/store.js';
import { sendAlert } from '../lib/notify.js';

/**
 * Cron endpoint (runs every 10 min via vercel.json).
 * Evaluates every domain, updates the "active" pointer to the first
 * healthy one, persists status, and emails when something breaks.
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
  await store.setJSON('rotator:state', {
    active,
    results,
    updated_at: new Date().toISOString()
  });

  // Alert when the active domain changed, or when things went BLOCKED/DOWN.
  const broken = results.filter((r) => r.status === 'BLOCKED' || r.status === 'DOWN');
  if (prev.active && prev.active !== active) {
    await sendAlert(
      `[Rotator] Switched active domain -> ${active}`,
      `The active domain changed.\n\nOld: ${prev.active}\nNew: ${active}\n\n` +
        results.map((r) => `${r.status.padEnd(8)} ${r.url}`).join('\n')
    );
  } else if (broken.length && (!prev.results || prev.results.length === 0)) {
    await sendAlert(
      `[Rotator] ${broken.length} domain(s) unavailable`,
      broken.map((r) => `${r.status} ${r.url}`).join('\n')
    );
  }

  res.status(200).json({ active, count: results.length, results, persisted: store.enabled });
}
