const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

let serverProcess;
let baseUrl;
let dataDir;
let adminCookie;
let apiKey;
let productId;

async function freePort() {
  return new Promise((resolve, reject) => {
    const socket = net.createServer();
    socket.once('error', reject);
    socket.listen(0, '127.0.0.1', () => {
      const port = socket.address().port;
      socket.close(error => error ? reject(error) : resolve(port));
    });
  });
}

before(async () => {
  const port = await freePort();
  dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'wp-panda-test-'));
  baseUrl = `http://127.0.0.1:${port}`;
  serverProcess = spawn(process.execPath, [path.resolve(__dirname, '../server.js')], {
    cwd: path.resolve(__dirname, '..'),
    env: { ...process.env, PORT: String(port), HOST: '127.0.0.1', ADMIN_PASSWORD: 'test-password-strong', APP_SECRET: 'test-secret-which-is-long-enough-12345', API_PUBLIC_URL: baseUrl, DATA_DIR: dataDir },
    stdio: 'ignore',
  });
  let ready = false;
  for (let i = 0; i < 60; i++) {
    if (serverProcess.exitCode !== null) throw new Error(`Server exited with ${serverProcess.exitCode}`);
    try { if ((await fetch(`${baseUrl}/health`)).ok) { ready = true; break; } } catch {}
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  assert.equal(ready, true, 'server should become ready');
});

after(() => {
  if (serverProcess && serverProcess.exitCode === null) serverProcess.kill('SIGTERM');
  if (dataDir) fs.rmSync(dataDir, { recursive: true, force: true });
});

test('admin, release publishing and protected WordPress update download', async () => {
  let response = await fetch(`${baseUrl}/api/admin/catalog`);
  assert.equal(response.status, 401);

  response = await fetch(`${baseUrl}/api/admin/login`, {
    method: 'POST', headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ password: 'test-password-strong' }),
  });
  assert.equal(response.status, 200);
  adminCookie = response.headers.get('set-cookie').split(';')[0];

  response = await fetch(`${baseUrl}/api/admin/products`, {
    method: 'POST', headers: { cookie: adminCookie, 'content-type': 'application/json' },
    body: JSON.stringify({ name: 'Test Plugin', slug: 'test-plugin', type: 'plugin' }),
  });
  assert.equal(response.status, 201);
  const created = await response.json();
  apiKey = created.apiKey;
  productId = created.product.id;

  const form = new FormData();
  form.append('version', '1.2.0');
  form.append('changelog', 'Security improvements');
  form.append('package', new Blob([Buffer.from([0x50, 0x4b, 3, 4, 1, 2, 3])], { type: 'application/zip' }), 'plugin.zip');
  response = await fetch(`${baseUrl}/api/admin/products/${productId}/releases`, { method: 'POST', headers: { cookie: adminCookie }, body: form });
  assert.equal(response.status, 201);

  response = await fetch(`${baseUrl}/api/v1/check?slug=test-plugin&type=plugin&version=1.0.0`, { headers: { authorization: `Bearer ${apiKey}` } });
  assert.equal(response.status, 200);
  const update = await response.json();
  assert.equal(update.update, true);
  assert.equal(update.version, '1.2.0');
  assert.match(update.package, /^http:\/\/127\.0\.0\.1:/);

  response = await fetch(update.package);
  assert.equal(response.status, 200);
  assert.equal((await response.arrayBuffer()).byteLength, 7);

  response = await fetch(`${baseUrl}/api/v1/check?slug=test-plugin&type=plugin&version=1.2.0`, { headers: { authorization: `Bearer ${apiKey}` } });
  assert.deepEqual(await response.json(), { update: false });

  response = await fetch(`${baseUrl}/api/admin/products/${productId}/rotate-key`, { method: 'POST', headers: { cookie: adminCookie } });
  assert.equal(response.status, 200);
  const rotated = (await response.json()).apiKey;
  response = await fetch(`${baseUrl}/api/v1/check?slug=test-plugin&type=plugin&version=1.0.0`, { headers: { authorization: `Bearer ${apiKey}` } });
  assert.equal(response.status, 401);
  response = await fetch(`${baseUrl}/api/v1/check?slug=test-plugin&type=plugin&version=1.0.0`, { headers: { authorization: `Bearer ${rotated}` } });
  assert.equal(response.status, 200);
});
