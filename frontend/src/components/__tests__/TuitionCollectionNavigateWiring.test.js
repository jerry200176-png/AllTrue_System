import { it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const source = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../App.vue'), 'utf8');

// Bug #371: TuitionCollectionPage emits 'navigate' (前往課程核對 etc.); App must listen or the click is dead.
it('App.vue listens to navigate from TuitionCollectionPage', () => {
  const tag = source.match(/<TuitionCollectionPage\b[^>]*\/>/)?.[0] ?? '';
  expect(tag).toMatch(/@navigate="onNavigateFromCourseManagement"/);
});
