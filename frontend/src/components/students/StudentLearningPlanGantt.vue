<script setup>
// #4970 step 4b: per-student learning-plan Gantt (pure CSS grid). Read-only bars + a list with the controls.
import { computed, nextTick, ref, watch } from 'vue';
import { Check, CircleDashed, Clock, TriangleAlert } from '@lucide/vue';
import { authedFetch } from '../../lib/authedFetch';
import { GRADES, SUBJECTS } from '../../lib/constants';
import { ymdInTaipei } from '../../lib/sessionDates';
import {
  STATE_LABELS, barSpan, buildTimeline, dateRangeText, examMarkers, markerPercent, summaryText, unitAriaText, unitState,
} from '../../lib/learningPlanGantt';
import ConfirmDialog from '../app-overlay/ConfirmDialog.vue';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '../ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../ui/dialog';
import { Skeleton } from '../ui/skeleton';

const props = defineProps({
  studentId: { type: [Number, String], required: true },
  branchId: { type: [Number, String], default: null },
  studentGrade: { type: String, default: '' },
});

const FIELD = 'tw:h-11 tw:w-full tw:min-w-0 tw:rounded-md tw:border tw:bg-background tw:px-2 tw:text-sm';
const TITLE_W = 'var(--gantt-title-w)';
const COL_W = 'var(--gantt-col-w)';
const BAR = {
  done: 'tw:bg-(--ds-success-wash) tw:border-(--ds-success-line) tw:text-(--ds-success-text)',
  behind: 'tw:bg-destructive/15 tw:border-destructive tw:border-dashed tw:text-destructive',
  in_progress: 'tw:bg-primary tw:border-primary tw:text-primary-foreground',
  upcoming: 'tw:bg-background tw:border-border tw:text-muted-foreground',
};
const ICON = { done: Check, behind: TriangleAlert, in_progress: Clock, upcoming: CircleDashed };

const courses = ref([]);
const plans = ref([]);
const loading = ref(false);
const loadError = ref('');
const actionError = ref('');
const readOnly = ref(false);
const saving = ref(false);
const today = ref(ymdInTaipei());
const applying = ref(null); // { course, templates, templateId, start, end, loading, note }
const editingUnit = ref(null); // { plan, unit, start, end }
const editingRange = ref(null); // { plan, start, end }
const archiving = ref(null);

class ApiError extends Error {
  constructor(message, status) { super(message); this.status = status; }
}
async function api(path, options = {}) {
  const res = await authedFetch(`/api/v1${path}`, {
    ...options,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status === 403 ? '你沒有這位學生學習計畫的權限' : body.message || `HTTP ${res.status}`, res.status);
  return body;
}

const courseLabel = (c) => [c.subject_name || c.subject, c.teacher_name].filter(Boolean).join('・') || `課程 ${c.id}`;
const sections = computed(() => {
  const list = courses.value.map((c) => ({ key: c.id, course: c, label: courseLabel(c), plan: plans.value.find((p) => Number(p.student_class_id) === Number(c.id)) || null }));
  const known = new Set(courses.value.map((c) => Number(c.id)));
  for (const p of plans.value) if (!known.has(Number(p.student_class_id))) list.push({ key: `plan-${p.id}`, course: null, label: p.title, plan: p });
  return list;
});
const chart = (plan) => {
  const timeline = buildTimeline(plan.plan_start, plan.plan_end);
  const units = [...(plan.units || [])].sort((a, b) => a.sort_order - b.sort_order);
  return { timeline, units, markers: examMarkers(timeline, plan), todayPercent: markerPercent(timeline, today.value) };
};

// Open the chart with the today line about a third from the left of the visible track (start if today is before the plan, end if after).
const scrollers = new Map();
const setScroller = (id, el) => { if (el) scrollers.set(id, el); else scrollers.delete(id); };
function scrollToToday(plan) {
  const el = scrollers.get(plan.id);
  if (!el) return;
  const title = el.querySelector('[data-testid="gantt-title"]')?.parentElement?.offsetWidth || 0;
  const track = el.scrollWidth - title;
  const pct = markerPercent(buildTimeline(plan.plan_start, plan.plan_end), today.value);
  if (pct === null) { el.scrollLeft = today.value < plan.plan_start ? 0 : el.scrollWidth; return; }
  el.scrollLeft = Math.max(0, (pct / 100) * track - (el.clientWidth - title) / 3);
}
const scrollAllToToday = async () => { await nextTick(); plans.value.forEach(scrollToToday); };

let loadSeq = 0;
async function load({ keepError = false } = {}) {
  const my = ++loadSeq;
  const id = props.studentId;
  today.value = ymdInTaipei();
  loading.value = true;
  loadError.value = '';
  if (!keepError) actionError.value = '';
  try {
    const [c, p] = await Promise.all([api(`/student-classes?student_id=${id}&per_page=100`), api(`/students/${id}/learning-plans`)]);
    if (my !== loadSeq) return;
    const list = c.data?.data ?? c.data ?? c;
    courses.value = (Array.isArray(list) ? list : []).filter((x) => x.status !== 'inactive');
    plans.value = p.data || [];
    scrollAllToToday();
  } catch (e) {
    if (my === loadSeq) loadError.value = e.message || '載入失敗，請稍後再試';
  } finally {
    if (my === loadSeq) loading.value = false;
  }
}

/** One write, then reload. A 403 means this user cannot write here: hide the write controls from then on. */
async function write(path, method, body) {
  if (saving.value) return false;
  saving.value = true;
  actionError.value = '';
  try {
    await api(path, { method, body: JSON.stringify(body) });
  } catch (e) {
    if (e.status === 403) { readOnly.value = true; actionError.value = '你不是這門課的授課老師，只能查看學習計畫'; } else actionError.value = e.message;
    saving.value = false;
    return false;
  }
  try { await load({ keepError: true }); } finally { saving.value = false; }
  return true;
}

async function toggle(plan, unit, event) {
  const checked = event.target.checked;
  if (!(await write(`/learning-plans/${plan.id}/units/${unit.id}`, 'PATCH', { completed: checked }))) event.target.checked = !checked;
}

let templateSeq = 0;
async function openApply(course) {
  const my = ++templateSeq;
  const subject = SUBJECTS.some((s) => s.value === course.subject) ? course.subject : '';
  const grade = GRADES.some((g) => g.value === props.studentGrade) ? props.studentGrade : '';
  actionError.value = '';
  applying.value = { course, templates: [], templateId: '', start: today.value, end: '', loading: true, note: '' };
  const fetchTemplates = (filters) => api(`/curriculum-templates?${new URLSearchParams({ campus_id: String(props.branchId), status: 'active', ...filters })}`);
  try {
    let list = (await fetchTemplates({ ...(subject && { subject }), ...(grade && { grade }) })).data || [];
    let note = '';
    if (!list.length && (subject || grade)) {
      list = (await fetchTemplates({})).data || [];
      note = list.length ? '沒有符合這門課科目與年級的範本，以下列出所有使用中的範本。' : '';
    }
    if (my === templateSeq && applying.value) Object.assign(applying.value, { templates: list, templateId: list[0]?.id ?? '', note, loading: false });
  } catch (e) {
    if (my === templateSeq && applying.value) { applying.value.loading = false; actionError.value = e.message; }
  }
}
async function submitApply() {
  const a = applying.value;
  if (!a || !a.templateId || !a.start) { actionError.value = '請選擇範本與開始日期'; return; }
  const body = { student_class_id: a.course.id, template_id: Number(a.templateId), plan_start: a.start, ...(a.end && { plan_end: a.end }) };
  if (await write(`/students/${props.studentId}/learning-plans`, 'POST', body)) applying.value = null;
}
function closeApply() { templateSeq += 1; applying.value = null; actionError.value = ''; }

const openUnit = (plan, unit) => { actionError.value = ''; editingUnit.value = { plan, unit, start: unit.planned_start, end: unit.planned_end }; };
async function submitUnit(body) {
  const e = editingUnit.value;
  if (!e || (!body && (!e.start || !e.end))) { actionError.value = '請填開始日與結束日'; return; }
  if (await write(`/learning-plans/${e.plan.id}/units/${e.unit.id}`, 'PATCH', body || { planned_start: e.start, planned_end: e.end })) editingUnit.value = null;
}
const openRange = (plan) => { actionError.value = ''; editingRange.value = { plan, start: plan.plan_start, end: plan.plan_end }; };
async function submitRange() {
  const r = editingRange.value;
  if (!r || !r.start || !r.end) { actionError.value = '請填計畫開始日與結束日'; return; }
  if (await write(`/learning-plans/${r.plan.id}`, 'PATCH', { plan_start: r.start, plan_end: r.end })) editingRange.value = null;
}
// The Action button closes the dialog and fires confirm in one click: clear after the click so confirmArchive still sees it.
const closeConfirm = (open) => { if (!open) queueMicrotask(() => { archiving.value = null; }); };
async function confirmArchive() {
  const plan = archiving.value;
  if (!plan) return; // already closed
  archiving.value = null;
  await write(`/learning-plans/${plan.id}`, 'PATCH', { status: 'archived' });
}
const closeEdit = () => { editingUnit.value = null; editingRange.value = null; actionError.value = ''; };

watch(() => props.studentId, () => { readOnly.value = false; courses.value = []; plans.value = []; load(); }, { immediate: true });
</script>

<template>
  <section class="tw:space-y-4" aria-label="學習計畫" :aria-busy="loading || undefined">
    <div v-if="loading && !sections.length" class="tw:space-y-2" role="status" aria-label="學習計畫載入中">
      <Skeleton v-for="n in 3" :key="n" class="tw:h-16 tw:w-full" />
    </div>
    <div v-else-if="loadError" role="alert" class="tw:space-y-2">
      <p>{{ loadError }}</p>
      <Button variant="outline" class="tw:min-h-11" @click="load()">重新載入</Button>
    </div>
    <p v-else-if="!sections.length" class="tw:text-muted-foreground">這位學生目前沒有進行中的課程，還不能建立學習計畫。</p>
    <p v-if="actionError && !applying && !editingUnit && !editingRange" role="alert" class="tw:text-destructive">{{ actionError }}</p>
    <p v-if="readOnly" role="status" class="tw:text-muted-foreground">你只能查看這份學習計畫；要修改請由授課老師或主任操作。</p>

    <Card v-for="s in sections" :key="s.key" :data-testid="`plan-section-${s.key}`" class="tw:gap-3 tw:py-4">
      <CardHeader class="tw:px-4">
        <CardTitle class="tw:text-base tw:break-words">{{ s.label }}<template v-if="s.plan && s.plan.title !== s.label">・{{ s.plan.title }}</template></CardTitle>
      </CardHeader>
      <CardContent class="tw:space-y-3 tw:px-4">
        <template v-if="!s.plan">
          <p>這門課還沒有學習計畫。</p>
          <Button v-if="!readOnly && s.course" class="tw:min-h-11" :disabled="saving" @click="openApply(s.course)">套用教材範本</Button>
        </template>
        <template v-else>
          <template v-for="c in [chart(s.plan)]" :key="s.plan.id">
            <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2" data-testid="plan-summary">
              <Badge variant="secondary">{{ summaryText(s.plan).percent }}</Badge>
              <Badge :variant="s.plan.units_behind ? 'destructive' : 'outline'">{{ summaryText(s.plan).behind }}</Badge>
              <Badge variant="outline">{{ summaryText(s.plan).exam }}</Badge>
              <span class="tw:text-sm tw:text-muted-foreground">計畫期間 {{ s.plan.plan_start }} 至 {{ s.plan.plan_end }}</span>
            </div>
            <div v-if="!readOnly" class="tw:flex tw:flex-wrap tw:gap-2">
              <Button variant="outline" class="tw:min-h-11" :disabled="saving" @click="openRange(s.plan)">調整計畫期間</Button>
              <Button variant="outline" class="tw:min-h-11" :disabled="saving" @click="archiving = s.plan">封存計畫</Button>
            </div>
            <p v-if="!c.units.length" class="tw:text-muted-foreground">這份計畫沒有單元。</p>
            <template v-else>
              <div class="tw:flex tw:items-center tw:justify-between tw:gap-2">
                <p class="tw:text-xs tw:text-muted-foreground">每格一週，數字是該週開始的日期</p>
                <Button size="sm" variant="outline" class="tw:min-h-11" @click="scrollToToday(s.plan)">回到今天</Button>
              </div>
              <div :ref="(el) => setScroller(s.plan.id, el)" class="tw:[--gantt-title-w:7rem] tw:[--gantt-col-w:2rem] tw:[--gantt-row-h:3.5rem] tw:sm:[--gantt-title-w:14rem] tw:sm:[--gantt-col-w:2.25rem] tw:sm:[--gantt-row-h:4.5rem] tw:[scrollbar-width:thin] tw:overflow-x-auto tw:rounded-md tw:border tw:border-border tw:bg-card" role="region" tabindex="0" :aria-label="`${s.label} 學習計畫甘特圖，可左右捲動`" data-testid="gantt-scroll">
                <div class="tw:relative" :style="{ minWidth: `calc(${TITLE_W} + ${c.timeline.weeks} * ${COL_W})` }">
                  <div class="tw:pointer-events-none tw:absolute tw:inset-y-0 tw:right-0" :style="{ left: TITLE_W }" aria-hidden="true">
                    <span v-if="c.todayPercent !== null" data-testid="today-line" class="tw:absolute tw:inset-y-0 tw:border-l-2 tw:border-primary" :style="{ left: `${c.todayPercent}%` }" />
                    <span v-for="m in c.markers" :key="m.date" data-testid="exam-marker" class="tw:absolute tw:inset-y-0 tw:border-l-2 tw:border-dashed tw:border-destructive" :style="{ left: `${m.percent}%` }" />
                  </div>
                  <div class="tw:grid tw:border-b tw:border-border tw:text-xs" :style="{ gridTemplateColumns: `${TITLE_W} 1fr` }">
                    <div class="tw:sticky tw:left-0 tw:z-10 tw:flex tw:items-end tw:bg-card tw:p-2 tw:font-medium">單元</div>
                    <div>
                      <div class="tw:grid" :style="{ gridTemplateColumns: `repeat(${c.timeline.weeks}, minmax(0, 1fr))` }">
                        <span v-for="m in c.timeline.months" :key="m.startColumn" class="tw:truncate tw:border-l tw:border-border tw:px-1 tw:pt-1 tw:text-sm tw:font-medium" :style="{ gridColumn: `${m.startColumn} / span ${m.span}` }">{{ m.label }}</span>
                      </div>
                      <div class="tw:grid" :style="{ gridTemplateColumns: `repeat(${c.timeline.weeks}, minmax(0, 1fr))` }">
                        <span v-for="w in c.timeline.columns" :key="w.index" class="tw:border-l tw:border-border tw:px-1 tw:tabular-nums tw:text-muted-foreground">{{ Number(w.date.slice(8)) }}</span>
                      </div>
                      <div class="tw:relative tw:h-7">
                        <span v-if="c.todayPercent !== null" class="tw:absolute tw:top-1 tw:z-[1] tw:-translate-x-1/2 tw:whitespace-nowrap tw:rounded-full tw:bg-primary tw:px-2 tw:py-0.5 tw:font-medium tw:text-primary-foreground" :style="{ left: `${c.todayPercent}%` }">今天</span>
                        <span v-for="m in c.markers" :key="m.date" class="tw:absolute tw:top-1 tw:z-[1] tw:-translate-x-1/2 tw:whitespace-nowrap tw:rounded-full tw:bg-destructive tw:px-2 tw:py-0.5 tw:font-medium tw:text-white" :style="{ left: `${m.percent}%` }">{{ m.label }}</span>
                      </div>
                    </div>
                  </div>
                  <div v-for="(u, i) in c.units" :key="u.id" class="tw:grid tw:h-(--gantt-row-h) tw:items-center tw:border-b tw:border-border last:tw:border-b-0" :style="{ gridTemplateColumns: `${TITLE_W} 1fr` }" data-testid="gantt-row">
                    <div class="tw:sticky tw:left-0 tw:z-10 tw:flex tw:h-full tw:min-w-0 tw:flex-col tw:justify-center tw:gap-0.5 tw:border-r tw:border-border tw:bg-card tw:px-2 tw:text-[0.8125rem] tw:sm:text-sm">
                      <p class="tw:line-clamp-2 tw:break-all tw:leading-tight" data-testid="gantt-title" aria-hidden="true" :title="u.title"><span class="tw:tabular-nums tw:text-muted-foreground">{{ i + 1 }}.</span> {{ u.title }}</p>
                      <p class="tw:flex tw:items-center tw:gap-1 tw:whitespace-nowrap tw:text-xs" aria-hidden="true">
                        <component :is="ICON[unitState(u, today)]" class="tw:size-3 tw:shrink-0" />{{ STATE_LABELS[unitState(u, today)] }}
                        <Badge v-if="u.dates_manual" variant="outline" class="tw:px-1.5 tw:py-0">手動</Badge>
                      </p>
                      <span class="tw:sr-only">{{ unitAriaText(u, i, today) }}</span>
                    </div>
                    <div class="tw:grid tw:h-full tw:items-center" aria-hidden="true" :style="{ gridTemplateColumns: `repeat(${c.timeline.weeks}, minmax(0, 1fr))`, backgroundImage: `repeating-linear-gradient(to right, var(--ds-border) 0 1px, transparent 1px calc(100% / ${c.timeline.weeks}))` }">
                      <span class="tw:flex tw:h-7 tw:items-center tw:gap-1 tw:overflow-hidden tw:rounded-md tw:border tw:px-1.5 tw:text-xs tw:tabular-nums"
                        :class="BAR[unitState(u, today)]" :data-state="unitState(u, today)" data-testid="gantt-bar"
                        :style="{ gridColumn: `${barSpan(c.timeline, u).colStart} / ${barSpan(c.timeline, u).colEnd}` }"
                        :title="`${u.title} ${dateRangeText(u)}`"><component :is="ICON[unitState(u, today)]" class="tw:size-3.5 tw:shrink-0" /><span v-if="barSpan(c.timeline, u).colEnd - barSpan(c.timeline, u).colStart >= 3" class="tw:truncate">{{ dateRangeText(u) }}</span></span>
                    </div>
                  </div>
                </div>
              </div>
              <p v-if="!s.plan.next_exam" class="tw:text-xs tw:text-muted-foreground">圖上只標示下一次段考；計畫期間內沒有後續段考。</p>
              <p v-else class="tw:text-xs tw:text-muted-foreground">圖上只標示下一次段考。</p>

              <ul class="tw:m-0 tw:list-none tw:space-y-2 tw:p-0" :aria-label="`${s.label} 單元清單`" data-testid="unit-list">
                <li v-for="(u, i) in c.units" :key="u.id" class="tw:space-y-2 tw:rounded-md tw:border tw:border-border tw:p-3" data-testid="unit-item">
                  <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:break-words">
                    <strong>{{ i + 1 }}. {{ u.title }}</strong>
                    <Badge v-if="u.dates_manual" variant="outline">手動</Badge>
                  </div>
                  <p class="tw:flex tw:items-center tw:gap-1 tw:text-sm">
                    <component :is="ICON[unitState(u, today)]" class="tw:size-4" aria-hidden="true" />
                    <span>{{ dateRangeText(u) }}・{{ STATE_LABELS[unitState(u, today)] }}</span>
                  </p>
                  <fieldset v-if="!readOnly" :disabled="saving" class="tw:m-0 tw:flex tw:flex-wrap tw:items-center tw:gap-3 tw:border-0 tw:p-0">
                    <label class="tw:flex tw:min-h-11 tw:items-center tw:gap-2 tw:leading-none">
                      <input type="checkbox" class="tw:size-5" :checked="!!u.completed_at" :aria-label="`第 ${i + 1} 單元 ${u.title} 已完成`" @change="toggle(s.plan, u, $event)" />
                      已完成
                    </label>
                    <Button variant="outline" class="tw:min-h-11" @click="openUnit(s.plan, u)">調整日期</Button>
                  </fieldset>
                </li>
              </ul>
            </template>
          </template>
        </template>
      </CardContent>
    </Card>

    <Dialog :open="!!applying" @update:open="(o) => { if (!o) closeApply(); }">
      <DialogContent class="tw:max-h-[calc(100dvh-1rem)] tw:overflow-y-auto">
        <DialogHeader>
          <DialogTitle>套用教材範本</DialogTitle>
          <DialogDescription>範本的單元會複製到這門課，並依學校段考日期自動排好日期。</DialogDescription>
        </DialogHeader>
        <fieldset v-if="applying" :disabled="saving" class="tw:m-0 tw:min-w-0 tw:space-y-3 tw:border-0 tw:p-0">
          <p v-if="applying.loading" role="status">範本載入中…</p>
          <p v-else-if="!applying.templates.length">目前沒有使用中的教材範本，請先請主任到段考成績頁的「教材範本庫」建立。</p>
          <p v-if="applying.note" role="status" class="tw:text-sm tw:text-muted-foreground">{{ applying.note }}</p>
          <label v-if="applying.templates.length" class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">教材範本
            <select v-model="applying.templateId" :class="FIELD">
              <option v-for="t in applying.templates" :key="t.id" :value="t.id">{{ t.title }}（{{ t.units_count }} 個單元）</option>
            </select>
          </label>
          <label class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">計畫開始日<input v-model="applying.start" type="date" :class="FIELD" /></label>
          <label class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">計畫結束日（選填，不填則排 18 週）<input v-model="applying.end" type="date" :class="FIELD" /></label>
          <p v-if="actionError" role="alert" class="tw:text-sm tw:text-destructive">{{ actionError }}</p>
        </fieldset>
        <DialogFooter>
          <Button variant="outline" class="tw:min-h-11" :disabled="saving" @click="closeApply">取消</Button>
          <Button class="tw:min-h-11" :disabled="saving || !applying?.templates.length" @click="submitApply">套用</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="!!editingUnit" @update:open="(o) => { if (!o) closeEdit(); }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>調整單元日期</DialogTitle>
          <DialogDescription>{{ editingUnit?.unit.title }}。手動排好的單元不會被自動排程移動，日期可以和其他單元重疊。</DialogDescription>
        </DialogHeader>
        <fieldset v-if="editingUnit" :disabled="saving" class="tw:m-0 tw:min-w-0 tw:space-y-3 tw:border-0 tw:p-0">
          <label class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">開始日<input v-model="editingUnit.start" type="date" :class="FIELD" /></label>
          <label class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">結束日<input v-model="editingUnit.end" type="date" :class="FIELD" /></label>
          <p v-if="actionError" role="alert" class="tw:text-sm tw:text-destructive">{{ actionError }}</p>
        </fieldset>
        <DialogFooter>
          <Button v-if="editingUnit?.unit.dates_manual" variant="outline" class="tw:min-h-11" :disabled="saving" @click="submitUnit({ dates_manual: false })">恢復自動排程</Button>
          <Button variant="outline" class="tw:min-h-11" :disabled="saving" @click="closeEdit">取消</Button>
          <Button class="tw:min-h-11" :disabled="saving" @click="submitUnit()">儲存</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="!!editingRange" @update:open="(o) => { if (!o) closeEdit(); }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>調整計畫期間</DialogTitle>
          <DialogDescription>改了之後，自動排程的單元會重新排日期；手動排好的單元不會動。</DialogDescription>
        </DialogHeader>
        <fieldset v-if="editingRange" :disabled="saving" class="tw:m-0 tw:min-w-0 tw:space-y-3 tw:border-0 tw:p-0">
          <label class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">計畫開始日<input v-model="editingRange.start" type="date" :class="FIELD" /></label>
          <label class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">計畫結束日<input v-model="editingRange.end" type="date" :class="FIELD" /></label>
          <p v-if="actionError" role="alert" class="tw:text-sm tw:text-destructive">{{ actionError }}</p>
        </fieldset>
        <DialogFooter>
          <Button variant="outline" class="tw:min-h-11" :disabled="saving" @click="closeEdit">取消</Button>
          <Button class="tw:min-h-11" :disabled="saving" @click="submitRange">儲存</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      :open="!!archiving"
      title="封存這份學習計畫？"
      description="封存後這門課不再顯示這份計畫，之後可以重新套用範本建立新的計畫。"
      confirm-label="封存"
      destructive
      :loading="saving"
      @update:open="closeConfirm"
      @confirm="confirmArchive"
    />
  </section>
</template>
