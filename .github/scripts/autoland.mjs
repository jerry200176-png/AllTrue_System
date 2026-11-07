// autoland (#4953 W1, Kodiak-style): for PRs labeled `autoland`, add `queue` once every gate land-queue.mjs /
// review-gate.mjs already compute is green. It reuses decide() (required checks, threads, Codex state, R3 marker via
// readReview) and never re-implements a gate. It only adds a label: no merge, no approval, no deployment/environment.
// Runs from the default branch only; never checks out or executes PR code. Any error = no label (fail closed).
import { execFileSync } from 'node:child_process';
import { decide, load, neutral, requiredChecks, TRUSTED_AUTHORS } from './land-queue.mjs';

// meta: {labels, isDraft, base, isCrossRepository}; pr: land-queue load() shape. -> {action: 'queue'|'wait'|'skip', reason}
export function autolandDecision(meta, pr, required) {
  const labels = meta.labels || [];
  if (!labels.includes('autoland')) return { action: 'skip', reason: 'no autoland label' };
  if (labels.includes('queue')) return { action: 'skip', reason: 'already queued' };
  if (meta.isDraft || meta.base !== 'main' || meta.isCrossRepository) return { action: 'skip', reason: 'draft, non-main base or fork' };
  if (!TRUSTED_AUTHORS.has(pr.authorAssociation)) return { action: 'skip', reason: 'untrusted author' };
  // neutral(): BEHIND is the queue's job (it runs update-branch); everything else is land-queue's own decision.
  const d = decide(neutral(pr), required);
  return d.action === 'merge' ? { action: 'queue', reason: 'all gates green' } : { action: 'wait', reason: d.reason || d.action };
}

const REPO = process.env.REPO || process.env.GITHUB_REPOSITORY;
const gh = (...args) => execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 64 << 20 });

function main() {
  const { required } = requiredChecks();
  let queued = 0;
  const prs = JSON.parse(gh('pr', 'list', '--repo', REPO, '--label', 'autoland', '--state', 'open', '--base', 'main', '--limit', '200', '--json', 'number,isDraft,baseRefName,isCrossRepository,labels'));
  for (const p of prs) {
    try {
      const meta = { labels: p.labels.map((l) => l.name), isDraft: p.isDraft, base: p.baseRefName, isCrossRepository: p.isCrossRepository };
      const pr = load(p.number);
      const d = autolandDecision({ ...meta, labels: pr.labels }, pr, required);
      console.log(`#${p.number} autoland -> ${d.action} (${d.reason})`);
      if (d.action === 'queue') { gh('pr', 'edit', String(p.number), '--repo', REPO, '--add-label', 'queue'); queued++; }
    } catch (e) { console.log(`#${p.number} autoland: unreadable, not queueing (${String(e.message).split('\n')[0]})`); }
  }
  // A label added with GITHUB_TOKEN triggers no workflow; workflow_dispatch is the exception, so start the queue explicitly.
  if (queued) gh('workflow', 'run', 'land-queue.yml', '--repo', REPO, '--ref', 'main');
}

if (import.meta.url === `file://${process.argv[1]}`) main();
