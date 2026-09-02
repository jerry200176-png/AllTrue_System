import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TeacherHomePage.vue'), 'utf8');
const appSource = readFileSync(resolve(__dirname, '../../App.vue'), 'utf8');

describe('TeacherHome weekly schedule disclosure', () => {
  it('opens only today by default and keeps other days manually expandable', () => {
    expect(source).toContain('<details\n          v-for="day in weekDays"');
    expect(source).toContain(':open="day.isToday"');
    expect(source).not.toContain(':open="day.isToday || day.events.length > 0"');
    expect(source).toContain('<summary class="th-day-summary">');
    expect(source).toContain('本週課表');
  });

  it('retains the last good week while refreshing and exposes course details', () => {
    expect(source).toContain('loadingWeek && !weekLoadedOnce');
    expect(source).toContain('weekLoadedOnce.value = true');
    expect(source).toContain('class="th-event-details"');
    expect(source).toContain('@click="openCourseDetails(ev)"');
    expect(source).toContain('classSessionId: s.id');
    expect(source).toContain('studentId: s.studentId');
  });

  it('keeps Monday-to-Sunday headers visible while the schedule scrolls', () => {
    expect(source).toMatch(/\.th-day-summary\s*\{[\s\S]*position:\s*sticky/);
    expect(source).toMatch(/\.th-day-summary\s*\{[\s\S]*top:\s*0/);
    expect(source).toMatch(/\.th-day\s*\{\s*border-radius:\s*10px;\s*\}/);
  });

  it('keeps the teacher-only mount guard and director surface unchanged', () => {
    expect(appSource).toContain("isTeacher && active === 'teacher-home'");
    expect(appSource).toContain("isDirector && active === 'director'");
  });
});
