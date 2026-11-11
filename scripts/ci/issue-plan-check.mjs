#!/usr/bin/env node
// issue-plan-check: does an issue body carry the executable plan that stage 4 (gate:ready) requires?
// ADVISORY in this PR: no workflow runs it and nothing blocks on it. gate:ready reviewers (tech-lead, qa-lead) run it by hand.
// Definition of the plan format (single source): docs/sop/ISSUE_PLAN.md. This file only mirrors its headings.
// Read-only. Offline unless --issue N is given; then one GET to the fixed api.github.com origin (client reused from
// pr-digest.mjs: token never printed, any non-2xx fails closed).
//
// Usage:
//   node scripts/ci/issue-plan-check.mjs [--file path | < body.md] [--json]
//   node scripts/ci/issue-plan-check.mjs --issue N [--repo owner/name] [--json]   (token: GH_TOKEN or GITHUB_TOKEN)
// Exit: 0 plan complete; 1 plan missing/incomplete; 2 usage or API error.
// Forms: full (marker `<!-- plan:v1 -->`, 7 sections) or short (marker `<!-- plan:v1 short -->`, 5 labelled lines; only
// for a fix of ~50 lines or less with no UI change). The checker cannot measure size: the reviewer judges the short form.
import { readFileSync } from 'node:fs';

export const FULL_MARKER = '<!-- plan:v1 -->';
export const SHORT_MARKER = '<!-- plan:v1 short -->';
export const SECTIONS = ['問題與證據', '不可變更清單', '範圍與不做的事', '驗收條件', 'PR 拆分', '上線與復原', '待 Founder 決定'];
export const SHORT_LINES = ['問題', '根因', '修法', '測試', '復原'];
export const ADVISORY = 'advisory: format check only, not a quality review; gate:ready is decided by tech-lead and qa-lead';

const norm = (s) => String(s).replace(/\s+/g, '').toLowerCase();
const PLACEHOLDER = /^(tbd|todo|tba|待補|待填|\.\.\.|…|-|—)$/i;

// Text that counts as content: drop HTML comments (template hints), empty checkboxes/bullets, placeholders.
export function meaningful(text) {
  const t = String(text ?? '').replace(/<!--[\s\S]*?-->/g, '');
  return t.split('\n').map((l) => l.replace(/^\s*(?:[-*+]|\d+[.)])?\s*(?:\[[ xX]?\])?\s*/, '').trim())
    .filter((l) => l && !PLACEHOLDER.test(l)).join('\n');
}

// Pure: body in, verdict out. Never throws.
export function checkPlan(body) {
  const text = String(body ?? '').replace(/\r\n?/g, '\n');
  const short = text.includes(SHORT_MARKER);
  const full = !short && text.includes(FULL_MARKER);
  if (!short && !full) return { form: 'none', ok: false, missing: [], empty: [], note: `no plan marker (${FULL_MARKER} or ${SHORT_MARKER})` };
  if (short) {
    const missing = [];
    const empty = [];
    for (const label of SHORT_LINES) {
      const m = new RegExp(`^\\s*(?:[-*]\\s*)?\\**${label}\\**\\s*[:：]\\**(.*)$`, 'm').exec(text);
      if (!m) missing.push(label);
      else if (!meaningful(m[1])) empty.push(label);
    }
    return { form: 'short', ok: !missing.length && !empty.length, missing, empty };
  }
  const lines = text.split('\n');
  const heads = [];
  let fence = false;
  lines.forEach((l, i) => {
    if (/^\s*(```|~~~)/.test(l)) fence = !fence;
    const m = !fence && /^(#{1,6})\s+(.*?)\s*#*\s*$/.exec(l);
    if (m) heads.push({ i, level: m[1].length, title: norm(m[2]) });
  });
  const missing = [];
  const empty = [];
  for (const name of SECTIONS) {
    const key = norm(name);
    const k = heads.findIndex((h) => h.title === key || h.title.startsWith(key));
    if (k < 0) { missing.push(name); continue; }
    // A section ends at the next heading of the same or higher level; deeper headings belong to it.
    const end = heads.slice(k + 1).find((h) => h.level <= heads[k].level);
    const content = lines.slice(heads[k].i + 1, end ? end.i : lines.length).join('\n');
    if (!meaningful(content)) empty.push(name);
  }
  return { form: 'full', ok: !missing.length && !empty.length, missing, empty };
}

export function format(r) {
  if (r.ok) return `plan ok (${r.form} form)`;
  if (r.form === 'none') return `plan missing: ${r.note}`;
  const p = [`plan incomplete (${r.form} form)`];
  if (r.missing.length) p.push(`missing: ${r.missing.join(', ')}`);
  if (r.empty.length) p.push(`empty: ${r.empty.join(', ')}`);
  return p.join(' | ');
}

export function parseArgs(argv) {
  const o = { json: false, file: null, issue: null, repo: null };
  for (let k = 0; k < argv.length; k++) {
    const a = argv[k];
    const val = () => { const v = argv[++k]; if (v === undefined) throw new Error(`${a} needs a value`); return v; };
    if (a === '--json') o.json = true;
    else if (a === '--file') o.file = val();
    else if (a === '--repo') o.repo = val();
    else if (a === '--issue') { o.issue = Number(val()); if (!Number.isInteger(o.issue) || o.issue < 1) throw new Error('--issue needs an issue number'); }
    else throw new Error(`unknown argument ${a}`);
  }
  if (o.issue && o.file) throw new Error('use --file or --issue, not both');
  return o;
}

// deps: {api, readFile, readStdin} injectable for tests. Returns {code, stdout, stderr}; never throws.
export async function run(argv, env = process.env, deps = {}) {
  const token = env.GH_TOKEN || env.GITHUB_TOKEN || '';
  const redact = (s) => (token ? String(s).split(token).join('***') : String(s));
  try {
    const o = parseArgs(argv);
    let body;
    if (o.issue) {
      const repo = o.repo || env.GITHUB_REPOSITORY;
      if (!/^[\w.-]+\/[\w.-]+$/.test(repo || '')) throw new Error('repo unknown: pass --repo owner/name or set GITHUB_REPOSITORY');
      if (!token && !deps.api) throw new Error('no token: set GH_TOKEN or GITHUB_TOKEN');
      let api = deps.api;
      if (!api) {
        process.env.REPO = repo; // land-queue (imported by pr-digest) reads REPO once at import time
        const pd = await import('./pr-digest.mjs');
        api = pd.makeClient(repo, token);
      }
      const issue = await api.one(`issues/${o.issue}`);
      if (issue?.pull_request) throw new Error(`#${o.issue} is a pull request, not an issue`);
      body = issue?.body ?? '';
    } else if (o.file) {
      body = (deps.readFile || ((f) => readFileSync(f, 'utf8')))(o.file);
    } else {
      body = await (deps.readStdin || (async () => { let s = ''; for await (const c of process.stdin) s += c; return s; }))();
    }
    const r = checkPlan(body);
    const stdout = o.json ? JSON.stringify({ advisory: ADVISORY, ...r }) : `${format(r)}\n${ADVISORY}`;
    return { code: r.ok ? 0 : 1, stdout: redact(stdout), stderr: '' };
  } catch (e) {
    const msg = String(e.message || e).replace(/[\x00-\x1f\x7f]/g, '?').slice(0, 300);
    return { code: 2, stdout: '', stderr: redact(`issue-plan-check: ${msg}`) };
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const r = await run(process.argv.slice(2));
  if (r.stdout) console.log(r.stdout);
  if (r.stderr) console.error(r.stderr);
  process.exitCode = r.code;
}
