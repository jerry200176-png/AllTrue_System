// Control-plane rule P1 (2026-10-15): a workflow that references production SSH secrets
// (PI_SSH_*, PI_HOST_KEY, any *DEPLOY_KEY* / *SSH*KEY*, or every secret via toJSON(secrets) /
// secrets[expr] / `secrets: inherit`) must not start from an unreviewed ref.
// Allowed: workflow_dispatch, schedule, workflow_run, workflow_call, repository_dispatch, and
// `push` whose `branches:` filter is exactly main. Rejected: push with no branch filter, any
// non-main branch, branches-ignore or tags; every pull_request (its `branches:` filter matches the
// BASE branch, so the unreviewed PR head still runs) and every pull_request_target.
// Only exception: a workflow whose sole secret references sit inside jobs gated with
// `if: ... github.event_name == 'schedule'` (no `||`), so its PR/push jobs never see the secret.
// Any `on:` shape this parser cannot read fails closed. Dependency-free on purpose:
// control-plane-lint runs before `npm ci`.

const NAME = String.raw`(?:PI_SSH_[A-Z0-9_]*|PI_HOST_KEY|[A-Z0-9_]*DEPLOY_KEY[A-Z0-9_]*|[A-Z0-9_]*SSH[A-Z0-9_]*KEY[A-Z0-9_]*)`;
const SECRET_PATTERNS = [
  new RegExp(String.raw`\bsecrets\s*\.\s*${NAME}\b`, 'i'), // whitespace around `.` is valid in ${{ }}
  new RegExp(String.raw`\bsecrets\s*\[\s*['"]${NAME}['"]\s*\]`, 'i'),
  /\bsecrets\s*\[(?!\s*['"])/i, // dynamic index: any secret
  /\btoJSON\s*\(\s*secrets\s*\)/i, // every secret
  /^\s*secrets:\s*inherit\b/im, // reusable-workflow caller passes every secret
];

export const referencesProductionSecret = (text) => SECRET_PATTERNS.some((p) => p.test(text));

const REF_EVENTS = new Set(['push', 'pull_request', 'pull_request_target']);
const PUSH_KEYS = new Set(['branches', 'paths', 'paths-ignore']);
const ON_KEY = /^(?:on|"on"|'on'):(.*)$/;
const BARE_KEY = /^([a-z_][a-z0-9_-]*):(?:\s+(.*?))?\s*$/;

const stripComment = (v) => v.replace(/\s+#.*$/, '').trim();
const unquote = (v) => v.trim().replace(/^['"]|['"]$/g, '');
const indentOf = (line) => line.length - line.trimStart().length;
const meaningful = (line) => line.trim() !== '' && !line.trimStart().startsWith('#');
const unreadable = (line, what) => ({ event: 'on', line, reason: `unreadable trigger shape (${what}); fails closed` });

/** Returns [{event, line, reason}] for every trigger that can start from a non-main ref. */
export function unsafeRefTriggers(text) {
  const lines = text.split('\n');
  const start = lines.findIndex((l) => ON_KEY.test(l));
  if (start < 0) return [unreadable(1, 'no top-level on: key')];
  const problems = [];
  const inline = stripComment(lines[start].match(ON_KEY)[1]);
  if (inline) {
    if (!/^(?:[a-z_]+|\[\s*[a-z_]+(?:\s*,\s*[a-z_]+)*\s*\])$/.test(inline)) return [unreadable(start + 1, inline)];
    for (const ev of inline.replace(/[[\]]/g, '').split(',').map((s) => s.trim())) {
      if (REF_EVENTS.has(ev)) problems.push(refProblem(ev, start + 1, 'no branch filter'));
    }
    return problems;
  }
  let end = start + 1;
  while (end < lines.length && (!meaningful(lines[end]) || indentOf(lines[end]) > 0)) end++;
  const block = lines.slice(start + 1, end).map((t, i) => ({ text: t, no: start + 2 + i }))
    .filter((l) => meaningful(l.text));
  if (block.length === 0) return [unreadable(start + 1, 'empty on:')];
  const eventIndent = indentOf(block[0].text);
  for (let i = 0; i < block.length; i++) {
    const { text: l, no } = block[i];
    if (indentOf(l) < eventIndent) return [unreadable(no, l.trim())];
    if (indentOf(l) > eventIndent) continue;
    const item = l.trim().match(/^-\s+([a-z_]+)$/);
    if (item) {
      if (REF_EVENTS.has(item[1])) problems.push(refProblem(item[1], no, 'no branch filter'));
      continue;
    }
    const m = stripComment(l.trim()).match(BARE_KEY);
    if (!m) {
      problems.push(unreadable(no, l.trim()));
      continue;
    }
    const [, event, value = ''] = m;
    if (!REF_EVENTS.has(event)) continue;
    if (event !== 'push') {
      problems.push(refProblem(event, no, `${event} runs the unreviewed PR head whatever its branch filter`));
      continue;
    }
    const body = [];
    for (let j = i + 1; j < block.length && indentOf(block[j].text) > eventIndent; j++) body.push(block[j]);
    const reason = pushFilterProblem(value, body);
    if (reason) problems.push(refProblem(event, no, reason));
  }
  return problems;
}

const refProblem = (event, line, reason) => ({ event, line, reason });

function pushFilterProblem(value, body) {
  if (value && value !== '{}' && value !== '~' && value !== 'null') return `unreadable flow-style filter "${value}"; fails closed`;
  if (body.length === 0) return 'no branch filter';
  const keyIndent = indentOf(body[0].text);
  let branches = null;
  for (let i = 0; i < body.length; i++) {
    const l = body[i].text;
    if (indentOf(l) < keyIndent) return `unreadable filter line "${l.trim()}"; fails closed`;
    if (indentOf(l) > keyIndent) continue;
    const m = stripComment(l.trim()).match(BARE_KEY);
    if (!m) return `unreadable filter line "${l.trim()}"; fails closed`;
    if (!PUSH_KEYS.has(m[1])) return `${m[1]} filter reaches non-main refs`;
    if (m[1] !== 'branches') continue;
    if (m[2]) {
      if (!/^\[.*\]$/.test(m[2])) return `unreadable branches value "${m[2]}"; fails closed`;
      branches = m[2].slice(1, -1).split(',').map(unquote);
    } else {
      branches = [];
      for (let j = i + 1; j < body.length && indentOf(body[j].text) > keyIndent; j++) {
        const item = stripComment(body[j].text.trim()).match(/^-\s+(.+)$/);
        if (!item) return `unreadable branches item "${body[j].text.trim()}"; fails closed`;
        branches.push(unquote(item[1]));
      }
    }
  }
  if (branches === null) return 'no branch filter';
  const v = branches.filter(Boolean);
  if (v.length === 0) return 'empty branch filter';
  const bad = v.filter((b) => b !== 'main');
  return bad.length ? `branch filter includes non-main ref(s): ${bad.join(', ')}` : null;
}

/** Splits a workflow into its top-level (non-job) text and each job's block. */
function jobBlocks(text) {
  const lines = text.split('\n');
  const start = lines.findIndex((l) => /^jobs:\s*(?:#.*)?$/.test(l));
  if (start < 0) return { outside: text, jobs: [] };
  let end = start + 1;
  while (end < lines.length && (!meaningful(lines[end]) || indentOf(lines[end]) > 0)) end++;
  const jobs = [];
  for (let i = start + 1; i < end; i++) {
    const m = lines[i].match(/^ {2}([A-Za-z0-9_-]+):\s*$/);
    if (m) jobs.push({ id: m[1], lines: [] });
    else if (jobs.length) jobs[jobs.length - 1].lines.push(lines[i]);
    else if (meaningful(lines[i])) return { outside: text, jobs: [] }; // unreadable jobs map: treat all as outside
  }
  const outside = [...lines.slice(0, start), ...lines.slice(end)].join('\n');
  return { outside, jobs: jobs.map((j) => ({ id: j.id, text: j.lines.join('\n') })) };
}

function scheduleOnly(jobText) {
  const m = jobText.match(/^ {4}if:\s*(.+)$/m);
  if (!m) return false;
  const cond = m[1].trim();
  return /github\.event_name\s*==\s*'schedule'/.test(cond) && !cond.includes('||');
}

/** Violations for one workflow file's text (empty when it holds no production SSH secret). */
export function checkWorkflow(text) {
  if (!referencesProductionSecret(text)) return [];
  const problems = unsafeRefTriggers(text);
  if (problems.length === 0) return [];
  const structural = problems.some((p) => p.event === 'on' || p.event === 'pull_request_target');
  const { outside, jobs } = jobBlocks(text);
  if (!structural && !referencesProductionSecret(outside)) {
    const exposed = jobs.filter((j) => referencesProductionSecret(j.text) && !scheduleOnly(j.text));
    if (exposed.length === 0) return [];
    return problems.map((p) => ({ ...p, reason: `${p.reason}; secret-bearing job(s): ${exposed.map((j) => j.id).join(', ')}` }));
  }
  return problems;
}
