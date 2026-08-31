import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const __dirname = dirname(fileURLToPath(import.meta.url));
const pagePath = resolve(__dirname, '../../pages/CourseManagement.vue'), source = readFileSync(pagePath, 'utf8');
describe('CourseManagement lens UX', () => {
  it('keeps the lens role, triage summary, and resettable accessible filters', () => {
    for (const marker of ['唯讀營運視圖', 'course-lens-guidance', 'course-lens-summary', 'const courseLensMetrics = computed', '剩餘 2 堂以下', '堂數待對帳', 'usageReviewCount', 'tag-usage-review', 'for="course-filter-student"', 'id="course-filter-student"', 'data-testid="course-filter-clear"', '清除篩選', 'function clearCourseFilters()', ':focus-visible', 'class="course-lens-primary-action"', "@click=\"emit('navigate', 'students')\""]) {
      expect(source).toContain(marker);
    }
  });
});
