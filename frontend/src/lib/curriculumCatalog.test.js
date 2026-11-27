import test from 'node:test';
import assert from 'node:assert/strict';
import { CURRICULUM_CATALOG, catalogFor, catalogUnits } from './curriculumCatalog.js';

test('every catalog entry fits what the template API accepts', () => {
  assert.equal(new Set(CURRICULUM_CATALOG.map((c) => c.key)).size, CURRICULUM_CATALOG.length);
  for (const c of CURRICULUM_CATALOG) {
    const units = catalogUnits(c);
    assert.ok(c.label.length <= 120 && c.publisher.length <= 40, c.key);
    assert.ok(units.length > 0 && units.length <= 80, c.key);
    for (const u of units) {
      assert.equal(u.title.trim(), u.title);
      assert.ok(u.title.length > 0 && u.title.length <= 120 && u.note.length <= 255, `${c.key} ${u.title}`);
    }
  }
});

test('offers only the chosen subject and grade, in book order', () => {
  const j1 = catalogFor('Math', 'J1');
  assert.equal(j1.length, 6);
  assert.ok(j1.every((c) => c.subject === 'Math' && c.grade === 'J1'));
  assert.deepEqual(catalogFor('English', 'J1'), []);
  const hanlin = j1.find((c) => c.publisher === '翰林' && c.semester === 1);
  assert.equal(hanlin.label, '七年級上學期數學（翰林）');
  assert.deepEqual(catalogUnits(hanlin)[0], { title: '1-1 正數與負數', note: '第一章 數與數線' });
});
