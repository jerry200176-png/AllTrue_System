// W12 (#4953): diff-based mutation testing for StrykerJS. Prints one `--mutate` value per changed line range of the
// control-plane scripts ("file:start-end"), so a PR only mutates the lines it touched (Google-style diff mutation).
// Usage: node scripts/ci/stryker-diff-ranges.mjs <base>   -> lines on stdout, nothing when no file qualifies.
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

// Files whose guards the R3 self-check cares about; each has a node:test file in the Stryker command (stryker.config.json).
export const TARGETS = /^(\.github\/scripts\/(review-gate|land-queue|autoland)|scripts\/ci\/stryker-diff-ranges)\.mjs$/;

// diff: `git diff -U0` output. Returns ["file:start-end", ...] for added/changed lines of TARGETS (pure deletions skip).
export function rangesFromDiff(diff) {
  const out = [];
  let file = null;
  for (const line of diff.split('\n')) {
    const f = /^\+\+\+ b\/(.+)$/.exec(line);
    if (f) { file = TARGETS.test(f[1]) ? f[1] : null; continue; }
    const h = /^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/.exec(line);
    if (h && file) {
      const start = +h[1], len = h[2] === undefined ? 1 : +h[2];
      if (len > 0) out.push(`${file}:${start}-${start + len - 1}`);
    }
  }
  return out;
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const base = process.argv[2] || 'origin/main';
  const diff = execFileSync('git', ['diff', '-U0', '--no-color', `${base}...HEAD`, '--', '.github/scripts', 'scripts/ci'], { encoding: 'utf8', maxBuffer: 64 << 20 });
  console.log(rangesFromDiff(diff).join('\n'));
}
