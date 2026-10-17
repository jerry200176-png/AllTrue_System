// Control-plane rule P3 (Founder Q2, 2026-10-15): every job that holds the production SSH secrets
// and WRITES production must wait for the Founder's approval in its own run. `production-ssh` has
// no required reviewer, so an agent that can call workflow_dispatch could otherwise write production
// unreviewed. `production-activation` has the Founder as required reviewer.
//
// Source of truth: `job_gates` in docs/governance/PRODUCTION_WORKFLOW_INVENTORY.json, keyed
// "<workflow file>:<job id>". Every secret-bearing job (P2 detection) must be listed there with
//   writes:   true | false   (false = strictly read-only on production; one-line justification)
//   gate:     how the write is gated (below)
//   evidence: file:line references that prove the classification
// Gates:
//   production-ssh         read-only jobs only (writes: false)
//   production-activation  the job itself declares the reviewer-gated environment
//   needs:<job>            the job `needs:` <job> in the same file, <job> declares production-activation,
//                          and the job's `if:` requires needs.<job>.result == 'success' with no `||`
//   founder-decision       an explicitly listed ungated writer (scheduled operations, the Founder 1A
//                          auto-deploy path). It is not exempt by being scheduled: it needs a non-empty
//                          `decision` plus every EXCEPTION_EVIDENCE field (authorization with its source,
//                          fixed write scope, idempotency, stop switch, audit trail, target isolation)
// Unlisted jobs, stale entries, and read-only jobs whose own text carries a strong write marker fail.
// Scripts a job ships to the Pi (base64 / scp) are not scanned; the evidence field covers them.

import { jobBlocks, environmentName, jobProperty, referencesProductionSecret } from './production-ssh-environment.mjs';

export const GATED_ENVIRONMENT = 'production-activation';
export const EXCEPTION_EVIDENCE = Object.freeze(['authorization', 'scope', 'idempotency', 'stop_switch', 'audit_trail', 'isolation']);
export const READ_ONLY_ENVIRONMENTS = Object.freeze(['production-ssh', GATED_ENVIRONMENT]);

// Strong write markers. A read-only job may not contain any of these in its own text.
export const WRITE_MARKERS = Object.freeze([
  /\bartisan\s+(?:migrate(?!:status)\b|optimize\b|config:cache|route:cache|opcache:reset|cache:clear|config:clear|down\b|up\b)/,
  /\bgit\s+(?:reset|pull|merge(?![-\w])|rebase|clean)\b/,
  /\bsystemctl\s+(?:restart|reload|stop|start|enable|disable)\b/,
  /\bALLOW_PROD_REPAIR=1\b/,
  /\s--apply\b/,
  /->(?:save|update|insert|insertGetId|upsert|delete|forceDelete|increment|decrement)\(/,
  /(?<!Request)::(?:create|insert|updateOrCreate|firstOrCreate|addComment|changeStatus)\(/,
  /-e\s*["'][^\n]*\b(?:CREATE|DROP|ALTER|TRUNCATE)\s+(?:DATABASE|TABLE|USER)\b/i, // mysql -e DDL
  /\bDB::statement\(/,
  /\b(?:INSERT\s+INTO|DELETE\s+FROM)\b|\bUPDATE\s+`?\w+`?\s+SET\b/i,
  /\bsed\s+-i\b/,
  /\brsync\b/,
  /\brm\s+-[a-z]*r[a-z]*\s+\/home\/admin/,
  /api\.line\.me|\/message\/push\b/,
  /\bcurl\b[^\n]*-X\s*(?:PUT|PATCH|DELETE)\b/, // POST is also used for logins and read APIs
]);

const hasSchedule = (text) => {
  const lines = text.split('\n');
  const start = lines.findIndex((l) => /^(?:on|"on"|'on'):/.test(l));
  if (start < 0) return false;
  if (/schedule/.test(lines[start])) return true;
  for (let i = start + 1; i < lines.length && (lines[i].trim() === '' || /^\s/.test(lines[i])); i++) {
    if (/^\s+schedule:/.test(lines[i])) return true;
  }
  return false;
};

const listValue = (prop) => {
  if (!prop) return [];
  const raw = prop.value
    ? prop.value.replace(/\s+#.*$/, '').replace(/^\[|\]$/g, '').split(',')
    : prop.children.map((c) => c.trim().replace(/^-\s*/, ''));
  return raw.map((s) => s.trim().replace(/^(['"])(.*)\1$/, '$2')).filter(Boolean);
};

const scalarValue = (prop) => (prop ? [prop.value, ...prop.children].join(' ').trim() : '');

/** The job_gates entries of one workflow file, keyed by job id. */
export function gatesForWorkflow(inventory, file) {
  const all = (inventory && typeof inventory.job_gates === 'object' && inventory.job_gates) || {};
  return Object.fromEntries(Object.entries(all)
    .filter(([key]) => key.startsWith(`${file}:`))
    .map(([key, value]) => [key.slice(file.length + 1), value]));
}

/** job_gates keys whose workflow file no longer exists (stale). */
export function staleGateKeys(inventory, files) {
  const known = new Set(files);
  return Object.keys((inventory && inventory.job_gates) || {}).filter((key) => !known.has(key.split(':')[0]));
}

/** Violations [{job, line, reason}] for one workflow file against its job_gates entries. */
export function checkWriteGates(text, classified = {}) {
  const parsed = referencesProductionSecret(text) ? jobBlocks(text) : { jobs: [] };
  if (!parsed) return [{ job: '*', line: 1, reason: 'unreadable jobs: map; fails closed' }];
  const problems = [];
  const secretJobs = parsed.jobs.filter((j) => referencesProductionSecret(j.lines.join('\n')));
  const byId = new Map(parsed.jobs.map((j) => [j.id, j]));
  for (const id of Object.keys(classified)) {
    if (!secretJobs.some((j) => j.id === id)) problems.push({ job: id, line: 1, reason: 'inventory entry is not a production-secret job in this file (stale)' });
  }
  for (const job of secretJobs) {
    const c = classified[job.id];
    const fail = (reason) => problems.push({ job: job.id, line: job.line, reason });
    if (!c) { fail('not classified in docs/governance/PRODUCTION_WORKFLOW_INVENTORY.json job_gates'); continue; }
    if (typeof c.writes !== 'boolean') { fail('writes must be true or false'); continue; }
    if (typeof c.evidence !== 'string' || !/\S+\.\w+:\d+/.test(c.evidence)) { fail('evidence needs file:line references'); continue; }
    const env = environmentName(job.lines);
    const gate = String(c.gate || '');
    if (!c.writes) {
      if (!READ_ONLY_ENVIRONMENTS.includes(gate) || env !== gate) fail(`read-only job gate "${gate}" must match its environment ("${env}")`);
      const jobText = job.lines.filter((l) => !l.trimStart().startsWith('#')).join('\n');
      const hit = WRITE_MARKERS.find((re) => re.test(jobText));
      if (hit) fail(`classified read-only but its text has a write marker ${hit}; reclassify as writes: true`);
      continue;
    }
    if (gate === GATED_ENVIRONMENT) {
      if (env !== GATED_ENVIRONMENT) fail(`writes production but declares "${env}"; declare environment: ${GATED_ENVIRONMENT}`);
    } else if (gate.startsWith('needs:')) {
      const gateId = gate.slice('needs:'.length);
      const gateJob = byId.get(gateId);
      const cond = scalarValue(jobProperty(job.lines, 'if'));
      if (!gateJob || environmentName(gateJob.lines) !== GATED_ENVIRONMENT) fail(`gate job ${gateId} must exist and declare ${GATED_ENVIRONMENT}`);
      else if (!listValue(jobProperty(job.lines, 'needs')).includes(gateId)) fail(`must list ${gateId} in needs`);
      else if (!cond.includes(`needs.${gateId}.result == 'success'`) || cond.includes('||')) fail(`if: must require needs.${gateId}.result == 'success' with no ||`);
    } else if (gate === 'founder-decision') {
      if (typeof c.decision !== 'string' || !c.decision.trim()) fail('founder-decision gate needs a decision text');
      const missing = EXCEPTION_EVIDENCE.filter((k) => typeof c[k] !== 'string' || !c[k].trim());
      if (missing.length) fail(`founder-decision exception lacks evidence: ${missing.join(', ')}`);
      if (c.schedule === true && !hasSchedule(text)) fail('marked as a scheduled writer but the workflow has no schedule trigger');
    } else {
      fail(`writes production with unknown gate "${gate}"`);
    }
  }
  return problems;
}
