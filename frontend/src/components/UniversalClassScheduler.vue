<template>
  <div class="modal-overlay">
    <div class="modal universal-scheduler-modal">
      <header class="scheduler-header">
        <h3>{{ title }}</h3>
        <p class="scheduler-subtitle">
          可先勾選「已上過課」日期；系統會依固定星期自動推算未來堂次，若今天尚未下課也可排入今日。
        </p>
      </header>

      <div class="scheduler-layout">
        <section class="panel-stack">
          <article class="scheduler-card">
            <h4>欄位設定</h4>
            <div class="scheduler-grid">
              <div class="form-group">
                <label>學生 *</label>
                <SearchableSelect
                  v-model="form.student_id"
                  :options="studentOptions"
                  placeholder="輸入學生姓名搜尋..."
                />
              </div>

              <div class="form-group">
                <label>老師 *</label>
                <SearchableSelect
                  v-model="form.teacher_id"
                  :options="teacherOptions"
                  placeholder="輸入老師姓名搜尋..."
                />
              </div>

              <div class="form-group">
                <label>科目 *</label>
                <select v-model="form.subject">
                  <option v-for="s in subjectOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
              </div>

              <div v-if="scopeWarning" class="scope-warning-banner" style="grid-column: 1 / -1;">
                ⚠️ {{ scopeWarning }}（仍可儲存）
              </div>

              <div class="form-group">
                <label>上課類型 *</label>
                <select v-model="form.class_type">
                  <option value="one_on_one">一對一</option>
                  <option value="one_on_two">一對二</option>
                  <option value="one_on_three">一對三</option>
                  <option value="tutoring">輔導</option>
                </select>
              </div>

              <div class="form-group">
                <label>{{ hasPerDayDuration ? '每小時費用 *' : '單堂費用 *' }}</label>
                <input v-model.number="form.price_per_session" type="number" min="0" step="50" />
              </div>

              <div class="form-group">
                <label>繳費方式 *</label>
                <select v-model="form.payment_type">
                  <option value="session">按堂數</option>
                  <option value="monthly">每月固定</option>
                </select>
              </div>

              <div v-if="form.payment_type === 'session'" class="form-group">
                <label>購買總堂數 *</label>
                <input v-model.number="form.total_classes" type="number" min="1" />
              </div>

              <template v-else>
                <div class="form-group">
                  <label>結算日 *</label>
                  <select v-model.number="form.settlement_day">
                    <option :value="null">請選擇</option>
                    <option v-for="day in settlementDayOptions" :key="day" :value="day">每月 {{ day }} 號</option>
                  </select>
                </div>

                <div class="form-group">
                  <label>本月預排堂數 *</label>
                  <input v-model.number="form.monthly_sessions" type="number" min="1" />
                  <p class="field-note">月結課不扣購買堂數，這裡只決定本次要先建立幾堂課。</p>
                  <p v-if="monthlyCapacityHint" class="field-note">{{ monthlyCapacityHint }}</p>
                  <p v-if="monthlyCapacityWarning" class="field-note warning-text">{{ monthlyCapacityWarning }}</p>
                </div>
              </template>

              <div class="form-group">
                <label>上課教室</label>
                <select v-model="form.room_id">
                  <option value="">未指定</option>
                  <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
              </div>
            </div>
          </article>

          <article class="scheduler-card">
            <h4>固定上課星期</h4>
            <div class="scheduler-grid">
              <div class="form-group">
                <label>預設上課時長（小時） *</label>
                <input v-model.number="form.duration_hours" type="number" min="0.5" step="0.5" inputmode="decimal" />
                <p class="field-note">新增星期時的預設時長，可在下方逐日調整。</p>
              </div>
            </div>
            <div class="weekday-row">
              <label
                v-for="day in weekdayOptions"
                :key="day.value"
                :class="['weekday-chip', { selected: form.days_of_week.includes(day.value) }]"
              >
                <input v-model="form.days_of_week" type="checkbox" :value="day.value" />
                <span>{{ day.label }}</span>
              </label>
            </div>
            <div v-if="selectedDays.length > 0" class="weekday-slot-grid">
              <div v-for="day in selectedDays" :key="`slot-${day}`" class="weekday-slot-row per-day-row">
                <span class="weekday-slot-day">週{{ weekdayLabelMap[day] }}</span>
                <select :value="slotStartTimeByDay[day] || form.start_time" @change="updateDaySlot(day, $event.target.value)">
                  <option v-for="slot in halfHourTimeOptions" :key="`${day}-${slot}`" :value="slot">{{ slot }}</option>
                </select>
                <input
                  type="number"
                  class="per-day-dur"
                  :value="slotDurationByDay[day] ?? form.duration_hours"
                  min="0.5"
                  step="0.5"
                  inputmode="decimal"
                  @change="updateDayDuration(day, $event.target.value)"
                />
                <small class="field-note">~ {{ computeSessionEndTime(slotStartTimeByDay[day] || form.start_time, slotDurationByDay[day] ?? form.duration_hours) }}</small>
              </div>
            </div>
          </article>

          <article class="scheduler-card">
            <h4>備註</h4>
            <div class="form-group">
              <textarea v-model="form.memo" rows="2" placeholder="可選填"></textarea>
            </div>
          </article>
        </section>

        <section class="panel-stack">
          <article class="scheduler-card calendar-card">
            <div class="calendar-header">
              <button class="ghost small" type="button" @click="shiftMonth(-1)">上個月</button>
              <strong>{{ monthLabel }}</strong>
              <button class="ghost small" type="button" @click="shiftMonth(1)">下個月</button>
            </div>

            <div class="calendar-weekdays">
              <span v-for="wd in weekdayText" :key="wd">{{ wd }}</span>
            </div>

            <div class="calendar-grid">
              <button
                v-for="cell in calendarCells"
                :key="cell.key"
                type="button"
                :class="[
                  'calendar-cell',
                  {
                    muted: !cell.inMonth,
                    confirmed: cell.status === 'manual',
                    future: cell.status === 'future',
                  },
                ]"
                @click="onDateClick(cell)"
              >
                <span>{{ cell.day }}</span>
                <small v-if="cell.status === 'manual'">手動</small>
                <small v-else-if="cell.status === 'future'">預排</small>
              </button>
            </div>
          </article>

          <article class="scheduler-card">
            <h4>日曆摘要</h4>
            <div class="summary-row">
              <div class="summary-pill confirmed">
                <div class="summary-label">手動選定</div>
                <strong>{{ manualDates.length }}</strong>
              </div>
              <div class="summary-pill future">
                <div class="summary-label">未來預排</div>
                <strong>{{ futureDates.length }}</strong>
              </div>
              <div class="summary-pill total">
                <div class="summary-label">{{ plannedCountLabel }}</div>
                <strong>{{ safePlannedSessions }}</strong>
              </div>
            </div>

            <div class="legend-row">
              <span class="legend-chip confirmed">🟢 手動指定日期</span>
              <span class="legend-chip future">🔵 系統預排(未來)</span>
            </div>

            <p class="hint-text">
              可手動指定過去或未來日期；系統只會從最後一個手動日期之後自動補齊剩餘堂次。
              <template v-if="form.payment_type === 'monthly'">月結課只會累積已使用堂次，不會扣剩餘堂數。</template>
            </p>

            <div class="calendar-actions">
              <button class="ghost small" type="button" @click="clearConfirmedDates" :disabled="manualDates.length === 0">
                清除手動日期
              </button>
            </div>

            <div v-if="manualDates.length > 0" class="date-list confirmed">
              <p>手動指定日期：</p>
              <div>{{ manualDates.join('、') }}</div>
            </div>

            <div v-if="futureDates.length > 0" class="date-list future">
              <p>系統未來預排：</p>
              <div>{{ futureDates.join('、') }}</div>
            </div>
          </article>
        </section>
      </div>

      <div class="modal-actions">
        <button class="ghost" type="button" @click="$emit('cancel')">取消</button>
        <button class="primary" type="button" :disabled="submitting" @click="submit">
          {{ submitting ? '送出中...' : submitLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { createUniversalClassSchedule } from '../lib/universalSchedulerApi';
import { checkTeacherScope } from '../lib/constants';
import SearchableSelect from './SearchableSelect.vue';

const weekdayOptions = [
  { value: 1, label: '週一' },
  { value: 2, label: '週二' },
  { value: 3, label: '週三' },
  { value: 4, label: '週四' },
  { value: 5, label: '週五' },
  { value: 6, label: '週六' },
  { value: 7, label: '週日' },
];
const weekdayLabelMap = { 1: '一', 2: '二', 3: '三', 4: '四', 5: '五', 6: '六', 7: '日' };

const weekdayText = ['一', '二', '三', '四', '五', '六', '日'];
const halfHourTimeOptions = buildHalfHourTimeOptions();
const settlementDayOptions = Array.from({ length: 31 }, (_, index) => index + 1);

const subjectOptions = [
  { value: 'Chinese', label: '國文' },
  { value: 'English', label: '英文' },
  { value: 'Math', label: '數學' },
  { value: 'Science', label: '理化' },
  { value: 'Chemistry', label: '化學' },
  { value: 'Physics', label: '物理' },
  { value: 'Biology', label: '生物' },
  { value: 'Social', label: '社會' },
];

const props = defineProps({
  title: { type: String, default: '通用排課' },
  submitLabel: { type: String, default: '儲存排課' },
  branchId: { type: [Number, String], default: null },
  students: { type: Array, default: () => [] },
  teachers: { type: Array, default: () => [] },
  rooms: { type: Array, default: () => [] },
  initialStudentId: { type: [Number, String], default: '' },
  initialTeacherId: { type: [Number, String], default: '' },
  mode: { type: String, default: 'create' },
});

const emit = defineEmits(['success', 'cancel']);

const nowAtOpen = new Date();
const currentMonth = ref(new Date(nowAtOpen.getFullYear(), nowAtOpen.getMonth(), 1));
const submitting = ref(false);

const form = reactive({
  student_id: props.initialStudentId ? Number(props.initialStudentId) : '',
  teacher_id: props.initialTeacherId ? Number(props.initialTeacherId) : '',
  subject: 'Math',
  class_type: 'one_on_one',
  confirmed_dates: [],
  total_classes: 8,
  settlement_day: null,
  monthly_sessions: 4,
  days_of_week: [],
  day_time_slots: [],
  start_time: '16:00',
  duration_hours: 2,
  price_per_session: 1000,
  payment_type: 'session',
  room_id: '',
  memo: '',
});

const studentOptions = computed(() => (
  (props.students || []).map((student) => ({
    value: Number(student?.id ?? 0),
    label: student?.name || `#${student?.id ?? ''}`,
  })).filter((student) => Number.isFinite(student.value) && student.value > 0)
));
const teacherOptions = computed(() => (
  (props.teachers || []).map((teacher) => {
    const id = Number(teacher?.id ?? 0);
    const label = String(
      teacher?.name
      || teacher?.Name
      || teacher?.T_Name
      || teacher?.username
      || teacher?.LoginName
      || `老師#${id || ''}`
    ).trim();
    return { value: id, label };
  }).filter((teacher) => Number.isFinite(teacher.value) && teacher.value > 0 && teacher.label)
));

const selectedTeacher = computed(() => {
  const tid = Number(form.teacher_id);
  return tid > 0 ? (props.teachers || []).find((t) => Number(t.id) === tid) : null;
});

const selectedStudent = computed(() => {
  const sid = Number(form.student_id);
  return sid > 0 ? (props.students || []).find((s) => Number(s.id) === sid) : null;
});

const scopeWarning = computed(() => {
  const teacher = selectedTeacher.value;
  if (!teacher) return null;
  const studentGrade = selectedStudent.value?.grade || selectedStudent.value?.ClassID || null;
  return checkTeacherScope(teacher, form.subject, studentGrade, subjectOptions);
});

const apiScopeWarning = ref('');

const safePlannedSessions = computed(() => (
  form.payment_type === 'monthly'
    ? Math.max(1, Number(form.monthly_sessions) || 1)
    : Math.max(1, Number(form.total_classes) || 1)
));
const selectedDays = computed(() => (
  [...new Set((form.days_of_week || []).map((d) => Number(d)).filter((d) => d >= 1 && d <= 7))].sort((a, b) => a - b)
));
const slotStartTimeByDay = computed(() => {
  const map = {};
  for (const slot of (form.day_time_slots || [])) {
    const day = Number(slot?.day || 0);
    if (day >= 1 && day <= 7) {
      map[day] = String(slot?.start_time || '').slice(0, 5);
    }
  }
  return map;
});
const slotDurationByDay = computed(() => {
  const map = {};
  for (const slot of (form.day_time_slots || [])) {
    const day = Number(slot?.day || 0);
    const dur = Number(slot?.duration_hours || 0);
    if (day >= 1 && day <= 7 && dur > 0) {
      map[day] = dur;
    }
  }
  return map;
});
const hasPerDayDuration = computed(() => {
  const vals = Object.values(slotDurationByDay.value);
  if (vals.length < 2) return false;
  return new Set(vals.map((v) => v.toFixed(1))).size > 1;
});
const plannedCountLabel = computed(() => (
  form.payment_type === 'monthly' ? '本月預排堂數' : '購買總堂數'
));
const monthlyAvailableTotal = computed(() => manualDates.value.length + futureDates.value.length);
const monthlyCapacityHint = computed(() => {
  if (form.payment_type !== 'monthly') return '';
  if ((form.days_of_week || []).length === 0) return '';
  return `本月依目前星期設定最多可排 ${monthlyAvailableTotal.value} 堂（含手動指定）。`;
});
const monthlyCapacityWarning = computed(() => {
  if (form.payment_type !== 'monthly') return '';
  const requested = Number(safePlannedSessions.value || 0);
  if (requested <= 0) return '';
  if (requested <= monthlyAvailableTotal.value) return '';
  return `目前設定超出本月可排上限，請降低本月預排堂數或調整固定星期。`;
});

const monthLabel = computed(() => {
  const y = currentMonth.value.getFullYear();
  const m = String(currentMonth.value.getMonth() + 1).padStart(2, '0');
  return `${y}-${m}`;
});

watch(
  () => form.days_of_week,
  () => {
    syncDaySlotsFromSelection();
  },
  { deep: true, immediate: true }
);

watch(
  () => form.day_time_slots,
  (slots) => {
    if (Array.isArray(slots) && slots.length > 0 && slots[0]?.start_time) {
      form.start_time = normalizeHalfHourTime(slots[0].start_time);
    }
  },
  { deep: true }
);

const manualDates = computed(() => sortDates(form.confirmed_dates || []));
const manualDateSet = computed(() => new Set(manualDates.value));
const confirmedDates = computed(() => manualDates.value.filter((date) => isManualDateConfirmed(date)));
const manualScheduledDates = computed(() => manualDates.value.filter((date) => !isManualDateConfirmed(date)));

const futureDates = computed(() => {
  const manual = manualDates.value;
  const remaining = safePlannedSessions.value - manual.length;
  if (remaining <= 0) return [];

  const daySet = new Set((form.days_of_week || []).map((d) => Number(d)).filter((d) => d >= 1 && d <= 7));
  if (daySet.size === 0) return [];

  const todayYmd = getCurrentTodayYmd();
  const lastManual = manual.length > 0 ? manual[manual.length - 1] : null;
  const effectiveAnchor = lastManual || todayYmd;
  const targetMonthYm = form.payment_type === 'monthly' ? effectiveAnchor.slice(0, 7) : '';
  const selected = [];
  const seen = new Set(manual);
  const cursor = new Date(`${effectiveAnchor}T00:00:00`);
  let guard = 0;
  while (selected.length < remaining && guard < 3660) {
    guard += 1;
    const candidate = new Date(cursor);
    const ymd = toYmd(candidate);
    const dow = weekdayOneToSeven(candidate);
    const canUse = (!lastManual || ymd > lastManual)
      && daySet.has(dow)
      && ymd >= todayYmd
      && (form.payment_type !== 'monthly' || ymd.slice(0, 7) === targetMonthYm)
      && !seen.has(ymd);
    if (canUse) {
      seen.add(ymd);
      selected.push(ymd);
    }
    cursor.setDate(cursor.getDate() + 1);
  }
  return sortDates(selected);
});

const futureDateSet = computed(() => new Set(futureDates.value));

const calendarCells = computed(() => {
  const first = new Date(currentMonth.value.getFullYear(), currentMonth.value.getMonth(), 1);
  const firstWeekday = ((first.getDay() + 6) % 7); // Mon=0
  const start = new Date(first);
  start.setDate(first.getDate() - firstWeekday);

  const cells = [];
  for (let i = 0; i < 42; i += 1) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    const ymd = toYmd(d);

    let status = '';
    if (manualDateSet.value.has(ymd)) status = 'manual';
    else if (futureDateSet.value.has(ymd)) status = 'future';

    cells.push({
      key: ymd,
      ymd,
      day: d.getDate(),
      inMonth: d.getMonth() === currentMonth.value.getMonth(),
      status,
    });
  }
  return cells;
});

function toYmd(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function getCurrentTodayYmd() {
  return toYmd(new Date());
}

function weekdayOneToSeven(date) {
  const jsDay = date.getDay();
  return jsDay === 0 ? 7 : jsDay;
}

function shiftMonth(delta) {
  const base = currentMonth.value;
  currentMonth.value = new Date(base.getFullYear(), base.getMonth() + delta, 1);
}

function sortDates(arr) {
  return [...new Set(arr)].sort((a, b) => a.localeCompare(b));
}

function normalizeHalfHourTime(raw) {
  const [hRaw, mRaw] = String(raw || '00:00').split(':');
  const hour = Math.max(0, Math.min(23, Number(hRaw) || 0));
  const minute = Number(mRaw) || 0;
  const rounded = minute < 15 ? 0 : (minute < 45 ? 30 : 0);
  const carryHour = minute >= 45 ? 1 : 0;
  const finalHour = (hour + carryHour) % 24;
  return `${String(finalHour).padStart(2, '0')}:${String(rounded).padStart(2, '0')}`;
}

function buildHalfHourTimeOptions() {
  const slots = [];
  for (let hour = 0; hour < 24; hour += 1) {
    for (const minute of [0, 30]) {
      slots.push(`${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`);
    }
  }
  return slots;
}

function durationHoursToMinutes(rawHours) {
  const hours = Number(rawHours);
  if (!Number.isFinite(hours) || hours <= 0) return 0;
  return Math.round(hours * 60);
}

function getSessionEndDateTime(ymd) {
  const weekday = weekdayOneToSeven(new Date(`${ymd}T00:00:00`));
  const startForDay = slotStartTimeByDay.value[weekday] || form.start_time;
  const normalizedStart = normalizeHalfHourTime(startForDay || '00:00');
  const dayDur = slotDurationByDay.value[weekday] ?? form.duration_hours;
  const durationMinutes = durationHoursToMinutes(dayDur);
  if (durationMinutes < 30) return null;
  const [year, month, day] = String(ymd || '').split('-').map(Number);
  const [hour, minute] = normalizedStart.split(':').map(Number);
  if (!year || !month || !day || !Number.isFinite(hour) || !Number.isFinite(minute)) return null;
  const endAt = new Date(year, month - 1, day, hour, minute, 0, 0);
  endAt.setMinutes(endAt.getMinutes() + durationMinutes);
  return endAt;
}

function computeSessionEndTime(startTimeRaw, durHours) {
  const normalizedStart = normalizeHalfHourTime(startTimeRaw || '00:00');
  const durationMinutes = durationHoursToMinutes(durHours != null ? durHours : form.duration_hours);
  if (durationMinutes < 30) return '--:--';
  const [hour, minute] = normalizedStart.split(':').map(Number);
  if (!Number.isFinite(hour) || !Number.isFinite(minute)) return '--:--';
  const endAt = new Date(2000, 0, 1, hour, minute, 0, 0);
  endAt.setMinutes(endAt.getMinutes() + durationMinutes);
  return `${String(endAt.getHours()).padStart(2, '0')}:${String(endAt.getMinutes()).padStart(2, '0')}`;
}

function syncDaySlotsFromSelection() {
  const existed = new Map(
    (form.day_time_slots || [])
      .map((slot) => [Number(slot?.day || 0), slot])
      .filter(([day]) => day >= 1 && day <= 7)
  );
  const firstSlot = existed.size > 0 ? [...existed.values()][0] : null;
  const baseTime = firstSlot ? normalizeHalfHourTime(firstSlot.start_time || '16:00') : normalizeHalfHourTime('16:00');
  form.day_time_slots = selectedDays.value.map((day) => {
    const ex = existed.get(day);
    return {
      day,
      start_time: ex ? normalizeHalfHourTime(ex.start_time || '16:00') : baseTime,
      duration_hours: ex?.duration_hours || form.duration_hours,
    };
  });
}

function updateDaySlot(day, nextTime) {
  const d = Number(day);
  if (d < 1 || d > 7) return;
  const normalized = normalizeHalfHourTime(nextTime);
  form.day_time_slots = (form.day_time_slots || []).map((slot) => (
    Number(slot?.day || 0) === d ? { ...slot, start_time: normalized } : slot
  ));
}

function updateDayDuration(day, rawValue) {
  const d = Number(day);
  if (d < 1 || d > 7) return;
  const dur = Math.max(0.5, Math.round(Number(rawValue) * 2) / 2);
  form.day_time_slots = (form.day_time_slots || []).map((slot) => (
    Number(slot?.day || 0) === d ? { ...slot, duration_hours: dur } : slot
  ));
}

function isTodaySessionEnded() {
  const todayYmd = getCurrentTodayYmd();
  const sessionEndAt = getSessionEndDateTime(todayYmd);
  if (!sessionEndAt) return false;
  return new Date() >= sessionEndAt;
}

function clearConfirmedDates() {
  form.confirmed_dates = [];
}

function isManualDateConfirmed(ymd) {
  const todayYmd = getCurrentTodayYmd();
  if (ymd < todayYmd) return true;
  if (ymd > todayYmd) return false;
  return isTodaySessionEnded();
}

function onDateClick(cell) {
  if (!cell?.ymd) return;
  if (form.payment_type === 'monthly') {
    const targetYm = toYmd(currentMonth.value).slice(0, 7);
    if (cell.ymd.slice(0, 7) !== targetYm) {
      alert('月結課程僅可選擇同一月份日期。');
      return;
    }
  }
  const current = new Set(manualDateSet.value);
  if (current.has(cell.ymd)) {
    current.delete(cell.ymd);
    form.confirmed_dates = sortDates([...current]);
    return;
  }

  if (current.size >= safePlannedSessions.value) {
    alert(`手動指定日期不可超過${plannedCountLabel.value}（${safePlannedSessions.value}）。`);
    return;
  }

  current.add(cell.ymd);
  form.confirmed_dates = sortDates([...current]);
}

async function submit() {
  const branchId = Number(props.branchId || 0);
  if (branchId <= 0) {
    alert('請先選擇分校後再建立課程');
    return;
  }
  const todayYmd = getCurrentTodayYmd();
  if (!form.student_id) {
    alert('請選擇學生');
    return;
  }
  if (!form.teacher_id) {
    alert('請選擇老師');
    return;
  }
  const selectedTeacher = (props.teachers || []).find((teacher) => String(teacher.id) === String(form.teacher_id || ''));
  if (selectedTeacher) {
    const branchIds = Array.isArray(selectedTeacher.branch_ids)
      ? selectedTeacher.branch_ids.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0)
      : [];
    const teacherBranch = Number(selectedTeacher.branch_id || 0);
    if (branchIds.length > 0 && !branchIds.includes(branchId) && teacherBranch !== branchId) {
      alert('所選老師未綁定目前分校，請改選其他老師');
      return;
    }
  }
  const durationMinutes = durationHoursToMinutes(form.duration_hours);
  if (durationMinutes < 30) {
    alert('上課時長至少為 0.5 小時');
    return;
  }
  if (durationMinutes > 480) {
    alert('上課時長不可超過 8 小時');
    return;
  }
  if (manualDates.value.length > safePlannedSessions.value) {
    alert(`手動指定日期不可超過${plannedCountLabel.value}`);
    return;
  }

  if (form.payment_type === 'monthly' && !form.settlement_day) {
    alert('月結課請先選擇結算日');
    return;
  }
  const projectedFutureDates = futureDates.value;
  if (form.payment_type === 'monthly') {
    const targetYm = toYmd(currentMonth.value).slice(0, 7);
    const allDates = sortDates([...manualDates.value, ...projectedFutureDates]);
    const hasCrossMonth = allDates.some((date) => String(date).slice(0, 7) !== targetYm);
    if (hasCrossMonth) {
      alert('月結課程僅能建立在同一月份，請調整手動日期或月份。');
      return;
    }
  }

  const remaining = safePlannedSessions.value - manualDates.value.length;
  if (remaining > 0 && (form.days_of_week || []).length === 0) {
    alert('尚有未排堂次，請先設定固定上課星期讓系統推算未來日期');
    return;
  }
  if (selectedDays.value.length > 0) {
    const missing = selectedDays.value.find((day) => !slotStartTimeByDay.value[day]);
    if (missing) {
      alert(`請設定週${weekdayLabelMap[missing]}的上課時間`);
      return;
    }
  }
  if (projectedFutureDates.some((date) => date < todayYmd)) {
    alert('系統預排日期不可早於今天，請調整固定上課星期');
    return;
  }
  if ((manualDates.value.length + projectedFutureDates.length) !== safePlannedSessions.value) {
    alert(`系統無法補齊至${plannedCountLabel.value}，請調整固定上課星期或手動指定日期`);
    return;
  }

  submitting.value = true;
  try {
    const normalizedStartTime = normalizeHalfHourTime(form.start_time);
    const normalizedDaySlots = selectedDays.value.map((day) => {
      const perDayDur = slotDurationByDay.value[day] ?? form.duration_hours;
      return {
        day,
        start_time: normalizeHalfHourTime(slotStartTimeByDay.value[day] || normalizedStartTime),
        duration_minutes: durationHoursToMinutes(perDayDur),
      };
    });
    const manualConfirmedDates = confirmedDates.value;
    const manualFutureDates = manualScheduledDates.value;
    const payload = {
      branch_id: branchId,
      student_id: Number(form.student_id),
      teacher_id: Number(form.teacher_id),
      subject: form.subject,
      class_type: form.class_type,
      confirmed_dates: manualConfirmedDates,
      future_dates: sortDates([...manualFutureDates, ...projectedFutureDates]),
      days_of_week: [...(form.days_of_week || [])].map((d) => Number(d)).filter((d) => d >= 1 && d <= 7),
      day_time_slots: normalizedDaySlots,
      start_time: normalizedStartTime,
      duration_minutes: durationMinutes,
      rate_unit: hasPerDayDuration.value ? 'hour' : 'session',
      price_per_session: Math.max(0, Number(form.price_per_session) || 0),
      payment_type: form.payment_type || 'session',
      settlement_day: form.payment_type === 'monthly' ? Number(form.settlement_day) || null : null,
      monthly_sessions: form.payment_type === 'monthly' ? safePlannedSessions.value : null,
      room_id: form.room_id ? Number(form.room_id) : null,
      memo: form.memo || null,
      mode: props.mode,
    };

    if (form.payment_type === 'session') {
      payload.total_classes = safePlannedSessions.value;
    }

    const result = await createUniversalClassSchedule(payload);
    const createdConfirmed = Number(result?.created_confirmed_sessions ?? manualConfirmedDates.length);
    const createdFuture = Number(result?.created_future_sessions ?? (manualFutureDates.length + projectedFutureDates.length));
    const autoBackfilled = Number(result?.auto_backfilled_sessions ?? 0);
    const autoBackfillSuffix = autoBackfilled > 0
      ? `，其中 ${autoBackfilled} 堂因新增時已過下課時間，系統已自動補登核准`
      : '';
    let msg = `已建立 ${createdConfirmed + createdFuture} 堂課（已上課 ${createdConfirmed}、未來預排 ${createdFuture}）${autoBackfillSuffix}`;
    if (result?.dual_teacher_warning) {
      msg += `\n\n⚠️ ${result.dual_teacher_warning}`;
    }
    if (result?.scope_warning) {
      msg += `\n\n⚠️ 學段提示：${result.scope_warning}`;
    }
    alert(msg);
    emit('success', result);
  } catch (err) {
    alert(err?.message || '排課失敗，請稍後再試');
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.universal-scheduler-modal {
  width: min(1180px, 96vw);
  max-height: 94vh;
  overflow-y: auto;
  padding: 0;
  border-radius: 20px;
  background: #f9fafb;
}

.scheduler-header {
  padding: 22px 24px 14px;
  border-bottom: 1px solid #e5e7eb;
  background: #f9fafb;
}

.scheduler-subtitle {
  margin-top: 6px;
  color: var(--text-light);
  font-size: 13px;
}

.scheduler-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  gap: 16px;
  padding: 18px 20px;
  background: #f9fafb;
}

.panel-stack {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.scheduler-card {
  border: 1px solid #e5e7eb;
  border-radius: 20px;
  padding: 16px;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}

.scheduler-card h4 {
  margin-bottom: 12px;
  font-size: 14px;
  color: var(--text);
}

.scheduler-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-group-full {
  grid-column: 1 / -1;
}

.field-note {
  font-size: 12px;
  color: var(--text-light);
}
.warning-text {
  color: #b45309;
  font-weight: 600;
}

.weekday-row {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.weekday-chip {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 58px;
  padding: 7px 11px;
  border-radius: 999px;
  border: 1px solid #d6deeb;
  background: #fff;
  cursor: pointer;
  text-align: center;
}

.weekday-chip.selected {
  background: #ecf4ff;
  border-color: #6ea8fe;
}

.weekday-chip input[type='checkbox'] {
  position: absolute;
  width: 0;
  height: 0;
  opacity: 0;
  pointer-events: none;
}

.weekday-chip span {
  display: inline-block;
  white-space: nowrap;
  line-height: 1;
}

.weekday-slot-grid {
  margin-top: 12px;
  display: grid;
  gap: 8px;
}

.weekday-slot-row {
  display: grid;
  grid-template-columns: 56px minmax(120px, 170px) auto;
  gap: 8px;
  align-items: center;
}
.weekday-slot-row.per-day-row {
  grid-template-columns: 56px minmax(100px, 150px) 70px auto;
}
.per-day-dur {
  width: 70px;
  text-align: center;
  padding: 5px 4px;
  border: 1px solid #d6deeb;
  border-radius: 8px;
  font-size: 13px;
}

.weekday-slot-day {
  font-size: 12px;
  color: var(--text-light);
}

.calendar-card {
  padding-bottom: 12px;
}

.calendar-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.calendar-weekdays {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
  text-align: center;
  font-size: 12px;
  color: #59677a;
  margin-bottom: 6px;
}

.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
}

.calendar-cell {
  border: 1px solid #d8e0ed;
  background: #fff;
  border-radius: 10px;
  min-height: 44px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  font-size: 13px;
}

.calendar-cell small {
  font-size: 10px;
  font-weight: 600;
}

.calendar-cell.muted {
  opacity: 0.45;
}

.calendar-cell.confirmed {
  border-color: #52b36f;
  background: #ebf9ef;
  color: #16783b;
  font-weight: 700;
}

.calendar-cell.future {
  border-color: #71a5ff;
  background: #ecf4ff;
  color: #1657c1;
  font-weight: 700;
}

.summary-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 10px;
}

.summary-pill {
  border-radius: 10px;
  padding: 10px;
  border: 1px solid #d9e1ef;
  background: #f8fbff;
}

.summary-pill .summary-label {
  font-size: 11px;
  color: #60758f;
}

.summary-pill strong {
  font-size: 18px;
}

.summary-pill.confirmed {
  background: #ecf9ef;
  border-color: #b8e6c4;
  color: #177a3c;
}

.summary-pill.future {
  background: #edf4ff;
  border-color: #bed7ff;
  color: #1858bc;
}

.legend-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 8px;
}

.legend-chip {
  border-radius: 999px;
  padding: 5px 10px;
  font-size: 12px;
}

.legend-chip.confirmed {
  background: #ecf9ef;
  color: #177a3c;
}

.legend-chip.future {
  background: #edf4ff;
  color: #1858bc;
}

.hint-text {
  color: var(--text-light);
  font-size: 12px;
  line-height: 1.55;
}

.calendar-actions {
  margin-top: 10px;
}

.date-list {
  margin-top: 10px;
  padding: 10px;
  border-radius: 10px;
  border: 1px solid #d9e1ef;
  font-size: 12px;
}

.date-list p {
  margin-bottom: 6px;
  font-weight: 700;
}

.date-list.confirmed {
  background: #f6fcf7;
  border-color: #cae8d2;
}

.date-list.future {
  background: #f7faff;
  border-color: #c8daf7;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 0 20px 18px;
}

@media (max-width: 980px) {
  .scheduler-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .scheduler-grid {
    grid-template-columns: 1fr;
  }

  .summary-row {
    grid-template-columns: 1fr;
  }
}

.scope-warning-banner {
  background: #fffbeb;
  border: 1px solid #f59e0b;
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 13px;
  color: #92400e;
  line-height: 1.5;
}
</style>
