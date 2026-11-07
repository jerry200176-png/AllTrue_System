// node --test preview/proxy.test.mjs : Pages Function allow-list, header stripping, secret isolation.
import test from 'node:test';
import assert from 'node:assert/strict';
import { onRequest } from '../functions/api/[[path]].js';

const env = { PREVIEW_API_ORIGIN: 'https://stg.example', PREVIEW_API_GATE_COOKIE: 'gate-v', PREVIEW_DEMO_DIRECTOR_PASSWORD: 'pw-d' };
const call = (path, init, e = env) => onRequest({ request: new Request(`https://pr.example${path}`, init), env: e });
const withFetch = async (fn) => { const seen = []; const real = globalThis.fetch; globalThis.fetch = async (u, i) => { seen.push({ url: String(u), headers: i.headers, body: i.body }); return new Response(JSON.stringify({ data: { session: { access_token: 't' } }, token: 'tok' }), { status: 200 }); }; try { await fn(seen); } finally { globalThis.fetch = real; } };

test('unset origin => mocks mode, nothing proxied', async () => {
  assert.deepEqual(await (await call('/api/preview-config', {}, {})).json(), { proxy: false });
  assert.equal((await call('/api/v1/me', {}, {})).status, 503);
});
test('only /api/v1 is proxied; gate cookie is injected, browser cookies dropped, Bearer forwarded unchanged', async () => {
  await withFetch(async (seen) => {
    assert.equal((await call('/api/other')).status, 404);
    assert.equal((await call('/api/v1/../admin')).status, 404);
    const r = await call('/api/v1/me', { headers: { Authorization: 'Bearer x', 'Cf-Access-Client-Id': 'evil', Cookie: 'a=b' } });
    assert.equal(r.status, 200);
    assert.equal(seen.length, 1);
    assert.equal(seen[0].url, 'https://stg.example/api/v1/me');
    assert.equal(seen[0].headers.get('Authorization'), 'Bearer x');
    assert.equal(seen[0].headers.get('Cookie'), 'alltrue_staging_gate=gate-v');
    assert.equal(seen[0].headers.get('cf-access-client-id'), null);
  });
});
test('generic proxy is read-only: POST/PATCH/DELETE/PUT => 405, nothing reaches staging', async () => {
  await withFetch(async (seen) => {
    for (const method of ['POST', 'PATCH', 'DELETE', 'PUT']) {
      const r = await call('/api/v1/students/1', { method, body: method === 'DELETE' ? undefined : '{}' });
      assert.equal(r.status, 405, method);
    }
    assert.equal((await call('/api/v1/students/1', { method: 'HEAD' })).status, 200);
    assert.equal(seen.length, 1);
  });
});
test('demo login rejects prototype-key roles', async () => {
  await withFetch(async (seen) => {
    for (const role of ['__proto__', 'constructor']) {
      assert.equal((await call('/api/preview-demo-login', { method: 'POST', body: JSON.stringify({ role }) })).status, 400);
    }
    assert.equal(seen.length, 0);
  });
});
test('demo login runs server-side and never returns the password', async () => {
  await withFetch(async (seen) => {
    const r = await call('/api/preview-demo-login', { method: 'POST', body: JSON.stringify({ role: 'director' }) });
    const text = await r.text();
    assert.ok(!text.includes('pw-d'));
    assert.equal(JSON.parse(seen[0].body).account, 'demo-director');
    assert.equal(JSON.parse(text).session.access_token, 't');
  });
});
