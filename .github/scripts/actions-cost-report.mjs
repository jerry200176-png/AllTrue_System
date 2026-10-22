// Completed attempts for a bounded run-creation cohort, not account billing or token cost.
// Fail before issue writes if capped, missing, or inconsistent API data prevents enumeration.
import { execFileSync } from 'node:child_process';

export const WINDOW_DAYS = 7;
export const RUNS_PER_DAY_FLAG = 48; // land-queue's */5 schedule alone is 288/day
export const MARK = '<!-- actions-cost-report -->';
const PAGE_SIZE = 100;
const SEARCH_CAP = 1000;
const iso = (ms) => new Date(ms).toISOString().replace('.000Z', 'Z');

// GitHub's created search is inclusive and capped at 1,000 even with pagination:
// https://docs.github.com/en/rest/actions/workflow-runs#list-workflow-runs-for-a-repository
// Use disjoint whole-second ranges, splitting capped ranges BEFORE following pages.
export function collectRuns(api, { repo, since, until }) {
  if (!Number.isSafeInteger(since) || !Number.isSafeInteger(until) || since % 1000 || until % 1000 || since >= until) throw new Error('Invalid creation window');
  const runs = new Map();
  function window(start, end) {
    const page = (n) => {
      const query = new URLSearchParams({ created: `${iso(start)}..${iso(end - 1000)}`, per_page: String(PAGE_SIZE), page: String(n) });
      const data = api(`/repos/${repo}/actions/runs?${query}`);
      if (data.incomplete_results || !Number.isSafeInteger(data.total_count) || data.total_count < 0 || !Array.isArray(data.workflow_runs)) throw new Error('Incomplete runs response');
      return data;
    };
    const first = page(1);
    if (first.total_count >= SEARCH_CAP) {
      if (end - start <= 1000) throw new Error(`Runs API cap cannot be split at ${iso(start)}`);
      const middle = start + Math.floor((end - start) / 2000) * 1000;
      window(start, middle);
      window(middle, end);
      return;
    }
    const found = new Map();
    for (let n = 1; n <= Math.max(1, Math.ceil(first.total_count / PAGE_SIZE)); n += 1) {
      const data = n === 1 ? first : page(n);
      const expected = Math.min(PAGE_SIZE, Math.max(0, first.total_count - (n - 1) * PAGE_SIZE));
      if (data.total_count !== first.total_count || data.workflow_runs.length !== expected) throw new Error('Runs pagination changed or is partial');
      for (const run of data.workflow_runs) {
        const created = Date.parse(run.created_at);
        if (!Number.isSafeInteger(run.id) || run.id <= 0 || !Number.isFinite(created) || created < start || created >= end || found.has(run.id) || runs.has(run.id)) throw new Error('Duplicate or out-of-window run');
        found.set(run.id, run);
      }
    }
    if (found.size !== first.total_count) throw new Error('Runs count is incomplete');
    for (const [id, run] of found) runs.set(id, run);
  }
  window(since, until);
  return [...runs.values()];
}

export function collectAttempts(api, { repo, since, until }) {
  const runs = collectRuns(api, { repo, since, until });
  const attempts = [];
  const nonCompleted = {};
  let afterCutoff = 0;
  for (const run of runs) {
    if (typeof run.name !== 'string' || !run.name) throw new Error(`Missing workflow name for run ${run.id}`);
    if (!Number.isSafeInteger(run.run_attempt) || run.run_attempt < 1) throw new Error(`Missing attempt count for run ${run.id}`);
    for (let n = 1; n <= run.run_attempt; n += 1) {
      // The list already contains the latest attempt. Fetch each earlier attempt,
      // retaining its failure/cancellation and elapsed time after a successful retry.
      const attempt = n === run.run_attempt ? run : api(`/repos/${repo}/actions/runs/${run.id}/attempts/${n}`);
      if (attempt.id !== run.id || attempt.run_attempt !== n || typeof attempt.status !== 'string') throw new Error(`Invalid attempt ${run.id}/${n}`);
      if (attempt.status !== 'completed') {
        if (n !== run.run_attempt) throw new Error(`Historical attempt is incomplete: ${run.id}/${n}`);
        nonCompleted[attempt.status] = (nonCompleted[attempt.status] ?? 0) + 1;
        continue;
      }
      const start = Date.parse(attempt.run_started_at);
      const end = Date.parse(attempt.updated_at);
      if (typeof attempt.conclusion !== 'string' || !attempt.conclusion || !Number.isFinite(start) || !Number.isFinite(end) || start < Date.parse(run.created_at) || end < start) throw new Error(`Missing or invalid completed-attempt data: ${run.id}/${n}`);
      if (start >= until || end >= until) { afterCutoff += 1; continue; }
      attempts.push({ ...attempt, name: run.name });
    }
  }
  return { attempts, coverage: { since: iso(since), until: iso(until), runCount: runs.length, nonCompleted, afterCutoff } };
}

export function aggregate(runs, { days = WINDOW_DAYS, flagPerDay = RUNS_PER_DAY_FLAG } = {}) {
  const by = new Map();
  for (const r of runs) {
    const w = by.get(r.name) ?? { name: r.name, runs: 0, minutes: 0, failed: 0, cancelled: 0 };
    w.runs += 1;
    if (r.run_started_at && r.updated_at) w.minutes += Math.max(0, Date.parse(r.updated_at) - Date.parse(r.run_started_at)) / 60000;
    if (r.conclusion === 'failure') w.failed += 1;
    if (r.conclusion === 'cancelled') w.cancelled += 1;
    by.set(r.name, w);
  }
  return [...by.values()]
    .map((w) => ({ ...w, perDay: w.runs / days, flagged: w.runs / days > flagPerDay }))
    .sort((a, b) => b.minutes - a.minutes);
}

export function render(rows, { days = WINDOW_DAYS, flagPerDay = RUNS_PER_DAY_FLAG, date, coverage }) {
  const total = rows.reduce((s, r) => s + r.minutes, 0);
  const lines = [
    MARK,
    `## Actions completed attempts: ${days}-day run-creation cohort (${date})`,
    `Total: ${rows.reduce((s, r) => s + r.runs, 0)} completed attempts, about ${Math.round(total)} wall-clock minutes (approximation: per-attempt updated_at minus run_started_at; includes waiting inside an attempt and possible metadata delay, counts parallel jobs as one elapsed span, excludes queueing before start; not billed minutes or monetary/token cost).`,
    ...(coverage ? [
      `Scope: ${coverage.runCount} runs originally created in [${coverage.since}, ${coverage.until}) UTC; only attempts started and completed before the exclusive cutoff are counted. Whole attempt intervals are summed, without clipping.`,
      `Omitted attempts: ${Object.entries(coverage.nonCompleted).map(([status, count]) => `${count} ${status}`).join(', ') || '0 non-completed'}; ${coverage.afterCutoff} completed at/after the cutoff. Status is observed live during collection, not a historical snapshot.`,
    ] : []),
    'Coverage limit: reruns this week of runs originally created before the cohort are excluded. This is not complete seven-day repository usage; the runs API filters original creation time, not attempt activity.',
    '',
    '| Workflow | Completed attempts | Attempts/day | ~Wall-clock minutes | Failed | Cancelled | Flag |',
    '|---|---:|---:|---:|---:|---:|---|',
    ...rows.slice(0, 25).map((r) => `| ${r.name} | ${r.runs} | ${r.perDay.toFixed(1)} | ${Math.round(r.minutes)} | ${r.failed} | ${r.cancelled} | ${r.flagged ? `> ${flagPerDay}/day` : ''} |`),
  ];
  const flagged = rows.filter((r) => r.flagged);
  lines.push('', flagged.length ? `Flagged (more than ${flagPerDay} completed attempts/day in this cohort): ${flagged.map((r) => `**${r.name}**`).join(', ')}. Check cancellations for concurrency churn; consider a longer schedule interval or path filters.` : `No workflow exceeded ${flagPerDay} completed attempts/day in this cohort.`);
  lines.push('', 'Founder one-time action: set a GitHub spending limit / budget alert (Settings > Billing and licensing > Budgets and alerts) so a runaway workflow stops at a known cost.');
  return lines.join('\n');
}

const execGh = (args, input) => execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 256 << 20, input });
const HYGIENE_LABEL = 'ops-hygiene';

// gh follows every Link page, and a later-page/API/JSON failure aborts before writes.
function readList(gh, path, valid) {
  const pages = JSON.parse(gh(['api', path, '--paginate', '--slurp']));
  if (!Array.isArray(pages) || pages.length === 0 || pages.some((page, n) => !Array.isArray(page) || page.length > PAGE_SIZE || (n < pages.length - 1 && page.length !== PAGE_SIZE) || page.some((item) => !valid(item)))) throw new Error(`Invalid paginated lookup: ${path}`);
  return pages.flat();
}

const validIssue = (issue) => issue && Number.isSafeInteger(issue.number) && issue.number > 0 && issue.state === 'open'
  && (issue.body === null || typeof issue.body === 'string')
  && (!Object.hasOwn(issue, 'pull_request') || (issue.pull_request && typeof issue.pull_request.url === 'string'));

export function main({ repo = process.env.GITHUB_REPOSITORY, now = Date.now(), gh = execGh } = {}) {
  if (!repo) throw new Error('GITHUB_REPOSITORY is required');
  const until = Math.floor(now / 1000) * 1000;
  const since = until - WINDOW_DAYS * 86400e3;
  const api = (path) => JSON.parse(gh(['api', path]));
  const { attempts, coverage } = collectAttempts(api, { repo, since, until });
  const body = render(aggregate(attempts), { date: iso(until).slice(0, 10), coverage });
  // Search all open issues so an existing, unlabelled marker is still reused.
  // Closing the tracker ends that active tracker; this workflow never reopens it.
  const issues = readList(gh, `/repos/${repo}/issues?state=open&sort=created&direction=asc&per_page=100`, validIssue);
  if (new Set(issues.map((issue) => issue.number)).size !== issues.length) throw new Error('Duplicate issues in tracker lookup');
  const trackers = issues.filter((i) => !i.pull_request && i.body?.includes(MARK));
  if (trackers.length > 1) throw new Error('Multiple active Actions report trackers; refusing to write');
  let issue = trackers[0];
  const post = (path, data) => JSON.parse(gh(['api', '-X', 'POST', path, '--input', '-'], JSON.stringify(data)));
  if (!issue) {
    const labels = readList(gh, `/repos/${repo}/labels?per_page=100`, (label) => label && typeof label.name === 'string' && label.name.length > 0);
    if (!labels.some((label) => label.name.toLowerCase() === HYGIENE_LABEL)) {
      const label = post(`/repos/${repo}/labels`, { name: HYGIENE_LABEL, color: 'c5def5', description: 'Operational workflow hygiene' });
      if (label?.name !== HYGIENE_LABEL) throw new Error('Label creation was not confirmed');
    }
    issue = post(`/repos/${repo}/issues`, { title: '[ops] Weekly Actions usage report', labels: [HYGIENE_LABEL], body: `${MARK}\nWeekly comments from .github/workflows/actions-cost-report.yml. Single active tracker; closing it allows a new one next time.` });
    if (!validIssue(issue) || !issue.body?.includes(MARK) || !Array.isArray(issue.labels) || !issue.labels.some((label) => label.name?.toLowerCase() === HYGIENE_LABEL)) throw new Error('Labelled tracker creation was not confirmed');
  }
  gh(['api', '-X', 'POST', `/repos/${repo}/issues/${issue.number}/comments`, '--input', '-'], JSON.stringify({ body }));
  console.log(`completed_attempts=${attempts.length} cohort_runs=${coverage.runCount} issue=#${issue.number}`);
}

if (import.meta.url === `file://${process.argv[1]}`) main();
