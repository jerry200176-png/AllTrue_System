// Control-plane rule P2 (2026-10-15, closes #4143): every job that can read the production SSH
// secrets must declare `environment: production-ssh`. That environment only admits refs/heads/main
// (deployment branch policy, docs/RUNBOOK_PRODUCTION_SSH_ENVIRONMENT.md), so GitHub itself refuses
// the job on any other ref. This replaces reasoning about trigger shapes: a job without the
// environment never sees the secret, whatever starts it.
//
// Secrets covered (case-insensitive): secrets.PI_* (PI_SSH_KEY, PI_SSH_HOST, PI_SSH_USER,
// PI_HOST_KEY, PI_HOST, PI_USER, ...), any *DEPLOY_KEY* / *SSH*KEY*, the index form
// secrets['X'], a dynamic secrets[expr], toJSON(secrets) and `secrets: inherit`.
//
// Documented exceptions (DOCUMENTED_EXCEPTIONS): jobs that already declare a reviewer-gated
// environment keep it, because a job can declare only one. Those environments must ALSO hold the
// same SSH secrets and a main-only branch policy (runbook step "Existing environments").
// A `uses:` job that calls a local reusable workflow with `secrets: inherit` passes; the called
// workflow's jobs are checked in their own file. Anything this parser cannot read fails closed.
// Dependency-free on purpose: control-plane-lint runs before `npm ci`.
// NOTE: PR #4140 adds the P1 trigger rule to this same file; merge both when rebasing.

export const REQUIRED_ENVIRONMENT = 'production-ssh';
export const DOCUMENTED_EXCEPTIONS = Object.freeze(['production-activation']);

const NAME = String.raw`(?:PI_[A-Z0-9_]*|[A-Z0-9_]*DEPLOY_KEY[A-Z0-9_]*|[A-Z0-9_]*SSH[A-Z0-9_]*KEY[A-Z0-9_]*)`;
const SECRET_PATTERNS = [
  new RegExp(String.raw`\bsecrets\.${NAME}\b`, 'i'),
  new RegExp(String.raw`\bsecrets\[\s*['"]${NAME}['"]\s*\]`, 'i'),
  /\bsecrets\[(?!\s*['"])/i, // dynamic index: any secret
  /\btoJSON\(\s*secrets\s*\)/i, // every secret
  /^\s*secrets:\s*inherit\b/im, // reusable-workflow caller passes every secret
];
const ALIAS = /(?::\s+|-\s+|<<:\s*)\*[A-Za-z_][A-Za-z0-9_-]*\s*(?:#.*)?$/m;

export const referencesProductionSecret = (text) => SECRET_PATTERNS.some((p) => p.test(text));

const indentOf = (line) => line.length - line.trimStart().length;
const meaningful = (line) => line.trim() !== '' && !line.trimStart().startsWith('#');
const stripComment = (v) => v.replace(/\s+#.*$/, '').trim();
const unquote = (v) => v.trim().replace(/^(['"])(.*)\1$/, '$2');

/** Splits a workflow into top-level (non-job) text and each job's block; null when unreadable. */
export function jobBlocks(text) {
  const lines = text.split('\n');
  const start = lines.findIndex((l) => /^(?:jobs|"jobs"|'jobs'):\s*(?:#.*)?$/.test(l));
  if (start < 0) return null;
  let end = start + 1;
  while (end < lines.length && (!meaningful(lines[end]) || indentOf(lines[end]) > 0)) end++;
  const body = lines.slice(start + 1, end).map((t, i) => ({ t, no: start + 2 + i }));
  const first = body.find((l) => meaningful(l.t));
  if (!first) return null;
  const jobIndent = indentOf(first.t);
  const jobs = [];
  for (const l of body) {
    if (!meaningful(l.t)) {
      if (jobs.length) jobs[jobs.length - 1].lines.push(l.t);
      continue;
    }
    const ind = indentOf(l.t);
    if (ind < jobIndent) return null;
    if (ind === jobIndent) {
      const m = stripComment(l.t.trim()).match(/^(['"]?)([A-Za-z_][A-Za-z0-9_-]*)\1:$/);
      if (!m) return null;
      jobs.push({ id: m[2], line: l.no, lines: [] });
    } else jobs[jobs.length - 1].lines.push(l.t);
  }
  const outside = [...lines.slice(0, start), ...lines.slice(end)].join('\n');
  return { outside, jobs: jobs.map((j) => ({ id: j.id, line: j.line, lines: j.lines })) };
}

/** Job-level property `key` → {value, children} at the job's own indent, or null. */
function jobProperty(jobLines, key) {
  const first = jobLines.find(meaningful);
  if (!first) return null;
  const propIndent = indentOf(first);
  for (let i = 0; i < jobLines.length; i++) {
    const l = jobLines[i];
    if (!meaningful(l) || indentOf(l) !== propIndent) continue;
    const m = stripComment(l.trim()).match(/^(['"]?)([A-Za-z_-]+)\1:(?:\s+(.*))?$/);
    if (!m || m[2] !== key) continue;
    const children = [];
    for (let j = i + 1; j < jobLines.length && (!meaningful(jobLines[j]) || indentOf(jobLines[j]) > propIndent); j++) {
      if (meaningful(jobLines[j])) children.push(jobLines[j]);
    }
    return { value: (m[3] || '').trim(), children };
  }
  return null;
}

/** The literal environment name a job declares; '' when absent; null when not statically readable. */
export function environmentName(jobLines) {
  const p = jobProperty(jobLines, 'environment');
  if (!p) return '';
  let name;
  if (p.value) {
    const flow = p.value.match(/^\{(.*)\}$/);
    if (flow) {
      const n = flow[1].match(/(?:^|,)\s*name\s*:\s*([^,}]+)/);
      name = n ? n[1] : null;
    } else name = p.value;
  } else {
    const childIndent = p.children.length ? indentOf(p.children[0]) : 0;
    const n = p.children
      .filter((c) => indentOf(c) === childIndent)
      .map((c) => stripComment(c.trim()).match(/^name:\s*(.+)$/))
      .find(Boolean);
    name = n ? n[1] : null;
  }
  if (name == null) return null;
  name = unquote(stripComment(name));
  return /^[A-Za-z0-9_.-]+$/.test(name) ? name : null; // expressions are not statically provable
}

function callsLocalWorkflowWithInherit(jobLines) {
  const uses = jobProperty(jobLines, 'uses');
  const secrets = jobProperty(jobLines, 'secrets');
  return Boolean(uses && /^['"]?\.\/\.github\/workflows\/[^\s'"]+\.ya?ml['"]?$/.test(stripComment(uses.value))
    && secrets && stripComment(secrets.value) === 'inherit');
}

/** Violations [{job, line, reason}] for one workflow file's text (empty when it holds no SSH secret). */
export function checkJobEnvironments(text) {
  if (!referencesProductionSecret(text)) return [];
  if (ALIAS.test(text)) {
    return [{ job: '*', line: 1, reason: 'YAML alias in a production SSH secret workflow; cannot prove which job reads the secret, fails closed' }];
  }
  const parsed = jobBlocks(text);
  if (!parsed) return [{ job: '*', line: 1, reason: 'unreadable jobs: map; fails closed' }];
  const problems = [];
  if (referencesProductionSecret(parsed.outside)) {
    problems.push({ job: '*', line: 1, reason: 'production SSH secret referenced outside jobs (workflow-level env/defaults); move it into an environment-gated job' });
  }
  const allowed = new Set([REQUIRED_ENVIRONMENT, ...DOCUMENTED_EXCEPTIONS]);
  for (const job of parsed.jobs) {
    const jobText = job.lines.join('\n');
    if (!referencesProductionSecret(jobText)) continue;
    const env = environmentName(job.lines);
    if (env && allowed.has(env)) continue;
    if (env === '' && callsLocalWorkflowWithInherit(job.lines)) continue;
    const reason = env === null ? 'environment is not a literal name'
      : env === '' ? 'no environment declared' : `environment "${env}" is not main-restricted for SSH secrets`;
    problems.push({ job: job.id, line: job.line, reason });
  }
  return problems;
}
