#!/usr/bin/env node
// Route-aware bundle budget telemetry (#1273).
//
// Historically the "Bundle size budget" CI step compared *all* route-split
// CSS combined against a 200 KB budget meant for initial-load cost. Because
// most CSS is lazy route CSS (code-split per page), that comparison was
// noise: it warned on every CI run regardless of what a user actually
// downloads on first load. This script reports initial / largest-lazy-route
// / total separately for both CSS and JS, and only budgets what a user
// actually pays for on first load (initial chunk) or on navigating to the
// single heaviest route (largest lazy chunk) — never the noisy aggregate.
//
// A Vite output-naming change (e.g. the entry chunk no longer named
// `index-*`) must fail loudly here, not silently report a 0 KB metric that
// reads as "healthy". See summarizeAssets().

import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

// Budgets, in KB, calibrated against a fresh `main` build measured
// 2026-07-26 (frontend/dist_build/assets, 35 CSS chunks / 646,932 bytes
// total, 60 JS chunks / 1,565,648 bytes total). Raising any of these
// requires re-measuring main and documenting the new baseline here.
export const BUDGETS = Object.freeze({
  // Unchanged from the pre-#1273 step (ci.yml:511). Baseline ~204 KB
  // (index-DREhZXuJ.js); #1273 only addresses the CSS metric.
  jsInitialKB: 500,
  // Baseline ~102 KB (index-D8hhTwND.css). ~37% headroom for growth.
  cssInitialKB: 140,
  // Baseline ~68 KB (CourseManagement chunk, the heaviest single route CSS
  // on main). ~47% headroom. This is the budget the old code never had —
  // it compared the 630 KB *aggregate* against the 200 KB *initial* budget
  // instead of asking "how much CSS does the heaviest single route cost".
  cssLargestLazyKB: 100,
});

const toKB = (bytes) => Math.ceil(bytes / 1024);

/**
 * Classify a flat list of build assets of one extension into initial
 * (entry-prefixed), largest-lazy (heaviest non-entry chunk, with filename),
 * and total. Pure — no filesystem access — so it is fully unit-testable.
 *
 * Throws if no file matches entryPrefix: a Vite naming change must be a
 * loud CI failure, not a silent 0 KB "all clear".
 */
export function summarizeAssets(files, { entryPrefix, label = '' } = {}) {
  if (!entryPrefix) throw new TypeError('entryPrefix is required');
  if (!Array.isArray(files)) throw new TypeError('files must be an array of {name, size}');

  const entryFiles = files.filter((file) => file.name.startsWith(entryPrefix));
  if (entryFiles.length === 0) {
    throw new Error(
      `no entry chunk found matching prefix "${entryPrefix}"${label ? ` for ${label}` : ''} — ` +
        'Vite output naming may have changed; refusing to report a silent 0 KB metric'
    );
  }

  const initial = entryFiles.reduce((sum, file) => sum + file.size, 0);
  const total = files.reduce((sum, file) => sum + file.size, 0);

  const lazyFiles = files.filter((file) => !file.name.startsWith(entryPrefix));
  const largestLazy = lazyFiles.reduce(
    (max, file) => (max === null || file.size > max.size ? { name: file.name, size: file.size } : max),
    null
  );

  return { initial, total, count: files.length, largestLazy };
}

/** List build assets of one extension in `dir`, excluding sourcemaps. */
function listAssets(dir, ext) {
  let entries;
  try {
    entries = fs.readdirSync(dir);
  } catch (err) {
    throw new Error(`cannot read assets directory "${dir}": ${err.message}`);
  }
  return entries
    .filter((name) => name.endsWith(`.${ext}`) && !name.endsWith('.map'))
    .map((name) => ({ name, size: fs.statSync(path.join(dir, name)).size }));
}

/** Build the full { css, js } report for a Vite `dist_build/assets` dir. */
export function buildReport(assetsDir) {
  return {
    css: summarizeAssets(listAssets(assetsDir, 'css'), { entryPrefix: 'index-', label: 'css' }),
    js: summarizeAssets(listAssets(assetsDir, 'js'), { entryPrefix: 'index-', label: 'js' }),
  };
}

function formatKB(bytes) {
  return `${toKB(bytes)} KB`;
}

/** Render the GitHub step-summary table + plain log line + warnings. */
function renderReport({ css, js }) {
  const summaryLines = [
    '### 📦 Bundle Size',
    '| Asset | Size |',
    '|---|---|',
    `| JS initial chunk | ${formatKB(js.initial)} |`,
    `| JS total (all chunks) | ${formatKB(js.total)} (${js.count} chunks) |`,
    `| CSS initial chunk | ${formatKB(css.initial)} |`,
    css.largestLazy
      ? `| CSS largest lazy-route chunk | ${formatKB(css.largestLazy.size)} (\`${css.largestLazy.name}\`) |`
      : '| CSS largest lazy-route chunk | n/a (no lazy routes) |',
    `| CSS total (all route chunks) | ${formatKB(css.total)} (${css.count} chunks) |`,
  ];
  const summaryMarkdown = `${summaryLines.join('\n')}\n`;

  const plainLine =
    `JS initial: ${formatKB(js.initial)}, total: ${formatKB(js.total)} (${js.count} chunks); ` +
    `CSS initial: ${formatKB(css.initial)}, largest lazy route: ${
      css.largestLazy ? `${formatKB(css.largestLazy.size)} (${css.largestLazy.name})` : 'n/a'
    }, total: ${formatKB(css.total)} (${css.count} chunks)`;

  const warnings = [];
  if (toKB(js.initial) > BUDGETS.jsInitialKB) {
    warnings.push(`Initial JS chunk ${toKB(js.initial)} KB 超過 ${BUDGETS.jsInitialKB} KB 預算`);
  }
  if (toKB(css.initial) > BUDGETS.cssInitialKB) {
    warnings.push(`Initial CSS ${toKB(css.initial)} KB 超過 ${BUDGETS.cssInitialKB} KB 預算`);
  }
  if (css.largestLazy && toKB(css.largestLazy.size) > BUDGETS.cssLargestLazyKB) {
    warnings.push(
      `Largest lazy-route CSS chunk ${css.largestLazy.name} (${toKB(css.largestLazy.size)} KB) 超過 ${BUDGETS.cssLargestLazyKB} KB 預算`
    );
  }

  return { summaryMarkdown, plainLine, warnings };
}

function main() {
  const assetsDir = process.argv[2];
  if (!assetsDir) {
    console.error('Usage: node scripts/bundle-budget.mjs <dist-assets-dir>');
    process.exit(2);
  }

  let report;
  try {
    report = buildReport(assetsDir);
  } catch (err) {
    // Metric-integrity failure (e.g. Vite renamed the entry chunk): fail
    // loudly rather than let a broken metric report "healthy".
    console.error(`ERROR: ${err.message}`);
    process.exit(1);
  }

  const { summaryMarkdown, plainLine, warnings } = renderReport(report);

  if (process.env.GITHUB_STEP_SUMMARY) {
    fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, summaryMarkdown);
  }
  console.log(summaryMarkdown);
  console.log(plainLine);
  for (const warning of warnings) {
    console.log(`::warning::${warning}`);
  }

  // Budget overruns are advisory (unchanged from the pre-#1273 step, which
  // ended `exit 0`): this script never fails CI on a size regression, only
  // on a metric-integrity failure above.
  process.exit(0);
}

const invokedPath = process.argv[1] ? pathToFileURL(process.argv[1]).href : '';
if (invokedPath === import.meta.url) {
  main();
}
