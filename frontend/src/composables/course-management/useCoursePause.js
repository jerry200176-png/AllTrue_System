import { ref, computed } from 'vue';
import { authedFetch, getAccessToken } from '../../lib/authedFetch';
import { CLOSED_COURSE_RESUME_MESSAGE, isCourseCloseCommitted } from '../../lib/courseActions.js';

// Pause / resume (暫停 / 恢復) confirm flow for CourseManagement: confirm-dialog state,
// the POST /student-classes/:id/pause request and its error copy. The page supplies
// `onChanged` (reload the list + resync the open course manager). Close (結案) shares
// the same endpoint through lib/closeCourseNoRenew.js; the page passes that flow's per-course
// lock as `courseLock` so a pause/resume and a close of the same course never run together.
export const COURSE_LIFECYCLE_BUSY_MESSAGE = '這門課還有其他操作處理中，請稍候再試';
const noLock = { run: (_course, fn) => fn(), isPending: () => false };

// #4188: a closed course is never resumed in place. `askClosed` shows the message with a 續課 button
// (resolves true when chosen) and `openRenewal` opens the page's existing renewal entry.
export function useCoursePause({
  onChanged, notify = (msg) => alert(msg), courseLock = noLock, askClosed = null, openRenewal = null,
}) {
  const target = ref(null);
  const submitting = ref(false);
  const cancelRemaining = ref(true);
  const isResume = computed(() => target.value?.status === 'inactive');
  const impacts = computed(() => isResume.value
    ? ['恢復後可繼續排課與補課', '後續仍依原課程設定計算堂數與提醒', '已取消的未來堂次不會自動重建，需依需要重新排課']
    : [
        cancelRemaining.value ? '取消未來尚未上課堂次' : '不取消剩餘排課（堂次仍會留在行事曆）',
        '暫停期間不排新課、不計入待辦',
        '可從歷史課程或暫停清單恢復',
      ]);

  async function explainClosed(course) {
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

  async function send(course) {
    const paused = course.status === 'inactive';
    const action = paused ? '恢復' : '暫停';
    submitting.value = true;
    try {
      const token = await getAccessToken();
      if (!token) { notify('請重新登入'); return; }

      const body = { action: paused ? 'resume' : 'pause' };
      if (!paused) body.cancel_remaining = !!cancelRemaining.value;

      const res = await authedFetch(`/api/v1/student-classes/${course.id}/pause`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }, token);
      const json = await res.json().catch(() => ({}));
      if (!res.ok && json.error_code === 'course_closed_use_renewal') {
        // The list was stale: the course closed elsewhere. Same explanation as a closed row.
        target.value = null;
        await explainClosed(course);
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
