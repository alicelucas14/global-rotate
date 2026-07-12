/**
 * Zero-dependency local dev server.
 *
 * Serves public/ and maps /api/* to the Vercel-style handlers so you can
 * preview the whole project at http://localhost:3000 without deploying or
 * logging into Vercel. It shims the Vercel res helpers (status/json/send)
 * on top of Node's native response.
 *
 *   node scripts/dev-server.js
 */
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const PORT = process.env.PORT || 3000;

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.ico': 'image/x-icon',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml'
};

/** Add the Vercel-style helpers Node's http.ServerResponse doesn't have. */
function enhance(res) {
  res.status = (code) => {
    res.statusCode = code;
    return res;
  };
  res.json = (obj) => {
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    res.end(JSON.stringify(obj, null, 2));
    return res;
  };
  res.send = (body) => {
    res.end(body);
    return res;
  };
  return res;
}

async function serveStatic(res, relPath) {
  try {
    const filePath = path.join(root, 'public', relPath);
    const data = await readFile(filePath);
    res.setHeader('Content-Type', MIME[path.extname(filePath)] || 'application/octet-stream');
    res.end(data);
    return true;
  } catch {
    return false;
  }
}

const server = http.createServer(async (req, res) => {
  enhance(res);
  const url = new URL(req.url, `http://localhost:${PORT}`);
  const pathname = url.pathname;

  try {
    if (pathname.startsWith('/api/')) {
      const name = pathname.replace('/api/', '').replace(/\/$/, '');
      const mod = await import(`../api/${name}.js`);
      await mod.default(req, res);
      return;
    }

    if (pathname === '/' ) {
      await serveStatic(res, 'index.html');
      return;
    }

    const served = await serveStatic(res, pathname.replace(/^\//, ''));
    if (!served) {
      res.status(404).send('Not found: ' + pathname);
    }
  } catch (err) {
    res.status(500).send('Server error: ' + (err && err.stack || err));
  }
});

server.listen(PORT, () => {
  console.log(`\n  Global Rotate dev server running:`);
  console.log(`    Gateway     http://localhost:${PORT}/`);
  console.log(`    Status API  http://localhost:${PORT}/api/status`);
  console.log(`    Go redirect http://localhost:${PORT}/api/go`);
  console.log(`    Health run  http://localhost:${PORT}/api/health-check\n`);
});
