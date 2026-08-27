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
});
