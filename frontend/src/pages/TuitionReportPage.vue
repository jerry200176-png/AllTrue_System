<template>
  <PageShell data-testid="tuition-report-page">
    <PageHeader title="當月學收" context="本月已上課堂數 × 費率的估算，不等於已收。" data-guide="tuition-report-header">
      <template #actions>
        <div class="tr-month-picker tw:flex tw:flex-1 tw:items-center tw:justify-between tw:gap-1 tw:md:flex-none">
          <Button variant="outline" size="icon" :class="control.pageIcon" aria-label="上一月" @click="prevMonth">
            <ChevronLeft class="tw:size-5" :stroke-width="2" aria-hidden="true" />
          </Button>
          <span class="tr-month-label tw:min-w-32 tw:text-center tw:text-tpl-body tw:font-semibold tw:whitespace-nowrap tw:tabular-nums">{{ year }} 年 {{ month }} 月</span>
          <Button variant="outline" size="icon" :class="control.pageIcon" aria-label="下一月" @click="nextMonth">
            <ChevronRight class="tw:size-5" :stroke-width="2" aria-hidden="true" />
          </Button>
        </div>
        <Button variant="outline" size="icon" :class="control.pageIcon" aria-label="重新整理" :disabled="loading" @click="loadData">
          <RefreshCw class="tw:size-5" :stroke-width="2" aria-hidden="true" />
        </Button>
        <Button variant="outline" :class="[ctrlButton, 'tw:max-md:flex-1']" :disabled="loading || !rows.length" @click="exportCsv">
          <Download class="tw:size-4" :stroke-width="2" aria-hidden="true" />
          匯出 CSV
        </Button>
      </template>
    </PageHeader>

    <SectionCard v-if="loading">
      <div class="tw:p-5">
        <div class="tr-loading tw:flex tw:flex-col tw:gap-3" role="status" aria-live="polite" data-testid="tuition-report-loading">
          <span class="tw:sr-only">載入中…</span>
          <Skeleton v-for="n in 3" :key="n" class="tw:h-14 tw:w-full" />
        </div>
      </div>
    </SectionCard>

    <Alert v-else-if="error" tone="danger" class="tr-error tw:text-tpl-body">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-3">
        <span class="tr-error-msg tw:min-w-0 tw:flex-1 tw:[overflow-wrap:anywhere]">{{ error }}</span>
        <Button variant="outline" :class="`tr-retry-btn ${ctrlButton} tw:text-foreground`" @click="loadData">再試一次</Button>
      </div>
    </Alert>

    <template v-else>
      <template v-if="rows.length || unpaidOnly">
        <StatStrip class="tr-stats">
          <StatItem label="學生" :value="summary.total_students" unit="人" class="tw:max-md:hidden" />
          <StatItem label="堂數" :value="summary.total_sessions" unit="堂" class="tw:max-md:hidden" />
          <StatItem label="學收合計" :value="formatAmount(summary.total_tuition)" class="tw:max-md:col-span-2" />
          <StatItem label="已收" :value="formatAmount(summary.total_collected)" :sub="`應收 ${formatAmount(summary.total_due)}`" tone="success" />
          <StatItem label="未收" :value="summary.unpaid_students" unit="人" :sub="outstandingSub" :tone="collection.allCollected ? 'neutral' : 'danger'" />
        </StatStrip>
        <p class="tw:sr-only" role="status" data-testid="collection-summary">{{ collection.text }}</p>
      </template>

      <SectionCard v-if="rows.length || unpaidOnly" title="學收明細" :count="meta.total || rows.length">
        <template #actions>
          <label class="tr-filter tw:inline-flex tw:min-h-11 tw:cursor-pointer tw:items-center tw:gap-2 tw:text-tpl-body tw:font-medium">
            <Checkbox :model-value="unpaidOnly" class="tw:size-5" @update:model-value="onFilterChange" />
            只看未收
          </label>
        </template>

        <div v-if="!rows.length" class="tr-empty tw:flex tw:flex-col tw:items-center tw:gap-2 tw:px-5 tw:py-10 tw:text-center" role="status">
          <CircleCheck class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
          <p class="tw:text-tpl-body tw:text-tpl-secondary">{{ collection.allCollected ? '本月學收已收齊' : `本分校 ${year}/${month} 無有效課程紀錄` }}</p>
        </div>

        <Table v-else class="tr-table" :class="t.table">
          <TableCaption class="tw:sr-only">{{ year }} 年 {{ month }} 月學收明細</TableCaption>
          <TableHeader>
            <TableRow class="tw:hover:bg-transparent">
              <TableHead scope="col" :class="[t.head, 'tw:pl-5']">學生</TableHead>
              <TableHead scope="col" :class="[t.head, t.phoneHide]">科目</TableHead>
              <TableHead scope="col" :class="[t.head, t.phoneHide]">老師</TableHead>
              <TableHead scope="col" :class="[t.head, t.phoneHide]">班型</TableHead>
              <TableHead scope="col" :class="[t.numHead, t.phoneHide]">月堂數</TableHead>
              <TableHead scope="col" :class="[t.numHead, t.phoneHide]">費率</TableHead>
              <TableHead scope="col" :class="[t.numHead, 'tw:max-md:pr-5']">上課應收（估算）</TableHead>
              <TableHead scope="col" :class="[t.head, t.phoneHide]">收款狀態</TableHead>
              <TableHead scope="col" :class="[t.head, t.phoneHide, 'tw:pr-5']">繳費日期</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="r in rows" :key="r.student_class_id" class="tw:hover:bg-muted/60">
              <!-- in-app #543: a name stays on one line (PAGE_TEMPLATE name-cell rule 1); the subject and teacher repeat under it on phone. -->
              <TableCell :class="[t.name, 'tw:pl-5']">
                <span class="student-name" :title="r.student_name">{{ r.student_name }}</span>
                <span :class="[t.sub, t.phoneOnly]">{{ r.subject }}・<span class="tw:inline-block tw:whitespace-nowrap">{{ r.teacher_name }}</span></span>
              </TableCell>
              <TableCell :class="[t.cell, t.phoneHide, 'tw:min-w-[8em] tw:whitespace-normal']">{{ r.subject }}</TableCell>
              <TableCell :class="[t.cell, t.phoneHide]">{{ r.teacher_name }}</TableCell>
              <TableCell :class="[t.cell, t.phoneHide, 'tw:text-tpl-secondary']">{{ classTypeLabel(r.class_type) }}</TableCell>
              <TableCell :class="[t.num, t.phoneHide]">{{ r.monthly_sessions }}</TableCell>
              <TableCell :class="[t.num, t.phoneHide]">{{ formatAmount(r.rate) }}</TableCell>
              <TableCell :class="[t.num, 'tw:font-semibold tw:max-md:pr-5']">
                {{ formatAmount(r.monthly_tuition) }}
                <span :class="['tw:mt-1 tw:ml-auto tw:flex tw:max-w-[12em] tw:flex-wrap tw:items-center tw:justify-end tw:gap-x-2 tw:gap-y-1', t.phoneOnly]">
                  <span v-if="owed(r)" class="pay-owed tw:text-tpl-caption tw:font-normal tw:text-tpl-secondary">差 {{ formatAmount(r.outstanding_amount) }}</span>
                  <StatusBadge :tone="payTone[r.pay_status] || 'neutral'" class="pay-tag" :class="`pay-tag--${r.pay_status}`">{{ payStatusLabel(r.pay_status) }}</StatusBadge>
                </span>
              </TableCell>
              <TableCell :class="[t.cell, t.phoneHide]">
                <span class="tw:flex tw:items-center tw:gap-2">
                  <StatusBadge :tone="payTone[r.pay_status] || 'neutral'" class="pay-tag" :class="`pay-tag--${r.pay_status}`">{{ payStatusLabel(r.pay_status) }}</StatusBadge>
                  <span v-if="owed(r)" class="pay-owed tw:text-tpl-caption tw:whitespace-nowrap tw:text-tpl-secondary tw:tabular-nums">差 {{ formatAmount(r.outstanding_amount) }}</span>
                </span>
              </TableCell>
              <TableCell :class="[t.cell, t.phoneHide, 'paid-date tw:pr-5 tw:whitespace-nowrap tw:tabular-nums', r.last_paid_at ? '' : 'tw:text-tpl-secondary']">{{ r.last_paid_at || '—' }}</TableCell>
            </TableRow>
          </TableBody>
          <tfoot>
            <TableRow class="tw:hover:bg-transparent">
              <TableCell :class="[t.total, 'tw:pl-5']">合計</TableCell>
              <TableCell :class="[t.total, t.phoneHide]" :colspan="3"></TableCell>
              <TableCell :class="[t.total, t.phoneHide, 'tw:text-right tw:tabular-nums']">{{ summary.total_sessions }}</TableCell>
              <TableCell :class="[t.total, t.phoneHide]"></TableCell>
              <TableCell :class="[t.total, 'tw:text-right tw:tabular-nums tw:max-md:pr-5']">{{ formatAmount(summary.total_tuition) }}</TableCell>
              <TableCell :class="[t.total, t.phoneHide]"></TableCell>
              <TableCell :class="[t.total, t.phoneHide]"></TableCell>
            </TableRow>
          </tfoot>
        </Table>

        <template v-if="meta.last_page > 1" #footer>
          <nav class="tr-pagination tw:flex tw:w-full tw:items-center tw:justify-between tw:gap-3" aria-label="學收報表分頁">
            <Button variant="outline" :class="control.row" :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">上一頁</Button>
            <span class="tr-page-info tw:text-center tw:text-tpl-caption tw:text-tpl-secondary">第 {{ meta.current_page }} / {{ meta.last_page }} 頁（共 {{ meta.total }} 筆）</span>
            <Button variant="outline" :class="control.row" :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">下一頁</Button>
          </nav>
        </template>
      </SectionCard>

      <SectionCard v-else class="tr-empty" role="status">
        <div class="tw:flex tw:flex-col tw:items-center tw:gap-2 tw:px-5 tw:py-10 tw:text-center">
          <CalendarDays class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
          <p class="tw:text-tpl-body tw:text-tpl-secondary">本分校 {{ year }}/{{ month }} 無有效課程紀錄</p>
        </div>
      </SectionCard>
    </template>
  </PageShell>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { CalendarDays, ChevronLeft, ChevronRight, CircleCheck, Download, RefreshCw } from 'lucide-vue-next';
import { collectionSummary, payStatusLabel } from '../lib/tuitionPayStatus';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { PageShell, PageHeader, StatStrip, StatItem, SectionCard, StatusBadge, control, dataTable as t } from '@/components/ui/page';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
});

const now = new Date();
const year = ref(now.getFullYear());
const month = ref(now.getMonth() + 1);

const rows = ref([]);
const summary = ref({ total_students: 0, total_sessions: 0, total_tuition: 0, total_due: 0, total_collected: 0, total_outstanding: 0, unpaid_students: 0 });
const meta = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0 });
const loading = ref(false);
const error = ref('');
const currentPage = ref(1);
const unpaidOnly = ref(false);
const collection = computed(() => collectionSummary(summary.value));
function onFilterChange(checked) { unpaidOnly.value = checked === true; currentPage.value = 1; loadData(); }

const ctrlButton = control.page;
// PAGE_TEMPLATE §6: 已收 = success, 未收 = danger, 待確認 / 部分 = warning, the rest neutral.
const payTone = { paid: 'success', partial: 'warning', review: 'warning', unpaid: 'danger' };
const owed = (r) => r.pay_status !== 'paid' && r.outstanding_amount > 0;
// The 未收 figure's sub line: what is still owed, or that everything is in; unconfirmed evidence is named so it never reads as collected.
const outstandingSub = computed(() => {
  if (collection.value.allCollected) return '本月學收已收齊';
  const review = Number(summary.value.review_students || 0);
  const base = `差 ${formatAmount(summary.value.total_outstanding)}`;
  return review > 0 ? `${base}，另有 ${review} 人待確認` : base;
});

const CLASS_TYPE_LABELS = {
  one_on_one: '一對一',
  one_on_two: '一對二',
  one_on_three: '一對三',
  tutoring: '輔導',
  trial: '試聽',
};
const classTypeLabel = (type) => CLASS_TYPE_LABELS[type] || type || '—';
const formatAmount = (n) => Number(n || 0).toLocaleString('zh-TW');

function prevMonth() {
  if (month.value <= 1) { year.value--; month.value = 12; }
  else { month.value--; }
  currentPage.value = 1;
  loadData();
}
function nextMonth() {
  if (month.value >= 12) { year.value++; month.value = 1; }
  else { month.value++; }
  currentPage.value = 1;
  loadData();
}
function goPage(p) {
  currentPage.value = p;
  loadData();
}

async function loadData() {
  loading.value = true;
  error.value = '';
  try {
    const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
    const token = session?.access_token;
    if (!token) { error.value = '請先登入'; return; }

    const params = new URLSearchParams();
    if (props.branchId != null && props.branchId !== '') {
      params.set('branch_id', String(Number(props.branchId)));
    }
    params.set('year', String(year.value));
    params.set('month', String(month.value));
    params.set('page', String(currentPage.value));
    if (unpaidOnly.value) params.set('unpaid_only', '1');

    const resp = await fetch(`/api/v1/finance/branch-monthly-tuition?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) throw new Error(`載入失敗（${resp.status}）`);
    const json = await resp.json();
    rows.value = json.data || [];
    summary.value = json.summary || { total_students: 0, total_sessions: 0, total_tuition: 0, total_due: 0, total_collected: 0, total_outstanding: 0, unpaid_students: 0 };
    meta.value = json.meta || { current_page: 1, last_page: 1, per_page: 50, total: 0 };
  } catch (e) {
    error.value = e.message || '載入失敗';
  } finally {
    loading.value = false;
  }
}

async function exportCsv() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  const token = session?.access_token;
  if (!token) { alert('請先登入'); return; }
  const params = new URLSearchParams();
  if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));
  params.set('year', String(year.value));
  params.set('month', String(month.value));
  const url = `/api/v1/finance/branch-monthly-tuition/export?${params}`;
  try {
    const resp = await fetch(url, { headers: { Authorization: `Bearer ${token}` } });
    if (!resp.ok) { alert('匯出失敗'); return; }
    const blob = await resp.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    const cd = resp.headers.get('Content-Disposition') || '';
    const match = cd.match(/filename\*?=['"]?([^'";\s]+)/);
    a.download = match ? decodeURIComponent(match[1]) : `當月學收_${year.value}${String(month.value).padStart(2,'0')}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  } catch (e) {
    alert('匯出失敗：' + e.message);
  }
}

watch(() => props.branchId, () => { currentPage.value = 1; loadData(); }, { flush: 'post' });
loadData();
</script>
