import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TeacherHomePage.vue'), 'utf8');

describe('TeacherHome weekly schedule disclosure', () => {
  it('opens only today by default and keeps other days manually expandable', () => {
    expect(source).toContain('<details\n          v-for="day in weekDays"');
    expect(source).toContain(':open="day.isToday"');
    expect(source).not.toContain(':open="day.isToday || day.events.length > 0"');
    expect(source).toContain('<summary class="th-day-summary">');
    expect(source).toContain('本週課表');
  });
});
