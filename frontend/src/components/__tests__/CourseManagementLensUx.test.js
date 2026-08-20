import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

// UX guardrails for the CourseManagement read-only triage lens. These checks keep
// the page's intent visible even though its data table is too integration-heavy
// for a small isolated component mount.
const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue');
const source = readFileSync(pagePath, 'utf8');

describe('CourseManagement lens UX', () => {
  it('explains the read-only role and provides one canonical edit destination', () => {
    expect(source).toContain('唯讀營運視圖');
    expect(source).toContain('course-lens-guidance');
    expect(source).toContain('建立、編輯、續報與加購課程，請從「學生管理」的學生主檔進入');
    expect(source).toContain('class="course-lens-primary-action"');
    expect(source).toContain("@click=\"emit('navigate', 'students')\"");
  });

  it('surfaces triage metrics without introducing another write action', () => {
    expect(source).toContain('course-lens-summary');
    expect(source).toContain('const courseLensMetrics = computed');
    expect(source).toContain('剩餘 2 堂以下');
    expect(source).not.toContain('course-lens-metric--danger');
  });

  it('makes filters labelled, keyboard reachable, and easy to reset', () => {
    expect(source).toContain('for="course-filter-student"');
    expect(source).toContain('id="course-filter-student"');
    expect(source).toContain('data-testid="course-filter-clear"');
    expect(source).toContain('清除篩選');
    expect(source).toContain('function clearCourseFilters()');
    expect(source).toContain(':focus-visible');
  });
});
