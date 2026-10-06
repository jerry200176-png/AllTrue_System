import { ref, computed } from 'vue';
import { authedFetch, getAccessToken } from '../../lib/authedFetch';

// Pause / resume (暫停 / 恢復) confirm flow for CourseManagement: confirm-dialog state,
// the POST /student-classes/:id/pause request and its error copy. The page supplies
// `onChanged` (reload the list + resync the open course manager). Close (結案) shares
// the same endpoint through lib/closeCourseNoRenew.js.
export function useCoursePause({ onChanged, notify = (msg) => alert(msg) }) {
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

  function request(course) {
    cancelRemaining.value = true;
    target.value = course;
  }

  async function confirm() {
    if (submitting.value) return;
    const course = target.value;
    if (!course) return;
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
