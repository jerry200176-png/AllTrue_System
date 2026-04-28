<template>
  <section class="rsc wp" id="recent-subs-sec" data-guide="director-recent-subs">
    <header class="wp__head">
      <span class="material-symbols-outlined wp__hi" aria-hidden="true">&#xe8d4;</span>
      <h3>代課動態</h3>
      <span v-if="items.length" class="wp__badge">{{ items.length }}</span>
    </header>
    <div v-if="loading" class="rsc-skel" aria-hidden="true">
      <div v-for="n in 3" :key="n" class="rsc-skel__row">
        <div class="rsc-skel__line rsc-skel__line--w60"></div>
        <div class="rsc-skel__line rsc-skel__line--w80"></div>
        <div class="rsc-skel__line rsc-skel__line--w40"></div>
      </div>
    </div>
    <template v-else>
      <div v-if="items.length === 0" class="rsc-empty">
        <span class="material-symbols-outlined rsc-empty__icon" aria-hidden="true">event_available</span>
        <div class="rsc-empty__title">近 7 天無代課記錄</div>
        <div class="rsc-empty__desc">老師出勤穩定，辛苦您！</div>
      </div>
      <ul v-else class="rsc-list">
        <li v-for="row in displayedItems" :key="row.id" class="rsc-row">
          <span class="rsc-row__rail" aria-hidden="true"></span>
          <div class="rsc-row__meta">
            <span class="rsc-row__date">{{ formatDate(row.session_date) }}</span>
            <span class="rsc-row__time">{{ formatTimeRange(row.start_time, row.end_time) }}</span>
            <span class="rsc-row__student">{{ row.student_name }} · {{ row.subject || '課程' }}</span>
          </div>
          <div class="rsc-row__flow">
            <span class="rsc-name rsc-name--old">
              <small>原老師</small>
              <strong>{{ row.old_teacher_name || '—' }}</strong>
            </span>
            <span class="material-symbols-outlined rsc-arrow-icon" aria-hidden="true">arrow_forward</span>
            <span class="rsc-name rsc-name--new">
              <small>代課</small>
              <strong>{{ row.new_teacher_name || '—' }}</strong>
            </span>
            <span
              v-if="row.operation_type === 'substitute_with_reschedule'"
              class="rsc-row__chip rsc-row__chip--rescheduled"
              :title="formatRescheduleTooltip(row)"
            >含換時</span>
            <span v-if="row.cross_campus" class="rsc-row__badge">跨分校</span>
          </div>
          <button
            v-if="row.reason"
            type="button"
            class="rsc-row__reason"
            :class="{ 'rsc-row__reason--expanded': isReasonExpanded(row.id) }"
            :aria-expanded="isReasonExpanded(row.id)"
            @click="toggleReason(row.id)"
          >
            <span class="material-symbols-outlined rsc-row__reason-icon" aria-hidden="true">sticky_note_2</span>
            <span class="rsc-row__reason-text">{{ row.reason }}</span>
          </button>
        </li>
      </ul>
      <p v-if="items.length" class="rsc-summary">共 {{ items.length }} 筆代課</p>
      <footer v-if="items.length > limit && !expanded" class="wp__foot">
        <button class="btn-o btn-xs" @click="expanded = true">查看全部 ({{ items.length }})</button>
      </footer>
      <footer v-else-if="expanded && items.length > limit" class="wp__foot">
        <button class="btn-o btn-xs" @click="expanded = false">收合</button>
      </footer>
    </template>
  </section>
</template>

<script setup>
// RecentSubstitutesCard — 近 7 天代課記錄（PRD 9c058f19 FR-012 / US-05）
import { computed, ref, reactive, watch } from 'vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  // async ({ branch_id }) => { items: [...] }
  fetchRecent: { type: Function, required: true },
});

const items = ref([]);
const loading = ref(false);
const expanded = ref(false);
const limit = 5;
const expandedReasons = reactive(new Set());

const displayedItems = computed(() =>
  expanded.value ? items.value : items.value.slice(0, limit)
);

async function load() {
  if (!props.branchId) {
    items.value = [];
    return;
  }
  loading.value = true;
  try {
    const r = await props.fetchRecent({ branch_id: props.branchId });
    items.value = Array.isArray(r?.items) ? r.items : [];
  } catch (e) {
    items.value = [];
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.branchId,
  () => {
    expandedReasons.clear();
    load();
  },
  { immediate: true }
);

// 相對日期：今天 / 昨天 / M/D（週X）；異常時 fallback 到原字串避免白畫面
const WEEK_LABELS = ['日', '一', '二', '三', '四', '五', '六'];
function formatDate(dateStr) {
  if (!dateStr || typeof dateStr !== 'string') return dateStr || '';
  try {
    const parts = dateStr.slice(0, 10).split('-');
    if (parts.length !== 3) return dateStr;
    const [y, m, d] = parts.map((p) => parseInt(p, 10));
    if (!y || !m || !d) return dateStr;
    const target = new Date(y, m - 1, d);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const diffDays = Math.round((target.getTime() - today.getTime()) / 86400000);
    if (diffDays === 0) return '今天';
    if (diffDays === -1) return '昨天';
    return `${m}/${d}（週${WEEK_LABELS[target.getDay()]}）`;
  } catch (e) {
    return dateStr;
  }
}

function formatTimeRange(start, end) {
  const s = (start || '').slice(0, 5);
  const e = (end || '').slice(0, 5);
  if (!s && !e) return '';
  return `${s} ～ ${e}`;
}

function isReasonExpanded(id) {
  return expandedReasons.has(id);
}

function toggleReason(id) {
  if (expandedReasons.has(id)) expandedReasons.delete(id);
  else expandedReasons.add(id);
}

function formatRescheduleTooltip(row) {
  if (!row) return '';
  const od = row.original_session_date || '';
  const os = row.original_start_time || '';
  const oe = row.original_end_time || '';
  const nd = row.session_date || '';
  const ns = row.start_time || '';
  const ne = row.end_time || '';
  if (od && os && oe && nd && ns && ne) {
    return `原定 ${od} ${os}~${oe} 已調整至 ${nd} ${ns}~${ne}`;
  }
  return '此筆代課同時調整了上課時間';
}

defineExpose({ reload: load });
</script>

<style scoped>
.rsc {
  display: flex;
  flex-direction: column;
  gap: 0;
}

.rsc-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  list-style: none;
  margin: 0;
  padding: 0 14px 4px;
}

.rsc-row {
  background:
    linear-gradient(135deg, rgba(255, 255, 255, 0.98), rgba(250, 250, 248, 0.92)),
    var(--porsche-surface);
  border: 1px solid var(--porsche-border);
  border-radius: 20px;
  box-shadow: 0 14px 34px rgba(15, 23, 42, 0.055), inset 0 1px 0 rgba(255, 255, 255, 0.88);
  display: grid;
  gap: 12px;
  padding: 15px 15px 15px 18px;
  position: relative;
  transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}
.rsc-row:hover {
  border-color: var(--porsche-border-strong);
  box-shadow: 0 20px 42px rgba(15, 23, 42, 0.095), inset 0 1px 0 rgba(255, 255, 255, 0.9);
  transform: translateY(-1px);
}
.rsc-row__rail {
  position: absolute;
  inset: 16px auto 16px 9px;
  width: 2px;
  border-radius: 999px;
  background: linear-gradient(180deg, var(--porsche-ink), var(--porsche-amber));
  opacity: 0.72;
}

/* ── Meta row：日期 / 時間 / 學生 */
.rsc-row__meta {
  align-items: center;
  color: var(--porsche-ink-soft);
  display: grid;
  gap: 8px;
  grid-template-columns: minmax(58px, auto) minmax(96px, auto) minmax(0, 1fr);
}
.rsc-row__date {
  background: rgba(17, 24, 39, 0.06);
  border: 1px solid rgba(17, 24, 39, 0.1);
  border-radius: 999px;
  color: var(--porsche-ink);
  display: inline-flex;
  font-size: 11px;
  font-weight: 800;
  justify-content: center;
  letter-spacing: 0.02em;
  padding: 4px 10px;
  white-space: nowrap;
}
.rsc-row__time {
  color: var(--porsche-ink);
  font-size: 12px;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  letter-spacing: -0.01em;
  white-space: nowrap;
}
.rsc-row__student {
  color: var(--porsche-ink-soft);
  font-size: 12px;
  font-weight: 600;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ── Flow row：原老師 → 代課老師 + 標籤 */
.rsc-row__flow {
  align-items: center;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1.08fr) auto auto;
  gap: 8px;
}
.rsc-name {
  display: grid;
  gap: 3px;
  overflow: hidden;
  border-radius: 16px;
  line-height: 1.2;
  min-width: 0;
  padding: 10px 12px 11px;
}
.rsc-name small {
  color: inherit;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.1em;
  opacity: 0.7;
  text-transform: uppercase;
}
.rsc-name strong {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rsc-name--old {
  background: rgba(248, 250, 252, 0.9);
  border: 1px solid rgba(148, 163, 184, 0.2);
  color: var(--porsche-ink-soft);
  font-size: 13px;
  font-weight: 600;
}
.rsc-name--new {
  background:
    linear-gradient(135deg, rgba(17, 24, 39, 0.98), rgba(51, 65, 85, 0.96));
  border: 1px solid rgba(17, 24, 39, 0.82);
  color: #fff;
  font-size: 14px;
  font-weight: 800;
  letter-spacing: -0.01em;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
}
.rsc-arrow-icon {
  color: var(--porsche-amber);
  flex-shrink: 0;
  font-size: 17px;
  opacity: 0.86;
}

.rsc-row__chip {
  background: rgba(248, 250, 252, 0.88);
  border: 1px solid var(--porsche-border);
  border-radius: 999px;
  color: var(--porsche-ink-soft);
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  white-space: nowrap;
}
.rsc-row__chip--rescheduled {
  background: rgba(196, 122, 24, 0.1);
  border-color: rgba(196, 122, 24, 0.24);
  color: var(--porsche-amber);
}
.rsc-row__badge {
  background: rgba(37, 99, 235, 0.08);
  border: 1px solid rgba(37, 99, 235, 0.18);
  border-radius: 999px;
  color: var(--porsche-blue);
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  white-space: nowrap;
}

/* ── Reason：可展開 */
.rsc-row__reason {
  align-items: flex-start;
  background: rgba(248, 250, 252, 0.64);
  border: 1px solid rgba(148, 163, 184, 0.16);
  border-radius: 14px;
  cursor: pointer;
  display: flex;
  font-family: inherit;
  gap: 6px;
  min-height: 44px;
  padding: 9px 11px;
  text-align: left;
  transition: background 0.15s ease, border-color 0.15s ease;
  width: 100%;
}
.rsc-row__reason:hover {
  background: #fff;
  border-color: var(--porsche-border-strong);
}
.rsc-row__reason:focus-visible {
  outline: 2px solid var(--porsche-ink);
  outline-offset: 1px;
}
.rsc-row__reason-icon {
  color: var(--porsche-ink-soft);
  flex-shrink: 0;
  font-size: 16px;
  margin-top: 1px;
}
.rsc-row__reason-text {
  color: var(--porsche-ink-soft);
  display: -webkit-box;
  flex: 1;
  font-size: 12px;
  font-weight: 500;
  line-height: 1.55;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  word-break: break-word;
}
.rsc-row__reason--expanded .rsc-row__reason-text {
  -webkit-line-clamp: unset;
  display: block;
  white-space: normal;
}

/* ── Summary */
.rsc-summary {
  color: var(--porsche-ink-soft);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  margin: 4px 2px 0;
  padding: 0 16px;
  text-align: right;
  text-transform: uppercase;
}

/* ── Empty */
.rsc-empty {
  align-items: center;
  background:
    linear-gradient(135deg, rgba(255, 255, 255, 0.84), rgba(248, 250, 252, 0.78));
  border: 1px solid var(--porsche-border);
  border-radius: 18px;
  color: var(--porsche-ink-soft);
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0 14px 4px;
  padding: 34px 12px 28px;
  text-align: center;
}
.rsc-empty__icon {
  color: var(--porsche-green);
  font-size: 38px;
}
.rsc-empty__title {
  color: var(--porsche-ink);
  font-weight: 900;
  margin-top: 4px;
}
.rsc-empty__desc { color: var(--porsche-ink-soft); font-size: 12px; font-weight: 600; }

/* ── Skeleton */
.rsc-skel { display: flex; flex-direction: column; gap: 10px; padding: 0 14px 4px; }
.rsc-skel__row {
  background: rgba(255, 255, 255, 0.82);
  border: 1px solid var(--porsche-border);
  border-radius: 18px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 14px;
}
.rsc-skel__line {
  height: 10px;
  background: linear-gradient(90deg, rgba(148, 163, 184, 0.14), rgba(148, 163, 184, 0.28), rgba(148, 163, 184, 0.14));
  background-size: 200% 100%;
  border-radius: 999px;
  animation: rsc-skel-pulse 1.4s ease-in-out infinite;
}
.rsc-skel__line--w40 { width: 40%; }
.rsc-skel__line--w60 { width: 60%; }
.rsc-skel__line--w80 { width: 80%; }
@keyframes rsc-skel-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.6; }
}

/* ── 窄寬度 fallback */
@media (max-width: 560px) {
  .rsc-row__meta {
    grid-template-columns: 1fr;
  }
  .rsc-row__student {
    white-space: normal;
  }
  .rsc-row__flow {
    align-items: stretch;
    grid-template-columns: 1fr auto 1fr;
  }
  .rsc-row__chip,
  .rsc-row__badge {
    justify-self: start;
  }
}
</style>
