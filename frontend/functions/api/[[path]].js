// Cloudflare Pages Function: same-origin proxy from the PR preview to the staging API.
// Secrets (Pages env, never sent to the browser):
//   PREVIEW_API_ORIGIN, PREVIEW_API_GATE_COOKIE (value of staging's alltrue_staging_gate cookie),
//   PREVIEW_DEMO_DIRECTOR_PASSWORD, PREVIEW_DEMO_TEACHER_PASSWORD,
//   PREVIEW_DEMO_PARENT_STUDENT_ID, PREVIEW_DEMO_PARENT_PHONE
// If PREVIEW_API_ORIGIN is unset the preview runs on in-browser mocks instead.
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' } });
const DEMO_ACCOUNT = { director: 'demo-director', teacher: 'demo-teacher' }; // seeded by infra/daan-staging/seed-demo.php

function upstream(env, path, init = {}) {
  const origin = new URL(env.PREVIEW_API_ORIGIN);
  const target = new URL(path, origin);
  if (target.origin !== origin.origin || !target.pathname.startsWith('/api/v1/')) return null;
  const headers = new Headers(init.headers);
  // Browser Authorization (Bearer) passes through unchanged; browser cookies never do.
  for (const k of [...headers.keys()]) if (/^(cf-|x-forwarded|x-real-ip|x-user-|host$|cookie$)/i.test(k)) headers.delete(k);
  headers.set('Cookie', `alltrue_staging_gate=${env.PREVIEW_API_GATE_COOKIE || ''}`);
  return fetch(target, { ...init, headers, redirect: 'manual' });
}

export async function onRequest({ request, env }) {
  const url = new URL(request.url);
  if (url.pathname === '/api/preview-config') return json({ proxy: Boolean(env.PREVIEW_API_ORIGIN) });
  if (!env.PREVIEW_API_ORIGIN) return json({ message: 'preview: no staging configured' }, 503);

  if (url.pathname === '/api/preview-demo-login' && request.method === 'POST') {
    const { role } = await request.json().catch(() => ({}));
    const jsonHeaders = { 'Content-Type': 'application/json', Accept: 'application/json' };
    if (role === 'parent') {
      const res = await upstream(env, '/api/v1/parent/login', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ StudentID: Number(env.PREVIEW_DEMO_PARENT_STUDENT_ID), Phone: env.PREVIEW_DEMO_PARENT_PHONE }) });
      const j = res && res.ok ? await res.json().catch(() => null) : null;
      return j?.token ? json({ token: j.token }) : json({ message: 'demo login unavailable' }, 502);
    }
    const account = Object.hasOwn(DEMO_ACCOUNT, role) ? DEMO_ACCOUNT[role] : null;
    const password = role === 'director' ? env.PREVIEW_DEMO_DIRECTOR_PASSWORD : env.PREVIEW_DEMO_TEACHER_PASSWORD;
    if (!account || !password) return json({ message: 'unknown role' }, 400);
    const res = await upstream(env, '/api/v1/auth/login', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ account, password }) });
    const j = res && res.ok ? await res.json().catch(() => null) : null;
    return j?.data?.session ? json({ session: j.data.session }) : json({ message: 'demo login unavailable' }, 502);
  }

  if (!url.pathname.startsWith('/api/v1/')) return json({ message: 'not found' }, 404);
  // Read-only proxy: the preview is reachable by anyone with the URL, so it must never write to staging.
  if (!['GET', 'HEAD'].includes(request.method)) return new Response(JSON.stringify({ message: 'preview is read-only' }), { status: 405, headers: { 'Content-Type': 'application/json', Allow: 'GET, HEAD' } });
  const res = await upstream(env, url.pathname + url.search, { method: request.method, headers: request.headers, body: undefined });
  if (!res) return json({ message: 'not found' }, 404);
  const out = new Headers(res.headers);
  out.delete('set-cookie');
  return new Response(res.body, { status: res.status, headers: out });
}
