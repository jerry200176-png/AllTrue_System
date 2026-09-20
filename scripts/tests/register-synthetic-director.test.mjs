import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import test from 'node:test';

const root = path.resolve(import.meta.dirname, '../..');
const helper = path.join(root, 'scripts/acceptance/register-synthetic-director.mjs');

function runHelper(url, dir, extra = {}) {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [helper], {
      cwd: root,
      env: { ...process.env, ALLTRUE_SYNTHETIC_BASE_URL: url, ALLTRUE_SYNTHETIC_STATE_DIR: dir, ...extra },
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (chunk) => { stdout += chunk; });
    child.stderr.on('data', (chunk) => { stderr += chunk; });
    child.on('close', (code) => resolve({ code, stdout, stderr }));
  });
}

async function serverFor(handler) {
  const server = http.createServer(handler);
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  return { server, url: `http://127.0.0.1:${server.address().port}` };
}

test('registers once, persists receipt id, and never prints password', async (t) => {
  const seen = [];
  const { server, url } = await serverFor(async (req, res) => {
    let body = '';
    for await (const chunk of req) body += chunk;
    if (req.url === '/api/v1/directors/register') {
      seen.push(JSON.parse(body));
      res.writeHead(201, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ message: 'pending', pending_application_id: 8416 }));
    } else {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify([{ id: 16, name: '測試分校' }]));
    }
  });
  t.after(() => server.close());
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-synthetic-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));

  const first = await runHelper(url, dir);
  assert.equal(first.code, 0);
  assert.match(first.stdout, /status=pending_approval/);
  assert.equal(seen.length, 1);
  assert.equal(seen[0].campus_id, 16);
  assert.equal(seen[0].account, 'alltrue-smoke-director-campus16-v1');
  assert.ok(seen[0].password);
  const credentials = JSON.parse(fs.readFileSync(path.join(dir, 'synthetic-director-campus16.credentials.json')));
  assert.doesNotMatch(first.stdout, /password/i);
  assert.doesNotMatch(first.stdout, new RegExp(credentials.password.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));

  const state = JSON.parse(fs.readFileSync(path.join(dir, 'synthetic-director-campus16.state.json')));
  assert.deepEqual(state, {
    canonical_ref: 'alltrue-smoke-director-campus16-v1',
    account: 'alltrue-smoke-director-campus16-v1',
    name: 'AllTrue Smoke Director C16',
    campus_id: 16,
    account_id: 8416,
    campus_name: '測試分校',
    status: 'pending_approval',
  });
  assert.equal((fs.statSync(path.join(dir, 'synthetic-director-campus16.credentials.json')).mode & 0o777), 0o600);

  const second = await runHelper(url, dir);
  assert.equal(second.code, 0);
  assert.match(second.stdout, /account_id|status=pending_approval/);
  assert.equal(seen.length, 1, 'a rerun must reuse the receipt and not register again');
});

test('preserves credentials and refuses a registration collision', async (t) => {
  const { server, url } = await serverFor(async (req, res) => {
    res.writeHead(422, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ message: '此帳號已被使用' }));
  });
  t.after(() => server.close());
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-synthetic-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  const result = await runHelper(url, dir);
  assert.equal(result.code, 4);
  assert.match(result.stdout, /status=identity_collision_refused/);
  assert.ok(fs.existsSync(path.join(dir, 'synthetic-director-campus16.credentials.json')));
  assert.equal(fs.existsSync(path.join(dir, 'synthetic-director-campus16.state.json')), false);
});

test('does not delete generated credentials when invite token is required', async (t) => {
  const { server, url } = await serverFor(async (req, res) => {
    res.writeHead(403, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ message: 'invite required' }));
  });
  t.after(() => server.close());
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-synthetic-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  const result = await runHelper(url, dir);
  assert.equal(result.code, 3);
  assert.match(result.stdout, /status=registration_requires_invite_token/);
  const credentials = JSON.parse(fs.readFileSync(path.join(dir, 'synthetic-director-campus16.credentials.json')));
  assert.equal(credentials.campus_id, 16);
  assert.ok(credentials.password);
});

test('repairs weak existing credential permissions before reuse', async (t) => {
  const { server, url } = await serverFor(async (req, res) => {
    if (req.url === '/api/v1/directors/register') {
      res.writeHead(201, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ pending_application_id: 9416 }));
    } else {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify([{ id: 16, name: '測試分校' }]));
    }
  });
  t.after(() => server.close());
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-synthetic-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  assert.equal((await runHelper(url, dir)).code, 0);
  const credentialsPath = path.join(dir, 'synthetic-director-campus16.credentials.json');
  fs.chmodSync(credentialsPath, 0o644);
  const rerun = await runHelper(url, dir);
  assert.equal(rerun.code, 0);
  assert.equal((fs.statSync(credentialsPath).mode & 0o777), 0o600);
});

test('fails closed when a receipt loses its matching credential', async (t) => {
  const { server, url } = await serverFor(async (req, res) => {
    if (req.url === '/api/v1/directors/register') {
      res.writeHead(201, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ pending_application_id: 9516 }));
    } else {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify([{ id: 16, name: '測試分校' }]));
    }
  });
  t.after(() => server.close());
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-synthetic-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  assert.equal((await runHelper(url, dir)).code, 0);
  const credentialsPath = path.join(dir, 'synthetic-director-campus16.credentials.json');
  fs.unlinkSync(credentialsPath);
  const rerun = await runHelper(url, dir);
  assert.equal(rerun.code, 1);
  assert.match(rerun.stderr, /receipt_credentials_missing/);
  assert.equal(fs.existsSync(credentialsPath), false, 'must not mint a replacement credential');
});
