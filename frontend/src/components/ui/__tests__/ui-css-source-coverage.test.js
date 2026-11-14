import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { resolve, relative, sep } from 'node:path';

// ui.css uses `source(none)` + explicit `@source` lines, so a file that uses `tw:` utilities but is not listed gets
// no utilities at all and renders unstyled with no error (學生管理 search box, PR 5307). This guard fails the build
// when any .vue/.js/.ts file under src uses a `tw:` class without an `@source` that covers it.
const SRC = resolve(__dirname, '../../..');
const TW_CLASS = /(^|[\s"'`{(:])(?:[a-z0-9-]+:)*tw:[a-z!\[-]/m;

const walk = (dir) => readdirSync(dir).flatMap((name) => {
  const p = resolve(dir, name);
  if (name === 'node_modules' || name === '__tests__') return [];
  if (statSync(p).isDirectory()) return walk(p);
  return /\.(vue|js|ts)$/.test(name) && !/\.test\.js$/.test(name) ? [p] : [];
});

const sources = [...readFileSync(resolve(SRC, 'ui.css'), 'utf8').matchAll(/^@source\s+"([^"]+)";/gm)].map((m) => resolve(SRC, m[1]));
const isCovered = (file) => sources.some((s) => file === s || file.startsWith(s + sep));

describe('ui.css @source coverage', () => {
  it('lists at least one @source', () => {
    expect(sources.length).toBeGreaterThan(0);
  });
  it('covers every file that uses a tw: utility class', () => {
    const users = walk(SRC).filter((f) => TW_CLASS.test(readFileSync(f, 'utf8')));
    expect(users.length).toBeGreaterThan(0);
    const missing = users.filter((f) => !isCovered(f)).map((f) => relative(SRC, f));
    expect(missing, `add @source "./<path>"; to src/ui.css for: ${missing.join(', ')}`).toEqual([]);
  });
  it('points every @source at an existing path', () => {
    const gone = sources.filter((s) => { try { statSync(s); return false; } catch { return true; } }).map((s) => relative(SRC, s));
    expect(gone).toEqual([]);
  });
});
