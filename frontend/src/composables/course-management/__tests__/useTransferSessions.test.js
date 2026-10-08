import { beforeEach, describe, expect, it, vi } from 'vitest';

const authedFetch = vi.fn();
const getAccessToken = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({
  authedFetch: (...a) => authedFetch(...a),
  getAccessToken: (...a) => getAccessToken(...a),
}));

import { useTransferSessions } from '../useTransferSessions';

const res = (ok, json) => ({ ok, json: async () => json });
const flush = () => new Promise((r) => setTimeout(r, 0));
const src = { id: 7, student_id: 3, student_name: '小明', subject: 'math', subject_name: '數學' };
const units = [
  { id: 1, date: '2026-09-01', status: 'attended' },
  { id: 2, date: '2026-09-08', status: 'scheduled' },
  { id: 3, date: '2026-09-15', status: 'cancelled', hasAttendanceHistory: true },
];
let reload, notify, goToBilling, goToPurchase, allSessionUnits;
const make = () => useTransferSessions({ allSessionUnits, goToBilling, goToPurchase, reload, notify });
const post = (body) => ({
  method: 'POST', credentials: 'include',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify(body),
});

beforeEach(() => {
  authedFetch.mockReset();
  getAccessToken.mockReset().mockResolvedValue('tok');
  reload = vi.fn(async () => {});
  notify = vi.fn();
  goToBilling = vi.fn();
  goToPurchase = vi.fn();
  allSessionUnits = vi.fn(() => units);
});

describe('useTransferSessions open / lookup', () => {
  it('sessionOptions keeps only attended and recoverable-cancelled sessions', () => {
    const t = make();
    expect(t.sessionOptions.value).toEqual([]);
    t.course.value = src;
    expect(t.sessionOptions.value.map((o) => [o.id, o.recoverableCancelled])).toEqual([[1, false], [3, true]]);
  });

  it('open() looks up the same student by id and keeps same-subject other courses only', async () => {
    authedFetch.mockResolvedValue(res(true, { data: [
      { id: 7, student_id: 3, subject_name: '數學' },                         // the source itself
      { id: 8, student_id: 3, student_name: '小明', subject_name: '數學', teacher: { name: '王' } },
      { id: 9, student_id: 3, student_name: '小明', subject_name: '英文', subject: 'english' }, // other subject
      { id: 10, student_id: 4, student_name: '小華', subject_name: '數學' },  // other student
    ] }));
    const t = make();
    t.open(src);
    expect(t.showModal.value).toBe(true);
    expect(t.targetCoursesLoading.value).toBe(true);
    await flush();
    expect(authedFetch).toHaveBeenCalledWith('/api/v1/student-classes?per_page=100&page=1&student_id=3', {
      credentials: 'include', headers: { Accept: 'application/json' },
    }, 'tok');
    expect(t.targetCourses.value.map((c) => [c.id, c.teacher_name])).toEqual([[8, '王']]);
    expect(t.targetCoursesLoading.value).toBe(false);
  });

  it('falls back to the student name, and skips the lookup for date-mode courses', async () => {
    authedFetch.mockResolvedValue(res(true, []));
    make().open({ id: 7, student_name: '小明' });
    await flush();
    expect(authedFetch.mock.calls[0][0]).toBe('/api/v1/student-classes?per_page=100&page=1&name=%E5%B0%8F%E6%98%8E');
    authedFetch.mockClear();
    const t = make();
    t.open({ ...src, schedule_mode: 'Date' });
    await flush();
    expect(authedFetch).not.toHaveBeenCalled();
    expect(t.showModal.value).toBe(true);
  });

  it('a failed lookup leaves the target list empty and stops loading (manual id fallback)', async () => {
    authedFetch.mockRejectedValue(new Error('offline'));
    const t = make();
    t.open(src);
    await flush();
    expect(t.targetCourses.value).toEqual([]);
    expect(t.targetCoursesLoading.value).toBe(false);
  });

  it('openBillingNextStep closes the modal and goes to billing for the course', () => {
    const t = make();
    t.open({ ...src, schedule_mode: 'date' });
    t.openBillingNextStep();
    expect(t.showModal.value).toBe(false);
    expect(goToBilling).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }));
  });
});

describe('useTransferSessions target full (in-app #379)', () => {
  const full = {
    code: 'target_capacity_exceeded',
    message: '目標課程堂數已滿（8/8）。請先在目標課程加買堂數，再轉課。',
    next_actions: [{ code: 'open_target_purchase', label: '前往目標課程加購', available: true, student_class_id: 8 }],
  };

  it('shows the plain message and goToPurchase receives the looked-up target course', async () => {
    authedFetch.mockResolvedValueOnce(res(true, { data: [{ id: 8, student_id: 3, student_name: '小明', subject_name: '數學' }] }));
    const t = make();
    t.open(src);
    await flush();
    authedFetch.mockResolvedValueOnce(res(false, full));
    await t.submit({ targetCourseId: 8, sessionIds: [1], reason: '' });
    expect(t.error.value).toBe(full.message);
    expect(t.nextActions.value).toEqual(full.next_actions);
    t.openTargetPurchase(8);
    expect(t.showModal.value).toBe(false);
    expect(goToPurchase).toHaveBeenCalledWith(expect.objectContaining({ id: 8 }));
  });

  it('keeps the modal open with a hint when the target is not in the looked-up list', () => {
    const t = make();
    t.showModal.value = true;
    t.openTargetPurchase(99);
    expect(t.showModal.value).toBe(true);
    expect(t.error.value).toContain('加購');
    expect(goToPurchase).not.toHaveBeenCalled();
  });
});

describe('useTransferSessions.submit', () => {
  const opened = () => { const t = make(); t.course.value = src; return t; };

  it('does nothing without a course or session ids', async () => {
    await make().submit({ targetCourseId: 8, sessionIds: [1], reason: '' });
    await opened().submit({ targetCourseId: 8, sessionIds: [], reason: '' });
    expect(authedFetch).not.toHaveBeenCalled();
  });

  it('asks to log in again without a token', async () => {
    getAccessToken.mockResolvedValue(undefined);
    const t = opened();
    await t.submit({ targetCourseId: 8, sessionIds: [1], reason: '' });
    expect(t.error.value).toBe('請重新登入後再試');
    expect(authedFetch).not.toHaveBeenCalled();
    expect(t.submitting.value).toBe(false);
  });

  it('posts transfer-sessions without reason, closes, toasts and reloads', async () => {
    authedFetch.mockResolvedValue(res(true, {}));
    const t = opened(); t.showModal.value = true;
    await t.submit({ targetCourseId: 8, sessionIds: [1], reason: 'ignored' });
    expect(authedFetch).toHaveBeenCalledWith('/api/v1/student-classes/7/transfer-sessions',
      post({ session_ids: [1], target_student_class_id: 8 }), 'tok');
    expect(t.showModal.value).toBe(false);
    expect(notify).toHaveBeenCalledWith({
      title: '已轉移堂次紀錄', description: '已轉移 1 堂到課程 #8', variant: 'success', durationMs: 7000,
    });
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('uses recover-transfer-sessions with the reason when a cancelled session is selected', async () => {
    authedFetch.mockResolvedValue(res(true, { message: 'ok' }));
    const t = opened();
    await t.submit({ targetCourseId: 8, sessionIds: [1, 3], reason: '補登' });
    expect(authedFetch).toHaveBeenCalledWith('/api/v1/student-classes/7/recover-transfer-sessions',
      post({ session_ids: [1, 3], target_student_class_id: 8, reason: '補登' }), 'tok');
    expect(notify.mock.calls[0][0].description).toBe('ok');
  });

  it('joins server details, message and conflict; keeps modal open; exposes next_actions', async () => {
    authedFetch.mockResolvedValue(res(false, {
      errors: { a: ['x'], b: ['y'] }, message: 'm', conflict_session_id: 5, next_actions: [{ k: 1 }],
    }));
    const t = opened(); t.showModal.value = true;
    await t.submit({ targetCourseId: 8, sessionIds: [1], reason: '' });
    expect(t.error.value).toBe('x y m 衝突堂次 #5');
    expect(t.nextActions.value).toEqual([{ k: 1 }]);
    expect(t.showModal.value).toBe(true);
    expect(reload).not.toHaveBeenCalled();
    authedFetch.mockResolvedValue(res(false, { conflict_schedule_id: 6 }));
    await t.submit({ targetCourseId: 8, sessionIds: [1], reason: '' });
    expect(t.error.value).toBe('衝突預排 #6');
    authedFetch.mockResolvedValue(res(false, {}));
    await t.submit({ targetCourseId: 8, sessionIds: [1], reason: '' });
    expect(t.error.value).toBe('轉移失敗');
  });

  it('network errors become the 轉移失敗 copy and re-enable submit', async () => {
    authedFetch.mockRejectedValue(new Error('offline'));
    const t = opened();
    await t.submit({ targetCourseId: 8, sessionIds: [1], reason: '' });
    expect(t.error.value).toBe('轉移失敗：offline');
    expect(t.submitting.value).toBe(false);
  });
});
