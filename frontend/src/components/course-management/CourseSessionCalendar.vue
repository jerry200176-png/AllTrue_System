<template>
  <section class="course-session-calendar tw:rounded-2xl tw:border tw:border-border tw:bg-card tw:p-3 tw:shadow-tpl-card tw:sm:p-5" data-testid="course-session-calendar" aria-label="課程排課行事曆">
    <div class="tw:flex tw:items-center tw:gap-2">
      <Button variant="secondary" size="icon" class="tw:size-14" data-testid="csc-prev-month" aria-label="上個月" @click="shiftMonth(-1)"><ChevronLeft :size="26" aria-hidden="true" /></Button>
      <strong class="csc-month tw:flex-1 tw:whitespace-nowrap tw:text-center tw:text-[length:var(--tpl-text-list)] tw:font-bold">{{ monthLabel }}</strong>
      <Button variant="secondary" size="icon" class="tw:size-14" data-testid="csc-next-month" aria-label="下個月" @click="shiftMonth(1)"><ChevronRight :size="26" aria-hidden="true" /></Button>
      <Button variant="secondary" class="tw:h-14 tw:px-5 tw:text-[length:var(--tpl-text-body)]" data-testid="csc-today" @click="goToday">今天</Button>
    </div>
    <p class="csc-hint tw:mt-3 tw:text-[length:var(--tpl-text-caption)] tw:text-tpl-secondary" data-testid="csc-scope-hint">
      點空白的未來日期可新增堂次。
    </p>
    <div class="csc-weekdays tw:mt-3 tw:text-[length:var(--tpl-text-caption)] tw:font-semibold tw:text-tpl-secondary" aria-hidden="true">
      <span v-for="wd in weekdayLabels" :key="wd">{{ wd }}</span>
    </div>
    <div class="csc-grid tw:mt-1" role="grid">
      <button
        v-for="cell in cells"
        :key="cell.key"
        type="button"
        role="gridcell"
        :class="[
          'csc-cell',
          {
            'csc-cell--muted': !cell.inMonth,
            'csc-cell--today': cell.date === todayYmd,
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
        @dragend="onDragEnd"
        @dragover="onDragOver($event, cell)"
        @dragleave="dropDate = ''"
        @drop="onDrop($event, cell)"
      >
        <span class="csc-day-num">{{ cell.day }}</span>
        <small v-if="cell.hasMaterialized" class="csc-label csc-label--mat" :data-testid="`csc-time-${cell.date}`">{{ timeLabel(cell) }}</small>
        <small v-else-if="cell.hasProjected" class="csc-label csc-label--proj">預排{{ timeLabel(cell) === '已排' ? '' : ` ${timeLabel(cell)}` }}</small>
        <small v-else-if="cell.canCreate && createEnabled" class="csc-label csc-label--add">＋</small>
        <small v-if="stateWord(cell)" class="csc-label csc-label--warn">{{ stateWord(cell) }}</small>
      </button>
    </div>
    <div class="csc-legend tw:mt-4 tw:text-[length:var(--tpl-text-caption)]">
      <span class="csc-chip"><i class="csc-dot csc-dot--mat" aria-hidden="true" />已排（開始時間）</span>
      <span class="csc-chip"><i class="csc-dot csc-dot--proj" aria-hidden="true" />預排</span>
      <span class="csc-chip"><i class="csc-dot csc-dot--warn" aria-hidden="true" />請假／取消</span>
      <span v-if="canMove" class="csc-chip">可拖拉改期</span>
    </div>
    <div v-if="showQuickAdd && createEnabled && canQuickAdd" class="tw:mt-3">
      <Button variant="secondary" class="tw:h-14 tw:px-5 tw:text-[length:var(--tpl-text-body)]" data-testid="csc-quick-add" @click="$emit('quick-add')">補課／補登…</Button>
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
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

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
// Cancelled (Esc / dropped outside): forget the lesson so a later unrelated drop can't move it (Codex P2 on #4898).
function onDragEnd() { dragFrom = null; dropDate.value = ''; }
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
/* Same look as the class calendar (SmartCalendar): colored blocks carry the start time; today is an ink dot. */
.csc-weekdays, .csc-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
.csc-weekdays { text-align: center; }
.csc-cell { min-height: 72px; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; gap: 2px; padding: 6px 2px; border: 1px solid transparent; border-radius: 12px; background: var(--ds-canvas-soft); color: var(--ds-ink); cursor: default; font-size: var(--tpl-text-body); }
.csc-cell:disabled { opacity: 1; }
.csc-cell--muted { opacity: .4; background: transparent; }
.csc-cell--materialized { background: color-mix(in srgb, var(--ds-cat-blue-deep, var(--ds-info)) 16%, var(--ds-canvas)); border-color: var(--ds-cat-blue-deep, var(--ds-info)); }
.csc-cell--projected { background: var(--ds-canvas); border: 1px dashed var(--ds-cat-violet-deep, var(--ds-info)); }
.csc-cell--creatable { cursor: pointer; background: var(--ds-canvas); border: 1px dashed var(--ds-hairline-input); }
.csc-cell[draggable='true'] { cursor: grab; }
.csc-cell--drop { outline: 2px dashed var(--ds-ink); outline-offset: -2px; }
.csc-cell:focus-visible { outline: 2px solid var(--ds-ink); outline-offset: 2px; }
.csc-day-num { display: grid; place-items: center; min-width: 2rem; height: 2rem; border-radius: 999px; font-weight: 700; line-height: 1; }
.csc-cell--today .csc-day-num { background: var(--ds-ink); color: var(--ds-canvas); }
.csc-label { font-size: .8125rem; font-weight: 700; line-height: 1.2; white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis; color: var(--ds-ink); }
.csc-label--warn { color: var(--ds-warning); }
.csc-label--add { color: var(--ds-ink-secondary); font-size: 1.25rem; }
.csc-legend { display: flex; flex-wrap: wrap; gap: 8px 16px; color: var(--ds-ink-secondary); }
.csc-chip { display: inline-flex; align-items: center; gap: 8px; }
.csc-dot { width: 14px; height: 14px; border-radius: 999px; display: inline-block; }
.csc-dot--mat { background: var(--ds-cat-blue-deep, var(--ds-info)); }
.csc-dot--proj { border: 2px dashed var(--ds-cat-violet-deep, var(--ds-info)); }
.csc-dot--warn { background: var(--ds-warning); }
</style>
