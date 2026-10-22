// in-app #429 (GitHub #4331): adding lessons has one name, 「續課」 (Founder 2026-10-15 Q8=A).
// Fails when user-facing 「加購」 wording comes back anywhere in frontend/src (comments are ignored).
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { resolve, dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  DUAL_TEACHER_COPY_DRAWER_LABEL, DUAL_TEACHER_COPY_LABEL, RENEW_LESSONS_LABEL, dualTeacherCopyNotice,
} from '../../lib/courseIntent.js';
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
    const walkPhp = (dir) => readdirSync(dir).flatMap((name) => {
      const p = join(dir, name);
      return statSync(p).isDirectory() ? walkPhp(p) : (name.endsWith('.php') ? [p] : []);
    });
    const offenders = walkPhp(backend)
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

// in-app #423 (GitHub #4303): the copy-for-another-teacher action read as "change teacher", so a
// student was "moved" while the original course kept its future lessons and the old teacher's seat.
describe('dual-teacher copy is not a teacher change (#4303)', () => {
  it('no user-facing 「換師複製」 or 「並更換老師」 wording remains in frontend/src', () => {
    const offenders = walk(root)
      .filter((f) => /換師複製|並更換老師/.test(stripComments(readFileSync(f, 'utf8'))))
      .map((f) => relative(root, f));
    expect(offenders).toEqual([]);
  });

  it('the ⋯ menu and course drawer use the shared labels', () => {
    const move = courseActions({ status: 'active' }, {}).groups.find((g) => g.id === 'move');
    expect(move.items).toContainEqual({ id: 'duplicate', label: DUAL_TEACHER_COPY_LABEL });
    const page = readFileSync(join(root, 'pages/CourseManagement.vue'), 'utf8');
    expect(page).toContain('{{ DUAL_TEACHER_COPY_LABEL }}');
    expect(page).toContain('{{ DUAL_TEACHER_COPY_DRAWER_LABEL }}');
    expect(page).toContain('message: dualTeacherCopyNotice(teacherName)');
    expect(DUAL_TEACHER_COPY_DRAWER_LABEL).toContain('原課程保留');
  });

  it('the confirm says the old course keeps its lessons and seat, and points to 轉課', () => {
    const text = dualTeacherCopyNotice('王老師');
    expect(text).toContain('原課程（王老師）和它之後已排的堂次都會保留');
    expect(text).toContain('名額也不會釋放');
    expect(text).toContain('「⋯ › 調動 › 轉課」');
    expect(dualTeacherCopyNotice('')).toContain('原課程（目前老師）');
  });
});
