#!/usr/bin/env node
// pr-digest: one compact line per open PR (token saver for agents; replaces dumping check-run / PR JSON).
// Read-only: only GET requests to the GitHub REST API. Never prints the token.
//
// Usage:
//   node scripts/ci/pr-digest.mjs [--repo owner/name] [--pr N] [--json] [--max-lines N]
//   Token: GH_TOKEN or GITHUB_TOKEN. Repo: --repo or GITHUB_REPOSITORY.
//
// Line: #N sha8 [draft] ms:<mergeable_state> req:ok/total [fail:a,b] [pend:c] [miss:d] [adv:x] rv:<review> mk:<markers>
//       thr:<n|?> behind:<n> L:<labels>
//   - req counts REQUIRED checks only (the ruleset list from land-queue's requiredChecks(); newest run per name wins;
//     success/neutral/skipped = ok; failure/cancelled/timed_out/... = fail; absent from the head = miss).
//   - rv: review-gate's verdict for the exact head (scope not evaluated, so R3's extra marker is NOT checked);
//     review/final-head check state is shown as fh:<state>. mk: owner markers naming the exact head only.
//   - thr: unresolved review threads. REST cannot read resolution state, so this is `?` (unknown), never a guess.
// Exit: non-zero only on API/auth/usage errors. An API error is printed as ERROR and never rendered as green.
//
// Reused (not re-implemented): requiredChecks / checkStates (.github/scripts/land-queue.mjs),
// reviewGate / REVIEW_CHECK / reviewMarker / gateMarker (.github/scripts/review-gate.mjs).
// Limit: required checks are matched by name only (land-queue additionally pins the reporting app).
// land-queue / review-gate read REPO once at import time (only their `gh` helpers use it), so set it from --repo first.
const repoArg = process.argv.indexOf('--repo');
if (repoArg > 0 && process.argv[repoArg + 1]) process.env.REPO = process.argv[repoArg + 1];
const { checkStates, unresolvedCount, requiredChecks } = await import('../../.github/scripts/land-queue.mjs');
const { REVIEW_CHECK, reviewGate, reviewMarker } = await import('../../.github/scripts/review-gate.mjs');

const WATCH_LABELS = ['queue', 'autoland', 'do-not-touch', 'needs-founder'];
const SHA40 = /^[0-9a-f]{40}$/;

// REST check runs + commit statuses -> the rollup node shape checkStates() reads.
export function toRollup(checkRuns, statuses) {
  return [
    ...(checkRuns || []).map((r) => ({ name: r.name, conclusion: String(r.conclusion || '').toUpperCase() || null,
      status: r.status, startedAt: r.started_at || r.created_at })),
    ...(statuses || []).map((s) => ({ context: s.context, state: String(s.state || '').toUpperCase(), createdAt: s.created_at })),
  ];
}

// Marker kinds the owner left for exactly this head: ['review', 'gate:qa-lead', 'gate:x(fail)'].
export function markersOn(comments, head, owner) {
  if (!Array.isArray(comments) || !owner || !SHA40.test(head || '')) return [];
  const lower = owner.toLowerCase();
  const out = new Set();
  for (const c of comments) {
    if (c?.user?.login?.toLowerCase() !== lower) continue;
    const body = c.body || '';
    if (body.includes(reviewMarker(head))) out.add('review');
    for (const m of body.matchAll(/<!-- gate:([a-z-]+):v1 sha=([0-9a-f]{40}) verdict=(pass|fail) -->/g)) {
      if (m[2] === head) out.add(`gate:${m[1]}${m[3] === 'fail' ? '(fail)' : ''}`);
    }
  }
  return [...out].sort();
}

// Pure: API payloads in, one digest row out.
// in: {pr (pulls/N), checkRuns, statuses, comments (null = unreadable), behindBy (null = unknown), threads (GraphQL-shaped conn or null),
//      required: [names], owner}
export function summarizePr(i) {
  const head = i.pr.head?.sha || '';
  const rollup = toRollup(i.checkRuns, i.statuses);
  const present = new Set(rollup.map((c) => c.name || c.context));
  const req = i.required || [];
  const st = checkStates(rollup, req);
  const bad = (s) => req.filter((n) => present.has(n) && st[n] === s);
  const fail = bad('fail');
  const pending = bad('pending');
  const missing = req.filter((n) => !present.has(n));
  const okCount = req.filter((n) => present.has(n) && st[n] === 'pass').length;
  const others = [...present].filter((n) => !req.includes(n) && n !== REVIEW_CHECK);
  const ast = checkStates(rollup, others);
  const advisoryFail = others.filter((n) => ast[n] === 'fail');
  const fh = checkStates(rollup, [REVIEW_CHECK]);
  const finalHead = present.has(REVIEW_CHECK) ? fh[REVIEW_CHECK] : 'none';
  const rg = Array.isArray(i.comments)
    ? reviewGate(i.comments, head, i.owner, 'lower')
    : { ok: false, why: 'PR comments unreadable' };
  const labels = (i.pr.labels || []).map((l) => l.name).filter((n) => WATCH_LABELS.includes(n));
  return {
    n: i.pr.number, sha: head.slice(0, 8), draft: !!i.pr.draft, labels,
    mergeable: i.pr.mergeable_state || 'unknown',
    req: { ok: okCount, total: req.length, fail, pending, missing },
    advisoryFail, finalHead,
    review: rg.ok ? rg.via : `no(${String(rg.why || '').slice(0, 60)})`,
    markers: markersOn(i.comments, head, i.owner),
    threads: unresolvedCount(i.threads), // null = unknown
    behind: Number.isInteger(i.behindBy) ? i.behindBy : null,
  };
}

const list = (a, max = 6) => (a.length > max ? `${a.slice(0, max).join(',')},+${a.length - max}` : a.join(','));

export function formatRow(r, verbose = false) {
  if (r.error) return `#${r.n} ERROR ${r.error}`;
  const p = [`#${r.n}`, r.sha];
  if (r.draft) p.push('draft');
  p.push(`ms:${r.mergeable}`, `req:${r.req.ok}/${r.req.total}`);
  if (r.req.fail.length) p.push(`fail:${list(r.req.fail)}`);
  if (r.req.pending.length) p.push(`pend:${list(r.req.pending)}`);
  if (r.req.missing.length) p.push(`miss:${list(r.req.missing)}`);
  if (r.advisoryFail.length) p.push(`adv:${list(r.advisoryFail, 3)}`);
  p.push(`fh:${r.finalHead}`, `rv:${r.review}`);
  if (r.markers.length) p.push(`mk:${r.markers.join(',')}`);
  p.push(`thr:${r.threads ?? '?'}`, `behind:${r.behind ?? '?'}`);
  if (r.labels.length) p.push(`L:${r.labels.join(',')}`);
  let line = p.join(' ');
  if (verbose) {
    const jobs = [...r.req.fail, ...r.advisoryFail];
    line += `\n  failing jobs: ${jobs.length ? jobs.join('; ') : 'none'}`;
  }
  return line;
}

export const redact = (s, token) => (token ? String(s).split(token).join('***') : String(s));

export function parseArgs(argv) {
  const o = { json: false, pr: null, repo: null, maxLines: Infinity };
  for (let k = 0; k < argv.length; k++) {
    const a = argv[k];
    const val = () => { const v = argv[++k]; if (v === undefined) throw new Error(`${a} needs a value`); return v; };
    if (a === '--json') o.json = true;
    else if (a === '--pr') { o.pr = Number(val()); if (!Number.isInteger(o.pr) || o.pr < 1) throw new Error('--pr needs a PR number'); }
    else if (a === '--repo') o.repo = val();
    else if (a === '--max-lines') { o.maxLines = Number(val()); if (!Number.isInteger(o.maxLines) || o.maxLines < 1) throw new Error('--max-lines needs a positive integer'); }
    else throw new Error(`unknown argument ${a}`);
  }
  return o;
}

// GET-only REST client with Link-header pagination. Throws on any non-2xx (fail closed).
export function makeClient(repo, token, fetchImpl = globalThis.fetch) {
  const base = 'https://api.github.com';
  const get = async (path) => {
    let url = `${base}/repos/${repo}/${path}`;
    const pages = [];
    for (let guard = 0; url && guard < 50; guard++) {
      const res = await fetchImpl(url, { method: 'GET', headers: { Authorization: `Bearer ${token}`,
        Accept: 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'pr-digest' } });
      if (!res.ok) throw new Error(`GET ${path} -> HTTP ${res.status}`);
      pages.push(await res.json());
      url = /<([^>]+)>;\s*rel="next"/.exec(res.headers?.get?.('link') || '')?.[1] || null;
    }
    return pages;
  };
  return {
    one: async (path) => (await get(path))[0],
    all: async (path, key) => (await get(path)).flatMap((p) => (key ? p[key] : p) || []),
  };
}

async function digestOne(api, pr, ctx) {
  const n = pr.number;
  const full = await api.one(`pulls/${n}`);
  const head = full.head.sha;
  const [checkRuns, status, comments, cmp] = await Promise.all([
    api.all(`commits/${head}/check-runs?per_page=100`, 'check_runs'),
    api.one(`commits/${head}/status?per_page=100`),
    api.all(`issues/${n}/comments?per_page=100`),
    api.one(`compare/${encodeURIComponent(full.base?.ref || 'main')}...${head}?per_page=1`),
  ]);
  return summarizePr({ pr: full, checkRuns, statuses: status?.statuses, comments,
    behindBy: cmp?.behind_by, threads: null, required: ctx.required, owner: ctx.owner });
}

// deps: {api, requiredChecks} injectable for tests. Returns {code, stdout, stderr}; never throws.
export async function run(argv, env = process.env, deps = {}) {
  const token = env.GH_TOKEN || env.GITHUB_TOKEN || '';
  const out = [];
  const err = [];
  try {
    const o = parseArgs(argv);
    const repo = o.repo || env.GITHUB_REPOSITORY;
    if (!/^[\w.-]+\/[\w.-]+$/.test(repo || '')) throw new Error('repo unknown: pass --repo owner/name or set GITHUB_REPOSITORY');
    if (!token && !deps.api) throw new Error('no token: set GH_TOKEN or GITHUB_TOKEN');
    const api = deps.api || makeClient(repo, token);
    process.env.GH_TOKEN = token; // requiredChecks() shells out to `gh api` (GET) which reads GH_TOKEN
    const required = (deps.requiredChecks || requiredChecks)().required;
    if (!Array.isArray(required) || !required.length) throw new Error('no required checks found');
    const ctx = { required, owner: repo.split('/')[0] };
    const prs = o.pr ? [{ number: o.pr }] : await api.all('pulls?state=open&base=main&per_page=100');
    const rows = [];
    let failed = false;
    for (const pr of prs) {
      try { rows.push(await digestOne(api, pr, ctx)); } catch (e) { failed = true; rows.push({ n: pr.number, error: String(e.message).split('\n')[0] }); }
    }
    if (o.json) out.push(JSON.stringify(rows));
    else {
      const shown = rows.slice(0, o.maxLines);
      for (const r of shown) out.push(formatRow(r, o.pr !== null));
      if (rows.length > shown.length) out.push(`... ${rows.length - shown.length} more (raise --max-lines)`);
      if (!rows.length) out.push('no open PRs to main');
    }
    return { code: failed ? 1 : 0, stdout: redact(out.join('\n'), token), stderr: '' };
  } catch (e) {
    err.push(`pr-digest: ${e.message}`);
    return { code: 1, stdout: redact(out.join('\n'), token), stderr: redact(err.join('\n'), token) };
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const r = await run(process.argv.slice(2));
  if (r.stdout) console.log(r.stdout);
  if (r.stderr) console.error(r.stderr);
  process.exitCode = r.code;
}
