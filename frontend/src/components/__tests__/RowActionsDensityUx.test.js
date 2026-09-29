import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

const read = (p) => readFileSync(new URL(p, import.meta.url), 'utf8');
const attendance = read('../../pages/AttendancePage.vue');
const notifications = read('../../pages/NotificationsCenter.vue');
const students = read('../../pages/StudentsList.vue');

describe('row actions density', () => {
  it('attendance: unrecorded status renders neutral (defaults only count once touched)', () => {
    expect(attendance).toContain('isStatusChosen(s.class_session_id, opt.value)');
    expect(attendance).toContain('pendingMarkTouched');
    expect(attendance).not.toMatch(/active: pendingMarkStatus\[/);
    expect(attendance).toMatch(/\.att-ops-stack \{ flex-direction: row/);
  });
  it('notifications: one primary action, rest in menu, no duplicated summary', () => {
    expect(notifications).not.toContain('>前往處理</AtButton>');
    expect(notifications).toContain('primaryActionLabel(item)');
    expect(notifications).toContain('<AtRowMenu');
    expect(notifications).toContain('!title.includes(payload.student_name)');
  });
  it('students: delete lives in the row menu and RFID never wraps', () => {
    expect(students).toMatch(/<AtRowMenu>[\s\S]*deleteStudent\(student\)/);
    expect(students).toMatch(/\.rfid-unbound \{\s*white-space: nowrap/);
  });
});
