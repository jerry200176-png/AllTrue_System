#!/usr/bin/env node
// Validation for the push-triggered Phase-A request path (.github/workflows/bug-phase-a-request.yml).
// Pure checks so they can be unit tested (scripts/tests/bug-phase-a-request.test.mjs). The Pi write
// itself is not re-implemented here: `remote` extracts the existing bug-phase-a-triage.yml script.
// Everything printed is IDs, counts and hashes only (public repo, #3605).
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import { findUserFacingCopyIssues } from '../lib/userFacingCopyGate.mjs';

export const REQUEST_PATH = 'operations/closeout/bug-phase-a-triage.request.md';
export const MAX_ENTRIES = 5;
export const MAX_REPLY_CHARS = 1000;
// BugReportService::DISPOSITION_KINDS minus LOGGED_CLOSE_KINDS: suggestion-type reports must use
// close_as_logged (F12), which this path does not offer.
export const ALLOWED_DISPOSITIONS = Object.freeze(['bug', 'already_solved', 'needs_info']);
const KEYS = ['bug_id', 'github_issue_url', 'disposition', 'public_reply'];
// Gate patterns written for release-note cards that do not fit a reply (counts like "2 張").
const IGNORED_GATE_IDS = new Set(['truncated_leading', 'empty_praise']);
// docs/CHAT_BUG_SYSTEM.md §3.8 forbidden words not already covered by the shared copy gate.
const SECTION_3_8 = [
  /rate_unit|VoidedAt|ClassSession\.Status|StudentClass|\bschedules\b/i,
  /\bSELECT\b|\bLaravel\b|\bmigration\b|\bCI\b|deploy\.yml|\bPhase\s*[ABC]\b/i,
  /\b[ABRP]\d{1,3}\b/,
];
const PII = [
  { id: 'phone', re: /(?<!\d)(?:\+?886[-\s]?|0)9\d{2}[-\s]?\d{3}[-\s]?\d{3}(?!\d)|(?<!\d)0\d{1,2}[-\s)]\d{3,4}[-\s]?\d{4}(?!\d)/ },
  { id: 'email', re: /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/ },
  { id: 'national_id', re: /\b[A-Z][12]\d{8}\b/ },
  { id: 'long_number', re: /\d{8,}/ },
];

export const sha256 = (text) => createHash('sha256').update(text, 'utf8').digest('hex');

/** Parses `key: value` blocks; every entry starts at `bug_id:`. Unknown lines fail closed. */
export function parseRequest(text) {
  const entries = [];
  const errors = [];
  let current = null;
  text.split('\n').forEach((raw, i) => {
    const line = raw.replace(/\r$/, '');
    if (line.trim() === '' || line.startsWith('#')) return;
    const m = line.match(/^([a-z_]+):[ \t]?(.*)$/);
    if (!m || !KEYS.includes(m[1])) {
      errors.push(`line ${i + 1}: unknown or malformed line`);
      return;
    }
    if (m[1] === 'bug_id') entries.push((current = {}));
    if (!current) return void errors.push(`line ${i + 1}: ${m[1]} before bug_id`);
    if (m[1] in current) return void errors.push(`line ${i + 1}: duplicate ${m[1]}`);
    current[m[1]] = m[2].trim();
  });
  return { entries, errors };
}

export function issueNumber(url, repo) {
  const prefix = `https://github.com/${repo}/issues/`;
  if (!url.startsWith(prefix)) return null;
  const n = url.slice(prefix.length);
  return /^[1-9][0-9]*$/.test(n) ? Number(n) : null;
}

export function issueReferencesBug(issue, bugId) {
  if (!issue || issue.is_pull_request) return false;
  const ref = new RegExp(`(?:in-app\\s*#|alltrue:bug_report:)${bugId}(?!\\d)`);
  return ref.test(issue.title || '') || ref.test(issue.body || '');
}

/** Problems with a public reply (§3.8 plain language, issue link, no PII). Returns rule ids only. */
export function replyProblems(reply, { bugId, issueUrl }) {
  const problems = [];
  if (!reply) return ['empty'];
  if (Array.from(reply).length > MAX_REPLY_CHARS) problems.push('too_long');
  if (!new RegExp(`${issueUrl.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}(?!\\d)`).test(reply)) problems.push('missing_issue_url');
  const rest = reply.split(issueUrl).join(' ');
  if (/https?:\/\//i.test(rest)) problems.push('other_url');
  for (const p of PII) if (p.re.test(rest)) problems.push(`pii_${p.id}`);
  const ownRef = new RegExp(`(?:in-app\\s*)?#${bugId}(?!\\d)`, 'g');
  for (const hit of findUserFacingCopyIssues(rest.replace(ownRef, ' '))) {
    if (!IGNORED_GATE_IDS.has(hit.id)) problems.push(`plain_language_${hit.id}`);
  }
  if (SECTION_3_8.some((re) => re.test(rest))) problems.push('plain_language_3_8');
  return [...new Set(problems)];
}

/** Full offline + issue validation. `issues` maps issue number -> {title, body, is_pull_request}. */
export function validateRequest(text, { repo, issues = {} }) {
  const { entries, errors } = parseRequest(text);
  if (entries.length > MAX_ENTRIES) errors.push(`too many entries: ${entries.length} > ${MAX_ENTRIES}`);
  const seen = new Set();
  const plan = [];
  entries.forEach((e, idx) => {
    const tag = `entry ${idx + 1}`;
    const missing = KEYS.filter((k) => !e[k]);
    if (missing.length) return void errors.push(`${tag}: missing ${missing.join(', ')}`);
    if (!/^[1-9][0-9]{0,9}$/.test(e.bug_id)) return void errors.push(`${tag}: bug_id must be a positive integer`);
    const bugId = Number(e.bug_id);
    if (seen.has(bugId)) errors.push(`bug ${bugId}: listed twice`);
    seen.add(bugId);
    if (!ALLOWED_DISPOSITIONS.includes(e.disposition)) errors.push(`bug ${bugId}: disposition not allowed on this path`);
    const number = issueNumber(e.github_issue_url, repo);
    if (number === null) return void errors.push(`bug ${bugId}: github_issue_url must be an issue in ${repo}`);
    if (!issueReferencesBug(issues[number], bugId)) errors.push(`bug ${bugId}: issue #${number} missing, a PR, or not referencing in-app #${bugId}`);
    for (const p of replyProblems(e.public_reply, { bugId, issueUrl: e.github_issue_url })) errors.push(`bug ${bugId}: public_reply ${p}`);
    plan.push({ bug_id: bugId, issue_url: e.github_issue_url, issue_number: number, disposition: e.disposition, reply_sha256: sha256(e.public_reply) });
  });
  return { ok: errors.length === 0, errors, plan, request_sha256: sha256(text) };
}

/**
 * Per-bug decision from the read-only probe. `new` → apply. Already triaged with this request's
 * issue on the disposition and this exact reply present → already_applied (no-op). Anything else is
 * refused: the Founder approved a new→triaged move, not a re-disposition.
 */
export function decide(plan, probe) {
  const byId = new Map(probe.map((p) => [Number(p.bug_id), p]));
  const out = { apply: [], already_applied: [], refused: [] };
  for (const { bug_id: id } of plan) {
    const p = byId.get(id);
    if (!p || !p.found) out.refused.push({ bug_id: id, reason: 'not_found' });
    else if (p.status === 'new' && p.url_comment_present && !p.reply_present) out.refused.push({ bug_id: id, reason: 'other_reply_with_issue_url' });
    else if (p.status === 'new') out.apply.push(id);
    else if (p.status === 'triaged' && p.disposition_url && p.reply_present) out.already_applied.push(id);
    else out.refused.push({ bug_id: id, reason: `status_${String(p.status).replace(/[^a-z_]/g, '')}` });
  }
  return out;
}

/** The Pi-side script of bug-phase-a-triage.yml (heredoc body), so the write logic has one source. */
export function extractRemoteScript(workflowText) {
  const m = workflowText.match(/bash -s" <<'REMOTE'\n([\s\S]*?)\n {10}REMOTE\n/);
  if (!m) throw new Error('bug-phase-a-triage.yml REMOTE heredoc not found');
  return m[1].split('\n').map((l) => l.replace(/^ {10}/, '')).join('\n') + '\n';
}

function arg(name) {
  const i = process.argv.indexOf(`--${name}`);
  if (i < 0 || !process.argv[i + 1]) throw new Error(`missing --${name}`);
  return process.argv[i + 1];
}

function main(cmd) {
  const read = (p) => fs.readFileSync(p, 'utf8');
  if (cmd === 'issues') {
    for (const e of parseRequest(read(arg('request'))).entries) {
      const n = issueNumber(e.github_issue_url || '', arg('repo'));
      if (n !== null) console.log(n);
    }
  } else if (cmd === 'validate') {
    const result = validateRequest(read(arg('request')), { repo: arg('repo'), issues: JSON.parse(read(arg('issues'))) });
    for (const e of result.errors) console.error(`::error::${e}`);
    fs.writeFileSync(arg('out'), JSON.stringify(result));
    console.log(`request_sha256=${result.request_sha256} entries=${result.plan.length} ids=${result.plan.map((p) => p.bug_id).join(',')}`);
    if (!result.ok) process.exit(1);
  } else if (cmd === 'decide') {
    const decision = decide(JSON.parse(read(arg('plan'))).plan, JSON.parse(read(arg('probe'))));
    console.log(JSON.stringify(decision));
    if (decision.refused.length) process.exit(1);
  } else if (cmd === 'reply') {
    const id = arg('bug-id');
    const e = parseRequest(read(arg('request'))).entries.find((x) => x.bug_id === id);
    if (!e) throw new Error(`bug ${id} not in request`);
    process.stdout.write(Buffer.from(e.public_reply, 'utf8').toString('base64'));
  } else if (cmd === 'remote') {
    fs.writeFileSync(arg('out'), extractRemoteScript(read(arg('workflow'))));
  } else {
    throw new Error('usage: bug-phase-a-request.mjs issues|validate|decide|reply|remote ...');
  }
}

if (import.meta.url === `file://${process.argv[1]}`) main(process.argv[2]);
