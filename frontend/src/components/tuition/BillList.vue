<template>
  <section :class="['bl', { 'has-checks': anyCheck }]" aria-label="帳單清單">
    <AtInlineAlert v-if="error && !rows.length" tone="danger" title="帳務資料載入失敗" data-testid="bill-error">
      {{ error }}。載入失敗，金額先不顯示。
      <template #action>
        <AtButton variant="secondary" size="sm" shape="rect" :loading="loading" data-testid="bill-retry" @click="$emit('retry')">重試</AtButton>
      </template>
    </AtInlineAlert>
    <div v-else-if="pending" class="bl__loading" data-testid="bill-loading" role="status" aria-label="載入帳務資料中">
      <AtSkeleton :rows="6" height="28px" />
    </div>
    <template v-else>
      <div class="bl__summary" role="group" aria-label="數字條" data-testid="bill-summary">
        <button type="button" :class="{ 'is-on': filter === 'owing' }" data-testid="bill-sum-owed" @click="setFilter('owing')">到今天未收 <strong>{{ money(totalOwed) }}</strong></button>
        <button type="button" :class="{ 'is-on': filter === 'overdue' }" data-testid="bill-sum-overdue" @click="setFilter('overdue')">逾期 <strong>{{ overdueCount }}</strong> 筆</button>
        <button type="button" :class="{ 'is-on': filter === 'confirm' }" data-testid="bill-sum-confirm" @click="setFilter('confirm')">待確認入帳 <strong>{{ counts.confirm }}</strong> 筆</button>
      </div>
      <div class="bl__tools">
        <input v-model="query" type="search" placeholder="搜尋學生" aria-label="搜尋學生" data-testid="bill-search" />
        <div class="bl__chips" role="group" aria-label="篩選" data-guide="tuition-chips">
          <button
            v-for="c in CHIPS"
            :key="c.key"
            type="button"
            :class="['bl__chip', { 'is-on': activeFilter === c.key }]"
            :aria-pressed="activeFilter === c.key"
            :data-testid="`bill-filter-${c.key}`"
            @click="setFilter(c.key)"
          >{{ c.label }} {{ counts[c.key] }}</button>
          <ActionMenu :groups="moreGroups" label="更多篩選" trigger-text="更多" trigger-class="bl__more" @select="setFilter" />
          <span v-if="MORE.some((m) => m.key === activeFilter)" class="bl__chip is-on" data-testid="bill-filter-current">{{ filterLabel }} {{ activeFilter === 'all' ? rows.length : counts[activeFilter] }}</span>
        </div>
      </div>
      <div v-if="anyCheck || rows.length" class="bl__bulk" role="toolbar" aria-label="清單操作" data-testid="bill-bulk">
        <label v-if="anyCheck" class="bl__bulk-all">
          <input type="checkbox" :checked="allPicked" :indeterminate.prop="picked.length > 0 && !allPicked" data-testid="bill-select-all" @change="pickAll($event.target.checked)" />
          全選
        </label>
        <template v-if="picked.length">
          <AtButton size="sm" shape="rect" data-testid="bill-batch" @click="$emit('batch', pickedMode, picked.map((b) => b.class_id))">{{ pickedMode === 'confirm' ? '確認入帳' : '登記' }} {{ picked.length }} 筆</AtButton>
          <AtButton variant="secondary" size="sm" shape="rect" data-testid="bill-clear-picked" @click="clearSelection">取消選取</AtButton>
        </template>
        <AtButton class="bl__csv" variant="secondary" size="sm" shape="rect" :disabled="exportDisabled || !visible.length" data-testid="bill-export" @click="$emit('export', visible.map((b) => b.src))">匯出 CSV</AtButton>
      </div>
      <AtEmpty v-if="!visible.length" icon="search_off" :title="query.trim() ? '找不到符合的帳單' : '目前沒有要處理的帳單'" :description="query.trim() ? `找不到符合「${query.trim()}」的學生。請調整搜尋。` : '可以切換上方的狀態看其他帳單。'" data-testid="bill-empty" data-guide="tuition-rows" />
      <template v-else>
        <div class="bl__head" aria-hidden="true">
          <div class="bl__cols"><span>學生・科目</span><span>期間／剩幾堂</span><span>狀態</span><span>到期／逾期</span><span class="bl__num">未收</span></div>
        </div>
        <ul class="bl__list" data-guide="tuition-rows">
          <li v-for="b in visible" :key="b.key" :data-testid="`bill-row-${b.key}`" :data-kind="b.kind">
            <input
              v-if="selectable(b)"
              type="checkbox"
              class="bl__check"
              :checked="selected.has(b.key)"
              :disabled="Boolean(pickedKind) && pickedKind !== b.kind"
              :title="pickedKind && pickedKind !== b.kind ? '一次只能選同一種' : undefined"
              :aria-label="`選取 ${b.student_name} ${b.subject}`"
              :data-testid="`bill-pick-${b.key}`"
              @change="pick(b.key, $event.target.checked)"
            />
            <div class="bl__row">
              <span class="bl__who">
                <button type="button" class="bl__name" :data-testid="`bill-name-${b.key}`" :data-student="b.student_id" @click="$emit('student', b)">{{ b.student_name }}</button>
                <span class="bl__subject">{{ b.subject }}</span>
              </span>
              <span class="bl__period">{{ b.period }}</span>
              <span class="bl__status"><AtBadge :tone="TONES[b.kind]" :label="b.label" :title="b.help || undefined" /></span>
              <span :class="['bl__due', { due: b.overdue_days > 0 }]">{{ dueText(b) }}</span>
              <span :class="['bl__num', { due: b.owed > 0 }]">{{ b.owed > 0 ? money(b.owed) : '—' }}</span>
              <span v-if="b.hint" class="bl__hint">{{ b.hint }}</span>
            </div>
            <div class="bl__actions">
              <AtButton
                v-if="PRIMARY[b.kind]"
                size="sm"
                shape="rect"
                :variant="b.kind === 'confirm' ? 'primary' : 'secondary'"
                :data-testid="`bill-action-${b.key}`"
                :aria-label="`${PRIMARY[b.kind]}：${b.student_name} ${b.subject}`"
                @click="$emit('action', b, b.kind)"
              >{{ PRIMARY[b.kind] }}</AtButton>
              <ActionMenu :groups="menuGroups(b)" :label="`更多操作：${b.student_name} ${b.subject}`" trigger-class="bl__menu" @select="(id) => $emit('menu', b, id)" />
            </div>
          </li>
        </ul>
      </template>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import ActionMenu from '../ActionMenu.vue';
import AtBadge from '../design-system/AtBadge.vue';
import AtButton from '../design-system/AtButton.vue';
import AtEmpty from '../design-system/AtEmpty.vue';
import AtInlineAlert from '../design-system/AtInlineAlert.vue';
import AtSkeleton from '../design-system/AtSkeleton.vue';
import { billCounts, billRows, TODO_KINDS } from '../../lib/billRows.js';
import { slipAvailability } from '../../lib/billingDocState.js';

const props = defineProps({
  alertRows: { type: Array, default: () => [] },
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
defineEmits(['student', 'action', 'menu', 'batch', 'export', 'retry']);

const CHIPS = [
  { key: 'todo', label: '要處理' }, { key: 'confirm', label: '待確認入帳' }, { key: 'owing', label: '未繳' },
  { key: 'issue', label: '要開帳單' }, { key: 'review', label: '需核對' },
];
const MORE = [{ key: 'paid', label: '已收' }, { key: 'waived', label: '不收了' }, { key: 'all', label: '全部' }];
const PRIMARY = { confirm: '確認入帳', owing: '登記收款', issue: '開帳單', review: '核對' };
const TONES = { confirm: 'warning', owing: 'danger', issue: 'neutral', review: 'info', paid: 'success', waived: 'neutral' };
const FILTERS = [...CHIPS, ...MORE, { key: 'overdue', label: '逾期' }].map((f) => f.key);
const moreGroups = [{ id: 'more', label: '', items: MORE.map((m) => ({ id: m.key, label: m.label })) }];

const rows = computed(() => billRows(props.alertRows, props.today));
const counts = computed(() => billCounts(rows.value));
const totalOwed = computed(() => rows.value.reduce((s, b) => s + b.owed, 0));
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
const visible = computed(() => rows.value.filter((b) => (TESTS[activeFilter.value] || ((x) => x.kind === activeFilter.value))(b)
  && (!query.value.trim() || b.student_name.includes(query.value.trim()))));

const money = (v) => 'NT$ ' + Number(v || 0).toLocaleString('zh-TW');
const md = (d) => { const [, m, day] = String(d).slice(0, 10).split('-'); return `${Number(m)}/${Number(day)}`; };
const dueText = (b) => (b.overdue_days > 0 ? `逾期 ${b.overdue_days} 天` : b.due_date && b.kind === 'owing' ? `${md(b.due_date)} 到期` : '—');

// Dangerous actions sit after the divider, only where the page allows them (same guards as the drawer's contract card).
function menuGroups(b) {
  const a = (b.class_id != null && props.rowActions[b.class_id]) || {};
  const slip = slipAvailability({ ...b.src, id: b.class_id }, props.canDocs);
  const canRefund = b.class_id != null && b.src.schedule_mode !== 'date' && Number(b.src.paid_amount) > 0 && !b.src.closed_reason && !b.src.stop;
  const safe = [
    ...(b.kind === 'confirm' ? [{ id: 'reject', label: '退回' }] : []),
    { id: 'slip', label: '傳繳費單', disabled: !slip.ok, reason: slip.ok ? '' : slip.reason },
    { id: 'student', label: '看學生全部帳務' },
    ...(b.class_id != null ? [{ id: 'sessions', label: '上課明細' }] : []),
  ];
  const danger = [
    ...(a.waive ? [{ id: 'waive', label: '改成不收', danger: true }] : []),
    ...(a.void ? [{ id: 'void', label: '撤銷收款', danger: true }] : []),
    ...(a.settle ? [{ id: 'settle', label: '結案', danger: true }] : []),
    ...(canRefund ? [{ id: 'refund', label: '退費', danger: true }] : []),
  ];
  return [{ id: 'safe', label: '', items: safe }, ...(danger.length ? [{ id: 'danger', label: '', items: danger }] : [])];
}

// Batch: only rows of the same kind can be picked together, and only contracts the page's batch preview accepts.
const selected = ref(new Set());
const selectable = (b) => (b.kind === 'confirm' || b.kind === 'owing') && b.class_id != null && Boolean(props.batchModes[b.class_id]);
const selectableRows = computed(() => visible.value.filter(selectable));
const anyCheck = computed(() => selectableRows.value.length > 0);
const picked = computed(() => selectableRows.value.filter((b) => selected.value.has(b.key)));
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
defineExpose({ neighbor, clearSelection });
</script>

<style scoped>
.bl{display:grid;gap:10px}
.bl__summary{display:flex;flex-wrap:wrap;gap:8px}
.bl__summary button{border:1px solid var(--ds-border);background:var(--ds-canvas);border-radius:var(--ds-radius-lg,8px);padding:8px 14px;font:inherit;font-size:14px;cursor:pointer;min-height:44px}
.bl__summary button.is-on{border-color:var(--ds-primary,var(--ds-ink-mute));background:var(--ds-primary-wash,var(--ds-canvas-soft))}
.bl__summary strong{font-size:18px;margin:0 2px}
.bl__tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.bl__tools input{flex:1 1 180px;min-height:36px;padding:6px 10px;font:inherit}
.bl__chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.bl__chip{border:1px solid var(--ds-border);background:var(--ds-canvas);border-radius:999px;padding:4px 12px;font-size:13px;cursor:pointer;min-height:32px}
.bl__chip.is-on{background:var(--ds-primary-wash,var(--ds-canvas-soft));border-color:var(--ds-primary,var(--ds-ink-mute));font-weight:700}
.bl__bulk{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.bl__bulk-all{display:flex;white-space:nowrap;align-items:center;gap:6px;font-size:var(--ds-font-size-sm);min-height:32px}
.bl__csv{margin-left:auto}
.bl__list{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.bl{--bl-check:0px}
.bl.has-checks{--bl-check:32px}
.bl__head,.bl__list>li{display:grid;grid-template-columns:var(--bl-check) minmax(0,1fr) 9.5em;align-items:center;column-gap:4px}
.bl__cols,.bl__row{grid-column:2;display:grid;grid-template-columns:minmax(9em,1.4fr) minmax(8em,1.2fr) 8em 7em 7em;gap:4px 12px;align-items:center}
.bl__cols{padding:0 13px;color:var(--ds-ink-mute)}
.bl__cols>span{font-size:12px}
.bl__row{border:1px solid var(--ds-border);border-radius:var(--ds-radius-lg,8px);background:var(--ds-surface,var(--ds-canvas));padding:8px 12px;min-height:52px}
.bl__check{grid-column:1;width:20px;height:20px;margin:0}
.bl__who{display:grid}
.bl__name{background:none;border:0;padding:0;font:inherit;font-weight:700;text-align:left;cursor:pointer;color:inherit;text-decoration:underline;text-decoration-color:var(--ds-border);min-height:28px}
.bl__subject,.bl__hint{font-size:12px;color:var(--ds-ink-mute)}
.bl__hint{grid-column:1/-1}
.bl__num{text-align:right;font-variant-numeric:tabular-nums}
.bl__due{font-size:13px;font-variant-numeric:tabular-nums}
.bl__num.due,.bl__due.due{color:var(--ds-danger);font-weight:700}
.bl__actions{grid-column:3;display:flex;gap:4px;align-items:center;justify-content:flex-end}
@media (max-width:760px){
  .bl.has-checks{--bl-check:36px}
  .bl__check{width:24px;height:24px}
  .bl__head{display:none}
  .bl__chips{flex-wrap:nowrap;overflow-x:auto;max-width:100%;padding-bottom:2px}
  .bl__chip{flex:0 0 auto;min-height:44px}
  .bl__tools input{min-height:44px}
  .bl__list>li{grid-template-columns:var(--bl-check) minmax(0,1fr)}
  .bl__name{min-height:44px}
  .bl :deep(.am__trigger){min-height:44px;min-width:44px}
  .bl__row{grid-template-columns:1fr auto;grid-template-areas:"who status" "hint hint";gap:2px 12px}
  .bl__period,.bl__due,.bl__num{display:none}
  .bl__actions{grid-column:2;justify-content:flex-start;padding:4px 0}
}
</style>
