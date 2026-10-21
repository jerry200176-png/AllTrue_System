// in-app #429 (GitHub #4331): adding lessons has one name, 「續課」 (Founder 2026-10-15 Q8=A).
// Fails when user-facing 「加購」 wording comes back anywhere in frontend/src (comments are ignored).
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { resolve, dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { RENEW_LESSONS_LABEL } from '../../lib/courseIntent.js';
import { courseActions } from '../../lib/courseActions.js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const walk = (dir) => readdirSync(dir).flatMap((name) => {
  const p = join(dir, name);
  if (statSync(p).isDirectory()) return name === '__tests__' ? [] : walk(p);
  return /\.(vue|js)$/.test(name) && !/\.test\.js$|\.generated\.js$/.test(name) ? [p] : [];
});
const stripComments = (src) => src
  .replace(/<!--[\s\S]*?-->/g, '')
  .replace(/\/\*[\s\S]*?\*\//g, '')
  .replace(/(^|[^:'"`\\])\/\/.*$/gm, '$1');

describe('one name for adding lessons (#4331)', () => {
  it('no user-facing 「加購」 wording remains in frontend/src', () => {
    const offenders = walk(root)
      .filter((f) => stripComments(readFileSync(f, 'utf8')).includes('加購'))
      .map((f) => relative(root, f));
    expect(offenders).toEqual([]);
  });

  it('no 「加購」 wording remains in backend/app responses', () => {
    const backend = resolve(root, '../../backend/app');
    // ContractRenewal.php is being edited by open PR #4338; its two messages are renamed once that lands.
    const pending = new Set(['Services/Billing/ContractRenewal.php']);
    const walkPhp = (dir) => readdirSync(dir).flatMap((name) => {
      const p = join(dir, name);
      return statSync(p).isDirectory() ? walkPhp(p) : (name.endsWith('.php') ? [p] : []);
    });
    const offenders = walkPhp(backend)
      .filter((f) => !pending.has(relative(backend, f)))
      .filter((f) => stripComments(readFileSync(f, 'utf8')).replace(/^\s*#.*$/gm, '').includes('加購'))
      .map((f) => relative(backend, f));
    expect(offenders).toEqual([]);
  });

  it('the row/⋯ menu default is the shared constant', () => {
    const billing = courseActions({ status: 'active' }, {}).groups.find((g) => g.id === 'billing');
    expect(billing.items[0]).toEqual({ id: 'purchase', label: RENEW_LESSONS_LABEL });
  });

  it('the 新增課程 duplicate prompt offers 續課 on each existing course', () => {
    const modal = readFileSync(join(root, 'components/EnrollmentConflictDecisionModal.vue'), 'utf8');
    expect(modal).toContain("'延續輔導課（不收費）' : RENEW_LESSONS_LABEL");
  });
});
