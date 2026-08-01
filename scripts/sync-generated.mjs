#!/usr/bin/env node
/** Canonical generated sync. `npm run sync:generated` | `--check` */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const check = process.argv.includes('--check');
const STEPS = [
  { source: 'docs/CHANGELOG.md', artifacts: ['frontend/src/lib/changelogDraft.generated.js'], run: ['node', 'scripts/changelog-to-release-notes.mjs'] },
  { source: 'docs/STAFF_UPDATES.yml', artifacts: ['frontend/src/lib/staffUpdates.generated.js'], run: ['node', 'scripts/staff-updates-to-js.mjs'] },
  { source: 'docs/PARENT_UPDATES.yml', artifacts: ['frontend/src/lib/parentUpdates.generated.js'], run: ['node', 'scripts/parent-updates-to-js.mjs'] },
];

function run(cmd, args) {
  const r = spawnSync(cmd, args, { cwd: ROOT, encoding: 'utf8' });
  if (r.status !== 0) {
    process.stderr.write(r.stderr || r.stdout || `${cmd} failed\n`);
    process.exit(r.status || 1);
  }
}
function snap(files) {
  const o = {};
  for (const f of files) {
    const p = path.join(ROOT, f);
    o[f] = fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : null;
  }
  return o;
}

if (check) {
  const before = Object.assign({}, ...STEPS.map((s) => snap(s.artifacts)));
  for (const s of STEPS) run(s.run[0], s.run.slice(1));
  const drifted = [];
  for (const s of STEPS) {
    const after = snap(s.artifacts);
    for (const art of s.artifacts) {
      if (before[art] !== after[art]) drifted.push({ source: s.source, artifact: art });
    }
  }
  for (const [f, content] of Object.entries(before)) {
    const p = path.join(ROOT, f);
    if (content === null) { if (fs.existsSync(p)) fs.unlinkSync(p); }
    else fs.writeFileSync(p, content);
  }
  if (drifted.length) {
    for (const d of drifted) console.error(`DRIFT source=${d.source} artifact=${d.artifact}`);
    console.error('Fix: npm run sync:generated && git add frontend/src/lib/*.generated.js');
    process.exit(1);
  }
  console.log('sync-generated --check: OK');
  process.exit(0);
}
for (const s of STEPS) { console.log('sync:', s.source); run(s.run[0], s.run.slice(1)); }
console.log('sync-generated: OK');
