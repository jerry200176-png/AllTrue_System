// Scheduled repo hygiene (read-mostly REST, no checkout of PR code, no secrets beyond GITHUB_TOKEN).
//   node repo-hygiene.mjs stale        weekly: label inactive owner PRs, report inactive drafts for owner review
//   node repo-hygiene.mjs queue-alarm  hourly: one issue when `queue` is stuck or land-queue keeps failing
// The CLI never closes a PR: owner identity/inactivity does not prove work can be discarded.
// Never deletes a branch (B5): merged-branch leftovers are only reported.
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

const t = (s) => {
  const n = typeof s === 'string' ? Date.parse(s) : NaN;
  return Number.isFinite(n) && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/.test(s)
    && new Date(n).toISOString().slice(0, 19) === s.slice(0, 19) ? n : NaN;
};
export const daysBetween = (now, then) => (now - t(then)) / DAY;

// Legacy "agent PR" scope is the repo owner, shared by agents and the Founder.
// It scopes reminders only, never authorizes discarding work. Other authors are untouched.
export const isAgentPr = (pr, owner) => Boolean(owner) && pr.user?.login === owner && pr.user?.type === 'User';

// lastActivity: ISO time of the newest head commit/comment/review/reopen, excluding our stale comment,
// so our label/comment never resets the clock.
export function decideStale(pr, lastActivity, now, owner, { closeDrafts = false } = {}) {
  if (pr.state !== 'open' || typeof pr.draft !== 'boolean' || !Array.isArray(pr.labels)
      || pr.labels.some((l) => typeof l?.name !== 'string' || !l.name)
      || !Number.isFinite(now) || !Number.isFinite(t(lastActivity))) return { action: 'none' };
  const labels = pr.labels.map((l) => l.name);
  const hasStale = labels.includes(STALE_LABEL);
  if (!isAgentPr(pr, owner) || pr.base?.ref !== 'main' || labels.some((l) => EXEMPT_LABELS.includes(l))) return { action: 'none' };
  const idle = daysBetween(now, lastActivity);
  if (idle < 0) return { action: 'none' };
  // Preserve the original proposal as an explicit opt-in decision helper for tests.
  // The scheduled/dispatch CLI never opts in and has no PR-close implementation.
  if (closeDrafts === true && pr.draft && idle >= CLOSE_DRAFT_DAYS) return { action: 'close', idle };
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
  if (!Array.isArray(runs)) throw new Error('Unknown workflow runs');
  let n = 0;
  for (const r of runs) {
    if (!['completed', 'queued', 'in_progress', 'requested', 'waiting', 'pending'].includes(r?.status)
        || (r.status === 'completed' && !['success', 'failure', 'cancelled', 'skipped', 'neutral', 'timed_out', 'action_required', 'startup_failure', 'stale'].includes(r.conclusion))) throw new Error('Unknown workflow run metadata');
    if (r.status !== 'completed' || ['cancelled', 'skipped', 'neutral'].includes(r.conclusion)) continue;
    if (r.conclusion === 'success') break;
    n += 1;
  }
  return n;
}

export function alarmFindings({ queued, runs, now, hours = QUEUE_HOURS, streak = FAIL_STREAK }) {
  if (!Array.isArray(queued) || !Number.isFinite(now)) throw new Error('Unknown queue metadata');
  const out = [];
  for (const p of queued) {
    if (!Number.isSafeInteger(p.number) || p.number <= 0 || !Number.isFinite(t(p.since)) || t(p.since) > now) throw new Error('Unknown queue age metadata');
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
const pages = (path) => {
  const result = JSON.parse(gh(['api', `/repos/${REPO}/${path}`, '--paginate', '--slurp']));
  if (!Array.isArray(result) || !result.length || result.some((p) => !Array.isArray(p))) throw new Error(`Incomplete pages: ${path}`);
  return result.flat();
};
const send = (method, path, body) => JSON.parse(gh(['api', '-X', method, `/repos/${REPO}/${path}`, '--input', '-'], JSON.stringify(body)) || 'null');

function ensureLabels() {
  const labels = pages('labels?per_page=100');
  if (labels.some((l) => typeof l?.name !== 'string')) throw new Error('Incomplete label metadata');
  for (const [name, color, description] of [
    ['ops-hygiene', '5319e7', 'Repository hygiene reports and queue alarms'],
    [STALE_LABEL, 'ededed', 'Pull request inactive for 14 days'],
  ]) {
    if (labels.some((l) => l.name === name)) continue;
    const label = send('POST', 'labels', { name, color, description });
    if (label?.name !== name) throw new Error(`Label was not created: ${name}`);
  }
}

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
  // The PR commit-list endpoint is capped at 250 commits even when paginated.
  // Read the exact head instead, so commits beyond page one cannot look inactive.
  const sha = pr.head?.sha;
  if (!Number.isSafeInteger(pr.number) || pr.number <= 0 || !/^[a-f0-9]{40}$/i.test(sha || '')) throw new Error('Incomplete PR head metadata');
  const head = api(`commits/${sha}`);
  if (head?.sha !== sha) throw new Error(`Unknown head commit for #${pr.number}`);
  times.push(head.commit?.committer?.date);
  for (const c of pages(`issues/${pr.number}/comments?per_page=100`)) if (!c.body?.includes(MARK_STALE)) times.push(c.created_at);
  for (const c of pages(`pulls/${pr.number}/comments?per_page=100`)) times.push(c.created_at);
  for (const r of pages(`pulls/${pr.number}/reviews?per_page=100`)) times.push(r.submitted_at);
  for (const e of pages(`issues/${pr.number}/events?per_page=100`)) {
    if (typeof e?.event !== 'string' || typeof e.created_at !== 'string' || !Number.isFinite(t(e.created_at))) throw new Error(`Incomplete event metadata for #${pr.number}`);
    if (e.event === 'reopened') times.push(e.created_at);
  }
  if (times.some((s) => typeof s !== 'string' || !Number.isFinite(t(s)))) throw new Error(`Unknown activity timestamp for #${pr.number}`);
  return new Date(Math.max(...times.map(t))).toISOString();
}

function runStale() {
  const owner = REPO.split('/')[0];
  const now = Date.now();
  const labeled = [], deferred = [], cleared = [];
  for (const pr of pages('pulls?state=open&per_page=100')) {
    const d = decideStale(pr, lastActivity(pr), now, owner);
    if (pr.draft === true && d.idle >= CLOSE_DRAFT_DAYS) deferred.push(pr.number);
    if (d.action === 'label') {
      send('POST', `issues/${pr.number}/labels`, { labels: [STALE_LABEL] });
      send('POST', `issues/${pr.number}/comments`, { body: `${MARK_STALE}\nNo activity for ${STALE_DAYS}+ days. Push, comment, reopen or add \`keep-open\` to keep it. This is a reminder only: no PR is automatically closed. Drafts inactive for ${CLOSE_DRAFT_DAYS} days are reported for explicit owner disposition/approval.` });
      labeled.push(pr.number);
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
    `- Owner drafts inactive ${CLOSE_DRAFT_DAYS}d (report only; explicit owner disposition/approval required): ${list(deferred)}`,
    '- Draft auto-close: deferred. Inactivity does not establish that an unresolved request can be discarded.',
    `- Stale label cleared (activity resumed): ${list(cleared)}`,
    '', '### Merged-branch leftovers (report only, never deleted automatically)', '```', leftovers.slice(0, 6000), '```',
  ].join('\n');
  const issue = upsertIssue(MARK_REPORT, '[ops] Weekly repo hygiene report', body);
  console.log(`stale: labeled=${labeled.length} drafts_reported=${deferred.length} cleared=${cleared.length} report=#${issue.number}`);
}

function runQueueAlarm() {
  const now = Date.now();
  const queued = [];
  for (const pr of pages('pulls?state=open&per_page=100')) {
    if (!Number.isSafeInteger(pr.number) || pr.number <= 0 || !Array.isArray(pr.labels)
        || pr.labels.some((l) => typeof l?.name !== 'string' || !l.name)) throw new Error('Unknown queued PR metadata');
    if (!pr.labels.some((l) => l.name === 'queue')) continue;
    const events = pages(`issues/${pr.number}/events?per_page=100`);
    let previous = -Infinity;
    for (const e of events) {
      const at = t(e?.created_at);
      if (typeof e?.event !== 'string' || !e.event || !Number.isFinite(at) || at > now || at < previous
          || (['labeled', 'unlabeled'].includes(e.event) && (typeof e.label?.name !== 'string' || !e.label.name))) throw new Error('Unknown queue event metadata');
      previous = at;
    }
    const since = queuedSince(events);
    if (!since) throw new Error('Unknown queue label history');
    queued.push({ number: pr.number, since });
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
  if (!['stale', 'queue-alarm'].includes(mode)) { console.error('usage: repo-hygiene.mjs stale|queue-alarm'); process.exit(2); }
  ensureLabels();
  if (mode === 'stale') runStale();
  else if (mode === 'queue-alarm') runQueueAlarm();
}

if (import.meta.url === `file://${process.argv[1]}`) main();
