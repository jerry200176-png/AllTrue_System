import { ref, computed } from 'vue';
import { authedFetch, getAccessToken } from '../../lib/authedFetch';
import { CLOSED_COURSE_RESUME_MESSAGE, CONVERTED_TRIAL_RESUME_MESSAGE, isCourseCloseCommitted } from '../../lib/courseActions.js';

// Pause / resume (暫停 / 恢復) confirm flow for CourseManagement: confirm-dialog state,
// the POST /student-classes/:id/pause request and its error copy. The page supplies
// `onChanged` (reload the list + resync the open course manager). Close (結案) shares
// the same endpoint through lib/closeCourseNoRenew.js; the page passes that flow's per-course
// lock as `courseLock` so a pause/resume and a close of the same course never run together.
export const COURSE_LIFECYCLE_BUSY_MESSAGE = '這門課還有其他操作處理中，請稍候再試';
const noLock = { run: (_course, fn) => fn(), isPending: () => false };

// #4188: a closed course is never resumed in place. `askClosed` shows the message with a 續課 button
// (resolves true when chosen) and `openRenewal` opens the page's existing renewal entry.
export function useCoursePause({ onChanged, notify = (msg) => alert(msg), courseLock = noLock }) {
  // Read from the same argument so the #4159 signature line stays as merged.
  const { askClosed = null, openRenewal = null, isSuperAdmin = () => false, askReason = (msg) => window.prompt(msg) } = arguments[0] || {};
  const target = ref(null);
  const submitting = ref(false);
  const cancelRemaining = ref(true);
  const isResume = computed(() => target.value?.status === 'inactive');
  const impacts = computed(() => isResume.value
    ? ['恢復後可繼續排課與補課', '後續仍依原課程設定計算堂數與提醒', '恢復成功時，會接回因暫停、結案或停用而取消的排課；其他另行取消的堂次不會一併恢復']
    : [
        cancelRemaining.value ? '取消未來尚未上課堂次' : '不取消剩餘排課（堂次仍會留在行事曆）',
        '暫停期間不排新課、不計入待辦',
        '可從歷史課程或暫停清單恢復',
      ]);

  async function explainClosed(course) {
    // A converted trial has no renewal of its own (convertTrial refuses it again); point to the formal course.
    if (course?.closed_reason === 'converted_trial') { notify(CONVERTED_TRIAL_RESUME_MESSAGE); return; }
    // Waived (確認不收) is terminal: no renewal offer; same wording as the server's refusal.
    if (course?.closed_reason === 'waived') { notify('此合約已確認不收，不能恢復或變更狀態'); return; }
    // Founder 2026-10-28: a super admin may undo a mistaken close, with a reason (server audits it).
    if (isSuperAdmin()) {
      const reason = String(askReason('這門課已經結案。超級管理員可以「復原結案」（會留下稽核紀錄）；要改用續課請按取消。\n請輸入復原原因：') || '').trim();
      if (reason) { await courseLock.run(course, () => send(course, reason)); return; }
    }
    if (!askClosed || !openRenewal) { notify(CLOSED_COURSE_RESUME_MESSAGE); return; }
    const goRenew = await askClosed({
      title: '這門課已經結案', message: CLOSED_COURSE_RESUME_MESSAGE, confirmLabel: '前往續課', cancelLabel: '關閉',
    });
    if (goRenew) openRenewal(course);
  }

  function request(course) {
    if (courseLock.isPending(course)) { notify(COURSE_LIFECYCLE_BUSY_MESSAGE); return; }
    if (isCourseCloseCommitted(course)) { target.value = null; return explainClosed(course); }
    cancelRemaining.value = true;
    target.value = course;
  }

  async function confirm() {
    if (submitting.value) return;
    const course = target.value;
    if (!course) return;
    if (courseLock.isPending(course)) { notify(COURSE_LIFECYCLE_BUSY_MESSAGE); return; }
    if (isCourseCloseCommitted(course)) { target.value = null; await explainClosed(course); return; }
    await courseLock.run(course, () => send(course));
  }

  async function send(course, reopenReason = '') {
    const paused = course.status === 'inactive' || !!reopenReason;
    const action = paused ? '恢復' : '暫停';
    submitting.value = true;
    try {
      const token = await getAccessToken();
      if (!token) { notify('請重新登入'); return; }

      const body = { action: paused ? 'resume' : 'pause' };
      if (!paused) body.cancel_remaining = !!cancelRemaining.value;
      if (reopenReason) body.reopen_reason = reopenReason;

      const res = await authedFetch(`/api/v1/student-classes/${course.id}/pause`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }, token);
      const json = await res.json().catch(() => ({}));
      if (!res.ok && json.error_code === 'course_closed_use_renewal' && !reopenReason) {
        // The list was stale: the course closed elsewhere. Explain it with the server's close reason
        // (a trial converted elsewhere must not be sent back to conversion), then reload.
        target.value = null;
        await explainClosed({ ...course, status: 'inactive', closed_reason: json.closed_reason || course.closed_reason });
        await onChanged();
        return;
      }
      if (!res.ok && json.error_code === 'course_already_closed') {
        // A pause after another tab's close: show the server message and reload so the row is fresh.
        target.value = null;
        notify(json.message || '這門課已經結案，不能再暫停。');
        await onChanged();
        return;
      }
      if (!res.ok) {
        notify(`${action}失敗：` + (json.message || res.statusText));
        return;
      }
      notify(json.message || `已${action}`);
      target.value = null;
      await onChanged();
    } catch (e) {
      notify('操作失敗：' + (e?.message || '請稍後再試'));
    } finally {
      submitting.value = false;
    }
  }

  return { target, submitting, cancelRemaining, isResume, impacts, request, confirm };
}
