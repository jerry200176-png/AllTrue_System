import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// #4721: run the real issues step with a fake gh. A reporter's 「問題仍存在」 reopens the closed canonical issue once.
const wf = readFileSync(new URL('../../.github/workflows/bug-auto-intake.yml', import.meta.url), 'utf8');
const step = wf.slice(wf.indexOf('      - name: Find or create one issue per report'), wf.indexOf('\n  ack:'));
const script = step.split('        run: |\n')[1].split('\n').map((s) => s.replace(/^          /, '')).join('\n');
const repo = 'owner/repo';
const mark = '<!-- inapp-reopen:410:77 -->';
const issue = (state, comments = []) => ({ number: 4152, url: `https://github.com/${repo}/issues/4152`, state,
  body: 'SourceRef: alltrue:bug_report:410', author: { login: 'owner' }, comments });
function run(found, dry = false, reopenFails = false) {
  const dir = mkdtempSync(join(tmpdir(), 'intake-reopen-'));
  try {
    writeFileSync(join(dir, 'data'), JSON.stringify(found));
    writeFileSync(join(dir, 'gh'), `#!/usr/bin/env node
const fs = require('fs'), p = process.env.TEST_DIR, i = JSON.parse(fs.readFileSync(p+'/data')), a = process.argv.slice(2);
fs.appendFileSync(p+'/calls', JSON.stringify(a)+'\\n');
if (a[0] === 'issue' && a[1] === 'list') console.log(i ? '4152' : '');
else if (a[0] === 'issue' && a[1] === 'view' && i) console.log(JSON.stringify(i));
else if (a[0] === 'issue' && a[1] === 'reopen') process.exit(process.env.REOPEN_FAILS ? 1 : 0);
else if (a[0] === 'issue' && a[1] === 'comment') {}
else process.exit(2);
`, { mode: 0o755 });
    const r = spawnSync('bash', ['-c', script], { encoding: 'utf8', env: { ...process.env, PATH: `${dir}:${process.env.PATH}`, TEST_DIR: dir,
      GITHUB_OUTPUT: join(dir, 'out'), GITHUB_REPOSITORY_OWNER: 'owner', GITHUB_REPOSITORY: repo, GH_REPO: repo, CANDIDATES: '[]',
      REOPENED: JSON.stringify([{ bug_id: 410, log_id: 77, at: '2026-10-30T09:00:00+08:00' }]), DRY_RUN: dry ? 'true' : 'false', ...(reopenFails ? { REOPEN_FAILS: '1' } : {}) } });
    let calls = [];
    try { calls = readFileSync(join(dir, 'calls'), 'utf8').trim().split('\n').map(JSON.parse); } catch {}
    assert.equal(r.status, reopenFails ? 1 : 0, r.stderr);
    return calls.filter((a) => a[0] === 'issue' && (a[1] === 'reopen' || a[1] === 'comment')).map((a) => a.join(' '));
  } finally { rmSync(dir, { recursive: true, force: true }); }
}
const once = run(issue('CLOSED'));
assert.equal(once.length, 2, 'a closed canonical issue is reopened, then marked');
assert.match(once[0], /^issue reopen \S+$/);
assert.match(once[1], /^issue comment .*inapp-reopen:410:77 -->Reopened by bug-auto-intake: the reporter of in-app #410/);
assert.deepEqual(run(issue('CLOSED'), false, true).map((c) => c.split(' ')[1]), ['reopen'], 'a failed reopen leaves no marker, so the next run retries');
assert.equal(run(issue('CLOSED', [{ body: mark, author: { login: 'github-actions' } }])).length, 0, 'once per reopen');
assert.equal(run(issue('CLOSED', [{ body: mark, author: { login: 'stranger' } }])).length, 2, 'an untrusted marker suppresses nothing');
assert.equal(run(issue('OPEN')).length, 0, 'an open issue is left alone');
assert.equal(run(issue('CLOSED'), true).length, 0, 'dry run writes nothing');
assert.equal(run(null).length, 0, 'no trusted issue: warn only');
console.log('bug-auto-intake reopen: PASS');
