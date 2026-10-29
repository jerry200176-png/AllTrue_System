import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

// #4443: the server takes the reviewer from the verified login (#4440); the client must not send DirectorID.
describe('learning-record callers do not send DirectorID', () => {
  for (const f of ['LearningRecordsPage.vue', 'DirectorDashboard.vue', 'CourseManagement.vue']) {
    it(f, () => {
      const src = readFileSync(`src/pages/${f}`, 'utf8');
      expect(src).not.toMatch(/\bDirectorID\b/);
    });
  }
});
