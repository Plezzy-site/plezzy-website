const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { spawn } = require('child_process');
const backend = require('./backend.cjs');

let QRCode;
try {
  QRCode = require('qrcode');
} catch (e) {
  console.log('QRCode package optional');
}

const PORT = 3000;
const PUBLIC_DIR = __dirname;

// Smart LAN IP discovery (filters out Tailscale, Docker, WSL, virtual adapters)
function getLanIp() {
  const interfaces = os.networkInterfaces();
  const candidates = [];

  for (const name of Object.keys(interfaces)) {
    const isVirtual = /tailscale|docker|vethernet|vbox|vmware|hyper-v|wsl|tap|tun|loopback/i.test(name);
    for (const iface of interfaces[name]) {
      if (iface.family === 'IPv4' && !iface.internal) {
        const ip = iface.address;
        const isCgnat = /^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./.test(ip) || ip.startsWith('100.');
        let score = 0;

        if (/wi-?fi|wireless|wlan/i.test(name)) score += 50;
        else if (/ethernet|lan/i.test(name)) score += 40;

        if (ip.startsWith('192.168.')) score += 30;
        else if (ip.startsWith('10.')) score += 20;
        else if (/^172\.(1[6-9]|2\d|3[01])\./.test(ip)) score += 20;

        if (isVirtual) score -= 100;
        if (isCgnat) score -= 100;

        candidates.push({ name, ip, score });
      }
    }
  }

  candidates.sort((a, b) => b.score - a.score);
  return candidates.length > 0 ? candidates[0].ip : '127.0.0.1';
}

const lanIp = getLanIp();
const lanMobileUrl = `http://${lanIp}:${PORT}/`;
let tunnelUrl = null;

// Start public HTTPS tunnel via Cloudflare or Pinggy (accessible anywhere in the world)
function initTunnel() {
  const localCloudflared = path.join(PUBLIC_DIR, process.platform === 'win32' ? 'cloudflared.exe' : 'cloudflared');
  const hasCloudflared = fs.existsSync(localCloudflared);

  if (hasCloudflared) {
    console.log('Initiating worldwide Cloudflare tunnel...');
    try {
      const tunnelProc = spawn(localCloudflared, [
        'tunnel',
        '--url', `http://127.0.0.1:${PORT}`,
        '--no-autoupdate'
      ]);

      const handleOutput = (data) => {
        const text = data.toString();
        const match = text.match(/https:\/\/[a-zA-Z0-9-]+\.trycloudflare\.com/);
        if (match && !tunnelUrl) {
          tunnelUrl = match[0] + '/';
          console.log('\n===================================================================');
          console.log('>>> 🌍 PLEZZY LIVE WORLDWIDE LINK:');
          console.log(`>>> ${tunnelUrl}`);
          console.log('>>> (This temporary link can be opened on ANY device anywhere in the world!)');
          console.log('===================================================================\n');
        }
      };

      tunnelProc.stdout.on('data', handleOutput);
      tunnelProc.stderr.on('data', handleOutput);

      tunnelProc.on('error', (err) => {
        console.log('Cloudflare tunnel error:', err.message);
        fallbackPinggy();
      });

      tunnelProc.on('exit', (code) => {
        console.log(`Cloudflare tunnel exited with code ${code}, reconnecting in 5s...`);
        tunnelUrl = null;
        setTimeout(initTunnel, 5000);
      });
      return;
    } catch (err) {
      console.log('Failed to start Cloudflare tunnel, falling back to Pinggy:', err.message);
    }
  }

  fallbackPinggy();
}

function fallbackPinggy() {
  try {
    const tunnelProc = spawn('ssh', [
      '-o', 'StrictHostKeyChecking=no',
      '-o', 'ServerAliveInterval=30',
      '-p', '443',
      '-R0:127.0.0.1:3000',
      'a.pinggy.io'
    ]);

    const handleOutput = (data) => {
      const text = data.toString();
      const match = text.match(/https:\/\/[a-zA-Z0-9-]+\.free\.pinggy\.net/) ||
                    text.match(/https:\/\/[a-zA-Z0-9-]+\.run\.pinggy-free\.link/);
      if (match && !tunnelUrl) {
        tunnelUrl = match[0] + '/';
        console.log(`Cloud HTTPS Tunnel ready: ${tunnelUrl}`);
      }
    };

    tunnelProc.stdout.on('data', handleOutput);
    tunnelProc.stderr.on('data', handleOutput);

    tunnelProc.on('error', (err) => {
      console.log('Tunnel service optional (SSH unavailable):', err.message);
    });

    tunnelProc.on('exit', () => {
      tunnelUrl = null;
      setTimeout(initTunnel, 10000);
    });
  } catch (e) {
    console.log('Tunnel initiation skipped:', e.message);
  }
}

initTunnel();

const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.wav': 'audio/wav',
  '.mp4': 'video/mp4',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.eot': 'application/vnd.ms-fontobject',
  '.otf': 'font/otf',
  '.wasm': 'application/wasm',
  '.ico': 'image/x-icon',
  '.webmanifest': 'application/manifest+json'
};

// Rate limiting map (max 60 requests per minute per IP for API endpoints)
const RATE_LIMIT_WINDOW_MS = 60 * 1000;
const MAX_REQUESTS_PER_WINDOW = 60;
const ipRequestCounts = new Map();

setInterval(() => {
  const now = Date.now();
  for (const [ip, data] of ipRequestCounts.entries()) {
    if (now - data.resetTime > RATE_LIMIT_WINDOW_MS) {
      ipRequestCounts.delete(ip);
    }
  }
}, 60000);

function checkRateLimit(ip) {
  const now = Date.now();
  let clientData = ipRequestCounts.get(ip);
  if (!clientData || now > clientData.resetTime) {
    clientData = { count: 1, resetTime: now + RATE_LIMIT_WINDOW_MS };
    ipRequestCounts.set(ip, clientData);
    return true;
  }
  clientData.count++;
  return clientData.count <= MAX_REQUESTS_PER_WINDOW;
}

function applySecurityHeaders(res, isHttps = false) {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'SAMEORIGIN');
  res.setHeader('X-XSS-Protection', '1; mode=block');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  res.setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
  res.setHeader('Content-Security-Policy', "default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob: https:; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data: https:; connect-src 'self' https: wss:; frame-ancestors 'self';");
  if (isHttps) {
    res.setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
  }
}

const server = http.createServer((req, res) => {
  const isHttps = req.headers['x-forwarded-proto'] === 'https' || Boolean(req.socket.encrypted);
  applySecurityHeaders(res, isHttps);

  const clientIp = (req.headers['x-forwarded-for'] ? req.headers['x-forwarded-for'].split(',')[0].trim() : null) ||
                   req.headers['cf-connecting-ip'] ||
                   req.headers['x-real-ip'] ||
                   req.socket.remoteAddress || '127.0.0.1';

  let parsedUrl;
  try {
    parsedUrl = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  } catch (e) {
    res.statusCode = 400;
    res.end('Bad Request');
    return;
  }

  let reqPath = decodeURI(parsedUrl.pathname);

  // Path traversal & Null byte protection
  if (reqPath.includes('\0') || reqPath.includes('..')) {
    res.statusCode = 403;
    res.end('Forbidden: Invalid Path');
    return;
  }

  // Block direct access to server-side code, customer databases, hidden files, logs, and backups
  const forbiddenPatterns = /\.(cjs|env|git|bak\d*|tmp|log|md|lock)$/i;
  const lowerPath = reqPath.toLowerCase();
  if (
    lowerPath.startsWith('/data/') || 
    lowerPath.startsWith('/.') || 
    lowerPath === '/package.json' || 
    lowerPath === '/package-lock.json' || 
    forbiddenPatterns.test(lowerPath)
  ) {
    if (lowerPath !== '/manifest.webmanifest' && lowerPath !== '/assets/products.json') {
      res.statusCode = 403;
      res.end('Forbidden: Access Denied');
      return;
    }
  }

  // Rate limiting for API requests
  if (reqPath.startsWith('/api/')) {
    if (!checkRateLimit(clientIp)) {
      res.writeHead(429, {
        'Content-Type': 'application/json',
        'Retry-After': '60'
      });
      res.end(JSON.stringify({ error: 'Too Many Requests', message: 'Rate limit exceeded. Please try again later.' }));
      return;
    }
  }

  // Handle Backend API Routes (/api/orders, /api/admin, /api/customers, /api/products, /api/upload)
  if (
    reqPath.startsWith('/api/orders') ||
    reqPath.startsWith('/api/admin') ||
    reqPath.startsWith('/api/customers') ||
    reqPath.startsWith('/api/products') ||
    reqPath.startsWith('/api/upload')
  ) {
    backend.handleApiRequest(req, res, parsedUrl).then(handled => {
      if (!handled) {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Endpoint Not Found' }));
      }
    }).catch(err => {
      console.error('[API Server Error]', err);
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Internal Server Error', message: err.message }));
    });
    return;
  }

  // Network info endpoint for mobile discovery
  if (reqPath === '/__plezzy_info') {
    res.writeHead(200, {
      'Content-Type': 'application/json',
      'Cache-Control': 'no-store, no-cache, must-revalidate',
      'Access-Control-Allow-Origin': '*'
    });
    res.end(JSON.stringify({
      hostIp: lanIp,
      port: PORT,
      lanUrl: lanMobileUrl,
      tunnelUrl: tunnelUrl,
      mobileUrl: lanMobileUrl
    }));
    return;
  }

  // QR Code SVG endpoint for instantaneous mobile camera scanning
  if (reqPath === '/api/qr.svg') {
    if (QRCode) {
      const customUrl = parsedUrl.searchParams.get('url');
      const targetUrl = customUrl ? decodeURIComponent(customUrl) : (tunnelUrl || lanMobileUrl);

      QRCode.toString(targetUrl, {
        type: 'svg',
        margin: 2,
        errorCorrectionLevel: 'M',
        color: { dark: '#141414', light: '#ffffff' }
      }, (err, svg) => {
        if (err) {
          res.statusCode = 500;
          res.end('QR generation error');
        } else {
          res.writeHead(200, {
            'Content-Type': 'image/svg+xml',
            'Cache-Control': 'no-store, no-cache, must-revalidate, proxy-revalidate',
            'Pragma': 'no-cache',
            'Expires': '0',
            'Access-Control-Allow-Origin': '*'
          });
          res.end(svg);
        }
      });
      return;
    } else {
      res.statusCode = 404;
      res.end('QR generator not available');
      return;
    }
  }

  if (reqPath === '/') reqPath = '/index.html';

  let filePath = path.normalize(path.join(PUBLIC_DIR, reqPath));
  if (!filePath.startsWith(PUBLIC_DIR)) {
    res.statusCode = 403;
    res.end('Forbidden');
    return;
  }

  fs.stat(filePath, (err, stats) => {
    if (err || !stats.isFile()) {
      filePath = path.join(PUBLIC_DIR, 'index.html');
    }

    const ext = path.extname(filePath).toLowerCase();
    const contentType = MIME_TYPES[ext] || 'application/octet-stream';

    try {
      const stat = fs.statSync(filePath);
      const total = stat.size;

      if (req.headers.range) {
        const range = req.headers.range;
        const parts = range.replace(/bytes=/, '').split('-');
        const partialstart = parts[0];
        const partialend = parts[1];

        const start = parseInt(partialstart, 10);
        const end = partialend ? parseInt(partialend, 10) : total - 1;
        const chunksize = (end - start) + 1;

        res.writeHead(206, {
          'Content-Range': 'bytes ' + start + '-' + end + '/' + total,
          'Accept-Ranges': 'bytes',
          'Content-Length': chunksize,
          'Content-Type': contentType,
          'Access-Control-Allow-Origin': '*'
        });
        fs.createReadStream(filePath, { start: start, end: end }).pipe(res);
      } else {
        res.writeHead(200, {
          'Content-Length': total,
          'Content-Type': contentType,
          'Accept-Ranges': 'bytes',
          'Access-Control-Allow-Origin': '*'
        });
        fs.createReadStream(filePath).pipe(res);
      }
    } catch (e) {
      res.statusCode = 500;
      res.end('Error reading file: ' + e.message);
    }
  });
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`Server running locally at http://localhost:${PORT}/`);
  console.log(`Server accessible on LAN (Wi-Fi) at ${lanMobileUrl}`);

  try {
    const startCmd = process.platform === 'win32' ? `start http://localhost:${PORT}/` :
                     process.platform === 'darwin' ? `open http://localhost:${PORT}/` :
                     `xdg-open http://localhost:${PORT}/`;
    const { exec } = require('child_process');
    exec(startCmd);
  } catch (e) {
    // ignore
  }
});
