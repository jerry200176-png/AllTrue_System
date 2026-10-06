import { authedFetch, getAccessToken } from '../../lib/authedFetch';
import { canApplyRenewalPreview } from '../../lib/coursePricing';
import {
  applyMonthlyRenewalPreview,
  canSubmitMonthlyRenewal,
  invalidateMonthlyRenewalPreview,
  nextPeriodEnd,
  renewalErrorMessage,
} from '../../lib/monthlyRenewalPreview';

// Monthly renewal API flow shared by CourseManagement and StudentsList.
// The page owns the modal/refs and the post-success UI (toast vs alert, reload);
// this owns the renewal-preview / renew-monthly requests, their preconditions and
// the stale-preview race guard. Money/period rules live in lib/monthlyRenewalPreview.js.
export const RENEW_NEED_LOGIN_PREVIEW = '請重新登入後再預覽新一期。';
export const RENEW_NEED_PREVIEW_ALERT = '請先完成新一期期間預覽與月結核對。';

const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };

export function useMonthlyRenewal({
  form,            // ref: renewMonthlyForm
  warnings,        // ref: renewMonthlyWarnings
  previewRequestId,// ref: renewMonthlyPreviewRequestId
  isModalOpen,     // () => boolean
  currentCourseId, // () => id of the course the modal is open for
}) {
  async function loadPreview(course, requestedEndDate = '') {
    const requestId = ++previewRequestId.value;
    try {
      const token = await getAccessToken();
      if (!token || !course?.id) {
        Object.assign(form.value, { preview_status: 'error', preview_error: RENEW_NEED_LOGIN_PREVIEW });
        return;
      }
      const endDate = requestedEndDate || nextPeriodEnd(course?.end_date || course?.EndDate || null, course?.settlement_day);
      invalidateMonthlyRenewalPreview(form.value, endDate);
      const res = await authedFetch(`/api/v1/student-classes/${course.id}/renewal-preview`, {
        method: 'POST',
        credentials: 'include',
        headers: JSON_HEADERS,
        body: JSON.stringify({ mode: 'renew_monthly', end_date: endDate }),
      }, token);
      const json = await res.json().catch(() => ({}));
      if (!isModalOpen() || !canApplyRenewalPreview({
        requestId,
        currentRequestId: previewRequestId.value,
        courseId: course.id,
        currentCourseId: currentCourseId(),
        requestedEndDate: endDate,
        currentEndDate: form.value.preview_end_date,
      })) return;
      if (res.ok || json.severity === 'blocked') {
        warnings.value = [...(json.warnings || []), ...(json.blockers || [])];
        applyMonthlyRenewalPreview(form.value, json);
      } else {
        Object.assign(form.value, { preview_status: 'error', preview_error: renewalErrorMessage(json, '無法取得期間預覽，請重試。') });
      }
    } catch {
      if (requestId === previewRequestId.value && course?.id === currentCourseId()) {
        Object.assign(form.value, { preview_status: 'error', preview_error: '無法取得期間預覽，請檢查連線後重試。' });
      }
    }
  }

  // POST renew-monthly. Resolves to { status: 'no-token' } | { status: 'error', message } |
  // { status: 'ok', json, token }. Network errors reject so the page keeps its own catch.
  // A NONE discount must be omitted or admin gets 403 (only financial roles may send `discount`).
  async function submit(course, endDate) {
    const token = await getAccessToken();
    if (!token) return { status: 'no-token' };
    const discount = form.value.discount;
    const res = await authedFetch(`/api/v1/student-classes/${course.id}/renew-monthly`, {
      method: 'POST',
      credentials: 'include',
      headers: JSON_HEADERS,
      body: JSON.stringify({ end_date: endDate, ...(discount?.type && discount.type !== 'NONE' ? { discount } : {}) }),
    }, token);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
      return { status: 'error', message: details || json?.message || '續約失敗' };
    }
    return { status: 'ok', json, token };
  }

  return { loadPreview, submit, canSubmit: canSubmitMonthlyRenewal };
}
