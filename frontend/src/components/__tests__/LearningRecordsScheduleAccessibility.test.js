import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/LearningRecordsPage.vue'), 'utf8');

describe('LearningRecords schedule accessibility', () => {
  it('labels the today/week view switch as a pressed-button group', () => {
    expect(source).toContain('class="ts-tabs ts-tabs--ios" role="group" aria-label="課表檢視"');

    const scheduleButtons = [...source.matchAll(/<button[\s\S]*?>/g)]
      .map((match) => match[0])
      .filter((openingTag) => openingTag.includes('scheduleView ==='));

    expect(scheduleButtons).toHaveLength(2);
    for (const openingTag of scheduleButtons) {
      expect(openingTag).toContain('type="button"');
      expect(openingTag).toContain(':aria-pressed=');
    }
  });

  it('gives week navigation and fill actions explicit button semantics', () => {
    expect(source).toContain('type="button" class="icon-btn" aria-label="上一週"');
    expect(source).toContain('type="button" class="icon-btn" aria-label="下一週"');
    const weekEvent = [...source.matchAll(/<div[\s\S]*?>/g)]
      .map((match) => match[0])
      .find((openingTag) => openingTag.includes('class="ts-event ts-event-sm"'));

    expect(weekEvent).toBeTruthy();
    expect(weekEvent).toContain('role="button"');
    expect(weekEvent).toContain('tabindex="0"');
    expect(weekEvent).toContain(':aria-label=');
    expect(source).toContain('@keydown.enter.prevent="openFromScheduleMaybe(ev)"');
    expect(source).toContain('@keydown.space.prevent="openFromScheduleMaybe(ev)"');
    expect(source).toContain('.ts-event[role="button"]:focus-visible');

    const fillButtons = [...source.matchAll(/<button[\s\S]*?>/g)]
      .map((match) => match[0])
      .filter((openingTag) => openingTag.includes('class="ts-fill-btn"'));

    expect(fillButtons).toHaveLength(1);
    expect(fillButtons[0]).toContain('type="button"');
  });
});
