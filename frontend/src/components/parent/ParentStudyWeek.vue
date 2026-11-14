<script setup>
// issue 5269 step 4: the family's read-only "讀書任務" in the parent portal. No write control exists here.
//
// STATE RULE: the server list is the only truth; nothing is kept as a draft. Each week change (and 再試一次) fetches that week;
// an answer that belongs to an older week, or to an older token (the parent switched child), is dropped. A failed fetch keeps the
// previous week's list off screen (it would be the wrong week) and shows one error with 再試一次. An empty week is not an error.
// The progress line is the week's own done / total, derived from the same list; no plan or attendance figure is shown
// here because the parent portal has no such data for the family today.
import { computed, ref, watch } from 'vue';
import { CalendarDays } from '@lucide/vue';
import { getParentStudyTasks } from '../../api';
import { ymdInTaipei } from '../../lib/sessionDates';
import { addDays, formatMd } from '../../lib/learningPlanGantt';
import { KIND_LABELS, dayLabel, groupByDay, weekDays, weekRangeText, weekStartOf, weekTaskProgress } from '../../lib/studyWeek';
import { Alert } from '../ui/alert';
import { Button } from '../ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '../ui/card';
import { Progress } from '../ui/progress';
import { Skeleton } from '../ui/skeleton';
import { StatusBadge } from '../ui/page';

const props = defineProps({
  token: { type: String, required: true },
});

const today = ref(ymdInTaipei());
const weekStart = ref(weekStartOf(today.value));
const tasks = ref([]);
const loading = ref(true);
const error = ref('');
let seq = 0;

async function load() {
  const my = ++seq;
  const start = weekStart.value;
  loading.value = true; error.value = ''; tasks.value = [];
  try {
    const body = await getParentStudyTasks(props.token, start);
    if (my !== seq) return;
    tasks.value = Array.isArray(body.data) ? body.data : [];
  } catch (e) {
    if (my === seq) error.value = e?.status === 401 ? '登入已過期，請重新登入後再看' : '讀書任務暫時載入不了，請再試一次';
  } finally {
    if (my === seq) loading.value = false;
  }
}

const days = computed(() => weekDays(weekStart.value));
// Only days that have something to do; an empty Monday adds nothing for a parent reading on a phone.
const dayRows = computed(() => {
  const byDay = groupByDay(tasks.value, days.value);
  return days.value.filter((d) => byDay[d].length).map((d) => ({ date: d, tasks: byDay[d] }));
});
const isThisWeek = computed(() => weekStart.value === weekStartOf(today.value));
const progress = computed(() => weekTaskProgress(tasks.value));
const progressText = computed(() => {
  const p = progress.value;
  if (p.status !== 'ok') return '';
  return p.done === p.total ? `這一週的 ${p.total} 項任務都完成了` : `已完成 ${p.done} 項，共 ${p.total} 項`;
});
const minutesText = (m) => (m ? `約 ${m} 分鐘` : '');
const doneText = (t) => `已完成（${formatMd(t.done_at)}）`;

const goTo = (start) => { weekStart.value = start; };
const prevWeek = () => goTo(addDays(weekStart.value, -7));
const nextWeek = () => goTo(addDays(weekStart.value, 7));
const thisWeek = () => { today.value = ymdInTaipei(); goTo(weekStartOf(today.value)); };

watch(() => props.token, () => { today.value = ymdInTaipei(); weekStart.value = weekStartOf(today.value); load(); });
watch(weekStart, load);
load();
</script>

<template>
  <Card class="tw:gap-3 tw:py-5 tw:shadow-none" data-testid="parent-study-week" :aria-busy="loading || undefined">
    <CardHeader class="tw:gap-1 tw:px-5">
      <CardTitle class="tw:m-0 tw:text-tpl-list tw:font-bold">讀書任務</CardTitle>
      <p class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary tw:tabular-nums" role="status" data-testid="week-label">{{ weekRangeText(weekStart) }}</p>
    </CardHeader>
    <CardContent class="tw:flex tw:flex-col tw:gap-3 tw:px-5">
      <div class="tw:grid tw:grid-cols-3 tw:gap-2">
        <Button variant="outline" class="tw:h-11 tw:text-tpl-body" data-testid="week-prev" @click="prevWeek">上一週</Button>
        <Button variant="outline" class="tw:h-11 tw:text-tpl-body" :disabled="isThisWeek" data-testid="week-today" @click="thisWeek">本週</Button>
        <Button variant="outline" class="tw:h-11 tw:text-tpl-body" data-testid="week-next" @click="nextWeek">下一週</Button>
      </div>

      <div v-if="loading" class="tw:flex tw:flex-col tw:gap-3" data-testid="study-loading" aria-label="讀書任務載入中">
        <Skeleton class="tw:h-6 tw:w-1/2" />
        <Skeleton class="tw:h-16 tw:w-full" />
        <Skeleton class="tw:h-16 tw:w-full" />
      </div>

      <Alert v-else-if="error" tone="danger" data-testid="study-error">
        <p class="tw:m-0 tw:text-tpl-body tw:text-pretty">{{ error }}</p>
        <Button variant="outline" class="tw:mt-3 tw:h-11 tw:text-tpl-body" data-testid="study-retry" @click="load">再試一次</Button>
      </Alert>

      <div v-else-if="!tasks.length" class="tw:flex tw:flex-col tw:items-center tw:gap-2 tw:py-6 tw:text-center" data-testid="study-empty">
        <CalendarDays class="tw:size-8 tw:text-tpl-secondary" aria-hidden="true" />
        <p class="tw:m-0 tw:text-tpl-body tw:font-semibold tw:text-pretty">這一週老師還沒有安排讀書任務</p>
      </div>

      <template v-else>
        <div class="tw:flex tw:flex-col tw:gap-2" data-testid="study-progress">
          <p class="tw:m-0 tw:text-tpl-body tw:font-semibold tw:tabular-nums">{{ progressText }}</p>
          <Progress :model-value="progress.percent" aria-hidden="true" class="tw:bg-[color:var(--ds-hairline)]" indicator-class="tw:rounded-full tw:bg-foreground tw:transition-transform tw:duration-300 tw:ease-out tw:motion-reduce:transition-none" />
        </div>
        <ol class="tw:m-0 tw:flex tw:list-none tw:flex-col tw:p-0" data-testid="study-days">
          <li v-for="row in dayRows" :key="row.date" :data-testid="`day-${row.date}`" :aria-current="row.date === today ? 'date' : undefined"
            class="tw:flex tw:flex-col tw:gap-2 tw:border-t tw:border-border tw:py-3">
            <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-x-2">
              <h4 class="tw:m-0 tw:text-tpl-body tw:font-bold tw:whitespace-nowrap tw:tabular-nums">{{ dayLabel(row.date) }}</h4>
              <StatusBadge v-if="row.date === today">今天</StatusBadge>
            </div>
            <ul class="tw:m-0 tw:flex tw:list-none tw:flex-col tw:gap-3 tw:p-0">
              <li v-for="t in row.tasks" :key="t.id" class="tw:flex tw:min-w-0 tw:flex-col tw:gap-1" data-testid="study-task">
                <p class="tw:m-0 tw:text-tpl-body tw:font-semibold tw:break-words tw:text-pretty">{{ t.title }}</p>
                <p class="tw:m-0 tw:flex tw:flex-wrap tw:items-center tw:gap-x-2 tw:gap-y-1 tw:text-tpl-caption tw:text-tpl-secondary">
                  <span class="tw:whitespace-nowrap">{{ KIND_LABELS[t.kind] || KIND_LABELS.other }}</span>
                  <span v-if="minutesText(t.minutes)" class="tw:whitespace-nowrap">{{ minutesText(t.minutes) }}</span>
                  <StatusBadge v-if="t.done_at" tone="success" data-testid="task-done">{{ doneText(t) }}</StatusBadge>
                  <span v-else class="tw:whitespace-nowrap" data-testid="task-open">還沒完成</span>
                </p>
              </li>
            </ul>
          </li>
        </ol>
      </template>
    </CardContent>
  </Card>
</template>
