#!/usr/bin/env node
/**
 * Bounded Phase-A batch helper for bug-phase-a-triage.yml.
 * Max 5 rows; triaged-only (close_as_logged forced false); PII-safe (IDs/hashes only).
 * Does not implement Pi write — the workflow reuses the existing REMOTE script per row.
 */
import { createHash } from 'node:crypto';
import fs from 'node:fs';

export const MAX_BATCH = 5;
export const MAX_REPLY_CHARS = 2000;

/** Canonical row line used to bind Founder approval to the exact batch payload. */
export function rowCanonical({ bug_id, github_issue_url, disposition, public_reply }) {
  const replySha = sha256(public_reply);
  return `${bug_id}|${github_issue_url}|${disposition}|${replySha}|close_as_logged=false`;
}

export function sha256(text) {
  return createHash('sha256').update(text, 'utf8').digest('hex');
}

export function batchPayloadSha256(items) {
  // Stable order by numeric bug_id so equivalent sets hash the same.
  const lines = [...items]
    .map((it) => rowCanonical(it))
    .sort((a, b) => Number(a.split('|')[0]) - Number(b.split('|')[0]));
  return sha256(lines.join('\n'));
}

export function issueInRepo(url, repo) {
  const prefix = `https://github.com/${repo}/issues/`;
  if (!url.startsWith(prefix)) return false;
  return /^[1-9][0-9]*$/.test(url.slice(prefix.length));
}

/**
 * @param {unknown} raw
 * @param {{ repo: string }} opts
 * @returns {{ ok: true, items: object[], batch_sha256: string } | { ok: false, errors: string[] }}
 */
export function validateBatch(raw, { repo }) {
  const errors = [];
  let parsed;
  try {
    parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
  } catch {
    return { ok: false, errors: ['batch_json is not valid JSON'] };
  }
  if (!Array.isArray(parsed)) return { ok: false, errors: ['batch_json must be a JSON array'] };
  if (parsed.length < 1) return { ok: false, errors: ['batch_json must contain at least 1 item'] };
  if (parsed.length > MAX_BATCH) return { ok: false, errors: [`batch_json exceeds max ${MAX_BATCH}`] };

  const seen = new Set();
  const items = [];
  parsed.forEach((row, idx) => {
    const tag = `item[${idx}]`;
    if (!row || typeof row !== 'object' || Array.isArray(row)) {
      errors.push(`${tag}: must be an object`);
      return;
    }
    const bug_id = String(row.bug_id ?? '').trim();
    const github_issue_url = String(row.github_issue_url ?? '').trim();
    const disposition = String(row.disposition ?? '').trim();
    const public_reply = typeof row.public_reply === 'string' ? row.public_reply : '';
    if ('close_as_logged' in row && row.close_as_logged !== false && row.close_as_logged !== 'false') {
      errors.push(`${tag}: batch mode forbids close_as_logged (triaged only)`);
    }
    if (!/^[1-9][0-9]{0,9}$/.test(bug_id)) errors.push(`${tag}: invalid bug_id`);
    else if (seen.has(bug_id)) errors.push(`${tag}: duplicate bug_id ${bug_id}`);
    else seen.add(bug_id);
    if (!issueInRepo(github_issue_url, repo)) errors.push(`${tag}: github_issue_url must be an issue in ${repo}`);
    if (!/^[a-z_]+$/.test(disposition)) errors.push(`${tag}: invalid disposition syntax`);
    if (!public_reply) errors.push(`${tag}: public_reply required`);
    else if ([...public_reply].length > MAX_REPLY_CHARS) errors.push(`${tag}: public_reply too long`);
    else if (!public_reply.includes(github_issue_url)) errors.push(`${tag}: public_reply must contain github_issue_url`);
    // Extra keys fail closed so approval binds to a known shape.
    const allowed = new Set(['bug_id', 'github_issue_url', 'disposition', 'public_reply', 'close_as_logged']);
    for (const k of Object.keys(row)) {
      if (!allowed.has(k)) errors.push(`${tag}: unknown field ${k}`);
    }
    items.push({
      bug_id,
      github_issue_url,
      disposition,
      public_reply,
      reply_sha256: public_reply ? sha256(public_reply) : '',
      reply_length: public_reply ? [...public_reply].length : 0,
    });
  });

  if (errors.length) return { ok: false, errors };
  return { ok: true, items, batch_sha256: batchPayloadSha256(items) };
}

/** Markdown summary: IDs / dispositions / lengths / hashes only (no reply body). */
export function formatPreviewSummary(items, batchSha) {
  const lines = [
    '## In-app batch write requested (approve only if this matches the reviewed list)',
    '',
    `- mode: batch`,
    `- count: ${items.length}`,
    `- close_as_logged: false (batch mode: triaged only, no auto-close)`,
    `- batch_payload_sha256: ${batchSha}`,
    '',
    '| bug_id | issue | disposition | reply_len | reply_sha256 |',
    '|---:|---|---|---:|---|',
  ];
  for (const it of items) {
    const issueNo = it.github_issue_url.split('/').pop();
    lines.push(
      `| ${it.bug_id} | #${issueNo} | ${it.disposition} | ${it.reply_length} | \`${it.reply_sha256}\` |`,
    );
  }
  lines.push('', 'Reply text is intentionally not shown (public repo). Compare sha256 rows with the private list.');
  return `${lines.join('\n')}\n`;
}

function arg(name) {
  const i = process.argv.indexOf(`--${name}`);
  if (i < 0 || !process.argv[i + 1]) throw new Error(`missing --${name}`);
  return process.argv[i + 1];
}

function main(cmd) {
  if (cmd === 'preview' || cmd === 'plan') {
    const event = JSON.parse(fs.readFileSync(arg('event'), 'utf8'));
    const repo = arg('repo');
    const raw = event?.inputs?.batch_json;
    const result = validateBatch(raw ?? '', { repo });
    if (!result.ok) {
      for (const e of result.errors) console.error(`::error::${e}`);
      process.exit(1);
    }
    if (cmd === 'preview') {
      process.stdout.write(formatPreviewSummary(result.items, result.batch_sha256));
    } else {
      // plan: machine-readable for the write job (includes reply text only in this private step file)
      const out = arg('out');
      fs.writeFileSync(out, JSON.stringify({
        batch_sha256: result.batch_sha256,
        items: result.items,
      }));
      console.log(`batch_sha256=${result.batch_sha256} count=${result.items.length} ids=${result.items.map((i) => i.bug_id).join(',')}`);
    }
    return;
  }
  throw new Error('usage: bug-phase-a-batch.mjs preview|plan --event <path> --repo <owner/name> [--out plan.json]');
}

if (import.meta.url === `file://${process.argv[1]}`) main(process.argv[2]);
