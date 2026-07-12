/**
 * Production server entry — for aaPanel (Node project / PM2), a VPS, or any
 * Node host. Serves the gateway page (public/) and the /api/* routes.
 *
 * aaPanel setup:
 *   Website -> Node project -> add project
 *     Run directory : this folder
 *     Startup file  : server.js   (or start command: node server.js)
 *     Port          : 3000  (or set PORT env; aaPanel maps it via reverse proxy)
 *   Then bind your domain and enable the reverse proxy to 127.0.0.1:PORT.
 *
 * Health-check cron (replaces Vercel cron): add an aaPanel Cron job ->
 *   Shell script, every 10 min:
 *     curl -s http://127.0.0.1:3000/api/health-check > /dev/null
 */
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = __dirname;
const PORT = process.env.PORT || 3000;
const HOST = process.env.HOST || '0.0.0.0';

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.ico': 'image/x-icon',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg'
};

/** Add the Vercel-style helpers the handlers expect on Node's response. */
function enhance(res) {
  res.status = (code) => {
    res.statusCode = code;
    return res;
  };
  res.json = (obj) => {
    res.setHeader('Content-Type', 'application/json; charset=utf-8');
    res.end(JSON.stringify(obj));
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
    if (!filePath.startsWith(path.join(root, 'public'))) return false; // path traversal guard
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
      const name = pathname.replace('/api/', '').replace(/\/$/, '').replace(/[^a-z0-9-]/gi, '');
      const mod = await import(`./api/${name}.js`);
      await mod.default(req, res);
      return;
    }

    if (pathname === '/') {
      await serveStatic(res, 'index.html');
      return;
    }

    const served = await serveStatic(res, pathname.replace(/^\//, ''));
    if (!served) res.status(404).send('Not found');
  } catch (err) {
    res.status(500).send('Server error');
    console.error(err);
  }
});

server.listen(PORT, HOST, () => {
  console.log(`Global Rotate listening on http://${HOST}:${PORT}`);
});
