// land-queue: self-hosted merge queue (GitHub native merge queue is
// unavailable for this user-owned repo: a merge_queue ruleset probe returned 422).
// Runs from the base branch only; it never checks out or executes PR code.
import { execFileSync } from 'node:child_process';

const FAILED = new Set(['FAILURE', 'TIMED_OUT', 'CANCELLED', 'ACTION_REQUIRED', 'STARTUP_FAILURE', 'ERROR']);
const PASSED = new Set(['SUCCESS', 'NEUTRAL', 'SKIPPED']);

// Oldest label time first (PR number breaks ties). Only open, non-draft, base-main PRs.
export function orderQueue(prs) {
  return prs
    .filter((p) => !p.isDraft && p.baseRefName === 'main' && !p.isCrossRepository)
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

// pr: {mergeStateStatus, behindBy, unresolvedThreads, rollup}. Spec order: BEHIND, DIRTY, failed, threads, merge, wait.
// behindBy keeps "head contains main" enforced here once the ruleset's strict policy is off
// (GitHub then stops reporting BEHIND). Batch eligibility passes behindBy: 0 on purpose.
export function decide(pr, required) {
  if (pr.mergeStateStatus === 'BEHIND' || pr.behindBy > 0) return { action: 'update' };
  if (pr.mergeStateStatus === 'DIRTY') return { action: 'reject', reason: 'conflict', text: 'conflict with main, please merge main and re-add `queue`' };
  const states = checkStates(pr.rollup, required);
  const failed = Object.keys(states).filter((n) => states[n] === 'fail');
  if (failed.length) return { action: 'reject', reason: 'checks', text: `required checks failed: ${failed.join(', ')}. Fix and re-add \`queue\`` };
  if (pr.unresolvedThreads > 0) return { action: 'reject', reason: 'threads', text: `${pr.unresolvedThreads} unresolved review thread(s). Resolve them and re-add \`queue\`` };
  const allGreen = Object.values(states).every((s) => s === 'pass');
  if (pr.mergeStateStatus === 'CLEAN' && allGreen) return { action: 'merge' };
  return { action: 'wait' };
}

// Batch: up to BATCH_MAX oldest eligible PRs tested together on a branch cut from the main tip.
// cands: [{n, serial}] in queue order, already eligible. A PR whose batch went red is serial-only
// (until re-queued); if the oldest candidate is serial-only it goes first, alone, no batch.
export const BATCH_MAX = 4;
export const BATCH_PREFIX = 'chore/land-queue-batch-'; // chore/ passes Presubmit CHECK 1
export function planBatch(cands) {
  const batch = [];
  for (const c of cands) { if (c.serial || batch.length === BATCH_MAX) break; batch.push(c); }
  return batch.length >= 2 ? batch : [];
}

// Members live in the batch's first-parent merge commits: "land-queue batch member #N <sha>".
export const memberMessage = (n, sha) => `land-queue batch member #${n} ${sha}`;
export const parseMember = (msg) => { const m = /^land-queue batch member #(\d+) ([0-9a-f]{40})/.exec(msg || ''); return m ? { n: +m[1], sha: m[2] } : null; };

// Any required context failed -> red; all pass -> land; otherwise wait (red once older than ttl).
export function batchVerdict(states, ageMs, ttlMs = 2 * 3600e3) {
  const v = Object.values(states);
  if (v.includes('fail')) return 'red';
  if (v.length && v.every((s) => s === 'pass')) return 'land';
  return ageMs > ttlMs ? 'red' : 'wait';
}

export const marker = (reason, sha) => `<!-- land-queue:${reason}:${sha} -->`;

const REPO = process.env.REPO || process.env.GITHUB_REPOSITORY;
const gh = (...args) => execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 64 << 20 });
const api = (path, ...extra) => JSON.parse(gh('api', `/repos/${REPO}/${path}`, ...extra) || 'null');
const apiPages = (path) => JSON.parse(gh('api', `/repos/${REPO}/${path}`, '--paginate', '--slurp')).flat();

// strict: the ruleset still demands up-to-date branches. Batching only runs when it is off
// (a batch merges PR heads that are behind main, which strict would refuse).
function requiredChecks() {
  const rules = api('rules/branches/main').filter((r) => r.type === 'required_status_checks');
  const ctx = rules.flatMap((r) => r.parameters.required_status_checks.map((c) => c.context));
  if (!ctx.length) throw new Error('no required status checks found; refusing to merge');
  return { required: [...new Set(ctx)], strict: rules.some((r) => r.parameters.strict_required_status_checks_policy) };
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

const QC = `query($o:String!,$r:String!,$s:GitObjectID!){repository(owner:$o,name:$r){object(oid:$s){... on Commit{statusCheckRollup{contexts(first:100){nodes{
    __typename ... on CheckRun{name conclusion status startedAt} ... on StatusContext{context state createdAt}}}}}}}}`;

function rollupFor(sha) {
  const [o, r] = REPO.split('/');
  const c = JSON.parse(gh('api', 'graphql', '-f', `query=${QC}`, '-F', `o=${o}`, '-F', `r=${r}`, '-F', `s=${sha}`)).data.repository.object;
  return c?.statusCheckRollup?.contexts.nodes ?? [];
}

function load(n) {
  const [o, r] = REPO.split('/');
  const p = JSON.parse(gh('api', 'graphql', '-f', `query=${Q}`, '-F', `o=${o}`, '-F', `r=${r}`, '-F', `n=${n}`)).data.repository.pullRequest;
  return {
    behindBy: api(`compare/main...${p.headRefOid}`).behind_by,
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
// Required check "PHPStan Advisory (php)" comes from Security Scan, which takes no pr_number
// (PRs run it natively), so only batch branches dispatch it.
const BATCH_ONLY_WORKFLOWS = ['codeql.yml'];
// n undefined = a batch branch: no PR, so no pr_number (the guard is skipped; Golden skips itself).
function kickChecksIfMissing(n, pr) {
  if (pr.rollup.length) return;
  const runs = api(`actions/runs?head_sha=${pr.sha}&per_page=1`).total_count;
  if (runs) return;
  console.log(`${n ? '#' + n : 'batch'}: no checks on ${pr.sha}; dispatching check workflows on ${pr.headRefName}`);
  for (const wf of n ? CHECK_WORKFLOWS : [...CHECK_WORKFLOWS, ...BATCH_ONLY_WORKFLOWS]) gh('workflow', 'run', wf, '--repo', REPO, '--ref', pr.headRefName, ...(n ? ['-f', `pr_number=${n}`] : []));
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
const mainTip = () => api('git/ref/heads/main').object.sha;
const squash = (n, sha) => { gh('pr', 'merge', String(n), '--repo', REPO, '--squash', '--delete-branch', '--match-head-commit', sha); merged = true; };
const dropBranch = (name) => { try { gh('api', '-X', 'DELETE', `/repos/${REPO}/git/refs/heads/${name}`); } catch (e) { console.log(`branch delete: ${e.message}`); } };
// Batch eligibility = what decide() calls "merge", ignoring how far behind main the head is.
const neutral = (pr) => ({ ...pr, behindBy: 0, mergeStateStatus: pr.mergeStateStatus === 'BEHIND' ? 'CLEAN' : pr.mergeStateStatus });

// A batch that went red is not retried as a group: members go serial (update-branch, wait, merge).
// Comment is unconditional on purpose: its time vs the latest `queue` label scopes the serial-only flag.
const FAILED_MARK = '<!-- land-queue:batch-failed -->';
function serialOnly(n, since) {
  return apiPages(`issues/${n}/comments?per_page=100`).some((c) => c.body?.includes(FAILED_MARK) && c.created_at >= since);
}

// Walk the batch's first-parent merge commits (newest first) back to the main commit it was cut from.
function readBatch(tip) {
  const members = [];
  let sha = tip;
  for (;;) {
    const c = api(`git/commits/${sha}`);
    const m = parseMember(c.message);
    if (!m) return { members: members.reverse(), base: sha };
    members.push({ ...m, sha: c.parents[1].sha === m.sha ? m.sha : '' });
    sha = c.parents[0].sha;
  }
}

function failBatch(name, members, why) {
  console.log(`batch ${name} red: ${why}`);
  for (const m of members) gh('pr', 'comment', String(m.n), '--repo', REPO, '--body', `land-queue: batch ${name} failed (${why}); this PR now lands on its own (update-branch, wait, merge).\n\n${FAILED_MARK}`);
  dropBranch(name);
}

// Tree equality is the proof that squashing the members in order produced exactly what the batch tested.
function landBatch(name, tip, base, members) {
  let expect = base;
  let done = 0;
  for (const m of members) {
    if (mainTip() !== expect) { console.log('main moved while landing; remaining members re-batch next run'); break; }
    squash(m.n, m.sha);
    expect = api(`pulls/${m.n}`).merge_commit_sha;
    console.log(`batch landed #${m.n} -> ${expect}`);
    done++;
  }
  dropBranch(name);
  if (done === members.length && mainTip() === expect) {
    const tree = (sha) => api(`git/commits/${sha}`).tree.sha;
    if (tree(expect) !== tree(tip)) { console.error(`::error::landed tree ${tree(expect)} differs from tested batch tree ${tree(tip)}`); process.exitCode = 1; }
  }
}

// Returns true when a batch is in flight (or just landed/failed) and the serial loop must not run.
function stepBatch(name, tipSha, queue, required) {
  const { members, base } = readBatch(tipSha);
  const heads = new Map(queue.map((p) => [p.number, load(p.number)]));
  const stale = !members.length ? 'no members' : base !== mainTip() ? 'main moved'
    : members.some((m) => !m.sha || heads.get(m.n)?.sha !== m.sha || decide(neutral(heads.get(m.n)), required).action !== 'merge') ? 'a member changed' : '';
  if (stale) { console.log(`batch ${name} dropped: ${stale}`); dropBranch(name); return false; }
  const pr = { sha: tipSha, headRefName: name, rollup: rollupFor(tipSha) };
  const age = Date.now() - Date.parse(api(`git/commits/${tipSha}`).committer.date);
  const v = batchVerdict(checkStates(pr.rollup, required), age);
  console.log(`batch ${name} [${members.map((m) => '#' + m.n).join(' ')}] -> ${v}`);
  if (v === 'red') failBatch(name, members, 'a required check failed or timed out on the combined branch');
  else if (v === 'land') landBatch(name, tipSha, base, members);
  else kickChecksIfMissing(undefined, pr);
  return true;
}

function startBatch(queue, required) {
  const cands = [];
  for (const p of queue) {
    const pr = load(p.number);
    const d = decide(neutral(pr), required);
    if (d.action === 'reject') reject(p.number, pr.sha, d);
    else if (d.action === 'merge') cands.push({ n: p.number, pr, serial: serialOnly(p.number, p.labeledAt) });
  }
  const batch = planBatch(cands);
  if (!batch.length) return false;
  const base = mainTip();
  const name = `${BATCH_PREFIX}${process.env.GITHUB_RUN_ID || Date.now()}`;
  gh('api', '-X', 'POST', `/repos/${REPO}/git/refs`, '-f', `ref=refs/heads/${name}`, '-f', `sha=${base}`);
  let tip = base;
  for (const c of batch) {
    try {
      const r = JSON.parse(gh('api', '-X', 'POST', `/repos/${REPO}/merges`, '-f', `base=${name}`, '-f', `head=${c.pr.sha}`, '-f', `commit_message=${memberMessage(c.n, c.pr.sha)}`) || 'null');
      if (r) tip = r.sha;
    } catch (e) {
      console.log(`#${c.n} left out of batch: ${e.message}`);
      if (/409|conflict/i.test(e.message)) note(c.n, c.pr.sha, 'batch-conflict', `conflicts with other queued PRs in batch ${name}; it stays queued and lands after them or on its own.`);
    }
  }
  if (tip === base) { dropBranch(name); return false; }
  console.log(`batch ${name} cut from ${base}: ${batch.map((c) => '#' + c.n).join(' ')}`);
  kickChecksIfMissing(undefined, { sha: tip, headRefName: name, rollup: [] });
  return true;
}

function processQueue() {
  const { required, strict } = requiredChecks();
  const listed = JSON.parse(gh('pr', 'list', '--repo', REPO, '--label', 'queue', '--state', 'open', '--base', 'main', '--json', 'number,isDraft,baseRefName,isCrossRepository'));
  const queue = orderQueue(listed.map((p) => ({ ...p, labeledAt: labeledAt(p.number) })));
  console.log(`queue: ${queue.map((p) => '#' + p.number).join(' ') || '(empty)'}; batching ${strict ? 'off (strict up-to-date still required)' : 'on'}`);
  const open = api(`git/matching-refs/heads/${BATCH_PREFIX}`);
  if (strict) open.forEach((b) => dropBranch(b.ref.replace('refs/heads/', ''))); // ruleset flipped back: abandon batches
  else {
    for (const b of open.slice(1)) dropBranch(b.ref.replace('refs/heads/', '')); // one batch at a time
    if (open.length ? stepBatch(open[0].ref.replace('refs/heads/', ''), open[0].object.sha, queue, required) : false) return;
    if (startBatch(queue, required)) return;
  }
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
    // One serial merge per run: main moved, so every other head's behindBy is stale (strict used to refuse them).
    if (d.action === 'merge') { squash(n, pr.sha); break; }
    kickChecksIfMissing(n, pr);
    continue; // wait: don't block the PRs behind it (no head-of-line blocking)
  }
}

function main() {
  try { processQueue(); } finally { if (merged) ciForMainTip(); } // even if a later PR throws
}

if (import.meta.url === `file://${process.argv[1]}`) main();
