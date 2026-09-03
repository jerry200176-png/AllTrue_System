import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../OperationsQuickStart.vue'), 'utf8');
const pageSource = (name) => readFileSync(resolve(__dirname, `../../pages/${name}`), 'utf8');

describe('operations quick-start UX contract', () => {
  it('uses one reusable task-first component with accessible current state', () => {
    for (const marker of [
      'data-testid="operations-quick-start"',
      'role="list"',
      ':aria-current="currentId && currentId === step.id ? \'step\' : undefined"',
      ':aria-label="`${step.title}：${step.description || step.action || \'\'}`"',
      "defineEmits(['select'])",
    ]) expect(source).toContain(marker);
  });

  it('collapses to one-column touch targets on small screens', () => {
    expect(source).toContain('@media (max-width: 720px)');
    expect(source).toContain('grid-template-columns: 1fr');
    expect(source).toContain('min-height');
  });

  it('keeps the director, billing, and calendar flows on existing safe paths', () => {
    const director = pageSource('DirectorDashboard.vue');
    const billing = pageSource('TuitionCollectionPage.vue');
    const calendar = pageSource('SmartCalendar.vue');
    for (const marker of ['收款與核帳', '新增排課', '調課／代課', "intent: 'pending'", "intent: 'quick-add'", "intent: 'reschedule'"]) {
      expect(director).toContain(marker);
    }
    for (const marker of ['帳務處理流程', '登記繳費回報', '確認入帳與收據', 'clear-initial-tab']) {
      expect(billing).toContain(marker);
    }
    for (const marker of ['排課處理流程', '調課', '換代課', 'clear-initial-intent']) {
      expect(calendar).toContain(marker);
    }
    expect(calendar).toContain('v-if="!isTeacher"');
  });

  it('keeps the director first viewport task-first and progressively discloses secondary tools', () => {
    const director = pageSource('DirectorDashboard.vue');
    const focusIndex = director.indexOf('aria-label="今天的主任工作"');
    const fillRateIndex = director.indexOf('aria-label="老師評量完成率"');
    const quickStartIndex = director.indexOf('class="director-secondary-tools"');

    expect(focusIndex).toBeGreaterThan(-1);
    expect(quickStartIndex).toBeGreaterThan(focusIndex);
    expect(fillRateIndex).toBeGreaterThan(quickStartIndex);
    expect(director).toContain('查看為什麼這些工作排在前面');
    expect(director).toContain('營運指標與常用入口');
    expect(director).toContain('@toggle="secondaryToolsOpen = $event.currentTarget.open"');
  });

  it('keeps course, billing, and calendar primary work ahead of guidance panels', () => {
    const course = pageSource('CourseManagement.vue');
    const billing = pageSource('TuitionCollectionPage.vue');
    const calendar = pageSource('SmartCalendar.vue');

    expect(course).toContain('class="course-context-disclosure"');
    expect(course).toContain('class="course-stats-disclosure"');
    expect(billing).toContain('class="tc-process-disclosure"');
    expect(billing).toContain('class="tc-summary-disclosure"');
    expect(calendar).toContain('class="calendar-process-disclosure"');
    expect(calendar).toContain('data-guide="calendar-toolbar"');
  });
});
