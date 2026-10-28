import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, expect, it, vi } from 'vitest';
import { leaveWithRenewalShift } from '../../lib/leaveSuccessorShift.js';

// Run the page's own pending-list command (and its shift runner) with synthetic boundary adapters.
const source = readFileSync(resolve(__dirname, '../../pages/AttendancePage.vue'), 'utf8');
const between = (from, to) => source.slice(source.indexOf(from), source.indexOf(to, source.indexOf(from)));
const runner = between('async function leaveUnmarkedWithShift', 'function cancelLeaveConfirmation');
const command = between('async function doSubmitPendingMark', '// Batch mark all selected');
const plan = { applicable: true, apply_enabled: true, eligible: true, leave_session: { id: 11, date: '2026-11-17' }, preview_token: 'a'.repeat(64), old_end_date: '2026-12-01', successor_end_date: '2026-12-29',
  blockers: [], moves: [{ role: 'old_tail', session_id: null, from: null, to: { date: '2026-12-01', start: '19:00:00', end: '21:00:00' } },
    { role: 'successor', session_id: 21, from: { date: '2026-12-01', start: '19:00:00', end: '21:00:00' }, to: { date: '2026-12-08', start: '19:00:00', end: '21:00:00' } }] };
const session = { class_session_id: 11, student_id: 5, student_class_id: 1, student_name: '王小明', teacher_id: 9 };

function setup({ role = 'director', preview = plan, accepted = true } = {}) {
  const deps = {
    props: { userRole: role, branchId: 1, userId: 3 }, statusEditOwner: { value: 0 }, getToken: async () => 'synthetic',
    leaveBlockers: { value: [] }, leaveBlockerDate: { value: '' }, pendingMarkMsg: { value: '' }, pendingMarkMsgType: { value: '' }, pendingMarkSubmitting: { value: {} },
    confirmSuccessorLeave: vi.fn().mockResolvedValue(accepted), leaveWithRenewalShift, statusLabelMap: { leave: '請假', present: '出席' },
    trackAdoptionEvent: vi.fn(), fetchPendingSessions: vi.fn(), fetchRecords: vi.fn(), extractApiError: e => e.message,
  };
  vi.stubGlobal('fetch', vi.fn(async url => ({ ok: true, status: 200, json: async () => url.endsWith('/preview') ? preview : url.endsWith('/apply') ? { applied: true } : {} })));
  const names = Object.keys(deps);
  const submit = Function(...names, `${runner}\n${command}\nreturn doSubmitPendingMark;`)(...names.map(n => deps[n]));
  return { submit, deps };
}
const urls = () => fetch.mock.calls.map(([url]) => url);
afterEach(() => vi.unstubAllGlobals());

it('in-app410 #4578 a director leaving an unmarked lesson shifts the renewal after the preview confirmation', async () => {
  const { submit, deps } = setup();
  await submit(session, 'leave');
  expect(urls()).toEqual(['/api/v1/schedules/leave-successor-shift/preview', '/api/v1/schedules/leave-successor-shift/apply']);
  expect(deps.confirmSuccessorLeave.mock.calls[0][0]).toContain('2026-12-08');
  expect(deps.pendingMarkMsg.value).toBe('已請假並順延續約：王小明'); expect(deps.pendingMarkMsgType.value).toBe('success');
  expect(deps.fetchPendingSessions).toHaveBeenCalledOnce(); expect(deps.fetchRecords).toHaveBeenCalledOnce();
});
it('in-app410 #4578 a lesson whose tail fits keeps the ordinary attendance post', async () => {
  const { submit } = setup({ preview: { applicable: false } });
  await submit(session, 'leave');
  expect(urls()).toEqual(['/api/v1/schedules/leave-successor-shift/preview', '/api/v1/attendance']);
  expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({ ClassSessionID: 11, Status: 'leave' });
});
it('in-app410 #4578 a blocked preview or a declined confirmation writes nothing and says why', async () => {
  const blocked = setup({ preview: { ...plan, eligible: false, blockers: [{ message: '歷史需核對', course_id: 1, session_id: 21 }] } });
  await blocked.submit(session, 'leave');
  expect(urls()).toEqual(['/api/v1/schedules/leave-successor-shift/preview']);
  expect(blocked.deps.leaveBlockers.value).toHaveLength(1); expect(blocked.deps.leaveBlockerDate.value).toBe('2026-11-17');
  expect(blocked.deps.pendingMarkMsgType.value).toBe('error'); expect(blocked.deps.fetchRecords).not.toHaveBeenCalled();
  const declined = setup({ accepted: false });
  await declined.submit(session, 'leave');
  expect(urls()).toEqual(['/api/v1/schedules/leave-successor-shift/preview']); expect(declined.deps.fetchRecords).not.toHaveBeenCalled();
});
it('in-app410 #4578 teachers and other statuses never touch the shift endpoints', async () => {
  const teacher = setup({ role: 'teacher' });
  await teacher.submit(session, 'leave');
  expect(urls()).toEqual(['/api/v1/attendance']);
  const present = setup();
  await present.submit(session, 'present');
  expect(urls()).toEqual(['/api/v1/attendance']);
});
it('in-app410 #4578 a failed apply is reported, not shown as a leave', async () => {
  const { submit, deps } = setup();
  fetch.mockImplementation(async url => url.endsWith('/preview') ? { ok: true, json: async () => plan } : { ok: false, status: 409, json: async () => ({ message: '課表已變動，請重新預覽後再確認' }) });
  await expect(submit(session, 'leave')).rejects.toThrow('重新預覽');
  expect(deps.pendingMarkMsg.value).toBe('請假失敗：課表已變動，請重新預覽後再確認'); expect(deps.pendingMarkMsgType.value).toBe('error');
  expect(deps.fetchRecords).not.toHaveBeenCalled();
});
it('in-app410 #4578 the make-up list shifts the renewal too and drops the lesson from the list', async () => {
  const makeup = between('const submitMakeupMark = async (s) => {', 'const saveStatusEdit =');
  const deps = {
    props: { userRole: 'director', branchId: 1, userId: 3 }, statusEditOwner: { value: 0 }, getToken: async () => 'synthetic',
    leaveBlockers: { value: [] }, leaveBlockerDate: { value: '' }, confirmSuccessorLeave: vi.fn().mockResolvedValue(true), leaveWithRenewalShift,
    makeupMsg: { value: '' }, makeupMsgType: { value: '' }, makeupMarkStatus: { value: { 11: 'leave' } }, makeupMarkSubmitting: { value: {} },
    makeupSessions: { value: [session, { ...session, class_session_id: 12 }] }, makeupTotal: { value: 2 },
    fetchRecords: vi.fn(), fetchMakeupSessions: vi.fn(), statusLabelMap: { leave: '請假' }, extractApiError: e => e.message,
  };
  vi.stubGlobal('fetch', vi.fn(async url => ({ ok: true, status: 200, json: async () => url.endsWith('/preview') ? plan : { applied: true } })));
  const names = Object.keys(deps);
  await Function(...names, `${runner}\n${makeup}\nreturn submitMakeupMark;`)(...names.map(n => deps[n]))(session);
  expect(urls()).toEqual(['/api/v1/schedules/leave-successor-shift/preview', '/api/v1/schedules/leave-successor-shift/apply']);
  expect(deps.makeupMsg.value).toBe('已補登請假並順延續約：王小明'); expect(deps.makeupMsgType.value).toBe('success');
  expect(deps.makeupSessions.value.map(r => r.class_session_id)).toEqual([12]); expect(deps.makeupTotal.value).toBe(1);
  expect(deps.fetchRecords).toHaveBeenCalledOnce();
});
