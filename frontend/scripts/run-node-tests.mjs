// W14 (#4953/#4952): run EVERY plain-node `*.test.js` under frontend/src with Node's built-in test runner.
// Files that import vitest are not node tests: they must be matched by the vitest include (vitest.config.js);
// scripts/tests/frontend-test-coverage.guard.test.mjs fails when a test file is in neither group.
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const FRONTEND = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export const walkTests = (dir = path.join(FRONTEND, 'src')) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
  const p = path.join(dir, e.name);
  if (e.isDirectory()) return e.name === 'node_modules' ? [] : walkTests(p);
  return e.name.endsWith('.test.js') ? [path.relative(FRONTEND, p).split(path.sep).join('/')] : [];
});
export const isVitestFormat = (rel) => /from\s+['"]vitest['"]/.test(fs.readFileSync(path.join(FRONTEND, rel), 'utf8'));
export const collectNodeTests = () => walkTests().filter((t) => !isVitestFormat(t)).sort();

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const files = collectNodeTests();
  if (!files.length) { console.error('no node tests found; refusing to pass an empty run'); process.exit(1); }
  console.log(`node --test: ${files.length} plain-node test files`);
  process.exit(spawnSync(process.execPath, ['--test', ...files], { cwd: FRONTEND, stdio: 'inherit' }).status ?? 1);
}
