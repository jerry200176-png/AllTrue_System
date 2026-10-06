#!/usr/bin/env node
// Advisory: list other open PRs to main that touch the same files as this one,
// and files many recently merged PRs touched ("hot"). Always exits 0.
// Usage: node scripts/pr-overlap.mjs [PR_NUMBER] [--markdown]
//   no number -> current branch diff vs origin/main.
import { execFileSync } from 'node:child_process';

export const MARKER = '<!-- pr-overlap -->';
const HOT_MIN = 3; // merged PRs in the window that make a file "hot"

const sh = (cmd, args) => execFileSync(cmd, args, { encoding: 'utf8', maxBuffer: 256 << 20 });
const gh = (args) => JSON.parse(sh('gh', args));

// Other open PRs whose files intersect `files`. Returns [{...pr, overlap: [paths]}].
export function findOverlaps(files, prs, self) {
  const mine = new Set(files);
  return prs
    .filter((p) => p.number !== self)
    .map((p) => ({ ...p, overlap: p.files.filter((f) => mine.has(f)).sort() }))
    .filter((p) => p.overlap.length)
    .sort((a, b) => b.overlap.length - a.overlap.length || a.number - b.number);
}

// Files touched by >= min merged PRs, as [[path, count]] sorted by count.
export function hotFiles(mergedPrs, min = HOT_MIN) {
  const n = new Map();
  for (const p of mergedPrs) for (const f of new Set(p.files)) n.set(f, (n.get(f) || 0) + 1);
  return [...n].filter(([, c]) => c >= min).sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]));
}

export function render(overlaps, hot, files) {
  if (!overlaps.length) return ''; // hot files alone never trigger a comment
  const out = [];
  {
    out.push(`${overlaps.length} other open PR(s) touch the same files. Coordinate or wait before editing further.`, '');
    for (const p of overlaps) {
      out.push(`- #${p.number} ${p.title} (\`${p.headRefName}\`, ${p.author?.login ?? '?'}, updated ${p.updatedAt})`);
      for (const f of p.overlap) out.push(`  - \`${f}\``);
    }
  }
  const mine = new Set(files);
  const h = hot.filter(([f]) => mine.has(f));
  if (h.length) {
    out.push('', `Hot files (touched by ${HOT_MIN}+ PRs merged in the last 7 days):`);
    for (const [f, c] of h) out.push(`- \`${f}\` (${c})`);
  }
  return out.join('\n');
}

// gh pr list caps `files` at 100; fetch the full list for those.
function fullFiles(pr) {
  if (pr.files.length < 100) return pr.files.map((f) => f.path);
  return sh('gh', ['api', '--paginate', `repos/{owner}/{repo}/pulls/${pr.number}/files`, '--jq', '.[].filename']).split('\n').filter(Boolean);
}

function main() {
  const args = process.argv.slice(2);
  const markdown = args.includes('--markdown');
  const num = Number(args.find((a) => /^\d+$/.test(a))) || null;
  const fields = 'number,title,headRefName,author,updatedAt,files';
  const norm = (list) => list.map((p) => ({ ...p, files: fullFiles(p) }));
  const open = norm(gh(['pr', 'list', '--base', 'main', '--state', 'open', '--limit', '300', '--json', fields]));
  const since = new Date(Date.now() - 7 * 864e5).toISOString().slice(0, 10);
  const merged = norm(gh(['pr', 'list', '--base', 'main', '--state', 'merged', '--search', `merged:>=${since}`, '--limit', '300', '--json', 'number,files']));
  const files = num
    ? (open.find((p) => p.number === num) ?? norm([gh(['pr', 'view', String(num), '--json', fields])])[0]).files
    : sh('git', ['diff', '--name-only', 'origin/main...HEAD']).split('\n').filter(Boolean);
  const overlaps = findOverlaps(files, open, num);
  const text = render(overlaps, hotFiles(merged), files);
  if (markdown) console.log(overlaps.length ? `${MARKER}\n${text}` : ''); // empty = no comment
  else console.log(text || 'No overlapping open PRs.');
}

if (import.meta.url === `file://${process.argv[1]}`) {
  try { main(); } catch (e) { console.error(`pr-overlap: advisory check failed: ${e.message}`); }
}
