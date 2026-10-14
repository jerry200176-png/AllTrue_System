// Only GET/GraphQL query operations. PR text is data, never shell/code/instructions.
import { execFileSync } from 'node:child_process';
import { decide, emptyLedger } from './codex-review-throttle.mjs';

const gh = (...args) => execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 32 << 20 });
const repo = process.env.GITHUB_REPOSITORY;
if (!/^[\w.-]+\/[\w.-]+$/.test(repo || '')) throw new Error('invalid repository');
const get = (path) => JSON.parse(gh('api', `/repos/${repo}/${path}`));
const pages = (path) => gh('api', `/repos/${repo}/${path}`, '--paginate', '--jq', '.[] | @json').trim().split('\n').filter(Boolean).map((line) => JSON.parse(line));
const [owner, name] = repo.split('/');
const query = `query($owner:String!,$name:String!,$n:Int!){repository(owner:$owner,name:$name){pullRequest(number:$n){
headRefOid baseRefOid reviewThreads(first:100){nodes{id isResolved comments{totalCount}} pageInfo{hasNextPage}}
commits(last:1){nodes{commit{oid statusCheckRollup{contexts(first:100){pageInfo{hasNextPage} nodes{
__typename ... on CheckRun{name status conclusion startedAt checkSuite{app{databaseId} repository{nameWithOwner}}}
}}}}}}}}}`;
const prArg = process.env.REVIEW_PR;
if (prArg && !/^[1-9]\d*$/.test(prArg)) throw new Error('invalid PR');
const prs = prArg ? [get(`pulls/${prArg}`)] : pages('pulls?state=open&base=main&per_page=100');
const rules = get('rules/branches/main').filter((r) => r.type === 'required_status_checks');
const required = rules.flatMap((r) => r.parameters.required_status_checks);
for (const item of prs) {
  try {
    const p = get(`pulls/${item.number}`);
    if (p.base.ref !== 'main') continue;
    const files = pages(`pulls/${p.number}/files?per_page=100`);
    // Reuse the existing Python classifier from the TRUSTED checkout, passing PR data on stdin.
    const risk = JSON.parse(execFileSync('python3', ['-B', '-c',
      'import json,sys; from scripts.governance.autonomy_gate import classify_scope; x=json.load(sys.stdin); print(json.dumps(classify_scope(x["paths"],x["patch"])))'],
    { input: JSON.stringify({ paths: files.flatMap((f) => [f.filename, f.previous_filename].filter(Boolean)),
      patch: files.map((f) => f.patch || '').join('\n') }), encoding: 'utf8' }));
    const graph = JSON.parse(gh('api', 'graphql', '-f', `query=${query}`, '-f', `owner=${owner}`, '-f', `name=${name}`, '-F', `n=${p.number}`));
    const g = graph.data?.repository?.pullRequest;
    const commit = g?.commits?.nodes?.[0]?.commit;
    const conn = commit?.statusCheckRollup?.contexts;
    const reviews = pages(`pulls/${p.number}/reviews?per_page=100`);
    const comments = pages(`issues/${p.number}/comments?per_page=100`);
    const ready = comments.some((c) => c.user?.login === p.user.login && ['OWNER', 'MEMBER', 'COLLABORATOR'].includes(c.author_association)
      && c.body?.trim() === `codex-review-ready ${p.head.sha} ${p.base.sha}`);
    const fresh = get(`pulls/${p.number}`);
    const s = { repo, number: p.number, head: p.head.sha, base: p.base.sha, state: fresh.state, draft: fresh.draft,
      required: [...new Set(required.map((c) => c.context))], pins: required.map((c) => [c.context, c.integration_id]),
      checks: conn?.nodes, ciHead: commit?.oid, threads: g?.reviewThreads, risk, ready, fixReady: ready,
      // updated_at is conservative: pushes update it; comments can defer a run unnecessarily.
      observedChangeAt: fresh.updated_at, baseDependenciesChanged: true,
      externalReview: reviews.some((r) => r.user?.login === 'chatgpt-codex-connector[bot]')
        || comments.some((c) => /@codex\s+review/i.test(c.body || '')),
      evidenceComplete: !graph.errors && files.length === p.changed_files && conn?.pageInfo?.hasNextPage === false
        && g?.headRefOid === p.head.sha && g?.baseRefOid === p.base.sha
        && fresh.head.sha === p.head.sha && fresh.base.sha === p.base.sha };
    // A simulation never creates request state. Durable live storage/transport remain uninstalled.
    const plan = decide(s, emptyLedger());
    console.log(JSON.stringify({ repo, number: p.number, head: s.head, base: s.base, ...plan,
      unresolvedFindings: plan.unresolvedFindings?.map((t) => t.id), dryRun: true, liveEnabled: false }));
  } catch { console.log(JSON.stringify({ number: item.number, action: 'hold', reason: 'read-failed', reviewPassed: false, dryRun: true })); }
}
