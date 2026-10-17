import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import {
  MAX_BATCH,
  batchPayloadSha256,
  formatPreviewSummary,
  sha256,
  validateBatch,
} from '../ci/bug-phase-a-batch.mjs';

const REPO = 'jerry200176-png/AllTrue_System';
const issue = (n) => `https://github.com/${REPO}/issues/${n}`;
const item = (id, n, disposition = 'bug', reply = `已看過。追蹤：${issue(n)}`) => ({
  bug_id: String(id),
  github_issue_url: issue(n),
  disposition,
  public_reply: reply,
});

test('accepts max-5 batch including suggestion→triaged (no close)', () => {
  const items = [
    item(411, 4156, 'bug'),
    item(415, 4158, 'bug'),
    item(416, 4195, 'suggestion'),
  ];
  const r = validateBatch(JSON.stringify(items), { repo: REPO });
  assert.equal(r.ok, true);
  assert.equal(r.items.length, 3);
  assert.equal(r.items[2].disposition, 'suggestion');
  assert.equal(r.batch_sha256, batchPayloadSha256(items));
});

test('rejects more than MAX_BATCH and close_as_logged in a row', () => {
  const tooMany = Array.from({ length: MAX_BATCH + 1 }, (_, i) => item(100 + i, 4000 + i));
  assert.equal(validateBatch(JSON.stringify(tooMany), { repo: REPO }).ok, false);
  const withClose = [{ ...item(411, 4156), close_as_logged: true }];
  const r = validateBatch(JSON.stringify(withClose), { repo: REPO });
  assert.equal(r.ok, false);
  assert.match(r.errors.join('\n'), /forbids close_as_logged/);
});

test('rejects duplicate ids, foreign issues, and unknown fields', () => {
  assert.match(
    validateBatch(JSON.stringify([item(1, 1), item(1, 2)]), { repo: REPO }).errors.join(),
    /duplicate/,
  );
  assert.match(
    validateBatch(JSON.stringify([{ ...item(1, 1), github_issue_url: 'https://github.com/evil/repo/issues/1' }]), { repo: REPO }).errors.join(),
    /must be an issue/,
  );
  assert.match(
    validateBatch(JSON.stringify([{ ...item(1, 4001), extra: 'nope' }]), { repo: REPO }).errors.join(),
    /unknown field/,
  );
});

test('preview summary is PII-safe (no reply body) and lists hashes', () => {
  const items = [item(416, 4195, 'suggestion', `老師您好，建議…追蹤：${issue(4195)}`)];
  const r = validateBatch(JSON.stringify(items), { repo: REPO });
  const md = formatPreviewSummary(r.items, r.batch_sha256);
  assert.ok(md.includes('batch_payload_sha256'));
  assert.ok(md.includes(r.items[0].reply_sha256));
  assert.ok(!md.includes('老師您好'));
  assert.ok(md.includes('suggestion'));
});

test('canonical hash is order-independent by bug_id', () => {
  const a = [item(416, 4195, 'suggestion'), item(411, 4156)];
  const b = [item(411, 4156), item(416, 4195, 'suggestion')];
  assert.equal(batchPayloadSha256(a), batchPayloadSha256(b));
  assert.equal(sha256('x'), createHash('sha256').update('x', 'utf8').digest('hex'));
});

test('CLI preview writes summary without reply text', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'phase-a-batch-'));
  try {
    const event = path.join(dir, 'event.json');
    const reply = `正式站已調整。追蹤：${issue(4195)}`;
    fs.writeFileSync(event, JSON.stringify({
      inputs: {
        batch_json: JSON.stringify([item(416, 4195, 'suggestion', reply)]),
      },
    }));
    const script = path.join(path.dirname(fileURLToPath(import.meta.url)), '../ci/bug-phase-a-batch.mjs');
    const out = execFileSync('node', [script, 'preview', '--event', event, '--repo', REPO], { encoding: 'utf8' });
    assert.ok(out.includes('batch_payload_sha256'));
    assert.ok(out.includes(sha256(reply)));
    assert.ok(!out.includes('正式站已調整'));
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
