<template>
  <article class="schedule-coordination-card" aria-live="polite" data-testid="teacher-availability-planner">
    <div class="schedule-coordination-heading">
      <div>
        <h5><span class="material-symbols-outlined" aria-hidden="true">event_available</span>先找可行時段</h5>
        <p>核對未來四次固定日期的老師忙碌與容量；只試算本次，不會直接儲存課程。</p>
      </div>
      <span v-if="teacher" class="coordination-branch-badge">{{ resolvedTeacherBranchLabel }}</span>
    </div>

    <div class="schedule-coordination-controls">
      <label>
        <span>學生可配合開始時間</span>
        <select v-model="windowStart" aria-label="學生可配合開始時間">
          <option v-for="time in halfHourTimeOptions" :key="`coord-start-${time}`" :value="time">{{ time }}</option>
        </select>
      </label>
      <span class="coordination-range-separator" aria-hidden="true">至</span>
      <label>
        <span class="sr-only">學生可配合結束時間</span>
        <select v-model="windowEnd" aria-label="學生可配合結束時間">
          <option v-for="time in halfHourTimeOptions" :key="`coord-end-${time}`" :value="time">{{ time }}</option>
        </select>
      </label>
      <button
        type="button"
        class="ghost small coordination-search-button"
        :disabled="loading || !teacherId || selectedDays.length === 0"
        @click="findSlots"
      >
        <span v-if="loading" class="btn-spinner material-symbols-outlined" aria-hidden="true">progress_activity</span>
        {{ loading ? '比對中…' : '尋找老師空檔' }}
      </button>
    </div>

    <p v-if="!teacherId" class="field-note">請先選老師；目前只查詢已選老師的跨分校忙碌與容量。</p>
    <p v-else-if="selectedDays.length === 0" class="field-note">先勾選固定上課星期。</p>
    <p v-if="errorMessage" class="coordination-message coordination-message--error" role="alert">{{ errorMessage }}</p>
    <p v-if="resultsStale" class="coordination-message" data-testid="teacher-availability-stale">
      排課條件已變更，請重新查詢以取得最新老師空檔。
    </p>

    <div v-if="hasCurrentResults && candidates.length > 0" class="coordination-results">
      <div class="coordination-results-title">推薦時段 <span>點一下即可套用到固定排課</span></div>
      <button
        v-for="candidate in candidates"
        :key="`${candidate.date}-${candidate.start_time}`"
        type="button"
        class="coordination-candidate"
        @click="applyCandidate(candidate)"
      >
        <span class="coordination-candidate-date">{{ candidate.date.slice(5) }} 週{{ weekdayLabelMap[candidate.weekday] }}</span>
        <strong>{{ candidate.start_time }}–{{ candidate.end_time }}</strong>
        <span :class="['coordination-candidate-status', { 'is-capacity': candidate.status === 'capacity' }]">
          {{ candidate.status === 'capacity' ? '容量足夠' : `前${candidate.occurrenceTotal}次皆可排` }}
        </span>
      </button>
    </div>
    <p v-else-if="hasCurrentResults && !errorMessage" class="coordination-message">
      目前窗口沒有可直接套用的時段。{{ conflictHint }}可放寬時間窗口或改查其他老師。
    </p>
    <p v-if="appliedMessage" class="coordination-message coordination-message--success">已套用 {{ appliedMessage }}；送出時仍會由後端再次檢查衝堂與教室容量。</p>
  </article>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { getBranchName } from '../lib/useBranches.js';
import {
  buildScheduleCandidates,
  mergeRecurringScheduleCandidates,
  nextOccurrenceDates,
  rankScheduleCandidates,
} from '../lib/scheduleCandidateSlots.js';

const props = defineProps({
  teacherId: { type: [Number, String], default: '' },
  teacher: { type: Object, default: null },
  teacherBranchLabel: { type: String, default: '' },
  studentId: { type: [Number, String], default: '' },
  branchId: { type: [Number, String], default: 0 },
  classType: { type: String, default: '' },
  paymentType: { type: String, default: 'session' },
  startDate: { type: String, default: '' },
  endDate: { type: String, default: '' },
  daysOfWeek: { type: Array, default: () => [] },
  dayTimeSlots: { type: Array, default: () => [] },
  durationHours: { type: [Number, String], default: 2 },
  timeOptions: { type: Array, default: () => [] },
  fetchAvailability: { type: Function, required: true },
});

const emit = defineEmits(['apply']);
const weekdayLabelMap = { 1: '一', 2: '二', 3: '三', 4: '四', 5: '五', 6: '六', 7: '日' };
const halfHourTimeOptions = computed(() => props.timeOptions.length ? props.timeOptions : buildHalfHourTimeOptions());
const windowStart = ref('16:00');
const windowEnd = ref('21:00');
const loading = ref(false);
const searched = ref(false);
const errorMessage = ref('');
const appliedMessage = ref('');
const busyByDate = ref({});
const queriedKey = ref('');
let requestToken = 0;

const selectedDays = computed(() => (
  [...new Set((props.daysOfWeek || []).map(Number).filter((day) => day >= 1 && day <= 7))].sort((a, b) => a - b)
));
const normalizedSlots = computed(() => (props.dayTimeSlots || []).map((slot) => ({
  day: Number(slot?.day || 0),
  start: String(slot?.start_time || '').slice(0, 5),
  duration: Number(slot?.duration_hours || 0) || Number(props.durationHours) || 0,
})));
const queryKey = computed(() => JSON.stringify({
  teacher: Number(props.teacherId || 0),
  student: Number(props.studentId || 0),
  branch: Number(props.branchId || 0),
  startDate: String(props.startDate || ''),
  endDate: String(props.endDate || ''),
  paymentType: String(props.paymentType || 'session'),
  days: selectedDays.value,
  slots: normalizedSlots.value,
  windowStart: windowStart.value,
  windowEnd: windowEnd.value,
  duration: Number(props.durationHours || 0),
  classType: String(props.classType || ''),
}));
const hasCurrentResults = computed(() => searched.value && queriedKey.value === queryKey.value);
const resultsStale = computed(() => searched.value && !hasCurrentResults.value);
const teacherBranchLabel = computed(() => {
  const ids = Array.isArray(props.teacher?.branch_ids)
    ? props.teacher.branch_ids.map(Number).filter((id) => id > 0)
    : [];
  const primary = Number(props.teacher?.branch_id || 0);
  const branches = [...new Set([...ids, ...(primary > 0 ? [primary] : [])])];
  return branches.length ? `可服務：${branches.map((id) => getBranchName(id)).join('、')}` : '分校資料待確認';
});
const resolvedTeacherBranchLabel = computed(() => props.teacherBranchLabel || teacherBranchLabel.value);

function toYmd(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function nextBaseDate() {
  const today = toYmd(new Date());
  return props.startDate && props.startDate > today ? props.startDate : today;
}

function datesForDay(day) {
  const dates = nextOccurrenceDates(nextBaseDate(), day, 4);
  return props.paymentType === 'monthly' && props.endDate
    ? dates.filter((date) => date <= props.endDate)
    : dates;
}

function durationForDay(day) {
  const slot = normalizedSlots.value.find((entry) => entry.day === Number(day));
  return Math.round((slot?.duration || Number(props.durationHours) || 0) * 60);
}

const allCandidates = computed(() => {
  const all = [];
  for (const day of selectedDays.value) {
    const dates = datesForDay(day);
    if (!dates.length || dates.some((date) => !Object.prototype.hasOwnProperty.call(busyByDate.value, date))) continue;
    const candidatesByDate = dates.map((date) => buildScheduleCandidates({
      date,
      weekday: day,
      windowStart: windowStart.value,
      windowEnd: windowEnd.value,
      durationMinutes: durationForDay(day),
      busySlots: busyByDate.value[date],
      classType: props.classType,
      branchId: Number(props.branchId || 0),
    }));
    all.push(...mergeRecurringScheduleCandidates(candidatesByDate));
  }
  return rankScheduleCandidates(all);
});
const candidates = computed(() => allCandidates.value.filter((candidate) => candidate.status !== 'conflict').slice(0, 12));
const conflictHint = computed(() => {
  const blocked = allCandidates.value.find((candidate) => candidate.status === 'conflict');
  return blocked?.conflictTooltip
    ? `最近的阻塞原因：${blocked.conflictTooltip}（前${blocked.occurrenceTotal}次中${blocked.occurrenceCount}次可排）。`
    : '';
});

function teacherSupportsBranch() {
  const ids = Array.isArray(props.teacher?.branch_ids)
    ? props.teacher.branch_ids.map(Number).filter((id) => id > 0)
    : [];
  const primary = Number(props.teacher?.branch_id || 0);
  const branchId = Number(props.branchId || 0);
  return !branchId || !ids.length && !primary || ids.includes(branchId) || primary === branchId;
}

async function findSlots() {
  searched.value = false;
  errorMessage.value = '';
  appliedMessage.value = '';
  const teacherId = Number(props.teacherId || 0);
  if (!teacherId || !props.teacher) {
    errorMessage.value = '請先選擇老師。';
    return;
  }
  if (!selectedDays.value.length) {
    errorMessage.value = '請先勾選固定上課星期。';
    return;
  }
  if (!teacherSupportsBranch()) {
    errorMessage.value = '所選老師未綁定目前分校，無法試算此分校時段。';
    return;
  }
  if (windowStart.value >= windowEnd.value) {
    errorMessage.value = '可配合時間的結束時間必須晚於開始時間。';
    return;
  }
  if (durationForDay(selectedDays.value[0]) < 30) {
    errorMessage.value = '請先設定至少 0.5 小時的預設上課時長。';
    return;
  }

  const token = ++requestToken;
  loading.value = true;
  try {
    const dates = selectedDays.value.flatMap((day) => datesForDay(day));
    const results = await Promise.all(dates.map(async (date) => {
      try {
        const response = await props.fetchAvailability(teacherId, date, {
          excludeStudentId: Number(props.studentId || 0) || undefined,
        });
        return { date, busySlots: Array.isArray(response?.busy_slots) ? response.busy_slots : [] };
      } catch {
        return { date, error: true };
      }
    }));
    if (token !== requestToken) return;
    busyByDate.value = Object.fromEntries(results.filter((result) => !result.error).map((result) => [result.date, result.busySlots]));
    const failed = results.filter((result) => result.error).length;
    errorMessage.value = failed === results.length
      ? '目前無法取得老師可用性，請稍後再試。'
      : failed > 0 ? `${failed} 個固定上課日期暫時無法試算；為避免誤排，該星期暫不顯示建議。` : '';
    queriedKey.value = queryKey.value;
    searched.value = true;
  } finally {
    if (token === requestToken) loading.value = false;
  }
}

function applyCandidate(candidate) {
  emit('apply', candidate);
  const day = weekdayLabelMap[candidate.weekday] || '';
  appliedMessage.value = `${candidate.date.slice(5)} 起每週${day} ${candidate.start_time}–${candidate.end_time}`;
}

watch(queryKey, () => {
  requestToken += 1;
  loading.value = false;
  errorMessage.value = '';
  appliedMessage.value = '';
}, { flush: 'sync' });

function buildHalfHourTimeOptions() {
  const options = [];
  for (let hour = 0; hour < 24; hour += 1) {
    for (const minute of [0, 30]) options.push(`${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`);
  }
  return options;
}
</script>

<style scoped>
.schedule-coordination-card { margin-top: 14px; padding: 14px; border: 1px solid var(--ds-primary); border-radius: 14px; background: var(--ds-primary-wash); }
.schedule-coordination-heading { display: flex; justify-content: space-between; gap: 12px; }
.schedule-coordination-heading h5 { display: flex; align-items: center; gap: 5px; margin: 0; color: var(--ds-primary-deep, var(--ds-primary)); font-size: 14px; }
.schedule-coordination-heading h5 .material-symbols-outlined { font-size: 18px; }
.schedule-coordination-heading p { margin: 4px 0 0; color: var(--text-light); font-size: 12px; }
.coordination-branch-badge, .coordination-candidate-status { padding: 3px 8px; border-radius: 999px; background: var(--ds-success-wash); color: var(--ds-success); font-size: 11px; font-weight: 700; }
.schedule-coordination-controls { display: flex; align-items: flex-end; gap: 8px; margin-top: 12px; }
.schedule-coordination-controls label { display: flex; flex: 0 1 150px; flex-direction: column; gap: 4px; color: var(--text-light); font-size: 11px; font-weight: 600; }
.schedule-coordination-controls select { width: 100%; }
.coordination-range-separator { padding-bottom: 9px; color: var(--text-light); font-size: 12px; }
.coordination-search-button { white-space: nowrap; }
.coordination-results { margin-top: 12px; }
.coordination-results-title { margin-bottom: 7px; color: var(--ds-ink); font-size: 12px; font-weight: 700; }
.coordination-candidate { display: inline-flex; align-items: center; gap: 8px; margin: 0 6px 6px 0; padding: 7px 10px; border: 1px solid var(--ds-success); border-radius: 9px; background: var(--ds-canvas); color: var(--ds-ink); cursor: pointer; font: inherit; font-size: 12px; text-align: left; }
.coordination-candidate:hover, .coordination-candidate:focus-visible { border-color: var(--ds-primary); box-shadow: 0 0 0 2px var(--ds-primary-wash); }
.coordination-candidate-date { color: var(--text-light); }
.coordination-candidate strong { font-variant-numeric: tabular-nums; }
.coordination-candidate-status.is-capacity { background: var(--ds-warning-wash); color: var(--ds-warning); }
.coordination-message { margin: 10px 0 0; color: var(--text-light); font-size: 12px; line-height: 1.5; }
.coordination-message--error { color: var(--ds-danger); }
.coordination-message--success { color: var(--ds-success); }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
@media (max-width: 760px) {
  .schedule-coordination-controls { align-items: stretch; flex-wrap: wrap; }
  .schedule-coordination-controls label { flex: 1 1 110px; }
  .coordination-range-separator { display: none; }
  .coordination-search-button { flex: 1 0 100%; }
}
</style>
