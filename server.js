const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { URL } = require('node:url');

const PORT = Number(process.env.PORT || 3000);
const HOST = process.env.HOST || '0.0.0.0';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'change-me-now';
const SECRET = process.env.APP_SECRET || 'local-dev-secret-change-before-deploy';
const DATA_DIR = path.resolve(process.env.DATA_DIR || './data');
const STORE = path.join(DATA_DIR, 'catalog.json');
const PRIVATE_FILES = path.join(DATA_DIR, 'packages');
const MAX_UPLOAD = 100 * 1024 * 1024;
if (process.env.NODE_ENV === 'production') {
  if (!ADMIN_PASSWORD || ADMIN_PASSWORD === 'change-me-now' || ADMIN_PASSWORD.startsWith('replace-with') || ADMIN_PASSWORD.length < 16) {
    throw new Error('Set a unique ADMIN_PASSWORD of at least 16 characters before production startup.');
  }
  if (!APP_SECRET || APP_SECRET === 'local-dev-secret-change-before-deploy' || APP_SECRET.startsWith('replace-with') || APP_SECRET.length < 32) {
    throw new Error('Set a random APP_SECRET of at least 32 characters before production startup.');
  }
  if (!process.env.API_PUBLIC_URL || !/^https:\/\//i.test(process.env.API_PUBLIC_URL)) {
    throw new Error('Set API_PUBLIC_URL to the public HTTPS origin before production startup.');
  }
}
fs.mkdirSync(PRIVATE_FILES, { recursive: true });
if (!fs.existsSync(STORE)) fs.writeFileSync(STORE, JSON.stringify({ products: [] }, null, 2), { mode: 0o600 });
else { try { fs.chmodSync(STORE, 0o600); } catch {} }

try { fs.chmodSync(DATA_DIR, 0o700); fs.chmodSync(PRIVATE_FILES, 0o700); } catch {}
const readStore = () => { try { return JSON.parse(fs.readFileSync(STORE, 'utf8')); } catch { return { products: [] }; } };
const writeStore = (db) => { const temp = `${STORE}.tmp`; fs.writeFileSync(temp, JSON.stringify(db, null, 2), { mode: 0o600 }); fs.renameSync(temp, STORE); fs.chmodSync(STORE, 0o600); };
const random = (n = 24) => crypto.randomBytes(n).toString('base64url');
const sha = (x) => crypto.createHash('sha256').update(x).digest('hex');
const hmac = (x) => crypto.createHmac('sha256', SECRET).update(x).digest('hex');
const safeEqual = (a, b) => { const aa = Buffer.from(String(a)); const bb = Buffer.from(String(b)); return aa.length === bb.length && crypto.timingSafeEqual(aa, bb); };
const json = (res, status, body) => { res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' }); res.end(JSON.stringify(body)); };
const cookieMap = (req) => Object.fromEntries((req.headers.cookie || '').split(';').map(x => x.trim().split('=').map(decodeURIComponent)).filter(x => x.length === 2));
function adminOk(req) {
  const value = cookieMap(req).panda_session || '';
  const [expires, signature] = value.split('.');
  return expires && Number(expires) > Date.now() && safeEqual(signature || '', hmac(`admin:${expires}`));
}
function sendFile(res, file, type, download = false) {
  const stream = fs.createReadStream(file);
  stream.on('error', () => { if (!res.headersSent) json(res, 404, { error: 'Файл не найден' }); });
  const headers = { 'content-type': type, 'cache-control': 'private, no-store', 'x-content-type-options': 'nosniff' };
  if (download) headers['content-disposition'] = 'attachment; filename="wordpress-package.zip"';
  res.writeHead(200, headers);
  stream.pipe(res);
}
function bodyBuffer(req, limit = 1024 * 1024) {
  return new Promise((resolve, reject) => {
    const chunks = []; let size = 0;
    req.on('data', chunk => { size += chunk.length; if (size > limit) { reject(Object.assign(new Error('Размер запроса превышает лимит'), { status: 413 })); req.destroy(); } else chunks.push(chunk); });
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}
function parseMultipart(buf, boundary) {
  const fields = {}; let file = null;
  const marker = Buffer.from(`--${boundary}`);
  let cursor = 0;
  while ((cursor = buf.indexOf(marker, cursor)) !== -1) {
    cursor += marker.length;
    if (buf[cursor] === 45 && buf[cursor + 1] === 45) break;
    if (buf[cursor] === 13 && buf[cursor + 1] === 10) cursor += 2;
    const headEnd = buf.indexOf(Buffer.from('\r\n\r\n'), cursor); if (headEnd < 0) break;
    const headers = buf.subarray(cursor, headEnd).toString('utf8');
    const next = buf.indexOf(marker, headEnd + 4); if (next < 0) break;
    let end = next; if (buf[end - 2] === 13 && buf[end - 1] === 10) end -= 2;
    const data = buf.subarray(headEnd + 4, end);
    const disposition = headers.match(/content-disposition:\s*form-data;([^\r\n]+)/i)?.[1] || '';
    const name = disposition.match(/name="([^"]+)"/)?.[1];
    const filename = disposition.match(/filename="([^"]*)"/)?.[1];
    if (name && filename !== undefined && filename !== '') file = { name, filename: path.basename(filename), data: Buffer.from(data) };
    else if (name) fields[name] = data.toString('utf8');
    cursor = next;
  }
  return { fields, file };
}
function verifyProduct(req, product) {
  const token = (req.headers.authorization || '').replace(/^Bearer\s+/i, '');
  return !!token && safeEqual(sha(token), product.keyHash);
}
function signedPackage(release, origin) {
  const expires = Math.floor(Date.now() / 1000) + 600;
  const signature = hmac(`${release.id}:${expires}`);
  return `${origin}/download/${encodeURIComponent(release.id)}?expires=${expires}&signature=${signature}`;
}

const server = http.createServer(async (req, res) => {
  const base = `http://${req.headers.host || 'localhost'}`;
  const url = new URL(req.url, base);
  try {
    if (req.method === 'GET' && url.pathname === '/health') return json(res, 200, { ok: true });
    if (req.method === 'POST' && url.pathname === '/api/admin/login') {
      const body = await bodyBuffer(req); let payload;
      try { payload = JSON.parse(body.toString()); } catch { return json(res, 400, { error: 'Некорректный JSON' }); }
      if (!safeEqual(payload.password || '', ADMIN_PASSWORD)) return json(res, 401, { error: 'Неверный пароль' });
      const expires = Date.now() + 12 * 60 * 60 * 1000;
      res.setHeader('set-cookie', `panda_session=${expires}.${hmac(`admin:${expires}`)}; HttpOnly; SameSite=Strict; Path=/; Max-Age=43200${process.env.NODE_ENV === 'production' ? '; Secure' : ''}`);
      return json(res, 200, { ok: true });
    }
    if (req.method === 'POST' && url.pathname === '/api/admin/logout') {
      res.setHeader('set-cookie', 'panda_session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0'); return json(res, 200, { ok: true });
    }
    if (url.pathname.startsWith('/api/admin/')) {
      if (!adminOk(req)) return json(res, 401, { error: 'Требуется вход в панель' });
      const db = readStore();
      if (req.method === 'GET' && url.pathname === '/api/admin/catalog') {
        const products = db.products.map(({ keyHash, ...p }) => ({ ...p, releases: p.releases.map(({ file, ...release }) => release) }));
        const releases = products.reduce((n, p) => n + p.releases.length, 0);
        return json(res, 200, { products, stats: { products: products.length, releases, downloads: db.products.reduce((n, p) => n + p.releases.reduce((m, r) => m + (r.downloads || 0), 0), 0) } });
      }
      if (req.method === 'POST' && url.pathname === '/api/admin/products') {
        const raw = await bodyBuffer(req); let input; try { input = JSON.parse(raw.toString()); } catch { return json(res, 400, { error: 'Некорректный JSON' }); }
        const name = String(input.name || '').trim(); const slug = String(input.slug || '').trim().toLowerCase(); const type = input.type;
        if (name.length < 2 || !/^[a-z0-9][a-z0-9_-]{1,59}$/.test(slug) || !['plugin', 'theme'].includes(type)) return json(res, 400, { error: 'Проверьте название, slug и тип продукта' });
        if (db.products.some(p => p.slug === slug && p.type === type)) return json(res, 409, { error: 'Продукт с таким slug уже существует' });
        const apiKey = `wpu_${random(32)}`;
        const product = { id: random(12), name, slug, type, keyHash: sha(apiKey), createdAt: new Date().toISOString(), releases: [] };
        db.products.unshift(product); writeStore(db);
        return json(res, 201, { product: { ...product, keyHash: undefined }, apiKey });
      }
      const uploadMatch = url.pathname.match(/^\/api\/admin\/products\/([^/]+)\/releases$/);
      if (req.method === 'POST' && uploadMatch) {
        const product = db.products.find(p => p.id === uploadMatch[1]); if (!product) return json(res, 404, { error: 'Продукт не найден' });
        const typeHeader = req.headers['content-type'] || ''; const boundary = typeHeader.match(/boundary=(?:"([^"]+)"|([^;]+))/i)?.slice(1).find(Boolean);
        if (!boundary) return json(res, 400, { error: 'Ожидается multipart/form-data' });
        const raw = await bodyBuffer(req, MAX_UPLOAD + 2 * 1024 * 1024); const { fields, file } = parseMultipart(raw, boundary);
        if (!file || !file.filename.toLowerCase().endsWith('.zip') || file.data.length < 4 || file.data.length > MAX_UPLOAD || file.data[0] !== 0x50 || file.data[1] !== 0x4b) return json(res, 400, { error: 'Загрузите ZIP-архив WordPress (до 100 МБ)' });
        const version = String(fields.version || '').trim(); if (!/^\d+(\.\d+){0,3}$/.test(version)) return json(res, 400, { error: 'Укажите корректную версию, например 1.2.0' });
        const existing = product.releases.find(r => r.version === version); if (existing) { try { fs.unlinkSync(path.join(PRIVATE_FILES, existing.file)); } catch {} product.releases = product.releases.filter(r => r.version !== version); }
        const id = random(18); const filename = `${id}.zip`; fs.writeFileSync(path.join(PRIVATE_FILES, filename), file.data, { mode: 0o600, flag: 'wx' });
        const release = { id, file: filename, version, changelog: String(fields.changelog || '').slice(0, 10000), createdAt: new Date().toISOString(), size: file.data.length, downloads: 0 };
        product.releases.push(release); product.releases.sort((a, b) => compareVersions(b.version, a.version)); writeStore(db);
        return json(res, 201, { release: { ...release, file: undefined } });
      }
      const rotateMatch = url.pathname.match(/^\/api\/admin\/products\/([^/]+)\/rotate-key$/);
      if (req.method === 'POST' && rotateMatch) {
        const product = db.products.find(p => p.id === rotateMatch[1]); if (!product) return json(res, 404, { error: 'Продукт не найден' });
        const apiKey = `wpu_${random(32)}`; product.keyHash = sha(apiKey); writeStore(db);
        return json(res, 200, { apiKey });
      }
      const deleteMatch = url.pathname.match(/^\/api\/admin\/products\/([^/]+)$/);
      if (req.method === 'DELETE' && deleteMatch) {
        const index = db.products.findIndex(p => p.id === deleteMatch[1]); if (index < 0) return json(res, 404, { error: 'Продукт не найден' });
        for (const r of db.products[index].releases) { try { fs.unlinkSync(path.join(PRIVATE_FILES, r.file)); } catch {} }
        db.products.splice(index, 1); writeStore(db); return json(res, 200, { ok: true });
      }
      return json(res, 404, { error: 'Маршрут не найден' });
    }
    if (req.method === 'GET' && url.pathname === '/api/v1/check') {
      const slug = url.searchParams.get('slug'); const type = url.searchParams.get('type'); const installed = url.searchParams.get('version') || '0';
      const product = readStore().products.find(p => p.slug === slug && p.type === type);
      if (!product || !verifyProduct(req, product)) return json(res, 401, { error: 'Неверный ключ или продукт' });
      const release = product.releases[0];
      if (!release || compareVersions(release.version, installed) <= 0) return json(res, 200, { update: false });
      const publicOrigin = (process.env.API_PUBLIC_URL || `${req.socket.encrypted ? 'https' : 'http'}://${req.headers.host}`).replace(/\/$/, '');
      return json(res, 200, { update: true, slug: product.slug, type: product.type, name: product.name, version: release.version, changelog: release.changelog, tested: '6.8', requires: '6.0', requires_php: '7.4', package: signedPackage(release, publicOrigin), updated_at: release.createdAt });
    }
    const downloadMatch = url.pathname.match(/^\/download\/([a-zA-Z0-9_-]+)$/);
    if (req.method === 'GET' && downloadMatch) {
      const id = downloadMatch[1]; const expires = Number(url.searchParams.get('expires')); const signature = url.searchParams.get('signature');
      if (!expires || expires < Math.floor(Date.now() / 1000) || expires > Math.floor(Date.now() / 1000) + 610 || !safeEqual(signature || '', hmac(`${id}:${expires}`))) return json(res, 403, { error: 'Ссылка недействительна или истекла' });
      const db = readStore(); let selected;
      for (const p of db.products) { const r = p.releases.find(x => x.id === id); if (r) { selected = { product: p, release: r }; break; } }
      if (!selected) return json(res, 404, { error: 'Архив не найден' });
      selected.release.downloads = (selected.release.downloads || 0) + 1; writeStore(db);
      return sendFile(res, path.join(PRIVATE_FILES, selected.release.file), 'application/zip', true);
    }
    if (req.method === 'GET' && (url.pathname === '/' || url.pathname === '/index.html')) return sendFile(res, path.join(__dirname, 'public', 'index.html'), 'text/html; charset=utf-8');
    if (req.method === 'GET' && url.pathname.startsWith('/assets/')) {
      const asset = path.resolve(__dirname, 'public', url.pathname.slice(1)); if (!asset.startsWith(path.resolve(__dirname, 'public') + path.sep)) return json(res, 404, { error: 'Not found' });
      return sendFile(res, asset, asset.endsWith('.css') ? 'text/css' : 'application/javascript');
    }
    json(res, 404, { error: 'Not found' });
  } catch (error) {
    console.error(error);
    if (!res.headersSent) json(res, error.status || 500, { error: error.status ? error.message : 'Внутренняя ошибка сервера' }); else res.destroy();
  }
});
function compareVersions(a, b) {
  const parse = v => String(v).split(/[+-]/)[0].split('.').map(x => parseInt(x, 10) || 0);
  const aa = parse(a), bb = parse(b);
  for (let i = 0; i < Math.max(aa.length, bb.length); i++) { const x = aa[i] || 0, y = bb[i] || 0; if (x !== y) return x > y ? 1 : -1; }
  return 0;
}
server.listen(PORT, HOST, () => console.log(`WP Panda Update Vault listening on http://${HOST}:${PORT}`));
