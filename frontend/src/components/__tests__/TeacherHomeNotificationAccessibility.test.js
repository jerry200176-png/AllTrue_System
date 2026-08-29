import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TeacherHomePage.vue'), 'utf8');

describe('TeacherHome action accessibility', () => {
  it('keeps the primary actions as named, keyboard-accessible buttons', () => {
    expect(source).toContain('data-guide="teacher-home-work-queue"');
    expect(source).toContain('class="th-clockin-card card"');
    expect(source).toContain('@click="goAttendance"');
    const attendanceBlock = source.match(/<button[\s\S]*?class="th-clockin-card card"[\s\S]*?<\/button>/)?.[0] || '';
    expect(attendanceBlock).toContain('type="button"');
    expect(attendanceBlock).toContain('aria-labelledby="teacher-clockin-title"');
  });
});
