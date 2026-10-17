import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {
  ALLOWED_DISPOSITIONS, MAX_ENTRIES, REQUEST_PATH, decide, extractRemoteScript, parseRequest, replyProblems, validateRequest,
} from '../ci/bug-phase-a-request.mjs';
import { checkJobEnvironments } from '../ci/production-ssh-environment.mjs';
import { unsafeRefTriggers } from '../ci/production-secret-triggers.mjs';

const REPO = 'jerry200176-png/AllTrue_System';
const url = (n) => `https://github.com/${REPO}/issues/${n}`;
const REPLY = (n) => `已看過您的截圖，原因是同一天有兩堂課重疊，我們會修正顯示方式，修好後會在這裡通知您。追蹤：${url(n)}`;
const block = (id, n = 4000 + id, reply = REPLY(n), disposition = 'bug') =>
  `bug_id: ${id}\ngithub_issue_url: ${url(n)}\ndisposition: ${disposition}\npublic_reply: ${reply}\n`;
const issuesFor = (...ids) => Object.fromEntries(ids.map((id) => [4000 + id, { title: `[in-app #${id}][auto-intake] report`, body: '', is_pull_request: false }]));
const validate = (text, issues) => validateRequest(text, { repo: REPO, issues });

test('a valid request yields a plan without reply text', () => {
  const r = validate(`# header\n${block(12)}\n${block(13)}`, issuesFor(12, 13));
  assert.deepEqual(r.errors, []);
  assert.deepEqual(r.plan.map((p) => p.bug_id), [12, 13]);
  assert.equal(r.request_sha256.length, 64);
  assert.ok(!JSON.stringify(r.plan).includes('原因'), 'plan must not carry reply text');
});

test('the committed request template is a no-op', () => {
  const r = validate(fs.readFileSync(REQUEST_PATH, 'utf8'), {});
  assert.deepEqual([r.ok, r.plan.length], [true, 0]);
});

test('rejects non-integer, duplicate and unknown lines', () => {
  assert.match(validate(block('12a'), {}).errors.join(), /positive integer/);
  assert.match(validate(block('0'), {}).errors.join(), /positive integer/);
  assert.match(validate(block(12) + block(12), issuesFor(12)).errors.join(), /listed twice/);
  assert.match(validate(`${block(12)}note: hi\n`, issuesFor(12)).errors.join(), /unknown or malformed/);
  assert.match(validate(`bug_id: 12\nbug_id: 13\n`, {}).errors.join(), /missing/);
});

test('rejects more than MAX_ENTRIES reports', () => {
  const ids = Array.from({ length: MAX_ENTRIES + 1 }, (_, i) => i + 1);
  assert.match(validate(ids.map((id) => block(id)).join('\n'), issuesFor(...ids)).errors.join(), /too many entries/);
});

test('rejects issue from another repo, a PR, or an issue not naming the bug', () => {
  const other = `bug_id: 12\ngithub_issue_url: https://github.com/evil/repo/issues/4012\ndisposition: bug\npublic_reply: x https://github.com/evil/repo/issues/4012\n`;
  assert.match(validate(other, issuesFor(12)).errors.join(), /must be an issue in/);
  assert.match(validate(block(12), { 4012: { title: '[in-app #12]', is_pull_request: true } }).errors.join(), /not referencing/);
  assert.match(validate(block(12), { 4012: { title: '[in-app #120]', body: '' } }).errors.join(), /not referencing/);
  assert.match(validate(block(12), {}).errors.join(), /not referencing/);
  assert.deepEqual(validate(block(12), { 4012: { title: 'x', body: 'alltrue:bug_report:12' } }).errors, []);
});

test('rejects suggestion-type dispositions (they use close_as_logged)', () => {
  assert.ok(!ALLOWED_DISPOSITIONS.includes('suggestion'));
  assert.match(validate(block(12, 4012, REPLY(4012), 'suggestion'), issuesFor(12)).errors.join(), /disposition not allowed/);
});

test('disposition allowlist stays inside the service kinds and outside the logged-close kinds', () => {
  const src = fs.readFileSync('backend/app/Services/BugReportService.php', 'utf8');
  const list = (name) => [...src.match(new RegExp(`const ${name} = \\[([\\s\\S]*?)\\];`))[1].matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
  for (const d of ALLOWED_DISPOSITIONS) {
    assert.ok(list('DISPOSITION_KINDS').includes(d), d);
    assert.ok(!list('LOGGED_CLOSE_KINDS').includes(d), d);
  }
});

test('reply rules: issue URL, plain language, PII', () => {
  const ctx = { bugId: 12, issueUrl: url(4012) };
  assert.deepEqual(replyProblems(REPLY(4012), ctx), []);
  assert.deepEqual(replyProblems(`已收到 #12，我們正在查。追蹤：${url(4012)}`, ctx), []);
  assert.ok(replyProblems('已收到，我們正在查。', ctx).includes('missing_issue_url'));
  assert.ok(replyProblems(`見 ${url(40120)}`, ctx).includes('missing_issue_url'));
  assert.ok(replyProblems(`請撥 0912-345-678 ${url(4012)}`, ctx).includes('pii_phone'));
  assert.ok(replyProblems(`請撥 02-2345-6789 ${url(4012)}`, ctx).includes('pii_phone'));
  assert.ok(replyProblems(`寄到 a.b@example.com ${url(4012)}`, ctx).includes('pii_email'));
  assert.ok(replyProblems(`另見 https://example.com ${url(4012)}`, ctx).includes('other_url'));
  assert.ok(replyProblems(`StudentClass 欄位錯了 ${url(4012)}`, ctx).some((p) => p.startsWith('plain_language')));
  assert.ok(replyProblems(`已建 P0，等 CI 跑完 ${url(4012)}`, ctx).includes('plain_language_3_8'));
  assert.ok(replyProblems(`同 PR #3000 ${url(4012)}`, ctx).some((p) => p.startsWith('plain_language')));
  assert.ok(replyProblems(`${'很'.repeat(1001)}${url(4012)}`, ctx).includes('too_long'));
});

const probe = (over) => [{ bug_id: 12, found: true, status: 'new', disposition_url: false, url_comment_present: false, reply_present: false, ...over }];
const plan = [{ bug_id: 12 }];

test('decide: new -> apply; rerun after success -> already_applied (no-op)', () => {
  assert.deepEqual(decide(plan, probe({})), { apply: [12], already_applied: [], refused: [] });
  const done = decide(plan, probe({ status: 'triaged', disposition_url: true, url_comment_present: true, reply_present: true }));
  assert.deepEqual(done, { apply: [], already_applied: [12], refused: [] });
});

test('decide: refuses anything that is not new or exactly this request already applied', () => {
  assert.equal(decide(plan, probe({ status: 'triaged' })).refused[0].reason, 'status_triaged');
  assert.equal(decide(plan, probe({ status: 'triaged', disposition_url: true })).refused[0].reason, 'status_triaged');
  assert.equal(decide(plan, probe({ status: 'resolved' })).refused[0].reason, 'status_resolved');
  assert.equal(decide(plan, [{ bug_id: 12, found: false }]).refused[0].reason, 'not_found');
  assert.equal(decide(plan, []).refused[0].reason, 'not_found');
  assert.equal(decide(plan, probe({ url_comment_present: true })).refused[0].reason, 'other_reply_with_issue_url');
});

test('reuses the bug-phase-a-triage.yml Pi script (one write path)', () => {
  const remote = extractRemoteScript(fs.readFileSync('.github/workflows/bug-phase-a-triage.yml', 'utf8'));
  for (const marker of ['cd /home/admin/backend', 'lockForUpdate()', '$svc::changeStatus($bugId, $actor, "triaged"',
    '$svc::normalizeDispositionOptions(', '->where("body", "like", "%".$issueUrl."%")->exists()', 'PUBLIC_REPLY_B64" | base64 -d']) {
    assert.ok(remote.includes(marker), marker);
  }
  for (const v of ['BUG_ID', 'GITHUB_ISSUE_URL', 'DISPOSITION', 'CLOSE_AS_LOGGED', 'PUBLIC_REPLY_B64']) {
    assert.match(fs.readFileSync('.github/workflows/bug-phase-a-request.yml', 'utf8'), new RegExp(`${v}='(?:\\$|false')`), v);
  }
  assert.ok(!/^ {10}/m.test(remote), 'remote script must be dedented');
});

test('workflow structure: push to main on the request path, kill switch, approval-gated write', () => {
  const wf = fs.readFileSync('.github/workflows/bug-phase-a-request.yml', 'utf8');
  const on = wf.match(/\non:\n([\s\S]*?)\n\S/)[1];
  assert.equal(on.trim(), `push:\n    branches: [main]\n    paths:\n      - '${REQUEST_PATH}'`);
  assert.deepEqual(unsafeRefTriggers(wf), []);
  assert.deepEqual(checkJobEnvironments(wf), []);
  const job = (id) => wf.match(new RegExp(`\\n  ${id}:\\n([\\s\\S]*?)(?=\\n  [a-z-]+:\\n|$)`))[1];
  assert.match(job('gate'), /PHASE_A_REQUEST_ENABLED: \$\{\{ vars\.PHASE_A_REQUEST_ENABLED \}\}/);
  assert.match(job('gate'), /if \[\[ "\$PHASE_A_REQUEST_ENABLED" == "true" \]\]/);
  assert.ok(!/secrets\./.test(job('gate') + job('validate')), 'no secrets before the kill switch and validation');
  assert.match(job('validate'), /if: needs\.gate\.outputs\.enabled == 'true'/);
  assert.match(job('preflight'), /needs: validate\n/);
  assert.match(job('preflight'), /environment: production-ssh\n/);
  assert.ok(!/remote\.sh|bug-phase-a-triage\.yml/.test(job('preflight')), 'preflight must stay read-only');
  const write = job('write');
  assert.match(write, /environment: production-activation\n/);
  assert.match(write, /needs: \[validate, preflight\]/);
  assert.match(write, /sha256sum "\$REQ".*== "\$APPROVED_SHA256"/);
  assert.match(write, /\[\[ "\$NOW" == "\$APPROVED_APPLY" \]\]/);
  assert.match(write, /CLOSE_AS_LOGGED='false'/);
  assert.equal((wf.match(/remote\.sh/g) || []).length, 2, 'only the approved write job runs the Pi write script');
  assert.match(job('record'), /if: always\(\) && needs\.gate\.outputs\.enabled == 'true'/);
  assert.match(job('record'), /gh issue comment/);
});

test('parseRequest ignores comments and keeps reply verbatim', () => {
  const { entries, errors } = parseRequest(`# x\n${block(7)}`);
  assert.deepEqual(errors, []);
  assert.equal(entries[0].public_reply, REPLY(4007));
});
