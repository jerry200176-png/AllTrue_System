<template>
  <section class="exam-grades-page">
    <AtPageHeader
      title="段考成績"
      description="主任開段考場次，老師登錄自己學生的學校各科分數。"
      icon="quiz"
    >
      <template #actions>
        <AtButton v-if="isDirector" variant="primary" shape="rect" icon="add" @click="showCreate = true">開新段考</AtButton>
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
          <AtButton shape="rect" size="sm" variant="primary" @click="openRound(round)">
            {{ round.status === 'published' ? '登分' : '查看' }}
          </AtButton>
          <AtButton v-if="isDirector && round.status === 'draft'" shape="rect" size="sm" variant="ghost" @click="publishRound(round)">發布</AtButton>
          <AtButton v-if="isDirector && round.status === 'published'" shape="rect" size="sm" variant="ghost" @click="closeRound(round)">關閉</AtButton>
        </div>
      </article>
    </div>

    <AtDialog :open="showCreate" title="開新段考" title-id="exam-create-title" @close="showCreate = false">
      <label>標題<input v-model.trim="createForm.title" maxlength="120" placeholder="例如：113-1 第一次段考" /></label>
      <div class="exam-form-grid">
        <label>考試日<input v-model="createForm.exam_date" type="date" /></label>
        <label>登分截止<input v-model="createForm.entry_deadline" type="date" /></label>
      </div>
      <label>適用年級（ClassID，逗號分隔）<input v-model.trim="createForm.grade_ids_text" placeholder="例如：1,2,3" /></label>
      <label>科目（逗號分隔，空白則用國英數社自）<input v-model.trim="createForm.subjects_text" placeholder="國文,英文,數學" /></label>
      <p v-if="formError" class="exam-error">{{ formError }}</p>
      <template #actions>
        <AtButton shape="rect" variant="ghost" @click="showCreate = false">取消</AtButton>
        <AtButton shape="rect" variant="primary" :loading="saving" @click="createRound">建立</AtButton>
      </template>
    </AtDialog>

    <AtDialog :open="!!selected" :title="selected?.title || '登分'" title-id="exam-score-title" panel-class="exam-score-dialog" @close="selected = null">
      <AtSkeleton v-if="studentsLoading" rows="3" />
      <AtInlineAlert v-else-if="studentsError" tone="danger" title="無法載入學生"><p>{{ studentsError }}</p></AtInlineAlert>
      <AtEmpty v-else-if="!students.length" icon="person_off" title="沒有需登分的學生" description="此場次沒有符合年級且由你授課的學生。" />
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
            <tr v-for="row in students" :key="row.student_id">
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
import { supabase } from '../supabase';

const props = defineProps({
  branchId: { type: [Number, String], required: true },
  userRole: { type: String, default: 'teacher' },
  focusRoundId: { type: [Number, String], default: null },
});

const isDirector = computed(() => ['director', 'super_admin', 'admin'].includes(String(props.userRole || '')));
const canEditScores = computed(() => selected.value && (selected.value.status === 'published' || isDirector.value));

const loading = ref(false);
const error = ref('');
const rounds = ref([]);
const showCreate = ref(false);
const saving = ref(false);
const formError = ref('');
const createForm = reactive({ title: '', exam_date: '', entry_deadline: '', grade_ids_text: '', subjects_text: '' });

const selected = ref(null);
const students = ref([]);
const studentsLoading = ref(false);
const studentsError = ref('');
const scoreError = ref('');
const savingStudentId = ref(null);
const draftScores = reactive({});

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
  if (!res.ok) throw new Error(body.message || `HTTP ${res.status}`);
  return body;
}

function statusLabel(status) {
  return ({ draft: '草稿', published: '開放中', closed: '已關閉' }[status] || status);
}

async function loadRounds() {
  loading.value = true;
  error.value = '';
  try {
    const res = await api(`/exam-rounds?campus_id=${encodeURIComponent(props.branchId)}&per_page=100`);
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

async function createRound() {
  formError.value = '';
  const gradeIds = String(createForm.grade_ids_text || '')
    .split(/[,，\s]+/)
    .map((x) => Number(x))
    .filter((n) => Number.isFinite(n) && n > 0);
  if (!createForm.title || !gradeIds.length) {
    formError.value = '請填標題與至少一個年級';
    return;
  }
  const subjectsText = String(createForm.subjects_text || '').trim();
  const subjects = subjectsText
    ? subjectsText.split(/[,，]/).map((name, i) => ({ name: name.trim(), sort_order: i })).filter((s) => s.name)
    : undefined;
  saving.value = true;
  try {
    await api('/exam-rounds', {
      method: 'POST',
      body: JSON.stringify({
        campus_id: Number(props.branchId),
        title: createForm.title,
        exam_date: createForm.exam_date || null,
        entry_deadline: createForm.entry_deadline || null,
        grade_ids: gradeIds,
        subjects,
      }),
    });
    showCreate.value = false;
    createForm.title = '';
    createForm.exam_date = '';
    createForm.entry_deadline = '';
    createForm.grade_ids_text = '';
    createForm.subjects_text = '';
    await loadRounds();
  } catch (e) {
    formError.value = e.message || '建立失敗';
  } finally {
    saving.value = false;
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

async function closeRound(round) {
  if (!window.confirm('關閉後老師不能再改分，確定嗎？')) return;
  try {
    await api(`/exam-rounds/${round.id}/close`, { method: 'POST' });
    await loadRounds();
  } catch (e) {
    error.value = e.message || '關閉失敗';
  }
}

async function openRound(round) {
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
  border: 1px solid var(--border-subtle, #e5e7eb);
  border-radius: 12px;
  padding: 14px 16px;
  background: var(--surface, #fff);
}
.exam-round-card__head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
.exam-round-card__actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.muted { color: var(--text-muted, #6b7280); margin: 4px 0 0; font-size: 0.9rem; }
.status-pill { font-size: 0.8rem; padding: 2px 8px; border-radius: 999px; background: #f3f4f6; }
.status-published { background: #dcfce7; }
.status-closed { background: #e5e7eb; }
.exam-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.exam-error { color: #b91c1c; margin-top: 8px; }
.exam-score-table-wrap { overflow-x: auto; }
.exam-score-table { width: 100%; border-collapse: collapse; }
.exam-score-table th, .exam-score-table td { padding: 8px; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
.exam-score-input { width: 4.5rem; padding: 6px 8px; }
.exam-complete { color: #15803d; display: block; }
label { display: block; margin-bottom: 10px; }
label input, label select, label textarea { display: block; width: 100%; margin-top: 4px; }
@media (max-width: 720px) {
  .exam-form-grid { grid-template-columns: 1fr; }
}
</style>
