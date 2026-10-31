<template>
  <section class="sbl" aria-label="學生帳務清單">
    <AtInlineAlert v-if="error" tone="danger" title="帳務資料載入失敗" data-testid="sbl-error">
      {{ error }}。載入失敗，金額先不顯示。
      <template #action>
        <AtButton variant="secondary" size="sm" shape="rect" :loading="loading" data-testid="sbl-retry" @click="$emit('retry')">重試</AtButton>
      </template>
    </AtInlineAlert>
    <div v-else-if="pending" class="sbl__loading" data-testid="sbl-loading" role="status" aria-label="載入帳務資料中">
      <AtSkeleton :rows="6" height="28px" />
    </div>
    <template v-if="!pending && !(error && !alertRows.length)">
    <div class="sbl__summary" data-testid="sbl-summary">
      <span>本校區到今天未繳 <strong>{{ money(totalNow) }}</strong></span>
      <span>未繳 <strong>{{ owingCount }}</strong> 位</span>
      <span v-if="pendingReviewCount" data-testid="sbl-pending-review">需核對金額 <strong>{{ money(pendingReviewTotal) }}</strong>（{{ pendingReviewCount }} 筆，不在未繳內）</span>
    </div>
    <p v-if="rows.length && !owingCount" class="sbl__clear" data-testid="sbl-clear">本校區沒有到期未繳。</p>
    <div class="sbl__tools">
      <input v-model="query" type="search" placeholder="搜尋學生" aria-label="搜尋學生" data-testid="sbl-search" />
      <div class="sbl__chips" role="group" aria-label="篩選">
        <button
          v-for="(f, key) in STUDENT_FILTERS"
          :key="key"
          type="button"
          :class="['sbl__chip', { 'is-on': filter === key }]"
          :aria-pressed="filter === key"
          :data-testid="`sbl-filter-${key}`"
          @click="filter = key"
        >{{ f.label }}</button>
      </div>
      <label class="sbl__sort">排序
        <select v-model="sortKey" data-testid="sbl-sort">
          <option v-for="(o, key) in SORTS" :key="key" :value="key">{{ o.label }}</option>
        </select>
      </label>
    </div>
    <AtEmpty v-if="!visible.length" icon="search_off" title="找不到符合的學生" :description="query.trim() ? `找不到符合「${query.trim()}」的學生。請調整搜尋或篩選。` : '請調整篩選。'" data-testid="sbl-empty" />
    <template v-else>
      <div class="sbl__head" aria-hidden="true">
        <span>學生</span><span class="sbl__col-now">到今天未繳</span><span>逾期</span><span>狀態</span><span>最近繳費</span>
      </div>
      <ul class="sbl__list">
        <li v-for="r in visible" :key="r.student_id" :class="{ 'has-action': rowPrimaryAction(r) }">
          <button type="button" class="sbl__row" :data-testid="`sbl-row-${r.student_id}`" @click="$emit('open', r)">
            <span class="sbl__meta">{{ metaLine(r) }}</span>
            <span class="sbl__name">{{ r.student_name }}</span>
            <span class="sbl__now" :class="{ due: r.owed_now > 0 }">
              <strong>{{ r.owed_now > 0 ? money(r.owed_now) : '—' }}</strong>
              <small v-if="r.owed_later > 0">之後還會到期 {{ money(r.owed_later) }}</small>
            </span>
            <span class="sbl__overdue" :class="{ due: r.overdue_days > 0 }">{{ r.overdue_days > 0 ? `逾期 ${r.overdue_days} 天` : '—' }}</span>
            <span class="sbl__status">
              <AtBadge v-for="b in badges(r)" :key="b.label" :tone="b.tone" :label="b.label" />
              <span v-if="r.open_contracts > 1" class="sbl__muted">{{ r.open_contracts }} 份合約</span>
            </span>
            <span class="sbl__last">{{ r.last_paid_at ? formatDate(r.last_paid_at) : '—' }}</span>
          </button>
          <AtButton
            v-if="rowPrimaryAction(r)"
            class="sbl__action"
            size="sm"
            shape="rect"
            :variant="rowPrimaryAction(r).kind === 'confirm' ? 'primary' : 'secondary'"
            :data-testid="`sbl-action-${r.student_id}`"
            :aria-label="`${rowPrimaryAction(r).label}：${r.student_name}`"
            @click="$emit('action', r, rowPrimaryAction(r))"
          >{{ rowPrimaryAction(r).label }}</AtButton>
          <template v-if="r.contracts.length">
            <button
              type="button"
              class="sbl__toggle"
              :aria-expanded="isOpen(r.student_id)"
              :aria-controls="`sbl-contracts-${r.student_id}`"
              :data-testid="`sbl-expand-${r.student_id}`"
              @click="toggle(r.student_id)"
            >{{ isOpen(r.student_id) ? '收合合約' : `展開 ${r.contracts.length} 份合約` }}</button>
            <ul v-if="isOpen(r.student_id)" :id="`sbl-contracts-${r.student_id}`" class="sbl__contracts" :data-testid="`sbl-contracts-${r.student_id}`">
              <li v-for="(c, i) in r.contracts" :key="`${c.id}-${i}`" class="sbl__contract">
                <strong>{{ c.ref }}</strong>
                <span>{{ c.period }}</span>
                <span>未繳 {{ money(c.owed) }}</span>
                <span>已繳 {{ money(c.paid) }}</span>
                <span v-if="c.pending_review > 0">需核對金額 {{ money(c.pending_review) }}</span>
              </li>
            </ul>
          </template>
        </li>
      </ul>
    </template>
    </template>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import AtBadge from '../design-system/AtBadge.vue';
import AtButton from '../design-system/AtButton.vue';
import AtEmpty from '../design-system/AtEmpty.vue';
import AtInlineAlert from '../design-system/AtInlineAlert.vue';
import AtSkeleton from '../design-system/AtSkeleton.vue';
import { formatDate } from '../../lib/billingDocumentView.js';
import { LEGACY_FLAG_UNVERIFIED_LABEL } from '../../lib/courseMoneyState.js';
import { buildStudentBillingRows, rowPrimaryAction, STUDENT_FILTERS } from '../../lib/studentBillingRows.js';

const props = defineProps({
  alertRows: { type: Array, default: () => [] },
  students: { type: Array, default: () => [] },
  today: { type: String, default: undefined },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
defineEmits(['open', 'action', 'retry']);
// No alert data yet: zeros would read as "all paid".
const pending = computed(() => props.loading && !props.alertRows.length && !props.error);

const expanded = ref(new Set());
const isOpen = (id) => expanded.value.has(id);
const toggle = (id) => {
  const next = new Set(expanded.value);
  if (!next.delete(id)) next.add(id);
  expanded.value = next;
};
const query = ref('');
const filter = ref('all');
const rows = computed(() => buildStudentBillingRows(props.alertRows, props.students, props.today));
const totalNow = computed(() => rows.value.reduce((s, r) => s + r.owed_now, 0));
const owingCount = computed(() => rows.value.filter((r) => r.owed_now > 0).length);
// Founder D2: pending-confirmation amounts are a separate line, never part of 未收.
const pendingReviewTotal = computed(() => rows.value.reduce((s, r) => s + r.pending_review_amount, 0));
const pendingReviewCount = computed(() => rows.value.reduce((s, r) => s + r.pending_review_count, 0));
// Client-side only: the rows are already loaded (server-side sort stays a Founder-gated item, #309).
const SORTS = {
  amount: { label: '未繳金額', cmp: null },
  overdue: { label: '逾期天數', cmp: (a, b) => b.overdue_days - a.overdue_days || b.owed_now - a.owed_now },
  name: { label: '姓名', cmp: (a, b) => a.student_name.localeCompare(b.student_name, 'zh-Hant') },
};
const sortKey = ref('amount');
const visible = computed(() => {
  const list = rows.value.filter((r) => STUDENT_FILTERS[filter.value].test(r)
    && (!query.value.trim() || r.student_name.includes(query.value.trim())));
  const cmp = SORTS[sortKey.value].cmp;
  return cmp ? [...list].sort(cmp) : list;
});
// Status lozenges use the one §0.2 wording; text is always shown, never colour alone.
const badges = (r) => {
  const out = [];
  if (r.waiting_confirm > 0) out.push({ tone: 'warning', label: '待確認入帳' });
  if (r.pending_review_count > 0) out.push({ tone: 'warning', label: '需核對金額' });
  if (r.owed_now > 0) out.push({ tone: 'danger', label: '未繳' });
  else if (r.owed_later > 0 && !out.length) out.push({ tone: 'neutral', label: '還沒到期' });
  else if (!out.length && r.unbilled > 0) out.push({ tone: 'neutral', label: '還沒開帳單' });
  // #4114 (Founder Q3): a contract paid by the legacy flag alone reads 需核對, never 已收 (not owed either).
  else if (!out.length && r.legacy_flag_only > 0) out.push({ tone: 'warning', label: LEGACY_FLAG_UNVERIFIED_LABEL });
  // in-app #407: 已收 only when the server says a contract is paid (never a payment date alone).
  else if (!out.length) out.push(r.has_paid ? { tone: 'success', label: '已收' } : { tone: 'neutral', label: '沒有待收款項' });
  return out;
};
const metaLine = (r) => [r.overdue_days > 0 ? `逾期 ${r.overdue_days} 天` : '', r.last_paid_at ? `最近繳費 ${formatDate(r.last_paid_at)}` : ''].filter(Boolean).join('・');
// Adjacent student in the list as currently filtered / sorted (drawer ↑/↓).
function neighbor(studentId, dir) {
  const i = visible.value.findIndex((r) => r.student_id === Number(studentId));
  return i < 0 ? null : (visible.value[i + dir] || null);
}
defineExpose({ neighbor });
const money = (v) => 'NT$ ' + Number(v || 0).toLocaleString('zh-TW');
</script>

<style scoped>
.sbl{display:grid;gap:10px}
.sbl__summary{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:14px}
.sbl__summary strong{font-size:18px}
.sbl__tools{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.sbl__tools input{flex:1 1 180px;min-height:36px;padding:6px 10px;font:inherit}
.sbl__chips{display:flex;flex-wrap:wrap;gap:6px}
.sbl__chip{border:1px solid var(--ds-border);background:var(--ds-canvas);border-radius:999px;padding:4px 12px;font-size:13px;cursor:pointer;min-height:32px}
.sbl__chip.is-on{background:var(--ds-primary-wash,var(--ds-canvas-soft));border-color:var(--ds-primary,var(--ds-ink-mute));font-weight:700}
.sbl__clear{margin:0;color:var(--ds-ink-mute);font-size:13px}
.sbl__sort{display:flex;white-space:nowrap;align-items:center;gap:6px;font-size:13px;color:var(--ds-ink-mute)}
.sbl__sort select{min-height:36px;font:inherit;padding:4px 8px}
.sbl__list{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.sbl__head,.sbl__row{display:grid;grid-template-columns:minmax(7em,1.2fr) minmax(9em,1.3fr) 6em minmax(10em,1.8fr) 7.5em;gap:4px 12px;align-items:center}
.sbl__head{padding:0 12px;font-size:12px;color:var(--ds-ink-mute)}
.sbl__col-now{text-align:right}
.sbl__row{width:100%;min-height:52px;text-align:left;border:1px solid var(--ds-border);border-radius:var(--ds-radius-lg,8px);background:var(--ds-surface,var(--ds-canvas));padding:8px 12px;cursor:pointer;font:inherit;color:inherit}
.sbl__list>li.has-action{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px 8px;align-items:center}
.sbl__list>li.has-action>:not(.sbl__row):not(.sbl__action){grid-column:1/-1}
.sbl__action{min-height:44px;white-space:nowrap}
.sbl__row:hover{border-color:var(--ds-ink-mute)}
.sbl__meta{display:none}
.sbl__name{font-weight:700}
.sbl__now{display:grid;justify-items:end;font-variant-numeric:tabular-nums}
.sbl__now strong{font-size:16px}
.sbl__now small{font-size:12px;color:var(--ds-ink-mute)}
.sbl__now.due strong,.sbl__overdue.due{color:var(--ds-danger);font-weight:700}
.sbl__status{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px}
.sbl__last,.sbl__overdue{font-size:13px;font-variant-numeric:tabular-nums}
.sbl__muted{font-size:12px;color:var(--ds-ink-mute)}
.sbl__toggle{background:none;border:0;padding:4px 12px;min-height:32px;font-size:var(--ds-font-size-sm);color:var(--ds-ink-mute);cursor:pointer;text-decoration:underline}
.sbl__contracts{list-style:none;margin:0;padding:0 12px 8px}
.sbl__contract{display:flex;flex-wrap:wrap;gap:4px 16px;font-size:var(--ds-font-size-md);padding:4px 0;font-variant-numeric:tabular-nums}
@media (max-width:760px){
  .sbl__list>li.has-action{grid-template-columns:1fr}
  .sbl__action{width:100%}
  .sbl__toggle{min-height:44px}
  .sbl__head{display:none}
  .sbl__chips{flex-wrap:nowrap;overflow-x:auto;max-width:100%;padding-bottom:2px}
  .sbl__chip{flex:0 0 auto;min-height:44px}
  .sbl__tools input,.sbl__sort select{min-height:44px}
  .sbl__row{grid-template-columns:1fr auto;grid-template-areas:"meta meta" "name now" "status status";min-height:88px;gap:2px 12px}
  .sbl__meta{display:block;grid-area:meta;font-size:12px;color:var(--ds-ink-mute)}
  .sbl__name{grid-area:name}
  .sbl__now{grid-area:now}
  .sbl__status{grid-area:status}
  .sbl__overdue,.sbl__last{display:none}
}
</style>
