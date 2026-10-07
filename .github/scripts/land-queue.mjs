// land-queue: self-hosted merge queue (GitHub native merge queue is
// unavailable for this user-owned repo: a merge_queue ruleset probe returned 422).
// Runs from the base branch only; it never checks out or executes PR code.
import { execFileSync } from 'node:child_process';

const FAILED = new Set(['FAILURE', 'TIMED_OUT', 'CANCELLED', 'ACTION_REQUIRED', 'STARTUP_FAILURE', 'ERROR']);
const PASSED = new Set(['SUCCESS', 'NEUTRAL', 'SKIPPED']);

// Oldest label time first (PR number breaks ties). Only open, non-draft, base-main PRs.
export function orderQueue(prs) {
  return prs
    .filter((p) => !p.isDraft && p.baseRefName === 'main')
    .sort((a, b) => (a.labeledAt || '').localeCompare(b.labeledAt || '') || a.number - b.number);
}

// Latest run per required context -> 'pass' | 'fail' | 'pending'.
export function checkStates(rollup, required) {
  const latest = new Map();
  for (const c of rollup || []) {
    const name = c.name || c.context;
    const at = c.startedAt || c.createdAt || '';
    if (name && (!latest.has(name) || at >= latest.get(name).at)) latest.set(name, { c, at });
  }
  return Object.fromEntries(required.map((name) => {
    const c = latest.get(name)?.c;
    if (!c) return [name, 'pending'];
    const v = String(c.conclusion || c.state || '').toUpperCase();
    return [name, FAILED.has(v) ? 'fail' : PASSED.has(v) ? 'pass' : 'pending'];
  }));
}

// pr: {mergeStateStatus, unresolvedThreads, rollup}. Spec order: BEHIND, DIRTY, failed, merge, wait.
// Review threads are advisory (Founder 2026-10-07): required CI decides; threads never drop a PR.
export function decide(pr, required) {
  if (pr.mergeStateStatus === 'BEHIND') return { action: 'update' };
  if (pr.mergeStateStatus === 'DIRTY') return { action: 'reject', reason: 'conflict', text: 'conflict with main, please merge main and re-add `queue`' };
  const states = checkStates(pr.rollup, required);
  const failed = Object.keys(states).filter((n) => states[n] === 'fail');
  if (failed.length) return { action: 'reject', reason: 'checks', text: `required checks failed: ${failed.join(', ')}. Fix and re-add \`queue\`` };
  const allGreen = Object.values(states).every((s) => s === 'pass');
  if (pr.mergeStateStatus === 'CLEAN' && allGreen) return { action: 'merge' };
  return { action: 'wait' };
}

export const marker = (reason, sha) => `<!-- land-queue:${reason}:${sha} -->`;

const REPO = process.env.REPO || process.env.GITHUB_REPOSITORY;
const gh = (...args) => execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 64 << 20 });
const api = (path, ...extra) => JSON.parse(gh('api', `/repos/${REPO}/${path}`, ...extra) || 'null');
const apiPages = (path) => JSON.parse(gh('api', `/repos/${REPO}/${path}`, '--paginate', '--slurp')).flat();

function requiredContexts() {
  const rules = api('rules/branches/main');
  const ctx = rules.filter((r) => r.type === 'required_status_checks')
    .flatMap((r) => r.parameters.required_status_checks.map((c) => c.context));
  if (!ctx.length) throw new Error('no required status checks found; refusing to merge');
  return [...new Set(ctx)];
}

function labeledAt(n) {
  const ev = apiPages(`issues/${n}/events?per_page=100`).filter((e) => e.event === 'labeled' && e.label?.name === 'queue');
  return ev.length ? ev[ev.length - 1].created_at : '';
}

const Q = `query($o:String!,$r:String!,$n:Int!){repository(owner:$o,name:$r){pullRequest(number:$n){
  mergeStateStatus headRefName headRefOid
  reviewThreads(first:100){nodes{isResolved}}
  commits(last:1){nodes{commit{statusCheckRollup{contexts(first:100){nodes{
    __typename ... on CheckRun{name conclusion status startedAt} ... on StatusContext{context state createdAt}}}}}}}}}}`;

function load(n) {
  const [o, r] = REPO.split('/');
  const p = JSON.parse(gh('api', 'graphql', '-f', `query=${Q}`, '-F', `o=${o}`, '-F', `r=${r}`, '-F', `n=${n}`)).data.repository.pullRequest;
  return {
    mergeStateStatus: p.mergeStateStatus,
    headRefName: p.headRefName,
    sha: p.headRefOid,
    unresolvedThreads: p.reviewThreads.nodes.filter((t) => !t.isResolved).length,
    rollup: p.commits.nodes[0]?.commit.statusCheckRollup?.contexts.nodes ?? [],
  };
}

function note(n, sha, reason, text) {
  const m = marker(reason, sha);
  const seen = apiPages(`issues/${n}/comments?per_page=100`).some((c) => c.body?.includes(m));
  if (!seen) gh('pr', 'comment', String(n), '--repo', REPO, '--body', `land-queue: ${text}\n\n${m}`);
}

function reject(n, sha, d) {
  const m = marker(d.reason, sha);
  const seen = apiPages(`issues/${n}/comments?per_page=100`).some((c) => c.body?.includes(m));
  if (!seen) gh('pr', 'comment', String(n), '--repo', REPO, '--body', `land-queue: ${d.text}\n\n${m}`);
  try { gh('pr', 'edit', String(n), '--repo', REPO, '--remove-label', 'queue'); } catch (e) { console.log(`label removal: ${e.message}`); }
}

// GITHUB_TOKEN pushes (update-branch) do not fire pull_request workflows, so a fresh
// head has no checks. Dispatch the required-check workflows on the PR branch once;
// each guards that pr_number's head equals the dispatched SHA and its base is main.
const CHECK_WORKFLOWS = ['ci.yml', 'presubmit.yml', 'docs-integrity.yml', 'secret-scan.yml', 'agent-provenance.yml'];
function kickChecksIfMissing(n, pr) {
  if (pr.rollup.length) return;
  const runs = api(`actions/runs?head_sha=${pr.sha}&per_page=1`).total_count;
  if (runs) return;
  console.log(`#${n}: no checks on ${pr.sha}; dispatching check workflows on ${pr.headRefName}`);
  for (const wf of CHECK_WORKFLOWS) gh('workflow', 'run', wf, '--repo', REPO, '--ref', pr.headRefName, '-f', `pr_number=${n}`);
}

// GITHUB_TOKEN merges do not fire push CI on main, and deploy.yml only runs after
// a CI run on main. Dispatch it once per main tip; autonomous-convergence.yml
// holds when a CI run for the tip already exists, so it will not double dispatch.
function ciForMainTip() {
  const sha = api('git/ref/heads/main').object.sha;
  if (api(`actions/workflows/ci.yml/runs?branch=main&head_sha=${sha}&per_page=1`).total_count) return;
  console.log(`dispatching CI on main ${sha}`);
  gh('workflow', 'run', 'ci.yml', '--repo', REPO, '--ref', 'main');
}

let merged = false;
function processQueue() {
  const required = requiredContexts();
  const listed = JSON.parse(gh('pr', 'list', '--repo', REPO, '--label', 'queue', '--state', 'open', '--base', 'main', '--json', 'number,isDraft,baseRefName'));
  const queue = orderQueue(listed.map((p) => ({ ...p, labeledAt: labeledAt(p.number) })));
  console.log(`queue: ${queue.map((p) => '#' + p.number).join(' ') || '(empty)'}`);
  for (const { number: n } of queue) {
    const pr = load(n);
    const d = decide(pr, required);
    console.log(`#${n} ${pr.mergeStateStatus} -> ${d.action}${d.reason ? ' (' + d.reason + ')' : ''}`);
    if (d.action === 'update') {
      try {
        gh('api', '-X', 'PUT', `/repos/${REPO}/pulls/${n}/update-branch`, '-f', `expected_head_sha=${pr.sha}`);
      } catch (e) {
        // GITHUB_TOKEN cannot write .github/workflows; without LAND_QUEUE_TOKEN the merge from main is refused.
        if (!/workflows` permission|workflows permission/.test(String(e.message))) throw e;
        note(n, pr.sha, 'needs-manual-update', 'main changed workflow files, which the queue token may not merge. Merge main into this branch yourself (`gh pr update-branch`); the queue keeps the label and continues once it is up to date. (Set secret LAND_QUEUE_TOKEN to let the queue do this.)');
        continue; // keep the label; try the next queued PR
      }
      break;
    }
    if (d.action === 'reject') { reject(n, pr.sha, d); continue; }
    if (d.action === 'merge') { gh('pr', 'merge', String(n), '--repo', REPO, '--squash', '--delete-branch', '--match-head-commit', pr.sha); merged = true; continue; }
    kickChecksIfMissing(n, pr);
    continue; // wait: don't block the PRs behind it (no head-of-line blocking)
  }
}

function main() {
  try { processQueue(); } finally { if (merged) ciForMainTip(); } // even if a later PR throws
}

if (import.meta.url === `file://${process.argv[1]}`) main();
