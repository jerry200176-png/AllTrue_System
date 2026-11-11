import assert from 'node:assert/strict';
import test from 'node:test';
import { checkPlan, meaningful, run, parseArgs, SECTIONS, FULL_MARKER, SHORT_MARKER, SHORT_LINES } from './issue-plan-check.mjs';

const FOUNDER = '待 Founder 決定';
// A full plan body; pass {name: null} to drop a section, {name: text} to change its content.
const full = (over = {}) => {
  const bodies = Object.fromEntries(SECTIONS.map((s) => [s, `${s} 的內容`]));
  Object.assign(bodies, over);
  return `${FULL_MARKER}\n` + SECTIONS.map((s) => (bodies[s] === null ? '' : `## ${s}\n${bodies[s]}\n`)).join('\n');
};

test('complete full plan passes', () => {
  const r = checkPlan(full());
  assert.equal(r.ok, true);
  assert.equal(r.form, 'full');
});

test('no marker is not a plan, even with all headings', () => {
  const r = checkPlan(full().replace(FULL_MARKER, ''));
  assert.equal(r.ok, false);
  assert.equal(r.form, 'none');
});

test('missing section is named', () => {
  const r = checkPlan(full({ 驗收條件: null }));
  assert.deepEqual(r.missing, ['驗收條件']);
  assert.equal(r.ok, false);
});

test('empty section: blank, comment-only, empty checkbox and placeholders all count as empty', () => {
  for (const blank of ['', '   ', '<!-- 填這裡 -->', '- [ ] ', 'TBD', '待補', '-']) {
    const r = checkPlan(full({ 不可變更清單: blank }));
    assert.deepEqual(r.empty, ['不可變更清單'], JSON.stringify(blank));
  }
});

test('an explicit "無" and a checked box are content', () => {
  assert.equal(checkPlan(full({ [FOUNDER]: '無' })).ok, true);
  assert.equal(checkPlan(full({ 驗收條件: '- [x] 頁面導覽仍可點' })).ok, true);
});

test('heading with trailing text and deeper subheadings match; a section ends at the next same-level heading', () => {
  const body = full({ 驗收條件: '### 後端\n- 回傳 200' }).replace('## 驗收條件', '## 驗收條件（每條可寫成測試）');
  assert.equal(checkPlan(body).ok, true);
  const emptyThenNext = `${FULL_MARKER}\n` + SECTIONS.map((s) => `## ${s}\n${s === '範圍與不做的事' ? '' : 'x'}\n`).join('');
  assert.deepEqual(checkPlan(emptyThenNext).empty, ['範圍與不做的事']);
});

test('headings inside code fences are ignored', () => {
  const body = `${FULL_MARKER}\n\`\`\`\n${SECTIONS.map((s) => `## ${s}\nx`).join('\n')}\n\`\`\`\n`;
  assert.equal(checkPlan(body).missing.length, SECTIONS.length);
});

test('CRLF bodies work', () => {
  assert.equal(checkPlan(full().replace(/\n/g, '\r\n')).ok, true);
});

test('short form: five labelled lines pass; missing or empty label fails', () => {
  const ok = `${SHORT_MARKER}\n` + SHORT_LINES.map((l) => `- ${l}：內容`).join('\n');
  assert.equal(checkPlan(ok).ok, true);
  assert.equal(checkPlan(ok).form, 'short');
  const r = checkPlan(`${SHORT_MARKER}\n問題: x\n根因:\n修法: y\n復原: z`);
  assert.deepEqual(r.missing, ['測試']);
  assert.deepEqual(r.empty, ['根因']);
});

test('null and undefined bodies never throw', () => {
  assert.equal(checkPlan(null).ok, false);
  assert.equal(checkPlan(undefined).form, 'none');
  assert.equal(meaningful(null), '');
});

test('run: stdin, exit codes and --json', async () => {
  const good = await run([], {}, { readStdin: async () => full() });
  assert.equal(good.code, 0);
  assert.match(good.stdout, /plan ok/);
  const bad = await run(['--json'], {}, { readStdin: async () => full({ 驗收條件: null }) });
  assert.equal(bad.code, 1);
  const j = JSON.parse(bad.stdout);
  assert.deepEqual(j.missing, ['驗收條件']);
  assert.match(j.advisory, /advisory/);
});

test('run: --file reads the file and needs no token', async () => {
  const r = await run(['--file', 'x.md'], {}, { readFile: (f) => { assert.equal(f, 'x.md'); return full(); } });
  assert.equal(r.code, 0);
});

test('run: --issue uses the injected client, refuses PRs, fails closed on API error', async () => {
  const seen = [];
  const api = { one: async (p) => { seen.push(p); return { body: full() }; } };
  assert.equal((await run(['--issue', '12', '--repo', 'o/r'], {}, { api })).code, 0);
  assert.deepEqual(seen, ['issues/12']);
  const pr = await run(['--issue', '12', '--repo', 'o/r'], {}, { api: { one: async () => ({ pull_request: {}, body: full() }) } });
  assert.equal(pr.code, 2);
  const down = await run(['--issue', '12', '--repo', 'o/r'], {}, { api: { one: async () => { throw new Error('GET issues/12 -> HTTP 500'); } } });
  assert.equal(down.code, 2);
  assert.equal(down.stdout, '');
  const none = await run(['--issue', '12', '--repo', 'o/r'], {}, { api: { one: async () => ({ body: null }) } });
  assert.equal(none.code, 1);
});

test('run: --issue without token or repo is a usage error, not a network call', async () => {
  assert.equal((await run(['--issue', '3'], {}, {})).code, 2);
  assert.match((await run(['--issue', '3', '--repo', 'o/r'], {}, {})).stderr, /no token/);
});

test('run: the real client calls only api.github.com for --issue and never leaks the token', async () => {
  const token = 'ghp_secret_token_value';
  const calls = [];
  const realFetch = globalThis.fetch;
  globalThis.fetch = async (url, init) => {
    calls.push([String(url), init.headers.Authorization]);
    return { ok: true, headers: { get: () => null }, json: async () => ({ body: `${full()}\n${token}` }) };
  };
  try {
    const r = await run(['--issue', '9', '--repo', 'o/r', '--json'], { GH_TOKEN: token });
    assert.equal(r.code, 0);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], 'https://api.github.com/repos/o/r/issues/9');
    assert.ok(!r.stdout.includes(token) && !r.stderr.includes(token));
    assert.equal((await run([], {}, { readStdin: async () => full() })).code, 0);
    assert.equal(calls.length, 1, 'stdin mode made no network call');
  } finally { globalThis.fetch = realFetch; }
});

test('parseArgs rejects unknown and conflicting flags', () => {
  assert.throws(() => parseArgs(['--nope']), /unknown/);
  assert.throws(() => parseArgs(['--file', 'a', '--issue', '1']), /not both/);
  assert.throws(() => parseArgs(['--issue', 'x']), /issue number/);
});

test('the issue template carries the marker and every required heading, and an unfilled template fails', async () => {
  const { readFileSync } = await import('node:fs');
  const tpl = readFileSync(new URL('../../.github/ISSUE_TEMPLATE/04_plan.md', import.meta.url), 'utf8');
  const r = checkPlan(tpl);
  assert.equal(r.form, 'full');
  assert.deepEqual(r.missing, []);
  assert.deepEqual(r.empty, SECTIONS);
});
