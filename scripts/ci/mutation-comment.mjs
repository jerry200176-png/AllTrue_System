// W12 (#4953): one sticky PR comment listing the mutants that SURVIVED (file:line + mutator), advisory only.
// Usage: node scripts/ci/mutation-comment.mjs --section php|js --input <report> [--pr N --repo o/r]  (no --pr: print only)
// php: Infection text log ("1) /abs/path.php:25    [M] Plus"); js: StrykerJS mutation-testing-elements JSON.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { pathToFileURL } from 'node:url';

export const MARK = '<!-- mutation-advisory:v1 -->';
const sectionMark = (s) => `<!-- mutation-advisory:${s} -->`;

export function parseInfection(text) {
  // Escaped (tests ran, mutant lived) and Not Covered (no test ran the line); Timed Out / Skipped / Killed / Errors are ignored.
  const section = (name) => text.match(new RegExp(`^${name} mutants:\\s*$([\\s\\S]*?)(?=^[A-Z][A-Za-z ]* mutants:\\s*$|(?![\\s\\S]))`, 'm'))?.[1] ?? '';
  const rows = (body, status) => [...body.matchAll(/^\s*\d+\)\s+(\S+?):(\d+)\s+\[M\]\s+(\w+)/gm)].map((m) => ({ file: m[1].replace(/^.*?\/(backend\/)?(?=app\/)/, 'backend/'), line: +m[2], mutator: m[3], ...(status && { status }) }));
  return [...rows(section('Escaped')), ...rows(section('Not Covered'), 'NoCoverage')];
}

export function parseStryker(json) {
  const out = [];
  for (const [file, f] of Object.entries(json?.files ?? {})) {
    for (const m of f.mutants ?? []) if (m.status === 'Survived' || m.status === 'NoCoverage') out.push({ file, line: m.location?.start?.line, mutator: m.mutatorName, status: m.status });
  }
  return out;
}

// The report comes from an artifact produced by PR code: treat every field as hostile. Only plain path and mutator
// characters are rendered (no backtick, newline, `<`, `@`, `[`, `!`), rows are capped, the body is length-capped.
export const MAX_ROWS = 50;
export const MAX_BODY = 60000; // GitHub rejects comments over 65536 characters
const SAFE_FILE = /^[A-Za-z0-9_./-]{1,200}$/;
const SAFE_MUTATOR = /^[A-Za-z0-9_]{1,60}$/;
const isSafe = (m) => SAFE_FILE.test(String(m.file)) && SAFE_MUTATOR.test(String(m.mutator)) && Number.isInteger(m.line) && m.line > 0 && m.line < 1e7;

export function renderSection(section, mutants) {
  const safe = mutants.filter(isSafe);
  const dropped = mutants.length - safe.length;
  const rows = safe.slice(0, MAX_ROWS).map((m) => `- \`${m.file}:${m.line}\` ${m.mutator}${m.status === 'NoCoverage' ? ' (no test covers it)' : ''}`);
  if (safe.length > MAX_ROWS) rows.push(`- +${safe.length - MAX_ROWS} more (see the workflow artifact)`);
  if (dropped) rows.push(`- ${dropped} entries with unexpected characters skipped`);
  const body = rows.length ? rows.join('\n') : 'No surviving mutants on the changed lines.';
  return `${sectionMark(section)}\n### ${section === 'php' ? 'PHP (Infection)' : 'JS (StrykerJS)'}: ${safe.length} surviving\n${body}\n${sectionMark(`${section}-end`)}`;
}

// Replace this section in the existing sticky body (or append); other sections stay.
export function mergeBody(existing, section, rendered) {
  const re = new RegExp(`${sectionMark(section)}[\\s\\S]*?${sectionMark(`${section}-end`)}`);
  if (existing?.includes(MARK)) return (re.test(existing) ? existing.replace(re, () => rendered) : `${existing}\n\n${rendered}`).slice(0, MAX_BODY);
  return `${MARK}\n## Surviving mutants (advisory)\nOn a guard line, add a test that fails for each before asking for R3 review (MERGE_SOP).\n\n${rendered}`.slice(0, MAX_BODY);
}

function main() {
  const arg = (n) => { const i = process.argv.indexOf(n); return i < 0 ? undefined : process.argv[i + 1]; };
  const section = arg('--section');
  const raw = fs.readFileSync(arg('--input'), 'utf8');
  const rendered = renderSection(section, section === 'php' ? parseInfection(raw) : parseStryker(JSON.parse(raw)));
  const [pr, repo] = [arg('--pr'), arg('--repo')];
  if (!pr || !repo) { console.log(rendered); return; }
  const gh = (...a) => execFileSync('gh', a, { encoding: 'utf8', maxBuffer: 64 << 20 });
  const all = JSON.parse(gh('api', `/repos/${repo}/issues/${pr}/comments?per_page=100`, '--paginate', '--slurp')).flat();
  const mine = all.find((c) => c.body?.includes(MARK) && c.user?.login === 'github-actions[bot]');
  const body = mergeBody(mine?.body, section, rendered);
  // Body goes through stdin as JSON: never argv, never shell.
  const input = JSON.stringify({ body });
  if (mine) execFileSync('gh', ['api', '-X', 'PATCH', `/repos/${repo}/issues/comments/${mine.id}`, '--input', '-'], { input, encoding: 'utf8' });
  else execFileSync('gh', ['api', '-X', 'POST', `/repos/${repo}/issues/${pr}/comments`, '--input', '-'], { input, encoding: 'utf8' });
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) main();
