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
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">依結算月份檢視獎金與待人工確認項目（不含本薪）。</p>
      <p class="tw:mt-2 tw:flex tw:flex-wrap tw:items-center tw:gap-x-3 tw:gap-y-1 tw:text-tpl-caption tw:text-tpl-secondary">
        <span class="privilege-chip tw:rounded-full tw:bg-[var(--ds-warning-wash)] tw:px-2.5 tw:py-1 tw:font-semibold tw:text-[var(--ds-warning)]">高權限存取區</span>
        <span>制度 {{ policyVersion }} · {{ lockLabel }}</span>
      </p>
    </header>

    <!-- P0 2026-11-15: remove once the Founder has decided what happens to months locked under the old rules. -->
    <!-- A standing notice, not an interruption: info tone keeps role=status; the warning colours are set by hand. -->
    <Alert tone="info" title="本頁已照總部 115.07 公告計算" class="tw:border-[var(--ds-warning)] tw:bg-[var(--ds-warning-wash)] tw:text-tpl-body" data-testid="eligibility-calibration-notice">
      獎金、倍率、每週 16 段與新進保底都已照公告更新。2026-11-15 以前鎖定的月份仍是舊算法的數字；打開那個月份，會在獎金合計下面顯示照現在算法重算的金額與差額。
    </Alert>
    <p v-if="settledElsewhere.length" class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty" data-testid="eligibility-settled-elsewhere">
      也在別的分校任教、這個月由該分校結算的老師（不重複列在這裡）：{{ settledElsewhere.map((row) => `${row.teacher_name}（${row.campus_name}）`).join('、') }}
    </p>
    <p v-if="isLocked && recalculationNote" class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty" data-testid="eligibility-recalculation-note">{{ recalculationNote }}</p>

    <Card class="eligibility-card filters tw:gap-0 tw:p-4 tw:shadow-none">
      <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-4">
        <label :class="fieldLabel">結算月份
          <Input v-model="settlementMonth" type="month" :class="[fieldControl, 'tw:min-w-40']" @change="loadData" />
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
      <!-- What blocks this month's lock comes first; everything the system could work out by itself is not listed. -->
      <Card v-if="attention.length" class="eligibility-card tw:gap-0 tw:border-[var(--ds-warning)] tw:p-4 tw:shadow-none" data-testid="eligibility-attention">
        <h3 class="tw:text-tpl-list tw:font-semibold">需要你處理（{{ attention.length }}）</h3>
        <p class="tw:mt-1 tw:text-tpl-caption tw:text-tpl-secondary">這些處理完，這個月才能鎖定。其他的系統都算好了。</p>
        <ul class="tw:mt-2 tw:flex tw:flex-col tw:divide-y tw:divide-border">
          <li v-for="item in attention" :key="item.key" class="tw:flex tw:flex-wrap tw:items-center tw:gap-x-3 tw:gap-y-1 tw:py-2.5">
            <strong class="tw:whitespace-nowrap">{{ item.teacherName }}</strong>
            <span class="tw:min-w-0 tw:flex-1 tw:basis-48 tw:text-[length:var(--tpl-text-body)] tw:text-pretty">{{ item.label }}：{{ item.reason }}</span>
            <Button type="button" variant="outline" :class="[ctrlButton, 'tw:h-11']" @click="search = item.teacherName">只看這位</Button>
          </li>
        </ul>
      </Card>
      <div class="summary-grid tw:grid tw:grid-cols-1 tw:gap-3 tw:min-[601px]:grid-cols-3">
        <Card :class="summaryCard"><span :class="summaryLabel">要你處理</span><strong :class="[summaryValue, attention.length ? 'tw:text-[var(--ds-warning)]' : '']">{{ attention.length }} 項</strong></Card>
        <Card :class="summaryCard">
          <span :class="summaryLabel">這個月獎金合計</span><strong :class="summaryValue">{{ formatMoney(payoutTotal) }}</strong>
          <small v-if="reviewCount" :class="summaryLabel">不含 {{ reviewCount }} 位還沒確定的老師</small>
        </Card>
        <Card :class="summaryCard">
          <span :class="summaryLabel">正職老師</span><strong :class="summaryValue">{{ filteredTeachers.length }} 位</strong>
          <small v-if="idleTeachers.length" :class="summaryLabel">其中 {{ idleTeachers.length }} 位這個月沒有課</small>
        </Card>
      </div>

      <template v-if="filteredTeachers.length">
      <!-- One list for every screen width: a line per teacher, opened to see how the amount was worked out. The class
           names desktop-table, mobile-list and teacher-card are what frontend/e2e/teacher-eligibility-clarity.spec.js looks for. -->
      <Card class="eligibility-card desktop-table mobile-list tw:gap-0 tw:px-4 tw:py-1 tw:shadow-none">
        <details v-for="teacher in activeTeachers" :key="teacher.teacher_id" class="teacher-card tw:border-b tw:border-border tw:last:border-b-0" :open="filteredTeachers.length === 1">
          <summary class="tw:flex tw:min-h-14 tw:cursor-pointer tw:flex-wrap tw:items-center tw:gap-x-4 tw:gap-y-1 tw:py-3">
            <strong class="teacher-name tw:text-tpl-list tw:[word-break:keep-all]">{{ teacher.teacher_name }}</strong>
            <span class="tw:min-w-0 tw:flex-1 tw:basis-64 tw:text-tpl-caption tw:text-tpl-secondary tw:text-pretty">{{ teacherSummaryLine(teacher) }}</span>
            <span :class="['status', statusChip, isDraft(teacher) ? statusTone.review : statusTone.pass]">{{ isDraft(teacher) ? '要處理' : '可發放' }}</span>
            <span class="total-cell tw:flex tw:min-w-24 tw:flex-col tw:items-end">
              <b class="tw:text-xl tw:tabular-nums">{{ formatMoney(teacher.settlement?.total_payout) }}</b>
              <small v-if="isDraft(teacher)" :class="smallText">試算，還不能發</small>
              <small v-if="recalculationText(teacher)" :class="smallText">{{ recalculationText(teacher) }}</small>
            </span>
          </summary>
          <div class="tw:grid tw:gap-x-8 tw:gap-y-4 tw:pb-4 tw:min-[901px]:grid-cols-2">
            <section>
              <h3 class="tw:text-tpl-body tw:font-semibold">怎麼算出來的</h3>
              <div :class="mathRow"><span>科目數獎金<small :class="smallText">核薪總科目數 {{ formatSubjects(teacher.settlement?.payroll_subject_count) }} 科（正課 {{ formatSubjectUnits(teacher.settlement?.regular_subject_count) }}＋輔導試聽 {{ formatSubjectUnits(teacher.settlement?.tutoring_trial_subject_count) }}）</small></span><span :class="mathValue">{{ formatMoney(teacher.settlement?.subject_count_bonus) }}</span></div>
              <div :class="mathRow"><span>一對三獎金<small :class="smallText">{{ oneToThreeFormula(teacher.settlement) }}</small></span><span :class="mathValue">{{ formatMoney(teacher.settlement?.one_to_three_bonus) }}</span></div>
              <div :class="[mathRow, 'multiplier-cell']"><span>乘上教師倍率<small :class="smallText">{{ multiplierBreakdown(teacher.settlement) }}</small><small v-if="multiplierPendingNote(teacher.settlement)" :class="smallText">{{ multiplierPendingNote(teacher.settlement) }}</small></span><span :class="mathValue">{{ multiplierValue(teacher.settlement) }}</span></div>
              <div :class="mathRow"><span>倍率後獎金<small :class="smallText">{{ weightedBonusFormula(teacher.settlement) }}</small></span><span :class="mathValue">{{ formatMoney(teacher.settlement?.weighted_bonus_amount) }}</span></div>
              <div :class="[mathRow, 'weekly-segment-cell']"><span>每週 16 段獎金<small :class="smallText">{{ weeklyQualificationLabel(teacher) }}</small></span><span :class="mathValue">{{ formatMoney(teacher.settlement?.weekly_segment_bonus_amount ?? 0) }}</span></div>
              <div v-for="item in cashAdjustments(teacher.settlement)" :key="item.label" :class="mathRow"><span>{{ item.label }}</span><span :class="mathValue">{{ Number(item.amount) >= 0 ? '+' : '−' }}{{ formatMoney(Math.abs(Number(item.amount))) }}</span></div>
              <div :class="[mathRow, 'tw:border-b-0 tw:font-semibold']"><span>獎金合計</span><span :class="[mathValue, 'tw:text-[length:var(--tpl-text-list)]']">{{ formatMoney(teacher.settlement?.total_payout) }}</span></div>
            </section>
            <section>
              <h3 class="tw:text-tpl-body tw:font-semibold">每週 16 段（一段 2 小時）</h3>
              <p :class="smallText">看課表上的時間，同一時段重疊的課只算一次。每一週都滿，才有這筆獎金。</p>
              <div v-for="week in weeklyWeeks(teacher)" :key="week.week_start" :class="mathRow">
                <span>{{ week.week_start.slice(5).replace('-', '/') }}–{{ week.week_end.slice(5).replace('-', '/') }}<small :class="smallText">{{ weekExplanation(week).detail }}</small></span>
                <span :class="[mathValue, 'tw:whitespace-nowrap']">{{ weekExplanation(week).verdict }}</span>
              </div>
              <p v-if="!weeklyWeeks(teacher).length" :class="smallText">這個月沒有可以判定的週。</p>
            </section>
            <p v-if="reasonText(teacher)" class="mobile-reason tw:text-tpl-caption tw:leading-relaxed tw:text-tpl-secondary tw:min-[901px]:col-span-2">{{ reasonText(teacher) }}</p>
          </div>
        </details>
        <details v-if="idleTeachers.length" class="idle-teachers">
          <summary class="tw:flex tw:min-h-14 tw:cursor-pointer tw:items-center tw:py-3 tw:text-tpl-body tw:text-tpl-secondary">這個月沒有課的老師（{{ idleTeachers.length }} 位），不用處理</summary>
          <p class="tw:pb-4 tw:text-tpl-body tw:text-pretty">{{ idleTeachers.map((teacher) => teacher.teacher_name).join('、') }}</p>
        </details>
      </Card>
      </template>
      <Card v-else class="tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none" role="status">
        <UserX class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
        <p class="tw:px-5 tw:text-tpl-list tw:font-semibold">查詢期間沒有符合條件的正職老師</p>
        <p class="tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">請調整結算月份或老師搜尋條件後再試。</p>
      </Card>
      <div class="tw:[&_.eligibility-input-panel_button]:min-h-11 tw:[&_.eligibility-input-panel_select]:min-h-11 tw:[&_.eligibility-input-panel_input]:min-h-11 tw:[&_.eligibility-input-panel_textarea]:min-h-11">
        <TeacherEligibilityInputPanel
          :branch-id="props.branchId"
          :teachers="inputRoster(filteredTeachers, settledElsewhere)"
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
import { fetchTeacherEligibility, lockFulltimePayroll, reopenFulltimePayroll, exportFulltimePayrollCsv } from '../lib/teacherEligibilityApi.js';
import TeacherEligibilityInputPanel from '../components/TeacherEligibilityInputPanel.vue';
import {
  attentionItems,
  cashAdjustments,
  formatMoney,
  inputRoster,
  formatSubjects,
  formatSubjectUnits,
  hasNoLessons,
  monthWindow,
  multiplierBreakdown,
  oneToThreeFormula,
  multiplierPendingNote,
  multiplierValue,
  recalculationSummary,
  recalculationText,
  teacherSummaryLine,
  weekExplanation,
  weightedBonusFormula,
} from '../lib/teacherEligibilityDisplay.js';
import { humanizeApiErrorMessage } from '../lib/humanizeApiErrorMessage.js';

const ctrlButton = 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none';
const fieldLabel = 'tw:flex tw:flex-col tw:gap-1.5 tw:text-tpl-caption tw:text-tpl-secondary';
const fieldControl = 'tw:h-12 tw:rounded-lg tw:border-border tw:bg-card tw:px-3 tw:text-[length:var(--tpl-text-body)] tw:text-foreground tw:shadow-none';
const summaryCard = 'tw:gap-1.5 tw:p-4 tw:shadow-none';
const summaryLabel = 'tw:text-tpl-caption tw:text-tpl-secondary';
const summaryValue = 'tw:block tw:text-3xl tw:tabular-nums';
const smallText = 'tw:mt-1 tw:block tw:text-tpl-caption tw:text-tpl-secondary';
const statusChip = 'tw:inline-flex tw:rounded-full tw:px-2 tw:py-1 tw:text-tpl-caption tw:whitespace-nowrap';
const statusTone = {
  pass: 'tw:bg-[var(--ds-success-wash)] tw:text-[var(--ds-success)]',
  fail: 'tw:bg-[var(--ds-danger-wash)] tw:text-destructive',
  review: 'tw:bg-[var(--ds-warning-wash)] tw:text-[var(--ds-warning)]',
  unknown: 'tw:bg-secondary tw:text-tpl-secondary',
};
const mathRow = 'tw:flex tw:justify-between tw:gap-4 tw:border-b tw:border-border tw:py-2.5 tw:text-[length:var(--tpl-text-body)]';
const mathValue = 'tw:shrink-0 tw:text-right tw:font-semibold tw:tabular-nums';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: '' },
});
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
const settledElsewhere = ref([]);
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
const recalculationNote = computed(() => recalculationSummary(teachers.value));
const reviewCount = computed(() => filteredTeachers.value.filter(t => t.review_required).length);
const attention = computed(() => attentionItems(teachers.value));
// Draft rows are left out: a total that mixes final and trial amounts would look more certain than it is.
const payoutTotal = computed(() => filteredTeachers.value.reduce((sum, teacher) => sum + (isDraft(teacher) ? 0 : Number(teacher.settlement?.total_payout || 0)), 0));
// Teachers with no lesson and nothing to pay are folded away at the bottom: nothing to check about them.
const idleTeachers = computed(() => filteredTeachers.value.filter(hasNoLessons));
const activeTeachers = computed(() => filteredTeachers.value.filter((teacher) => !hasNoLessons(teacher)));

function weeklyMetrics(teacher) {
  return teacher.components?.weekly_16_segments?.metrics || {};
}

function weeklyQualificationLabel(teacher) {
  const metrics = weeklyMetrics(teacher);
  // A single week in view that is still running has no verdict yet, whatever its schedule says so far.
  if (metrics.week_count === 1 && metrics.unfinished_weeks) return '這一週還沒結束，先不判定';
  if (metrics.meets_16_segments === true) return '已達 16 段';
  if (metrics.meets_16_segments === false) return '未達 16 段';
  if (metrics.week_count && metrics.unfinished_weeks) return `${metrics.qualifying_weeks || 0}/${metrics.week_count} 週達標，${metrics.unfinished_weeks} 週還沒結束`;
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

// Every read carries the number of the load it belongs to; only the newest load may put anything on screen, so a slow
// answer for a month or campus the user has already left is dropped.
let loadNumber = 0;

async function loadData() {
  const thisLoad = ++loadNumber;
  loading.value = true; error.value = '';
  try {
    const range = monthWindow(settlementMonth.value);
    const data = await fetchTeacherEligibility({
      period: 'month',
      branchId: props.branchId,
      start: range.start,
      end: range.end,
    });
    if (thisLoad !== loadNumber) return;
    teachers.value = data.teachers || [];
    periodWindow.value = { start: data.period?.start || range.start || '', end: data.period?.end || range.end || '' };
    policyVersion.value = data.policy_version || policyVersion.value;
    branchSubjectTotal.value = Number(data.branch_subject_total ?? 0);
    lockState.value = data.lock || { status: 'draft' };
    settledElsewhere.value = data.settled_elsewhere || [];
    if (data.lock?.status === 'locked') loadRecalculation(thisLoad, range);
  } catch (e) { if (thisLoad === loadNumber) error.value = humanizeApiErrorMessage(e?.message, '載入失敗'); }
  finally { if (thisLoad === loadNumber) loading.value = false; }
}

// A locked month keeps its numbers; this adds what today's rules give, next to each one. It is extra information:
// the page is already usable, and a failure here leaves the locked figures as they are.
async function loadRecalculation(thisLoad, range) {
  try {
    const data = await fetchTeacherEligibility({ period: 'month', branchId: props.branchId, start: range.start, end: range.end, compare: true });
    if (thisLoad === loadNumber && data.lock?.status === 'locked') teachers.value = data.teachers || teachers.value;
  } catch { /* the locked figures stay on screen */ }
}

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
