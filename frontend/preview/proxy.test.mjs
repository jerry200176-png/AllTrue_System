// node --test preview/proxy.test.mjs : Pages Function allow-list, header stripping, secret isolation.
import test from 'node:test';
import assert from 'node:assert/strict';
import { onRequest } from '../functions/api/[[path]].js';

const env = { PREVIEW_API_ORIGIN: 'https://stg.example', CF_ACCESS_CLIENT_ID: 'id', CF_ACCESS_CLIENT_SECRET: 'sec', PREVIEW_DEMO_DIRECTOR_PASSWORD: 'pw-d' };
const call = (path, init, e = env) => onRequest({ request: new Request(`https://pr.example${path}`, init), env: e });
const withFetch = async (fn) => { const seen = []; const real = globalThis.fetch; globalThis.fetch = async (u, i) => { seen.push({ url: String(u), headers: i.headers, body: i.body }); return new Response(JSON.stringify({ data: { session: { access_token: 't' } }, token: 'tok' }), { status: 200 }); }; try { await fn(seen); } finally { globalThis.fetch = real; } };

test('unset origin => mocks mode, nothing proxied', async () => {
  assert.deepEqual(await (await call('/api/preview-config', {}, {})).json(), { proxy: false });
  assert.equal((await call('/api/v1/me', {}, {})).status, 503);
});
test('only /api/v1 is proxied; Access headers are injected, incoming Cf-Access stripped', async () => {
  await withFetch(async (seen) => {
    assert.equal((await call('/api/other')).status, 404);
    assert.equal((await call('/api/v1/../admin')).status, 404);
    const r = await call('/api/v1/me', { headers: { Authorization: 'Bearer x', 'Cf-Access-Client-Id': 'evil', Cookie: 'a=b' } });
    assert.equal(r.status, 200);
    assert.equal(seen.length, 1);
    assert.equal(seen[0].url, 'https://stg.example/api/v1/me');
    assert.equal(seen[0].headers.get('CF-Access-Client-Id'), 'id');
    assert.equal(seen[0].headers.get('Authorization'), 'Bearer x');
    assert.equal(seen[0].headers.get('cookie'), null);
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
