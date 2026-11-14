import { computed, reactive, ref, watch } from 'vue';
import { authedFetch } from '../../lib/authedFetch';
import { fetchAllPages } from '../../lib/pagedFetchAll.js';
import { getSubjectLabel } from '../../lib/constants';

const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
const newKey = () => globalThis.crypto.randomUUID();
const id = c => Number(c?.id ?? c?.ID);
const studentId = c => Number(c?.student_id ?? c?.StudentID);
const subject = c => String(c?.SubjectID ?? c?.subject ?? c?.subject_name ?? '').trim().toLowerCase();
export const creditCourseLabel = c => `${c?.subject_name || getSubjectLabel(c?.subject) || '課程'} · ${c?.teacher_name || '待指派'}`;

/** #535: server owns eligibility; one frozen intent survives uncertain responses and dialog reopening. */
export function useSubjectCreditTransfer(props, changed) {
  const form = reactive({ target: '', sessions: 1, reason: '' });
  const quote = ref(null), error = ref(''), notice = ref(''), busy = ref(false), loading = ref(false);
  const courses = ref([]), history = ref([]), historyError = ref(''), page = ref(1), lastPage = ref(1), historyReady = ref(false);
  const reversing = ref(null), reverseReason = ref(''), pending = ref(null);
  // In-memory only: no tokens, financial snapshots or reason text in browser storage.
  const intents = new Map();
  let generation = 0;
  let historyRequest = 0;
  const base = () => `/api/v1/student-classes/${id(props.course)}/subject-credit-transfer`;
  const locked = computed(() => busy.value || !!pending.value);
  const studentName = computed(() => String(props.course?.student_name || '').trim());
  const identityReady = computed(() => Number.isSafeInteger(id(props.course)) && id(props.course) > 0
    && Number.isSafeInteger(studentId(props.course)) && studentId(props.course) > 0 && !!studentName.value);
  const candidates = computed(() => courses.value.filter(c => id(c) !== id(props.course)
    && studentId(c) === studentId(props.course) && subject(c) && subject(c) !== subject(props.course)));
  const valid = computed(() => !!form.target && Number.isInteger(form.sessions) && form.sessions > 0
    && form.sessions <= 10000 && !!form.reason.trim() && form.reason.length <= 100);
  const canConfirm = computed(() => identityReady.value && valid.value && !!quote.value?.execution_available && historyReady.value && !locked.value);
  const label = courseId => {
    const c = courses.value.find(c => id(c) === Number(courseId)) || (id(props.course) === Number(courseId) ? props.course : null);
    return c ? creditCourseLabel(c) : '歷史合約（課程資料未載入）';
  };
  watch(() => [form.target, form.sessions, form.reason], () => { if (!pending.value) quote.value = null; });

  async function loadHistory(nextPage = 1, ticket = generation) {
    const request = ++historyRequest;
    historyReady.value = false;
    try {
      const res = await authedFetch(`${base()}?page=${nextPage}`, { headers });
      const data = await res.json();
      if (!res.ok || !Array.isArray(data?.data)) throw new Error(data?.message || '無法讀取轉用紀錄，請重試。');
      if (ticket !== generation || request !== historyRequest) return;
      history.value = data.data; page.value = data.current_page || 1; lastPage.value = data.last_page || 1;
      historyError.value = ''; historyReady.value = true;
    } catch (e) { if (ticket === generation && request === historyRequest) historyError.value = e.message || '無法讀取轉用紀錄，請重試。'; }
  }
  async function loadCourses(ticket) {
    courses.value = [];
    const sid = studentId(props.course);
    if (!Number.isSafeInteger(sid) || sid <= 0) { error.value = '找不到學生，請重新開啟課程。'; return; }
    try {
      const result = await fetchAllPages(`/api/v1/student-classes?student_id=${sid}`, null,
        { perPage: 100, maxPages: 20, fetcher: authedFetch });
      if (ticket === generation) courses.value = result.data;
    } catch { if (ticket === generation) error.value = '無法完整載入這位學生的課程，請關閉後重開。'; }
  }
  watch(() => [props.show, id(props.course)], async ([show]) => {
    const ticket = ++generation;
    if (!show) return;
    quote.value = null; error.value = ''; notice.value = ''; reversing.value = null; reverseReason.value = '';
    history.value = []; historyError.value = ''; historyReady.value = false; pending.value = intents.get(id(props.course)) || null;
    const saved = pending.value;
    Object.assign(form, saved?.type === 'apply' ? saved.form : { target: '', sessions: 1, reason: '' });
    if (saved) { quote.value = saved.quote; reversing.value = saved.record; reverseReason.value = saved.body.reason; }
    loading.value = true;
    await Promise.all([loadCourses(ticket), loadHistory(1, ticket)]);
    if (ticket === generation) loading.value = false;
  }, { immediate: true });

  async function preview() {
    if (!valid.value || locked.value) return;
    busy.value = true; error.value = ''; notice.value = ''; quote.value = null;
    try {
      const res = await authedFetch(`${base()}/preview`, { method: 'POST', headers, body: JSON.stringify({ target_course_id: Number(form.target), sessions: form.sessions }) });
      const data = await res.json();
      if (!res.ok) throw new Error(data?.message || '無法試算，請稍後再試。');
      if (!data?.preview || !data?.plan || !/^[a-f0-9]{64}$/.test(data.state_hash)) throw new Error('試算結果不完整，請重新試算。');
      quote.value = data;
    } catch (e) { error.value = e.message || '連線失敗，請重新試算。'; }
    finally { busy.value = false; }
  }
  async function send(intent) {
    if (busy.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    pending.value = intent; intents.set(id(props.course), intent);
    try {
      const res = await authedFetch(intent.path, { method: 'POST', headers, body: JSON.stringify(intent.body) });
      const data = await res.json().catch(() => null);
      if (!res.ok) {
        // Auth/rate limits and 5xx cannot settle a previous lost response. Preserve the exact request.
        if (res.status >= 400 && res.status < 500 && ![401, 403, 429].includes(res.status)) {
          intents.delete(id(props.course)); pending.value = null; quote.value = null;
        }
        throw new Error(data?.message || '尚未確認結果，請重試確認。');
      }
      if (!data?.transfer?.id) throw new Error('尚未確認結果，請重試確認。');
      intents.delete(id(props.course)); pending.value = null; quote.value = null; reversing.value = null;
      notice.value = intent.type === 'apply' ? '已確認轉用；新批次沒有自動排課，請到該科課程安排上課。' : '已確認還原；原轉用與補償紀錄都會保留。';
      changed();
      await loadHistory();
    } catch (e) { error.value = `${e.message || '連線失敗。'}${pending.value ? ' 尚未確認結果，請按「重試確認結果」，勿另建一筆。重新整理前請先確認結果。' : ''}`; }
    finally { busy.value = false; }
  }
  const confirm = () => canConfirm.value && send({ type: 'apply', path: base(), quote: quote.value, form: { ...form },
    body: { target_course_id: Number(form.target), sessions: form.sessions, reason: form.reason.trim(), state_hash: quote.value.state_hash, idempotency_key: newKey() } });
  function askReverse(record) { if (identityReady.value && !locked.value) { reversing.value = record; reverseReason.value = ''; error.value = ''; notice.value = ''; } }
  const confirmReverse = () => identityReady.value && reversing.value && reverseReason.value.trim() && reverseReason.value.length <= 100 && !locked.value
    && send({ type: 'reverse', record: reversing.value, path: `/api/v1/student-classes/${reversing.value.source_course_id}/subject-credit-transfer/${reversing.value.id}/reverse`, body: { reason: reverseReason.value.trim() } });
  return { form, quote, error, notice, busy, loading, locked, studentName, identityReady, candidates, history, historyError, page, lastPage,
    reversing, reverseReason, pending, valid, canConfirm, label, loadHistory, preview, confirm, askReverse, confirmReverse, retry: () => identityReady.value && pending.value && send(pending.value) };
}
