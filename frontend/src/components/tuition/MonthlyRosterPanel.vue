<template>
  <!-- 月對帳表 (Founder 2026-11-17): the directors' old monthly sheet as a workflow — pick a month, one row per student
       course, its lessons, what it owes, what came in — drawn with the page template, not as a spreadsheet. -->
  <section class="tw:flex tw:flex-col tw:gap-4" data-testid="monthly-roster">
    <p v-if="campusId == null" class="tw:px-5 tw:py-10 tw:text-center tw:text-tpl-body tw:text-tpl-secondary" role="status">請先選分校：月對帳表一次看一個分校。</p>
    <template v-else>
      <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-3">
        <div class="tw:flex tw:items-center tw:gap-1" role="group" aria-label="月份">
          <Button variant="outline" size="icon" :class="control.pageIcon" aria-label="上一月" @click="shift(-1)"><ChevronLeft class="tw:size-5" aria-hidden="true" /></Button>
          <span class="tw:min-w-32 tw:text-center tw:text-tpl-body tw:font-semibold tw:tabular-nums" data-testid="roster-month">{{ year }} 年 {{ month }} 月</span>
          <Button variant="outline" size="icon" :class="control.pageIcon" aria-label="下一月" @click="shift(1)"><ChevronRight class="tw:size-5" aria-hidden="true" /></Button>
        </div>
        <Input v-model="query" type="search" class="tw:h-11 tw:w-full tw:max-w-64" placeholder="找學生或課程編號" aria-label="找學生或課程編號" />
        <div class="tw:flex tw:flex-wrap tw:gap-2" role="group" aria-label="只看">
          <FilterChip v-for="f in FILTERS" :key="f.key" :active="filter === f.key" :count="counts[f.key]" :data-testid="`roster-filter-${f.key}`" @click="filter = f.key">{{ f.label }}</FilterChip>
        </div>
        <Button variant="outline" class="tw:min-h-11 tw:sm:ml-auto" :disabled="!visible.length" data-testid="roster-export" @click="exportCsv"><Download class="tw:size-4" aria-hidden="true" />匯出 Excel</Button>
      </div>

      <StatStrip>
        <StatItem label="學生" :value="summary.students" unit="人" />
        <StatItem label="這個月收到" :value="money(summary.paid_this_month)" tone="success" />
        <StatItem label="未繳" :value="money(summary.outstanding)" :tone="summary.outstanding > 0 ? 'danger' : 'neutral'" />
        <StatItem label="待確認入帳" :value="summary.pending_reports" unit="筆" :tone="summary.pending_reports > 0 ? 'warning' : 'neutral'" />
      </StatStrip>

      <Alert v-if="error" tone="danger" class="tw:text-tpl-body">
        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-3">
          <span class="tw:min-w-0 tw:flex-1">{{ error }}</span>
          <Button variant="outline" class="tw:min-h-11" @click="load">再試一次</Button>
        </div>
      </Alert>

      <SectionCard :title="`${year} 年 ${month} 月`" :count="visible.length">
        <div v-if="loading && !students.length" class="tw:flex tw:flex-col tw:gap-3 tw:p-5"><Skeleton v-for="n in 4" :key="n" class="tw:h-10" /></div>
        <p v-else-if="!visible.length" class="tw:px-5 tw:py-10 tw:text-center tw:text-tpl-body tw:text-tpl-secondary" role="status">
          {{ students.length ? '沒有符合的學生' : '這個月沒有課，也沒有要收的錢' }}
        </p>
        <Table v-else :class="t.table">
          <TableCaption class="tw:sr-only">{{ year }} 年 {{ month }} 月對帳</TableCaption>
          <TableHeader>
            <TableRow class="tw:hover:bg-transparent">
              <TableHead scope="col" :class="[t.head, 'tw:pl-5']">學生</TableHead>
              <TableHead scope="col" :class="t.head">科目・老師</TableHead>
              <TableHead scope="col" :class="[t.head, t.phoneHide]">這個月的課</TableHead>
              <TableHead scope="col" :class="[t.numHead, t.phoneHide]">單價</TableHead>
              <TableHead scope="col" :class="[t.head, 'tw:pr-5']">收款</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody v-for="s in visible" :key="s.student_id" :data-testid="`roster-student-${s.student_id}`">
            <TableRow v-for="(c, i) in s.courses" :key="c.course_id" class="tw:hover:bg-muted/60" :data-testid="`roster-course-${c.course_id}`">
              <TableCell v-if="i === 0" :rowspan="s.courses.length" :class="[t.name, 'tw:pl-5 tw:align-top']">
                <button type="button" class="tw:cursor-pointer tw:border-0 tw:bg-transparent tw:p-0 tw:text-left tw:font-semibold tw:text-foreground tw:underline-offset-4 tw:hover:underline" @click="open(s, c, null)">{{ s.student_name }}</button>
                <span :class="t.sub">{{ s.school }}</span>
                <span v-if="s.outstanding > 0" :class="[t.sub, 'tw:text-[var(--ds-danger-text)]']">共欠 {{ money(s.outstanding) }}</span>
              </TableCell>
              <TableCell :class="[t.cell, 'tw:min-w-[9em] tw:whitespace-normal']">
                {{ c.subject }}<span class="tw:text-tpl-secondary">・{{ c.teacher_name || '—' }}</span>
                <span v-if="c.stopped" :class="t.sub">已停課</span>
                <span :class="[t.sub, t.phoneOnly]">{{ lessonLine(c) }}</span>
              </TableCell>
              <TableCell :class="[t.cell, t.phoneHide, 'tw:whitespace-normal']">
                <ul class="tw:m-0 tw:flex tw:list-none tw:flex-wrap tw:gap-1.5 tw:p-0" :aria-label="`${c.subject}這個月 ${c.lessons.length} 堂`">
                  <li v-for="l in c.lessons" :key="l.date + l.start" :class="['tw:rounded-full tw:px-2 tw:py-0.5 tw:text-tpl-caption tw:tabular-nums', LESSON[l.kind].cls]" :title="`${l.date} ${l.start} ${LESSON[l.kind].label}`">{{ md(l.date) }}<span class="tw:sr-only"> {{ LESSON[l.kind].label }}</span></li>
                  <li v-if="!c.lessons.length" class="tw:text-tpl-caption tw:text-tpl-secondary">沒有排課</li>
                </ul>
                <span :class="t.sub">已上 {{ c.held }} 堂</span>
              </TableCell>
              <TableCell :class="[t.num, t.phoneHide]">{{ rateLabel(c) }}</TableCell>
              <TableCell :class="[t.cell, 'tw:pr-5 tw:whitespace-normal']">
                <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                  <StatusBadge :tone="state(c).tone">{{ state(c).label }}</StatusBadge>
                  <Button v-if="c.pending_reports" variant="outline" class="tw:min-h-11" :data-testid="`roster-confirm-${c.course_id}`" @click="open(s, c, 'confirm')">確認入帳</Button>
                  <Button v-else-if="c.money.outstanding > 0" variant="outline" class="tw:min-h-11" :data-testid="`roster-record-${c.course_id}`" @click="open(s, c, 'record')">登記收款</Button>
                </div>
                <span v-if="c.money.month_total != null && !c.money.month_invoiced" :class="t.sub">本月預估 {{ money(c.money.month_total) }}</span>
                <span v-for="(p, k) in c.paid_this_month" :key="k" :class="t.sub">{{ md(p.date) }} {{ METHOD[p.method] || p.method }} {{ money(p.amount) }}</span>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </SectionCard>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, Download } from '@lucide/vue';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FilterChip, SectionCard, StatItem, StatStrip, StatusBadge, control, dataTable as t } from '@/components/ui/page';
import { authedFetch, getAccessToken } from '../../lib/authedFetch';

const props = defineProps({ branchId: { type: [Number, String], default: null } });
const emit = defineEmits(['open']);

const FILTERS = [{ key: 'all', label: '全部' }, { key: 'owing', label: '未繳' }, { key: 'pending', label: '待確認入帳' }];
// The payment slip's price; null = no price set, or it changed inside this month (the student ledger has both).
const rateLabel = (c) => (c.rate ? `${money(c.rate)}${c.rate_unit === 'hour' ? '／小時' : ''}` : '—');

const LESSON = {
  held: { label: '已上', cls: 'tw:bg-secondary tw:text-foreground' },
  upcoming: { label: '還沒上', cls: 'tw:border tw:border-dashed tw:border-[var(--tpl-line)] tw:text-tpl-secondary' },
  off: { label: '請假或沒上', cls: 'tw:text-tpl-secondary tw:line-through' },
};
const METHOD = { cash: '現金', transfer: '匯款', card: '信用卡', line_pay: 'LINE Pay', backfill: '現金（補建）', void: '退回', refund: '退費' };

const now = new Date();
const year = ref(now.getFullYear());
const month = ref(now.getMonth() + 1);
const query = ref('');
const filter = ref('all');
const students = ref([]);
const summary = ref({ students: 0, outstanding: 0, paid_this_month: 0, pending_reports: 0 });
const loading = ref(false);
const error = ref('');
const campusId = computed(() => (props.branchId == null || props.branchId === '' ? null : Number(props.branchId)));
let seq = 0; // only the newest request may write: a slow reply for another month or campus is dropped

const money = (n) => `${n < 0 ? '−' : ''}NT$${Math.abs(Number(n || 0)).toLocaleString('en-US')}`;
const md = (d) => `${Number(d.slice(5, 7))}/${Number(d.slice(8, 10))}`;
const lessonLine = (c) => (c.lessons.length ? `${c.lessons.map((l) => md(l.date)).join('、')}（已上 ${c.held}）` : '沒有排課');
function state(c) {
  if (c.pending_reports) return { tone: 'warning', label: `待確認 ${c.pending_reports} 筆` };
  if (c.money.outstanding > 0) return { tone: 'danger', label: `未繳 ${money(c.money.outstanding)}` };
  if (c.money.status === 'review_required') return { tone: 'warning', label: '要核對' };
  if (['paid', 'free'].includes(c.money.status)) return { tone: 'success', label: '已收齊' };
  return { tone: 'neutral', label: '還沒有帳單' };
}
const matches = {
  all: () => true,
  owing: (s) => s.courses.some((c) => c.money.outstanding > 0),
  pending: (s) => s.courses.some((c) => c.pending_reports > 0),
};
const searched = computed(() => {
  const q = query.value.trim();
  return q ? students.value.filter((s) => s.student_name.includes(q) || s.courses.some((c) => String(c.course_id) === q)) : students.value;
});
const counts = computed(() => Object.fromEntries(FILTERS.map((f) => [f.key, searched.value.filter(matches[f.key]).length])));
const visible = computed(() => searched.value.filter(matches[filter.value]));

function shift(step) {
  const d = new Date(year.value, month.value - 1 + step, 1);
  year.value = d.getFullYear();
  month.value = d.getMonth() + 1;
}
// The directors' old sheet column order (Founder 2026-11-17); the rows on screen (search and filter apply). UTF-8 BOM so Excel opens it.
const CSV_HEADERS = ['科目', '老師', '學校', '學生', '繳費日期', '付費方式', '單價', '已上堂數', '本月預估', '這個月收到', '未繳', '收款狀態', '這個月的課'];
const csvCell = (v) => (typeof v === 'number' ? v : `"${String(v ?? '').replace(/^([=+\-@])/, "'$1").replace(/"/g, '""')}"`); // no spreadsheet formulas from names
function exportCsv() {
  const rows = visible.value.flatMap((s) => s.courses.map((c) => [
    c.subject, c.teacher_name, s.school, s.student_name,
    c.paid_this_month.map((p) => p.date).join('、'), c.paid_this_month.map((p) => METHOD[p.method] || p.method).join('、'),
    rateLabel(c), c.held, c.money.month_total != null && !c.money.month_invoiced ? c.money.month_total : '',
    c.paid_this_month.reduce((n, p) => n + Number(p.amount || 0), 0), c.money.outstanding, state(c).label,
    c.lessons.map((l) => `${md(l.date)}${l.kind === 'held' ? '' : `（${LESSON[l.kind].label}）`}`).join('、'),
  ]));
  const csv = '\ufeff' + [CSV_HEADERS, ...rows].map((r) => r.map(csvCell).join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
  a.download = `月對帳表_${year.value}-${String(month.value).padStart(2, '0')}.csv`;
  a.click();
  URL.revokeObjectURL(a.href);
}
function open(s, c, kind) {
  emit('open', { student_id: s.student_id, first_class_id: c.course_id }, kind ? { kind, classId: c.course_id } : { classId: c.course_id });
}

async function load() {
  const mine = ++seq;
  if (campusId.value == null) { students.value = []; return; }
  loading.value = true;
  error.value = '';
  try {
    const token = await getAccessToken();
    const q = new URLSearchParams({ year: String(year.value), month: String(month.value), branch_id: String(campusId.value) });
    const res = await authedFetch(`/api/v1/accounting/monthly-roster?${q}`, { headers: { Accept: 'application/json' } }, token);
    const json = await res.json().catch(() => ({}));
    if (mine !== seq) return;
    if (!res.ok) throw new Error(json?.message || `載入失敗（${res.status}）`);
    students.value = json.data || [];
    summary.value = json.summary || summary.value;
  } catch (e) {
    if (mine === seq) { error.value = e.message || '載入失敗'; students.value = []; }
  } finally {
    if (mine === seq) loading.value = false;
  }
}
watch([campusId, year, month], load, { immediate: true });
defineExpose({ reload: load, loading });
</script>
