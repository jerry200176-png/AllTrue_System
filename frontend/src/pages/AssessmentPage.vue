<template>
  <section class="assessment-page">
    <div class="page-header assessment-header">
      <div>
        <h2>學習檢測</h2>
        <p class="page-desc">建立檢測、記錄學生多次結果，與課後評量分開管理。</p>
      </div>
      <button class="primary" type="button" @click="openCreate">＋建立檢測</button>
    </div>

    <div class="assessment-summary-grid">
      <article class="card summary-card"><span>檢測數</span><strong>{{ summary.assessment_count }}</strong></article>
      <article class="card summary-card"><span>結果筆數</span><strong>{{ summary.result_count }}</strong></article>
      <article class="card summary-card"><span>平均分數</span><strong>{{ summary.average_percent == null ? '—' : `${summary.average_percent}%` }}</strong></article>
      <article class="card summary-card"><span>已審核結果</span><strong>{{ summary.reviewed_count }}</strong></article>
    </div>

    <div class="card assessment-list-card">
      <div class="assessment-list-head">
        <div>
          <h3>檢測清單</h3>
          <p class="muted">目前分校：{{ branchId }}</p>
        </div>
        <button class="ghost" type="button" :disabled="loading" @click="loadAll">重新整理</button>
      </div>
      <div v-if="loading" class="assessment-empty">載入中…</div>
      <div v-else-if="error" class="assessment-error" role="alert">{{ error }} <button class="ghost small" @click="loadAll">重試</button></div>
      <div v-else-if="!assessments.length" class="assessment-empty">目前還沒有檢測。先建立一份基準檢測。</div>
      <div v-else class="assessment-table-wrap">
        <table class="assessment-table">
          <thead><tr><th>檢測</th><th>範圍</th><th>日期</th><th>狀態</th><th>結果</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="assessment in assessments" :key="assessment.id">
              <td><strong>{{ assessment.title }}</strong><small>{{ assessment.assessment_type }}</small></td>
              <td>{{ assessment.student_name || '分校共用' }}</td>
              <td>{{ assessment.scheduled_for || '未設定' }}</td>
              <td><span :class="['status-pill', `status-${assessment.status}`]">{{ statusLabel(assessment.status) }}</span></td>
              <td>{{ assessment.result_count || 0 }}</td>
              <td class="assessment-actions">
                <button class="ghost small" type="button" @click="openAssessment(assessment)">結果</button>
                <button v-if="assessment.status === 'draft'" class="primary small" type="button" @click="publish(assessment)">發布</button>
                <button v-if="assessment.status === 'published' && isDirector" class="ghost small" type="button" @click="closeAssessment(assessment)">關閉</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="showCreate" class="modal-overlay" @click.self="showCreate = false">
      <div class="modal assessment-modal">
        <h3>建立學習檢測</h3>
        <p class="muted">先建立檢測定義，再發布後登錄學生結果。</p>
        <label>檢測名稱<input v-model.trim="createForm.title" maxlength="120" placeholder="例如：英文單字基準檢測" /></label>
        <label>課程範圍
          <select v-model="createForm.student_class_id">
            <option value="">{{ isTeacher ? '老師必須選擇課程' : '分校共用檢測' }}</option>
            <option v-for="item in classes" :key="item.id" :value="String(item.id)">{{ item.student_name }} · {{ item.subject || '未設定科目' }}</option>
          </select>
        </label>
        <div class="assessment-form-grid">
          <label>類型<select v-model="createForm.assessment_type"><option value="baseline">基準</option><option value="checkpoint">階段</option><option value="remediation">補強後</option><option value="other">其他</option></select></label>
          <label>滿分<input v-model.number="createForm.max_score" type="number" min="1" step="0.01" /></label>
          <label>檢測日期<input v-model="createForm.scheduled_for" type="date" /></label>
        </div>
        <label>說明<textarea v-model.trim="createForm.description" maxlength="10000" rows="3" placeholder="記錄檢測範圍或教學目的（選填）" /></label>
        <p v-if="formError" class="assessment-error">{{ formError }}</p>
        <div class="modal-actions"><button class="ghost" @click="showCreate = false">取消</button><button class="primary" :disabled="saving || !createForm.title" @click="createAssessment">{{ saving ? '建立中…' : '建立' }}</button></div>
      </div>
    </div>

    <div v-if="selectedAssessment" class="modal-overlay" @click.self="selectedAssessment = null">
      <div class="modal assessment-modal assessment-result-modal">
        <div class="assessment-modal-head"><div><h3>{{ selectedAssessment.title }}</h3><p class="muted">滿分 {{ selectedAssessment.max_score }} · {{ statusLabel(selectedAssessment.status) }}</p></div><button class="ghost" @click="selectedAssessment = null">關閉</button></div>
        <div v-if="resultsLoading" class="assessment-empty">結果載入中…</div>
        <template v-else>
          <div v-if="!results.length" class="assessment-empty">尚未登錄結果。</div>
          <table v-else class="assessment-table compact"><thead><tr><th>學生</th><th>次數</th><th>分數</th><th>狀態</th><th>操作</th></tr></thead><tbody><tr v-for="result in results" :key="result.id"><td>{{ result.student_name || result.student_id }}</td><td>第 {{ result.attempt_no }} 次</td><td>{{ result.score }}/{{ result.max_score }}（{{ result.percent }}%）</td><td>{{ result.status === 'reviewed' ? '已審核' : '待審' }}</td><td><button v-if="isDirector && result.status === 'submitted'" class="primary small" @click="reviewResult(result)">審核</button></td></tr></tbody></table>
          <div v-if="selectedAssessment.status === 'published'" class="result-entry">
            <h4>登錄學生結果</h4>
            <div class="assessment-form-grid"><label>學生／課程<select v-model="resultForm.student_class_id" @change="syncStudent"><option value="">請選擇</option><option v-for="item in assessmentStudents" :key="item.student_class_id" :value="String(item.student_class_id)">{{ item.name }}</option></select></label><label>分數<input v-model.number="resultForm.score" type="number" min="0" :max="selectedAssessment.max_score" step="0.01" /></label></div>
            <label>備註<textarea v-model.trim="resultForm.notes" rows="2" maxlength="10000" /></label>
            <p v-if="resultError" class="assessment-error">{{ resultError }}</p>
            <button class="primary" :disabled="savingResult || !resultForm.student_id || resultForm.score === ''" @click="saveResult">{{ savingResult ? '儲存中…' : '儲存結果' }}</button>
          </div>
        </template>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({ branchId: [String, Number], userRole: String });
const base = `${import.meta.env.VITE_API_BASE || '/api'}/v1`;
const isTeacher = computed(() => props.userRole === 'teacher');
const isDirector = computed(() => ['director', 'super_admin'].includes(props.userRole));
const assessments = ref([]);
const classes = ref([]);
const summary = reactive({ assessment_count: 0, result_count: 0, average_percent: null, reviewed_count: 0 });
const loading = ref(false);
const error = ref('');
const saving = ref(false);
const showCreate = ref(false);
const formError = ref('');
const selectedAssessment = ref(null);
const results = ref([]);
const assessmentStudents = ref([]);
const resultsLoading = ref(false);
const savingResult = ref(false);
const resultError = ref('');
const createForm = reactive({ title: '', description: '', assessment_type: 'checkpoint', max_score: 100, scheduled_for: '', student_class_id: '' });
const resultForm = reactive({ student_id: '', student_class_id: '', score: '', notes: '' });

function token() {
  try { return JSON.parse(localStorage.getItem('alltrue_session') || '{}')?.access_token || ''; } catch { return ''; }
}
function headers(json = false) { return { Authorization: `Bearer ${token()}`, Accept: 'application/json', ...(json ? { 'Content-Type': 'application/json' } : {}) }; }
async function api(path, options = {}) {
  const response = await fetch(`${base}${path}`, { ...options, headers: { ...headers(Boolean(options.body)), ...(options.headers || {}) } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body?.message || Object.values(body?.errors || {})?.flat?.()?.[0] || '操作失敗');
  return body;
}
function statusLabel(status) { return { draft: '草稿', published: '已發布', closed: '已關閉', archived: '已封存' }[status] || status; }
async function loadAll() {
  if (!props.branchId) return;
  loading.value = true; error.value = '';
  try {
    const [list, stats, optionRows] = await Promise.all([
      api(`/assessments?campus_id=${encodeURIComponent(props.branchId)}&per_page=100`),
      api(`/assessment-reports/summary?campus_id=${encodeURIComponent(props.branchId)}`),
      api(`/assessment-options/classes?campus_id=${encodeURIComponent(props.branchId)}`),
    ]);
    assessments.value = list.data || [];
    Object.assign(summary, stats.data || {});
    classes.value = optionRows.data || [];
  } catch (e) { error.value = e.message; } finally { loading.value = false; }
}
function openCreate() { formError.value = ''; Object.assign(createForm, { title: '', description: '', assessment_type: 'checkpoint', max_score: 100, scheduled_for: '', student_class_id: '' }); showCreate.value = true; }
async function createAssessment() {
  saving.value = true; formError.value = '';
  try {
    const body = { campus_id: Number(props.branchId), title: createForm.title, description: createForm.description || null, assessment_type: createForm.assessment_type, max_score: Number(createForm.max_score), scheduled_for: createForm.scheduled_for || null, student_class_id: createForm.student_class_id ? Number(createForm.student_class_id) : null };
    await api('/assessments', { method: 'POST', body: JSON.stringify(body) }); showCreate.value = false; await loadAll();
  } catch (e) { formError.value = e.message; } finally { saving.value = false; }
}
async function publish(assessment) { try { await api(`/assessments/${assessment.id}/publish`, { method: 'POST' }); await loadAll(); } catch (e) { error.value = e.message; } }
async function closeAssessment(assessment) { if (!window.confirm('關閉後不能再修改檢測定義，確定要關閉嗎？')) return; try { await api(`/assessments/${assessment.id}/close`, { method: 'POST' }); await loadAll(); } catch (e) { error.value = e.message; } }
async function openAssessment(assessment) {
  selectedAssessment.value = assessment; resultsLoading.value = true; resultError.value = ''; Object.assign(resultForm, { student_id: '', student_class_id: '', score: '', notes: '' });
  try { const [resultRows, studentRows] = await Promise.all([api(`/assessments/${assessment.id}/results`), api(`/assessments/${assessment.id}/students`)]); results.value = resultRows.data || []; assessmentStudents.value = studentRows.data || []; } catch (e) { resultError.value = e.message; } finally { resultsLoading.value = false; }
}
function syncStudent() { const row = assessmentStudents.value.find((item) => String(item.student_class_id) === String(resultForm.student_class_id)); resultForm.student_id = row ? String(row.student_id) : ''; }
async function saveResult() {
  savingResult.value = true; resultError.value = '';
  try { await api(`/assessments/${selectedAssessment.value.id}/results`, { method: 'POST', body: JSON.stringify({ student_id: Number(resultForm.student_id), student_class_id: Number(resultForm.student_class_id), score: Number(resultForm.score), notes: resultForm.notes || null }) }); await openAssessment(selectedAssessment.value); await loadAll(); } catch (e) { resultError.value = e.message; } finally { savingResult.value = false; }
}
async function reviewResult(result) { try { await api(`/assessment-results/${result.id}/review`, { method: 'POST' }); await openAssessment(selectedAssessment.value); await loadAll(); } catch (e) { resultError.value = e.message; } }
watch(() => props.branchId, loadAll);
onMounted(loadAll);
</script>

<style scoped>
.assessment-page { max-width: 1440px; margin: 0 auto; }
.assessment-header, .assessment-list-head, .assessment-modal-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.assessment-summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 16px 0; }
.summary-card { padding: 16px; display: flex; flex-direction: column; gap: 6px; }
.summary-card span, .muted { color: var(--ds-text-tertiary); font-size: 13px; }
.summary-card strong { font-size: 28px; color: var(--ds-text-primary); }
.assessment-list-card { padding: 18px; }
.assessment-list-head { margin-bottom: 12px; }
.assessment-table-wrap { overflow-x: auto; }
.assessment-table { width: 100%; border-collapse: collapse; min-width: 760px; }
.assessment-table th, .assessment-table td { padding: 12px 10px; border-bottom: 1px solid var(--ds-hairline); text-align: left; vertical-align: middle; }
.assessment-table td strong, .assessment-table td small { display: block; }
.assessment-table td small { color: var(--ds-text-tertiary); margin-top: 3px; }
.assessment-actions { white-space: nowrap; }
.status-pill { border-radius: 999px; padding: 4px 9px; font-size: 12px; background: var(--ds-surface-2); }
.status-published { background: var(--ds-success-wash); color: var(--ds-success); }.status-draft { color: var(--ds-warning); background: var(--ds-warning-wash); }.status-closed { background: var(--ds-surface-2); color: var(--ds-text-tertiary); }
.assessment-empty { padding: 36px 12px; text-align: center; color: var(--ds-text-tertiary); }
.assessment-error { color: var(--ds-danger); background: var(--ds-danger-wash); border-radius: 8px; padding: 10px 12px; margin: 10px 0; }
.assessment-modal { max-width: 720px; width: calc(100vw - 32px); max-height: min(850px, calc(100vh - 32px)); overflow: auto; }
.assessment-modal label { display: block; margin: 14px 0; font-size: 13px; font-weight: 600; }
.assessment-modal input, .assessment-modal select, .assessment-modal textarea { display: block; width: 100%; margin-top: 6px; border: 1px solid var(--ds-hairline-input); border-radius: 7px; padding: 9px 10px; background: var(--ds-canvas); color: inherit; }
.assessment-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
.compact { min-width: 0; }
.result-entry { border-top: 1px solid var(--ds-hairline); margin-top: 18px; padding-top: 14px; }
.result-entry .assessment-form-grid { grid-template-columns: 1.5fr 1fr; }
@media (max-width: 720px) { .assessment-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .assessment-header { align-items: flex-start; } .assessment-form-grid { grid-template-columns: 1fr; } .result-entry .assessment-form-grid { grid-template-columns: 1fr; } }
</style>
