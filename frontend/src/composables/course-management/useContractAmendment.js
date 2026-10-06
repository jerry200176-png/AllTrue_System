import { ref } from 'vue';
import { authedFetch, getAccessToken } from '../../lib/authedFetch';

// Contract amendment (調整合約總堂數) preview/submit and revert (撤銷調整) flows for
// CourseManagement: modal state, the contract-amendment[/preview|/revert[/preview]]
// requests, the stale-preview guard and their error copy. The page owns the adjustment
// chooser (which course types may amend) and supplies `reload` (list refresh) and
// `notify` (toast) for the post-success UI.
const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };
const NEED_LOGIN = '登入狀態已失效，請重新登入。';

async function post(url, body, fallbackMessage) {
  const token = await getAccessToken();
  if (!token) throw new Error(NEED_LOGIN);
  const res = await authedFetch(url, {
    method: 'POST', credentials: 'include', headers: JSON_HEADERS, body: JSON.stringify(body),
  }, token);
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || fallbackMessage);
  return data;
}

export function useContractAmendment({ reload, notify }) {
  const showModal = ref(false);
  const course = ref(null);
  const preview = ref(null);
  const previewLoading = ref(false);
  const submitting = ref(false);
  const error = ref('');

  function open(c) {
    course.value = c;
    preview.value = null;
    error.value = '';
    showModal.value = true;
  }

  function close() {
    if (submitting.value || previewLoading.value) return;
    showModal.value = false;
    preview.value = null;
    error.value = '';
  }

  async function loadPreview(newSessionCount) {
    const c = course.value;
    if (!c?.id || previewLoading.value) return;
    previewLoading.value = true;
    error.value = '';
    preview.value = null;
    try {
      preview.value = await post(`/api/v1/student-classes/${c.id}/contract-amendment/preview`,
        { new_session_count: Number(newSessionCount) }, '無法預覽合約調整。');
    } catch (e) {
      error.value = e?.message || '無法預覽合約調整。';
    } finally {
      previewLoading.value = false;
    }
  }

  async function submit({ newSessionCount, reason }) {
    const c = course.value;
    if (!c?.id || !preview.value || submitting.value) return;
    if (Number(preview.value.new_session_count) !== Number(newSessionCount)) {
      error.value = '預覽已過期，請重新預覽後再送出。';
      preview.value = null;
      return;
    }
    submitting.value = true;
    error.value = '';
    try {
      const body = await post(`/api/v1/student-classes/${c.id}/contract-amendment`,
        { new_session_count: Number(newSessionCount), reason }, '合約調整失敗。');
      showModal.value = false;
      preview.value = null;
      await reload();
      notify({
        title: '合約已提前結束',
        description: body?.message || `已調整為 ${newSessionCount} 堂；已上課紀錄保留，帳務未變更。`,
        variant: 'success', durationMs: 7000,
      });
    } catch (e) {
      error.value = e?.message || '合約調整失敗。';
    } finally {
      submitting.value = false;
    }
  }

  // Revert (撤銷調整) of an amended course.
  const showRevertModal = ref(false);
  const revertCourse = ref(null);
  const revertPreview = ref(null);
  const revertLoading = ref(false);
  const revertSubmitting = ref(false);
  const revertError = ref('');
  const revertRequest = (path, body) => post(
    `/api/v1/student-classes/${revertCourse.value.id}/contract-amendment/revert${path}`, body, '撤銷調整失敗。');

  async function openRevert(c) {
    revertCourse.value = c;
    revertPreview.value = null;
    revertError.value = '';
    showRevertModal.value = true;
    revertLoading.value = true;
    try {
      revertPreview.value = await revertRequest('/preview', {});
    } catch (e) {
      revertError.value = e?.message || '無法預覽撤銷調整。';
    } finally {
      revertLoading.value = false;
    }
  }

  async function submitRevert(reason) {
    if (revertSubmitting.value) return;
    revertSubmitting.value = true;
    revertError.value = '';
    try {
      const body = await revertRequest('', { reason });
      showRevertModal.value = false;
      await reload();
      notify({ title: '已撤銷調整', description: body?.message, variant: 'success', durationMs: 7000 });
    } catch (e) {
      revertError.value = e?.message || '撤銷調整失敗。';
    } finally {
      revertSubmitting.value = false;
    }
  }

  return {
    showModal, course, preview, previewLoading, submitting, error, open, close, loadPreview, submit,
    showRevertModal, revertCourse, revertPreview, revertLoading, revertSubmitting, revertError, openRevert, submitRevert,
  };
}
