// Trusted checkout only; no PR code execution or installed live transport.
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { decide, emptyLedger } from './codex-review-throttle.mjs';
import { repositoryPolicy, githubClient } from './codex-review-shared.mjs';
export const request = async (method, route, body) => {
  let raw;
  const args = ['api', route, '--include', '--method', method];
  if (body) args.push('--input', '-');
  try { raw = execFileSync('gh', args, { input: body ? JSON.stringify(body) : undefined, encoding: 'utf8', maxBuffer: 32 << 20, stdio: ['pipe', 'pipe', 'pipe'] }); }
  catch (e) { raw = e.stdout; if (!raw) throw new Error('GitHub read failed'); }
  raw = String(raw).replace(/\r/g, ''); const end = raw.indexOf('\n\n');
  const headers = Object.fromEntries(raw.slice(0, end).split('\n').slice(1).map((l) => { const i = l.indexOf(':'); return [l.slice(0, i), l.slice(i + 1).trim()]; }));
  return { status: Number(/^HTTP\/\S+ (\d+)/.exec(raw)?.[1]), headers, body: JSON.parse(raw.slice(end + 2)) };
};
export function reader(api, repo, policy) {
const get = (p) => api('GET', `/repos/${repo}/${p}`);
async function pages(p) {
  const all = [];
  for (let page = 1; page <= 31; page++) {
    const batch = await get(`${p}&page=${page}`); if (!Array.isArray(batch)) throw new Error('pagination unknown');
    all.push(...batch); if (batch.length < 100) return all;
  }
  throw new Error('pagination incomplete');
}
const [owner, name] = repo.split('/');
const query = `query($owner:String!,$name:String!,$n:Int!){repository(owner:$owner,name:$name){pullRequest(number:$n){
headRefOid baseRefOid reviewThreads(first:100){nodes{id isResolved comments{totalCount}} pageInfo{hasNextPage}}
commits(last:1){nodes{commit{oid statusCheckRollup{contexts(first:100){pageInfo{hasNextPage} nodes{
__typename ... on CheckRun{name status conclusion startedAt checkSuite{app{databaseId} repository{nameWithOwner}}}
}}}}}}}}}`;
return async (number) => {
const prArg = String(number);
if (prArg && !/^[1-9]\d*$/.test(prArg)) throw new Error('invalid PR');
const rules = (await get(`rules/branches/${encodeURIComponent(policy.base)}`)).filter((r) => r.type === 'required_status_checks');
const required = rules.flatMap((r) => r.parameters.required_status_checks);
    const p = await get(`pulls/${number}`);
    if (p.base.ref !== policy.base) throw new Error('base mismatch');
    const files = await pages(`pulls/${p.number}/files?per_page=100`);
    const risk = policy.riskAdapter === 'alltrue' ? JSON.parse(execFileSync('python3', ['-B', '-c',
      'import json,sys; from scripts.governance.autonomy_gate import classify_scope; x=json.load(sys.stdin); print(json.dumps(classify_scope(x["paths"],x["patch"])))'],
    { input: JSON.stringify({ paths: files.flatMap((f) => [f.filename, f.previous_filename].filter(Boolean)),
      patch: files.map((f) => f.patch || '').join('\n') }), encoding: 'utf8' })) : { machine_minimum_tier: 3, reasons: ['full scope: no local classifier adapter installed'] };
    const graph = await api('POST', '/graphql', { query, variables: { owner, name, n: p.number } });
    const g = graph.data?.repository?.pullRequest, commit = g?.commits?.nodes?.[0]?.commit, conn = commit?.statusCheckRollup?.contexts;
    const reviews = await pages(`pulls/${p.number}/reviews?per_page=100`), comments = await pages(`issues/${p.number}/comments?per_page=100`);
    const ready = comments.some((c) => c.user?.login === p.user.login && ['OWNER', 'MEMBER', 'COLLABORATOR'].includes(c.author_association)
      && c.body?.trim() === `codex-review-ready ${p.head.sha} ${p.base.sha}`);
    const fresh = await get(`pulls/${p.number}`);
    return { reviews, comments, repo, number: p.number, head: p.head.sha, base: p.base.sha, state: fresh.state, draft: fresh.draft,
      required: [...new Set(required.map((c) => c.context))], pins: required.map((c) => [c.context, c.integration_id]),
      checks: conn?.nodes, ciHead: commit?.oid, threads: g?.reviewThreads, risk, ready, fixReady: ready,
      observedChangeAt: fresh.updated_at, baseDependenciesChanged: true,
      externalReview: reviews.some((r) => r.user?.login === 'chatgpt-codex-connector[bot]') || comments.some((c) => /@codex\s+review/i.test(c.body || '')),
      evidenceComplete: !graph.errors && files.length === p.changed_files && conn?.pageInfo?.hasNextPage === false
        && g?.headRefOid === p.head.sha && g?.baseRefOid === p.base.sha && fresh.head.sha === p.head.sha && fresh.base.sha === p.base.sha };
};
}
if (import.meta.url === `file://${process.argv[1]}`) {
  const repo = process.env.GITHUB_REPOSITORY;
  const config = JSON.parse(fs.readFileSync(process.env.REVIEW_CONFIG || '.github/scripts/codex-review-fleet.json', 'utf8'));
  const policy = repositoryPolicy(config, repo), api = githubClient(request);
  const n = process.env.REVIEW_PR;
  if (!/^[1-9]\d*$/.test(n || '')) throw new Error('specify one PR; bulk sweeps disabled');
  const s = await reader(api, repo, policy)(Number(n)), plan = decide(s, emptyLedger());
  console.log(JSON.stringify({ repo, number: Number(n), head: s.head, base: s.base, ...plan,
    unresolvedFindings: plan.unresolvedFindings?.map((t) => t.id), dryRun: true, liveEnabled: false }));
}
