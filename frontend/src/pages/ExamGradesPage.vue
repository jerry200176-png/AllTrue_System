<template>
  <section class="exam-grades-page">
    <AtPageHeader
      title="段考成績"
      description="主任開段考場次，老師登錄自己學生的學校各科分數。"
      icon="quiz"
    >
      <template #actions>
        <AtButton v-if="isDirector" variant="primary" shape="rect" icon="add" @click="openCreate">開新段考</AtButton>
        <label v-if="isDirector" class="exam-void-toggle"><input v-model="showVoid" type="checkbox" @change="loadRounds" /> 顯示已刪除</label>
        <AtButton shape="rect" variant="ghost" icon="refresh" :loading="loading" @click="loadRounds">重新整理</AtButton>
      </template>
    </AtPageHeader>

    <AtSkeleton v-if="loading" rows="4" />
    <AtInlineAlert v-else-if="error" tone="danger" title="無法載入段考">
      <p>{{ error }}</p>
      <template #action><AtButton shape="rect" size="sm" variant="ghost" @click="loadRounds">重試</AtButton></template>
    </AtInlineAlert>
    <AtEmpty
      v-else-if="!rounds.length"
      icon="quiz"
      :title="isDirector ? '還沒有段考場次' : '目前沒有開放中的段考'"
      :description="isDirector ? '建立場次並發布後，老師工作台會出現待登清單。' : '主任開放後會出現在這裡。'"
    />

    <div v-else class="exam-round-list">
      <article v-for="round in rounds" :key="round.id" class="exam-round-card">
        <div class="exam-round-card__head">
          <div>
            <strong>{{ round.title }}</strong>
            <p class="muted">{{ round.exam_date || '未定考試日' }} · 截止 {{ round.entry_deadline || '未設' }}</p>
          </div>
          <span :class="['status-pill', `status-${round.status}`]">{{ statusLabel(round.status) }}</span>
        </div>
        <p class="muted">科目：{{ (round.subjects || []).map((s) => s.name).join('、') || '—' }}</p>
        <div class="exam-round-card__actions">
          <AtButton v-if="round.status !== 'void'" shape="rect" size="sm" variant="primary" @click="openRound(round)">
            {{ examRoundActionLabel(round, isDirector) }}
          </AtButton>
          <AtButton v-if="isDirector && round.status === 'void'" shape="rect" size="sm" variant="primary" @click="restoreRound(round)">復原</AtButton>
          <AtButton v-if="isDirector && round.status === 'draft'" shape="rect" size="sm" variant="ghost" @click="publishRound(round)">發布</AtButton>
          <AtButton v-if="isDirector && round.status === 'published'" shape="rect" size="sm" variant="ghost" @click="askCloseRound(round)">關閉</AtButton>
          <ActionMenu
            v-if="isDirector && round.status !== 'void'"
            :groups="roundMenuGroups"
            :label="`更多操作：${round.title}`"
            @select="(id) => onRoundMenu(round, id)"
          />
        </div>
      </article>
    </div>

    <AtDialog :open="!!pendingClose" title="關閉段考" title-id="exam-close-title" @close="pendingClose = null">
      <p>關閉後老師不能再改分，確定關閉「{{ pendingClose?.title }}」嗎？</p>
      <p v-if="closeError" class="exam-error">{{ closeError }}</p>
      <template #actions>
        <AtButton shape="rect" variant="ghost" :disabled="closing" @click="pendingClose = null">取消</AtButton>
        <AtButton shape="rect" variant="primary" :loading="closing" @click="confirmCloseRound">確定關閉</AtButton>
      </template>
    </AtDialog>

    <AtDialog :open="!!pendingDelete" title="刪除段考" title-id="exam-delete-title" @close="pendingDelete = null">
      <p>確定刪除「{{ pendingDelete?.title }}」嗎？</p>
      <label v-if="deleteNeedsReason">此段考已有成績，刪除後會隱藏，主任可復原。請填寫原因
        <input v-model.trim="deleteReason" maxlength="255" />
      </label>
      <p v-if="deleteError" class="exam-error">{{ deleteError }}</p>
      <template #actions>
        <AtButton shape="rect" variant="ghost" :disabled="deleting" @click="pendingDelete = null">取消</AtButton>
        <AtButton shape="rect" variant="primary" :loading="deleting" @click="confirmDeleteRound">確定刪除</AtButton>
      </template>
    </AtDialog>

    <AtDialog :open="showCreate" :title="editingId ? '編輯段考' : '開新段考'" title-id="exam-create-title" @close="showCreate = false">
      <label>標題<input v-model.trim="createForm.title" maxlength="120" placeholder="例如：113-1 第一次段考" /></label>
      <div class="exam-form-grid">
        <label>考試日<input v-model="createForm.exam_date" type="date" /></label>
        <label>登分截止<input v-model="createForm.entry_deadline" type="date" /></label>
      </div>
      <fieldset class="exam-grade-picker">
        <legend>適用年級</legend>
        <label v-for="(g, i) in GRADES" :key="g.value" class="exam-grade-option">
          <input v-model="createForm.grade_ids" type="checkbox" :value="i + 1" /> {{ g.label }}
        </label>
      </fieldset>
      <fieldset class="exam-subject-editor">
        <legend>科目{{ editingId ? '' : '（不加則用國英數社自）' }}</legend>
        <div v-for="(sub, i) in createForm.subjects" :key="i" class="exam-subject-row">
          <input v-model.trim="sub.name" maxlength="40" :aria-label="`科目 ${i + 1}`" placeholder="例如：國文" />
          <AtButton shape="rect" size="sm" variant="ghost" @click="createForm.subjects.splice(i, 1)">移除</AtButton>
        </div>
        <AtButton shape="rect" size="sm" variant="ghost" icon="add" @click="createForm.subjects.push({ id: null, name: '' })">加科目</AtButton>
      </fieldset>
      <p v-if="formError" class="exam-error">{{ formError }}</p>
      <template #actions>
        <AtButton shape="rect" variant="ghost" @click="showCreate = false">取消</AtButton>
        <AtButton shape="rect" variant="primary" :loading="saving" @click="createRound">{{ editingId ? '儲存' : '建立' }}</AtButton>
      </template>
    </AtDialog>

    <AtDialog :open="!!selected" :title="selected?.title || '登分'" title-id="exam-score-title" panel-class="exam-score-dialog" @close="selected = null">
      <AtSkeleton v-if="studentsLoading" rows="3" />
      <AtInlineAlert v-else-if="studentsError" tone="danger" title="無法載入學生"><p>{{ studentsError }}</p></AtInlineAlert>
      <AtEmpty v-else-if="!students.length" icon="person_off" title="沒有需登分的學生" description="此場次沒有符合年級且由你授課的學生。" />
      <template v-else>
      <div class="exam-score-search">
        <label for="exam-score-search-input">找學生</label>
        <input
          id="exam-score-search-input"
          v-model="studentQuery"
          type="search"
          placeholder="輸入學生姓名或編號"
          autocomplete="off"
        />
        <small class="muted" aria-live="polite">顯示 {{ filteredStudents.length }} / {{ students.length }} 位</small>
      </div>
      <p v-if="!filteredStudents.length" class="muted exam-score-no-match">找不到符合「{{ studentQuery.trim() }}」的學生。</p>
      <div v-else class="exam-score-table-wrap">
        <table class="exam-score-table">
          <thead>
            <tr>
              <th>學生</th>
              <th v-for="subject in selected.subjects" :key="subject.id">{{ subject.name }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in filteredStudents" :key="row.student_id">
              <td>
                <strong>{{ row.student_name }}</strong>
                <small :class="{ 'exam-complete': row.is_complete }">{{ row.filled_subject_count }}/{{ row.required_subject_count }}</small>
              </td>
              <td v-for="subject in selected.subjects" :key="subject.id">
                <input
                  class="exam-score-input"
                  type="number"
                  min="0"
                  :max="subject.max_score"
                  step="0.5"
                  :disabled="!canEditScores"
                  :value="scoreValue(row, subject.id)"
                  @change="onScoreInput(row, subject.id, $event.target.value)"
                />
              </td>
              <td>
                <AtButton
                  v-if="canEditScores"
                  shape="rect"
                  size="sm"
                  variant="ghost"
                  :loading="savingStudentId === row.student_id"
                  @click="saveStudent(row)"
                >儲存</AtButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      </template>
      <p v-if="scoreError" class="exam-error">{{ scoreError }}</p>
      <template #actions>
        <AtButton shape="rect" variant="ghost" @click="selected = null">關閉</AtButton>
      </template>
    </AtDialog>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtSkeleton from '../components/design-system/AtSkeleton.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtDialog from '../components/design-system/AtDialog.vue';
import ActionMenu from '../components/ActionMenu.vue';
import { GRADES } from '../lib/constants';
import { supabase } from '../supabase';
import { canEditExamRound, examRoundActionLabel, filterExamStudents } from '../lib/examScoreStudentFilter';

const props = defineProps({
  branchId: { type: [Number, String], required: true },
  userRole: { type: String, default: 'teacher' },
  focusRoundId: { type: [Number, String], default: null },
});

const isDirector = computed(() => ['director', 'super_admin', 'admin'].includes(String(props.userRole || '')));
const canEditScores = computed(() => canEditExamRound(selected.value, isDirector.value));

const loading = ref(false);
const error = ref('');
const rounds = ref([]);
const showCreate = ref(false);
const saving = ref(false);
const formError = ref('');
const createForm = reactive({ title: '', exam_date: '', entry_deadline: '', grade_ids: [], subjects: [] });
const editingId = ref(null);
const showVoid = ref(false);
const roundMenuGroups = [{ id: 'round', label: '', items: [{ id: 'edit', label: '編輯' }, { id: 'delete', label: '刪除', danger: true }] }];
const pendingDelete = ref(null);
const deleteReason = ref('');
const deleteNeedsReason = ref(false);
const deleteError = ref('');
const deleting = ref(false);

const selected = ref(null);
const students = ref([]);
const studentsLoading = ref(false);
const studentsError = ref('');
const studentQuery = ref('');
const filteredStudents = computed(() => filterExamStudents(students.value, studentQuery.value));
const scoreError = ref('');
const savingStudentId = ref(null);
const draftScores = reactive({});
const pendingClose = ref(null);
const closing = ref(false);
const closeError = ref('');

async function api(path, options = {}) {
  const { data: { session } } = await supabase.auth.getSession();
  const token = session?.access_token;
  const res = await fetch(`/api/v1${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers || {}),
    },
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(Object.values(body.errors || {})[0]?.[0] || body.message || `HTTP ${res.status}`);
  return body;
}

function statusLabel(status) {
  return ({ draft: '草稿', published: '開放中', closed: '已關閉', void: '已刪除' }[status] || status);
}

async function loadRounds() {
  loading.value = true;
  error.value = '';
  try {
    const res = await api(`/exam-rounds?campus_id=${encodeURIComponent(props.branchId)}&per_page=100${isDirector.value && showVoid.value ? '&include_void=1' : ''}`);
    rounds.value = res.data || [];
    if (props.focusRoundId) {
      const focus = rounds.value.find((r) => String(r.id) === String(props.focusRoundId));
      if (focus) await openRound(focus);
    }
  } catch (e) {
    error.value = e.message || '載入失敗';
    rounds.value = [];
  } finally {
    loading.value = false;
  }
}

function resetForm() {
  Object.assign(createForm, { title: '', exam_date: '', entry_deadline: '', grade_ids: [], subjects: [] });
  editingId.value = null;
  formError.value = '';
}

function openCreate() {
  resetForm();
  showCreate.value = true;
}

function openEdit(round) {
  resetForm();
  editingId.value = round.id;
  Object.assign(createForm, {
    title: round.title,
    exam_date: round.exam_date || '',
    entry_deadline: round.entry_deadline || '',
    grade_ids: [...(round.grade_ids || [])],
    subjects: (round.subjects || []).map((x) => ({ id: x.id, name: x.name, max_score: x.max_score })),
  });
  showCreate.value = true;
}

async function createRound() {
  formError.value = '';
  const gradeIds = [...createForm.grade_ids].sort((x, y) => x - y);
  if (!createForm.title || !gradeIds.length) {
    formError.value = '請填標題與至少選一個年級';
    return;
  }
  const subjects = createForm.subjects
    .filter((x) => x.name)
    .map((x, i) => ({ ...(x.id ? { id: x.id, max_score: x.max_score } : {}), name: x.name, sort_order: i }));
  if (editingId.value && !subjects.length) {
    formError.value = '至少要有一個科目';
    return;
  }
  saving.value = true;
  try {
    await api(editingId.value ? `/exam-rounds/${editingId.value}` : '/exam-rounds', {
      method: editingId.value ? 'PATCH' : 'POST',
      body: JSON.stringify({
        ...(editingId.value ? {} : { campus_id: Number(props.branchId) }),
        title: createForm.title,
        exam_date: createForm.exam_date || null,
        entry_deadline: createForm.entry_deadline || null,
        grade_ids: gradeIds,
        subjects: subjects.length ? subjects : undefined,
      }),
    });
    showCreate.value = false;
    resetForm();
    await loadRounds();
  } catch (e) {
    formError.value = e.message || '儲存失敗';
  } finally {
    saving.value = false;
  }
}

function onRoundMenu(round, id) {
  if (id === 'edit') return openEdit(round);
  deleteError.value = '';
  deleteReason.value = '';
  deleteNeedsReason.value = false;
  pendingDelete.value = round;
}

async function confirmDeleteRound() {
  const round = pendingDelete.value;
  if (!round) return;
  deleting.value = true;
  deleteError.value = '';
  try {
    // Try without a reason first: a round with no scores is deleted outright; otherwise the API asks for one (422).
    await api(`/exam-rounds/${round.id}`, { method: 'DELETE', body: JSON.stringify({ reason: deleteReason.value || undefined }) });
    pendingDelete.value = null;
    await loadRounds();
  } catch (e) {
    if (/需填寫原因/.test(e.message || '')) deleteNeedsReason.value = true;
    deleteError.value = e.message || '刪除失敗';
  } finally {
    deleting.value = false;
  }
}

async function restoreRound(round) {
  try {
    await api(`/exam-rounds/${round.id}/restore`, { method: 'POST' });
    await loadRounds();
  } catch (e) {
    error.value = e.message || '復原失敗';
  }
}

async function publishRound(round) {
  try {
    await api(`/exam-rounds/${round.id}/publish`, { method: 'POST' });
    await loadRounds();
  } catch (e) {
    error.value = e.message || '發布失敗';
  }
}

function askCloseRound(round) {
  closeError.value = '';
  pendingClose.value = round;
}

async function confirmCloseRound() {
  const round = pendingClose.value;
  if (!round) return;
  closing.value = true;
  closeError.value = '';
  try {
    await api(`/exam-rounds/${round.id}/close`, { method: 'POST' });
    pendingClose.value = null;
    await loadRounds();
  } catch (e) {
    closeError.value = e.message || '關閉失敗';
  } finally {
    closing.value = false;
  }
}

async function openRound(round) {
  if (String(selected.value?.id) !== String(round?.id)) studentQuery.value = '';
  selected.value = round;
  studentsLoading.value = true;
  studentsError.value = '';
  scoreError.value = '';
  Object.keys(draftScores).forEach((k) => delete draftScores[k]);
  try {
    const res = await api(`/exam-rounds/${round.id}/students`);
    students.value = res.data || [];
    for (const row of students.value) {
      for (const score of row.scores || []) {
        draftScores[`${row.student_id}:${score.exam_round_subject_id}`] = score.score;
      }
    }
  } catch (e) {
    studentsError.value = e.message || '載入失敗';
    students.value = [];
  } finally {
    studentsLoading.value = false;
  }
}

function scoreValue(row, subjectId) {
  const key = `${row.student_id}:${subjectId}`;
  if (Object.prototype.hasOwnProperty.call(draftScores, key)) return draftScores[key];
  const found = (row.scores || []).find((s) => Number(s.exam_round_subject_id) === Number(subjectId));
  return found?.score ?? '';
}

function onScoreInput(row, subjectId, raw) {
  draftScores[`${row.student_id}:${subjectId}`] = raw === '' ? null : Number(raw);
}

async function saveStudent(row) {
  scoreError.value = '';
  const scores = (selected.value.subjects || []).map((subject) => {
    const key = `${row.student_id}:${subject.id}`;
    const value = Object.prototype.hasOwnProperty.call(draftScores, key)
      ? draftScores[key]
      : (row.scores || []).find((s) => Number(s.exam_round_subject_id) === Number(subject.id))?.score;
    if (value === '' || value == null || Number.isNaN(Number(value))) return null;
    return {
      student_id: row.student_id,
      exam_round_subject_id: subject.id,
      score: Number(value),
      is_absent: false,
    };
  }).filter(Boolean);

  if (!scores.length) {
    scoreError.value = '請至少填一科分數';
    return;
  }
  savingStudentId.value = row.student_id;
  try {
    await api(`/exam-rounds/${selected.value.id}/scores`, {
      method: 'POST',
      body: JSON.stringify({ scores }),
    });
    await openRound(selected.value);
  } catch (e) {
    scoreError.value = e.message || '儲存失敗';
  } finally {
    savingStudentId.value = null;
  }
}

watch(selected, (round) => { if (!round) studentQuery.value = ''; });
watch(() => props.branchId, loadRounds);
watch(() => props.focusRoundId, async (id) => {
  if (!id || loading.value) return;
  const focus = rounds.value.find((r) => String(r.id) === String(id));
  if (focus) await openRound(focus);
});
onMounted(loadRounds);
</script>

<style scoped>
.exam-round-list { display: grid; gap: 12px; }
.exam-round-card {
  border: 1px solid var(--border);
  border-radius: var(--ds-radius-lg);
  padding: 14px 16px;
  background: var(--card-bg);
}
.exam-round-card__head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
.exam-round-card__actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.muted { color: var(--text-light); margin: 4px 0 0; font-size: var(--ds-font-size-sm); }
.status-pill { font-size: var(--ds-font-size-xs); padding: 2px 8px; border-radius: var(--ds-radius-pill); background: var(--ds-canvas-soft); }
.status-published { background: var(--success-bg); }
.status-void { background: var(--danger-bg, var(--ds-surface-2)); }
.status-closed { background: var(--ds-surface-2); }
.exam-void-toggle { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-size: var(--ds-font-size-sm); }
.exam-grade-picker, .exam-subject-editor { border: 1px solid var(--border); border-radius: var(--ds-radius-lg); margin: 0 0 10px; padding: 8px 12px; }
.exam-grade-picker { display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: 2px 8px; }
.exam-grade-picker legend { grid-column: 1 / -1; }
.exam-grade-option { display: flex; align-items: center; gap: 6px; margin: 0; }
.exam-grade-option input { display: inline; width: auto; margin: 0; }
.exam-subject-row { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
.exam-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.exam-error { color: var(--danger); margin-top: 8px; }
.exam-score-search { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; }
.exam-score-search label { margin: 0; }
.exam-score-search input { flex: 1 1 12rem; min-height: 40px; padding: 6px 10px; }
.exam-score-no-match { margin: 8px 0; }
.exam-score-table-wrap { overflow-x: auto; }
.exam-score-table { width: 100%; border-collapse: collapse; }
.exam-score-table th, .exam-score-table td { padding: 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; }
.exam-score-input { width: 4.5rem; padding: 6px 8px; }
.exam-complete { color: var(--success); display: block; }
label { display: block; margin-bottom: 10px; }
label input, label select, label textarea { display: block; width: 100%; margin-top: 4px; }
@media (max-width: 720px) {
  .exam-form-grid { grid-template-columns: 1fr; }
}
</style>
