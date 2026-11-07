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
// R3/T3 (declared, or path-derived minimum; unknown/duplicate/odd declaration fails closed) additionally needs an owner `gate:security-data-reviewer:v1 sha=<head> verdict=pass`
// marker or the independent-review marker above; any owner `gate:<role>:v1 sha=<head> verdict=fail` marker vetoes every lane.
// Codex findings or an incomplete review on the head veto the fallback. Anything unknown or unparsable never passes.
// Runs from the default branch only; never checks out or executes PR code.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export const REVIEW_CHECK = 'review/final-head';
export const CODEX_LOGIN = 'chatgpt-codex-connector[bot]';
const SHA40 = /^[0-9a-f]{40}$/;
const STATE = /<!-- codex-review-state:v1 ([\s\S]*?) -->/g;
export const reviewMarker = (sha) => `<!-- independent-review:v1 sha=${sha} verdict=pass -->`;

const NAMED = { quot: '"', amp: '&', lt: '<', gt: '>', apos: "'" };
export const decodeEntities = (s) => s.replace(/&(?:#(\d+)|#x([0-9a-f]+)|(quot|amp|lt|gt|apos));/gi,
  (_, d, h, n) => (n ? NAMED[n.toLowerCase()] : String.fromCodePoint(parseInt(d ?? h, d ? 10 : 16))));

const failOf = (sha) => new RegExp(`<!-- (?:gate:[a-z-]+|independent-review):v1 sha=${sha} verdict=fail -->`);
// Gerrit copyCondition changekind:TRIVIAL_REBASE. mine = owner comment bodies, oldest first. Newest pass marker
// (per `pass(body, sha)`) on an earlier head whose samePatch(old) holds, or null. Any fail marker on that sha, or any
// later owner FAIL, vetoes the candidate; a throwing samePatch means no carry.
function carried(mine, head, pass, samePatch) {
  const seen = new Set();
  for (let i = mine.length - 1; i >= 0; i--) {
    for (const m of mine[i].matchAll(/sha=([0-9a-f]{40}) verdict=pass -->/g)) {
      const old = m[1];
      if (old === head || seen.has(old) || !pass(mine[i], old)) continue;
      seen.add(old);
      if (mine.some((b) => failOf(old).test(b)) || mine.slice(i + 1).some((b) => b.includes('verdict=fail'))) continue;
      try { if (samePatch(old)) return old; } catch { /* fail closed */ }
    }
  }
  return null;
}

export const gateMarker = (role, sha, verdict = 'pass') => `<!-- gate:${role}:v1 sha=${sha} verdict=${verdict} -->`;
// comments: issue comments [{user:{login}, body, created_at, updated_at}] or null when unreadable.
// Returns {ok: true, via} or {ok: false, why}.
// scope: 'lower' only when scripts/governance/pr_requires_security.py cleared the PR; anything else is treated as R3.
// samePatch(oldSha) => true only when the PR's own diff at oldSha is byte-identical to head's (I/O layer; fails closed).
export function reviewGate(comments, head, owner, scope, samePatch = () => false) {
  const g = codexGate(comments, head, owner, samePatch);
  if (!Array.isArray(comments) || !owner || !SHA40.test(head || '')) return g;
  const lower = owner.toLowerCase();
  const mine = comments.filter((c) => c?.user?.login?.toLowerCase() === lower).map((c) => c.body || '');
  const r3Pass = (b, sha) => b.includes(gateMarker('security-data-reviewer', sha)) || b.includes(reviewMarker(sha));
  if (mine.some((b) => failOf(head).test(b))) return { ok: false, why: `gate verdict=fail on ${head.slice(0, 7)}` };
  if (!g.ok || scope === 'lower') return g;
  if (mine.some((b) => r3Pass(b, head))) return g;
  const old = carried(mine, head, r3Pass, samePatch);
  if (old) return { ok: true, via: `r3-carried:${old.slice(0, 7)}` };
  return { ok: false, why: `R3 needs a security-data-reviewer marker on ${head.slice(0, 7)}` };
}

function codexGate(comments, head, owner, samePatch = () => false) {
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
  // `failed` = Codex could not run on this head (e.g. usage limit). Its findings flag is carried over from an
  // earlier head, not a result, so it neither passes nor vetoes; open review threads are gated separately.
  const onHead = cr?.commit_sha === head && cr.status !== 'failed';
  if (onHead && cr.status === 'completed' && cr.has_unresolved_findings === false) return { ok: true, via: 'codex' };
  if (onHead && (cr.has_unresolved_findings === true || (cr.status === 'completed' && cr.has_unresolved_findings !== false))) return { ok: false, why: `Codex reported unresolved findings on ${short}` };
  // A fallback is for absent/limited review capacity, not an override of an active
  // review. Otherwise the same head could merge before its pending findings arrive.
  if (onHead) return { ok: false, why: `Codex review ${cr.status ?? 'unknown'} on ${short}` };
  // TRIVIAL_REBASE: a clean completed Codex state on an earlier head carries when only main was merged in since.
  // Same per-SHA ambiguity rule as the current head: equal-timestamp records of that SHA must agree.
  const ambiguous = cr && records.some((r) => r.cr.commit_sha === cr.commit_sha && r.at === records[0].at
    && (r.cr.status !== cr.status || r.cr.has_unresolved_findings !== cr.has_unresolved_findings));
  // Records tied at the newest timestamp must agree whatever their SHA; sort order never decides a carry.
  const tie = records.some((r) => r.at === records[0].at && (r.cr.status !== records[0].cr.status
    || r.cr.has_unresolved_findings !== records[0].cr.has_unresolved_findings));
  const stale = cr && !ambiguous && !tie && cr.commit_sha !== head && cr.status === 'completed' && cr.has_unresolved_findings === false;
  // A reviewer FAIL must not be cleared by an update-branch: any owner fail marker (old head, an in-between head or
  // later) blocks the Codex carry, same as carried() does for markers.
  const failed = comments.some((c) => c?.user?.login?.toLowerCase() === owner.toLowerCase() && (c.body || '').includes('verdict=fail'));
  try { if (stale && !failed && samePatch(cr.commit_sha)) return { ok: true, via: `codex-carried:${cr.commit_sha.slice(0, 7)}` }; } catch { /* fail closed */ }
  const lower = owner.toLowerCase();
  const mine = comments.filter((c) => c?.user?.login?.toLowerCase() === lower).map((c) => c.body || '');
  if (mine.some((b) => b.includes(reviewMarker(head)))) return { ok: true, via: 'independent-review' };
  // A marker carry takes the old head's whole verdict: any Codex record on another SHA with findings, an unfinished
  // review or an unreadable verdict vetoes it (the marker alone never overrides Codex findings).
  // Anything but an exact `false` findings flag on ANY record vetoes (null, missing or non-boolean fail closed), including the head's own `failed` sticky state (a usage-limited Codex rewrites its one
  // comment for the head but keeps the old findings flag).
  const codexVeto = records.some((r) => r.cr.has_unresolved_findings !== false || (r.cr.commit_sha !== head
    && !['completed', 'failed'].includes(r.cr.status)));
  const old = codexVeto || tie ? null : carried(mine, head, (b, sha) => b.includes(reviewMarker(sha)), samePatch);
  if (old) return { ok: true, via: `independent-review-carried:${old.slice(0, 7)}` };
  if (!cr) return { ok: false, why: `no Codex review on ${short} yet` };
  return { ok: false, why: onHead ? `Codex review ${cr.status ?? 'unknown'} on ${short}` : `Codex last reviewed ${String(cr.commit_sha).slice(0, 7)}, not head ${short}` };
}

const REPO = process.env.REPO || process.env.GITHUB_REPOSITORY;
const gh = (...args) => execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 64 << 20 });
// GitHub 404s `/repos/o/r/` (trailing slash), so the repo itself is `/repos/o/r`.
const api = (path, ...extra) => JSON.parse(gh('api', path ? `/repos/${REPO}/${path}` : `/repos/${REPO}`, ...extra) || 'null');

// Declared tier (body) and path-derived minimum, via the one Python parser; any failure means R3.
// Pure: 'lower' only when the head did not move while reading and the Python parser clears body + files.
export function classifyScope(head, before, files, after) {
  try {
    if (before?.head?.sha !== head || after?.head?.sha !== head) return 'r3';
    return execFileSync('python3', [new URL('../../scripts/governance/pr_requires_security.py', import.meta.url).pathname], { input: JSON.stringify({ body: before.body ?? '', files }), encoding: 'utf8', timeout: 10000 }).trim();
  } catch { return 'r3'; } // incl. spawn timeout
}
function prScope(n, head) {
  try {
    const before = api(`pulls/${n}`);
    const files = JSON.parse(gh('api', `/repos/${REPO}/pulls/${n}/files?per_page=100`, '--paginate', '--slurp')).flat();
    return classifyScope(head, before, files, api(`pulls/${n}`));
  } catch { return 'r3'; }
}

// --- TRIVIAL_REBASE core (pure; unit-tested) ---
// `git patch-id --verbatim` (whitespace counts) of a plain diff, or null when it cannot be computed (never throws).
export function patchId(diff) {
  try {
    if (!diff || /^(Binary files |GIT binary patch)/m.test(diff)) return null; // blobs are not hashed by patch-id
    return execFileSync('git', ['patch-id', '--verbatim'], { input: diff, encoding: 'utf8' }).split(' ')[0] || null;
  } catch { return null; }
}
// patch-id ignores whitespace, hunk line numbers and mode lines. Line numbers must be free (main shifts them); the rest
// is pinned: every exact +/- line and every mode/rename line, order-free (patch-id already pins the order).
const exactLines = (diff) => diff.split('\n').filter((l) => /^(\+(?!\+\+ )|-(?!-- )|(old|new|deleted file|new file) mode |(rename|copy) (from|to) )/.test(l)).sort().join('\n');
export const sameDiff = (a, b) => {
  const x = patchId(a), y = patchId(b);
  return x !== null && x === y && exactLines(a) === exactLines(b);
};
// commits: compare(base...sha).commits of old and head, [{sha, parents:[{sha}]}]. True only when head contains old and
// every commit added since is a merge whose other parent is on main (outside the PR's own commit set).
export function onlyMainMerged(oldCommits, headCommits) {
  const own = new Set(headCommits.map((c) => c.sha));
  const before = new Set(oldCommits.map((c) => c.sha));
  if (![...before].every((sha) => own.has(sha))) return false; // old is not an ancestor of head
  return headCommits.filter((c) => !before.has(c.sha)).every((c) => c.parents?.length === 2 && c.parents.filter((p) => own.has(p.sha)).length === 1);
}

// TRIVIAL_REBASE proper: merge commit m must be the AUTOMATIC merge of its two parents (second parent on main), i.e.
// `git merge-tree --write-tree p1 p2` is conflict-free and yields exactly m's tree. A hand-edited or conflict-resolved
// merge (even one that keeps the same patch-id) is not. git: (...args) => stdout, throws on non-zero exit.
export function automaticMerge(git, m, main = 'origin/main') {
  try {
    const parents = git('rev-list', '--parents', '-n', '1', m).trim().split(' ').slice(1);
    if (parents.length !== 2) return false;
    git('merge-base', '--is-ancestor', parents[1], main);
    return git('merge-tree', '--write-tree', parents[0], parents[1]).split('\n')[0].trim() === git('rev-parse', `${m}^{tree}`).trim();
  } catch { return false; }
}
// Blobless throwaway bare repo with main and the PR head fetched (fetch only: nothing from the PR is checked out or run).
// exec(args) runs git with those args (injectable for tests). Caller removes dir.
export function carryRepo(n, base, repo = REPO, exec = (a) => execFileSync('git', a, { encoding: 'utf8', maxBuffer: 64 << 20, stdio: ['ignore', 'pipe', 'pipe'] })) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'trivial-rebase-'));
  const git = (...a) => exec(['-C', dir, ...a]);
  try {
    git('init', '--bare', '-q');
    git('config', 'credential.helper', '!gh auth git-credential'); // GH_TOKEN for the fetch and the lazy blob fetches
    git('fetch', '--no-tags', '--filter=blob:none', `https://github.com/${repo}.git`, `refs/heads/${base}:refs/remotes/origin/${base}`, `refs/pull/${n}/head:refs/pull/${n}/head`);
  } catch (e) { fs.rmSync(dir, { recursive: true, force: true }); throw e; }
  return { git, dir };
}

// Runs fn(git) against a fresh carry repo and always removes it (also when fn or the fetch throws).
export function withCarryRepo(n, base, fn, open = carryRepo) {
  const repo = open(n, base);
  try { return fn(repo.git); } finally { fs.rmSync(repo.dir, { recursive: true, force: true }); }
}

// Gerrit changekind:TRIVIAL_REBASE for PR n: carry old's review to head only if the PR's diff against main is the same
// (patch-id plus exact lines) and every commit added since is an automatic merge of main. Reads the API only; any error = false.
export function trivialRebase(n, head, old) {
  try {
    const base = api('').default_branch;
    if (!base || api(`pulls/${n}`).base?.ref !== base) return false;
    const commits = (sha) => JSON.parse(gh('api', `/repos/${REPO}/compare/${encodeURIComponent(base)}...${sha}?per_page=100`, '--paginate', '--slurp')).flatMap((p) => p.commits || []);
    const prShas = JSON.parse(gh('api', `/repos/${REPO}/pulls/${n}/commits?per_page=100`, '--paginate', '--slurp')).flat().map((c) => c.sha);
    const oldC = commits(old), headC = commits(head);
    if (!prShas.includes(old) || !prShas.includes(head) || !onlyMainMerged(oldC, headC)) return false;
    const before = new Set(oldC.map((c) => c.sha));
    const added = headC.map((c) => c.sha).filter((sha) => !before.has(sha));
    if (added.length && !withCarryRepo(n, base, (git) => added.every((sha) => automaticMerge(git, sha, `origin/${base}`)))) return false;
    const diff = (sha) => gh('api', `/repos/${REPO}/compare/${encodeURIComponent(base)}...${sha}`, '-H', 'Accept: application/vnd.github.diff');
    return sameDiff(diff(old), diff(head));
  } catch { return false; }
}

// Reads the PR's comments and evaluates the head; any read failure is unknown (never passes).
export function readReview(n, head) {
  try {
    const scope = prScope(n, head);
    const comments = JSON.parse(gh('api', `/repos/${REPO}/issues/${n}/comments?per_page=100`, '--paginate', '--slurp')).flat();
    return reviewGate(comments, head, REPO.split('/')[0], scope, (old) => trivialRebase(n, head, old));
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
    if (api(`pulls/${n}`).head?.sha !== pr.head.sha) { console.log(`#${n}: head moved; skipping publish`); continue; }
    publish(pr.head.sha, g);
    if (!g.ok && pr.auto_merge && !required) {
      console.log(`#${n}: auto-merge disabled until ${REVIEW_CHECK} is a required check (use the queue label)`);
      gh('pr', 'merge', String(n), '--repo', REPO, '--disable-auto');
    }
  }
}

if (import.meta.url === `file://${process.argv[1]}`) main();
