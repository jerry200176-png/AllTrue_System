import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const wf = readFileSync(new URL('../../.github/workflows/bug-sla-weekly-report.yml', import.meta.url), 'utf8');
const php = wf.slice(wf.indexOf('--execute='), wf.indexOf('REMOTE\n          )"'));

assert.match(wf, /cron: "0 1 \* \* 1"/, 'Monday 09:00 Taipei (01:00 UTC)');
for (const key of ['"new"', '"resolved"', '"closed"', '"reopened"', '"sla_overdue"', '"open_by_age"']) {
  assert.ok(php.includes(key), `snapshot carries weekly ${key}`);
}
// Public repo: the production query may not select any free text or person column.
assert.doesNotMatch(php, /description|"body"|reporter_user_id|->name|email/i, 'snapshot stays IDs-only');
const job = wf.slice(wf.indexOf('\n  issue:'));
assert.match(job, /needs: report/, 'issue job waits for the validated snapshot');
assert.match(job, /issues: write/, 'issue job can write issues');
assert.doesNotMatch(wf.slice(0, wf.indexOf('\n  issue:')), /issues: write/, 'production job has no issue write');
assert.match(job, /--author app\/github-actions/, 'only the bot-authored weekly issue is reused');
assert.match(job, /gh issue edit "\$existing" --body-file/, 'rerun updates instead of duplicating');

assert.match(job, /--owner "\$GITHUB_REPOSITORY_OWNER"/, 'only owner/bot issues are trusted');
console.log('bug-sla-weekly workflow contract: PASS');
