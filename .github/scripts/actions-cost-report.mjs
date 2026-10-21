// Weekly Actions usage report: runs and wall-clock minutes per workflow from the runs API
// (the repo token cannot read account billing endpoints, so minutes are an approximation:
// updated_at - run_started_at, includes waiting inside the run). One comment on one tracking issue.
import { execFileSync } from 'node:child_process';

export const WINDOW_DAYS = 7;
export const RUNS_PER_DAY_FLAG = 48; // land-queue's */5 schedule alone is 288/day
export const MARK = '<!-- actions-cost-report -->';

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

export function render(rows, { days = WINDOW_DAYS, flagPerDay = RUNS_PER_DAY_FLAG, date }) {
  const total = rows.reduce((s, r) => s + r.minutes, 0);
  const lines = [
    MARK,
    `## Actions usage, last ${days} days (${date})`,
    `Total: ${rows.reduce((s, r) => s + r.runs, 0)} runs, about ${Math.round(total)} wall-clock minutes (approximation: includes time waiting inside a run; not the billed figure).`,
    '',
    '| Workflow | Runs | Runs/day | ~Minutes | Failed | Cancelled | Flag |',
    '|---|---:|---:|---:|---:|---:|---|',
    ...rows.slice(0, 25).map((r) => `| ${r.name} | ${r.runs} | ${r.perDay.toFixed(1)} | ${Math.round(r.minutes)} | ${r.failed} | ${r.cancelled} | ${r.flagged ? `> ${flagPerDay}/day` : ''} |`),
  ];
  const flagged = rows.filter((r) => r.flagged);
  lines.push('', flagged.length ? `Flagged (more than ${flagPerDay} runs/day): ${flagged.map((r) => `**${r.name}**`).join(', ')}. Cancelled runs are usually concurrency churn; consider a longer schedule interval or path filters.` : `No workflow exceeded ${flagPerDay} runs/day.`);
  lines.push('', 'Founder one-time action: set a GitHub spending limit / budget alert (Settings > Billing and licensing > Budgets and alerts) so a runaway workflow stops at a known cost.');
  return lines.join('\n');
}

const REPO = process.env.GITHUB_REPOSITORY;
const gh = (args, input) => execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 256 << 20, input });

function main() {
  const since = new Date(Date.now() - WINDOW_DAYS * 86400e3).toISOString().slice(0, 10);
  const out = gh(['api', `/repos/${REPO}/actions/runs?created=>=${since}&status=completed&per_page=100`, '--paginate', '--jq', '.workflow_runs[] | {name,run_started_at,updated_at,conclusion}']);
  const runs = out.split('\n').filter(Boolean).map((l) => JSON.parse(l));
  const body = render(aggregate(runs), { date: new Date().toISOString().slice(0, 10) });
  const issues = JSON.parse(gh(['api', `/repos/${REPO}/issues?state=open&labels=ops-hygiene&per_page=100`]));
  let issue = issues.find((i) => !i.pull_request && i.body?.includes(MARK));
  if (!issue) issue = JSON.parse(gh(['api', '-X', 'POST', `/repos/${REPO}/issues`, '--input', '-'], JSON.stringify({ title: '[ops] Weekly Actions usage report', labels: ['ops-hygiene'], body: `${MARK}\nWeekly comments from .github/workflows/actions-cost-report.yml.` })));
  gh(['api', '-X', 'POST', `/repos/${REPO}/issues/${issue.number}/comments`, '--input', '-'], JSON.stringify({ body }));
  console.log(`runs=${runs.length} issue=#${issue.number}`);
}

if (import.meta.url === `file://${process.argv[1]}`) main();
