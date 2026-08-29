import { fileURLToPath } from 'node:url';

import { findUserFacingCopyIssues } from '../lib/userFacingCopyGate.mjs';

/**
 * Validate text that is about to be posted to an in-app bug report.
 *
 * The URL/bug-id checks stay in the workflow because they are write-back
 * inputs. This adapter owns the shared public-copy policy so the workflow
 * cannot accidentally bypass the maintained checker.
 *
 * @param {string} text
 * @returns {{ id: string, hint: string }[]}
 */
export function validatePublicBugReply(text) {
  return findUserFacingCopyIssues(text).map(({ id, hint }) => ({ id, hint }));
}

function main() {
  const issues = validatePublicBugReply(process.env.PUBLIC_REPLY || '');
  if (!issues.length) {
    console.log('public bug reply copy gate: ok');
    return;
  }

  console.error('public bug reply copy gate: failed');
  for (const issue of issues) {
    // Do not echo the submitted reply or matched text into Actions logs.
    console.error(`- ${issue.id}: ${issue.hint}`);
  }
  process.exitCode = 1;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  main();
}
