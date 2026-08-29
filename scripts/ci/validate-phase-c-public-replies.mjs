import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

import { findUserFacingCopyIssues } from '../lib/userFacingCopyGate.mjs';

const WORKFLOW = new URL('../../.github/workflows/bug-phase-c-allowlist.yml', import.meta.url);
const REPLY_RE = /^\s*"reply"\s*=>\s*"((?:\\.|[^"\\])*)"\s*,?\s*$/gm;
export const EXPECTED_PHASE_C_REPLY_COUNT = 37;

/**
 * Extract the deliberately static public replies from the Phase-C allowlist.
 * The workflow is PHP embedded in YAML, so keep this parser narrow and fail
 * closed when a reply line is malformed or missing.
 *
 * @param {string} source
 * @returns {{ line: number, text: string }[]}
 */
export function extractPhaseCPublicReplies(source) {
  const replies = [];
  for (const match of String(source).matchAll(REPLY_RE)) {
    const line = (source.slice(0, match.index).match(/\n/g)?.length ?? 0) + 1;
    const text = match[1].replaceAll('\\"', '"').replaceAll('\\\\', '\\');
    replies.push({ line, text });
  }
  return replies;
}

/**
 * @param {string} source
 * @returns {{ replyCount: number, issues: { line: number, id: string, hint: string }[] }}
 */
export function validatePhaseCPublicReplies(source) {
  const replies = extractPhaseCPublicReplies(source);
  const issues = [];
  for (const reply of replies) {
    for (const issue of findUserFacingCopyIssues(reply.text)) {
      issues.push({ line: reply.line, id: issue.id, hint: issue.hint });
    }
  }
  return { replyCount: replies.length, issues };
}

function main() {
  const source = fs.readFileSync(fileURLToPath(WORKFLOW), 'utf8');
  const result = validatePhaseCPublicReplies(source);
  if (result.replyCount !== EXPECTED_PHASE_C_REPLY_COUNT) {
    console.error(`phase-c public reply copy gate: failed (expected ${EXPECTED_PHASE_C_REPLY_COUNT} replies, found ${result.replyCount})`);
    process.exitCode = 1;
    return;
  }
  if (!result.issues.length) {
    console.log(`phase-c public reply copy gate: ok (${result.replyCount} replies)`);
    return;
  }

  console.error(`phase-c public reply copy gate: failed (${result.replyCount} replies)`);
  for (const issue of result.issues) {
    // Do not echo the submitted reply or matched text into Actions logs.
    console.error(`- line ${issue.line}: ${issue.id}: ${issue.hint}`);
  }
  process.exitCode = 1;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  main();
}
