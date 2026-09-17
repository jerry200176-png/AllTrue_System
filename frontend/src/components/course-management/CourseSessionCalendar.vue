<template>
  <section class="course-session-calendar" data-testid="course-session-calendar" aria-label="課程排課行事曆">
    <div class="csc-header">
      <button type="button" class="ghost small" data-testid="csc-prev-month" @click="shiftMonth(-1)">上個月</button>
      <strong>{{ monthLabel }}</strong>
      <button type="button" class="ghost small" data-testid="csc-next-month" @click="shiftMonth(1)">下個月</button>
    </div>
    <p class="csc-hint" data-testid="csc-scope-hint">
      顯示此課程已建立與預排堂次。可在空白的未來日期新增一堂；取消、改時間、改老師尚未開放。
    </p>
    <div class="csc-weekdays" aria-hidden="true">
      <span v-for="wd in weekdayLabels" :key="wd">{{ wd }}</span>
    </div>
    <div class="csc-grid" role="grid">
      <button
        v-for="cell in cells"
        :key="cell.key"
        type="button"
        role="gridcell"
        :class="[
          'csc-cell',
          {
            'csc-cell--muted': !cell.inMonth,
            'csc-cell--materialized': cell.hasMaterialized,
            'csc-cell--projected': !cell.hasMaterialized && cell.hasProjected,
            'csc-cell--creatable': cell.canCreate && createEnabled,
          },
        ]"
        :disabled="!cell.inMonth || (!cell.canCreate && cell.isEmpty) || (cell.canCreate && !createEnabled)"
        :data-date="cell.date || undefined"
        :data-testid="cell.inMonth ? `csc-day-${cell.date}` : undefined"
        :aria-label="cellAriaLabel(cell)"
        @click="onCellClick(cell)"
      >
        <span class="csc-day-num">{{ cell.day }}</span>
        <small v-if="cell.hasMaterialized" class="csc-label csc-label--mat">已排</small>
        <small v-else-if="cell.hasProjected" class="csc-label csc-label--proj">預排</small>
        <small v-else-if="cell.canCreate && createEnabled" class="csc-label">＋</small>
      </button>
    </div>
    <div class="csc-legend">
      <span class="csc-chip csc-chip--mat">已排（已建立）</span>
      <span class="csc-chip csc-chip--proj">預排</span>
      <span v-if="createEnabled" class="csc-chip csc-chip--add">空白未來日可新增</span>
    </div>
    <div v-if="createEnabled && canQuickAdd" class="csc-secondary">
      <button type="button" class="ghost small" data-testid="csc-quick-add" @click="$emit('quick-add')">補課／補登…</button>
    </div>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import {
  buildCourseSessionCalendarCells,
  canOfferQuickAddFromCalendar,
  courseSessionCalendarMonthLabel,
  courseSessionCalendarWeekdayLabels,
  toYmd,
} from '../../composables/course-management/useCourseSessionCalendar.js';

const props = defineProps({
  course: { type: Object, required: true },
  sessions: { type: Array, default: () => [] },
  createEnabled: { type: Boolean, default: true },
  todayYmd: { type: String, default: () => toYmd(new Date()) },
  initialYear: { type: Number, default: null },
  initialMonth: { type: Number, default: null },
});
const emit = defineEmits(['create-day', 'quick-add']);

const now = new Date();
const viewYear = ref(props.initialYear || now.getFullYear());
const viewMonth = ref(props.initialMonth || (now.getMonth() + 1));
watch(() => [props.initialYear, props.initialMonth], ([y, m]) => {
  if (Number.isFinite(y) && y > 0) viewYear.value = y;
  if (Number.isFinite(m) && m >= 1 && m <= 12) viewMonth.value = m;
});

const weekdayLabels = courseSessionCalendarWeekdayLabels();
const monthLabel = computed(() => courseSessionCalendarMonthLabel(viewYear.value, viewMonth.value));
const canQuickAdd = computed(() => canOfferQuickAddFromCalendar(props.course));
const cells = computed(() => buildCourseSessionCalendarCells({
  year: viewYear.value, month: viewMonth.value, sessions: props.sessions, todayYmd: props.todayYmd,
}));

function shiftMonth(delta) {
  const d = new Date(viewYear.value, viewMonth.value - 1 + delta, 1);
  viewYear.value = d.getFullYear();
  viewMonth.value = d.getMonth() + 1;
}
function cellAriaLabel(cell) {
  if (!cell.inMonth) return '';
  if (cell.hasMaterialized) return `${cell.date} 已建立堂次`;
  if (cell.hasProjected) return `${cell.date} 預排堂次`;
  if (cell.canCreate && props.createEnabled) return `${cell.date} 新增堂次`;
  return cell.date;
}
function onCellClick(cell) {
  // Occupied cells are display-only in Phase 0/1a (no cancel / edit).
  if (cell?.inMonth && cell.canCreate && props.createEnabled) emit('create-day', { date: cell.date });
}
</script>

<style scoped>
.course-session-calendar { margin-top: 12px; padding: 12px; border: 1px solid var(--ds-border); border-radius: 12px; background: var(--ds-surface); }
.csc-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.csc-hint { margin: 0 0 10px; font-size: .82rem; color: var(--ds-text-secondary); line-height: 1.45; }
.csc-weekdays, .csc-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
.csc-weekdays { margin-bottom: 4px; font-size: .75rem; color: var(--ds-text-secondary); text-align: center; }
.csc-cell { min-height: 52px; display: flex; flex-direction: column; align-items: flex-start; gap: 2px; padding: 6px; border: 1px solid var(--ds-border); border-radius: 8px; background: var(--ds-surface); cursor: default; text-align: left; }
.csc-cell:disabled { opacity: .55; }
.csc-cell--muted { opacity: .35; }
.csc-cell--materialized { background: var(--ds-success-wash); }
.csc-cell--projected { background: var(--ds-info-wash); }
.csc-cell--creatable { cursor: pointer; border-style: dashed; }
.csc-day-num { font-size: .82rem; font-weight: 600; }
.csc-label { font-size: .68rem; line-height: 1.2; }
.csc-label--mat { color: var(--ds-success); }
.csc-label--proj { color: var(--ds-info); }
.csc-legend { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.csc-chip { font-size: .75rem; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--ds-border); }
.csc-chip--mat { background: var(--ds-success-wash); }
.csc-chip--proj { background: var(--ds-info-wash); }
.csc-chip--add { border-style: dashed; }
.csc-secondary { margin-top: 10px; }
</style>
