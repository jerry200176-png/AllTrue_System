<template>
  <div class="eligibility-page tw:mx-auto tw:flex tw:max-w-[1800px] tw:flex-col tw:gap-4 tw:px-1 tw:pb-24 tw:text-foreground tw:md:px-0 tw:md:pb-0" data-testid="teacher-eligibility-page">
    <header class="tw:pb-2" data-guide="eligibility-header">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-3">
        <h2 class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-page tw:font-bold tw:tracking-tight">
          <Banknote class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
          正職薪資要件
        </h2>
        <div class="header-actions tw:flex tw:w-full tw:flex-wrap tw:items-center tw:gap-2 tw:md:w-auto">
          <Button variant="default" :class="[ctrlButton, 'tw:max-md:flex-1']" :disabled="loading || isLocked" @click="loadData">
            <RefreshCw class="tw:size-4" :stroke-width="2" aria-hidden="true" />
            {{ isLocked ? '已鎖定' : '重新結算' }}
          </Button>
          <Button variant="outline" :class="[ctrlButton, 'tw:max-md:flex-1']" :disabled="loading || !props.branchId" @click="exportCsv">
            <Download class="tw:size-4" :stroke-width="2" aria-hidden="true" />
            匯出 Excel
          </Button>
          <Button v-if="!isLocked" variant="outline" :class="[ctrlButton, 'tw:max-md:flex-1']" :disabled="loading || !props.branchId || reviewCount > 0" @click="lockMonth">
            <Lock class="tw:size-4" :stroke-width="2" aria-hidden="true" />
            鎖定本月
          </Button>
          <Button v-else-if="isHq" variant="outline" :class="[ctrlButton, 'tw:max-md:flex-1']" :disabled="loading" @click="reopenMonth">
            <LockOpen class="tw:size-4" :stroke-width="2" aria-hidden="true" />
            重開結算
          </Button>
        </div>
      </div>
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">依結算月份檢視本薪、獎金與待人工確認項目。</p>
      <p class="tw:mt-2 tw:flex tw:flex-wrap tw:items-center tw:gap-x-3 tw:gap-y-1 tw:text-tpl-caption tw:text-tpl-secondary">
        <span class="privilege-chip tw:rounded-full tw:bg-[var(--ds-warning-wash)] tw:px-2.5 tw:py-1 tw:font-semibold tw:text-[var(--ds-warning)]">高權限存取區</span>
        <span>制度 {{ policyVersion }} · {{ lockLabel }}</span>
      </p>
    </header>

    <!-- P0 2026-11-14: remove once holiday/weekday rates and the new-hire guarantee follow the HQ 115.07 announcement. -->
    <!-- A standing notice, not an interruption: info tone keeps role=status; the warning colours are set by hand. -->
    <Alert tone="info" title="本頁正依總部 115.07 公告校正中" class="tw:border-[var(--ds-warning)] tw:bg-[var(--ds-warning-wash)] tw:text-tpl-body" data-testid="eligibility-calibration-notice">
      科目數獎金、一對三獎金、正課 20 科倍率、每週 16 段已照公告更新。假日／平日倍率、新進保底尚未更新，鎖定前請人工核對。
    </Alert>

    <Card class="eligibility-card filters tw:gap-0 tw:p-4 tw:shadow-none">
      <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-4">
        <label :class="fieldLabel">結算月份
          <Input v-model="settlementMonth" type="month" :class="[fieldControl, 'tw:min-w-40']" @change="period = 'month'; loadData()" />
        </label>
        <label :class="fieldLabel">查詢層級
          <select v-model="period" :class="[fieldControl, selectControl, 'tw:min-w-40']" @change="loadData">
            <option value="week">本週</option>
            <option value="month">結算月</option>
            <option value="year">本年度</option>
          </select>
        </label>
        <label :class="[fieldLabel, 'search-label']">搜尋老師
          <Input v-model.trim="search" placeholder="姓名" :class="[fieldControl, 'tw:min-w-56']" />
        </label>
        <span class="policy-chip tw:rounded-lg tw:bg-secondary tw:px-3 tw:py-2 tw:text-tpl-caption tw:text-tpl-secondary tw:md:ml-auto">本月分校總科目數 {{ formatSubjects(branchSubjectTotal) }} 科｜制度 {{ policyVersion }}｜{{ lockLabel }}</span>
      </div>
    </Card>

    <div v-if="loading" class="tw:flex tw:flex-col tw:gap-3" role="status" aria-live="polite" data-testid="eligibility-loading">
      <span class="tw:sr-only">載入中…</span>
      <Skeleton v-for="n in 5" :key="n" class="tw:h-14 tw:w-full" />
    </div>
    <Alert v-else-if="error" tone="danger" title="無法載入正職薪資要件" class="tw:text-tpl-body">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-3">
        <p class="tw:min-w-0 tw:flex-1 tw:[overflow-wrap:anywhere]">{{ error }}</p>
        <Button type="button" variant="outline" :class="[ctrlButton, 'tw:text-foreground']" @click="loadData">重試</Button>
      </div>
    </Alert>
    <template v-else>
      <div class="summary-grid tw:grid tw:grid-cols-2 tw:gap-3 tw:min-[901px]:grid-cols-4">
        <Card :class="summaryCard"><span :class="summaryLabel">老師總數</span><strong :class="summaryValue">{{ filteredTeachers.length }}</strong></Card>
        <Card :class="summaryCard"><span :class="summaryLabel">符合獎金項目</span><strong :class="[summaryValue, 'tw:text-[var(--ds-success)]']">{{ qualifyingCount }}</strong></Card>
        <Card :class="summaryCard"><span :class="summaryLabel">待人工確認</span><strong :class="[summaryValue, 'tw:text-[var(--ds-warning)]']">{{ reviewCount }}</strong></Card>
        <Card :class="summaryCard"><span :class="summaryLabel">有扣除案件</span><strong :class="[summaryValue, 'tw:text-destructive']">{{ deductionCount }}</strong></Card>
      </div>

      <template v-if="filteredTeachers.length">
      <Card class="eligibility-card table-wrap desktop-table tw:gap-0 tw:py-0 tw:shadow-none tw:max-[900px]:hidden">
        <Table class="tw:min-w-[1280px] tw:text-[length:var(--tpl-text-body)] tw:[&_td]:text-[length:var(--tpl-text-body)]">
          <TableCaption class="tw:sr-only">{{ settlementMonth }} 正職老師薪資要件</TableCaption>
          <TableHeader>
            <TableRow class="tw:hover:bg-transparent">
              <TableHead scope="col" :class="headCell">老師姓名</TableHead>
              <TableHead scope="col" :class="headCell">正課科目數</TableHead>
              <TableHead scope="col" :class="headCell">輔導＋試聽科目數</TableHead>
              <TableHead scope="col" :class="headCell">核薪總科目數</TableHead>
              <TableHead scope="col" :class="headCell">一對三總計</TableHead>
              <TableHead scope="col" :class="headCell">科目數獎金</TableHead>
              <TableHead scope="col" :class="headCell">一對三獎金</TableHead>
              <TableHead scope="col" :class="headCell">教師倍率</TableHead>
              <TableHead scope="col" :class="headCell">倍率後獎金</TableHead>
              <TableHead scope="col" :class="headCell">加扣款</TableHead>
              <TableHead scope="col" :class="headCell">總發放金額</TableHead>
              <TableHead scope="col" :class="headCell">每週課段</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="teacher in filteredTeachers" :key="teacher.teacher_id">
              <!-- in-app #543 family: the name column keeps the whole name on one line (the table is wider than the card and scrolls). -->
              <TableCell :class="[bodyCell, 'teacher-name tw:min-w-[7.5em] tw:whitespace-nowrap']">
                <strong>{{ teacher.teacher_name }}</strong>
                <small :class="smallText">老師</small>
                <span :class="['status', statusClass(teacher.overall_status), statusChip, statusTone[statusClass(teacher.overall_status)]]">{{ statusLabel(teacher) }}</span>
              </TableCell>
              <TableCell :class="bodyCell">{{ formatSubjects(teacher.settlement?.regular_subject_count) }}</TableCell>
              <TableCell :class="bodyCell">{{ formatSubjects(teacher.settlement?.tutoring_trial_subject_count) }}</TableCell>
              <TableCell :class="bodyCell">{{ formatSubjects(teacher.settlement?.payroll_subject_count) }}</TableCell>
              <TableCell :class="bodyCell">{{ formatSubjects(teacher.settlement?.one_to_three_count) }}</TableCell>
              <TableCell :class="[bodyCell, 'tw:tabular-nums']">{{ formatMoney(teacher.settlement?.subject_count_bonus) }}</TableCell>
              <TableCell :class="[bodyCell, 'tw:tabular-nums']">{{ formatMoney(teacher.settlement?.one_to_three_bonus) }}</TableCell>
              <TableCell :class="[bodyCell, 'multiplier-cell tw:min-w-[150px]']">
                <template v-if="editingMultiplierId === teacher.teacher_id">
                  <Input v-model.number="multiplierDraft" type="number" min="0" max="300" step="0.01" :class="multiplierInput" aria-label="手動教師倍率" />
                  <Button variant="outline" :class="smallButton" :disabled="savingMultiplier" @click="saveMultiplier(teacher)">存</Button>
                  <Button variant="outline" :class="smallButton" @click="editingMultiplierId = null">取消</Button>
                </template>
                <template v-else>
                  <strong>{{ formatPct(teacher.settlement?.multiplier_pct) }}</strong>
                  <small v-if="teacher.settlement?.manual_multiplier_pct != null" :class="smallText">手動總倍率</small>
                  <small v-if="teacher.pending_salary?.manual_multiplier_pct != null" :class="smallText">待核准 {{ formatPct(teacher.pending_salary.manual_multiplier_pct) }}</small>
                  <small v-for="part in visibleMultiplierParts(teacher)" :key="part.key" :class="smallText">{{ part.label }} {{ formatPct(part.pct) }}</small>
                  <Button v-if="!isLocked" variant="outline" :class="smallButton" @click="startEditMultiplier(teacher)">改</Button>
                  <Button v-if="isHq && teacher.pending_salary?.manual_multiplier_pct != null && !isLocked" variant="outline" :class="smallButton" :disabled="savingMultiplier" @click="approveMultiplier(teacher)">核准倍率</Button>
                </template>
              </TableCell>
              <TableCell :class="bodyCell">
                <strong class="tw:tabular-nums">{{ formatMoney(teacher.settlement?.weighted_bonus_amount) }}</strong>
                <small :class="smallText">{{ weightedBonusFormula(teacher.settlement) }}</small>
              </TableCell>
              <TableCell :class="bodyCell">
                <span
                  v-for="item in teacher.settlement?.adjustments || []"
                  :key="item.label"
                  :class="['adj-chip', adjChip, Number(item.amount) >= 0 ? 'pos tw:bg-[var(--ds-success-wash)] tw:text-[var(--ds-success)]' : 'neg tw:bg-[var(--ds-danger-wash)] tw:text-destructive']"
                >{{ item.label }} {{ Number(item.amount) >= 0 ? '+' : '' }}{{ Math.round(Number(item.amount)) }}</span>
                <small v-if="!(teacher.settlement?.adjustments || []).length" :class="smallText">—</small>
              </TableCell>
              <TableCell :class="[bodyCell, 'total-cell']">
                <strong class="tw:tabular-nums tw:text-[var(--ds-primary-text)]">{{ formatMoney(teacher.settlement?.total_payout) }}</strong>
                <small v-if="isDraft(teacher)" :class="smallText">試算，未可發放</small>
              </TableCell>
              <TableCell :class="[bodyCell, 'weekly-segment-cell tw:min-w-[300px]']">
                <strong>{{ weeklySegmentsLabel(teacher) }}</strong>
                <small :class="smallText">{{ weeklyQualificationLabel(teacher) }}</small>
                <details v-if="weeklyWeeks(teacher).length" class="weekly-trace tw:mt-2 tw:border-t tw:border-border tw:pt-1.5">
                  <summary class="tw:cursor-pointer tw:text-tpl-caption tw:text-[var(--ds-primary-text)]">查看每週段數</summary>
                  <div v-for="week in weeklyWeeks(teacher)" :key="week.week_start" :class="traceRow">
                    <span>{{ week.week_start }}～{{ week.week_end }}</span>
                    <span>{{ formatSegments(week.total_segments) }} 段・{{ week.meets_16_segments ? '達標' : '未達' }}</span>
                  </div>
                </details>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </Card>
      <div class="mobile-list tw:hidden tw:gap-3 tw:max-[900px]:grid">
        <Card v-for="teacher in filteredTeachers" :key="teacher.teacher_id" class="eligibility-card teacher-card tw:gap-0 tw:p-4 tw:shadow-none">
          <div class="teacher-card-header tw:flex tw:items-start tw:justify-between tw:gap-3">
            <div class="tw:min-w-0 tw:[word-break:keep-all] tw:[overflow-wrap:break-word]"><strong>{{ teacher.teacher_name }}</strong><small :class="smallText">老師</small></div>
            <span :class="['status', statusClass(teacher.overall_status), statusChip, statusTone[statusClass(teacher.overall_status)]]">{{ statusLabel(teacher) }}</span>
          </div>
          <div :class="componentRow"><span>核薪總科目數</span><span :class="componentValue">{{ formatSubjects(teacher.settlement?.payroll_subject_count) }}</span></div>
          <div :class="componentRow"><span>科目數獎金</span><span :class="[componentValue, 'tw:tabular-nums']">{{ formatMoney(teacher.settlement?.subject_count_bonus) }}</span></div>
          <div :class="componentRow"><span>一對三獎金</span><span :class="[componentValue, 'tw:tabular-nums']">{{ formatMoney(teacher.settlement?.one_to_three_bonus) }}</span></div>
          <div :class="componentRow"><span>教師倍率</span><span :class="componentValue">
            <template v-if="editingMultiplierId === teacher.teacher_id">
              <Input v-model.number="multiplierDraft" type="number" min="0" max="300" step="0.01" :class="multiplierInput" aria-label="手動教師倍率" />
              <Button variant="outline" :class="smallButton" :disabled="savingMultiplier" @click="saveMultiplier(teacher)">存</Button>
              <Button variant="outline" :class="smallButton" @click="editingMultiplierId = null">取消</Button>
            </template>
            <template v-else>
              {{ formatPct(teacher.settlement?.multiplier_pct) }}
              <small v-if="teacher.pending_salary?.manual_multiplier_pct != null" :class="smallText">待核准 {{ formatPct(teacher.pending_salary.manual_multiplier_pct) }}</small>
              <Button v-if="!isLocked" variant="outline" :class="smallButton" @click="startEditMultiplier(teacher)">改</Button>
              <Button v-if="isHq && teacher.pending_salary?.manual_multiplier_pct != null && !isLocked" variant="outline" :class="smallButton" :disabled="savingMultiplier" @click="approveMultiplier(teacher)">核准倍率</Button>
            </template>
          </span></div>
          <div :class="componentRow"><span>倍率後獎金</span><span :class="[componentValue, 'tw:tabular-nums']">{{ formatMoney(teacher.settlement?.weighted_bonus_amount) }}</span></div>
          <div :class="[componentRow, 'total-cell']"><span>總發放金額</span><strong class="tw:tabular-nums tw:text-[var(--ds-primary-text)]">{{ formatMoney(teacher.settlement?.total_payout) }}</strong></div>
          <div :class="[componentRow, 'weekly-segment-row']">
            <span>每週課段</span>
            <span :class="componentValue">
              <strong>{{ weeklySegmentsLabel(teacher) }}</strong>
              <small :class="smallText">{{ weeklyQualificationLabel(teacher) }}</small>
            </span>
          </div>
          <details v-if="weeklyWeeks(teacher).length" class="weekly-trace mobile-weekly-trace tw:mt-2 tw:mb-3 tw:border-t tw:border-border tw:pt-1.5">
            <summary class="tw:cursor-pointer tw:text-tpl-caption tw:text-[var(--ds-primary-text)]">查看每週段數</summary>
            <div v-for="week in weeklyWeeks(teacher)" :key="week.week_start" :class="traceRow">
              <span>{{ week.week_start }}～{{ week.week_end }}</span>
              <span>{{ formatSegments(week.total_segments) }} 段・{{ week.meets_16_segments ? '達標' : '未達' }}</span>
            </div>
          </details>
          <p class="mobile-reason tw:mt-3 tw:text-tpl-caption tw:leading-relaxed tw:text-tpl-secondary">{{ reasonText(teacher) }}</p>
        </Card>
      </div>
      </template>
      <Card v-else class="tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none" role="status">
        <UserX class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
        <p class="tw:px-5 tw:text-tpl-list tw:font-semibold">查詢期間沒有符合條件的正職老師</p>
        <p class="tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">請調整結算月份、查詢層級或老師搜尋條件後再試。</p>
      </Card>
      <div class="tw:[&_.eligibility-input-panel_button]:min-h-11 tw:[&_.eligibility-input-panel_select]:min-h-11 tw:[&_.eligibility-input-panel_input]:min-h-11 tw:[&_.eligibility-input-panel_textarea]:min-h-11">
        <TeacherEligibilityInputPanel
          :branch-id="props.branchId"
          :teachers="filteredTeachers"
          :start="periodWindow.start"
          :end="periodWindow.end"
          :user-role="props.userRole"
          @changed="loadData"
        />
      </div>
      <p class="footnote tw:px-1 tw:text-tpl-caption tw:text-tpl-secondary">資料不足時仍會列出試算金額並標「待人工確認／試算」，不能鎖定發放。全勤／勞健保尚未列入自動計算。</p>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Banknote, Download, Lock, LockOpen, RefreshCw, UserX } from 'lucide-vue-next';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { fetchTeacherEligibility, saveTeacherMultiplierProfile, approveTeacherSalaryProfile, lockFulltimePayroll, reopenFulltimePayroll, exportFulltimePayrollCsv } from '../lib/teacherEligibilityApi.js';
import TeacherEligibilityInputPanel from '../components/TeacherEligibilityInputPanel.vue';
import {
  formatMoney,
  formatPct,
  formatSubjects,
  monthWindow,
  weightedBonusFormula,
} from '../lib/teacherEligibilityDisplay.js';
import { humanizeApiErrorMessage } from '../lib/humanizeApiErrorMessage.js';

const ctrlButton = 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none';
const smallButton = 'tw:mr-1 tw:mt-1 tw:h-11 tw:px-3 tw:text-tpl-caption tw:font-semibold tw:shadow-none';
const fieldLabel = 'tw:flex tw:flex-col tw:gap-1.5 tw:text-tpl-caption tw:text-tpl-secondary';
const fieldControl = 'tw:h-12 tw:rounded-lg tw:border-border tw:bg-card tw:px-3 tw:text-[length:var(--tpl-text-body)] tw:text-foreground tw:shadow-none';
const selectControl = 'tw:border tw:outline-none tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50';
const multiplierInput = 'tw:mr-1 tw:h-11 tw:w-24 tw:px-2 tw:text-[length:var(--tpl-text-body)] tw:shadow-none';
const summaryCard = 'tw:gap-1.5 tw:p-4 tw:shadow-none';
const summaryLabel = 'tw:text-tpl-caption tw:text-tpl-secondary';
const summaryValue = 'tw:block tw:text-3xl tw:tabular-nums';
const headCell = 'tw:h-auto tw:bg-secondary tw:px-3 tw:py-3 tw:text-tpl-caption tw:text-tpl-secondary';
const bodyCell = 'tw:px-3 tw:py-3 tw:align-top tw:whitespace-normal';
const smallText = 'tw:mt-1 tw:block tw:text-tpl-caption tw:text-tpl-secondary';
const statusChip = 'tw:mt-1.5 tw:inline-flex tw:rounded-full tw:px-2 tw:py-1 tw:text-tpl-caption tw:whitespace-nowrap';
const adjChip = 'tw:mr-1.5 tw:mb-1.5 tw:inline-flex tw:rounded-full tw:px-2 tw:py-0.5 tw:text-tpl-caption';
const statusTone = {
  pass: 'tw:bg-[var(--ds-success-wash)] tw:text-[var(--ds-success)]',
  fail: 'tw:bg-[var(--ds-danger-wash)] tw:text-destructive',
  review: 'tw:bg-[var(--ds-warning-wash)] tw:text-[var(--ds-warning)]',
  unknown: 'tw:bg-secondary tw:text-tpl-secondary',
};
const traceRow = 'course-trace-row tw:flex tw:justify-between tw:gap-3 tw:py-1.5 tw:text-tpl-caption tw:text-tpl-secondary';
const componentRow = 'tw:flex tw:justify-between tw:gap-3 tw:border-b tw:border-border tw:py-2.5 tw:text-[length:var(--tpl-text-body)]';
const componentValue = 'component-value tw:flex tw:flex-col tw:items-end tw:gap-1';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: '' },
});
const period = ref('month');
const now = new Date();
const settlementMonth = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);
const search = ref('');
const loading = ref(false);
const error = ref('');
const teachers = ref([]);
const periodWindow = ref({ start: '', end: '' });
const policyVersion = ref('115.07');
const branchSubjectTotal = ref(0);
const lockState = ref({ status: 'draft' });
const editingMultiplierId = ref(null);
const multiplierDraft = ref(100);
const savingMultiplier = ref(false);
const componentOptions = [
  { key: 'weekly_16_segments', label: '每週16段' },
  { key: 'holiday_16_hours', label: '假日16小時' },
  { key: 'weekday_afternoon', label: '平日下午課' },
  { key: 'special_performance', label: '特殊表現' },
  { key: 'deductions', label: '扣除案件' },
  { key: 'admin_allowance', label: '行政加給' },
  { key: 'subject_count_bonus', label: '科目數獎金' },
];

const isHq = computed(() => props.userRole === 'super_admin');
const isLocked = computed(() => lockState.value?.status === 'locked');
const lockLabel = computed(() => (isLocked.value ? '已鎖定結算單' : '草稿結算單'));

const filteredTeachers = computed(() => teachers.value.filter(teacher => !search.value || teacher.teacher_name.includes(search.value)));
const reviewCount = computed(() => filteredTeachers.value.filter(t => t.review_required).length);
const qualifyingCount = computed(() => filteredTeachers.value.filter((teacher) => componentOptions.some((item) => {
  const component = teacher.components?.[item.key];
  return component?.status === 'qualifies' && (Number(component.rate) > 0 || Number(component.amount) > 0);
})).length);
const deductionCount = computed(() => filteredTeachers.value.filter(t => Number(t.components?.deductions?.rate || 0) < 0).length);

function visibleMultiplierParts(teacher) {
  return (teacher.settlement?.multiplier_parts || []).filter((part) => Number(part.pct) !== 0);
}

function weeklyMetrics(teacher) {
  return teacher.components?.weekly_16_segments?.metrics || {};
}

function formatSegments(value) {
  if (value == null || value === '') return '—';
  return Number(value).toLocaleString('zh-TW', { maximumFractionDigits: 2 });
}

function weeklySegmentsLabel(teacher) {
  const metrics = weeklyMetrics(teacher);
  return `排課 ${formatSegments(metrics.total_segments)} 段`;
}

function weeklyQualificationLabel(teacher) {
  const metrics = weeklyMetrics(teacher);
  if (metrics.meets_16_segments === true) return '已達 16 段';
  if (metrics.meets_16_segments === false) return '未達 16 段';
  if (metrics.week_count) return `${metrics.qualifying_weeks || 0}/${metrics.week_count} 週達標`;
  return teacher.components?.weekly_16_segments?.status === 'review' ? '待確認段數' : '未判定';
}

function weeklyWeeks(teacher) {
  return (weeklyMetrics(teacher).weeks || []).map((week) => week.metrics || {}).filter((week) => week.week_start);
}

function isDraft(teacher) {
  return Boolean(teacher.review_required || teacher.settlement?.payout_is_draft);
}

async function exportCsv() {
  if (!props.branchId) return;
  try {
    await exportFulltimePayrollCsv({ month: settlementMonth.value, branchId: props.branchId });
  } catch (e) {
    error.value = humanizeApiErrorMessage(e?.message, '匯出失敗');
  }
}

async function lockMonth() {
  if (!props.branchId) return;
  if (!window.confirm('鎖定後本月金額凍結。仍有試算列時不能鎖定。')) return;
  try {
    await lockFulltimePayroll({ month: settlementMonth.value, branchId: props.branchId });
    await loadData();
  } catch (e) {
    error.value = humanizeApiErrorMessage(e?.message, '鎖定失敗');
  }
}

async function reopenMonth() {
  const reason = window.prompt('請填重開原因（總部）');
  if (!reason) return;
  try {
    await reopenFulltimePayroll({ month: settlementMonth.value, branchId: props.branchId, reason });
    await loadData();
  } catch (e) {
    error.value = humanizeApiErrorMessage(e?.message, '重開失敗');
  }
}

function startEditMultiplier(teacher) {
  editingMultiplierId.value = teacher.teacher_id;
  multiplierDraft.value = Number(teacher.settlement?.manual_multiplier_pct ?? teacher.settlement?.multiplier_pct ?? 100);
}

async function saveMultiplier(teacher) {
  savingMultiplier.value = true;
  try {
    const saved = await saveTeacherMultiplierProfile({
      teacher_id: teacher.teacher_id,
      branch_id: props.branchId || null,
      manual_multiplier_pct: multiplierDraft.value,
      effective_from: periodWindow.value.start || `${settlementMonth.value}-01`,
    });
    editingMultiplierId.value = null;
    await loadData();
    if (saved?.status === 'pending') {
      error.value = '教師倍率已送出，待總部核准後才會改結算金額。';
    }
  } catch (e) {
    error.value = humanizeApiErrorMessage(e?.message, '教師倍率儲存失敗');
  } finally {
    savingMultiplier.value = false;
  }
}

async function approveMultiplier(teacher) {
  if (!teacher.pending_salary?.id) return;
  savingMultiplier.value = true;
  try {
    await approveTeacherSalaryProfile(teacher.pending_salary.id);
    await loadData();
  } catch (e) {
    error.value = humanizeApiErrorMessage(e?.message, '教師倍率核准失敗');
  } finally {
    savingMultiplier.value = false;
  }
}

async function loadData() {
  loading.value = true; error.value = '';
  try {
    const range = period.value === 'month' ? monthWindow(settlementMonth.value) : {};
    const data = await fetchTeacherEligibility({
      period: period.value,
      branchId: props.branchId,
      start: range.start,
      end: range.end,
    });
    teachers.value = data.teachers || [];
    periodWindow.value = { start: data.period?.start || range.start || '', end: data.period?.end || range.end || '' };
    policyVersion.value = data.policy_version || policyVersion.value;
    branchSubjectTotal.value = Number(data.branch_subject_total ?? 0);
    lockState.value = data.lock || { status: 'draft' };
  } catch (e) { error.value = humanizeApiErrorMessage(e?.message, '載入失敗'); }
  finally { loading.value = false; }
}

function statusLabel(teacher) {
  if (isDraft(teacher)) return '試算';
  return { qualifies: '符合', not_qualifies: '不符合', review: '待人工確認' }[teacher.overall_status] || '未判定';
}
function statusClass(status) { return { qualifies: 'pass', not_qualifies: 'fail', review: 'review' }[status] || 'unknown'; }
function reasonText(teacher) {
  const reasons = [];
  if (teacher.work_hours_source === 'schedule') {
    reasons.push('每週段數來源：排課（同一時段重疊的課只算一次）');
  }
  for (const item of componentOptions) {
    const component = teacher.components?.[item.key];
    if (component?.reason) reasons.push(`${item.label}：${component.reason}`);
  }
  if (teacher.missing_fields?.length) reasons.push(`缺少：${teacher.missing_fields.join('、')}`);
  return reasons.join('；');
}

watch(() => props.branchId, loadData);
onMounted(loadData);
</script>
