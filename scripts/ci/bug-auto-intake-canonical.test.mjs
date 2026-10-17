import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// Execute the actual workflow shell with a local fake gh; no GitHub or production writes.
const wf = readFileSync(new URL('../../.github/workflows/bug-auto-intake.yml', import.meta.url), 'utf8');
const step = wf.slice(wf.indexOf('      - name: Find or create one issue per report'), wf.indexOf('\n  ack:'));
const script = step.split('        run: |\n')[1].split('\n').map((s) => s.replace(/^          /, '')).join('\n');
const repo = 'owner/repo';
const issue = (n, state = 'OPEN', body = 'SourceRef: alltrue:bug_report:416', who = 'owner', comments = []) =>
  ({ number: n, url: `https://github.com/${repo}/issues/${n}`, state, body, author: { login: who }, comments });
function run(issues, opts = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'intake-canonical-'));
  try {
    writeFileSync(join(dir, 'data'), JSON.stringify({ issues, ...opts }));
    writeFileSync(join(dir, 'gh'), `#!/usr/bin/env node
const fs = require('fs'), p = process.env.TEST_DIR, cfg = JSON.parse(fs.readFileSync(p+'/data')), a = process.argv.slice(2);
fs.appendFileSync(p+'/calls', JSON.stringify(a)+'\\n');
const created = fs.existsSync(p+'/created');
let issues = [...cfg.issues, ...(created ? (cfg.race || []) : [])];
if (created) issues.push({number:9000,url:'https://github.com/owner/repo/issues/9000',state:'OPEN',body:'SourceRef: alltrue:bug_report:416',author:{login:'github-actions'},comments:[]});
if (a[0] === 'issue' && a[1] === 'list') {
  if (cfg.searchError || (created && cfg.searchErrorAfterCreate)) process.exit(1);
  console.log(issues.filter(x=>!(cfg.hidden || []).includes(x.number)).map(x=>x.number).join('\\n'));
} else if (a[0] === 'issue' && a[1] === 'view') {
  const n = Number(a[2]); if ((cfg.viewError || []).includes(n)) process.exit(1);
  const i = issues.find(x=>x.number===n); if (!i) process.exit(1); console.log(JSON.stringify(i));
} else if (a[0] === 'issue' && a[1] === 'create') {
  fs.writeFileSync(p+'/created','1'); console.log('https://github.com/owner/repo/issues/9000');
} else if (a[0] === 'issue' && ['reopen','close'].includes(a[1])) {
  if (cfg.writeError) process.exit(1);
} else process.exit(2);
`, { mode: 0o755 });
    writeFileSync(join(dir, 'sleep'), '#!/bin/sh\nexit 0\n', { mode: 0o755 });
    const result = spawnSync('bash', ['-c', script], { encoding: 'utf8', env: { ...process.env, PATH: `${dir}:${process.env.PATH}`,
      TEST_DIR: dir, GITHUB_OUTPUT: join(dir, 'output'), GITHUB_REPOSITORY_OWNER: 'owner', GITHUB_REPOSITORY: repo, GH_REPO: repo,
      CANDIDATES: JSON.stringify([{ bug_id: 416, campus_id: 1, severity: 'medium', family: '' }]), DRY_RUN: opts.dry ? 'true' : 'false' } });
    let output = '', calls = [];
    try { output = readFileSync(join(dir, 'output'), 'utf8'); } catch {}
    try { calls = readFileSync(join(dir, 'calls'), 'utf8').trim().split('\n').map(JSON.parse); } catch {}
    return { ...result, output, calls };
  } finally { rmSync(dir, { recursive: true, force: true }); }
}
const writes = (r, operation) => r.calls.filter((a) => a[0] === 'issue' && a[1] === operation);
const duplicate = issue(4182, 'CLOSED', 'SourceRef: alltrue:bug_report:416\n\nCanonical tracking: #4195. SourceRef is preserved there and above.', 'github-actions');
let r = run([duplicate, issue(4195)]);
assert.equal(r.status, 0, r.stderr);
assert.match(r.output, /issues\/4195/);
assert.equal(writes(r, 'reopen').length, 0, 'consolidated duplicate stays closed');
// Exact report ownership and trusted consolidation, including shared-issue comment refs.
const marker = (target) => `<!-- intake-canonical:alltrue:bug_report:416:${target} -->`;
const comment = (body, who = 'owner') => ({ body, author: { login: who } });
function ok(result, n) {
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.output, new RegExp(`issues/${n}\\"`));
}
function blocked(result) {
  assert.notEqual(result.status, 0);
  assert.equal(result.output, '');
  for (const op of ['create', 'reopen', 'close']) assert.equal(writes(result, op).length, 0, op);
}
ok(run([duplicate, issue(4195)], { hidden: [4195] }), 4195); // Direct target lookup outside search window.
r = run([issue(4182, 'CLOSED')]); ok(r, 4182);
assert.equal(writes(r, 'reopen').length, 1, 'ordinary closed canonical still reopens');
r = run([duplicate, issue(4195, 'CLOSED')]); ok(r, 4195);
assert.equal(writes(r, 'reopen')[0][2], 'https://github.com/owner/repo/issues/4195');
r = run([duplicate, issue(4195)], { dry: true }); ok(r, 4195);
assert.equal(writes(r, 'reopen').length, 0);
ok(run([issue(1, 'OPEN', 'SourceRef: alltrue:bug_report:4161'), issue(2)]), 2);
ok(run([issue(1, 'OPEN', 'Related earlier SourceRef: alltrue:bug_report:416'), issue(2)]), 2);
ok(run([issue(1, 'OPEN', '## **SourceRef:** `alltrue:bug_report:416`')]), 1);
ok(run([issue(1, 'CLOSED', 'SourceRef: alltrue:bug_report:416\n' + marker(2)),
  issue(2, 'OPEN', '', 'mallory', [comment('SourceRef: alltrue:bug_report:416')])]), 2);
// Public issue authors/comments cannot redirect a trusted SourceRef on a shared issue.
ok(run([issue(1, 'OPEN', 'SourceRef: alltrue:bug_report:416\n' + marker(2), 'mallory'), issue(3)]), 3);
ok(run([issue(1, 'OPEN', 'SourceRef: alltrue:bug_report:416', 'owner', [comment(marker(2), 'mallory')]), issue(2)]), 1);
ok(run([issue(1, 'OPEN', marker(2), 'mallory', [comment('SourceRef: alltrue:bug_report:416')]), issue(2)]), 1);
ok(run([issue(1, 'OPEN', 'SourceRef: alltrue:bug_report:416\nCanonical tracking: https://github.com/evil/repo/issues/2'), issue(2)]), 1);
for (const target of [issue(4195, 'OPEN', 'SourceRef: alltrue:bug_report:4161'),
  issue(4195, 'OPEN', 'SourceRef: alltrue:bug_report:416', 'mallory'),
  { ...issue(4195), url: 'https://github.com/evil/repo/issues/4195' },
  { ...issue(4195), url: 'https://github.com/owner/repo/pull/4195' },
  issue(4195, 'OPEN', 'SourceRef: alltrue:bug_report:416\n' + marker(4182))]) blocked(run([duplicate, target]));
blocked(run([duplicate])); // Missing target is never "not found, create another".
blocked(run([duplicate, issue(4195)], { viewError: [4195] }));
blocked(run([issue(1), issue(2)], { viewError: [2] })); // Scan failure after an early match also fails closed.
blocked(run([issue(1)], { searchError: true }));
blocked(run([issue(1, 'OPEN', 'SourceRef: alltrue:bug_report:416\n' + marker(1))]));
blocked(run([duplicate, issue(2, 'OPEN', 'SourceRef: alltrue:bug_report:416\n' + marker(3)), issue(4195), issue(3)]));
r = run([], { race: [issue(8999, 'CLOSED', 'SourceRef: alltrue:bug_report:416\n' + marker(9500)), issue(9500, 'CLOSED')] });
ok(r, 9500);
assert.equal(writes(r, 'close')[0][2], 'https://github.com/owner/repo/issues/9000');
assert.equal(writes(r, 'reopen')[0][2], 'https://github.com/owner/repo/issues/9500', 'newer explicit canonical wins creation race');
r = run([], { race: [issue(8999)] }); ok(r, 8999);
assert.equal(writes(r, 'close').length, 1, 'legacy oldest trusted race dedupe retained');
r = run([]); ok(r, 9000); assert.equal(writes(r, 'create').length, 1);
r = run([], { dry: true }); assert.equal(r.status, 0); assert.equal(writes(r, 'create').length, 0);
for (const opts of [{ searchErrorAfterCreate: true },
  { race: [issue(8999, 'CLOSED', 'SourceRef: alltrue:bug_report:416\n' + marker(9500))] }]) {
  r = run([], opts);
  assert.notEqual(r.status, 0); assert.equal(r.output, '');
  assert.equal(writes(r, 'create').length, 1, 'created issue history remains after race lookup failure');
  assert.equal(writes(r, 'reopen').length, 0); assert.equal(writes(r, 'close').length, 0);
}
for (const [issues, opts, failedOp] of [[ [issue(1, 'CLOSED')], { writeError: true }, 'reopen' ],
  [ [], { race: [issue(8999)], writeError: true }, 'close' ]]) {
  r = run(issues, opts); assert.notEqual(r.status, 0); assert.equal(r.output, '');
  assert.equal(writes(r, failedOp).length, 1, 'failed mutation never produces acknowledgement pairs');
}
console.log('bug-auto-intake canonical behavior: PASS');
