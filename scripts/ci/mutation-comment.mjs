// W12 (#4953): one sticky PR comment listing the mutants that SURVIVED (file:line + mutator), advisory only.
// Usage: node scripts/ci/mutation-comment.mjs --section php|js --input <report> [--pr N --repo o/r]  (no --pr: print only)
// php: Infection text log ("1) /abs/path.php:25    [M] Plus"); js: StrykerJS mutation-testing-elements JSON.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { pathToFileURL } from 'node:url';

export const MARK = '<!-- mutation-advisory:v1 -->';
const sectionMark = (s) => `<!-- mutation-advisory:${s} -->`;

export function parseInfection(text) {
  return [...text.matchAll(/^\s*\d+\)\s+(\S+?):(\d+)\s+\[M\]\s+(\w+)/gm)].map((m) => ({ file: m[1].replace(/^.*?\/(backend\/)?(?=app\/)/, 'backend/'), line: +m[2], mutator: m[3] }));
}

export function parseStryker(json) {
  const out = [];
  for (const [file, f] of Object.entries(json?.files ?? {})) {
    for (const m of f.mutants ?? []) if (m.status === 'Survived' || m.status === 'NoCoverage') out.push({ file, line: m.location?.start?.line, mutator: m.mutatorName, status: m.status });
  }
  return out;
}

export function renderSection(section, mutants) {
  const body = mutants.length
    ? mutants.map((m) => `- \`${m.file}:${m.line}\` ${m.mutator}${m.status === 'NoCoverage' ? ' (no test covers it)' : ''}`).join('\n')
    : 'No surviving mutants on the changed lines.';
  return `${sectionMark(section)}\n### ${section === 'php' ? 'PHP (Infection)' : 'JS (StrykerJS)'}: ${mutants.length} surviving\n${body}\n${sectionMark(`${section}-end`)}`;
}

// Replace this section in the existing sticky body (or append); other sections stay.
export function mergeBody(existing, section, rendered) {
  const re = new RegExp(`${sectionMark(section)}[\\s\\S]*?${sectionMark(`${section}-end`)}`);
  if (existing?.includes(MARK)) return re.test(existing) ? existing.replace(re, () => rendered) : `${existing}\n\n${rendered}`;
  return `${MARK}\n## Surviving mutants (advisory)\nOn a guard line, add a test that fails for each before asking for R3 review (MERGE_SOP).\n\n${rendered}`;
}

function main() {
  const arg = (n) => process.argv[process.argv.indexOf(n) + 1];
  const section = arg('--section');
  const raw = fs.readFileSync(arg('--input'), 'utf8');
  const rendered = renderSection(section, section === 'php' ? parseInfection(raw) : parseStryker(JSON.parse(raw)));
  const [pr, repo] = [arg('--pr'), arg('--repo')];
  if (!pr || !repo) { console.log(rendered); return; }
  const gh = (...a) => execFileSync('gh', a, { encoding: 'utf8', maxBuffer: 64 << 20 });
  const all = JSON.parse(gh('api', `/repos/${repo}/issues/${pr}/comments?per_page=100`, '--paginate', '--slurp')).flat();
  const mine = all.find((c) => c.body?.includes(MARK) && c.user?.type === 'Bot');
  const body = mergeBody(mine?.body, section, rendered);
  if (mine) gh('api', '-X', 'PATCH', `/repos/${repo}/issues/comments/${mine.id}`, '-f', `body=${body}`);
  else gh('api', '-X', 'POST', `/repos/${repo}/issues/${pr}/comments`, '-f', `body=${body}`);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) main();
