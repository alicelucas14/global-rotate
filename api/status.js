import { getPoolUrls } from '../lib/domains.js';
import { store } from '../lib/store.js';

/**
 * Returns the current known status as JSON.
 * Used by the client gateway and by anyone monitoring the pool.
 *
 *   GET /api/status
 */
export default async function handler(req, res) {
  const state = await store.getJSON('rotator:state', null);
  res.setHeader('Cache-Control', 's-maxage=30, stale-while-revalidate=60');
  res.status(200).json(
    state || {
      active: getPoolUrls()[0] || null,
      results: [],
      updated_at: null,
      note: 'No health check has run yet.'
    }
  );
}
