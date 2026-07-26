#!/usr/bin/env node
/** Minimal CI report: node scripts/ci-actions-report.mjs --days 30 [--from-cache f.json] */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { classifySameShaRerun } from './ci/gov-codes.mjs';

const a = process.argv.slice(2);
const flag = (n, d = null) => { const i = a.indexOf(n); return i < 0 ? d : a[i + 1]; };
const days = Number(flag('--days', '30'));
const cache = flag('--from-cache');
const cutoff = new Date(Date.now() - days * 864e5).toISOString();
const runs = (cache ? JSON.parse(fs.readFileSync(cache, 'utf8')) : (() => {
  const out = [];
  for (let p = 1; p <= 30; p++) {
    const b = JSON.parse(execFileSync('gh', ['api', `repos/jerry200176-png/AllTrue_System/actions/runs?per_page=100&page=${p}`], { encoding: 'utf8' })).workflow_runs || [];
    if (!b.length) break;
    for (const r of b) { if (r.created_at < cutoff) return out; out.push(r); }
  }
  return out;
})()).filter((r) => r.created_at >= cutoff);

const map = new Map();
for (const r of runs) {
  if (!['success', 'failure'].includes(r.conclusion)) continue;
  const k = `${r.name}:${r.head_sha}`;
  (map.get(k) || map.set(k, []).get(k)).push(r);
}
let flakes = 0;
for (const lst of map.values()) {
  lst.sort((x, y) => x.created_at.localeCompare(y.created_at));
  let f = false;
  for (const r of lst) {
    if (r.conclusion === 'failure') f = true;
    else if (r.conclusion === 'success' && f) { flakes++; break; }
  }
}
const pr = runs.filter((r) => r.event === 'pull_request');
const ok = pr.filter((r) => r.conclusion === 'success').length;
const bad = pr.filter((r) => r.conclusion === 'failure').length;
const fail = {};
for (const r of runs.filter((r) => r.conclusion === 'failure')) fail[r.name] = (fail[r.name] || 0) + 1;
console.log(`# CI Report ${days}d runs=${runs.length} PR ok/fail=${ok}/${bad} flakes=${flakes}`);
for (const [n, c] of Object.entries(fail).sort((x, y) => y[1] - x[1]).slice(0, 8)) console.log(`- ${c} × ${n}`);
void classifySameShaRerun;
