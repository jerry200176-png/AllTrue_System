import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pageSource = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
const composableSource = readFileSync(resolve(__dirname, '../../composables/course-management/useCourseSessionsDisplay.js'), 'utf8');

describe('CourseManagement upcoming lesson preview (#321)', () => {
  it('uses the existing effective session view model and stable keys', () => {
    expect(pageSource).toContain('aria-label="下一堂"');
    expect(pageSource).toContain('upcomingSessionPreview(c, { todayYmd, limit: 1 })');
    expect(pageSource).toContain('formatSessionChipDate(unit)');
    expect(pageSource).toContain(':key="sessionRowKey(unit)"');
    expect(composableSource).toContain('primarySessionUnits(course)');
  });

  it('covers loading, retryable error, empty and overflow states', () => {
    expect(pageSource).toContain('載入中…');
    expect(pageSource).toContain('暫時無法載入。');
    expect(pageSource).toContain("'暫無下一堂' : '未排定'");
    expect(pageSource).toContain('重新載入');
  });

  it('keeps the four-field row (科目・老師, 下一堂, 剩幾堂, 繳費) plus one action cell', () => {
    expect(pageSource).toContain('管理課程');
    expect(pageSource).toContain('<th>科目・老師</th>');
    for (const th of ['下一堂', '剩幾堂', '繳費']) expect(pageSource).toContain(`<th class="col-sm-hide">${th}</th>`);
    expect(pageSource).not.toContain('<th>時段</th>');
    expect(pageSource).not.toContain('fetch(`/api/v1/student-classes/${c.id}/sessions`');
    expect(pageSource).not.toContain('fetch(`/api/v1/student-classes/${c.id}/session');
  });
});
