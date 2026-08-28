import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TeacherHomePage.vue'), 'utf8');

describe('TeacherHome controls accessibility', () => {
  it('uses a native, labelled button for the clock-in status card', () => {
    expect(source).toContain('type="button"\n      class="th-clockin-card card"');
    expect(source).toContain('aria-labelledby="teacher-clockin-title"');
    expect(source).toContain('aria-describedby="teacher-clockin-status"');
    expect(source).toContain('id="teacher-clockin-title"');
    expect(source).toContain('id="teacher-clockin-status"');
    expect(source).toContain('aria-live="polite"');
    expect(source).not.toContain('role="button" tabindex="0"\n      @keydown.enter="goAttendance"');
  });

  it('gives icon-only schedule controls explicit button names', () => {
    expect(source).toContain('title="上一週" aria-label="上一週"');
    expect(source).toContain('title="下一週" aria-label="下一週"');
    expect(source).toContain(':aria-label="activeReportMap[ev.id] ? \'查看課表回報\' : \'回報課表有誤\'"');
    expect(source).toContain('type="button"\n                class="th-report-btn"');
  });
});
