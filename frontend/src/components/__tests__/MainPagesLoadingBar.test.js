import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Director's main pages must route requests through trackedFetch so the top
// loading bar shows. Add a path here only for polling/background calls.
const PAGES = [
  'TuitionCollectionPage',
  'CourseManagement',
  'StudentsList',
  'SmartCalendar',
  'DirectorDashboard',
];
const ALLOWED_RAW_FETCH = {};

describe('main pages use trackedFetch (loading bar)', () => {
  for (const page of PAGES) {
    it(`${page}.vue has no raw fetch( call`, () => {
      const src = readFileSync(resolve(__dirname, `../../pages/${page}.vue`), 'utf8');
      const raw = src.split('\n').filter((l) => /(^|[^\w.$])fetch\(/.test(l));
      expect(raw.length).toBe(ALLOWED_RAW_FETCH[page] || 0);
    });
  }
});
