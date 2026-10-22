// review-gate: "LGTM on the final head". One rule shared by the land queue and the
// `review/final-head` check run (which GitHub native auto-merge waits on once the ruleset requires it).
// A PR head is reviewed only when, for that exact 40-hex SHA:
//   1. the Codex summary comment (author chatgpt-codex-connector[bot]) carries
//      `<!-- codex-review-state:v1 {...} -->` with code_review.commit_sha == head,
//      status == "completed" and has_unresolved_findings === false; or
//   2. fallback (Codex rate-limited / never ran): a PR comment by the repository owner account containing
//      `<!-- independent-review:v1 sha=<head> verdict=pass -->`. Only the orchestrator's fresh-context reviewer
//      agent writes it, after a /code-review run on that head. It is provenance evidence, NOT authentication:
//      the owner account is shared with agents, so anything holding that token can write it.
// Codex findings or an incomplete review on the head veto the fallback. Anything unknown or unparsable never passes.
// Runs from the default branch only; never checks out or executes PR code.
import { execFileSync } from 'node:child_process';

export const REVIEW_CHECK = 'review/final-head';
export const CODEX_LOGIN = 'chatgpt-codex-connector[bot]';
const SHA40 = /^[0-9a-f]{40}$/;
const STATE = /<!-- codex-review-state:v1 ([\s\S]*?) -->/g;
export const reviewMarker = (sha) => `<!-- independent-review:v1 sha=${sha} verdict=pass -->`;

const NAMED = { quot: '"', amp: '&', lt: '<', gt: '>', apos: "'" };
export const decodeEntities = (s) => s.replace(/&(?:#(\d+)|#x([0-9a-f]+)|(quot|amp|lt|gt|apos));/gi,
  (_, d, h, n) => (n ? NAMED[n.toLowerCase()] : String.fromCodePoint(parseInt(d ?? h, d ? 10 : 16))));

// comments: issue comments [{user:{login}, body, created_at, updated_at}] or null when unreadable.
// Returns {ok: true, via} or {ok: false, why}.
export function reviewGate(comments, head, owner) {
  if (!SHA40.test(head || '')) return { ok: false, why: 'PR head SHA unknown' };
  if (!Array.isArray(comments) || !owner) return { ok: false, why: 'PR comments unreadable' };
  const short = head.slice(0, 7);
  const records = [];
  for (const c of comments) {
    if (c?.user?.login !== CODEX_LOGIN) continue;
    const body = c.body || '';
    if (!body.includes('<!-- codex-review-state:v1')) continue;
    const matches = [...body.matchAll(STATE)];
    if (matches.length !== 1 || body.split('<!-- codex-review-state:v1').length !== 2) return { ok: false, why: 'Codex review state unreadable' };
    let cr;
    try { cr = JSON.parse(decodeEntities(matches[0][1]))?.code_review; } catch { cr = undefined; }
    if (!cr || typeof cr !== 'object' || Array.isArray(cr)
        || typeof cr.commit_sha !== 'string' || !SHA40.test(cr.commit_sha)) {
      return { ok: false, why: 'Codex review state unreadable' };
    }
    const stamp = c.updated_at || c.created_at;
    const at = typeof stamp === 'string' ? Date.parse(stamp) : NaN;
    if (typeof stamp !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/.test(stamp)
        || !Number.isFinite(at) || new Date(at).toISOString().slice(0, 19) !== stamp.slice(0, 19)) {
      return { ok: false, why: 'Codex review timestamp unreadable' };
    }
    records.push({ cr, at });
  }
  // A late review of another head cannot erase current-head evidence. Validate
  // every record first; malformed or ambiguous evidence never opens the fallback.
  records.sort((a, b) => b.at - a.at || a.cr.commit_sha.localeCompare(b.cr.commit_sha));
  const current = records.filter((r) => r.cr.commit_sha === head);
  const latest = current[0];
  if (latest && current.some((r) => r.at === latest.at && (r.cr.status !== latest.cr.status
      || r.cr.has_unresolved_findings !== latest.cr.has_unresolved_findings))) {
    return { ok: false, why: 'Codex current-head review state ambiguous' };
  }
  const cr = latest?.cr || records[0]?.cr;
  const onHead = cr?.commit_sha === head;
  if (onHead && cr.status === 'completed' && cr.has_unresolved_findings === false) return { ok: true, via: 'codex' };
  if (onHead && (cr.has_unresolved_findings === true || (cr.status === 'completed' && cr.has_unresolved_findings !== false))) return { ok: false, why: `Codex reported unresolved findings on ${short}` };
  // A fallback is for absent/limited review capacity, not an override of an active
  // review. Otherwise the same head could merge before its pending findings arrive.
  if (onHead) return { ok: false, why: `Codex review ${cr.status ?? 'unknown'} on ${short}` };
  const lower = owner.toLowerCase();
  if (comments.some((c) => c?.user?.login?.toLowerCase() === lower && (c.body || '').includes(reviewMarker(head)))) return { ok: true, via: 'independent-review' };
  if (!cr) return { ok: false, why: `no Codex review on ${short} yet` };
  return { ok: false, why: onHead ? `Codex review ${cr.status ?? 'unknown'} on ${short}` : `Codex last reviewed ${String(cr.commit_sha).slice(0, 7)}, not head ${short}` };
}

const REPO = process.env.REPO || process.env.GITHUB_REPOSITORY;
const gh = (...args) => execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 64 << 20 });
const api = (path, ...extra) => JSON.parse(gh('api', `/repos/${REPO}/${path}`, ...extra) || 'null');

// Reads the PR's comments and evaluates the head; any read failure is unknown (never passes).
export function readReview(n, head) {
  try {
    const comments = JSON.parse(gh('api', `/repos/${REPO}/issues/${n}/comments?per_page=100`, '--paginate', '--slurp')).flat();
    return reviewGate(comments, head, REPO.split('/')[0]);
  } catch (e) { return { ok: false, why: `PR comments unreadable (${e.message.split('\n')[0]})` }; }
}

// Publish the verdict as a check run on the head (success or in_progress; never failure, so a pending review
// does not read as a red check). Skip when the latest run already says the same thing.
function publish(head, g) {
  const title = g.ok ? `reviewed (${g.via})` : 'waiting for review on this head';
  const summary = g.ok ? `Final-head review present for ${head}.` : `${g.why}. Merge waits for a completed clean review of ${head}.`;
  const last = api(`commits/${head}/check-runs?check_name=${encodeURIComponent(REVIEW_CHECK)}&filter=latest`).check_runs?.[0];
  const same = last && (g.ok ? last.conclusion === 'success' : last.status !== 'completed') && last.output?.summary === summary;
  if (same) return;
  const args = ['-f', `name=${REVIEW_CHECK}`, '-f', `head_sha=${head}`, '-f', `output[title]=${title}`, '-f', `output[summary]=${summary}`];
  gh('api', '-X', 'POST', `/repos/${REPO}/check-runs`, ...args, ...(g.ok ? ['-f', 'status=completed', '-f', 'conclusion=success'] : ['-f', 'status=in_progress']));
}

// Until the ruleset requires REVIEW_CHECK, native auto-merge would not wait for it: switch it off on unreviewed heads.
const rulesetRequires = () => api('rules/branches/main').some((r) => r.type === 'required_status_checks'
  && r.parameters.required_status_checks.some((c) => c.context === REVIEW_CHECK));

function main() {
  const one = process.env.PR_NUMBER;
  const prs = one ? [Number(one)] : JSON.parse(gh('pr', 'list', '--repo', REPO, '--state', 'open', '--base', 'main', '--limit', '200', '--json', 'number')).map((p) => p.number);
  const required = rulesetRequires();
  for (const n of prs) {
    const pr = api(`pulls/${n}`);
    if (pr.state !== 'open' || pr.base?.ref !== 'main') continue;
    const g = readReview(n, pr.head.sha);
    console.log(`#${n} ${pr.head.sha}: ${g.ok ? 'reviewed via ' + g.via : 'pending: ' + g.why}`);
    publish(pr.head.sha, g);
    if (!g.ok && pr.auto_merge && !required) {
      console.log(`#${n}: auto-merge disabled until ${REVIEW_CHECK} is a required check (use the queue label)`);
      gh('pr', 'merge', String(n), '--repo', REPO, '--disable-auto');
    }
  }
}

if (import.meta.url === `file://${process.argv[1]}`) main();
