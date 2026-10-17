/**
 * Extracted write-step shell tests for bug-phase-a-triage.yml (runner + static YAML).
 *
 * Evidence boundary (honest):
 * - These tests (12 here + 8 batch = 20 Node) prove the **Actions runner** gate:
 *   receipt file parse, jq/hash/issue checks, fail-stop, sentinel scrubbing.
 * - Fake `ssh` **discards REMOTE stdin** (`cat >/dev/null`) and synthesizes
 *   stdout/exit — so this suite does **not** execute the REMOTE heredoc, does
 *   **not** run tinker/MySQL, and must not be cited as REMOTE-gate evidence.
 * - Static `extractWriteShell()` asserts only that YAML *contains* REMOTE
 *   receipt/`PHASE_A_FAIL` text; that is source inspection, not a live REMOTE run.
 * - Live REMOTE tinker + PsySH exit(1)→1 / exit success→0 + chunkById/txn:
 *   `backend/tests/Feature/BugPhaseATinkerIntegrationTest.php` (no invented wrapper).
 */
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
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
  const rest = after.slice(bodyStart);
  const endMatch = rest.match(/\n  [a-z0-9-]+:\n/);
  const body = endMatch ? rest.slice(0, endMatch.index) : rest;
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

/**
 * Fake ssh modes (FAKE_SSH_MODE) — runner-side fixtures only (REMOTE stdin discarded):
 * - ok: emit valid phase_a_receipt matching BUG_ID + PUBLIC_REPLY_B64 sha
 * - no_receipt | php_fail_exit0: synthetic exit 0 + error text, no receipt
 *   (tests runner fail-closed when ssh returns 0 without receipt; not a claim
 *   that real PsySH swallows exit(1) — live tinker asserts exit=1 on PHASE_A_FAIL)
 * - malformed: exit 0 garbage JSON
 * - wrong_hash / wrong_bug: exit 0 receipt that fails runner checks
 * - ssh255 / failAfter: transport failure
 */
function setupHarness({ mode = 'ok', failAfter = 0 } = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'phase-a-write-'));
  const bindir = path.join(dir, 'bin');
  fs.mkdirSync(bindir);
  const sshLog = path.join(dir, 'ssh.log');
  const sshScript = `#!/bin/bash
set +e
echo "ssh_call argv=$*" >> "${sshLog}"
n=$(wc -l < "${sshLog}" | tr -d ' ')
MODE="\${FAKE_SSH_MODE:-ok}"
if [[ "${failAfter}" -gt 0 && "$n" -gt "${failAfter}" ]]; then
  echo "fake-ssh fail-after" >&2
  exit 255
fi
# Parse BUG_ID and PUBLIC_REPLY_B64 from the remote command string.
CMD="$*"
BUG_ID="$(sed -n "s/.*BUG_ID='\\([0-9]*\\)'.*/\\1/p" <<<"$CMD")"
ISSUE="$(sed -n "s/.*GITHUB_ISSUE_URL='\\([^']*\\)'.*/\\1/p" <<<"$CMD")"
B64="$(sed -n "s/.*PUBLIC_REPLY_B64='\\([^']*\\)'.*/\\1/p" <<<"$CMD")"
# Decode to a file — $(base64 -d) would strip a trailing newline from the reply body.
BODY_FILE="${sshLog}.body"
printf '%s' "$B64" | base64 -d > "$BODY_FILE" 2>/dev/null || : > "$BODY_FILE"
RSHA="$(sha256sum "$BODY_FILE" | cut -d' ' -f1)"
# Discard REMOTE heredoc stdin — fake ssh never runs /tmp/phase-a-remote.sh.
# Therefore this suite proves runner gates only, not REMOTE execution.
cat >/dev/null

case "$MODE" in
  ssh255)
    echo "fake-ssh exit 255" >&2
    exit 255
    ;;
  no_receipt|php_fail_exit0)
    # Runner fixture: ssh exits 0 with no receipt (+ QueryException-shaped noise).
    # Not evidence of live PsySH exit behavior (see BugPhaseATinkerIntegrationTest).
    echo "PHASE_A_FAIL code=db_query_failed" >&2
    echo 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry SENTINEL_SQL_LEAK for key PRIMARY (SQL: insert into \`BugReportComment\` (\`body\`) values (SENTINEL_REPLY_LEAK))' >&2
    exit 0
    ;;
  leak_ssh_fail)
    echo 'Illuminate\\\\Database\\\\QueryException: SQLSTATE[42S02] SENTINEL_SQL_LEAK bindings:[SENTINEL_REPLY_LEAK]' >&2
    echo "PHASE_A_FAIL code=db_query_failed" >&2
    exit 255
    ;;
  malformed)
    echo 'not-json-at-all SENTINEL_SQL_LEAK SENTINEL_REPLY_LEAK'
    exit 0
    ;;
  wrong_hash)
    printf '%s\\n' "{\\"phase_a_receipt\\":1,\\"bug_id\\":$BUG_ID,\\"comment_id\\":99,\\"final\\":\\"triaged\\",\\"public_body_sha256\\":\\"deadbeef\\",\\"issue\\":\\"$ISSUE\\"}"
    exit 0
    ;;
  wrong_bug)
    printf '%s\\n' "{\\"phase_a_receipt\\":1,\\"bug_id\\":999999,\\"comment_id\\":99,\\"final\\":\\"triaged\\",\\"public_body_sha256\\":\\"$RSHA\\",\\"issue\\":\\"$ISSUE\\"}"
    exit 0
    ;;
  wrong_issue)
    printf '%s\\n' "{\\"phase_a_receipt\\":1,\\"bug_id\\":$BUG_ID,\\"comment_id\\":99,\\"final\\":\\"triaged\\",\\"public_body_sha256\\":\\"$RSHA\\",\\"issue\\":\\"https://github.com/other-org/OtherRepo/issues/999\\"}"
    exit 0
    ;;
  trailing_garbage)
    CID=$((1000 + BUG_ID))
    printf '%s\\n' "{\\"phase_a_receipt\\":1,\\"bug_id\\":$BUG_ID,\\"comment_id\\":$CID,\\"comment_skipped\\":false,\\"final\\":\\"triaged\\",\\"public_body_sha256\\":\\"$RSHA\\",\\"issue\\":\\"$ISSUE\\"}"
    echo 'trailing SENTINEL_SQL_LEAK garbage after valid receipt'
    exit 0
    ;;
  ok|*)
    CID=$((1000 + BUG_ID))
    printf '%s\\n' "{\\"phase_a_receipt\\":1,\\"bug_id\\":$BUG_ID,\\"comment_id\\":$CID,\\"comment_skipped\\":false,\\"final\\":\\"triaged\\",\\"public_body_sha256\\":\\"$RSHA\\",\\"issue\\":\\"$ISSUE\\"}"
    exit 0
    ;;
esac
`;
  fs.writeFileSync(path.join(bindir, 'ssh'), sshScript, { mode: 0o755 });
  const home = path.join(dir, 'home');
  fs.mkdirSync(path.join(home, '.ssh'), { recursive: true });
  fs.writeFileSync(path.join(home, '.ssh', 'deploy_key'), 'synthetic');
  fs.writeFileSync(path.join(home, '.ssh', 'known_hosts'), 'synthetic');
  return { dir, bindir, sshLog, home };
}

function runWriteShell({ event, mode = 'ok', failAfter = 0 }) {
  const { dir, bindir, sshLog, home } = setupHarness({ mode, failAfter });
  const eventPath = path.join(dir, 'event.json');
  fs.writeFileSync(eventPath, JSON.stringify({ inputs: event }));
  const shell = extractWriteShell();
  const isolated = shell
    .replaceAll('/tmp/phase-a-remote.sh', `${dir}/phase-a-remote.sh`)
    .replaceAll('/tmp/phase-a-batch-plan.json', `${dir}/phase-a-batch-plan.json`)
    .replaceAll('/tmp/phase-a-receipt-', `${dir}/phase-a-receipt-`);
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
      FAKE_SSH_MODE: mode === 'ssh255' ? 'ssh255' : mode,
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

test('extracted write shell gates success on receipt file + runner validation', () => {
  const shell = extractWriteShell();
  // Static YAML inspection only — does not execute REMOTE (fake ssh discards stdin).
  assert.match(shell, /RECEIPT_FILE=/, 'YAML REMOTE must use receipt file');
  assert.match(shell, /PHASE_A_FAIL code=missing_success_receipt/, 'YAML REMOTE emits missing_success_receipt');
  assert.match(shell, /phase_a_receipt/, 'receipt schema includes phase_a_receipt');
  assert.match(shell, /hash_equals\(\$replySha, hash\("sha256"/, 'dedup compares sha256 bytes, not unicode_ci =');
  assert.match(shell, /findPublicByHash|hash\("sha256", \(string\)\$row->body\)/, 'retry matches comment by hash not latest');
  assert.match(shell, /chunkById\(/, 'exact-hash scan must paginate all public comments');
  assert.doesNotMatch(shell, /->limit\(100\)/, 'must not window to newest 100 (misses older exact hash)');
  assert.match(shell, /PHASE_A_FAIL code=/, 'failures emit fixed codes only');
  assert.match(shell, /phase_a_emit_safe_remote_log/, 'runner must not echo raw tinker/SQL');
  assert.doesNotMatch(shell, /PHASE_A_FAIL "\.\$e->getMessage\(\)/, 'must not print exception message');
  assert.doesNotMatch(shell, /fwrite\(STDERR, "PHASE_A_FAIL "\.\$e->getMessage/, 'must not print exception message');
  assert.match(shell, /DB::commit\(\)/, 'commit before receipt');
  assert.match(shell, /rollBack\(\)/, 'rollback on failure');
  assert.match(shell, /receipt missing\/malformed\/not-unique/, 'runner rejects bad receipts');
  assert.match(shell, /receipt public_body_sha256 mismatch/, 'runner checks body hash');
  assert.match(shell, /jq -ce --slurp/, 'entire stdout must fully parse as one JSON value');
  assert.match(shell, /receipt issue mismatch/, 'runner checks receipt.issue URL');
  assert.doesNotMatch(shell, /jq -c 'select\(type=="object"/, 'must not ignore jq failures via select||true');
});

/**
 * Documents the >100 newest-window bug and the all-scan fix used in the workflow.
 * Newest-100 would miss id=1 when 101 public comments exist; chunked full scan must hit it.
 */
test('>100 public comments: newest-100 misses old exact hash; full scan finds it', () => {
  const rows = [];
  for (let i = 1; i <= 101; i += 1) {
    rows.push({ id: i, body: `public-body-${i}` });
  }
  const target = rows[0];
  const targetSha = sha256(target.body);
  const newest100 = [...rows].sort((a, b) => b.id - a.id).slice(0, 100);
  const limitedHit = newest100.find((r) => sha256(r.body) === targetSha);
  assert.equal(limitedHit, undefined, 'newest-100 must miss the oldest exact hash (regression fixture)');
  const fullHit = rows.find((r) => sha256(r.body) === targetSha);
  assert.equal(fullHit?.id, 1);
  const shell = extractWriteShell();
  assert.match(shell, /chunkById\(/);
  assert.doesNotMatch(shell, /->limit\(100\)/);
});

test('P1: batch ssh255 exits nonzero without false wrote', () => {
  const items = [
    makeItem(411, 4156, 'bug', `第一筆。追蹤：${issue(4156)}`),
    makeItem(415, 4158, 'bug', `第二筆。追蹤：${issue(4158)}`),
  ];
  const r = runWriteShell({ event: { batch_json: JSON.stringify(items) }, mode: 'ssh255' });
  assert.notEqual(r.code, 0);
  assert.doesNotMatch(r.stdout, /wrote bug_id=/);
  assert.doesNotMatch(r.stdout, /batch complete/);
  assert.equal((r.sshLog.match(/ssh_call/g) || []).length, 1);
  fs.rmSync(r.dir, { recursive: true, force: true });
});

test('runner fail-closes when fake-ssh returns exit0 + error text with no receipt', () => {
  const items = [
    makeItem(411, 4156, 'bug', `a：${issue(4156)}`),
    makeItem(415, 4158, 'bug', `b：${issue(4158)}`),
  ];
  const r = runWriteShell({ event: { batch_json: JSON.stringify(items) }, mode: 'php_fail_exit0' });
  assert.notEqual(r.code, 0, 'runner must not treat ssh exit0 without receipt as success');
  assert.match(r.stderr + r.stdout, /PHASE_A_FAIL code=missing_success_receipt|batch fail-stop/);
  assert.doesNotMatch(r.stdout, /wrote bug_id=/);
  assert.equal((r.sshLog.match(/ssh_call/g) || []).length, 1, 'must not continue to second row');
  fs.rmSync(r.dir, { recursive: true, force: true });
});

test('sentinel: SQL/reply bindings never appear in Actions-facing output', () => {
  const reply = `老師您好 SENTINEL_REPLY_LEAK。追蹤：${issue(4156)}`;
  for (const mode of ['php_fail_exit0', 'leak_ssh_fail', 'malformed']) {
    const r = runWriteShell({
      event: {
        bug_id: '411',
        github_issue_url: issue(4156),
        disposition: 'bug',
        public_reply: reply,
        close_as_logged: 'false',
        batch_json: '',
      },
      mode,
    });
    assert.notEqual(r.code, 0, mode);
    const combined = `${r.stdout}\n${r.stderr}`;
    assert.doesNotMatch(combined, /SENTINEL_SQL_LEAK/, mode);
    assert.doesNotMatch(combined, /SENTINEL_REPLY_LEAK/, mode);
    assert.doesNotMatch(combined, /SQLSTATE\[/, mode);
    assert.doesNotMatch(combined, /QueryException/, mode);
    if (mode === 'php_fail_exit0') {
      assert.match(combined, /PHASE_A_FAIL code=(missing_success_receipt|db_query_failed)/);
    }
    fs.rmSync(r.dir, { recursive: true, force: true });
  }
});

test('0-exit missing / malformed / wrong-hash / wrong-bug / wrong-issue / trailing-garbage receipts all fail', () => {
  const item = makeItem(411, 4156, 'bug', `x：${issue(4156)}`);
  for (const mode of ['no_receipt', 'malformed', 'wrong_hash', 'wrong_bug', 'wrong_issue', 'trailing_garbage']) {
    const r = runWriteShell({
      event: {
        bug_id: '411',
        github_issue_url: issue(4156),
        disposition: 'bug',
        public_reply: item.public_reply,
        close_as_logged: 'false',
        batch_json: '',
      },
      mode,
    });
    assert.notEqual(r.code, 0, mode);
    assert.doesNotMatch(r.stdout, /wrote bug_id=411/, mode);
    if (mode === 'trailing_garbage' || mode === 'wrong_issue') {
      assert.match(r.stderr + r.stdout, /receipt (missing\/malformed\/not-unique|issue mismatch)/, mode);
    }
    fs.rmSync(r.dir, { recursive: true, force: true });
  }
});

test('batch middle failure stops; prior valid receipt still counted', () => {
  const items = [
    makeItem(411, 4156, 'bug', `ok1：${issue(4156)}`),
    makeItem(415, 4158, 'bug', `fail：${issue(4158)}`),
    makeItem(416, 4195, 'suggestion', `never：${issue(4195)}`),
  ];
  const r = runWriteShell({
    event: { batch_json: JSON.stringify(items) },
    mode: 'ok',
    failAfter: 1,
  });
  assert.notEqual(r.code, 0);
  assert.match(r.stdout, /wrote bug_id=411/);
  assert.doesNotMatch(r.stdout, /wrote bug_id=415/);
  assert.doesNotMatch(r.stdout, /wrote bug_id=416/);
  assert.doesNotMatch(r.stdout, /batch complete/);
  assert.equal((r.sshLog.match(/ssh_call/g) || []).length, 2);
  fs.rmSync(r.dir, { recursive: true, force: true });
});

test('batch success requires unique valid receipts (Unicode/quotes/newline)', () => {
  const reply1 = `老師您好，「已看過」。\n追蹤：${issue(4156)}\n`;
  const reply2 = `第二筆 emoji 🙂 追蹤：${issue(4158)}`;
  const items = [
    makeItem(411, 4156, 'bug', reply1),
    makeItem(415, 4158, 'bug', reply2),
  ];
  const expectedSha = batchPayloadSha256(items);
  const r = runWriteShell({ event: { batch_json: JSON.stringify(items) }, mode: 'ok' });
  assert.equal(r.code, 0, r.stderr);
  assert.match(r.stdout, new RegExp(`batch_payload_sha256=${expectedSha}`));
  assert.match(r.stdout, /wrote bug_id=411.*comment_id=/);
  assert.match(r.stdout, /wrote bug_id=415/);
  assert.match(r.stdout, new RegExp(`reply_sha256=${sha256(reply1)}`));
  assert.match(r.stdout, /batch complete written=/);
  assert.equal(sha256(reply1), createHash('sha256').update(reply1, 'utf8').digest('hex'));
  fs.rmSync(r.dir, { recursive: true, force: true });
});

test('single-mode success path prints wrote only after valid receipt', () => {
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
    mode: 'ok',
  });
  assert.equal(r.code, 0, r.stderr);
  assert.match(r.stdout, new RegExp(`wrote bug_id=411 reply_sha256=${sha256(reply)} comment_id=1411 final=triaged`));
  fs.rmSync(r.dir, { recursive: true, force: true });
});

test('single-mode ssh255 exits 255 without wrote', () => {
  const reply = `單筆。追蹤：${issue(4156)}`;
  const r = runWriteShell({
    event: {
      bug_id: '411',
      github_issue_url: issue(4156),
      disposition: 'bug',
      public_reply: reply,
      close_as_logged: 'false',
      batch_json: '',
    },
    mode: 'ssh255',
  });
  assert.equal(r.code, 255);
  assert.doesNotMatch(r.stdout, /wrote bug_id=/);
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

test('static: one Founder gate / no-close unchanged', () => {
  const source = fs.readFileSync(WORKFLOW, 'utf8');
  assert.match(source, /environment: production-activation/);
  assert.match(source, /batch mode is triaged-only/);
});
