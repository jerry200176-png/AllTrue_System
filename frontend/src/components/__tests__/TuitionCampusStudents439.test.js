import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, it } from 'vitest';

// Run the page's actual loader with synthetic adapters, without mounting the whole page.
const source = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');
const body = source.slice(source.indexOf('let campusStudentsSeq'), source.indexOf('// ↑/↓ in the drawer'));

it('in-app439 a slow reply for the previous campus cannot refill the charge picker', async () => {
  const pending = {};
  const props = { branchId: 5 };
  const campusStudents = { value: [{ id: 99, name: '舊的' }] };
  const authedFetch = (url) => new Promise((done) => { pending[new URLSearchParams(url.split('?')[1]).get('branch_id')] = done; });
  const load = Function('props', 'campusStudents', 'authedFetch', `${body}; return loadCampusStudents;`)(props, campusStudents, authedFetch);
  const reply = (rows) => ({ json: async () => ({ data: rows }) });

  const first = load();
  expect(campusStudents.value).toEqual([]);
  props.branchId = 6;
  const second = load();
  pending['6'](reply([{ id: 2, name: '六號學生', enable: 1 }]));
  await second;
  pending['5'](reply([{ id: 1, name: '五號學生', enable: 1 }])); // campus 5 answers last
  await first;
  expect(campusStudents.value).toEqual([{ id: 2, name: '六號學生' }]);
});
