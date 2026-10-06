#!/usr/bin/env node
/**
 * Regenerate the (git-ignored) in-app release-note bundles from legacy files + fragments:
 *   docs/CHANGELOG.md + docs/changes/*.md          -> changelogDraft.generated.js
 *   docs/STAFF_UPDATES.yml + docs/staff-updates/   -> staffUpdates.generated.js
 *   docs/PARENT_UPDATES.yml + docs/parent-updates/ -> parentUpdates.generated.js
 * `npm run sync:generated`. `--check` is kept for gates: it just regenerates (fails on a bad fragment);
 * nothing generated is committed, so there is no drift to compare.
 */
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
for (const script of ['changelog-to-release-notes', 'staff-updates-to-js', 'parent-updates-to-js']) {
  const r = spawnSync('node', [`scripts/${script}.mjs`], { cwd: ROOT, encoding: 'utf8' });
  if (r.status !== 0) {
    process.stderr.write(r.stderr || r.stdout || `${script} failed\n`);
    process.exit(r.status || 1);
  }
}
console.log('sync-generated: OK');
