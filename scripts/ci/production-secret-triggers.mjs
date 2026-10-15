// Control-plane rule P1 (2026-10-15): a workflow that references production SSH secrets
// (PI_SSH_*, PI_HOST_KEY, any *DEPLOY_KEY* / *SSH*KEY*) must not start from an unreviewed ref.
// Allowed: workflow_dispatch, schedule, workflow_run, workflow_call, repository_dispatch, and
// `push` / `pull_request` whose `branches:` filter is exactly main. Rejected: push or
// pull_request with no branch filter, any non-main branch, branches-ignore, tags, and every
// pull_request_target. Dependency-free on purpose: control-plane-lint runs before `npm ci`.

export const PRODUCTION_SSH_SECRET =
  /\bsecrets\.(?:PI_SSH_[A-Z0-9_]*|PI_HOST_KEY|[A-Z0-9_]*DEPLOY_KEY[A-Z0-9_]*|[A-Z0-9_]*SSH[A-Z0-9_]*KEY[A-Z0-9_]*)\b/;

const REF_EVENTS = new Set(['push', 'pull_request', 'pull_request_target']);
const ON_KEY = /^(?:on|"on"|'on'):\s*(.*?)\s*(?:#.*)?$/;

const unquote = (v) => v.trim().replace(/^['"]|['"]$/g, '');
const indentOf = (line) => line.length - line.trimStart().length;
const meaningful = (line) => line.trim() !== '' && !line.trimStart().startsWith('#');

/** Returns [{event, line, reason}] for every trigger that can start from a non-main ref. */
export function unsafeRefTriggers(text) {
  const lines = text.split('\n');
  const start = lines.findIndex((l) => ON_KEY.test(l));
  if (start < 0) return [];
  const problems = [];
  const inline = lines[start].match(ON_KEY)[1];
  if (inline) {
    // `on: push` or `on: [push, workflow_dispatch]` — no branch filter is possible.
    for (const ev of inline.replace(/[[\]]/g, '').split(',').map(unquote)) {
      if (REF_EVENTS.has(ev)) problems.push({ event: ev, line: start + 1, reason: 'no branch filter' });
    }
    return problems;
  }
  let end = start + 1;
  while (end < lines.length && (!meaningful(lines[end]) || indentOf(lines[end]) > 0)) end++;
  const block = lines.slice(start + 1, end).map((text, i) => ({ text, no: start + 2 + i }));
  const eventIndent = Math.min(...block.filter((l) => meaningful(l.text)).map((l) => indentOf(l.text)));
  for (let i = 0; i < block.length; i++) {
    const { text: l, no } = block[i];
    if (!meaningful(l) || indentOf(l) !== eventIndent) continue;
    const m = l.trim().match(/^([A-Za-z_]+):\s*(.*?)\s*(?:#.*)?$/);
    if (!m || !REF_EVENTS.has(m[1])) continue;
    const event = m[1];
    if (event === 'pull_request_target') {
      problems.push({ event, line: no, reason: 'pull_request_target is never allowed with production secrets' });
      continue;
    }
    const body = [];
    for (let j = i + 1; j < block.length && (!meaningful(block[j].text) || indentOf(block[j].text) > eventIndent); j++) {
      if (meaningful(block[j].text)) body.push(block[j].text);
    }
    const reason = branchFilterProblem(m[2], body);
    if (reason) problems.push({ event, line: no, reason });
  }
  return problems;
}

function branchFilterProblem(inlineValue, body) {
  if (inlineValue && inlineValue !== '{}') {
    const flow = inlineValue.match(/^\{\s*branches:\s*\[([^\]]*)\]\s*\}$/);
    if (!flow) return `unsupported inline filter "${inlineValue}"`;
    return mainOnly(flow[1].split(',').map(unquote));
  }
  if (body.length === 0) return 'no branch filter';
  const keyIndent = indentOf(body[0]);
  const keys = body.filter((l) => indentOf(l) === keyIndent && !l.trim().startsWith('-'));
  for (const k of keys) {
    const key = k.trim().split(':')[0];
    if (key === 'branches-ignore' || key === 'tags' || key === 'tags-ignore') return `${key} filter reaches non-main refs`;
  }
  const idx = body.findIndex((l) => indentOf(l) === keyIndent && /^\s*branches:/.test(l));
  if (idx < 0) return 'no branch filter';
  const inline = body[idx].trim().slice('branches:'.length).replace(/#.*$/, '').trim();
  if (inline) return mainOnly(inline.replace(/[[\]]/g, '').split(',').map(unquote));
  const values = [];
  for (let j = idx + 1; j < body.length && indentOf(body[j]) > keyIndent; j++) {
    values.push(unquote(body[j].trim().replace(/^-\s*/, '').replace(/\s+#.*$/, '')));
  }
  return mainOnly(values);
}

function mainOnly(values) {
  const v = values.filter(Boolean);
  if (v.length === 0) return 'empty branch filter';
  const bad = v.filter((b) => b !== 'main');
  return bad.length ? `branch filter includes non-main ref(s): ${bad.join(', ')}` : null;
}

/** Violations for one workflow file's text (empty when it holds no production SSH secret). */
export function checkWorkflow(text) {
  if (!PRODUCTION_SSH_SECRET.test(text)) return [];
  return unsafeRefTriggers(text);
}
