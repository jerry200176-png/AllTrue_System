<template>
  <section class="rsc wp" id="recent-subs-sec" data-guide="director-recent-subs">
    <header class="wp__head rsc-head">
      <span class="material-symbols-outlined wp__hi">swap_horiz</span>
      <h3>近 7 天代課記錄</h3>
      <span v-if="items.length" class="wp__badge">{{ items.length }}</span>
    </header>
    <p class="wp__hint">僅顯示當前分校，資料來自代課送出時建立的家長通知。</p>
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
          <div class="rsc-row__meta">
            <span class="rsc-row__date">{{ formatDate(row.session_date) }}</span>
            <span class="rsc-row__time">{{ formatTimeRange(row.start_time, row.end_time) }}</span>
            <span class="rsc-row__student">{{ row.student_name }} · {{ row.subject || '課程' }}</span>
          </div>
          <div class="rsc-row__flow">
            <span class="rsc-name rsc-name--old">{{ row.old_teacher_name || '—' }}</span>
            <span class="material-symbols-outlined rsc-arrow-icon" aria-hidden="true">arrow_forward</span>
            <span class="rsc-name rsc-name--new">{{ row.new_teacher_name || '—' }}</span>
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
  gap: 6px;
}
.rsc-head { display: flex; align-items: center; gap: 8px; }

.rsc-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }

.rsc-row {
  padding: 10px 12px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 6px;
  transition: box-shadow 0.15s, border-color 0.15s;
}
.rsc-row:hover {
  border-color: #d1d5db;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

/* ── Meta row：日期 / 時間 / 學生 */
.rsc-row__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: #6b7280;
}
.rsc-row__date {
  font-weight: 700;
  color: #111827;
  font-size: 13px;
}
.rsc-row__time {
  color: #4b5563;
  font-variant-numeric: tabular-nums;
}
.rsc-row__student {
  color: #6b7280;
  margin-left: auto;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}

/* ── Flow row：原老師 → 代課老師 + 標籤 */
.rsc-row__flow {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  flex-wrap: wrap;
}
.rsc-name {
  line-height: 1.3;
  max-width: 10em;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rsc-name--old { color: #6b7280; }
.rsc-name--new { color: var(--primary, #2563eb); font-weight: 700; }
.rsc-arrow-icon {
  font-size: 18px;
  color: #9ca3af;
  flex-shrink: 0;
}

.rsc-row__chip {
  margin-left: 2px;
  background: #f1f5f9;
  color: #475569;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 999px;
  font-weight: 500;
}
.rsc-row__chip--rescheduled {
  background: #fef3c7;
  color: #92400e;
  font-weight: 600;
}
.rsc-row__badge {
  background: #fef3c7;
  color: #92400e;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 999px;
  font-weight: 600;
}

/* ── Reason：可展開 */
.rsc-row__reason {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  padding: 8px 10px;
  border: none;
  background: #f9fafb;
  border-radius: 8px;
  cursor: pointer;
  text-align: left;
  width: 100%;
  min-height: 44px;
  transition: background 0.15s;
  font-family: inherit;
}
.rsc-row__reason:hover { background: #f3f4f6; }
.rsc-row__reason:focus-visible {
  outline: 2px solid var(--primary, #2563eb);
  outline-offset: 1px;
}
.rsc-row__reason-icon {
  font-size: 16px;
  color: #9ca3af;
  flex-shrink: 0;
  margin-top: 1px;
}
.rsc-row__reason-text {
  font-size: 12px;
  color: #4b5563;
  line-height: 1.5;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
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
  font-size: 11px;
  color: #9ca3af;
  text-align: right;
  margin: 4px 2px 0;
}

/* ── Empty */
.rsc-empty {
  text-align: center;
  padding: 28px 8px 20px;
  color: #6b7280;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.rsc-empty__icon {
  font-size: 36px;
  color: #9ca3af;
}
.rsc-empty__title {
  font-weight: 600;
  color: #111827;
  margin-top: 4px;
}
.rsc-empty__desc { font-size: 12px; color: #6b7280; }

/* ── Skeleton */
.rsc-skel { display: flex; flex-direction: column; gap: 8px; padding: 6px 0; }
.rsc-skel__row {
  padding: 10px 12px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  background: #fff;
}
.rsc-skel__line {
  height: 10px;
  background: #e5e7eb;
  border-radius: 4px;
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
@media (max-width: 360px) {
  .rsc-row__student { margin-left: 0; width: 100%; }
  .rsc-name { max-width: 7em; }
}
</style>
