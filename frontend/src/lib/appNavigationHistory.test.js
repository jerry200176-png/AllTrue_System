import assert from 'node:assert/strict';
import test from 'node:test';
import { APP_PAGE_QUERY_KEY, buildAppPageUrl, parseAppPage, buildCourseFocusUrl, parseCourseFocusSearch } from './appNavigationHistory.js';

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

test('course links contain only routing IDs and discard existing query/hash data', () => {
  const link = buildCourseFocusUrl('https://example.test/app?token=private&student_name=Private#secret', {
    branchId: 9, studentId: 33, courseId: 4151,
  });
  assert.equal(link, 'https://example.test/app?app_page=course-mgmt&branch_id=9&student_id=33&course_id=4151');
  assert.deepEqual(parseCourseFocusSearch(new URL(link).search), { branchId: 9, studentId: 33, courseId: 4151 });
  assert.equal(buildCourseFocusUrl('javascript:alert(1)', { branchId: 9, studentId: 33, courseId: 4151 }), null);
});

test('course link IDs round trip at boundaries without rounding or changing identity', () => {
  for (const id of [1, 9, 4151, 2147483648, Number.MAX_SAFE_INTEGER]) {
    const value = { branchId: 9, studentId: id, courseId: id };
    const link = buildCourseFocusUrl('https://example.test/', value);
    assert.deepEqual(parseCourseFocusSearch(new URL(link).search), value);
  }
  assert.deepEqual(parseCourseFocusSearch('?app_page=course-mgmt&branch_id=09&student_id=033&course_id=004151'),
    { branchId: 9, studentId: 33, courseId: 4151 });
});

test('invalid, incomplete or ambiguous course links fail closed', () => {
  for (const value of ['0', '-1', '1.5', '1e3', '0x10', 'Infinity', 'NaN', '9007199254740992', '']) {
    for (const key of ['branch_id', 'student_id', 'course_id']) {
      const p = new URLSearchParams({ app_page: 'course-mgmt', branch_id: '9', student_id: '33', course_id: '4151' });
      p.set(key, value);
      assert.equal(parseCourseFocusSearch(p.toString()), null, `${key}=${value}`);
    }
    assert.equal(buildCourseFocusUrl('https://example.test/', { branchId: 9, studentId: 33, courseId: value }), null);
  }
  for (const suffix of ['&course_id=4152', '&student_id=34', '&branch_id=10', '&app_page=students']) {
    assert.equal(parseCourseFocusSearch(`?app_page=course-mgmt&branch_id=9&student_id=33&course_id=4151${suffix}`), null);
  }
  assert.equal(parseCourseFocusSearch('?app_page=students&branch_id=9&student_id=33&course_id=4151'), null);
  assert.equal(parseCourseFocusSearch('?app_page=course-mgmt&branch_id=9&course_id=4151'), null);
});
