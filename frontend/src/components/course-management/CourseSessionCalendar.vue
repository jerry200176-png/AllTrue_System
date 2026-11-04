<template>
  <section class="course-session-calendar" data-testid="course-session-calendar" aria-label="課程排課行事曆">
    <div class="csc-header">
      <button type="button" class="ghost small csc-nav" data-testid="csc-prev-month" aria-label="上個月" @click="shiftMonth(-1)">
        <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
      </button>
      <strong class="csc-month">{{ monthLabel }}</strong>
      <button type="button" class="ghost small csc-nav" data-testid="csc-next-month" aria-label="下個月" @click="shiftMonth(1)">
        <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
      </button>
      <button type="button" class="ghost small" data-testid="csc-today" @click="goToday">今天</button>
    </div>
    <p class="csc-hint" data-testid="csc-scope-hint">
      點空白的未來日期可新增堂次；已排與預排堂次會顯示在月曆中。
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
            'csc-cell--drop': dropDate === cell.date && isDropTarget(cell),
          },
        ]"
        :disabled="!cell.inMonth || (!cell.canCreate && cell.isEmpty) || (cell.canCreate && !createEnabled)"
        :data-date="cell.date || undefined"
        :data-testid="cell.inMonth ? `csc-day-${cell.date}` : undefined"
        :aria-label="cellAriaLabel(cell)"
        :draggable="dragSession(cell) ? 'true' : undefined"
        @click="onCellClick(cell)"
        @dragstart="onDragStart($event, cell)"
        @dragend="dropDate = ''"
        @dragover="onDragOver($event, cell)"
        @dragleave="dropDate = ''"
        @drop="onDrop($event, cell)"
      >
        <span class="csc-day-num">{{ cell.day }}</span>
        <small v-if="cell.hasMaterialized" class="csc-label csc-label--mat" :data-testid="`csc-time-${cell.date}`">{{ timeLabel(cell) }}</small>
        <small v-else-if="cell.hasProjected" class="csc-label csc-label--proj">預排{{ timeLabel(cell) === '已排' ? '' : ` ${timeLabel(cell)}` }}</small>
        <small v-else-if="cell.canCreate && createEnabled" class="csc-label">＋</small>
        <small v-if="stateWord(cell)" class="csc-label csc-label--warn">{{ stateWord(cell) }}</small>
      </button>
    </div>
    <div class="csc-legend">
      <span class="csc-chip csc-chip--mat">19:00 已建立（開始時間）</span>
      <span class="csc-chip csc-chip--warn">請假／取消／例外 等狀態</span>
      <span class="csc-chip csc-chip--proj">預排</span>
      <span v-if="canMove" class="csc-chip">可拖拉未來堂次改期</span>
      <span v-if="createEnabled" class="csc-chip csc-chip--add">空白未來日可新增</span>
    </div>
    <div v-if="showQuickAdd && createEnabled && canQuickAdd" class="csc-secondary">
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
  /** When false (Course Manager sessions), toolbar owns quick-add — no calendar duplicate. */
  showQuickAdd: { type: Boolean, default: true },
  todayYmd: { type: String, default: () => toYmd(new Date()) },
  initialYear: { type: Number, default: null },
  initialMonth: { type: Number, default: null },
  /** (session) => state word such as 請假; the parent already owns this rule. */
  stateLabel: { type: Function, default: () => '' },
  /** (session) => true when the parent's isLessonMovable() allows moving it; absent = no drag. */
  canMoveSession: { type: Function, default: null },
});
const emit = defineEmits(['create-day', 'quick-add', 'select-day', 'move-session']);
const canMove = computed(() => typeof props.canMoveSession === 'function');
const dropDate = ref('');
let dragFrom = null; // { id, date } of the lesson being dragged

const NORMAL_STATES = new Set(['', '已上', '預排', '已建立']);
const startOf = (s) => String(s?.startTime || s?.start_time || '').slice(0, 5);
function timeLabel(cell) {
  const times = cell.sessions.filter((s) => !s.isProjected && s.kind !== 'projected').map(startOf).filter(Boolean).sort();
  if (!times.length) return '已排';
  return times.length > 1 ? `${times[0]} +${times.length - 1}` : times[0];
}
function stateWord(cell) {
  const words = cell.sessions.map((s) => props.stateLabel(s)).filter((w) => !NORMAL_STATES.has(w));
  return words[0] || '';
}
// One lesson on a future day that the parent says is movable.
function dragSession(cell) {
  if (!canMove.value || !cell.inMonth || cell.sessions.length !== 1) return null;
  const s = cell.sessions[0];
  return !s.isProjected && s.kind !== 'projected' && s.id && cell.isFutureOrToday && props.canMoveSession(s) ? s : null;
}
function isDropTarget(cell) {
  return Boolean(dragFrom && cell.inMonth && cell.isFutureOrToday && cell.date !== dragFrom.date);
}
function onDragStart(e, cell) {
  const s = dragSession(cell);
  if (!s) { e.preventDefault?.(); return; }
  dragFrom = { id: s.id, date: cell.date };
  if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(s.id)); }
}
function onDragOver(e, cell) {
  if (!isDropTarget(cell)) return;
  e.preventDefault();
  dropDate.value = cell.date;
}
function onDrop(e, cell) {
  if (!isDropTarget(cell)) return;
  e.preventDefault();
  const from = dragFrom;
  dragFrom = null;
  dropDate.value = '';
  emit('move-session', { sessionId: from.id, fromDate: from.date, toDate: cell.date });
}

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
function goToday() {
  const t = props.todayYmd ? new Date(`${props.todayYmd}T12:00:00`) : new Date();
  viewYear.value = t.getFullYear();
  viewMonth.value = t.getMonth() + 1;
}
function cellAriaLabel(cell) {
  if (!cell.inMonth) return '';
  if (cell.hasMaterialized) return `${cell.date} 已建立堂次 ${timeLabel(cell)} ${stateWord(cell)}`.trim();
  if (cell.hasProjected) return `${cell.date} 預排堂次`;
  if (cell.canCreate && props.createEnabled) return `${cell.date} 新增堂次`;
  return cell.date;
}
function onCellClick(cell) {
  if (!cell?.inMonth) return;
  // Occupied: read-only focus only (no cancel / edit / this-and-future).
  if (cell.canCreate && props.createEnabled) {
    emit('create-day', { date: cell.date });
    return;
  }
  if (cell.hasMaterialized || cell.hasProjected) emit('select-day', { date: cell.date });
}
</script>

<style scoped>
.course-session-calendar { margin-top: 4px; padding: 12px; border: 1px solid var(--ds-border, var(--ds-hairline)); border-radius: 12px; background: var(--ds-surface, var(--ds-canvas)); width: 100%; }
.csc-header { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
.csc-month { flex: 1; text-align: center; font-size: .95rem; }
.csc-nav { min-width: 36px; padding-inline: 4px; }
.csc-nav .material-symbols-outlined { font-size: 20px; line-height: 1; }
.csc-hint { margin: 0 0 10px; font-size: .82rem; color: var(--ds-text-secondary, var(--ds-ink-mute)); line-height: 1.45; }
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
.csc-label--warn { color: var(--ds-warning); font-weight: 600; }
.csc-cell[draggable='true'] { cursor: grab; }
.csc-cell--drop { outline: 2px dashed var(--ds-info); outline-offset: -2px; background: var(--ds-info-wash); }
.csc-label { white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
.csc-legend { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.csc-chip { font-size: .75rem; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--ds-border); }
.csc-chip--mat { background: var(--ds-success-wash); }
.csc-chip--proj { background: var(--ds-info-wash); }
.csc-chip--warn { color: var(--ds-warning); }
.csc-chip--add { border-style: dashed; }
.csc-secondary { margin-top: 10px; }
</style>
