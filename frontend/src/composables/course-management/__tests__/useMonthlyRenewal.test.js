import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

const authedFetch = vi.fn();
const getAccessToken = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({
  authedFetch: (...a) => authedFetch(...a),
  getAccessToken: (...a) => getAccessToken(...a),
}));

import { useMonthlyRenewal, RENEW_NEED_LOGIN_PREVIEW } from '../useMonthlyRenewal';

const res = (ok, json) => ({ ok, json: async () => json });
function setup({ open = true, courseId = 5, discount } = {}) {
  const form = ref({ discount });
  const warnings = ref([]);
  const previewRequestId = ref(0);
  const state = { open, courseId };
  const r = useMonthlyRenewal({
    form, warnings, previewRequestId,
    isModalOpen: () => state.open, currentCourseId: () => state.courseId,
  });
  return { r, form, warnings, previewRequestId, state };
}
const course = { id: 5, end_date: '2026-09-30', settlement_day: null };

beforeEach(() => {
  authedFetch.mockReset();
  getAccessToken.mockReset().mockResolvedValue('tok');
});

describe('useMonthlyRenewal.loadPreview', () => {
  it('posts renewal-preview with the next period end and applies a ready preview', async () => {
    authedFetch.mockResolvedValue(res(true, { warnings: ['w'], proposed_course: { start_date: '2026-10-01' } }));
    const { r, form, warnings } = setup();
    await r.loadPreview(course);
    expect(authedFetch).toHaveBeenCalledWith('/api/v1/student-classes/5/renewal-preview', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ mode: 'renew_monthly', end_date: '2026-10-31' }),
    }, 'tok');
    expect(form.value).toMatchObject({ preview_status: 'ready', preview_end_date: '2026-10-31', preview_start_date: '2026-10-01', preview_blocked: false });
    expect(warnings.value).toEqual(['w']);
  });

  it('uses an explicit end date and treats severity=blocked as an applied (blocked) preview', async () => {
    authedFetch.mockResolvedValue(res(false, { severity: 'blocked', blockers: ['b'] }));
    const { r, form, warnings } = setup();
    await r.loadPreview(course, '2026-11-15');
    expect(JSON.parse(authedFetch.mock.calls[0][1].body).end_date).toBe('2026-11-15');
    expect(form.value).toMatchObject({ preview_status: 'ready', preview_blocked: true });
    expect(warnings.value).toEqual(['b']);
  });

  it('shows a re-login error and sends nothing without token or course id', async () => {
    getAccessToken.mockResolvedValue(undefined);
    const a = setup();
    await a.r.loadPreview(course);
    expect(a.form.value).toMatchObject({ preview_status: 'error', preview_error: RENEW_NEED_LOGIN_PREVIEW });
    getAccessToken.mockResolvedValue('tok');
    const b = setup();
    await b.r.loadPreview({});
    expect(b.form.value.preview_error).toBe(RENEW_NEED_LOGIN_PREVIEW);
    expect(authedFetch).not.toHaveBeenCalled();
  });

  it('reports server errors and network errors with the current copy', async () => {
    authedFetch.mockResolvedValueOnce(res(false, { message: 'nope' }));
    const a = setup();
    await a.r.loadPreview(course);
    expect(a.form.value.preview_status).toBe('error');
    authedFetch.mockRejectedValueOnce(new Error('offline'));
    const b = setup();
    await b.r.loadPreview(course);
    expect(b.form.value.preview_error).toBe('無法取得期間預覽，請檢查連線後重試。');
  });

  it('drops a stale response when the modal closed, was re-requested or switched course', async () => {
    let resolve;
    authedFetch.mockReturnValue(new Promise((r) => { resolve = r; }));
    const { r, form, previewRequestId } = setup();
    const p = r.loadPreview(course);
    await Promise.resolve(); await Promise.resolve();
    previewRequestId.value += 1; // modal closed / newer request
    resolve(res(true, { proposed_course: { start_date: '2026-10-01' } }));
    await p;
    expect(form.value.preview_status).toBe('loading');

    authedFetch.mockResolvedValue(res(true, {}));
    const closed = setup({ open: false });
    await closed.r.loadPreview(course);
    expect(closed.form.value.preview_status).toBe('loading');
  });
});

describe('useMonthlyRenewal.submit', () => {
  it('posts renew-monthly with only end_date when the discount is absent or NONE', async () => {
    authedFetch.mockResolvedValue(res(true, { new_course: { id: 9 } }));
    for (const discount of [undefined, { type: 'NONE', value: '0' }]) {
      authedFetch.mockClear();
      const { r } = setup({ discount });
      const out = await r.submit(course, '2026-10-31');
      expect(authedFetch).toHaveBeenCalledWith('/api/v1/student-classes/5/renew-monthly', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: '{"end_date":"2026-10-31"}',
      }, 'tok');
      expect(out).toEqual({ status: 'ok', json: { new_course: { id: 9 } }, token: 'tok' });
    }
  });

  it('includes a real discount after end_date', async () => {
    authedFetch.mockResolvedValue(res(true, {}));
    const discount = { type: 'FIXED', value: '500', reason: 'x' };
    await setup({ discount }).r.submit(course, '2026-10-31');
    expect(authedFetch.mock.calls[0][1].body).toBe(JSON.stringify({ end_date: '2026-10-31', discount }));
  });

  it('returns no-token without any request', async () => {
    getAccessToken.mockResolvedValue(undefined);
    expect(await setup().r.submit(course, '2026-10-31')).toEqual({ status: 'no-token' });
    expect(authedFetch).not.toHaveBeenCalled();
  });

  it('maps failures to the first available message', async () => {
    authedFetch.mockResolvedValueOnce(res(false, { errors: { a: ['x', 'y'], b: ['z'] }, message: 'm' }));
    expect(await setup().r.submit(course, 'e')).toEqual({ status: 'error', message: 'x y z' });
    authedFetch.mockResolvedValueOnce(res(false, { message: 'm' }));
    expect((await setup().r.submit(course, 'e')).message).toBe('m');
    authedFetch.mockResolvedValueOnce({ ok: false, json: async () => { throw new Error('bad'); } });
    expect((await setup().r.submit(course, 'e')).message).toBe('續約失敗');
  });

  it('lets network errors reject so each page keeps its own catch', async () => {
    authedFetch.mockRejectedValue(new Error('offline'));
    await expect(setup().r.submit(course, 'e')).rejects.toThrow('offline');
  });
});

// Remaining page-level differences are intentional and pinned here (see PR description).
describe('page wiring', () => {
  const cm = readFileSync(`${process.cwd()}/src/pages/CourseManagement.vue`, 'utf8');
  const sl = readFileSync(`${process.cwd()}/src/pages/StudentsList.vue`, 'utf8');
  const fnBody = (src, head) => src.slice(src.indexOf(head), src.indexOf(head) + 1800);

  it('both pages route through the composable and no longer hit the endpoints directly', () => {
    for (const src of [cm, sl]) {
      expect(src).toContain('useMonthlyRenewal({');
      expect(src).not.toContain('/renew-monthly`');
      expect(src).not.toContain('/renewal-preview`');
    }
  });

  it('both pages require a ready preview before submit (shared canSubmit gate)', () => {
    expect(fnBody(cm, 'async function submitRenewMonthly(endDate)')).toContain('canSubmitMonthlyRenewal(renewMonthlyForm.value, endDate)');
    const slBody = fnBody(sl, 'const submitRenewMonthly = async (endDate)');
    expect(slBody).toContain('monthlyRenewal.canSubmit(renewMonthlyForm.value, endDate)');
    expect(slBody).toContain('RENEW_NEED_PREVIEW_ALERT');
    expect(slBody.indexOf('canSubmit(')).toBeLessThan(slBody.indexOf('monthlyRenewal.submit('));
  });

  it('StudentsList stays silent for a missing target course; CourseManagement shows the login error', () => {
    expect(fnBody(sl, 'function loadRenewMonthlyPreview(endDate')).toContain('if (!course?.id) return;');
  });
});
