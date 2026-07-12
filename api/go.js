import { getPoolUrls } from '../lib/domains.js';
import { store } from '../lib/store.js';

/**
 * Instant server-side redirect to the current active domain.
 *
 * Point ads, QR codes, or a stable "safe" domain at this endpoint:
 *   https://your-project.vercel.app/api/go
 *
 * It 302-redirects to the last-known healthy domain (updated by the cron
 * health check). Query string is forwarded so campaign params survive.
 */
export default async function handler(req, res) {
  const state = await store.getJSON('rotator:state', null);
  const target = (state && state.active) || getPoolUrls()[0];

  if (!target) {
    res.status(503).send('No domains configured.');
    return;
  }

  // Forward original query string (utm_*, ref, etc.)
  const qs = req.url && req.url.includes('?') ? '?' + req.url.split('?')[1] : '';

  res.setHeader('Cache-Control', 'no-store');
  res.writeHead(302, { Location: target + qs });
  res.end();
}
