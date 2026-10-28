import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const page = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');

describe('history card 恢復課程 (#4515)', () => {
  it('is offered only for a paused course, not one that used up its lessons', () => {
    // useCoursePause treats a non-inactive course as a pause request, so 恢復 on a used-up
    // (still active) course opened a 暫停課程 dialog.
    const resume = page.match(/<button v-if="([^"]*)" class="action-dropdown-item action-dropdown-resume"/);
    expect(resume?.[1]).toContain("hc.status === 'inactive'");
  });
});
