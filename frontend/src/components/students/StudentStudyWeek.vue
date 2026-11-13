<script setup>
// issue 5269 step 2: the teacher's "本週讀書計畫" week view inside the student learning profile.
//
// STATE RULE (read before changing anything):
//  - The server list is the truth. The week's tasks are fetched by due date (Mon..Sun, Asia/Taipei); every successful
//    add / edit / move / delete re-fetches the week. Nothing is kept as a local draft: a form lives only while its dialog is open.
//  - A tick is the one optimistic change: it is applied at once, replaced by the server's task when the request succeeds,
//    and rolled back with a visible message when it fails.
//  - While a request for a task is in flight, that task's controls are disabled.
//  - A list response never overwrites a task that has a mutation in flight, or that a mutation response confirmed AFTER that
//    list request started (an older snapshot must not undo a confirmed tick, edit or delete). Each list request and each
//    confirmation takes a number from one counter; the list keeps the local version when its confirmation number is newer.
//  - Changing week (or student) drops every in-flight response that belongs to the old week.
//  - 複製上週: one POST for the shown week; the server skips what the week already has (same day, title and kind), so a repeat
//    click is harmless. The button is disabled while the request runs; the answer is shown as one line and the week is re-fetched.
//  - 套用到同班: the picker lives only while its dialog is open. The classmate list comes from the server; the ticked students are
//    the only local state, and a failed request keeps them so the teacher can press again. Duplicates are skipped by the server.
//  - A 403 on any write means this user may read but not write for this student: the write controls go away and one line says why.
import { computed, reactive, ref, watch } from 'vue';
import { CalendarDays, Plus } from '@lucide/vue';
import { authedFetch } from '../../lib/authedFetch';
import { ymdInTaipei } from '../../lib/sessionDates';
import { addDays, dateRangeText, summaryText } from '../../lib/learningPlanGantt';
import { KIND_LABELS, KIND_OPTIONS, dayLabel, groupByDay, unitsInWeek, weekDays, weekRangeText, weekStartOf } from '../../lib/studyWeek';
import ConfirmDialog from '../app-overlay/ConfirmDialog.vue';
import { SectionCard, StatusBadge, control } from '../ui/page';
import { Alert } from '../ui/alert';
import { Button } from '../ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '../ui/dialog';
import { Skeleton } from '../ui/skeleton';

const props = defineProps({ studentId: { type: [Number, String], required: true } });

const FIELD = 'tw:h-11 tw:w-full tw:min-w-0 tw:rounded-md tw:border tw:border-input tw:bg-background tw:px-3 tw:text-tpl-body tw:outline-none tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50';
const READ_ONLY_LINE = '你不是這位學生的授課老師，只能查看讀書計畫；要安排請由授課老師或主任操作。';

const today = ref(ymdInTaipei());
const weekStart = ref(weekStartOf(today.value));
const tasks = ref([]);
const plans = ref([]);
const loading = ref(true);
const loadError = ref('');
const planError = ref(false);
const actionError = ref('');
const notice = ref('');
const readOnly = ref(false);
const pending = ref({}); // task id -> true while a request for that task is in flight
const form = ref(null); // { id|null, title, kind, due_date, minutes, note, plan_unit_id }; null = dialog closed
const formError = ref('');
const saving = ref(false);
const deleting = ref(null);
const copying = ref(false);
// Picker for 套用到同班: null = closed. { task, students, max, selected: [ids], loading, error, submitting }
const picker = ref(null);
const lastPicker = ref(null); // what the closing dialog keeps showing while it fades out, so it never flashes empty
const view = computed(() => picker.value || lastPicker.value);

class ApiError extends Error {
  constructor(message, status) { super(message); this.status = status; }
}
async function api(path, options = {}) {
  const res = await authedFetch(`/api/v1${path}`, { ...options, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status === 403 ? READ_ONLY_LINE : body.message || `HTTP ${res.status}`, res.status);
  return body;
}

const days = computed(() => weekDays(weekStart.value));
const byDay = computed(() => groupByDay(tasks.value, days.value));
const isThisWeek = computed(() => weekStart.value === weekStartOf(today.value));
const doneCount = computed(() => tasks.value.filter((t) => t.done_at).length);
const activePlans = computed(() => plans.value.filter((p) => p.status === 'active'));
const weekUnits = computed(() => unitsInWeek(plans.value, weekStart.value));
const unitTitle = computed(() => Object.fromEntries(plans.value.flatMap((p) => (p.units || []).map((u) => [u.id, u.title]))));
const unitOptions = computed(() => activePlans.value.map((p) => ({ plan: p, units: [...(p.units || [])].sort((a, b) => a.sort_order - b.sort_order) })).filter((g) => g.units.length));

let loadSeq = 0;
let clock = 0; // one counter for list requests and mutation confirmations
const confirmedAt = {}; // task id -> { at, gone }: the last mutation the server confirmed for that task
const markConfirmed = (id, gone = false) => { confirmedAt[id] = { at: ++clock, gone }; };
/** The list answer, except for tasks whose local state is newer than the request (in flight, or confirmed since it started). */
function mergeList(list, started) {
  const local = new Map(tasks.value.map((t) => [t.id, t]));
  return list.flatMap((t) => {
    const c = confirmedAt[t.id];
    if (!pending.value[t.id] && !(c && c.at > started)) return [t];
    return c?.gone ? [] : [local.get(t.id) ?? t];
  });
}
/** Fetch the shown week. `keep` leaves the current list on screen (a reload after a write) instead of showing the skeleton. */
async function load({ keep = false } = {}) {
  const my = ++loadSeq;
  const id = props.studentId;
  const start = weekStart.value;
  const started = ++clock;
  if (!keep) { tasks.value = []; loading.value = true; }
  loadError.value = '';
  try {
    const body = await api(`/students/${id}/study-tasks?from=${start}&to=${addDays(start, 6)}`);
    if (my !== loadSeq) return; // another week (or student) was asked for since: this answer is stale
    tasks.value = Array.isArray(body.data) ? mergeList(body.data, started) : [];
  } catch (e) {
    if (my === loadSeq) loadError.value = e.status === 403 ? e.message : '讀書計畫載入失敗，請再試一次';
  } finally {
    if (my === loadSeq) loading.value = false;
  }
}

let planSeq = 0;
async function loadPlans() {
  const my = ++planSeq;
  planError.value = false;
  try {
    const body = await api(`/students/${props.studentId}/learning-plans`);
    if (my === planSeq) plans.value = Array.isArray(body.data) ? body.data : [];
  } catch { if (my === planSeq) planError.value = true; }
}

const goTo = (start) => { weekStart.value = start; notice.value = ''; actionError.value = ''; };
const prevWeek = () => goTo(addDays(weekStart.value, -7));
const nextWeek = () => goTo(addDays(weekStart.value, 7));
const thisWeek = () => { today.value = ymdInTaipei(); goTo(weekStartOf(today.value)); };

const setPending = (id, on) => { const next = { ...pending.value }; if (on) next[id] = true; else delete next[id]; pending.value = next; };
const patchLocal = (id, patch) => { tasks.value = tasks.value.map((t) => (t.id === id ? { ...t, ...patch } : t)); };
const markReadOnly = (e) => { if (e.status === 403) readOnly.value = true; };
const GONE_LINE = '這個任務已不存在，已重新整理';
/** A 404 on a write: the task was removed elsewhere. Say so and show the server's list again. */
async function handleGone() { actionError.value = GONE_LINE; await load({ keep: true }); }

async function toggle(task) {
  if (pending.value[task.id] || readOnly.value) return;
  const done = !task.done_at;
  const before = task.done_at;
  setPending(task.id, true);
  actionError.value = ''; notice.value = '';
  patchLocal(task.id, { done_at: done ? today.value : null });
  try {
    const body = await api(`/study-tasks/${task.id}`, { method: 'PATCH', body: JSON.stringify({ done }) });
    if (body.data) patchLocal(task.id, body.data);
    markConfirmed(task.id);
  } catch (e) {
    patchLocal(task.id, { done_at: before });
    markReadOnly(e);
    if (e.status === 404) await handleGone();
    else actionError.value = e.status === 403 ? e.message : `「${task.title}」沒有儲存成功，已還原。${e.message}`;
  } finally { setPending(task.id, false); }
}

function openAdd(date) {
  formError.value = '';
  form.value = { id: null, title: '', kind: 'homework', due_date: date, minutes: '', note: '', plan_unit_id: '' };
}
const openAddFirst = () => openAdd(isThisWeek.value ? today.value : weekStart.value);
function openEdit(task) {
  formError.value = '';
  form.value = { id: task.id, title: task.title, kind: task.kind, due_date: task.due_date, minutes: task.minutes ?? '', note: task.note ?? '', plan_unit_id: task.plan_unit_id ?? '' };
}
// The form cannot be dismissed while it is saving (Esc, the close button or Cancel), and a finished save closes only
// the form it was submitted from, so a slow save never discards a form the user opened afterwards.
const closeForm = () => { if (saving.value) return; form.value = null; formError.value = ''; };

async function submit() {
  const f = form.value;
  if (!f || saving.value || readOnly.value) return;
  const minutes = String(f.minutes ?? '').trim();
  if (!f.title.trim()) { formError.value = '請填任務名稱'; return; }
  if (!f.due_date) { formError.value = '請選日期'; return; }
  if (minutes && !(/^\d+$/.test(minutes) && +minutes >= 1 && +minutes <= 1440)) { formError.value = '預計分鐘請填 1 到 1440 的整數，或留空'; return; }
  const body = { title: f.title.trim(), kind: f.kind, due_date: f.due_date, minutes: minutes ? Number(minutes) : null, note: f.note.trim() || null, plan_unit_id: f.plan_unit_id ? Number(f.plan_unit_id) : null };
  saving.value = true; formError.value = ''; actionError.value = '';
  if (f.id) setPending(f.id, true);
  try {
    const res = await api(f.id ? `/study-tasks/${f.id}` : `/students/${props.studentId}/study-tasks`, { method: f.id ? 'PATCH' : 'POST', body: JSON.stringify(body) });
    if (f.id) { if (res.data) patchLocal(f.id, res.data); markConfirmed(f.id); }
  } catch (e) {
    markReadOnly(e);
    saving.value = false;
    if (f.id) setPending(f.id, false);
    if (e.status === 404 && f.id) { if (form.value === f) form.value = null; await handleGone(); return; }
    formError.value = e.message; // the dialog stays open with everything the user typed
    return;
  }
  const moved = f.id && !days.value.includes(body.due_date);
  if (form.value === f) form.value = null;
  notice.value = moved ? `已移到 ${dayLabel(body.due_date)}，不在這一週` : f.id ? '已儲存' : '已新增';
  try { await load({ keep: true }); } finally { saving.value = false; if (f.id) setPending(f.id, false); }
}

// The Action button closes the dialog and fires confirm in one click: clear after the click so confirmDelete still sees it.
const closeConfirm = (open) => { if (!open) queueMicrotask(() => { deleting.value = null; }); };
const askDelete = () => { const f = form.value; if (f?.id) deleting.value = tasks.value.find((t) => t.id === f.id) || { id: f.id, title: f.title }; };
async function confirmDelete() {
  const task = deleting.value;
  if (!task) return; // already closed
  deleting.value = null; form.value = null;
  setPending(task.id, true); actionError.value = ''; notice.value = '';
  try {
    await api(`/study-tasks/${task.id}`, { method: 'DELETE' });
    markConfirmed(task.id, true);
    notice.value = '已刪除';
    await load({ keep: true });
  } catch (e) {
    markReadOnly(e);
    if (e.status === 404) await handleGone();
    else actionError.value = e.status === 403 ? e.message : `「${task.title}」沒有刪除成功。${e.message}`;
  } finally { setPending(task.id, false); }
}

async function copyLastWeek() {
  if (copying.value || readOnly.value) return;
  const start = weekStart.value;
  const id = props.studentId;
  copying.value = true; actionError.value = ''; notice.value = '';
  try {
    const body = await api(`/students/${id}/study-tasks/copy-week`, { method: 'POST', body: JSON.stringify({ week_start: start }) });
    if (id !== props.studentId || start !== weekStart.value) return; // the user moved on; the new view loads itself
    const made = Array.isArray(body.data) ? body.data.length : 0;
    const skipped = Number(body.skipped) || 0;
    notice.value = made ? `已複製上週的 ${made} 個任務${skipped ? `，另有 ${skipped} 個這週已經有了` : ''}`
      : skipped ? '上週的任務這週都已經有了，沒有新增' : '上週沒有任務可以複製';
    await load({ keep: true });
  } catch (e) {
    markReadOnly(e);
    if (id === props.studentId && start === weekStart.value) actionError.value = e.status === 403 ? e.message : `沒有複製成功。${e.message}`;
  } finally { copying.value = false; }
}

async function openPicker(task) {
  if (!task || saving.value) return;
  form.value = null; formError.value = '';
  const p = reactive({ task, students: [], max: 30, selected: [], loading: true, error: '', submitting: false });
  picker.value = p; lastPicker.value = p;
  try {
    const body = await api(`/students/${props.studentId}/study-classmates`);
    if (picker.value !== p) return;
    p.students = Array.isArray(body.data) ? body.data : [];
    p.max = Number(body.max_targets) || 30;
  } catch (e) {
    if (picker.value !== p) return;
    markReadOnly(e);
    p.error = e.status === 403 ? e.message : '同班學生載入失敗，請關掉再試一次';
  } finally { if (picker.value === p) p.loading = false; }
}
const closePicker = () => { if (picker.value?.submitting) return; picker.value = null; };
const toggleMate = (id) => {
  const p = picker.value;
  if (!p || p.submitting) return;
  p.selected = p.selected.includes(id) ? p.selected.filter((x) => x !== id) : p.selected.length < p.max ? [...p.selected, id] : p.selected;
};
const allSelected = computed(() => !!picker.value && picker.value.students.length > 0 && picker.value.selected.length === Math.min(picker.value.students.length, picker.value.max));
const toggleAll = () => {
  const p = picker.value;
  if (!p || p.submitting) return;
  p.selected = allSelected.value ? [] : p.students.slice(0, p.max).map((s) => s.id);
};
async function applyPicked() {
  const p = picker.value;
  if (!p || p.submitting || !p.selected.length || readOnly.value) return;
  p.submitting = true; p.error = '';
  try {
    const body = await api(`/study-tasks/${p.task.id}/apply-to-students`, { method: 'POST', body: JSON.stringify({ student_ids: p.selected }) });
    const r = body.data || {};
    const parts = [`已套用到 ${r.created ?? 0} 位`];
    if (r.skipped) parts.push(`${r.skipped} 位本來就有這個任務`);
    if (r.refused) parts.push(`${r.refused} 位無法套用`);
    notice.value = parts.join('，');
    if (picker.value === p) picker.value = null;
  } catch (e) {
    markReadOnly(e);
    p.submitting = false;
    p.error = e.status === 404 ? '這個任務已不存在' : e.status === 403 ? e.message : `沒有套用成功，已選的學生還在，可以再按一次。${e.message}`;
  }
}

watch(() => props.studentId, () => {
  copying.value = false; picker.value = null; lastPicker.value = null;
  readOnly.value = false; plans.value = []; tasks.value = []; pending.value = {}; form.value = null; deleting.value = null;
  actionError.value = ''; notice.value = ''; today.value = ymdInTaipei(); weekStart.value = weekStartOf(today.value);
  loadPlans(); load();
}, { immediate: true });
watch(weekStart, () => load());
</script>

<template>
  <section class="tw:@container tw:space-y-3 tw:text-tpl-body" aria-label="本週讀書計畫" :aria-busy="loading || undefined" data-testid="study-week">
    <SectionCard title="讀書計畫" :count="tasks.length ? `${doneCount}/${tasks.length}` : null">
      <template #actions>
        <Button variant="outline" :class="control.row" aria-label="上一週" data-testid="week-prev" @click="prevWeek">上一週</Button>
        <span class="tw:min-w-24 tw:text-center tw:font-semibold tw:tabular-nums tw:max-md:order-first tw:max-md:basis-full tw:max-md:text-left" data-testid="week-label" role="status">{{ weekRangeText(weekStart) }}</span>
        <Button variant="outline" :class="control.row" aria-label="下一週" data-testid="week-next" @click="nextWeek">下一週</Button>
        <Button variant="outline" :class="control.row" :disabled="isThisWeek" data-testid="week-today" @click="thisWeek">本週</Button>
        <Button v-if="!readOnly" variant="outline" :class="control.row" :disabled="copying || loading" :aria-busy="copying || undefined" data-testid="copy-week" @click="copyLastWeek">{{ copying ? '複製中…' : '複製上週' }}</Button>
      </template>

      <div v-if="activePlans.length" class="tw:space-y-2 tw:border-t tw:border-border tw:px-5 tw:py-3" data-testid="week-plans">
        <div v-for="p in activePlans" :key="p.id" class="tw:space-y-0.5">
          <p class="tw:m-0 tw:font-semibold tw:break-words">{{ p.title }}</p>
          <p class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary tw:text-pretty tw:tabular-nums">{{ summaryText(p).percent }}・{{ summaryText(p).behind }}・{{ summaryText(p).exam }}</p>
        </div>
        <p v-if="weekUnits.length" class="tw:m-0 tw:text-tpl-caption tw:text-pretty" data-testid="week-units">
          <span class="tw:font-semibold">這週的單元：</span>{{ weekUnits.map((u) => `${u.title}（${dateRangeText(u)}）`).join('、') }}
        </p>
        <p v-else class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary" data-testid="week-units">這週學習計畫沒有排單元。</p>
      </div>
      <p v-else-if="planError" class="tw:m-0 tw:border-t tw:border-border tw:px-5 tw:py-3 tw:text-tpl-caption tw:text-tpl-secondary" role="status">學習計畫暫時載入不了，單元與段考倒數先不顯示；仍可安排任務。</p>

      <div v-if="loadError || actionError || readOnly || notice || (!readOnly && tasks.length)" class="tw:space-y-3 tw:border-t tw:border-border tw:px-5 tw:py-3">
        <Alert v-if="loadError" tone="danger" data-testid="load-error">
          <span>{{ loadError }}</span>
          <Button variant="outline" :class="[control.row, 'tw:ml-3']" @click="load({ keep: true })">再試一次</Button>
        </Alert>
        <Alert v-if="actionError" tone="danger" data-testid="action-error">{{ actionError }}</Alert>
        <Alert v-if="readOnly" tone="info" data-testid="read-only-note">{{ READ_ONLY_LINE }}</Alert>
        <p v-if="notice" class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary" role="status" data-testid="notice">{{ notice }}</p>
        <p v-if="!readOnly && tasks.length" class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary">點任務名稱可以修改內容或改到另一天。</p>
      </div>

      <div v-if="!loading && !loadError && !tasks.length" class="tw:flex tw:flex-col tw:items-center tw:gap-3 tw:border-t tw:border-border tw:px-5 tw:py-10 tw:text-center" data-testid="week-empty">
        <CalendarDays class="tw:size-8 tw:text-tpl-secondary" aria-hidden="true" />
        <p class="tw:m-0 tw:font-semibold">這週還沒有安排</p>
        <Button v-if="!readOnly" :class="control.page" data-testid="empty-add" @click="openAddFirst"><Plus aria-hidden="true" />新增任務</Button>
      </div>

      <div v-else-if="!loadError" class="tw:grid tw:grid-cols-1 tw:border-t tw:border-border tw:@4xl:grid-cols-7" data-testid="week-grid">
        <div v-for="d in days" :key="d" :data-testid="`day-${d}`" :aria-current="d === today ? 'date' : undefined"
          class="tw:min-w-0 tw:border-b tw:border-border tw:px-5 tw:py-2 tw:last:border-b-0 tw:@4xl:min-h-48 tw:@4xl:border-b-0 tw:@4xl:border-l tw:@4xl:px-2 tw:@4xl:first:border-l-0"
          :class="d === today ? 'tw:bg-[var(--ds-primary-wash)]' : ''">
          <div class="tw:flex tw:items-center tw:justify-between tw:gap-1">
            <div class="tw:flex tw:min-w-0 tw:flex-wrap tw:items-center tw:gap-x-2">
              <h4 class="tw:m-0 tw:text-tpl-body tw:font-bold tw:whitespace-nowrap tw:tabular-nums">{{ dayLabel(d) }}</h4>
              <StatusBadge v-if="d === today" class="tw:@4xl:hidden">今天</StatusBadge>
            </div>
            <Button v-if="!readOnly" variant="ghost" class="tw:size-11 tw:px-0 tw:shadow-none" :aria-label="`新增 ${dayLabel(d)} 的任務`" :data-testid="`add-${d}`" @click="openAdd(d)"><Plus class="tw:size-5" aria-hidden="true" /></Button>
          </div>
          <div class="tw:hidden tw:@4xl:block tw:@4xl:h-7"><StatusBadge v-if="d === today">今天</StatusBadge></div>
          <Skeleton v-if="loading" class="tw:mt-1 tw:h-16 tw:w-full" />
          <ul v-else-if="byDay[d].length" class="tw:m-0 tw:list-none tw:p-0">
            <li v-for="t in byDay[d]" :key="t.id" :data-testid="`task-${t.id}`" class="tw:group tw:flex tw:items-start tw:gap-1 tw:border-t tw:border-border tw:first:border-t-0">
              <label class="tw:flex tw:size-11 tw:shrink-0 tw:cursor-pointer tw:items-center tw:justify-center">
                <input type="checkbox" class="tw:size-6 tw:accent-primary" :checked="!!t.done_at" :disabled="readOnly || !!pending[t.id]" :aria-label="`${t.title} 已完成`" :data-testid="`done-${t.id}`" @change="toggle(t)" />
              </label>
              <component :is="readOnly ? 'div' : 'button'" :type="readOnly ? undefined : 'button'" :disabled="readOnly ? undefined : !!pending[t.id]" :aria-label="readOnly ? undefined : `修改：${t.title}`" :data-testid="`title-${t.id}`"
                class="tw:block tw:min-h-11 tw:min-w-0 tw:flex-1 tw:border-0 tw:bg-transparent tw:px-0 tw:py-2 tw:text-left tw:text-tpl-body tw:text-foreground tw:outline-none tw:enabled:cursor-pointer tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50"
                @click="!readOnly && openEdit(t)">
                <span class="tw:block tw:font-semibold tw:break-words tw:text-pretty" :class="[t.done_at ? 'tw:line-through tw:text-tpl-secondary' : '', readOnly ? '' : 'tw:group-hover:underline']">{{ t.title }}</span>
                <span class="tw:mt-1 tw:flex tw:flex-wrap tw:items-center tw:gap-x-2 tw:gap-y-1 tw:text-tpl-caption tw:font-normal tw:text-tpl-secondary">
                  <span class="tw:font-semibold">{{ KIND_LABELS[t.kind] || KIND_LABELS.other }}</span>
                  <span v-if="t.minutes" class="tw:tabular-nums tw:whitespace-nowrap">{{ t.minutes }} 分鐘</span>
                  <span v-if="t.plan_unit_id && unitTitle[t.plan_unit_id]" class="tw:break-words">{{ unitTitle[t.plan_unit_id] }}</span>
                </span>
                <span v-if="t.note" class="tw:mt-1 tw:block tw:text-tpl-caption tw:font-normal tw:text-tpl-secondary tw:text-pretty tw:break-words">{{ t.note }}</span>
              </component>
            </li>
          </ul>
          <p v-else class="tw:m-0 tw:py-2 tw:text-tpl-caption tw:text-tpl-secondary">沒有安排</p>
        </div>
      </div>
    </SectionCard>

    <Dialog :open="!!form" @update:open="(o) => { if (!o) closeForm(); }">
      <DialogContent class="tw:max-h-[calc(100dvh-1rem)] tw:overflow-y-auto tw:text-tpl-body">
        <DialogHeader>
          <DialogTitle class="tw:text-tpl-list">{{ form?.id ? '修改任務' : '新增任務' }}</DialogTitle>
          <DialogDescription class="tw:text-tpl-caption">{{ form?.id ? '改日期就是把任務移到另一天。' : '學生這天要完成的一件事。' }}</DialogDescription>
        </DialogHeader>
        <fieldset v-if="form" :disabled="saving" class="tw:m-0 tw:min-w-0 tw:space-y-3 tw:border-0 tw:p-0">
          <label class="tw:flex tw:flex-col tw:gap-1">任務名稱<input v-model="form.title" type="text" maxlength="120" autocomplete="off" :class="FIELD" data-testid="f-title" /></label>
          <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
            <label class="tw:flex tw:flex-col tw:gap-1">種類
              <select v-model="form.kind" :class="FIELD" data-testid="f-kind"><option v-for="k in KIND_OPTIONS" :key="k.value" :value="k.value">{{ k.label }}</option></select>
            </label>
            <label class="tw:flex tw:flex-col tw:gap-1">日期<input v-model="form.due_date" type="date" :class="FIELD" data-testid="f-date" /></label>
          </div>
          <label class="tw:flex tw:flex-col tw:gap-1">預計分鐘（選填）<input v-model="form.minutes" type="text" inputmode="numeric" autocomplete="off" :class="FIELD" data-testid="f-minutes" /></label>
          <label v-if="unitOptions.length" class="tw:flex tw:flex-col tw:gap-1">對應的單元（選填）
            <select v-model="form.plan_unit_id" :class="FIELD" data-testid="f-unit">
              <option value="">不對應單元</option>
              <optgroup v-for="g in unitOptions" :key="g.plan.id" :label="g.plan.title"><option v-for="u in g.units" :key="u.id" :value="u.id">{{ u.title }}</option></optgroup>
            </select>
          </label>
          <label class="tw:flex tw:flex-col tw:gap-1">備註（選填）<textarea v-model="form.note" maxlength="255" rows="2" :class="[FIELD, 'tw:h-auto tw:py-2']" data-testid="f-note"></textarea></label>
        </fieldset>
        <Alert v-if="formError" tone="danger" data-testid="form-error">{{ formError }}</Alert>
        <DialogFooter>
          <Button v-if="form?.id" variant="outline" :class="[control.page, 'tw:text-destructive tw:sm:mr-auto']" :disabled="saving || readOnly" data-testid="f-delete" @click="askDelete">刪除</Button>
          <Button v-if="form?.id" variant="outline" :class="control.page" :disabled="saving || readOnly" data-testid="f-apply" @click="openPicker(tasks.find((t) => t.id === form.id))">套用到同班其他學生</Button>
          <Button variant="outline" :class="control.page" :disabled="saving" @click="closeForm">取消</Button>
          <Button :class="control.page" :disabled="saving || readOnly" data-testid="f-save" @click="submit">儲存</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="!!picker" @update:open="(o) => { if (!o) closePicker(); }">
      <DialogContent class="tw:max-h-[calc(100dvh-1rem)] tw:overflow-y-auto tw:text-tpl-body" data-testid="apply-dialog">
        <DialogHeader>
          <DialogTitle class="tw:text-tpl-list">套用到同班其他學生</DialogTitle>
          <DialogDescription class="tw:text-tpl-caption tw:text-pretty">「{{ view?.task.title }}」（{{ view ? dayLabel(view.task.due_date) : '' }}）會同一天加到勾選的學生，不影響他們原有的任務。</DialogDescription>
        </DialogHeader>
        <div v-if="view" class="tw:space-y-2">
          <Skeleton v-if="view.loading" class="tw:h-24 tw:w-full" />
          <p v-else-if="!view.students.length && !view.error" class="tw:m-0 tw:py-3 tw:text-tpl-secondary" data-testid="apply-empty">這個班沒有其他學生可以套用。</p>
          <template v-else-if="view.students.length">
            <label class="tw:flex tw:min-h-11 tw:cursor-pointer tw:items-center tw:gap-3 tw:text-tpl-body tw:font-semibold">
              <input type="checkbox" class="tw:size-6 tw:accent-primary" :checked="allSelected" :disabled="view.submitting" data-testid="apply-all" @change="toggleAll" />全選
            </label>
            <ul class="tw:m-0 tw:max-h-72 tw:list-none tw:overflow-y-auto tw:border-y tw:border-border tw:p-0">
              <li v-for="s in view.students" :key="s.id" class="tw:border-b tw:border-border tw:last:border-b-0">
                <label class="tw:flex tw:min-h-11 tw:cursor-pointer tw:items-center tw:gap-3 tw:text-tpl-body tw:font-normal">
                  <input type="checkbox" class="tw:size-6 tw:accent-primary" :checked="view.selected.includes(s.id)" :disabled="view.submitting" :data-testid="`mate-${s.id}`" @change="toggleMate(s.id)" />
                  <span class="tw:min-w-0 tw:break-words">{{ s.name }}</span>
                </label>
              </li>
            </ul>
            <p v-if="view.students.length > view.max" class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary">一次最多 {{ view.max }} 位。</p>
          </template>
        </div>
        <Alert v-if="view?.error" tone="danger" data-testid="apply-error">{{ view.error }}</Alert>
        <DialogFooter>
          <Button variant="outline" :class="control.page" :disabled="view?.submitting" @click="closePicker">取消</Button>
          <Button :class="control.page" :disabled="!view || view.submitting || !view.selected.length || readOnly" :aria-busy="view?.submitting || undefined" data-testid="apply-go" @click="applyPicked">{{ view?.submitting ? '套用中…' : `套用到 ${view?.selected.length || 0} 位` }}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!deleting" title="刪除這個任務？" :description="deleting ? `「${deleting.title}」會從這位學生的讀書計畫移除，無法復原。` : ''" confirm-label="刪除" destructive @update:open="closeConfirm" @confirm="confirmDelete" />
  </section>
</template>
