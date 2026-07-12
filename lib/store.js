/**
 * Tiny persistent store for the "current active domain" and last statuses.
 *
 * Uses Upstash Redis over its REST API when configured (works great on
 * Vercel, generous free tier). If not configured, it degrades gracefully to
 * an in-memory store so the project still runs locally and on first deploy.
 *
 * To enable persistence, set these env vars (Upstash console -> REST API):
 *   UPSTASH_REDIS_REST_URL
 *   UPSTASH_REDIS_REST_TOKEN
 */

const URL = process.env.UPSTASH_REDIS_REST_URL;
const TOKEN = process.env.UPSTASH_REDIS_REST_TOKEN;
const enabled = Boolean(URL && TOKEN);

const memory = new Map();

async function redis(command) {
  const res = await fetch(URL, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(command)
  });
  if (!res.ok) throw new Error(`Upstash error ${res.status}`);
  const data = await res.json();
  return data.result;
}

export async function getJSON(key, fallback = null) {
  try {
    if (enabled) {
      const raw = await redis(['GET', key]);
      return raw ? JSON.parse(raw) : fallback;
    }
  } catch {
    /* fall through to memory */
  }
  return memory.has(key) ? memory.get(key) : fallback;
}

export async function setJSON(key, value) {
  const raw = JSON.stringify(value);
  memory.set(key, value);
  try {
    if (enabled) await redis(['SET', key, raw]);
  } catch {
    /* memory already updated */
  }
  return value;
}

export const store = { enabled, getJSON, setJSON };
