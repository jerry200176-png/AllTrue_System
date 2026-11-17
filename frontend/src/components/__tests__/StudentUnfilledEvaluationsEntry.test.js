// #4529 A (competitor gap #3922 item 3): from the student list straight to that student's unfilled evaluations.
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const read = (p) => readFileSync(resolve(__dirname, p), 'utf8');

describe('Student list 待補評量 entry (#4529 A)', () => {
  it('each course menu navigates to the learning page with the student and the unfilled lens', () => {
    expect(read('../../pages/StudentsList.vue')).toContain("@click=\"$emit('navigate', { target: 'learning', studentName: student.name, onlyUnfilled: true })\">待補評量</button>");
  });

  it('App passes the lens only for an explicit learning-page request', () => {
    const app = read('../../App.vue');
    expect(app).toContain("learningTargetOnlyUnfilled.value = target === 'learning' && onlyUnfilled === true;");
    expect(app).toContain(':target-only-unfilled="learningTargetOnlyUnfilled"');
  });

  it('the learning page turns 未填 on only when it was opened for that student', () => {
    expect(read('../../pages/LearningRecordsPage.vue')).toContain('const onlyUnfilled = ref(openedForStudent && props.targetOnlyUnfilled === true);');
  });
});
