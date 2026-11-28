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

    <ConfirmDialog
      :open="!!pendingClose"
      title="關閉這個段考？"
      :description="closeDescription"
      confirm-label="關閉段考"
      :loading="closing"
      @update:open="onCloseOpen"
      @confirm="confirmCloseRound"
    />

    <ConfirmDialog
      :open="!!pendingDelete && !deleteNeedsReason"
      title="刪除這個段考？"
      :description="deleteDescription"
      confirm-label="刪除"
      destructive
      :loading="deleting"
      @update:open="onDeleteOpen"
      @confirm="confirmDeleteRound"
    />

    <FormDialog
      :open="!!pendingDelete && deleteNeedsReason"
      size="sm"
      title="填寫刪除原因"
      :description="`「${pendingDelete?.title || ''}」已有成績，刪除後會隱藏，主任可復原。`"
      submit-label="刪除"
      :loading="deleting"
      :dirty="!!deleteReason"
      @update:open="onDeleteOpen"
      @submit="confirmDeleteRound"
    >
      <div class="tw:grid tw:gap-2">
        <Label for="exam-delete-reason" class="tw:text-tpl-body">刪除原因（必填）</Label>
        <Input id="exam-delete-reason" v-model="deleteReason" maxlength="255" :class="field" />
        <p v-if="deleteError" class="exam-error tw:m-0 tw:text-tpl-caption tw:text-destructive" role="alert">{{ deleteError }}</p>
      </div>
    </FormDialog>

    <FormDialog
      :open="showCreate"
      :title="editingId ? '編輯段考' : '開新段考'"
      description="設定場次名稱、日期、適用年級與科目。"
      :submit-label="editingId ? '儲存' : '建立'"
      :loading="saving"
      :dirty="formDirty"
      @update:open="(v) => { if (!v) showCreate = false; }"
      @submit="createRound"
    >
      <div class="tw:grid tw:gap-4">
        <div class="tw:grid tw:gap-2">
          <Label for="exam-form-title" class="tw:text-tpl-body">標題</Label>
          <Input id="exam-form-title" v-model="createForm.title" maxlength="120" placeholder="例如：113-1 第一次段考" :class="field" />
        </div>
        <div class="tw:grid tw:gap-4 tw:sm:grid-cols-2">
          <div class="tw:grid tw:gap-2">
            <Label for="exam-form-date" class="tw:text-tpl-body">考試日</Label>
            <Input id="exam-form-date" v-model="createForm.exam_date" type="date" :class="field" />
          </div>
          <div class="tw:grid tw:gap-2">
            <Label for="exam-form-deadline" class="tw:text-tpl-body">登分截止</Label>
            <Input id="exam-form-deadline" v-model="createForm.entry_deadline" type="date" :class="field" />
          </div>
        </div>
        <fieldset class="tw:m-0 tw:min-w-0 tw:border-0 tw:p-0">
          <legend class="tw:mb-2 tw:p-0 tw:text-tpl-body tw:font-medium">適用年級</legend>
          <div class="tw:grid tw:grid-cols-1 tw:gap-x-4 tw:min-[480px]:grid-cols-2 tw:sm:grid-cols-3">
            <label v-for="(g, i) in GRADES" :key="g.value" class="exam-grade-option tw:flex tw:min-h-11 tw:items-center tw:gap-2 tw:text-tpl-body tw:whitespace-nowrap">
              <input v-model="createForm.grade_ids" type="checkbox" :value="i + 1" class="tw:size-5 tw:shrink-0 tw:accent-[var(--ds-ink)]" />{{ g.label }}
            </label>
          </div>
        </fieldset>
        <fieldset class="tw:m-0 tw:min-w-0 tw:border-0 tw:p-0">
          <legend class="tw:mb-2 tw:p-0 tw:text-tpl-body tw:font-medium">科目{{ editingId ? '' : '（不加則用國英數社自）' }}</legend>
          <div class="tw:grid tw:gap-2">
            <div v-for="(sub, i) in createForm.subjects" :key="i" class="tw:flex tw:items-center tw:gap-2">
              <Input v-model="sub.name" maxlength="40" :aria-label="`科目 ${i + 1}`" placeholder="例如：國文" :class="field" />
              <Button type="button" variant="ghost" :class="control.row" @click="createForm.subjects.splice(i, 1)">移除</Button>
            </div>
            <Button type="button" variant="outline" :class="[control.row, 'tw:w-fit']" @click="createForm.subjects.push({ id: null, name: '' })"><Plus :size="18" aria-hidden="true" />加科目</Button>
          </div>
        </fieldset>
        <p v-if="formError" class="exam-error tw:m-0 tw:text-tpl-caption tw:text-destructive" role="alert">{{ formError }}</p>
      </div>
    </FormDialog>

    <Dialog :open="!!selected" @update:open="onScoreOpen">
      <DialogContent class="exam-score-dialog tw:max-h-[90dvh] tw:grid-rows-[auto_minmax(0,1fr)_auto] tw:gap-0 tw:overflow-hidden tw:p-0 tw:sm:max-w-5xl">
        <DialogHeader class="tw:p-6 tw:pb-4">
          <DialogTitle class="tw:pr-8 tw:text-tpl-list">{{ selected?.title || '登分' }}</DialogTitle>
          <DialogDescription>{{ canEditScores ? '在每位學生的科目欄輸入分數，按「儲存」存下那一列。' : '此場次目前只能查看分數。' }}</DialogDescription>
        </DialogHeader>
        <div class="tw:min-h-0 tw:overflow-y-auto tw:px-6 tw:pb-4">
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

            <!-- Correcting a closed round and the edit history are inline panels, not a second dialog (overlay rules 1 and 7). -->
            <form v-if="reasonFor" aria-label="更正已截止的成績" class="exam-reason-panel tw:mb-3 tw:grid tw:gap-3 tw:rounded-xl tw:border tw:border-border tw:bg-muted tw:p-4" @submit.prevent="submitScores(reasonFor, reasonText)">
              <p class="tw:m-0 tw:text-tpl-body tw:font-semibold">更正已截止的成績</p>
              <div class="tw:grid tw:gap-2">
                <Label for="exam-reason-input" class="tw:text-tpl-body">更正原因（必填）</Label>
                <Input id="exam-reason-input" v-model="reasonText" maxlength="255" placeholder="例如：登錄時打錯" :class="field" />
              </div>
              <div class="tw:flex tw:flex-wrap tw:gap-2">
                <Button type="button" variant="outline" :class="control.row" @click="reasonFor = null">取消</Button>
                <Button type="submit" :class="control.row" :disabled="!reasonText.trim() || savingStudentId !== null">確定更正</Button>
              </div>
            </form>
            <section v-if="historyRows" class="exam-history-panel tw:mb-3 tw:rounded-xl tw:border tw:border-border tw:p-4" aria-labelledby="exam-history-title">
              <div class="tw:mb-2 tw:flex tw:items-center tw:justify-between tw:gap-3">
                <h3 id="exam-history-title" tabindex="-1" class="tw:m-0 tw:text-tpl-body tw:font-semibold">修改紀錄：{{ historyName }}</h3>
                <Button type="button" variant="outline" :class="control.row" @click="historyRows = null">收起</Button>
              </div>
              <p v-if="!historyRows.length" class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary">沒有修改紀錄。</p>
              <ul v-else class="exam-history tw:m-0 tw:grid tw:list-none tw:gap-3 tw:p-0">
                <li v-for="h in historyRows" :key="h.id" class="tw:text-tpl-body">
                  <strong>{{ historyActionLabel(h.action) }} {{ subjectName(h.exam_round_subject_id) }}</strong><template v-if="h.action !== 'score_comment_updated'">：{{ describeScore(h.before) }} → {{ describeScore(h.after) }}</template>
                  <small class="tw:block tw:text-tpl-caption tw:text-tpl-secondary">{{ (h.created_at || '').slice(0, 16).replace('T', ' ') }}<template v-if="h.reason"> · {{ h.reason }}</template></small>
                </li>
              </ul>
            </section>

            <p v-if="!filteredStudents.length" class="exam-score-no-match tw:my-2 tw:text-tpl-caption tw:text-tpl-secondary">找不到符合「{{ studentQuery.trim() }}」的學生。</p>
            <!-- Names stay in view while scrolling across subjects; subject names stay in view while scrolling down. -->
            <div v-else class="tw:max-h-[min(60dvh,480px)] tw:overflow-auto tw:rounded-xl tw:border tw:border-solid tw:border-border">
              <!-- While the saved scores reload the table stays on screen but takes no input, so the refresh cannot overwrite typing. -->
              <table :class="['exam-score-table tw:w-full tw:border-collapse', dataTable.table, tableBusy && 'tw:opacity-60']" :inert="tableBusy || undefined" :aria-busy="tableBusy">
                <thead>
                  <tr>
                    <th :class="[dataTable.head, 'tw:sticky tw:top-0 tw:left-0 tw:z-2 tw:text-left']">學生</th>
                    <th v-for="subject in selectedSubjects" :key="subject.id" :class="[dataTable.head, 'tw:sticky tw:top-0 tw:z-1 tw:text-left']">{{ subject.name }}</th>
                    <th :class="[dataTable.head, 'tw:sticky tw:top-0 tw:right-0 tw:z-2']"><span class="tw:sr-only">操作</span></th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="row in filteredStudents" :key="row.student_id">
                  <tr>
                    <td :class="stickyName">
                      <span>{{ row.student_name }}</span>
                      <span :class="[dataTable.sub, 'tw:tabular-nums', row.is_complete ? toneText.success : '']">{{ row.is_complete ? '已登完 ' : '' }}{{ row.filled_subject_count }}/{{ row.required_subject_count }}<template v-if="commentCount(row)"> · 已寫 {{ commentCount(row) }} 科</template></span>
                    </td>
                    <td v-for="subject in selectedSubjects" :key="subject.id" :class="dataTable.cell">
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
                      <div class="tw:flex tw:items-center tw:justify-end tw:gap-2">
                        <button
                          v-if="canEditScores || commentCount(row)"
                          type="button"
                          :class="rowAction.secondary"
                          :aria-expanded="!!openComments[row.student_id]"
                          :aria-controls="`exam-comments-${row.student_id}`"
                          :aria-label="`${row.student_name} 老師的話`"
                          data-testid="exam-comment-toggle"
                          @click="openComments[row.student_id] = !openComments[row.student_id]"
                        >老師的話</button>
                        <button
                          v-if="canEditScores"
                          type="button"
                          :class="rowAction.secondary"
                          :disabled="savingStudentId === row.student_id"
                          :aria-busy="savingStudentId === row.student_id"
                          :aria-label="`儲存 ${row.student_name}`"
                          @click="saveStudent(row)"
                        >儲存</button>
                      </div>
                    </td>
                  </tr>
                  <!-- 老師的話: one box per subject, saved with the row's 儲存. Pinned to the left edge so it stays in view at 390px. -->
                  <tr v-if="openComments[row.student_id]" :id="`exam-comments-${row.student_id}`" data-testid="exam-comment-row">
                    <td :colspan="selectedSubjects.length + 2" class="tw:border-b tw:border-border tw:bg-muted tw:p-0">
                      <div class="tw:sticky tw:left-0 tw:grid tw:w-[min(100%,calc(100dvw-3rem))] tw:max-w-2xl tw:gap-3 tw:p-3 tw:md:px-4">
                        <p class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary">每科寫給家長的話，最多 500 字。</p>
                        <div v-for="subject in selectedSubjects" :key="subject.id" class="tw:grid tw:gap-1">
                          <Label :for="`exam-comment-${row.student_id}-${subject.id}`">{{ subject.name }}</Label>
                          <Textarea
                            :id="`exam-comment-${row.student_id}-${subject.id}`"
                            :model-value="commentOf(row, subject.id)"
                            maxlength="500"
                            rows="2"
                            :readonly="!canEditScores"
                            class="exam-comment-input tw:min-h-11 tw:bg-background tw:text-[length:var(--tpl-text-body)] tw:md:text-[length:var(--tpl-text-body)]"
                            @update:model-value="(v) => (draftComments[`${row.student_id}:${subject.id}`] = String(v ?? ''))"
                          />
                          <span class="tw:text-right tw:text-tpl-caption tw:text-tpl-secondary tw:tabular-nums">{{ commentOf(row, subject.id).length }}/500</span>
                        </div>
                      </div>
                    </td>
                  </tr>
                  </template>
                </tbody>
              </table>
            </div>
          </template>
          <p v-if="scoreError" class="exam-error tw:mt-3 tw:mb-0 tw:text-tpl-caption tw:text-destructive" role="alert">{{ scoreError }}</p>
        </div>
        <DialogFooter class="tw:border-t tw:bg-background tw:p-4 tw:sm:px-6">
          <Button type="button" variant="outline" @click="onScoreOpen(false)">關閉</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      v-model:open="askDiscardScores"
      title="放棄還沒儲存的分數？"
      description="關閉後，已輸入但還沒按「儲存」的分數不會保留。"
      confirm-label="放棄分數"
      cancel-label="繼續登分"
      destructive
      @confirm="discardScores"
    />
  </section>
</template>

<script setup>
import { trackedFetch as fetch } from '../lib/loadingBar';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { ClipboardList, Plus, RefreshCw, UserX } from 'lucide-vue-next';
import { Alert } from '../components/ui/alert';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import { Skeleton } from '../components/ui/skeleton';
import { FilterChip, PageHeader, SectionCard, StatusBadge, control, dataTable, pageShell, rowAction, toneText } from '../components/ui/page';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../components/ui/dialog';
import { Label } from '../components/ui/label';
import { Textarea } from '../components/ui/textarea';
import { ConfirmDialog, FormDialog } from '../components/app-overlay';
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
const formSnapshot = ref('');
const formDirty = computed(() => showCreate.value && JSON.stringify(createForm) !== formSnapshot.value);
const field = 'tw:h-11 tw:text-[length:var(--tpl-text-body)] tw:md:text-[length:var(--tpl-text-body)]';
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
const selectedSubjects = computed(() => selected.value?.subjects || []);
const askDiscardScores = ref(false);
const students = ref([]);
const studentsLoading = ref(false);
const studentsError = ref('');
const studentQuery = ref('');
const filteredStudents = computed(() => filterExamStudents(students.value, studentQuery.value));
const scoreError = ref('');
const savingStudentId = ref(null);
// Typing during a save or reload would be wiped when the saved scores are loaded again, so the table takes no input meanwhile.
const tableBusy = computed(() => studentsLoading.value || savingStudentId.value !== null);
const draftScores = reactive({});
// 老師的話 typed but not saved, by `${student_id}:${subject_id}`; which rows have the boxes open, by student_id.
const draftComments = reactive({});
const openComments = reactive({});
const pendingClose = ref(null);
const closing = ref(false);
const closeError = ref('');
const closeDescription = computed(() => `關閉後老師不能再改分，確定關閉「${pendingClose.value?.title || ''}」嗎？${closeError.value ? `\n${closeError.value}` : ''}`);
const deleteDescription = computed(() => `確定刪除「${pendingDelete.value?.title || ''}」嗎？${deleteError.value ? `\n${deleteError.value}` : ''}`);
// AlertDialogAction closes on click; the dialog has to stay open while the request runs (and after a failure, to show why),
// so a close request is applied one tick later and only when no request is running.
const onCloseOpen = (open) => { if (!open) queueMicrotask(() => { if (!closing.value) pendingClose.value = null; }); };
const onDeleteOpen = (open) => { if (!open) queueMicrotask(() => { if (!deleting.value) pendingDelete.value = null; }); };
const scoreDirty = computed(() => Object.keys(draftScores).length > 0 || Object.keys(draftComments).length > 0);
function onScoreOpen(open) {
  if (open) return;
  if (scoreDirty.value) askDiscardScores.value = true;
  else selected.value = null;
}
function discardScores() {
  askDiscardScores.value = false;
  Object.keys(draftScores).forEach((k) => delete draftScores[k]);
  Object.keys(draftComments).forEach((k) => delete draftComments[k]);
  selected.value = null;
}

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
  formSnapshot.value = JSON.stringify(createForm);
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
  formSnapshot.value = JSON.stringify(createForm);
  showCreate.value = true;
}

async function createRound() {
  formError.value = '';
  createForm.title = createForm.title.trim();
  createForm.subjects.forEach((x) => { x.name = (x.name || '').trim(); });
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
    await api(`/exam-rounds/${round.id}`, { method: 'DELETE', body: JSON.stringify({ reason: deleteReason.value.trim() || undefined }) });
    pendingDelete.value = null;
    await loadRounds();
  } catch (e) {
    if (/需填寫原因/.test(e.message || '') && !deleteNeedsReason.value) {
      deleteNeedsReason.value = true;
      deleteError.value = '';
      setTimeout(() => document.getElementById('exam-delete-reason')?.focus(), 100);
    } else {
      deleteError.value = e.message || '刪除失敗';
    }
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
    Object.keys(openComments).forEach((k) => delete openComments[k]);
    // Drafts belong to one round. A same-round reload after a row save keeps the other rows' unsaved drafts.
    Object.keys(draftScores).forEach((k) => delete draftScores[k]);
    Object.keys(draftComments).forEach((k) => delete draftComments[k]);
  }
  selected.value = round;
  studentsLoading.value = true;
  studentsError.value = '';
  scoreError.value = '';
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
  return { score: f?.score ?? null, is_absent: !!f?.is_absent, edited: !!f?.edited, clear: false, parent_comment: f?.parent_comment ?? '' };
}

function commentOf(row, subjectId) {
  return draftComments[`${row.student_id}:${subjectId}`] ?? serverCell(row, subjectId).parent_comment;
}

function commentCount(row) {
  return selectedSubjects.value.filter((s) => commentOf(row, s.id).trim()).length;
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
    await nextTick();
    const h = document.getElementById('exam-history-title');
    h?.focus();
    h?.scrollIntoView?.({ block: 'nearest' });
  } catch (e) {
    scoreError.value = examErrorMessage(e);
  }
}

function saveStudent(row) {
  scoreError.value = '';
  const built = rowScores(row);
  if (built.error) {
    scoreError.value = built.error;
    return;
  }
  if (!built.scores.length) {
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

const noScore = (c) => !c.is_absent && (c.score === null || c.score === undefined || c.score === '');

// One row's changes as the API payload. A comment on an untouched cell is sent alone (the server keeps the stored
// score, so a stale page never writes an old score back); the API keeps a comment only on a scored or 缺考 cell.
function rowScores(row) {
  const scores = [];
  for (const subject of selected.value.subjects || []) {
    const key = `${row.student_id}:${subject.id}`;
    const d = draftScores[key];
    let comment = key in draftComments ? draftComments[key].trim() : undefined;
    // Back to what is stored (e.g. typed then erased on an empty cell) is no change.
    if (comment === (serverCell(row, subject.id).parent_comment || '')) comment = undefined;
    if (d?.clear) {
      scores.push({ student_id: row.student_id, exam_round_subject_id: subject.id, clear: true });
      continue;
    }
    // A typed-then-erased (or ticked-then-unticked 缺考) cell is no entry: skip it, as before E2; only 清除 deletes.
    const typed = d && !noScore(d) ? d : null;
    if (!typed && comment === undefined) continue;
    const cell = typed || serverCell(row, subject.id);
    if (noScore(cell)) return { error: `「${subject.name}」要先填分數或勾缺考，才能存老師的話` };
    if (!typed) {
      scores.push({ student_id: row.student_id, exam_round_subject_id: subject.id, parent_comment: comment });
      continue;
    }
    scores.push({
      student_id: row.student_id,
      exam_round_subject_id: subject.id,
      score: cell.is_absent ? null : cell.score,
      is_absent: cell.is_absent,
      ...(comment === undefined ? {} : { parent_comment: comment }),
    });
  }
  return { scores };
}

async function submitScores(row, reason) {
  const { scores = [], error } = rowScores(row);
  if (error) {
    scoreError.value = error;
    return;
  }
  savingStudentId.value = row.student_id;
  try {
    await saveScores(api, selected.value.id, scores, (reason || '').trim());
    reasonFor.value = null;
    const mine = `${row.student_id}:`;
    [draftScores, draftComments].forEach((d) => Object.keys(d).filter((k) => k.startsWith(mine)).forEach((k) => delete d[k]));
    await openRound(selected.value);
  } catch (e) {
    reasonFor.value = null;
    scoreError.value = examErrorMessage(e);
  } finally {
    savingStudentId.value = null;
  }
}

watch(selected, (round) => {
  if (round) return;
  studentQuery.value = '';
  reasonFor.value = null;
  historyRows.value = null;
});
watch(reasonFor, async (row) => {
  if (!row) return;
  await nextTick();
  const input = document.getElementById('exam-reason-input');
  input?.focus();
  input?.scrollIntoView?.({ block: 'nearest' });
});
watch(() => props.branchId, loadRounds);
watch(() => props.focusRoundId, async (id) => {
  if (!id || loading.value) return;
  const focus = rounds.value.find((r) => String(r.id) === String(id));
  if (focus) await openRound(focus);
});
onMounted(loadRounds);
</script>
