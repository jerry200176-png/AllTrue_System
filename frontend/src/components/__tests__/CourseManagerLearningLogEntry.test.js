// in-app #29 (#4541): the 管理課程 drawer opens this student's 學習日誌 (every status, full history) in one click.
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const read = (p) => readFileSync(resolve(__dirname, p), 'utf8');
const page = read('../../pages/CourseManagement.vue');
const app = read('../../App.vue');
const lr = read('../../pages/LearningRecordsPage.vue');

describe('Course drawer 學習日誌 entry (#4541)', () => {
  it('the drawer menu lists 學習日誌 and the action navigates with the student name', () => {
    expect(page).toContain("{ id: 'records', label: '紀錄', items: [{ id: 'learning-log', label: '學習日誌' }] }");
    expect(page).toContain("'learning-log': () => emit('navigate', { target: 'learning', studentName: c.student_name || '' }),");
  });

  it('App hands the name to the learning page once and clears it when consumed', () => {
    expect(app).toContain("learningTargetStudentName.value = target === 'learning' ? String(studentName || '').trim() : '';");
    expect(app).toContain(':target-student-name="learningTargetStudentName"');
    expect(app).toContain('@clear-target-student="learningTargetStudentName = \'\'"');
  });

  it('the learning page filters to that student, lifts the 90-day window and shows every status before the first fetch', () => {
    const init = lr.slice(lr.indexOf('if (openedForStudent) {'), lr.indexOf("emit('clear-target-student');"));
    expect(init).toContain('filters.student_name = String(props.targetStudentName).trim();');
    expect(init).toContain('defaultWindowDisabled.value = true;');
    expect(init).toContain("reviewTab.value = 'all';");
    expect(lr.indexOf('if (openedForStudent) {')).toBeLessThan(lr.indexOf('watch(reviewTab,'));
  });

  it('a stale 家長回饋 focus token does not switch the student entry to parent messages (Codex r4430425687)', () => {
    expect(lr).toContain("const openedForStudent = Boolean(String(props.targetStudentName || '').trim());");
    expect(lr).toContain('if (props.feedbackFocusToken && !openedForStudent) nextTick(() => applyFeedbackFocus());');
    expect(lr).not.toContain('  if (props.feedbackFocusToken) nextTick(() => applyFeedbackFocus());');
  });
});
