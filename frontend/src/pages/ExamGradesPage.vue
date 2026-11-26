<template>
  <section :class="[pageShell, 'exam-grades-page']">
    <PageHeader title="段考成績" context="主任開段考場次，老師登錄自己學生的學校各科分數。">
      <template #actions>
        <Button variant="outline" :class="control.pageIcon" aria-label="重新整理" :aria-busy="loading" :disabled="loading" @click="loadRounds">
          <RefreshCw :size="20" :stroke-width="2" aria-hidden="true" :class="{ 'tw:animate-spin tw:motion-reduce:animate-none': loading }" />
        </Button>
        <FilterChip v-if="isDirector" class="tw:h-12" :active="showVoid" @click="toggleVoid">顯示已刪除</FilterChip>
        <Button v-if="isDirector" :class="control.page" @click="openCreate"><Plus :size="20" aria-hidden="true" />開新段考</Button>
      </template>
    </PageHeader>

    <Tabs v-model="tab" class="tw:gap-4">
      <TabsList>
        <TabsTrigger value="rounds">段考場次</TabsTrigger>
        <TabsTrigger value="periods">各校段考日期</TabsTrigger>
        <TabsTrigger value="curriculum">教材範本庫</TabsTrigger>
      </TabsList>
      <TabsContent value="rounds">
        <SectionCard title="段考場次" :count="loading || error ? null : rounds.length">
          <div v-if="loading" class="tw:px-5 tw:pb-4" aria-busy="true">
            <Skeleton v-for="i in 3" :key="i" class="tw:my-3 tw:h-18 tw:w-full tw:rounded-xl tw:motion-reduce:animate-none" />
          </div>
          <div v-else-if="error" class="tw:px-5 tw:pb-5">
            <Alert tone="danger" title="無法載入段考">
              <p>{{ error }}</p>
              <button type="button" :class="[rowAction.secondary, 'tw:mt-2']" @click="loadRounds">再試一次</button>
            </Alert>
          </div>
          <div v-else-if="!rounds.length" class="tw:flex tw:flex-col tw:items-center tw:gap-2 tw:px-5 tw:py-10 tw:text-center">
            <ClipboardList :size="32" :stroke-width="1.5" class="tw:text-tpl-secondary" aria-hidden="true" />
            <p class="tw:m-0 tw:font-semibold">{{ isDirector ? '還沒有段考場次' : '目前沒有開放中的段考' }}</p>
            <p class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary">{{ isDirector ? '建立場次並發布後，老師工作台會出現待登清單。' : '主任開放後會出現在這裡。' }}</p>
          </div>
          <ul v-else class="exam-round-list tw:m-0 tw:list-none tw:p-0">
            <li
              v-for="round in rounds"
              :key="round.id"
              class="exam-round-card tw:flex tw:min-h-18 tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-3 tw:border-t tw:border-border tw:px-4 tw:py-3 tw:md:px-5"
            >
              <div class="tw:min-w-0 tw:flex-1 tw:basis-64">
                <p class="tw:m-0 tw:flex tw:flex-wrap tw:items-center tw:gap-x-3 tw:gap-y-1 tw:font-semibold">
                  <span class="tw:min-w-0 tw:break-words">{{ round.title }}</span>
                  <StatusBadge :tone="round.status === 'published' ? 'success' : 'neutral'">{{ statusLabel(round.status) }}</StatusBadge>
                </p>
                <p class="tw:m-0 tw:mt-1 tw:text-tpl-caption tw:text-tpl-secondary">
                  <span :class="dataTable.fact">考試 {{ round.exam_date || '未定' }}</span>
                  <span :class="[dataTable.fact, 'tw:ml-3']">截止 {{ round.entry_deadline || '未設' }}</span>
                  <span class="tw:ml-3 tw:[word-break:keep-all]">{{ (round.subjects || []).map((s) => s.name).join('、') || '—' }}</span>
                </p>
              </div>
              <div class="exam-round-card__actions tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:max-md:w-full">
                <button v-if="isDirector && round.status === 'draft'" type="button" :class="rowAction.secondary" @click="publishRound(round)">發布</button>
                <button v-if="isDirector && round.status === 'published'" type="button" :class="rowAction.secondary" @click="askCloseRound(round)">關閉</button>
                <button v-if="round.status !== 'void'" type="button" :class="[rowAction.primary, 'tw:max-md:flex-1']" @click="openRound(round)">
                  {{ examRoundActionLabel(round, isDirector) }}
                </button>
                <button v-if="isDirector && round.status === 'void'" type="button" :class="rowAction.primary" @click="restoreRound(round)">復原</button>
                <ActionMenu
                  v-if="isDirector && round.status !== 'void'"
                  :groups="roundMenuGroups"
                  :label="`更多操作：${round.title}`"
                  :trigger-class="rowAction.menu"
                  @select="(id) => onRoundMenu(round, id)"
                />
              </div>
            </li>
          </ul>
        </SectionCard>
      </TabsContent>
      <TabsContent value="periods">
        <SchoolExamPeriodsPanel :branch-id="branchId" :is-director="isDirector" />
      </TabsContent>
      <TabsContent value="curriculum">
        <CurriculumTemplatesPanel :branch-id="branchId" :is-director="isDirector" />
      </TabsContent>
    </Tabs>

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

    <AtDialog :open="!!selected" :title="selected?.title || '登分'" title-id="exam-score-title" size="xl" panel-class="exam-score-dialog" @close="selected = null">
      <div v-if="studentsLoading && !students.length" aria-busy="true"><Skeleton v-for="i in 3" :key="i" class="tw:my-2 tw:h-14 tw:w-full tw:rounded-xl tw:motion-reduce:animate-none" /></div>
      <Alert v-else-if="studentsError" tone="danger" title="無法載入學生"><p>{{ studentsError }}</p></Alert>
      <div v-else-if="!students.length" class="tw:flex tw:flex-col tw:items-center tw:gap-2 tw:py-8 tw:text-center">
        <UserX :size="32" :stroke-width="1.5" class="tw:text-tpl-secondary" aria-hidden="true" />
        <p class="tw:m-0 tw:font-semibold">沒有需登分的學生</p>
        <p class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary">此場次沒有符合年級且由你授課的學生。</p>
      </div>
      <template v-else>
      <div class="tw:mb-3 tw:flex tw:flex-wrap tw:items-center tw:gap-3">
        <label for="exam-score-search-input" class="tw:sr-only">找學生</label>
        <Input
          id="exam-score-search-input"
          v-model="studentQuery"
          type="search"
          class="tw:h-11 tw:flex-1 tw:basis-48 tw:text-[length:var(--tpl-text-body)] tw:md:text-[length:var(--tpl-text-body)]"
          placeholder="找學生：輸入姓名或編號"
          autocomplete="off"
        />
        <span class="tw:text-tpl-caption tw:text-tpl-secondary tw:tabular-nums" aria-live="polite">顯示 {{ filteredStudents.length }} / {{ students.length }} 位</span>
      </div>
      <p v-if="!filteredStudents.length" class="exam-score-no-match tw:my-2 tw:text-tpl-caption tw:text-tpl-secondary">找不到符合「{{ studentQuery.trim() }}」的學生。</p>
      <!-- Names stay in view while scrolling across subjects; subject names stay in view while scrolling down. -->
      <div v-else class="tw:max-h-[min(60dvh,480px)] tw:overflow-auto tw:rounded-xl tw:border tw:border-solid tw:border-border">
        <table :class="['exam-score-table tw:w-full tw:border-collapse', dataTable.table]">
          <thead>
            <tr>
              <th :class="[dataTable.head, 'tw:sticky tw:top-0 tw:left-0 tw:z-2 tw:text-left']">學生</th>
              <th v-for="subject in selected.subjects" :key="subject.id" :class="[dataTable.head, 'tw:sticky tw:top-0 tw:z-1 tw:text-left']">{{ subject.name }}</th>
              <th :class="[dataTable.head, 'tw:sticky tw:top-0 tw:right-0 tw:z-2']"><span class="tw:sr-only">操作</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in filteredStudents" :key="row.student_id">
              <td :class="stickyName">
                <span>{{ row.student_name }}</span>
                <span :class="[dataTable.sub, 'tw:tabular-nums', row.is_complete ? toneText.success : '']">{{ row.is_complete ? '已登完 ' : '' }}{{ row.filled_subject_count }}/{{ row.required_subject_count }}</span>
              </td>
              <td v-for="subject in selected.subjects" :key="subject.id" :class="dataTable.cell">
                <ExamScoreCell
                  :score="cellOf(row, subject.id).score"
                  :is-absent="cellOf(row, subject.id).is_absent"
                  :cleared="cellOf(row, subject.id).clear"
                  :max="subject.max_score"
                  :editable="canEditScores"
                  :edited="cellOf(row, subject.id).edited"
                  :label="`${row.student_name} ${subject.name}`"
                  @change="(v) => onCellChange(row, subject.id, v)"
                  @clear="onCellClear(row, subject.id)"
                  @history="openHistory(row)"
                />
              </td>
              <td :class="stickySave">
                <button
                  v-if="canEditScores"
                  type="button"
                  :class="rowAction.secondary"
                  :disabled="savingStudentId === row.student_id"
                  :aria-busy="savingStudentId === row.student_id"
                  :aria-label="`儲存 ${row.student_name}`"
                  @click="saveStudent(row)"
                >儲存</button>
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

    <AtDialog :open="!!reasonFor" title="更正已截止的成績" title-id="exam-reason-title" @close="reasonFor = null">
      <label>更正原因（必填）<input v-model.trim="reasonText" maxlength="255" placeholder="例如：登錄時打錯" /></label>
      <template #actions>
        <AtButton shape="rect" variant="ghost" @click="reasonFor = null">取消</AtButton>
        <AtButton shape="rect" variant="primary" :disabled="!reasonText" @click="submitScores(reasonFor, reasonText)">確定更正</AtButton>
      </template>
    </AtDialog>

    <AtDialog :open="!!historyRows" :title="`修改紀錄：${historyName}`" title-id="exam-history-title" @close="historyRows = null">
      <p v-if="!historyRows?.length" class="muted">沒有修改紀錄。</p>
      <ul v-else class="exam-history">
        <li v-for="h in historyRows" :key="h.id">
          <strong>{{ historyActionLabel(h.action) }} {{ subjectName(h.exam_round_subject_id) }}</strong>：{{ describeScore(h.before) }} → {{ describeScore(h.after) }}
          <small class="muted">{{ (h.created_at || '').slice(0, 16).replace('T', ' ') }}<template v-if="h.reason"> · {{ h.reason }}</template></small>
        </li>
      </ul>
      <template #actions><AtButton shape="rect" variant="ghost" @click="historyRows = null">關閉</AtButton></template>
    </AtDialog>
  </section>
</template>

<script setup>
import { trackedFetch as fetch } from '../lib/loadingBar';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ClipboardList, Plus, RefreshCw, UserX } from 'lucide-vue-next';
import AtButton from '../components/design-system/AtButton.vue';
import { Alert } from '../components/ui/alert';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import { Skeleton } from '../components/ui/skeleton';
import { FilterChip, PageHeader, SectionCard, StatusBadge, control, dataTable, pageShell, rowAction, toneText } from '../components/ui/page';
import AtDialog from '../components/design-system/AtDialog.vue';
import ActionMenu from '../components/ActionMenu.vue';
import { GRADES } from '../lib/constants';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../components/ui/tabs';
import SchoolExamPeriodsPanel from '../components/SchoolExamPeriodsPanel.vue';
import CurriculumTemplatesPanel from '../components/CurriculumTemplatesPanel.vue';
import { supabase } from '../supabase';
import ExamScoreCell from '../components/exams/ExamScoreCell.vue';
import { describeScore, examErrorMessage, historyActionLabel, loadScoreHistory, saveScores } from '../lib/examScoresApi';
import { canEditExamRound, examRoundActionLabel, filterExamStudents } from '../lib/examScoreStudentFilter';

const props = defineProps({
  branchId: { type: [Number, String], required: true },
  userRole: { type: String, default: 'teacher' },
  focusRoundId: { type: [Number, String], default: null },
});

const isDirector = computed(() => ['director', 'super_admin', 'admin'].includes(String(props.userRole || '')));
const canEditScores = computed(() => canEditExamRound(selected.value, isDirector.value));

const tab = ref('rounds');
const loading = ref(false);
const error = ref('');
const rounds = ref([]);
const showCreate = ref(false);
const saving = ref(false);
const formError = ref('');
const createForm = reactive({ title: '', exam_date: '', entry_deadline: '', grade_ids: [], subjects: [] });
const editingId = ref(null);
const showVoid = ref(false);
function toggleVoid() {
  showVoid.value = !showVoid.value;
  loadRounds();
}
// The name column stays in view while scrolling across subjects: an opaque fill instead of dataTable.name's transparent one.
// 儲存 stays in view at the right edge for the same reason.
const stickySave = 'tw:sticky tw:right-0 tw:z-1 tw:h-14 tw:border-b tw:border-border tw:bg-[var(--ds-canvas)]! tw:px-3 tw:py-2 tw:md:px-4';
const stickyName = 'tw:sticky tw:left-0 tw:z-1 tw:h-14 tw:border-b tw:border-border tw:bg-[var(--ds-canvas)]! tw:px-3 tw:py-2 tw:md:px-4 tw:font-semibold tw:whitespace-nowrap tw:text-foreground';
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
  if (!res.ok) {
    const raw = Object.values(body.errors || {})[0]?.[0] || body.message || `HTTP ${res.status}`;
    throw Object.assign(new Error(raw), { status: res.status, raw });
  }
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
  // A different round starts empty (skeleton). Reloading the same round after a save keeps the table on screen, so the search
  // box, the scroll position and focus stay where they were.
  if (String(selected.value?.id) !== String(round?.id)) {
    studentQuery.value = '';
    students.value = [];
  }
  selected.value = round;
  studentsLoading.value = true;
  studentsError.value = '';
  scoreError.value = '';
  Object.keys(draftScores).forEach((k) => delete draftScores[k]);
  try {
    const res = await api(`/exam-rounds/${round.id}/students`);
    students.value = res.data || [];
  } catch (e) {
    studentsError.value = e.message || '載入失敗';
    students.value = [];
  } finally {
    studentsLoading.value = false;
  }
}

function cellOf(row, subjectId) {
  const key = `${row.student_id}:${subjectId}`;
  if (draftScores[key]) return { edited: false, ...serverCell(row, subjectId), ...draftScores[key] };
  return { clear: false, ...serverCell(row, subjectId) };
}

function serverCell(row, subjectId) {
  const f = (row.scores || []).find((s) => Number(s.exam_round_subject_id) === Number(subjectId));
  return { score: f?.score ?? null, is_absent: !!f?.is_absent, edited: !!f?.edited, clear: false };
}

function onCellChange(row, subjectId, v) {
  draftScores[`${row.student_id}:${subjectId}`] = { ...v, clear: false };
}

function onCellClear(row, subjectId) {
  draftScores[`${row.student_id}:${subjectId}`] = { score: null, is_absent: false, clear: true };
}

const reasonFor = ref(null);
const reasonText = ref('');
const historyRows = ref(null);
const historyName = ref('');

function subjectName(id) {
  return (selected.value?.subjects || []).find((s) => Number(s.id) === Number(id))?.name || '';
}

async function openHistory(row) {
  scoreError.value = '';
  try {
    historyRows.value = await loadScoreHistory(api, selected.value.id, row.student_id);
    historyName.value = row.student_name;
  } catch (e) {
    scoreError.value = examErrorMessage(e);
  }
}

function saveStudent(row) {
  scoreError.value = '';
  if (!(selected.value.subjects || []).some((s) => draftScores[`${row.student_id}:${s.id}`])) {
    scoreError.value = '沒有改動，請先修改分數';
    return;
  }
  // A director correcting a closed round must give a reason (API 422 otherwise).
  if (selected.value.status === 'closed') {
    reasonText.value = '';
    reasonFor.value = row;
    return;
  }
  return submitScores(row, '');
}

async function submitScores(row, reason) {
  const scores = [];
  for (const subject of selected.value.subjects || []) {
    const d = draftScores[`${row.student_id}:${subject.id}`];
    // A typed-then-erased (or ticked-then-unticked 缺考) cell is no entry: skip it, as before E2; only 清除 deletes.
    if (!d || (!d.clear && !d.is_absent && (d.score === null || d.score === undefined || d.score === ''))) continue;
    scores.push({
      student_id: row.student_id,
      exam_round_subject_id: subject.id,
      ...(d.clear ? { clear: true } : { score: d.is_absent ? null : d.score, is_absent: d.is_absent }),
    });
  }
  savingStudentId.value = row.student_id;
  try {
    await saveScores(api, selected.value.id, scores, reason);
    reasonFor.value = null;
    await openRound(selected.value);
  } catch (e) {
    reasonFor.value = null;
    scoreError.value = examErrorMessage(e);
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
/* Dialog forms only (the six AtDialog overlays move to components/ui/dialog in the next slice). */
.muted { color: var(--text-light); margin: 4px 0 0; font-size: var(--ds-font-size-sm); }
.exam-grade-picker, .exam-subject-editor { border: 1px solid var(--border); border-radius: var(--ds-radius-lg); margin: 0 0 10px; padding: 8px 12px; }
.exam-grade-picker { display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: 2px 8px; }
.exam-grade-picker legend { grid-column: 1 / -1; }
.exam-grade-option { display: flex; align-items: center; gap: 6px; margin: 0; }
.exam-grade-option input { display: inline; width: auto; margin: 0; }
.exam-subject-row { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
.exam-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.exam-error { color: var(--danger); margin-top: 8px; }
.exam-history { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; font-size: var(--ds-font-size-lg); }
.exam-history small { display: block; }
label { display: block; margin-bottom: 10px; }
label input, label select, label textarea { display: block; width: 100%; margin-top: 4px; }
@media (max-width: 720px) {
  .exam-form-grid { grid-template-columns: 1fr; }
}
</style>
