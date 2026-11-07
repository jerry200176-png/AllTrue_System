// Guard (W14): every *.test.js under frontend/src is executed by CI. Plain-node files run through
// scripts/run-node-tests.mjs (`npm run test:lib-node`); files that import vitest must be in vitest.config.js `include`.
// 28 tests sat in neither place and never ran. This file is itself plain-node, so the node runner runs the guard.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { collectNodeTests, isVitestFormat, walkTests } from '../../scripts/run-node-tests.mjs';

const FRONTEND = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const cfg = fs.readFileSync(path.join(FRONTEND, 'vitest.config.js'), 'utf8');
const include = [...cfg.split('include: [')[1].split(']')[0].matchAll(/'([^']+)'/g)].map((m) => m[1]);
const inVitest = (t) => include.some((g) => path.matchesGlob(t, g));

const all = walkTests();
assert.ok(all.length > 300, 'test discovery looks broken');
const node = new Set(collectNodeTests());

const uncovered = all.filter((t) => !node.has(t) && !inVitest(t));
assert.deepEqual(uncovered, [], `these test files never run (not plain-node, not in the vitest include): ${uncovered.join(', ')}`);

// A vitest-format file under the node runner would fail with "vitest" import errors; a plain file in the vitest include
// fails with "No test suite found". Keep the two groups disjoint.
const wrongGroup = all.filter((t) => inVitest(t) && !isVitestFormat(t));
assert.deepEqual(wrongGroup, [], `plain-node tests listed in the vitest include: ${wrongGroup.join(', ')}`);

// Explicit include entries must exist (a typo silently runs nothing).
const missing = include.filter((g) => !/[*?]/.test(g) && !fs.existsSync(path.join(FRONTEND, g)));
assert.deepEqual(missing, [], `vitest include lists missing files: ${missing.join(', ')}`);

console.log(`ok: ${all.length} test files, ${node.size} plain-node, ${all.length - node.size} vitest`);
