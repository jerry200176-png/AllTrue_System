import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');

describe('StudentsList contract date workflow', () => {
  it('loads all expanded-student contracts through one batched canonical read', () => {
    expect(source).toContain('fetchClassSessions({');
    expect(source).toContain('studentClassIds: ids');
    expect(source).toContain('response?.byClass?.[String(id)]');
    expect(source).not.toContain('fetchClassSessions({\n      token,\n      branchId: props.branchId,\n      studentClassId:');
  });

  it('keeps the date affordance and bulk comparison controls explicit', () => {
    expect(source).toContain('再顯示 ${studentCourseSessionPreview(student.id, course).overflow} 堂');
    expect(source).toContain('全部展開上課日期');
    expect(source).toContain('全部收合上課日期');
    expect(source).toContain('formatStudentCourseSessionDate(session)');
    expect(source).toContain('studentCourseSessionStatus(session)');
  });

  it('renders historical contracts through the same date display path', () => {
    expect(source).toContain('student-course-dates--history');
    expect(source).toContain('studentCourseSessionPreview(student.id, hc)');
    expect(source).toContain('studentCourseSessionRowKey(session, hc)');
  });

  it('keeps history date states ordered as loading, error, dates, then empty', () => {
    const historyStart = source.indexOf('student-course-dates--history');
    const historyEnd = source.indexOf('sl-history-card__actions', historyStart);
    const historyDates = source.slice(historyStart, historyEnd);
    const loading = historyDates.indexOf('isStudentCourseSessionsLoading(student.id)');
    const error = historyDates.indexOf('studentCourseSessionsLoadError(student.id)');
    const dates = historyDates.indexOf('studentCourseSessionPreview(student.id, hc).total > 0');
    const empty = historyDates.indexOf('目前沒有可顯示的上課日期。');

    expect(historyDates).toContain('上課日期暫時無法載入。');
    expect(historyDates).toContain('retryLoadStudentCourseSessions(student.id)');
    expect(loading).toBeGreaterThanOrEqual(0);
    expect(error).toBeGreaterThan(loading);
    expect(dates).toBeGreaterThan(error);
    expect(empty).toBeGreaterThan(dates);
  });
});
