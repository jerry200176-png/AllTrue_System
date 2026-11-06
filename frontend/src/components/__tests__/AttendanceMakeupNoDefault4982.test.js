import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, expect, it, vi } from 'vitest';

// in-app #512 / #4982: the 待補點名 row must not default to 到班; a lesson the student missed was deducted by one click.
const source = readFileSync(resolve(__dirname, '../../pages/AttendancePage.vue'), 'utf8');
const between = (from, to) => source.slice(source.indexOf(from), source.indexOf(to, source.indexOf(from)));
const submitSrc = between('const submitMakeupMark = async (s) => {', 'const saveStatusEdit =');
const fetchSrc = between('const fetchMakeupSessions = async', 'const submitMakeupMark =');
const row = { class_session_id: 11, student_id: 5, student_class_id: 1, student_name: 'S', teacher_id: 9 };
afterEach(() => vi.unstubAllGlobals());

it('submitting a make-up row with no chosen status posts nothing', async () => {
  vi.stubGlobal('fetch', vi.fn());
  const deps = { makeupMsg: { value: '' }, makeupMsgType: { value: '' }, makeupMarkStatus: { value: {} }, makeupMarkSubmitting: { value: {} } };
  const names = Object.keys(deps);
  await Function(...names, `${submitSrc}\nreturn submitMakeupMark;`)(...names.map(n => deps[n]))(row);
  expect(fetch).not.toHaveBeenCalled();
  expect(deps.makeupMsgType.value).toBe('error');
});

it('a chosen status is posted as is', async () => {
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 201, json: async () => ({}) })));
  const deps = {
    makeupMsg: { value: '' }, makeupMsgType: { value: '' }, makeupMarkStatus: { value: { 11: 'absent' } }, makeupMarkSubmitting: { value: {} },
    makeupSessions: { value: [row] }, makeupTotal: { value: 1 }, getToken: async () => 't', fetchRecords: vi.fn(), statusLabelMap: { absent: '缺席' },
    withPoolNotice: m => m, leaveUnmarkedWithShift: vi.fn(), extractApiError: e => e.message, fetchMakeupSessions: vi.fn(),
  };
  const names = Object.keys(deps);
  await Function(...names, `${submitSrc}\nreturn submitMakeupMark;`)(...names.map(n => deps[n]))(row);
  expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ ClassSessionID: 11, Status: 'absent' });
});

it('the list never seeds a default status and the button waits for a choice', () => {
  expect(fetchSrc).not.toContain("|| 'present'");
  expect(source).toContain('<option value="" disabled>請選擇</option>');
  expect(source).toContain('|| !makeupMarkStatus[s.class_session_id]');
});
