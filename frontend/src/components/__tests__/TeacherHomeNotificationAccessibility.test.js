import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TeacherHomePage.vue'), 'utf8');

describe('TeacherHome notification controls accessibility', () => {
  it('announces the current pending-sound setting as a pressed toggle', () => {
    expect(source).toContain(':aria-pressed="warningSoundEnabled ? \'true\' : \'false\'"');
    expect(source).toContain(':aria-label="warningSoundEnabled ? \'關閉待辦提示音\' : \'開啟待辦提示音\'"');
    expect(source).toContain('@click="togglePendingSound"');
  });

  it('names the daily snooze action and keeps the attendance CTA a button', () => {
    expect(source).toContain('aria-label="今日靜音待辦提示音"');
    expect(source).toContain('class="th-action-btn th-action-attendance"');
    expect(source).toContain('@click="goAttendance"');
    const attendanceBlock = source.match(/<button[\s\S]*?class="th-action-btn th-action-attendance"[\s\S]*?<\/button>/)?.[0] || '';
    expect(attendanceBlock).toContain('type="button"');
  });
});
