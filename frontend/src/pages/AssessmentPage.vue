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
      <article class="card summary-card"><span>待補強</span><strong>{{ summary.remediation_open_count }}</strong></article>
      <article class="card summary-card"><span>逾期補強</span><strong>{{ summary.remediation_overdue_count }}</strong></article>
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
          <section v-if="selectedAssessment.status === 'draft'" class="question-builder">
            <div class="assessment-modal-head"><div><h4>配置檢測題目</h4><p class="muted">只可選擇已核准題目；發布後會固定題目版本。</p></div><button class="ghost small" :disabled="questionLoading" @click="loadQuestionCatalog">重新載入題庫</button></div>
            <p v-if="questionError" class="assessment-error">{{ questionError }}</p>
            <div v-if="questionLoading" class="assessment-empty compact-empty">題庫載入中…</div>
            <div v-else-if="!questionCatalog.length" class="assessment-empty compact-empty">目前沒有可用的已核准題目，請先到題庫完成核准。</div>
            <div v-else class="question-picker">
              <label v-for="question in questionCatalog" :key="question.id" class="question-picker-row"><input v-model="selectedQuestionIds" type="checkbox" :value="Number(question.id)" /><span><strong>{{ question.prompt }}</strong><small>{{ question.bank_name }} · {{ question.knowledge_tag }} · 難度 {{ question.difficulty }}</small></span></label>
            </div>
            <button class="primary" :disabled="savingQuestions || !selectedQuestionIds.length || questionRows.length" @click="configureQuestions">{{ questionRows.length ? `已配置 ${questionRows.length} 題` : (savingQuestions ? '配置中…' : `配置 ${selectedQuestionIds.length} 題`) }}</button>
          </section>
          <div v-if="!results.length" class="assessment-empty">尚未登錄結果。</div>
          <table v-else class="assessment-table compact"><thead><tr><th>學生</th><th>次數</th><th>分數</th><th>狀態</th><th>補強</th><th>操作</th></tr></thead><tbody><tr v-for="result in results" :key="result.id"><td>{{ result.student_name || result.student_id }}</td><td>第 {{ result.attempt_no }} 次</td><td>{{ result.score }}/{{ result.max_score }}（{{ result.percent }}%）</td><td>{{ result.status === 'reviewed' ? '已審核' : '待審' }}</td><td><button class="ghost small" @click="openRemediation(result)">{{ result.remediation_count || 0 }} 筆</button></td><td><button v-if="isDirector && result.status === 'submitted'" class="primary small" @click="reviewResult(result)">審核</button></td></tr></tbody></table>
          <section v-if="selectedAssessment.status === 'published' && questionRows.length" class="attempt-panel">
            <div class="assessment-modal-head"><div><h4>數位作答</h4><p class="muted">教職員代學生開啟作答；客觀題自動評分，簡答題送主任複核。</p></div><span class="status-pill status-published">{{ questionRows.length }} 題</span></div>
            <div class="assessment-form-grid attempt-start"><label>學生／課程<select v-model="attemptForm.student_class_id" @change="syncAttemptStudent"><option value="">請選擇</option><option v-for="item in assessmentStudents" :key="item.student_class_id" :value="String(item.student_class_id)">{{ item.name }}</option></select></label><button class="primary" :disabled="attemptSaving || !attemptForm.student_class_id" @click="startAttempt">{{ attemptSaving ? '建立中…' : '開始一次作答' }}</button></div>
            <p v-if="attemptError" class="assessment-error">{{ attemptError }}</p>
            <div v-if="attempts.length" class="attempt-list"><div v-for="attempt in attempts" :key="attempt.id" class="attempt-row"><div><strong>{{ attempt.student_name || attempt.student_id }}</strong><small>第 {{ attempt.attempt_no }} 次 · {{ attemptStatusLabel(attempt.status) }}</small></div><span>{{ attempt.score == null ? '尚未計分' : `${attempt.score}/${attempt.max_score}（${attempt.percent}%）` }}</span><button class="ghost small" @click="openAttempt(attempt.id)">{{ attempt.status === 'submitted' && isDirector ? '複核' : '檢視' }}</button></div></div>
            <div v-if="attemptLoading" class="assessment-empty compact-empty">作答資料載入中…</div>
            <div v-if="activeAttempt" class="attempt-editor"><div class="assessment-modal-head"><h4>{{ activeAttempt.student_name || activeAttempt.student_id }} · 第 {{ activeAttempt.attempt_no }} 次</h4><span class="status-pill" :class="'status-' + activeAttempt.status">{{ attemptStatusLabel(activeAttempt.status) }}</span></div><div v-for="question in activeAttempt.questions" :key="question.id" class="attempt-question"><p><strong>{{ question.position }}. {{ question.prompt }}</strong><small>{{ question.knowledge_tag }} · 難度 {{ question.difficulty }}</small></p><div v-if="question.question_type === 'single_choice' || question.question_type === 'true_false'" class="choice-list"><label v-for="choice in (question.choices || (question.question_type === 'true_false' ? ['true', 'false'] : []))" :key="choice"><input v-model="answerDraft[String(question.id)]" type="radio" :name="'q-' + question.id" :value="choice" :disabled="activeAttempt.status !== 'in_progress'" />{{ choice }}</label></div><div v-else-if="question.question_type === 'multiple_choice'" class="choice-list"><label v-for="choice in (question.choices || [])" :key="choice"><input v-model="answerDraft[String(question.id)]" type="checkbox" :value="choice" :disabled="activeAttempt.status !== 'in_progress'" />{{ choice }}</label></div><textarea v-else v-model="answerDraft[String(question.id)]" rows="2" :disabled="activeAttempt.status !== 'in_progress'" placeholder="填寫學生答案" /></div><div v-if="activeAttempt.status === 'in_progress'" class="modal-actions"><button class="ghost" :disabled="attemptSaving" @click="saveAttempt(false)">儲存草稿</button><button class="primary" :disabled="attemptSaving" @click="saveAttempt(true)">{{ attemptSaving ? '送出中…' : '送出作答' }}</button></div><div v-if="isDirector && activeAttempt.status === 'submitted'" class="review-editor"><h4>簡答人工複核</h4><div v-for="answer in activeAttempt.answers.filter((row) => row.status === 'needs_review')" :key="answer.id" class="review-row"><div><strong>{{ answer.position }}. {{ answer.prompt }}</strong><small>學生答案：{{ answer.answer || '未作答' }}</small></div><input v-model.number="reviewScores[answer.id]" type="number" min="0" :max="answer.max_score" step="0.01" placeholder="分數" /></div><button class="primary" :disabled="attemptSaving" @click="reviewAttempt">{{ attemptSaving ? '送出中…' : '完成人工複核' }}</button></div></div>
          </section>
          <div v-else-if="selectedAssessment.status === 'published'" class="assessment-empty compact-empty">尚未配置題目；此檢測仍可使用下方的紙本結果登錄。</div>
          <div v-if="selectedResult" class="remediation-panel">
            <div class="assessment-modal-head"><div><h4>補強追蹤：{{ selectedResult.student_name || selectedResult.student_id }}</h4><p class="muted">從檢測結果建立知識缺口與後續行動。</p></div><button class="ghost small" @click="selectedResult = null">收合</button></div>
            <div v-if="remediationLoading" class="assessment-empty">補強資料載入中…</div>
            <template v-else>
              <p v-if="remediationError" class="assessment-error">{{ remediationError }}</p>
              <div v-if="!remediationActions.length" class="assessment-empty compact-empty">尚未建立補強行動。</div>
              <div v-for="action in remediationActions" :key="action.id" class="remediation-row">
                <div><strong>{{ action.knowledge_tag }}</strong><small>{{ action.plan || '未填寫計畫' }}<span v-if="action.due_date"> · 到期 {{ action.due_date }}</span></small></div>
                <span :class="['status-pill', 'status-' + action.status]">{{ remediationStatusLabel(action.status) }}</span>
                <button v-if="action.status === 'open'" class="ghost small" @click="updateRemediation(action, 'in_progress')">開始</button>
                <button v-if="action.status === 'in_progress'" class="primary small" @click="updateRemediation(action, 'completed')">完成</button>
              </div>
              <div class="remediation-form">
                <div class="assessment-form-grid"><label>知識缺口<input v-model.trim="remediationForm.knowledge_tag" maxlength="120" placeholder="例如：英文／過去式" /></label><label>行動類型<select v-model="remediationForm.action_type"><option value="practice">練習</option><option value="retake">重測</option><option value="teacher_followup">老師追蹤</option><option value="other">其他</option></select></label><label>預計完成<input v-model="remediationForm.due_date" type="date" /></label></div>
                <label>補強計畫<textarea v-model.trim="remediationForm.plan" rows="2" maxlength="10000" placeholder="描述學生下一步要完成的練習或教學安排" /></label>
                <button class="primary" :disabled="savingRemediation || !remediationForm.knowledge_tag" @click="createRemediation">{{ savingRemediation ? '建立中…' : '建立補強行動' }}</button>
              </div>
            </template>
          </div>
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
import { answerMapFromAttempt, attemptStatusLabel, buildAnswerPayload } from '../lib/assessmentRunner.js';

const props = defineProps({ branchId: [String, Number], userRole: String });
const base = `${import.meta.env.VITE_API_BASE || '/api'}/v1`;
const isTeacher = computed(() => props.userRole === 'teacher');
const isDirector = computed(() => ['director', 'super_admin'].includes(props.userRole));
const assessments = ref([]);
const classes = ref([]);
const summary = reactive({ assessment_count: 0, result_count: 0, average_percent: null, reviewed_count: 0, remediation_open_count: 0, remediation_overdue_count: 0, remediation_completed_count: 0 });
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
const selectedResult = ref(null);
const remediationActions = ref([]);
const remediationLoading = ref(false);
const remediationError = ref('');
const savingRemediation = ref(false);
const createForm = reactive({ title: '', description: '', assessment_type: 'checkpoint', max_score: 100, scheduled_for: '', student_class_id: '' });
const resultForm = reactive({ student_id: '', student_class_id: '', score: '', notes: '' });
const remediationForm = reactive({ knowledge_tag: '', action_type: 'practice', plan: '', due_date: '' });
const questionRows = ref([]);
const questionCatalog = ref([]);
const selectedQuestionIds = ref([]);
const questionLoading = ref(false);
const questionError = ref('');
const savingQuestions = ref(false);
const attempts = ref([]);
const attemptForm = reactive({ student_class_id: '' });
const activeAttempt = ref(null);
const answerDraft = reactive({});
const attemptLoading = ref(false);
const attemptSaving = ref(false);
const attemptError = ref('');
const reviewScores = reactive({});

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
function remediationStatusLabel(status) { return { open: '待處理', in_progress: '進行中', completed: '已完成', cancelled: '已取消' }[status] || status; }
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
  selectedResult.value = null; activeAttempt.value = null; attemptError.value = ''; questionError.value = '';
  selectedAssessment.value = assessment; resultsLoading.value = true; resultError.value = ''; Object.assign(resultForm, { student_id: '', student_class_id: '', score: '', notes: '' });
  Object.assign(attemptForm, { student_class_id: '' });
  try {
    const [resultResponse, studentResponse, questionResponse, attemptResponse] = await Promise.all([
      api(`/assessments/${assessment.id}/results`),
      api(`/assessments/${assessment.id}/students`),
      api(`/assessments/${assessment.id}/questions`),
      assessment.status === 'published' ? api(`/assessments/${assessment.id}/attempts`) : Promise.resolve({ data: [] }),
    ]);
    results.value = resultResponse.data || []; assessmentStudents.value = studentResponse.data || [];
    questionRows.value = questionResponse.data || []; attempts.value = attemptResponse.data || [];
    if (assessment.status === 'draft') await loadQuestionCatalog();
  } catch (e) { resultError.value = e.message; } finally { resultsLoading.value = false; }
}
function syncStudent() { const row = assessmentStudents.value.find((item) => String(item.student_class_id) === String(resultForm.student_class_id)); resultForm.student_id = row ? String(row.student_id) : ''; }
async function saveResult() {
  savingResult.value = true; resultError.value = '';
  try { await api(`/assessments/${selectedAssessment.value.id}/results`, { method: 'POST', body: JSON.stringify({ student_id: Number(resultForm.student_id), student_class_id: Number(resultForm.student_class_id), score: Number(resultForm.score), notes: resultForm.notes || null }) }); await openAssessment(selectedAssessment.value); await loadAll(); } catch (e) { resultError.value = e.message; } finally { savingResult.value = false; }
}
async function reviewResult(result) { try { await api(`/assessment-results/${result.id}/review`, { method: 'POST' }); await openAssessment(selectedAssessment.value); await loadAll(); } catch (e) { resultError.value = e.message; } }
async function openRemediation(result) {
  selectedResult.value = result; remediationLoading.value = true; remediationError.value = '';
  Object.assign(remediationForm, { knowledge_tag: '', action_type: 'practice', plan: '', due_date: '' });
  try { const response = await api('/assessment-results/' + result.id + '/remediation-actions'); remediationActions.value = response.data || []; } catch (e) { remediationError.value = e.message; } finally { remediationLoading.value = false; }
}
async function createRemediation() {
  savingRemediation.value = true; remediationError.value = '';
  try {
    const body = { knowledge_tag: remediationForm.knowledge_tag, action_type: remediationForm.action_type, plan: remediationForm.plan || null, due_date: remediationForm.due_date || null };
    await api('/assessment-results/' + selectedResult.value.id + '/remediation-actions', { method: 'POST', body: JSON.stringify(body) });
    await openRemediation(selectedResult.value); await loadAll();
  } catch (e) { remediationError.value = e.message; } finally { savingRemediation.value = false; }
}
async function updateRemediation(action, status) {
  remediationError.value = '';
  try { await api('/assessment-remediation-actions/' + action.id, { method: 'PATCH', body: JSON.stringify({ status }) }); await openRemediation(selectedResult.value); await loadAll(); } catch (e) { remediationError.value = e.message; }
}
async function loadQuestionCatalog() {
  questionLoading.value = true; questionError.value = '';
  try {
    const banks = await api(`/question-banks?campus_id=${encodeURIComponent(props.branchId)}`);
    const rows = await Promise.all((banks.data || []).map(async (bank) => {
      const response = await api(`/question-banks/${bank.id}/items?status=approved`);
      return (response.data || []).map((item) => ({ ...item, bank_name: bank.name }));
    }));
    const latestByKey = new Map();
    rows.flat().forEach((item) => {
      const key = item.question_key || `item-${item.id}`;
      const current = latestByKey.get(key);
      if (!current || Number(item.version_no || 0) > Number(current.version_no || 0)) latestByKey.set(key, item);
    });
    questionCatalog.value = [...latestByKey.values()];
    selectedQuestionIds.value = questionRows.value.map((question) => Number(question.question_bank_item_id));
  } catch (e) { questionError.value = e.message; } finally { questionLoading.value = false; }
}
async function configureQuestions() {
  if (!selectedAssessment.value || !selectedQuestionIds.value.length) return;
  savingQuestions.value = true; questionError.value = '';
  try {
    const response = await api(`/assessments/${selectedAssessment.value.id}/questions`, { method: 'POST', body: JSON.stringify({ question_bank_item_ids: selectedQuestionIds.value }) });
    questionRows.value = response.data || []; await loadAll();
  } catch (e) { questionError.value = e.message; } finally { savingQuestions.value = false; }
}
function syncAttemptStudent() { attemptError.value = ''; }
async function startAttempt() {
  const row = assessmentStudents.value.find((item) => String(item.student_class_id) === String(attemptForm.student_class_id));
  if (!row) return;
  attemptSaving.value = true; attemptError.value = '';
  try {
    const response = await api(`/assessments/${selectedAssessment.value.id}/attempts`, { method: 'POST', body: JSON.stringify({ student_id: Number(row.student_id), student_class_id: Number(row.student_class_id) }) });
    await openAttempt(response.data.id);
    attempts.value = [{ ...response.data }, ...attempts.value];
  } catch (e) { attemptError.value = e.message; } finally { attemptSaving.value = false; }
}
async function openAttempt(attemptId) {
  attemptLoading.value = true; attemptError.value = '';
  try {
    const response = await api(`/assessment-attempts/${attemptId}`); activeAttempt.value = response.data; Object.assign(answerDraft, answerMapFromAttempt(response.data));
    Object.keys(reviewScores).forEach((key) => delete reviewScores[key]);
    (response.data.answers || []).forEach((answer) => { if (answer.status === 'needs_review') reviewScores[answer.id] = answer.score ?? ''; });
  } catch (e) { attemptError.value = e.message; } finally { attemptLoading.value = false; }
}
async function saveAttempt(submit = false) {
  if (!activeAttempt.value) return;
  attemptSaving.value = true; attemptError.value = '';
  try {
    const saved = await api(`/assessment-attempts/${activeAttempt.value.id}/answers`, { method: 'PATCH', body: JSON.stringify({ answers: buildAnswerPayload(activeAttempt.value.questions, answerDraft) }) });
    activeAttempt.value = saved.data;
    if (submit) { const submitted = await api(`/assessment-attempts/${activeAttempt.value.id}/submit`, { method: 'POST' }); activeAttempt.value = submitted.data; await refreshAttempts(); }
  } catch (e) { attemptError.value = e.message; } finally { attemptSaving.value = false; }
}
async function refreshAttempts() { const response = await api(`/assessments/${selectedAssessment.value.id}/attempts`); attempts.value = response.data || []; }
async function reviewAttempt() {
  if (!activeAttempt.value) return;
  attemptSaving.value = true; attemptError.value = '';
  try {
    const reviews = (activeAttempt.value.answers || []).filter((answer) => answer.status === 'needs_review').map((answer) => ({ answer_id: answer.id, score: Number(reviewScores[answer.id] ?? 0), review_note: answer.review_note || null }));
    const response = await api(`/assessment-attempts/${activeAttempt.value.id}/review`, { method: 'POST', body: JSON.stringify({ reviews }) });
    activeAttempt.value = response.data; await refreshAttempts();
  } catch (e) { attemptError.value = e.message; } finally { attemptSaving.value = false; }
}
watch(() => props.branchId, loadAll);
onMounted(loadAll);
</script>

<style scoped>
.assessment-page { max-width: 1440px; margin: 0 auto; }
.assessment-header, .assessment-list-head, .assessment-modal-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.assessment-summary-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; margin: 16px 0; }
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
.status-published, .status-completed, .status-reviewed { background: var(--ds-success-wash); color: var(--ds-success); }.status-draft, .status-open, .status-submitted { color: var(--ds-warning); background: var(--ds-warning-wash); }.status-closed, .status-cancelled { background: var(--ds-surface-2); color: var(--ds-text-tertiary); }.status-in_progress { background: var(--ds-info-wash); color: var(--ds-info); }
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
.remediation-panel { margin-top: 16px; padding: 14px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); background: var(--ds-surface-0); }
.remediation-row { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--ds-hairline); }
.remediation-row strong, .remediation-row small { display: block; }.remediation-row small { color: var(--ds-text-tertiary); margin-top: 3px; }
.remediation-form { margin-top: 12px; }.compact-empty { padding: 14px 8px; }
.question-builder, .attempt-panel { margin: 16px 0; padding: 14px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); background: var(--ds-surface-0); }
.question-picker { display: grid; gap: 8px; max-height: 260px; overflow: auto; margin: 12px 0; }
.question-picker-row { display: grid !important; grid-template-columns: auto minmax(0, 1fr); align-items: start; gap: 9px; margin: 0 !important; padding: 9px; border: 1px solid var(--ds-hairline); border-radius: 8px; font-weight: 500 !important; cursor: pointer; }
.question-picker-row input { width: auto !important; margin: 2px 0 0 !important; }
.question-picker-row strong, .question-picker-row small, .attempt-row strong, .attempt-row small, .attempt-question small, .review-row strong, .review-row small { display: block; }
.question-picker-row small, .attempt-row small, .attempt-question small, .review-row small { margin-top: 3px; color: var(--ds-text-tertiary); font-weight: 400; }
.attempt-start { align-items: end; }.attempt-start .primary { margin-bottom: 14px; }
.attempt-list { display: grid; gap: 8px; margin: 14px 0; }
.attempt-row { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center; gap: 10px; padding: 10px; border: 1px solid var(--ds-hairline); border-radius: 8px; }
.attempt-editor { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--ds-hairline); }
.attempt-question { padding: 12px 0; border-bottom: 1px solid var(--ds-hairline); }.attempt-question p { margin: 0 0 8px; }
.choice-list { display: grid; gap: 7px; }.choice-list label { display: flex !important; align-items: center; gap: 7px; margin: 0 !important; font-weight: 400 !important; cursor: pointer; }.choice-list input { width: auto !important; margin: 0 !important; }
.review-editor { margin-top: 16px; padding: 12px; border: 1px solid var(--ds-warning); border-radius: 8px; background: var(--ds-warning-wash); }.review-row { display: grid; grid-template-columns: minmax(0, 1fr) 120px; gap: 10px; align-items: center; padding: 9px 0; border-bottom: 1px solid var(--ds-hairline); }.review-row input { margin-top: 0; }
@media (max-width: 1100px) { .assessment-summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 720px) { .assessment-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .assessment-header { align-items: flex-start; } .assessment-form-grid { grid-template-columns: 1fr; } .result-entry .assessment-form-grid { grid-template-columns: 1fr; } .remediation-row { grid-template-columns: minmax(0, 1fr) auto; }.remediation-row button { grid-column: 2; } .attempt-row { grid-template-columns: minmax(0, 1fr) auto; }.attempt-row button { grid-column: 2; grid-row: 1 / span 2; }.review-row { grid-template-columns: 1fr; } .attempt-start .primary { margin-bottom: 0; } }
</style>
