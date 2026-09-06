import assert from 'node:assert/strict';
import test from 'node:test';
import { APP_PAGE_QUERY_KEY, buildAppPageUrl, parseAppPage } from './appNavigationHistory.js';

const authorized = new Set(['teacher-home', 'calendar', 'attendance']);

test('parses only pages exposed to the current role', () => {
  assert.equal(parseAppPage('?app_page=calendar', authorized), 'calendar');
  assert.equal(parseAppPage('?app_page=students', authorized), null);
  assert.equal(parseAppPage('?app_page=calendar', new Set()), null);
});
test('builds a reload-safe URL without dropping unrelated state', () => {
  const url = buildAppPageUrl({
    pathname: '/app',
    search: '?branch_id=9&workflow_id=22',
    hash: '#top',
    page: 'calendar',
  });
  assert.equal(url, `/app?branch_id=9&workflow_id=22&${APP_PAGE_QUERY_KEY}=calendar#top`);

  assert.equal(
    buildAppPageUrl({ pathname: '/app', search: '?app_page=students&keep=1', page: 'attendance' }),
    '/app?app_page=attendance&keep=1',
  );
});
