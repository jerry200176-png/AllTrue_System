// Scheduled repo hygiene (read-mostly REST, no checkout of PR code, no secrets beyond GITHUB_TOKEN).
//   node repo-hygiene.mjs stale        weekly: label inactive agent PRs, close inactive agent DRAFTs, report
//   node repo-hygiene.mjs queue-alarm  hourly: one issue when `queue` is stuck or land-queue keeps failing
// Never closes a non-draft PR or a PR by someone other than the repo owner (agents share the owner token,
// RULEBOOK T1). Never deletes a branch (B5): merged-branch leftovers are only reported.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

export const STALE_DAYS = 14;
export const CLOSE_DRAFT_DAYS = 30;
export const QUEUE_HOURS = 2;
export const FAIL_STREAK = 3;
export const STALE_LABEL = 'stale';
export const EXEMPT_LABELS = ['queue', 'hold', 'keep-open', 'do-not-stale', 'founder-hold'];
export const MARK_STALE = '<!-- repo-hygiene:stale -->';
export const MARK_REPORT = '<!-- repo-hygiene:report -->';
export const MARK_ALARM = '<!-- repo-hygiene:queue-alarm -->';
const DAY = 86400e3;

const t = (s) => Date.parse(s);
export const daysBetween = (now, then) => (now - t(then)) / DAY;

// Agents and the Founder share the owner token, so "agent PR" = authored by the repo owner.
// Dependabot and other bots/humans are never touched.
export const isAgentPr = (pr, owner) => pr.user?.login === owner && pr.user?.type !== 'Bot';

// lastActivity: ISO time of the newest commit/comment/review that is NOT our own stale comment,
// so our label/comment never resets the clock.
export function decideStale(pr, lastActivity, now, owner) {
  const labels = (pr.labels || []).map((l) => l.name);
  const hasStale = labels.includes(STALE_LABEL);
  if (!isAgentPr(pr, owner) || pr.base?.ref !== 'main' || labels.some((l) => EXEMPT_LABELS.includes(l))) return { action: 'none' };
  const idle = daysBetween(now, lastActivity);
  if (pr.draft && idle >= CLOSE_DRAFT_DAYS) return { action: 'close', idle };
  if (idle >= STALE_DAYS) return hasStale ? { action: 'none', idle } : { action: 'label', idle };
  return hasStale ? { action: 'unlabel', idle } : { action: 'none', idle };
}

// events: [{event:'labeled'|'unlabeled', label:{name}, created_at}] for one PR.
export function queuedSince(events) {
  let since = null;
  for (const e of events) {
    if (e.label?.name !== 'queue') continue;
    since = e.event === 'labeled' ? e.created_at : e.event === 'unlabeled' ? null : since;
  }
  return since;
}

// runs: land-queue workflow runs, newest first. cancelled/skipped runs say nothing about health.
export function failStreak(runs) {
  let n = 0;
  for (const r of runs) {
    if (r.status !== 'completed' || ['cancelled', 'skipped', 'neutral'].includes(r.conclusion)) continue;
    if (r.conclusion === 'success') break;
    n += 1;
  }
  return n;
}

export function alarmFindings({ queued, runs, now, hours = QUEUE_HOURS, streak = FAIL_STREAK }) {
  const out = [];
  for (const p of queued) {
    const h = (now - t(p.since)) / 3600e3;
    if (h > hours) out.push(`PR #${p.number} has carried \`queue\` for ${h.toFixed(1)}h (limit ${hours}h)`);
  }
  const s = failStreak(runs);
  if (s >= streak) out.push(`land-queue workflow failed ${s} completed runs in a row`);
  return out;
}

// ---- GitHub I/O -------------------------------------------------------------
const REPO = process.env.GITHUB_REPOSITORY;
const gh = (args, input) => execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 64 << 20, input });
const api = (path, ...extra) => JSON.parse(gh(['api', `/repos/${REPO}/${path}`, ...extra]) || 'null');
const pages = (path) => JSON.parse(gh(['api', `/repos/${REPO}/${path}`, '--paginate', '--slurp'])).flat();
const send = (method, path, body) => JSON.parse(gh(['api', '-X', method, `/repos/${REPO}/${path}`, '--input', '-'], JSON.stringify(body)) || 'null');

function findIssue(mark) {
  return pages('issues?state=open&per_page=100&labels=ops-hygiene').find((i) => !i.pull_request && i.body?.includes(mark));
}
function upsertIssue(mark, title, body) {
  const full = `${body}\n\n${mark}`;
  const cur = findIssue(mark);
  if (cur) return send('PATCH', `issues/${cur.number}`, { body: full, title });
  return send('POST', 'issues', { title, body: full, labels: ['ops-hygiene'] });
}
function closeIssue(mark, note) {
  const cur = findIssue(mark);
  if (!cur) return;
  send('POST', `issues/${cur.number}/comments`, { body: note });
  send('PATCH', `issues/${cur.number}`, { state: 'closed', state_reason: 'completed' });
}

function lastActivity(pr) {
  const times = [pr.created_at];
  const commits = api(`pulls/${pr.number}/commits?per_page=100`);
  if (commits.length) times.push(commits[commits.length - 1].commit.committer.date);
  for (const c of pages(`issues/${pr.number}/comments?per_page=100`)) if (!c.body?.includes(MARK_STALE)) times.push(c.created_at);
  for (const c of pages(`pulls/${pr.number}/comments?per_page=100`)) times.push(c.created_at);
  for (const r of pages(`pulls/${pr.number}/reviews?per_page=100`)) if (r.submitted_at) times.push(r.submitted_at);
  return times.sort().pop();
}

function runStale() {
  const owner = REPO.split('/')[0];
  const now = Date.now();
  const labeled = [], closed = [], cleared = [];
  for (const pr of pages('pulls?state=open&per_page=100')) {
    const d = decideStale(pr, lastActivity(pr), now, owner);
    if (d.action === 'label') {
      send('POST', `issues/${pr.number}/labels`, { labels: [STALE_LABEL] });
      send('POST', `issues/${pr.number}/comments`, { body: `${MARK_STALE}\nNo activity for ${STALE_DAYS}+ days. Push, comment or add \`keep-open\` to keep it. Draft PRs with no activity for ${CLOSE_DRAFT_DAYS} days are closed automatically (reopen any time); non-draft PRs are never auto-closed.` });
      labeled.push(pr.number);
    } else if (d.action === 'close') {
      send('POST', `issues/${pr.number}/comments`, { body: `${MARK_STALE}\nClosing this draft: no activity for ${CLOSE_DRAFT_DAYS}+ days. Reopen it if the work is still wanted.` });
      send('PATCH', `pulls/${pr.number}`, { state: 'closed' });
      closed.push(pr.number);
    } else if (d.action === 'unlabel') {
      try { send('DELETE', `issues/${pr.number}/labels/${STALE_LABEL}`); } catch { /* already gone */ }
      cleared.push(pr.number);
    }
  }
  const leftovers = process.env.HYGIENE_REPORT_FILE ? readFileSync(process.env.HYGIENE_REPORT_FILE, 'utf8').trim() : '(branch-hygiene report not provided)';
  const list = (a) => (a.length ? a.map((n) => `#${n}`).join(', ') : 'none');
  const body = [
    `## Weekly hygiene report (${new Date(now).toISOString().slice(0, 10)})`,
    `- Labeled \`${STALE_LABEL}\` (inactive ${STALE_DAYS}d): ${list(labeled)}`,
    `- Closed agent drafts (inactive ${CLOSE_DRAFT_DAYS}d): ${list(closed)}`,
    `- Stale label cleared (activity resumed): ${list(cleared)}`,
    '', '### Merged-branch leftovers (report only, never deleted automatically)', '```', leftovers.slice(0, 6000), '```',
  ].join('\n');
  const issue = upsertIssue(MARK_REPORT, '[ops] Weekly repo hygiene report', body);
  console.log(`stale: labeled=${labeled.length} closed=${closed.length} cleared=${cleared.length} report=#${issue.number}`);
}

function runQueueAlarm() {
  const now = Date.now();
  const queued = [];
  for (const pr of pages('pulls?state=open&per_page=100')) {
    if (!(pr.labels || []).some((l) => l.name === 'queue')) continue;
    const since = queuedSince(pages(`issues/${pr.number}/events?per_page=100`));
    if (since) queued.push({ number: pr.number, since });
  }
  const runs = api('actions/workflows/land-queue.yml/runs?per_page=30').workflow_runs;
  const findings = alarmFindings({ queued, runs, now });
  if (findings.length) {
    const body = `## Land queue alarm\n${findings.map((f) => `- ${f}`).join('\n')}\n\nChecked ${new Date(now).toISOString()}. Check the queue comment on the PR and the latest land-queue run; fix the cause, this issue closes itself when clear.`;
    const issue = upsertIssue(MARK_ALARM, '[ops] Land queue alarm', body);
    console.log(`queue-alarm: ${findings.length} finding(s), issue #${issue.number}`);
  } else {
    closeIssue(MARK_ALARM, 'Queue healthy again; closing.');
    console.log('queue-alarm: clear');
  }
}

function main() {
  const mode = process.argv[2];
  if (mode === 'stale') runStale();
  else if (mode === 'queue-alarm') runQueueAlarm();
  else { console.error('usage: repo-hygiene.mjs stale|queue-alarm'); process.exit(2); }
}

if (import.meta.url === `file://${process.argv[1]}`) main();
