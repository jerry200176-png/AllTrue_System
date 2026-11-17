import assert from 'node:assert/strict';
import { test } from 'node:test';
import { installDirectorCalendarMocks } from './directorCalendarAcceptance.js';

const origin = 'https://app.example.test';
const dates = '/api/v1/student-classes/session-dates?branch_id=15';
const input = { branch_id: 15, range_start: '2026-11-09', range_end: '2026-11-15', courses: [{ id: 99 }, { id: 100 }] };

async function fixture(bind = true) {
  let handler, matches;
  const calendar = await installDirectorCalendarMocks({ route: async (match, next) => { matches = match; handler = next; } }, origin);
  const send = async (method, path, body) => {
    let result;
    await handler({ request: () => ({ method: () => method, url: () => origin + path, postDataJSON: () => body }),
      abort: async () => { result = { aborted: true }; },
      fulfill: async options => { result = { status: options.status, body: JSON.parse(options.body || '{}') }; } });
    return result;
  };
  if (bind) await send('GET', '/api/v1/student-classes?branch_id=15');
  return { send, calendar, matches };
}

test('in-app closeout smoke: the readonly POST gets only current synthetic session dates', async () => {
  const { send, calendar } = await fixture();
  const before = await send('POST', dates, input);
  assert.equal(before.status, 200);
  assert.deepEqual(Object.keys(before.body).sort(), ['100', '99']);
  assert.equal(before.body['99'].materialized[0].start_time, '20:00');
  await send('POST', '/api/v1/learning-records/reschedule-session', {
    student_class_id: 99, old_date: '2026-11-09', old_start_time: '20:00', new_date: '2026-11-09',
    start_time: '19:30', end_time: '21:30', ensure_schedule_exception: true,
  });
  const after = await send('POST', dates, input);
  assert.equal(after.body['99'].materialized[0].start_time, '19:30');
  assert.equal(after.body['100'].materialized[0].start_time, '17:00');
  assert.equal(calendar.counts.rejected, 0);
});

test('the readonly exception rejects unknown courses, mismatched scope, malformed input and other writes', async () => {
  const { send } = await fixture();
  for (const body of [null, { ...input, branch_id: 16 }, { ...input, courses: [{ id: 101 }] },
    { ...input, courses: [{ id: 99 }, { id: 99 }] }, { ...input, courses: '99' },
    { ...input, range_start: '0000' }, { ...input, range_start: undefined },
    { ...input, range_end: 'bad' }, { ...input, range_end: undefined }, { ...input, range_end: '2026-11-01' },
    { ...input, _method: 'DELETE' }]) {
    assert.equal((await send('POST', dates, body)).aborted, true);
  }
  for (const method of ['PUT', 'PATCH', 'DELETE']) assert.equal((await send(method, dates, input)).aborted, true);
  assert.equal((await send('POST', '/api/v1/student-classes?branch_id=15', input)).aborted, true);
  assert.equal((await send('POST', '/api/v1/learning-records', input)).aborted, true);
  assert.equal((await send('POST', dates.replace('15', '16'), input)).aborted, true);
});

test('unbound campus and foreign-origin requests never enter the synthetic read exception', async () => {
  const { send, matches } = await fixture(false);
  assert.equal((await send('POST', dates.replace('15', '0'), { ...input, branch_id: 0 })).aborted, true);
  assert.equal(matches(origin + dates), true);
  assert.equal(matches('https://other.example.test' + dates), false);
  assert.equal(matches('https://other.example.test/api/v1/class-sessions'), false);
  assert.equal(matches(origin + '/api/v1/unrelated'), false);
});
