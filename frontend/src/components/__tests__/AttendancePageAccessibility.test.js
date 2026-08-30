import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/AttendancePage.vue'), 'utf8');
const nativeButtonTags = [...source.matchAll(/<button\b(?:[^>"']|"[^"]*"|'[^']*')*>/g)].map(([tag]) => tag);

describe('AttendancePage workspace accessibility', () => {
  it('connects director tabs to exactly one keyboard-focusable panel', () => {
    expect(source).toContain('id="attendance-tab-student"');
    expect(source).toContain('id="attendance-tab-teacher"');
    expect(source).toContain('id="attendance-tab-student"\n        type="button"');
    expect(source).toContain('id="attendance-tab-teacher"\n        type="button"');
    expect(source).toContain('aria-controls="attendance-student-panel"');
    expect(source).toContain('aria-controls="attendance-teacher-panel"');
    expect(source).toContain('aria-labelledby="attendance-tab-teacher"');
    expect(source).toContain(':aria-labelledby="isTeacher ? \'attendance-student-panel-title\' : \'attendance-tab-student\'"');
    expect(source).toContain('id="attendance-student-panel"');
    expect(source).toContain('id="attendance-teacher-panel"');
    expect(source).toContain('role="tabpanel"');
    expect(source).toContain('tabindex="0"');
  });

  it('announces pending attendance status controls as pressed buttons', () => {
    expect(source).toContain('type="button"');
    expect(source).toContain(':aria-pressed="pendingMarkStatus[s.class_session_id] === opt.value"');
  });

  it('names the high-frequency records date and filter controls', () => {
    expect(source.match(/aria-label="查詢老師打卡日期"/g)).toHaveLength(1);
    expect(source.match(/aria-label="查詢出缺勤日期"/g)).toHaveLength(2);
    expect(source).toContain('aria-label="搜尋學生姓名"');
    expect(source).toContain('aria-label="依出缺勤狀態篩選"');
  });

  it('keeps every native action button explicit as a non-submit control', () => {
    expect(nativeButtonTags.length).toBeGreaterThan(20);
    expect(nativeButtonTags.filter((tag) => !/\btype\s*=\s*["']button["']/.test(tag))).toEqual([]);
  });
});
