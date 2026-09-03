import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '../..');
const read = (rel) => readFileSync(resolve(root, rel), 'utf8');

describe('authoritative mutation ownership (slice 2: students billing + LINE)', () => {
  it('removes students billing mutations and deep-links to tuition-collect', () => {
    const students = read('pages/StudentsList.vue');
    expect(students).not.toContain('PaymentEntryModal');
    expect(students).not.toContain('togglePaymentStatus');
    expect(students).toContain('goToTuitionBilling');
    expect(students).toContain('前往帳務中心');
  });

  it('removes LINE unbind from students and deep-links to binding-management', () => {
    const students = read('pages/StudentsList.vue');
    expect(students).not.toContain('removeLineBinding');
    expect(students).toContain('goToBindingManagement');
    expect(students).toContain('前往 LINE 綁定管理');
  });

  it('deep-links operational quick-add session to course-mgmt', () => {
    const students = read('pages/StudentsList.vue');
    expect(students).toContain('goToCourseMgmtOps');
    expect(students).toContain('補課／補登請至課程管理');
    expect(students).not.toContain('openQuickAddSession');
  });
});
