import { describe, expect, it } from 'vitest';
import { CURRICULUM_CATALOG, catalogFor, catalogUnits } from './curriculumCatalog';

describe('curriculumCatalog', () => {
  it('every entry fits what the template API accepts', () => {
    expect(new Set(CURRICULUM_CATALOG.map((c) => c.key)).size).toBe(CURRICULUM_CATALOG.length);
    for (const c of CURRICULUM_CATALOG) {
      const units = catalogUnits(c);
      expect(c.label.length).toBeLessThanOrEqual(120);
      expect(c.publisher.length).toBeLessThanOrEqual(40);
      expect(units.length).toBeGreaterThan(0);
      expect(units.length).toBeLessThanOrEqual(60);
      for (const u of units) {
        expect(u.title.trim()).toBe(u.title);
        expect(u.title.length).toBeGreaterThan(0);
        expect(u.title.length).toBeLessThanOrEqual(120);
        expect(u.note.length).toBeLessThanOrEqual(255);
      }
    }
  });

  it('offers only the chosen subject and grade, in book order', () => {
    const j1 = catalogFor('Math', 'J1');
    expect(j1.length).toBe(6);
    expect(j1.every((c) => c.subject === 'Math' && c.grade === 'J1')).toBe(true);
    expect(catalogFor('English', 'J1')).toEqual([]);
    const hanlin = j1.find((c) => c.publisher === '翰林' && c.semester === 1);
    expect(hanlin.label).toBe('七年級上學期數學（翰林）');
    expect(catalogUnits(hanlin)[0]).toEqual({ title: '1-1 正數與負數', note: '第一章 數與數線' });
  });
});
