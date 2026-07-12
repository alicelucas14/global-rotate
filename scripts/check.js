/**
 * CLI health check — run locally without deploying:
 *   node scripts/check.js
 *
 * Prints the status of every domain in the pool and which one the rotator
 * would pick as active. Handy for testing before you push.
 */
import { getPool } from '../lib/domains.js';
import { evaluate } from '../lib/checker.js';

const pool = getPool();
console.log(`Checking ${pool.length} domain(s)...\n`);

const results = [];
for (const d of pool) {
  // eslint-disable-next-line no-await-in-loop
  const r = await evaluate(d.url);
  results.push(r);
  const id = r.indonesia;
  const idInfo = id && id.idNodesTotal
    ? `ID nodes ${id.idNodesUp}/${id.idNodesTotal}`
    : 'ID check n/a';
  console.log(
    `${r.status.padEnd(8)} ${d.label.padEnd(10)} ${d.url}  (http ${r.direct.status}, ${idInfo})`
  );
}

const active = (results.find((r) => r.status === 'ACTIVE') || results[0] || {}).url;
console.log(`\n=> Active domain would be: ${active}`);
