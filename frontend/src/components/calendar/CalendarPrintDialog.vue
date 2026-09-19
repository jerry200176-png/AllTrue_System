<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { fetchAllPages } from '../../lib/pagedFetchAll';
import { fetchCalendarCoursesAndSchedulesParallel, fetchCalendarStudentClassesApi, fetchCalendarSchedulesApi } from '../../lib/calendarCourseLoad';
import { fetchClassSessionsProjection } from '../../lib/classSessionsApi';
import { getRange, projectPrintRows, filterPrintRows, summarizeRows, chunkPrintRows, serializePrintableRows, printPageStyle } from '../../lib/calendarPrint';

const props = defineProps({
  open: Boolean,
  branchId: [String, Number],
  branchName: { type: String, default: '' },
  rooms: { type: Array, default: () => [] },
  teachers: { type: Array, default: () => [] },
  initialDate: { type: String, default: '' },
});
const emit = defineEmits(['close']);
const period = ref('week');
const orientation = ref('landscape');
const currentDate = ref(props.initialDate || new Date().toISOString().slice(0, 10));
const loading = ref(false);
const error = ref('');
const allRows = ref([]);
const statusOptions = ['請假', '補課', '代課', '調課', '已取消'];
const defaultFilters = () => ({ teacher: '', room: '', student: '', statuses: [...statusOptions] });
const filters = ref(defaultFilters());
const requestToken = ref(0);
const opener = ref(null);
let printStyle = null;
let printFallbackTimer = null;

const range = computed(() => getRange(period.value, currentDate.value));
const rows = computed(() => filterPrintRows(allRows.value, filters.value));
const summary = computed(() => summarizeRows(rows.value, range.value, period.value));
const sheets = computed(() => chunkPrintRows(rows.value, orientation.value));
const activeFilterText = computed(() => {
  const values = [filters.value.teacher && `老師：${filters.value.teacher}`, filters.value.room && `教室：${filters.value.room}`, filters.value.student && `學生：${filters.value.student}`, filters.value.statuses.length < statusOptions.length && `狀態：${filters.value.statuses.join('、') || '無'}`].filter(Boolean);
  return values.length ? values.join('／') : '全部可見資料';
});
const canPrint = computed(() => !loading.value && !error.value && rows.value.length > 0 && allRows.value.length > 0);

function sessionToken() {
  try { return JSON.parse(localStorage.getItem('alltrue_session') || '{}')?.access_token || ''; } catch { return ''; }
}

async function load() {
  const token = ++requestToken.value;
  loading.value = true; error.value = ''; allRows.value = [];
  const selectedRange = range.value;
  try {
    const auth = sessionToken();
    if (!auth || !props.branchId) throw new Error('目前帳號無法讀取此分校課表。');
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const result = await fetchCalendarCoursesAndSchedulesParallel({
      fetchCourses: () => fetchCalendarStudentClassesApi({ baseUrl, token: auth, branchId: props.branchId, isTeacher: false, schedStart: selectedRange.start, schedEnd: selectedRange.end, fetchAllPages }),
      fetchSchedules: () => fetchCalendarSchedulesApi({ baseUrl, token: auth, branchId: props.branchId, isTeacher: false, schedStart: selectedRange.start, schedEnd: selectedRange.end }),
    });
    const projection = await fetchClassSessionsProjection({ token: auth, branchId: props.branchId, start: selectedRange.start, end: selectedRange.end });
    if (token !== requestToken.value || !props.open) return;
    if (!result.courses.apiSucceeded || !result.schedules.apiSucceeded) throw new Error('課表資料不完整，請重試。');
    if (result.schedules.list.length >= 5000) throw new Error('資料量過大，請縮小範圍或聯絡管理員。');
    const sessions = Object.values(projection.byClass || {}).flat();
    allRows.value = projectPrintRows({ courses: result.courses.list, schedules: result.schedules.list, sessions, rooms: props.rooms, teachers: props.teachers, range: selectedRange });
  } catch (cause) {
    if (token === requestToken.value) error.value = cause?.message || '課表資料暫時無法載入，請重試。';
  } finally {
    if (token === requestToken.value) loading.value = false;
  }
}

function clearFilters() { filters.value = defaultFilters(); }
function restoreFocus() { nextTick(() => opener.value?.focus?.()); }
function close() { requestToken.value += 1; cleanupPrint(); emit('close'); restoreFocus(); }
function cleanupPrint() {
  if (printFallbackTimer) { window.clearTimeout(printFallbackTimer); printFallbackTimer = null; }
  document.body.classList.remove('calendar-print-active');
  if (printStyle) { printStyle.remove(); printStyle = null; }
  window.removeEventListener('afterprint', onAfterPrint);
}
async function printReport() {
  if (!canPrint.value) return;
  serializePrintableRows(rows.value);
  cleanupPrint();
  printStyle = document.createElement('style');
  printStyle.dataset.calendarPrintPage = 'true';
  printStyle.textContent = printPageStyle(orientation.value);
  document.head.appendChild(printStyle);
  document.body.classList.add('calendar-print-active');
  await nextTick();
  window.addEventListener('afterprint', onAfterPrint, { once: true });
  try {
    window.print();
  } finally {
    // Some browsers do not emit afterprint when the dialog is cancelled.
    printFallbackTimer = window.setTimeout(cleanupPrint, 120000);
  }
}
function onAfterPrint() { cleanupPrint(); }
function onKeydown(event) { if (event.key === 'Escape') close(); }
watch(() => props.open, (open) => {
  if (open) { opener.value = document.activeElement; nextTick(() => document.querySelector('[data-calendar-print-dialog] button')?.focus()); load(); window.addEventListener('keydown', onKeydown); }
  else { cleanupPrint(); window.removeEventListener('keydown', onKeydown); restoreFocus(); }
});
watch([period, currentDate], () => { if (props.open) load(); });
onBeforeUnmount(() => { cleanupPrint(); window.removeEventListener('keydown', onKeydown); });
</script>

<template>
  <Teleport to="body">
    <div v-if="open" data-calendar-print-dialog class="calendar-print-dialog" role="dialog" aria-modal="true" aria-labelledby="calendar-print-title" aria-describedby="calendar-print-description">
      <div class="calendar-print-dialog__panel">
        <header class="calendar-print-controls">
          <div><h2 id="calendar-print-title">列印課表</h2><p id="calendar-print-description">主任人工核對用；僅顯示目前帳號可見的資料。</p></div>
          <div class="calendar-print-actions"><button type="button" @click="close">取消</button><button type="button" :disabled="!canPrint" @click="printReport">列印／另存 PDF</button></div>
        </header>
        <section class="calendar-print-toolbar" aria-label="列印設定">
          <label>範圍 <select v-model="period"><option value="week">本週</option><option value="month">當月</option></select></label>
          <label>日期 <input v-model="currentDate" type="date" /></label>
          <label>方向 <select v-model="orientation"><option value="landscape">橫向</option><option value="portrait">直向</option></select></label>
          <label>老師 <input v-model="filters.teacher" type="search" placeholder="全部老師" /></label>
          <label>教室 <input v-model="filters.room" type="search" placeholder="全部教室" /></label>
          <label>學生 <input v-model="filters.student" type="search" placeholder="搜尋學生" /></label>
          <fieldset class="calendar-print-statuses"><legend>狀態／異動（預設全選）</legend><label v-for="status in statusOptions" :key="status"><input v-model="filters.statuses" type="checkbox" :value="status" />{{ status }}</label></fieldset>
          <button type="button" @click="load">重新載入</button><button type="button" @click="clearFilters">清除篩選</button>
        </section>
        <p v-if="loading" class="calendar-print-state">正在準備{{ period === 'week' ? '本週' : '當月' }}課表…</p>
        <div v-else-if="error" class="calendar-print-state" role="alert">{{ error }} <button type="button" @click="load">重試</button></div>
        <div v-else-if="!allRows.length" class="calendar-print-state">此範圍沒有可列印的課程。</div>
        <div v-else-if="!rows.length" class="calendar-print-state">沒有符合篩選的課程。<button type="button" @click="clearFilters">清除篩選</button></div>
        <main v-else class="calendar-print-preview">
          <div v-for="sheet in sheets" :key="sheet.page" class="calendar-print-sheet">
            <div class="calendar-print-sheet__header"><strong>{{ branchName || `分校 #${branchId}` }}｜課表核對</strong><span>{{ range.start }} ～ {{ range.end }}｜{{ activeFilterText }}</span><span>內部人工核對用｜生成：{{ new Date().toLocaleString('zh-TW') }}｜第 {{ sheet.page }} / {{ sheet.pages }} 頁</span></div>
            <section v-if="sheet.kind === 'summary'" class="calendar-print-summary"><h3>{{ period === 'week' ? '週總覽' : '月總覽' }}</h3><div class="calendar-print-days"><article v-for="day in summary.days" :key="day.date"><b>{{ day.date }} {{ day.weekday }}</b><span>{{ day.count }} 堂</span><small>{{ day.exceptions.length ? day.exceptions.join('、') : '無異常' }}</small></article></div><p>明細合計：{{ summary.total }} 堂</p></section>
            <section v-else><h3>{{ sheet.date }} {{ sheet.continuation ? '（續）' : '' }}</h3><table><thead><tr><th>日期／星期</th><th>時間</th><th>學生</th><th>科目／班型</th><th>教師</th><th>校區／教室</th><th>狀態／異動</th></tr></thead><tbody><tr v-for="row in sheet.rows" :key="row.occurrenceKey"><td>{{ row.date }} {{ row.weekday }}</td><td>{{ row.startTime }}–{{ row.endTime }}</td><td>{{ row.studentName }}</td><td>{{ row.subjectName }}／{{ row.classTypeLabel }}</td><td>{{ row.effectiveTeacherName }}<span v-if="row.markers.includes('代課')">（代課）</span></td><td>{{ row.campusLabel }}／{{ row.roomLabel }}</td><td>{{ row.statusLabel }}<span v-if="row.markers.length">／{{ row.markers.join('、') }}</span></td></tr></tbody></table></section>
            <footer>本課表含學生姓名，僅供校內核對；列印後請妥善保管。</footer>
          </div>
        </main>
      </div>
    </div>
  </Teleport>
</template>

<style>
.calendar-print-dialog { position: fixed; inset: 0; z-index: 1000; background: rgba(15, 23, 42, .58); overflow: auto; padding: 24px; }
.calendar-print-dialog__panel { max-width: 1280px; margin: auto; background: #fff; border-radius: 10px; padding: 20px; color: #172033; }
.calendar-print-controls, .calendar-print-toolbar, .calendar-print-actions { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
.calendar-print-toolbar { justify-content: flex-start; padding: 14px 0; border-bottom: 1px solid #d7dce5; }
.calendar-print-toolbar label { display: inline-flex; gap: 5px; align-items: center; font-size: 13px; }
.calendar-print-toolbar input, .calendar-print-toolbar select, .calendar-print-toolbar button, .calendar-print-actions button { min-height: 32px; border: 1px solid #aeb7c5; border-radius: 5px; background: #fff; padding: 4px 9px; }
.calendar-print-statuses { display: inline-flex; gap: 7px; align-items: center; border: 1px solid #aeb7c5; border-radius: 5px; padding: 4px 8px; margin: 0; }
.calendar-print-statuses legend { font-size: 11px; }
.calendar-print-actions button:last-child { background: #155eef; color: #fff; border-color: #155eef; }
.calendar-print-state { margin: 28px 0; padding: 20px; background: #f5f7fa; }
.calendar-print-preview { margin-top: 18px; }
.calendar-print-sheet { background: white; padding: 18px 0; break-after: page; page-break-after: always; }
.calendar-print-sheet:last-child { break-after: auto; page-break-after: auto; }
.calendar-print-sheet__header { display: grid; gap: 3px; font-size: 12px; margin-bottom: 10px; }
.calendar-print-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
.calendar-print-days article { border: 1px solid #adb6c5; padding: 7px; min-height: 65px; display: grid; gap: 3px; }
.calendar-print-days span { font-weight: 700; } .calendar-print-days small { color: #4b5563; }
.calendar-print-sheet table { width: 100%; border-collapse: collapse; font-size: 11px; }
.calendar-print-sheet th, .calendar-print-sheet td { border: 1px solid #aeb7c5; padding: 5px; text-align: left; vertical-align: top; }
.calendar-print-sheet thead { display: table-header-group; } .calendar-print-sheet tr { break-inside: avoid; page-break-inside: avoid; }
.calendar-print-sheet footer { margin-top: 12px; font-size: 11px; color: #4b5563; }
@media print { body.calendar-print-active > *:not(.calendar-print-dialog) { display: none !important; } body.calendar-print-active .calendar-print-dialog { position: static; padding: 0; background: #fff; overflow: visible; } body.calendar-print-active .calendar-print-dialog__panel { padding: 0; max-width: none; } body.calendar-print-active .calendar-print-controls, body.calendar-print-active .calendar-print-toolbar, body.calendar-print-active .calendar-print-state { display: none !important; } }
</style>
