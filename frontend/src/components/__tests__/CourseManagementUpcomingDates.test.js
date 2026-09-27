import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pageSource = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
const composableSource = readFileSync(resolve(__dirname, '../../composables/course-management/useCourseSessionsDisplay.js'), 'utf8');

describe('CourseManagement upcoming lesson preview (#321)', () => {
  it('uses the existing effective session view model and stable keys', () => {
    expect(pageSource).toContain('aria-label="近期上課"');
    expect(pageSource).toContain('upcomingSessionPreview(c, { todayYmd, limit: 3 })');
    expect(pageSource).toContain('formatSessionChipDate(unit)');
    expect(pageSource).toContain(':key="sessionRowKey(unit)"');
    expect(composableSource).toContain('primarySessionUnits(course)');
  });

  it('covers loading, retryable error, empty and overflow states', () => {
    expect(pageSource).toContain('上課日期載入中…');
    expect(pageSource).toContain('上課日期暫時無法載入。');
    expect(pageSource).toContain('目前沒有即將上課日期。');
    expect(pageSource).toContain('另有 {{ upcomingSessionPreview(c, { todayYmd, limit: 3 }).overflow }} 堂');
    expect(pageSource).toContain('重新載入');
  });

  it('keeps the existing six-column dense list and detail action', () => {
    expect(pageSource).toContain('管理課程');
    expect(pageSource).toContain('<th>時段</th>');
    expect(pageSource).toContain('colspan="6"');
    expect(pageSource).not.toContain('fetch(`/api/v1/student-classes/${c.id}/sessions`');
    expect(pageSource).not.toContain('fetch(`/api/v1/student-classes/${c.id}/session');
  });
});
