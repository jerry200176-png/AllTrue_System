/**
 * Shared readers: frozen legacy file + one-file-per-change fragments.
 * PRs add NEW files under docs/changes, docs/staff-updates, docs/parent-updates;
 * they never edit docs/CHANGELOG.md / STAFF_UPDATES.yml / PARENT_UPDATES.yml.
 */
import fs from 'node:fs';
import path from 'node:path';

const FRAGMENT_RE = /^\d{4}-\d{2}-\d{2}-.+\.md$/;

function files(root, dir, re) {
  const abs = path.join(root, dir);
  if (!fs.existsSync(abs)) return [];
  return fs.readdirSync(abs).filter((f) => re.test(f)).sort();
}

/** Fragment files (docs/changes/<date>-<slug>.md), newest first. */
export function changeFragmentFiles(root) {
  return files(root, 'docs/changes', FRAGMENT_RE).reverse().map((f) => `docs/changes/${f}`);
}

/** CHANGELOG markdown: fragments (newest first) followed by the frozen legacy file. */
export function readChangelogText(root) {
  const parts = changeFragmentFiles(root).map((f) => fs.readFileSync(path.join(root, f), 'utf8').trimEnd());
  parts.push(fs.readFileSync(path.join(root, 'docs', 'CHANGELOG.md'), 'utf8'));
  return parts.join('\n\n');
}

/** YAML `updates:` text: legacy list followed by one list per fragment file (each may repeat `updates:`). */
export function readUpdatesYaml(root, legacyFile, dir) {
  const parts = [fs.readFileSync(path.join(root, 'docs', legacyFile), 'utf8')];
  for (const f of files(root, dir, /^[^.].*\.yml$/)) parts.push(fs.readFileSync(path.join(root, dir, f), 'utf8'));
  return parts.join('\n');
}
