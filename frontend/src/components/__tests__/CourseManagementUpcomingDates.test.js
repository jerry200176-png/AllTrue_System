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
    for (const th of ['科目・老師', '下一堂', '剩幾堂', '繳費']) expect(pageSource).toMatch(new RegExp(`<th[^>]*data-col="\\w+">${th}</th>`));
    expect(pageSource).not.toContain('<th>時段</th>');
    expect(pageSource).not.toContain('fetch(`/api/v1/student-classes/${c.id}/sessions`');
    expect(pageSource).not.toContain('fetch(`/api/v1/student-classes/${c.id}/session');
  });

  it('header order equals cell order, and warnings stay in the always-visible first cell (#4659 step 5)', () => {
    const a = pageSource.indexOf('<table class="course-table course-table--simple">');
    const table = pageSource.slice(a, pageSource.indexOf('History courses collapsible section', a));
    const heads = [...table.slice(0, table.indexOf('</thead>')).matchAll(/<th[^>]*data-col="(\w+)"/g)].map((m) => m[1]);
    const row = table.slice(table.indexOf('<tr :class="[\'course-row\''), table.indexOf('<tr v-if="!courseManagerEnabled && expandedDates'));
    const cells = [...row.matchAll(/<td[^>]*(?:data-col="(\w+)"|class="(cell-actions)")/g)].map((m) => m[1] || 'actions');
    expect(heads).toEqual(['subject', 'next', 'remaining', 'payment', 'actions']);
    expect(cells).toEqual(heads);
    const subjectCell = row.slice(row.indexOf('data-col="subject"'), row.indexOf('data-col="next"'));
    for (const w of ['rowWarningSummary(group, c)', 'role="alert"', '查看待核對回報']) expect(subjectCell).toContain(w);
    for (const hidden of row.split('<td').filter((t) => t.includes('col-sm-hide'))) {
      expect(hidden).not.toContain('role="alert"');
      expect(hidden).not.toContain('rowWarningSummary');
    }
  });
});
