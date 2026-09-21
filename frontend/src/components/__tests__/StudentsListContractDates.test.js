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
});
