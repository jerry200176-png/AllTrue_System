<script setup>
// issue 5269 step 3: three progress figures at the top of the 讀書計畫 tab, from data the profile already loaded
// (plan API `percent_done` / `units_behind`, the shown week's tasks, the learning timeline's sign-ins). It fetches nothing.
// A figure with no real data shows 「—」 and the reason, never 0%. The bar under each number is decorative (aria-hidden);
// the number is the text. Colour follows PAGE_TEMPLATE §6: warning only when units are behind, success when the week is all done.
import { computed } from 'vue';
import { StatItem, StatStrip } from '../ui/page';
import { Progress } from '../ui/progress';
import { Skeleton } from '../ui/skeleton';
import { attendanceThisMonth, planProgress, weekTaskProgress } from '../../lib/studyWeek';

const props = defineProps({
  tasks: { type: Array, required: true },
  tasksLoading: { type: Boolean, default: false },
  tasksError: { type: Boolean, default: false },
  plans: { type: Array, required: true },
  plansLoading: { type: Boolean, default: false },
  plansError: { type: Boolean, default: false },
  attendanceItems: { type: Array, default: null }, // the learning timeline's items; null = not available
  cappedSources: { type: Array, default: () => [] },
  today: { type: String, required: true },
  teacherScope: { type: Boolean, default: false }, // a teacher's timeline holds only the lessons that teacher may see
});

const DASH = '—';
const plan = computed(() => planProgress(props.plans));
const week = computed(() => weekTaskProgress(props.tasks));
const attendance = computed(() => attendanceThisMonth(props.attendanceItems, props.today, props.cappedSources));

const planFigure = computed(() => {
  const p = plan.value;
  if (props.plansLoading) return { loading: true };
  if (props.plansError) return { reason: '學習計畫暫時載入不了' };
  if (p.status === 'none') return { reason: '還沒有學習計畫' };
  if (p.status === 'no-units') return { reason: '學習計畫還沒排單元' };
  const sub = `${p.behind > 0 ? `落後 ${p.behind} 個單元` : '沒有落後的單元'}${p.plans > 1 ? `（共 ${p.plans} 個計畫）` : ''}`;
  return { value: `${p.percent}%`, percent: p.percent, sub, tone: p.behind > 0 ? 'warning' : 'neutral' };
});
const weekFigure = computed(() => {
  const w = week.value;
  if (props.tasksLoading) return { loading: true };
  if (props.tasksError) return { reason: '這週的任務載入失敗' };
  if (w.status === 'none') return { reason: '這週還沒有安排任務' };
  const all = w.done === w.total;
  return { value: `${w.done}/${w.total}`, unit: '項', percent: w.percent, sub: all ? '全部完成' : `還剩 ${w.total - w.done} 項`, tone: all ? 'success' : 'neutral' };
});
const attendanceFigure = computed(() => {
  const a = attendance.value;
  if (a.status === 'unavailable') return { reason: '出席資料還沒載入' };
  if (a.status === 'partial') return { reason: '本月紀錄太多，無法完整計算' };
  if (a.status === 'none') return { reason: '這個月還沒有點名紀錄' };
  return { value: `${a.percent}%`, percent: a.percent, sub: `到 ${a.came} 堂，共 ${a.total} 堂${props.teacherScope ? '・我的課' : ''}`, tone: 'neutral' };
});

// The shared Progress meter (8px, reka-ui): `class` re-colours the track, `indicatorClass` the fill. Only transform is transitioned.
const TRACK = 'tw:bg-[color:var(--ds-hairline)]';
const FILL = {
  neutral: 'tw:bg-foreground',
  warning: 'tw:bg-[color:var(--ds-warning-text)]',
  success: 'tw:bg-[color:var(--ds-success-text)]',
};
const INDICATOR = 'tw:rounded-full tw:transition-transform tw:duration-300 tw:ease-out tw:motion-reduce:transition-none';
const items = computed(() => [
  { key: 'plan', label: '計畫進度', fig: planFigure.value, wide: true },
  { key: 'week', label: '這週任務', fig: weekFigure.value },
  { key: 'attendance', label: '本月出席', fig: attendanceFigure.value },
]);
</script>

<template>
  <StatStrip data-testid="study-progress">
    <StatItem
      v-for="it in items" :key="it.key" :label="it.label" :value="it.fig.value ?? (it.fig.loading ? '…' : DASH)" :unit="it.fig.unit || ''"
      :tone="it.fig.tone || 'neutral'" :data-testid="`progress-${it.key}`" :aria-busy="it.fig.loading || undefined"
      :class="it.wide ? 'tw:max-md:col-span-2' : ''"
    >
      <template #sub>
        <Skeleton v-if="it.fig.loading" class="tw:mt-1 tw:h-2 tw:w-full tw:rounded-full" />
        <template v-else>
          <span class="tw:block tw:text-pretty" :data-testid="`progress-${it.key}-sub`">{{ it.fig.reason || it.fig.sub }}</span>
          <Progress v-if="it.fig.percent !== undefined" :model-value="it.fig.percent" aria-hidden="true" :class="['tw:mt-2', TRACK]" :indicator-class="[INDICATOR, FILL[it.fig.tone]]" :data-testid="`progress-${it.key}-bar`" />
        </template>
      </template>
    </StatItem>
  </StatStrip>
</template>
