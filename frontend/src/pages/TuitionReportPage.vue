<template>
  <div class="tuition-report-page tw:mx-auto tw:flex tw:max-w-6xl tw:flex-col tw:gap-4 tw:px-[calc(var(--ds-space-4)-10px)] tw:pb-24 tw:text-foreground tw:md:px-0 tw:md:pb-0" data-testid="tuition-report-page">
    <header class="tw:pb-2" data-guide="tuition-report-header">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-3">
        <h2 class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-page tw:font-bold tw:tracking-tight">
          <BarChart3 class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
          當月學收
        </h2>
        <div class="tr-controls tw:flex tw:w-full tw:flex-wrap tw:items-center tw:gap-2 tw:md:w-auto">
          <div class="tr-month-picker tw:flex tw:w-full tw:items-center tw:justify-between tw:gap-1 tw:md:w-auto">
            <Button variant="outline" size="icon" :class="`${ctrlButton} tw:size-12 tw:px-0`" aria-label="上一月" @click="prevMonth">
              <ChevronLeft class="tw:size-5" :stroke-width="2" aria-hidden="true" />
            </Button>
            <span class="tr-month-label tw:min-w-28 tw:text-center tw:font-semibold tw:whitespace-nowrap tw:tabular-nums">{{ year }} 年 {{ month }} 月</span>
            <Button variant="outline" size="icon" :class="`${ctrlButton} tw:size-12 tw:px-0`" aria-label="下一月" @click="nextMonth">
              <ChevronRight class="tw:size-5" :stroke-width="2" aria-hidden="true" />
            </Button>
          </div>
          <Button variant="outline" :class="`${ctrlButton} tw:max-md:flex-1`" :disabled="loading" @click="loadData">
            <RefreshCw class="tw:size-4" :stroke-width="2" aria-hidden="true" />
            重新整理
          </Button>
          <Button variant="outline" :class="`${ctrlButton} tw:max-md:flex-1`" :disabled="loading || !rows.length" @click="exportCsv">
            <Download class="tw:size-4" :stroke-width="2" aria-hidden="true" />
            匯出 CSV
          </Button>
        </div>
      </div>
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">本月已上課堂數 × 費率的估算，不等於已收。</p>
    </header>

    <div v-if="loading" class="tr-loading tw:flex tw:flex-col tw:gap-3" role="status" aria-live="polite" data-testid="tuition-report-loading">
      <span class="tw:sr-only">載入中…</span>
      <Skeleton v-for="n in 3" :key="n" class="tw:h-14 tw:w-full" />
    </div>

    <Alert v-else-if="error" tone="danger" class="tr-error tw:text-tpl-body">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-3">
        <span class="tr-error-msg tw:min-w-0 tw:flex-1 tw:[overflow-wrap:anywhere]">{{ error }}</span>
        <Button variant="outline" :class="`tr-retry-btn ${ctrlButton} tw:text-foreground`" @click="loadData">再試一次</Button>
      </div>
    </Alert>

    <template v-else>
      <div v-if="rows.length || unpaidOnly" class="tr-stats tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:text-tpl-body">
        <span class="tr-stat tw:rounded-lg tw:bg-secondary tw:px-3.5 tw:py-1.5">學生 <strong class="tw:tabular-nums">{{ summary.total_students }}</strong> 人</span>
        <span class="tr-stat tw:rounded-lg tw:bg-secondary tw:px-3.5 tw:py-1.5">堂數 <strong class="tw:tabular-nums">{{ summary.total_sessions }}</strong> 堂</span>
        <span class="tr-stat tr-stat--primary tw:rounded-lg tw:bg-secondary tw:px-3.5 tw:py-1.5 tw:font-semibold">學收合計 <strong class="tw:tabular-nums">{{ formatAmount(summary.total_tuition) }}</strong></span>
        <span
          class="tr-stat tw:rounded-lg tw:bg-secondary tw:px-3.5 tw:py-1.5 tw:font-semibold"
          :class="collection.allCollected ? 'tr-stat--ok tw:text-[var(--ds-success)]' : 'tr-stat--warn tw:text-destructive'"
          data-testid="collection-summary"
          role="status"
        >{{ collection.text }}</span>
        <label class="tr-filter tw:inline-flex tw:min-h-11 tw:items-center tw:gap-2">
          <Checkbox :model-value="unpaidOnly" class="tw:size-5" @update:model-value="onFilterChange" />
          只看未收
        </label>
      </div>

      <Card v-if="!rows.length && unpaidOnly && collection.allCollected" class="tr-empty tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none" role="status">
        <CircleCheck class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
        <p class="tw:text-tpl-body tw:text-tpl-secondary">本月學收已收齊</p>
      </Card>

      <Card v-else-if="!rows.length" class="tr-empty tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none" role="status">
        <CalendarDays class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
        <p class="tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">本分校 {{ year }}/{{ month }} 無有效課程紀錄</p>
      </Card>

      <Card v-else class="tr-table-wrap tw:gap-0 tw:py-0 tw:shadow-none">
        <Table class="tr-table tw:min-w-[36rem] tw:text-[length:var(--tpl-text-body)] tw:[&_td]:text-[length:var(--tpl-text-body)]">
          <TableCaption class="tw:sr-only">{{ year }} 年 {{ month }} 月學收明細</TableCaption>
          <TableHeader>
            <TableRow class="tw:hover:bg-transparent">
              <TableHead scope="col" :class="headCell">學生</TableHead>
              <TableHead scope="col" :class="headCell">科目</TableHead>
              <TableHead scope="col" :class="[headCell, hideSm]">老師</TableHead>
              <TableHead scope="col" :class="[headCell, hideSm]">班型</TableHead>
              <TableHead scope="col" :class="numHead">月堂數</TableHead>
              <TableHead scope="col" :class="numHead">費率</TableHead>
              <TableHead scope="col" :class="numHead">上課應收（估算）</TableHead>
              <TableHead scope="col" :class="headCell">收款狀態</TableHead>
              <TableHead scope="col" :class="[headCell, hideSm]">繳費日期</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="r in rows" :key="r.student_class_id">
              <!-- in-app #543: a name stays on one line. `overflow-wrap:anywhere` made the column's min-content one character, so the table crushed it. -->
              <TableCell class="student-name tw:max-w-[11em] tw:min-w-[4.5em] tw:truncate tw:px-3 tw:py-3 tw:whitespace-nowrap" :title="r.student_name">{{ r.student_name }}</TableCell>
              <TableCell class="tw:min-w-[7em] tw:px-3 tw:py-3 tw:whitespace-normal tw:[word-break:keep-all] tw:[overflow-wrap:break-word]">{{ r.subject }}</TableCell>
              <TableCell :class="['tw:min-w-[5em] tw:px-3 tw:py-3 tw:whitespace-nowrap', hideSm]">{{ r.teacher_name }}</TableCell>
              <TableCell :class="['tw:px-3 tw:py-3', hideSm]">
                <span :class="typeChip">{{ classTypeLabel(r.class_type) }}</span>
              </TableCell>
              <TableCell class="tw:px-3 tw:py-3 tw:text-right tw:tabular-nums">{{ r.monthly_sessions }}</TableCell>
              <TableCell class="tw:px-3 tw:py-3 tw:text-right tw:tabular-nums">{{ formatAmount(r.rate) }}</TableCell>
              <TableCell class="tw:px-3 tw:py-3 tw:text-right tw:tabular-nums"><strong>{{ formatAmount(r.monthly_tuition) }}</strong></TableCell>
              <TableCell class="tw:px-3 tw:py-3">
                <span class="pay-tag tw:inline-block tw:rounded-full tw:bg-secondary tw:px-2 tw:py-0.5 tw:text-tpl-caption tw:font-semibold tw:whitespace-nowrap" :class="[`pay-tag--${r.pay_status}`, payTone[r.pay_status]]">{{ payStatusLabel(r.pay_status) }}</span>
                <small v-if="r.pay_status !== 'paid' && r.outstanding_amount > 0" class="pay-owed tw:block tw:text-tpl-caption tw:text-tpl-secondary">差 {{ formatAmount(r.outstanding_amount) }}</small>
              </TableCell>
              <TableCell :class="['tw:px-3 tw:py-3', hideSm]">
                <span v-if="r.last_paid_at" class="paid-date tw:whitespace-nowrap tw:text-[var(--ds-success)]">{{ r.last_paid_at }}</span>
                <span v-else class="tw:text-tpl-secondary">—</span>
              </TableCell>
            </TableRow>
          </TableBody>
          <tfoot class="tw:border-t-2 tw:border-border">
            <TableRow class="tw:hover:bg-transparent">
              <TableCell :colspan="4" class="tw:px-3 tw:py-3 tw:max-md:hidden"><strong>合計</strong></TableCell>
              <TableCell class="tw:px-3 tw:py-3 tw:md:hidden"><strong>合計</strong></TableCell>
              <TableCell class="tw:px-3 tw:py-3 tw:text-right tw:tabular-nums"><strong>{{ summary.total_sessions }}</strong></TableCell>
              <TableCell class="tw:px-3 tw:py-3 tw:text-right">—</TableCell>
              <TableCell class="tw:px-3 tw:py-3 tw:text-right tw:tabular-nums"><strong>{{ formatAmount(summary.total_tuition) }}</strong></TableCell>
              <TableCell class="tw:px-3 tw:py-3"></TableCell>
              <TableCell :class="['tw:px-3 tw:py-3', hideSm]"></TableCell>
            </TableRow>
          </tfoot>
        </Table>
      </Card>

      <nav v-if="meta.last_page > 1" class="tr-pagination tw:flex tw:flex-wrap tw:items-center tw:justify-center tw:gap-3" aria-label="學收報表分頁">
        <Button variant="outline" :class="ctrlButton" :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">上一頁</Button>
        <span class="tr-page-info tw:text-tpl-caption tw:text-tpl-secondary">第 {{ meta.current_page }} / {{ meta.last_page }} 頁（共 {{ meta.total }} 筆）</span>
        <Button variant="outline" :class="ctrlButton" :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">下一頁</Button>
      </nav>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { BarChart3, CalendarDays, ChevronLeft, ChevronRight, CircleCheck, Download, RefreshCw } from 'lucide-vue-next';
import { collectionSummary, payStatusLabel } from '../lib/tuitionPayStatus';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

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

const ctrlButton = 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none';
const headCell = 'tw:px-3 tw:text-tpl-caption';
const numHead = `${headCell} tw:text-right`;
const hideSm = 'tw:max-md:hidden';
const typeChip = 'tw:inline-block tw:rounded-full tw:border tw:border-border tw:bg-secondary tw:px-2 tw:py-0.5 tw:text-tpl-caption tw:font-semibold tw:whitespace-nowrap';
const payTone = {
  paid: 'tw:text-[var(--ds-success)]',
  partial: 'tw:text-[var(--ds-warning)]',
  review: 'tw:text-[var(--ds-warning)]',
  unpaid: 'tw:text-destructive',
};

const CLASS_TYPE_LABELS = {
  one_on_one: '一對一',
  one_on_two: '一對二',
  one_on_three: '一對三',
  tutoring: '輔導',
  trial: '試聽',
};
const classTypeLabel = (t) => CLASS_TYPE_LABELS[t] || t || '—';
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
