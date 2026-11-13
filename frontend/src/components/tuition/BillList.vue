<template>
  <section class="bl tw:flex tw:flex-col tw:gap-4" aria-label="帳單清單">
    <Alert v-if="error && !rows.length" tone="danger" title="帳務資料載入失敗" class="tw:text-tpl-body" data-testid="bill-error">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-3">
        <span class="tw:min-w-0 tw:flex-1 tw:text-tpl-body">{{ error }}。載入失敗，金額先不顯示。</span>
        <Button variant="outline" :class="control.row" :disabled="loading" data-testid="bill-retry" @click="$emit('retry')">重試</Button>
      </div>
    </Alert>
    <SectionCard v-else-if="pending" data-testid="bill-loading" role="status" aria-label="載入帳務資料中">
      <div class="tw:flex tw:flex-col tw:gap-3 tw:p-5">
        <Skeleton v-for="n in 6" :key="n" class="tw:h-14 tw:w-full" />
      </div>
    </SectionCard>
    <template v-else>
      <Alert v-if="error" tone="warning" title="帳務資料更新失敗" class="tw:text-tpl-body" data-testid="bill-stale">
        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-3">
          <span class="tw:min-w-0 tw:flex-1 tw:text-tpl-body">{{ error }}。下面是上次載入的資料，可能不是最新的。</span>
          <Button variant="outline" :class="control.row" :disabled="loading" data-testid="bill-stale-retry" @click="$emit('retry')">重試</Button>
        </div>
      </Alert>

      <StatStrip data-testid="bill-summary">
        <StatItem label="到今天未收" :value="money(totalOwed)" :tone="totalOwed > 0 ? 'danger' : 'neutral'" class="tw:max-lg:col-span-2">
          <button type="button" :class="[sumButton, { 'is-on': filter === 'owing' }]" :aria-pressed="filter === 'owing'" data-testid="bill-sum-owed" @click="setFilter('owing')"><span class="tw:sr-only">到今天未收 {{ money(totalOwed) }}</span></button>
        </StatItem>
        <StatItem label="逾期" :value="overdueCount" unit="筆">
          <button type="button" :class="[sumButton, { 'is-on': filter === 'overdue' }]" :aria-pressed="filter === 'overdue'" data-testid="bill-sum-overdue" @click="setFilter('overdue')"><span class="tw:sr-only">逾期 {{ overdueCount }} 筆</span></button>
        </StatItem>
        <StatItem label="待確認入帳" :value="counts.confirm" unit="筆">
          <button type="button" :class="[sumButton, { 'is-on': filter === 'confirm' }]" :aria-pressed="filter === 'confirm'" data-testid="bill-sum-confirm" @click="setFilter('confirm')"><span class="tw:sr-only">待確認入帳 {{ counts.confirm }} 筆</span></button>
        </StatItem>
      </StatStrip>

      <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
        <div class="tw:relative tw:w-full tw:lg:w-72">
          <Search class="tw:pointer-events-none tw:absolute tw:top-1/2 tw:left-4 tw:size-5 tw:-translate-y-1/2 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
          <Input v-model="query" type="search" placeholder="搜尋學生" aria-label="搜尋學生" class="tw:h-12 tw:rounded-full tw:bg-card tw:pl-11 tw:text-[length:var(--tpl-text-body)] tw:shadow-none" data-testid="bill-search" />
        </div>
        <div role="group" aria-label="篩選" class="tw:-mx-4 tw:flex tw:max-w-[100vw] tw:min-w-0 tw:flex-1 tw:items-center tw:gap-2 tw:px-4 tw:max-lg:basis-full tw:max-md:overflow-x-auto tw:md:mx-0 tw:md:flex-wrap tw:md:px-0" data-guide="tuition-chips">
          <FilterChip
            v-for="c in CHIPS"
            :key="c.key"
            :class="['bl__chip', { 'is-on': activeFilter === c.key }]"
            :active="activeFilter === c.key"
            :count="counts[c.key]"
            :data-testid="`bill-filter-${c.key}`"
            @click="setFilter(c.key)"
          >{{ c.label }}</FilterChip>
          <ActionMenu :groups="moreGroups" label="更多篩選" trigger-text="更多" :trigger-class="moreTrigger" @select="setFilter" />
          <FilterChip
            v-if="MORE.some((m) => m.key === activeFilter)"
            class="bl__chip is-on"
            active
            :count="activeFilter === 'all' ? rows.length : counts[activeFilter]"
            data-testid="bill-filter-current"
          >{{ filterLabel }}</FilterChip>
        </div>
        <label class="tw:inline-flex tw:items-center tw:gap-1 tw:text-tpl-caption tw:whitespace-nowrap tw:text-tpl-secondary tw:ml-auto">排序
          <select v-model="sortKey" class="tw:h-11 tw:cursor-pointer tw:rounded-full tw:border-0 tw:bg-transparent tw:py-0 tw:pr-2 tw:pl-1 tw:text-[length:var(--tpl-text-caption)] tw:font-semibold tw:text-foreground tw:outline-none tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50" data-testid="bill-sort">
            <option v-for="(o, key) in SORTS" :key="key" :value="key">{{ o.label }}</option>
          </select>
        </label>
      </div>

      <SectionCard>
        <SelectionBar v-if="picked.length" data-testid="bill-bulk">
          <span data-testid="bill-picked-total">已選 {{ picked.length }} 筆<template v-if="pickedOwed > 0"><span class="tw:font-normal tw:text-tpl-secondary">，到今天未收合計 <strong class="tw:font-semibold tw:whitespace-nowrap tw:text-foreground tw:tabular-nums">{{ money(pickedOwed) }}</strong></span></template></span>
          <template #actions>
            <Button variant="ghost" :class="control.row" data-testid="bill-clear-picked" @click="clearSelection">取消選取</Button>
            <Button :class="control.row" data-testid="bill-batch" @click="$emit('batch', pickedMode, picked.map((b) => b.class_id))">{{ pickedMode === 'confirm' ? '確認入帳' : '登記' }} {{ picked.length }} 筆</Button>
          </template>
        </SelectionBar>

        <div v-if="!visible.length && !noRowStudents.length" class="tw:flex tw:flex-col tw:items-center tw:gap-2 tw:px-5 tw:py-12 tw:text-center" data-testid="bill-empty" data-guide="tuition-rows">
          <SearchX class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
          <p class="tw:m-0 tw:text-tpl-body tw:font-semibold tw:text-pretty">{{ query.trim() ? '找不到符合的帳單' : `目前沒有「${chipLabel}」的帳單` }}</p>
          <p class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary tw:text-pretty">{{ query.trim() ? `找不到符合「${query.trim()}」的學生。請調整搜尋。` : '可以切換上方的狀態看其他帳單。' }}</p>
        </div>
        <Table v-else :class="t.table">
          <TableCaption class="tw:sr-only">帳單清單</TableCaption>
          <TableHeader v-if="visible.length">
            <TableRow class="bl__cols tw:hover:bg-transparent">
              <TableHead v-if="anyCheck" scope="col" :class="[t.head, checkCol]">
                <Checkbox
                  :model-value="allPicked ? true : picked.length > 0 ? 'indeterminate' : false"
                  :class="checkBox"
                  aria-label="全選"
                  data-testid="bill-select-all"
                  @update:model-value="(v) => pickAll(v === true)"
                />
              </TableHead>
              <TableHead scope="col" :class="[t.head, !anyCheck && 'tw:pl-4 tw:md:pl-5']">學生・科目</TableHead>
              <TableHead scope="col" :class="[t.head, 'tw:max-lg:hidden bl__period']">期間／剩幾堂</TableHead>
              <TableHead scope="col" :class="[t.head, t.phoneHide]">狀態</TableHead>
              <TableHead scope="col" :class="[t.head, 'tw:max-lg:hidden']">到期／逾期</TableHead>
              <TableHead scope="col" :class="[t.numHead, 'bl__num']">未收</TableHead>
              <TableHead scope="col" :class="[t.head, 'tw:w-12 tw:pr-4 tw:pl-0 tw:md:w-auto tw:md:pr-5']"><span class="tw:sr-only">操作</span></TableHead>
            </TableRow>
          </TableHeader>
          <TableBody v-if="noRowStudents.length" data-testid="bill-no-rows">
            <TableRow v-for="st in noRowStudents" :key="st.id" class="bl__plainrow tw:hover:bg-muted/60">
              <TableCell v-if="anyCheck" :class="[t.cell, checkCol]" />
              <TableCell :class="[t.name, !anyCheck && 'tw:pl-4 tw:md:pl-5']">
                <button type="button" :class="nameButton" :title="st.name" :data-testid="`bill-student-${st.id}`" @click="$emit('student', { student_id: st.id, class_id: null })">{{ st.name }}</button>
              </TableCell>
              <TableCell :class="[t.cell, 'tw:text-tpl-secondary']" colspan="5">沒有待收款項</TableCell>
            </TableRow>
          </TableBody>
          <TableBody v-if="visible.length" data-guide="tuition-rows">
            <TableRow
              v-for="b in visible"
              :key="b.key"
              :data-testid="`bill-row-${b.key}`"
              :data-kind="b.kind"
              :data-state="selected.has(b.key) ? 'selected' : undefined"
              :class="['bl__row tw:cursor-pointer', selected.has(b.key) ? 'tw:bg-[color-mix(in_srgb,var(--ds-primary-wash)_55%,transparent)] tw:hover:bg-[var(--ds-primary-wash)]' : 'tw:hover:bg-muted/60']"
              @click="$emit('quick', b)"
            >
              <TableCell v-if="anyCheck" :class="[t.cell, checkCol]" @click.stop>
                <Checkbox
                  v-if="selectable(b)"
                  :model-value="selected.has(b.key)"
                  :disabled="Boolean(pickedKind) && pickedKind !== b.kind"
                  :title="pickedKind && pickedKind !== b.kind ? '一次只能選同一種' : undefined"
                  :class="['bl__check', checkBox]"
                  :aria-label="`選取 ${b.student_name} ${b.subject}`"
                  :data-testid="`bill-pick-${b.key}`"
                  @update:model-value="(v) => pick(b.key, v === true)"
                />
                <span v-else class="bl__nocheck tw:relative tw:inline-block tw:w-5 tw:cursor-help tw:text-center tw:leading-none tw:text-tpl-secondary" :title="noPickReason(b)" :data-testid="`bill-nopick-${b.key}`"><span aria-hidden="true">–</span><span class="tw:sr-only">{{ noPickReason(b) }}</span></span>
              </TableCell>
              <TableCell :class="[t.name, 'tw:h-16', !anyCheck && 'tw:pl-4 tw:md:pl-5']">
                <button type="button" :class="nameButton" :data-testid="`bill-name-${b.key}`" :data-student="b.student_id" @click.stop="$emit('student', b)">{{ b.student_name }}</button>
                <span :class="t.sub">
                  <button type="button" :class="openButton" :data-testid="`bill-open-${b.key}`" :aria-label="`看這筆帳單：${b.student_name} ${b.subject}`" @click.stop="$emit('quick', b)">{{ b.subject || '帳單' }}</button><span :class="['tw:lg:hidden', 'bl__period-sub tw:inline-block tw:whitespace-nowrap']">・{{ periodText(b.period) }}</span>
                </span>
                <span v-if="b.hint" :class="[t.sub, 'bl__hint']">{{ b.hint }}</span>
                <span v-for="w in b.warnings" :key="w" :class="[t.sub, 'bl__warn tw:max-w-sm tw:text-[var(--ds-warning-text)]']" role="note" :data-testid="`bill-warn-${b.key}`">{{ w }}</span>
              </TableCell>
              <TableCell :class="[t.cell, 'bl__period tw:max-lg:hidden tw:whitespace-nowrap tw:tabular-nums']">{{ periodText(b.period) }}</TableCell>
              <TableCell :class="[t.cell, t.phoneHide]"><StatusBadge :tone="TONES[b.kind]" :title="b.help || undefined">{{ b.label }}</StatusBadge></TableCell>
              <TableCell :class="[t.cell, 'bl__due tw:max-lg:hidden tw:whitespace-nowrap tw:tabular-nums', b.overdue_days > 0 ? 'due tw:font-semibold tw:text-[var(--ds-danger-text)]' : 'tw:text-tpl-secondary']">{{ dueText(b) }}</TableCell>
              <TableCell :class="[t.num, 'bl__num tw:font-semibold tw:max-md:pl-0', { due: b.owed > 0 }]">
                {{ b.owed > 0 ? money(b.owed) : '—' }}
                <span v-if="b.owed_later > 0 && !(b.review_amount > 0)" :class="[t.sub, 'bl__review']" :data-testid="`bill-later-${b.key}`">之後還會到期 {{ money(b.owed_later) }}</span>
                <span v-if="b.review_amount > 0" :class="[t.sub, 'bl__review']" :data-testid="`bill-review-${b.key}`">需核對金額 {{ money(b.review_amount) }}</span>
                <span :class="[t.sub, 'tw:lg:hidden bl__due tw:font-normal', b.overdue_days > 0 ? 'tw:text-[var(--ds-danger-text)]' : '']">{{ dueText(b) !== '—' ? dueText(b) : b.label }}</span>
              </TableCell>
              <TableCell :class="[t.cell, 'tw:pr-4 tw:pl-0 tw:md:pr-5']">
                <ChevronRight :class="['tw:ml-auto tw:size-5 tw:text-tpl-secondary', t.phoneOnly]" :stroke-width="2" aria-hidden="true" />
                <span :class="['bl__actions tw:flex tw:items-center tw:justify-end tw:gap-1', t.phoneHide]" @click.stop>
                  <Button
                    v-if="primaryOf(b)"
                    :variant="b.kind === 'confirm' ? 'default' : 'outline'"
                    :class="[control.row, 'tw:min-w-24']"
                    :data-testid="`bill-action-${b.key}`"
                    :aria-label="`${primaryOf(b).label}：${b.student_name} ${b.subject}`"
                    @click="$emit('action', b, primaryOf(b).action)"
                  >{{ primaryOf(b).label }}</Button>
                  <ActionMenu :groups="menuGroups(b)" :label="`更多操作：${b.student_name} ${b.subject}`" :trigger-class="rowMenu" @select="(id) => $emit('menu', b, id)" />
                </span>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
        <template v-if="anyCheck || rows.length" #footer>
          <span v-if="visible.length" data-testid="bill-visible-total">目前顯示 {{ visible.length }} 筆<template v-if="visibleOwed > 0">，到今天未收合計 <strong class="tw:text-foreground tw:tabular-nums">{{ money(visibleOwed) }}</strong></template></span>
          <Button variant="outline" :class="[control.row, 'tw:ml-auto']" :disabled="exportDisabled || !visible.length" data-testid="bill-export" @click="$emit('export', exportPayload())">匯出 Excel</Button>
        </template>
      </SectionCard>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { ChevronRight, Search, SearchX } from '@lucide/vue';
import ActionMenu from '../ActionMenu.vue';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FilterChip, SectionCard, SelectionBar, StatItem, StatStrip, StatusBadge, control, dataTable as t } from '@/components/ui/page';
import { billCounts, billDueText as dueText, billMenuGroups, billRows, TODO_KINDS } from '../../lib/billRows.js';
import { slipAvailability } from '../../lib/billingDocState.js';

const props = defineProps({
  alertRows: { type: Array, default: () => [] },
  // Campus students, so a name search still finds a fully paid student (one line 「沒有待收款項」).
  students: { type: Array, default: () => [] },
  today: { type: String, default: undefined },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  // Deep links (notifications, 首頁) pick a chip; { key } so the same link re-applies after the director changed it.
  presetFilter: { type: Object, default: () => ({ key: 'todo' }) },
  // { [contractId]: 'confirm' | 'report' } = contracts the page's batch preview accepts.
  batchModes: { type: Object, default: () => ({}) },
  // { [contractId]: { waive, void, settle } } = the dangerous actions the page allows for that contract.
  rowActions: { type: Object, default: () => ({}) },
  canDocs: { type: Boolean, default: false },
  exportDisabled: { type: Boolean, default: false },
});
defineEmits(['student', 'quick', 'action', 'menu', 'batch', 'export', 'retry']);

// Class sets for the pieces the shared page parts do not cover (tokens and tw: utilities only).
const checkCol = 'tw:w-12 tw:pr-0 tw:pl-4 tw:md:pl-5';
// 20px box; the pseudo-element grows the hit area to 48px without moving the row.
const checkBox = "tw:relative tw:size-5 tw:after:absolute tw:after:-inset-3.5 tw:after:content-['']";
const nameButton = 'tw:block tw:max-w-full tw:min-h-0 tw:cursor-pointer tw:rounded-sm tw:border-0 tw:bg-transparent tw:p-0 tw:text-left tw:text-[length:inherit] tw:font-semibold tw:text-foreground tw:whitespace-nowrap tw:outline-none tw:hover:underline tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50 tw:max-md:pointer-events-none';
const openButton = 'tw:min-h-0 tw:cursor-pointer tw:rounded-sm tw:border-0 tw:bg-transparent tw:p-0 tw:text-left tw:text-[length:inherit] tw:font-normal tw:text-tpl-secondary tw:outline-none tw:hover:underline tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50 tw:max-md:pointer-events-none';
const moreTrigger = 'tw:inline-flex tw:h-11 tw:shrink-0 tw:items-center tw:rounded-full tw:border-0 tw:bg-card tw:px-4 tw:text-[length:var(--tpl-text-caption)] tw:font-semibold tw:text-tpl-secondary tw:shadow-[inset_0_0_0_1px_var(--ds-hairline)] tw:hover:bg-card tw:hover:text-foreground';
const rowMenu = 'bl__menu tw:size-11 tw:rounded-full tw:border-0 tw:bg-transparent tw:p-0 tw:text-[length:var(--tpl-text-body)] tw:text-tpl-secondary tw:hover:bg-muted';
// The strip figure is also the filter button: it covers the whole figure, the pressed one gets the wash.
const sumButton = 'tw:absolute tw:inset-0 tw:min-h-0 tw:cursor-pointer tw:border-0 tw:bg-transparent tw:outline-none tw:transition-colors tw:hover:bg-muted/40 tw:focus-visible:ring-3 tw:focus-visible:ring-inset tw:focus-visible:ring-ring/50 tw:aria-pressed:bg-[color-mix(in_srgb,var(--ds-primary-wash)_60%,transparent)]';

const CHIPS = [
  { key: 'todo', label: '要處理' }, { key: 'confirm', label: '待確認入帳' }, { key: 'owing', label: '未繳' },
  { key: 'issue', label: '要開帳單' }, { key: 'review', label: '需核對' },
];
const MORE = [{ key: 'paid', label: '已收' }, { key: 'waived', label: '不收了' }, { key: 'all', label: '全部' }];
const PRIMARY = { confirm: ['確認入帳', 'confirm'], owing: ['登記收款', 'record'], review: ['核對', 'open'] };
// Every primary opens something that works today: 開帳單 only for monthly bills (drafts); a count-mode contract gets its 繳費單
// when one can be issued, else its billing in the drawer (a paid renewal has no bill to issue).
function primaryOf(b) {
  if (PRIMARY[b.kind]) return { label: PRIMARY[b.kind][0], action: PRIMARY[b.kind][1] };
  if (b.kind !== 'issue') return null;
  // #4919: a package row has no contract to issue against (class_id null), so it only opens the billing.
  if (b.class_id == null) return { label: '看帳務', action: 'open' };
  if (b.src.schedule_mode === 'date') return { label: '開帳單', action: 'issue' };
  return slipOf(b).ok ? { label: '繳費單', action: 'slip' } : { label: '看帳務', action: 'open' };
}
const slipOf = (b) => slipAvailability({ ...b.src, id: b.class_id }, props.canDocs);
const SORTS = {
  // D8: owed now first, then what falls due later, then name (the explicit order the label promises).
  amount: { label: '未繳金額', cmp: (a, b) => b.owed - a.owed || b.owed_later - a.owed_later || a.student_name.localeCompare(b.student_name, 'zh-Hant') },
  overdue: { label: '逾期天數', cmp: (a, b) => b.overdue_days - a.overdue_days || b.owed - a.owed },
  // in-app #467 / #4824: fewest lessons left first; contracts without a count go last.
  remaining: { label: '剩餘堂數（少到多）', cmp: (a, b) => (a.sort_remaining ?? Infinity) - (b.sort_remaining ?? Infinity) || b.owed - a.owed },
  name: { label: '姓名', cmp: (a, b) => a.student_name.localeCompare(b.student_name, 'zh-Hant') },
};
// Badge tone for a row kind (PAGE_TEMPLATE §6: 待確認／需核對 = warning, 未收 = danger, 已收 = success, the rest neutral).
const TONES = { confirm: 'warning', owing: 'danger', issue: 'neutral', review: 'warning', paid: 'success', waived: 'neutral' };
const FILTERS = [...CHIPS, ...MORE, { key: 'overdue', label: '逾期' }].map((f) => f.key);
const moreGroups = [{ id: 'more', label: '', items: MORE.map((m) => ({ id: m.key, label: m.label })) }];

const rows = computed(() => billRows(props.alertRows, props.today));
const counts = computed(() => billCounts(rows.value));
const totalOwed = computed(() => sumOwed(rows.value));
const overdueCount = computed(() => rows.value.filter((b) => b.overdue_days > 0).length);
const pending = computed(() => props.loading && !props.alertRows.length && !props.error);

const query = ref('');
const filter = ref(FILTERS.includes(props.presetFilter?.key) ? props.presetFilter.key : 'todo');
watch(() => props.presetFilter, (p) => { if (FILTERS.includes(p?.key)) filter.value = p.key; });
const setFilter = (key) => { filter.value = key; };
// A name search looks through every status, so a paid student is still findable.
const activeFilter = computed(() => (query.value.trim() ? 'all' : filter.value));
const filterLabel = computed(() => MORE.find((m) => m.key === activeFilter.value)?.label || '');
const TESTS = {
  todo: (b) => TODO_KINDS.includes(b.kind),
  overdue: (b) => b.overdue_days > 0,
  all: () => true,
};
// in-app #494 / #4884: 要開帳單 lists fewest lessons left first (the sort stays changeable).
const sortKey = ref(filter.value === 'issue' ? 'remaining' : 'amount');
watch(filter, (f) => { sortKey.value = f === 'issue' ? 'remaining' : 'amount'; });
watch([activeFilter, query], () => clearSelection());
const visible = computed(() => {
  const list = rows.value.filter((b) => (TESTS[activeFilter.value] || ((x) => x.kind === activeFilter.value))(b)
    && (!query.value.trim() || b.student_name.includes(query.value.trim())));
  const cmp = SORTS[sortKey.value].cmp;
  return cmp ? [...list].sort(cmp) : list;
});
const chipLabel = computed(() => [...CHIPS, ...MORE, { key: 'overdue', label: '逾期' }].find((c) => c.key === activeFilter.value)?.label || '');
const noRowStudents = computed(() => {
  const q = query.value.trim();
  if (!q) return [];
  const have = new Set(rows.value.map((b) => b.student_id));
  return props.students.filter((st) => !have.has(Number(st.id)) && String(st.name || '').includes(q));
});

// Display only: a course period inside one year reads 11/01–11/30 (the year stays when the range crosses years).
const periodText = (p) => {
  const m = /^(\d{4})-(\d{2})-(\d{2}) ~ (\d{4})-(\d{2})-(\d{2})$/.exec(String(p || ''));
  return m && m[1] === m[4] ? `${m[2]}/${m[3]}–${m[5]}/${m[6]}` : p;
};
const money = (v) => 'NT$ ' + Number(v || 0).toLocaleString('zh-TW');
// in-app #520 / #5099: the same number each row's 未收 cell shows (b.owed), summed over the rows on screen and over the ticked rows.
// No amount when it is 0 (those rows show 「—」), so a 待確認入帳 view never reads as 「NT$ 0」.
const sumOwed = (list) => list.reduce((s, b) => s + b.owed, 0);
const visibleOwed = computed(() => sumOwed(visible.value));

// Exactly what is on screen (filter, search, tab): the ids only; the server re-reads the rows and writes every cell.
const exportPayload = () => ({
  filter: activeFilter.value,
  search: query.value.trim(),
  items: visible.value.map((b) => ({ id: b.class_id, package_id: b.package_id })),
});

const menuGroups = (b) => billMenuGroups(b, props);

// Batch: only rows of the same kind can be picked together, and only contracts the page's batch preview accepts.
const selected = ref(new Set());
const selectable = (b) => (b.kind === 'confirm' || b.kind === 'owing') && b.class_id != null && Boolean(props.batchModes[b.class_id]);
// Why a row has no checkbox while others do (the column stays reserved so every row lines up).
const noPickReason = (b) => (b.kind === 'confirm' || b.kind === 'owing'
  ? '這一筆目前不能批次處理（缺少帳單或金額）'
  : '這個狀態不能批次處理，請用右邊的按鈕');
const selectableRows = computed(() => visible.value.filter(selectable));
const anyCheck = computed(() => selectableRows.value.length > 0);
// Derived from every selected row (not just the visible ones), and cleared on any view change, so the bar never mixes kinds.
const picked = computed(() => rows.value.filter((b) => selected.value.has(b.key) && selectable(b)));
const pickedOwed = computed(() => sumOwed(picked.value));
const pickedKind = computed(() => picked.value[0]?.kind || '');
const pickedMode = computed(() => (pickedKind.value === 'confirm' ? 'confirm' : 'report'));
const allKind = computed(() => pickedKind.value || selectableRows.value[0]?.kind);
const allPicked = computed(() => { const same = selectableRows.value.filter((b) => b.kind === allKind.value); return same.length > 0 && picked.value.length === same.length; });
function pick(key, on) {
  const next = new Set(selected.value);
  if (on) next.add(key); else next.delete(key);
  selected.value = next;
}
const pickAll = (on) => { selected.value = on ? new Set(selectableRows.value.filter((b) => b.kind === allKind.value).map((b) => b.key)) : new Set(); };
const clearSelection = () => { selected.value = new Set(); };
// Adjacent student in the list as filtered (drawer ↑/↓): the first visible contract of the next/previous student.
function neighbor(studentId, dir) {
  const ids = [...new Set(visible.value.map((b) => b.student_id))];
  const i = ids.indexOf(Number(studentId));
  const b = i >= 0 && ids[i + dir] != null ? visible.value.find((x) => x.student_id === ids[i + dir]) : null;
  return b ? { student_id: b.student_id, first_class_id: b.class_id } : null;
}
// Contract ids of the owing rows in the order and filter on screen (for 儲存並看下一筆未繳).
const owingQueue = () => visible.value.filter((b) => b.kind === 'owing' && b.class_id != null).map((b) => Number(b.class_id));
defineExpose({ neighbor, clearSelection, owingQueue });
</script>
