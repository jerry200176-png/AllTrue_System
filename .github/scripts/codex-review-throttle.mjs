// Trusted-base controller. Live entry is admission-gated and disabled by checked-in policy.
import fs from 'node:fs';
import { randomBytes } from 'node:crypto';
import path from 'node:path';
import { fromPinned, checkStates, unresolvedCount } from './land-queue.mjs';

export const POLICY = 'codex-throttle-v1';
const SHA = /^[a-f0-9]{40}$/;
const STATES = new Set(['requested', 'running', 'completed', 'failed']);
export const keyOf = (s) => [s.repo, s.number, s.head, s.base, POLICY].join(':');
export const emptyLedger = () => ({ version: 1, entries: [] });

export function validateLedger(ledger) {
  if (ledger?.version !== 1 || !Array.isArray(ledger.entries)) throw new Error('ledger unknown');
  if (ledger.external !== undefined && (!Array.isArray(ledger.external) || ledger.external.some((e) => !/^(review|comment):[1-9]\d*$/.test(e.id) || !['completed', 'running', 'unknown'].includes(e.state) || !Number.isInteger(e.number) || e.number < 1 || !/^[\w.-]+\/[\w.-]+$/.test(e.repo) || (e.head !== null && !SHA.test(e.head))))) throw new Error('external ledger corrupt');
  const keys = new Set();
  for (const e of ledger.entries) {
    if (!e || !STATES.has(e.state) || !e.policy || !SHA.test(e.head) || !SHA.test(e.base)
      || !Number.isInteger(e.number) || e.number < 1 || !/^[\w.-]+\/[\w.-]+$/.test(e.repo)
      || e.key !== [e.repo, e.number, e.head, e.base, e.policy].join(':') || keys.has(e.key)) throw new Error('ledger corrupt');
    keys.add(e.key);
  }
  return ledger;
}

// Missing/corrupt durable state fails closed; bootstrap is an explicit operator step.
// Caller must hold a repository-wide lock across load/readback/reserve/send/save.
export class FileLedger {
  constructor(path) { this.path = path; }
  async exclusive(work) {
    const fd = fs.openSync(`${this.path}.lock`, 'wx', 0o600);
    try { return await work(); } finally { fs.closeSync(fd); fs.unlinkSync(`${this.path}.lock`); }
  }
  load() { return validateLedger(JSON.parse(fs.readFileSync(this.path, 'utf8'))); }
  save(ledger) {
    validateLedger(ledger);
    const tmp = `${this.path}.tmp`;
    const fd = fs.openSync(tmp, 'wx', 0o600);
    try { fs.writeFileSync(fd, JSON.stringify(ledger)); fs.fsyncSync(fd); } finally { fs.closeSync(fd); }
    fs.renameSync(tmp, this.path);
    const dir = fs.openSync(path.dirname(this.path), 'r');
    try { fs.fsyncSync(dir); } finally { fs.closeSync(dir); }
  }
}

export function decide(s, ledger, now = Date.now()) {
  validateLedger(ledger);
  const hold = (reason) => ({ action: 'hold', reason, reviewPassed: false });
  if (!/^[\w.-]+\/[\w.-]+$/.test(s.repo) || !Number.isInteger(s.number) || s.number < 1
    || !SHA.test(s.head) || !SHA.test(s.base)) return hold('identity-unknown');
  if (s.state !== 'open' || s.draft !== false) return hold('not-ready-pr');
  if (s.evidenceComplete !== true || s.externalReview !== false || unresolvedCount(s.threads) === null) return hold('evidence-unknown');
  const outside = (ledger.external || []).filter((e) => e.repo === s.repo && e.number === s.number);
  if (outside.some((e) => e.state !== 'completed')) return hold('external-review-unknown');
  const entries = ledger.entries.filter((e) => e.repo === s.repo && e.number === s.number);
  if (entries.some((e) => e.key === keyOf(s))) return hold('already-recorded');
  if (entries.some((e) => ['requested', 'running'].includes(e.state))) return hold('review-in-flight');
  // Failed/uncertain requests consume a slot too. Policy/base changes never reset this budget.
  if (entries.length + outside.length >= 2) return hold('round-limit');
  const last = entries.at(-1) || outside.at(-1);
  if (last && (last.state !== 'completed' || last.head === s.head)) return hold('no-consolidated-fix');
  if (last && s.fixReady !== true) return hold('fix-not-ready');
  const age = now - Date.parse(s.observedChangeAt);
  if (s.ready !== true && (!Number.isFinite(age) || age < 15 * 60e3)) return hold('settling');
  if (!Array.isArray(s.required) || !s.required.length || s.ciHead !== s.head) return hold('ci-unknown');
  const checks = fromPinned(s.checks, new Map(s.pins), s.repo);
  // Reuse pinned producer selection and latest-attempt reduction, but neutral/skipped are NOT green here.
  if (checks.some((c) => !Number.isFinite(Date.parse(c.startedAt)))) return hold('ci-time-unknown');
  const strict = checks.map((c) => ({ ...c, conclusion: c.status === 'COMPLETED' && c.conclusion === 'SUCCESS' ? 'SUCCESS' : 'UNKNOWN' }));
  if (Object.values(checkStates(strict, s.required)).some((v) => v !== 'pass')) return hold('ci-not-green');
  if (!Number.isInteger(s.risk?.machine_minimum_tier)) return hold('risk-unknown');
  const full = !last || s.risk.machine_minimum_tier >= 3 || s.base !== last.base || s.baseDependenciesChanged !== false
    || /financial|billing|auth|entitlement|deduct|concurren|migration|control.?plane/i.test((s.risk.reasons || []).join(' '));
  return { repo: s.repo, number: s.number, head: s.head, base: s.base, action: 'candidate', key: keyOf(s), reviewPassed: false, round: entries.length + outside.length + 1,
    scope: full ? 'full' : 'incremental', from: last?.head || null, to: s.head,
    unresolvedFindings: s.threads.nodes.filter((t) => !t.isResolved || t.comments.totalCount < 2) };
}

// Called by fixtures and the disabled-by-default, trusted-main live runner.
// reserve is persisted BEFORE send. A throw leaves requested (uncertain), never a retryable blank.
export async function control(options) {
  if (options.dryRun !== false) return run(options);
  if (!options.store.exclusive) throw new Error('durable exclusive lock required');
  return options.store.exclusive(() => run(options));
}
async function run({ read, store, transport, dryRun = true, now = Date.now() }) {
  let ledger = await store.load();
  let s = await read();
  ledger = await store.load();
  // Always reconcile persisted uncertainty by reading the remote marker, never by resending.
  if (!dryRun && transport) {
    for (const e of ledger.entries.filter((e) => e.repo === s.repo && e.number === s.number && ['requested', 'running'].includes(e.state))) {
      const remote = await transport.lookup(e);
      if (remote && STATES.has(remote.state)) { e.state = remote.state; await store.save(ledger); }
    }
  }
  let plan = decide(s, ledger, now);
  if (plan.action !== 'candidate' || dryRun) return { ...plan, dryRun };
  if (!transport?.verified) return { action: 'hold', reason: 'transport-unverified', reviewPassed: false };
  const fresh = await read(); // immediate head/base AND CI/state revalidation before reservation
  if (fresh.head !== s.head || fresh.base !== s.base) return { action: 'hold', reason: 'refs-moved', reviewPassed: false };
  s = fresh;
  ledger = await store.load();
  plan = decide(s, ledger, now);
  if (plan.action !== 'candidate') return plan;
  plan = { ...plan, nonce: randomBytes(16).toString('hex') };
  const e = { nonce: plan.nonce, repo: s.repo, number: s.number, head: s.head, base: s.base, policy: POLICY,
    key: plan.key, state: 'requested', round: plan.round, requestedAt: new Date(now).toISOString(), requestBody: transport.body?.(plan) };
  ledger.entries.push(e);
  await store.save(ledger);
  try {
    await transport.send(plan, e); // must carry exact immutable refs, delta and unresolved findings
    const remote = await transport.lookup(e);
    if (remote && STATES.has(remote.state)) { e.state = remote.state; await store.save(ledger); }
  } catch {
    // A failed POST/response is ambiguous. One readback, no blind retry, even across restarts.
    const remote = await transport.lookup(e);
    if (remote && STATES.has(remote.state)) { e.state = remote.state; await store.save(ledger); }
  }
  return { ...plan, action: e.state, dryRun: false, reviewPassed: false };
}
