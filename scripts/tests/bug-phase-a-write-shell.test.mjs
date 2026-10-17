/**
 * Executable extraction of bug-phase-a-triage.yml write shell.
 * Reproduces the P1: write_one under || / post-ssh echo masking ssh 255.
 * Uses synthetic event payload + fake ssh only — never contacts a host.
 */
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { batchPayloadSha256, sha256 } from '../ci/bug-phase-a-batch.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const WORKFLOW = path.join(ROOT, '.github/workflows/bug-phase-a-triage.yml');
const REPO = 'jerry200176-png/AllTrue_System';

/** Extract the indented `run: |` body of the write step (exact YAML shell). */
function extractWriteShell(source = fs.readFileSync(WORKFLOW, 'utf8')) {
  const marker = '      - name: Write triage and public reply\n';
  const start = source.indexOf(marker);
  assert.ok(start >= 0, 'write step must exist');
  const after = source.slice(start);
  const runAt = after.indexOf('\n        run: |\n');
  assert.ok(runAt >= 0, 'write step must have run: |');
  const bodyStart = runAt + '\n        run: |\n'.length;
  // Next top-level job (2-space indent) ends the step.
  const rest = after.slice(bodyStart);
  const endMatch = rest.match(/\n  [a-z0-9-]+:\n/);
  const body = endMatch ? rest.slice(0, endMatch.index) : rest;
  // Drop the 10-space YAML indent from each line.
  return body
    .split('\n')
    .map((line) => (line.startsWith('          ') ? line.slice(10) : line))
    .join('\n')
    .replace(/\n+$/, '\n');
}

function issue(n) {
  return `https://github.com/${REPO}/issues/${n}`;
}

function makeItem(id, n, disposition, reply) {
  return {
    bug_id: String(id),
    github_issue_url: issue(n),
    disposition,
    public_reply: reply,
  };
}

function setupHarness({ sshExit = 0, sshLogPath, failAfter = 0 } = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'phase-a-write-'));
  const bindir = path.join(dir, 'bin');
  fs.mkdirSync(bindir);
  const sshLog = sshLogPath || path.join(dir, 'ssh.log');
  // Fake ssh: record each invocation; optionally fail after N successes.
  const sshScript = `#!/bin/bash
set +e
echo "ssh_call argv=$*" >> "${sshLog}"
# Count prior calls
n=$(wc -l < "${sshLog}" | tr -d ' ')
if [[ "${failAfter}" -gt 0 && "$n" -gt "${failAfter}" ]]; then
  echo "fake-ssh fail-after" >&2
  exit 255
fi
if [[ "${sshExit}" -ne 0 ]]; then
  echo "fake-ssh exit ${sshExit}" >&2
  exit ${sshExit}
fi
# Success path: consume remote script from stdin and print a synthetic receipt.
cat >/dev/null
echo '{"ok":true,"fake":true}'
exit 0
`;
  fs.writeFileSync(path.join(bindir, 'ssh'), sshScript, { mode: 0o755 });
  // Minimal deploy key path the workflow expects (ssh is faked; key file only needs to exist).
  const home = path.join(dir, 'home');
  fs.mkdirSync(path.join(home, '.ssh'), { recursive: true });
  fs.writeFileSync(path.join(home, '.ssh', 'deploy_key'), 'synthetic');
  fs.writeFileSync(path.join(home, '.ssh', 'known_hosts'), 'synthetic');
  // Provide node + jq from real PATH; prepend fake ssh.
  return { dir, bindir, sshLog, home };
}

function runWriteShell({ event, sshExit = 0, failAfter = 0, envExtra = {} }) {
  const { dir, bindir, sshLog, home } = setupHarness({ sshExit, failAfter });
  const eventPath = path.join(dir, 'event.json');
  fs.writeFileSync(eventPath, JSON.stringify({ inputs: event }));
  const shell = extractWriteShell();
  // Workflow writes /tmp/phase-a-remote.sh and /tmp/phase-a-batch-plan.json — isolate under dir via TMPDIR
  // and rewrite those absolute paths in a wrapper so parallel tests do not collide.
  const isolated = shell
    .replaceAll('/tmp/phase-a-remote.sh', `${dir}/phase-a-remote.sh`)
    .replaceAll('/tmp/phase-a-batch-plan.json', `${dir}/phase-a-batch-plan.json`);
  const scriptPath = path.join(dir, 'write.sh');
  fs.writeFileSync(scriptPath, isolated);
  const result = spawnSync('bash', [scriptPath], {
    cwd: ROOT,
    encoding: 'utf8',
    env: {
      ...process.env,
      PATH: `${bindir}:${process.env.PATH}`,
      HOME: home,
      GITHUB_EVENT_PATH: eventPath,
      GITHUB_REPOSITORY: REPO,
      PI_SSH_USER: 'synthetic',
      PI_SSH_HOST: 'invalid.test',
      BUG_ID: event.bug_id || '',
      GITHUB_ISSUE_URL: event.github_issue_url || '',
      DISPOSITION: event.disposition || '',
      CLOSE_AS_LOGGED: String(event.close_as_logged ?? 'false'),
      ...envExtra,
    },
  });
  return {
    code: result.status,
    stdout: result.stdout || '',
    stderr: result.stderr || '',
    sshLog: fs.existsSync(sshLog) ? fs.readFileSync(sshLog, 'utf8') : '',
    dir,
  };
}

test('extracted write shell contains ssh||return 1 and exact-body dedup markers', () => {
  const shell = extractWriteShell();
  assert.match(shell, /ssh[\s\S]*\|\| return \$\?/, 'write_one must explicitly return ssh status (preserve 255)');
  assert.match(shell, /exact_public_body/, 'REMOTE must use exact public-body dedup');
  assert.match(shell, /public_body_mismatch/, 'REMOTE must post-commit verify public body hash');
  assert.match(shell, /base64 -d; printf x/, 'REMOTE must preserve trailing newlines on PUBLIC_REPLY');
  assert.doesNotMatch(shell, /where\("body", "like", "%\."\s*\.\s*\$issueUrl/, 'URL-only like skip must be gone');
  assert.match(shell, /case "\$DISPOSITION" in[\s\S]*needs_info\)/, 'write_one uses DISPOSITION_KINDS allowlist');
});

test('P1 reproduce: batch with fake ssh255 exits nonzero, does not print false written success', () => {
  const items = [
    makeItem(411, 4156, 'bug', `第一筆。追蹤：${issue(4156)}`),
    makeItem(415, 4158, 'bug', `第二筆。追蹤：${issue(4158)}`),
  ];
  const r = runWriteShell({
    event: { batch_json: JSON.stringify(items) },
    sshExit: 255,
  });
  assert.notEqual(r.code, 0, 'batch must not exit 0 when ssh returns 255');
  assert.match(r.stderr + r.stdout, /batch fail-stop|fake-ssh exit 255/);
  assert.doesNotMatch(r.stdout, /wrote bug_id=/, 'must not print wrote after ssh failure');
  assert.doesNotMatch(r.stdout, /batch complete written=/, 'must not claim batch complete');
  const calls = (r.sshLog.match(/ssh_call/g) || []).length;
  assert.equal(calls, 1, 'fail-stop must not attempt the second row after first ssh 255');
  fs.rmSync(r.dir, { recursive: true, force: true });
});

test('single-mode fake ssh255 exits nonzero without wrote success line', () => {
  const reply = `單筆「引號」與結尾換行。\n追蹤：${issue(4156)}\n`;
  const r = runWriteShell({
    event: {
      bug_id: '411',
      github_issue_url: issue(4156),
      disposition: 'bug',
      public_reply: reply,
      close_as_logged: 'false',
      batch_json: '',
    },
    sshExit: 255,
  });
  assert.equal(r.code, 255, 'single-mode must surface ssh exit 255 under set -e');
  assert.doesNotMatch(r.stdout, /wrote bug_id=411/);
  fs.rmSync(r.dir, { recursive: true, force: true });
});

test('batch middle failure stops and keeps only prior written ids', () => {
  const items = [
    makeItem(411, 4156, 'bug', `ok1：${issue(4156)}`),
    makeItem(415, 4158, 'bug', `fail：${issue(4158)}`),
    makeItem(416, 4195, 'suggestion', `never：${issue(4195)}`),
  ];
  const r = runWriteShell({
    event: { batch_json: JSON.stringify(items) },
    sshExit: 0,
    failAfter: 1, // first succeeds, second+ fail
  });
  assert.notEqual(r.code, 0);
  assert.match(r.stdout + r.stderr, /batch fail-stop at bug_id=415/);
  assert.match(r.stdout, /wrote bug_id=411/);
  assert.doesNotMatch(r.stdout, /wrote bug_id=415/);
  assert.doesNotMatch(r.stdout, /wrote bug_id=416/);
  assert.doesNotMatch(r.stdout, /batch complete/);
  const calls = (r.sshLog.match(/ssh_call/g) || []).length;
  assert.equal(calls, 2, 'third row must not be attempted after middle failure');
  fs.rmSync(r.dir, { recursive: true, force: true });
});

test('batch success path prints written with reply hashes (Unicode/quotes/newline preserved in plan)', () => {
  const reply1 = `老師您好，「已看過」。\n追蹤：${issue(4156)}\n`;
  const reply2 = `第二筆 emoji 🙂 追蹤：${issue(4158)}`;
  const items = [
    makeItem(411, 4156, 'bug', reply1),
    makeItem(415, 4158, 'bug', reply2),
  ];
  const expectedSha = batchPayloadSha256(items);
  const r = runWriteShell({
    event: { batch_json: JSON.stringify(items) },
    sshExit: 0,
  });
  assert.equal(r.code, 0, r.stderr);
  assert.match(r.stdout, new RegExp(`batch_payload_sha256=${expectedSha}`));
  assert.match(r.stdout, /wrote bug_id=411/);
  assert.match(r.stdout, /wrote bug_id=415/);
  assert.match(r.stdout, new RegExp(`reply_sha256=${sha256(reply1)}`));
  assert.match(r.stdout, /batch complete written=.*411.*415/);
  // Trailing newline in reply1 must survive into the plan hash (x-sentinel round-trip).
  assert.equal(sha256(reply1), createHash('sha256').update(reply1, 'utf8').digest('hex'));
  fs.rmSync(r.dir, { recursive: true, force: true });
});

test('preview rejects this_is_not_valid disposition before any write', () => {
  const source = fs.readFileSync(WORKFLOW, 'utf8');
  const marker = '      - name: Show the requested write\n';
  const start = source.indexOf(marker);
  const after = source.slice(start);
  const runAt = after.indexOf('\n        run: |\n');
  const bodyStart = runAt + '\n        run: |\n'.length;
  const rest = after.slice(bodyStart);
  const endMatch = rest.match(/\n  [a-z0-9-]+:\n/);
  const body = (endMatch ? rest.slice(0, endMatch.index) : rest)
    .split('\n')
    .map((line) => (line.startsWith('          ') ? line.slice(10) : line))
    .join('\n');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'phase-a-prev-'));
  try {
    const eventPath = path.join(dir, 'event.json');
    fs.writeFileSync(eventPath, JSON.stringify({
      inputs: {
        bug_id: '411',
        github_issue_url: issue(4156),
        disposition: 'this_is_not_valid',
        public_reply: `x ${issue(4156)}`,
        close_as_logged: 'false',
        batch_json: '',
      },
    }));
    const summary = path.join(dir, 'summary.md');
    fs.writeFileSync(summary, '');
    const script = path.join(dir, 'preview.sh');
    fs.writeFileSync(script, body);
    const result = spawnSync('bash', [script], {
      cwd: ROOT,
      encoding: 'utf8',
      env: {
        ...process.env,
        GITHUB_EVENT_PATH: eventPath,
        GITHUB_REPOSITORY: REPO,
        GITHUB_STEP_SUMMARY: summary,
      },
    });
    assert.notEqual(result.status, 0);
    assert.match((result.stdout || '') + (result.stderr || ''), /invalid disposition/);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('static: one Founder gate / no-close / no shell injection markers remain', () => {
  const source = fs.readFileSync(WORKFLOW, 'utf8');
  assert.match(source, /environment: production-activation/);
  assert.match(source, /batch mode is triaged-only/);
  assert.match(source, /close_as_logged forced false|close_as_logged: false \(batch/);
  assert.doesNotMatch(source, /eval |\$\{!|`\$\{/);
});
