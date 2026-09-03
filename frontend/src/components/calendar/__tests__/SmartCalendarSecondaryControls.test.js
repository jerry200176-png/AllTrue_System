import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../../pages/SmartCalendar.vue'), 'utf8');

describe('SmartCalendar secondary-controls disclosure contract', () => {
  it('keeps primary date and view controls visible before the disclosure', () => {
    const primaryIndex = source.indexOf('toolbar-row toolbar-row-primary');
    const disclosureIndex = source.indexOf('class="calendar-secondary-controls-disclosure"');

    expect(primaryIndex).toBeGreaterThanOrEqual(0);
    expect(disclosureIndex).toBeGreaterThan(primaryIndex);
    expect(source.slice(primaryIndex, disclosureIndex)).toContain('calendar-jump-date');
    expect(source.slice(primaryIndex, disclosureIndex)).toContain('日檢視');
    expect(source.slice(primaryIndex, disclosureIndex)).toContain('週檢視');
  });

  it('groups secondary filters and actions behind an accessible native disclosure', () => {
    expect(source).toContain('<details v-if="!isTeacher" class="calendar-secondary-controls-disclosure">');
    expect(source).toContain('<summary class="calendar-secondary-controls-summary">');
    expect(source).toContain('篩選與更多操作');
    expect(source).toContain('calendarSecondaryControlsSummary');

    const disclosureStart = source.indexOf('<details v-if="!isTeacher" class="calendar-secondary-controls-disclosure">');
    const disclosureEnd = source.indexOf('</details>', disclosureStart);
    const disclosure = source.slice(disclosureStart, disclosureEnd);
    for (const marker of ['toolbar-room-select', '搜尋老師', '搜尋學生', 'openTeacherLeaveBatch', '管理教室', 'openQuickAdd']) {
      expect(disclosure).toContain(marker);
    }
  });
});
