// Shared adapters; no CLI, token discovery, scheduler or installed live transport.
import { validateLedger, POLICY } from './codex-review-throttle.mjs';
export function repositoryPolicy(config, repo, live = false) {
  const rows = config?.repositories?.filter((r) => r.repo === repo);
  if (config?.version !== 1 || config.policy !== POLICY || rows?.length !== 1) throw new Error('repository not uniquely configured');
  const r = rows[0];
  if (!r.base || !['alltrue', 'full'].includes(r.riskAdapter)) throw new Error('repository policy unverified');
  if (live && !(r.enabled === true && r.overrideOffVerified === true && r.canaryVerified === true)) throw new Error('live activation unverified');
  return r;
}
export class Backoff extends Error {
  constructor(until) { super('GitHub cooldown; stop this entire sweep'); this.until = until; }
}
// All repo callers share one client/cooldown. Never retry POST after a timeout.
export function githubClient(request, { now = Date.now, cooldown = { until: 0 }, persist = () => {} } = {}) {
  return async (method, route, body) => {
    if (now() < cooldown.until) throw new Backoff(cooldown.until);
    const r = await request(method, route, body);
    const h = Object.fromEntries(Object.entries(r.headers || {}).map(([k, v]) => [k.toLowerCase(), v]));
    if (r.body?.errors?.some((e) => e.type === 'RATE_LIMITED') || r.status === 429 || (r.status === 403 && (h['retry-after'] || h['x-ratelimit-remaining'] === '0' || /secondary rate limit/i.test(r.body?.message || '')))) {
      const retry = h['retry-after'];
      const at = retry && /^\d+$/.test(retry) ? now() + Number(retry) * 1000 : Date.parse(retry);
      cooldown.until = Math.max(now() + 60000, Number.isFinite(at) ? at : 0,
        h['x-ratelimit-remaining'] === '0' ? Number(h['x-ratelimit-reset']) * 1000 || 0 : 0);
      await persist(cooldown); throw new Backoff(cooldown.until);
    }
    if (!Number.isInteger(r.status) || r.status < 200 || r.status >= 300) throw new Error(`GitHub HTTP ${r.status}`);
    return r.body;
  };
}
// Pre-provisioned data-only branch, never main. Contents SHA is the remote CAS:
// stale/uncertain saves throw BEFORE send. Missing state never bootstraps empty.
export class GitHubLedger {
  constructor(api, repo) {
    if (!/^[\w.-]+\/[\w.-]+$/.test(repo)) throw new Error('invalid repository');
    this.api = api; this.repo = repo; this.root = `/repos/${repo}/contents/ledger.json`; this.branch = 'codex-review-state';
  }
  async exclusive(work) {
    if (this.busy) throw new Error('controller already active'); this.busy = true;
    try { return await work(); } finally { this.busy = false; }
  }
  async dataOnly() {
    const files = await this.api('GET', `${this.root.replace(/\/ledger\.json$/, '')}?ref=${this.branch}`);
    if (!Array.isArray(files) || files.length !== 1 || files[0].path !== 'ledger.json' || files[0].type !== 'file') throw new Error('state branch is not data-only');
  }
  async load() {
    await this.dataOnly();
    const f = await this.api('GET', `${this.root}?ref=${this.branch}`);
    if (f.type !== 'file' || f.encoding !== 'base64' || !/^[a-f0-9]{40}$/.test(f.sha)) throw new Error('ledger unknown');
    const ledger = validateLedger(JSON.parse(Buffer.from(f.content, 'base64').toString('utf8')));
    if ([...ledger.entries, ...(ledger.external || [])].some((e) => e.repo !== this.repo)) throw new Error('ledger repository mismatch');
    this.external = (ledger.external || []).map((e) => `${e.number}:${e.id}`); this.keys = ledger.entries.map((e) => e.key); this.sha = f.sha; return ledger;
  }
  async save(ledger) {
    await this.dataOnly();
    validateLedger(ledger);
    if ([...ledger.entries, ...(ledger.external || [])].some((e) => e.repo !== this.repo) || this.keys?.some((k, i) => ledger.entries[i]?.key !== k)) throw new Error('ledger history changed');
    if (this.external?.some((k) => !(ledger.external || []).some((e) => `${e.number}:${e.id}` === k))) throw new Error('external history removed');
    if (!this.sha) throw new Error('ledger not loaded');
    const result = await this.api('PUT', this.root, { branch: this.branch, sha: this.sha,
      message: 'Record Codex review state', content: Buffer.from(JSON.stringify(ledger)).toString('base64') });
    // Unknown PUT outcome is reconciled by a fresh load on the next run, never retried.
    if (!/^[a-f0-9]{40}$/.test(result.content?.sha)) throw new Error('ledger write uncertain');
    this.external = (ledger.external || []).map((e) => `${e.number}:${e.id}`); this.keys = ledger.entries.map((e) => e.key); this.sha = result.content.sha;
  }
}

// Invoke only after the bounded state-write decision. Never auto-provision or retry.
export async function verifyRemoteCas(api, repo, nonce) {
  if (!/^[a-f0-9]{32}$/.test(nonce)) throw new Error('probe nonce required');
  const first = new GitHubLedger(api, repo), second = new GitHubLedger(api, repo);
  const x = await first.load(), y = await second.load();
  if (x.entries.length || x.external?.length) throw new Error('probe requires unused ledger');
  x.casProbe = nonce; y.casProbe = `${nonce}-loser`; await first.save(x);
  let conflict = false;
  try { await second.save(y); } catch (e) { if (!/\b409\b/.test(e.message)) throw e; conflict = true; }
  const proof = await first.load();
  if (!conflict || proof.casProbe !== nonce) throw new Error('CAS service verification failed');
  return { repo, blob: first.sha, verified: true };
}

// Explicit post-decision provisioning only. Never overwrites an existing ref.
export async function bootstrapState(api, repo) {
  new GitHubLedger(api, repo); // validate repo before constructing any route
  const root = `/repos/${repo}/git`, ref = `${root}/ref/heads/codex-review-state`;
  try { await api('GET', ref); throw new Error('state already exists'); }
  catch (e) { if (!/\b404\b/.test(e.message)) throw e; }
  const tree = await api('POST', `${root}/trees`, { tree: [{ path: 'ledger.json', mode: '100644', type: 'blob', content: JSON.stringify({ version: 1, entries: [] }) }] });
  if (!/^[a-f0-9]{40}$/.test(tree.sha)) throw new Error('tree unknown');
  const commit = await api('POST', `${root}/commits`, { message: 'Initialize data-only review state', tree: tree.sha, parents: [] });
  if (!/^[a-f0-9]{40}$/.test(commit.sha)) throw new Error('commit unknown');
  try { await api('POST', `${root}/refs`, { ref: 'refs/heads/codex-review-state', sha: commit.sha }); }
  catch (e) { if ((await api('GET', ref)).object?.sha !== commit.sha) throw e; }
  return { repo, commit: commit.sha };
}
