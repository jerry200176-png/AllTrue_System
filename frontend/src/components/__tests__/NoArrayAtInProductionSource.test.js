import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

// #3845: Array.prototype.at is missing on Safari < 15.4; Vite does not polyfill methods.
// Production source must use arr[arr.length - 1] instead of arr.at(-1).
const walk = (dir) => readdirSync(dir).flatMap((n) => {
  const p = join(dir, n);
  if (statSync(p).isDirectory()) return n === '__tests__' ? [] : walk(p);
  return /\.(vue|js|ts)$/.test(n) && !/\.(test|spec)\./.test(n) ? [p] : [];
});

describe('production source avoids Array.prototype.at', () => {
  it('has no .at( call', () => {
    const offenders = walk(join(__dirname, '..', '..')).filter((f) => /\.at\(/.test(readFileSync(f, 'utf8')));
    expect(offenders).toEqual([]);
  });
});
