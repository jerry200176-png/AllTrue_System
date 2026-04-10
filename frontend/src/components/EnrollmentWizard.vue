<template>
  <div class="modal-overlay">
    <div class="modal enrollment-modal">
      <header class="wizard-header">
        <div>
          <h3>{{ title }}</h3>
          <p class="wizard-subtitle">用同一套流程完成新生建檔、課程主約與第一批堂次建立。</p>
        </div>
        <div class="wizard-steps">
          <span
            v-for="item in steps"
            :key="item.value"
            :class="['wizard-step', { active: step === item.value, done: step > item.value }]"
          >
            {{ item.label }}
          </span>
        </div>
      </header>

      <section v-if="step === 1" class="wizard-section">
        <div class="toggle-row">
          <button :class="['mode-chip', { active: studentMode === 'existing' }]" type="button" @click="studentMode = 'existing'">既有學生</button>
          <button :class="['mode-chip', { active: studentMode === 'new' }]" type="button" @click="studentMode = 'new'">新生入班</button>
        </div>

        <div v-if="studentMode === 'existing'" class="form-group">
          <label>學生 *</label>
          <SearchableSelect
            v-model="existingStudentId"
            :options="studentOptions"
            placeholder="輸入學生姓名搜尋..."
          />
        </div>

        <div v-else class="wizard-grid">
          <div class="form-group">
            <label>學生姓名 *</label>
            <input v-model.trim="studentForm.name" type="text" placeholder="請輸入學生姓名" />
          </div>
          <div class="form-group">
            <label>年級</label>
            <select v-model="studentForm.grade">
              <option v-for="grade in gradeOptions" :key="grade.value" :value="grade.value">{{ grade.label }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>就讀學校</label>
            <input v-model.trim="studentForm.school" type="text" placeholder="例：大安國中" />
          </div>
          <div class="form-group">
            <label>學生手機</label>
            <input v-model.trim="studentForm.phone" type="text" placeholder="09xxxxxxxx" />
          </div>
          <div class="form-group">
            <label>家長姓名</label>
            <input v-model.trim="studentForm.parent_name" type="text" placeholder="請輸入家長姓名" />
          </div>
          <div class="form-group">
            <label>家長手機</label>
            <input v-model.trim="studentForm.parent_phone" type="text" placeholder="09xxxxxxxx" />
          </div>
          <div class="form-group form-group-full">
            <label>備註</label>
            <textarea v-model.trim="studentForm.notes" rows="2" placeholder="特殊需求、家長備註..."></textarea>
          </div>
        </div>
      </section>

      <section v-else-if="step === 2" class="wizard-section">
        <div class="wizard-grid">
          <div class="form-group">
            <label>老師 *</label>
            <SearchableSelect
              v-model="courseForm.teacher_id"
              :options="teacherOptions"
              placeholder="輸入老師姓名搜尋..."
            />
          </div>

          <div class="form-group">
            <label>科目 *</label>
            <select v-model="courseForm.subject">
              <option v-for="subject in subjectOptions" :key="subject.value" :value="subject.value">{{ subject.label }}</option>
            </select>
          </div>

          <div v-if="scopeWarning" class="scope-warning-banner" style="grid-column: 1 / -1;">
            ⚠️ {{ scopeWarning }}（仍可儲存）
          </div>

          <div class="form-group">
            <label>上課類型 *</label>
            <select v-model="courseForm.class_type">
              <option value="one_on_one">一對一</option>
              <option value="one_on_two">一對二</option>
              <option value="one_on_three">一對三</option>
              <option value="tutoring">輔導</option>
            </select>
          </div>

          <div class="form-group">
            <label>{{ hasPerDayDuration ? '每小時費用 *' : '單堂費用 *' }}</label>
            <input v-model.number="courseForm.price_per_session" type="number" min="0" />
          </div>

          <div class="form-group">
            <label>繳費方式 *</label>
            <select v-model="courseForm.payment_type">
              <option value="session">堂數制</option>
              <option value="monthly">月結</option>
            </select>
          </div>

          <div v-if="courseForm.payment_type === 'session'" class="form-group">
            <label>購買總堂數 *</label>
            <input v-model.number="courseForm.total_classes" type="number" min="1" />
          </div>

          <template v-else>
            <div class="form-group">
              <label>結算日 *</label>
              <select v-model.number="courseForm.settlement_day">
                <option :value="null">請選擇</option>
                <option v-for="day in settlementDayOptions" :key="day" :value="day">每月 {{ day }} 號</option>
              </select>
            </div>

            <div class="form-group">
              <label>本月預排堂數 *</label>
              <input v-model.number="courseForm.monthly_sessions" type="number" min="1" />
              <p v-if="monthlyCapacityHint" class="field-note">{{ monthlyCapacityHint }}</p>
              <p v-if="monthlyCapacityWarning" class="field-note warning-text">{{ monthlyCapacityWarning }}</p>
            </div>
          </template>

          <div class="form-group">
            <label>教室</label>
            <select v-model="courseForm.room_id">
              <option value="">未指定</option>
              <option v-for="room in rooms" :key="room.id" :value="room.id">{{ room.name }}</option>
            </select>
          </div>

          <div class="form-group form-group-full">
            <label>備註</label>
            <textarea v-model.trim="courseForm.memo" rows="2" placeholder="課程或學生狀況補充"></textarea>
          </div>
        </div>
      </section>

      <section v-else-if="step === 3" class="wizard-section wizard-layout">
        <div class="panel-stack">
          <article class="wizard-card">
            <h4>固定上課星期</h4>
            <div class="wizard-grid">
              <div class="form-group">
                <label>預設上課時長（小時） *</label>
                <input v-model.number="scheduleForm.duration_hours" type="number" min="0.5" step="0.5" inputmode="decimal" />
                <p class="field-note">新增星期時的預設時長，可在下方逐日調整。</p>
              </div>
            </div>

            <div class="weekday-row">
              <label
                v-for="day in weekdayOptions"
                :key="day.value"
                :class="['weekday-chip', { selected: scheduleForm.days_of_week.includes(day.value) }]"
              >
                <input v-model="scheduleForm.days_of_week" type="checkbox" :value="day.value" />
                <span>{{ day.label }}</span>
              </label>
            </div>

            <div v-if="sortedSelectedDays.length > 0" class="day-time-slots">
              <div v-for="d in sortedSelectedDays" :key="d" class="day-time-slot-row per-day-row">
                <span class="day-time-slot-label">{{ weekdayOptions.find(w => w.value === d)?.label }}</span>
                <select
                  :value="slotStartTimeByDay[d] || scheduleForm.start_time"
                  @change="updateSlotTime(d, $event.target.value)"
                >
                  <option v-for="t in halfHourTimeOptions" :key="`${d}-${t}`" :value="t">{{ t }}</option>
                </select>
                <input
                  type="number"
                  class="per-day-dur"
                  :value="slotDurationByDay[d] ?? scheduleForm.duration_hours"
                  min="0.5"
                  step="0.5"
                  inputmode="decimal"
                  @change="updateSlotDuration(d, $event.target.value)"
                />
                <span class="day-time-slot-end">~ {{ computeEndTimeDisplay(slotStartTimeByDay[d] || scheduleForm.start_time, slotDurationByDay[d] ?? scheduleForm.duration_hours) }}</span>
              </div>
            </div>

            <p class="field-note">可先手動指定任何日期；系統會從最後一個手動日期之後自動補齊剩餘堂次。</p>
            <p class="field-note">如果第一堂要從特定日期開始，先在右側月曆點選該日，再設定固定星期，系統就會自動補齊到總堂數。</p>
          </article>
        </div>

        <div class="panel-stack">
          <article class="wizard-card calendar-card">
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

          <article class="wizard-card">
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
                <strong>{{ plannedSessions }}</strong>
              </div>
            </div>

            <div class="legend-row">
              <span class="legend-chip confirmed">🟢 手動指定日期</span>
              <span class="legend-chip future">🔵 系統預排</span>
            </div>

            <p class="hint-text">
              可手動指定過去或未來日期；系統只會從最後一個手動日期之後自動補齊剩餘堂次。
              月結課不扣購買堂數，只會累積已使用堂次；堂數制才會同步扣剩餘堂數。
            </p>
          </article>
        </div>
      </section>

      <section v-else class="wizard-section">
        <div class="wizard-grid summary-grid">
          <div class="wizard-card">
            <h4>學生</h4>
            <p>{{ studentMode === 'existing' ? selectedStudentLabel : (studentForm.name || '未填寫') }}</p>
          </div>
          <div class="wizard-card">
            <h4>課程主約</h4>
            <p>{{ selectedTeacherLabel }} / {{ selectedSubjectLabel }} / {{ classTypeLabel(courseForm.class_type) }}</p>
            <p>{{ courseForm.payment_type === 'monthly' ? `月結，每月 ${courseForm.settlement_day || '—'} 號結算` : `堂數制，共 ${plannedSessions} 堂` }}</p>
          </div>
          <div class="wizard-card">
            <h4>排課摘要</h4>
            <p>{{ weekdaySummaryWithTimes || '尚未設定固定星期' }}</p>
            <p v-if="hasPerDayDuration">時長依星期不同</p>
            <p v-else>每堂 {{ scheduleForm.duration_hours }} 小時</p>
          </div>
          <div class="wizard-card">
            <h4>建立內容</h4>
            <p>已上課 {{ confirmedDates.length }} 堂，未來堂次 {{ futureSessionCount }} 堂</p>
            <p v-if="courseForm.room_id">教室：{{ selectedRoomLabel }}</p>
          </div>
        </div>
      </section>

      <div class="wizard-actions">
        <button class="ghost" type="button" @click="handleBack">{{ step === 1 ? '取消' : '上一步' }}</button>
        <button v-if="step < 4" class="primary" type="button" @click="goNext">下一步</button>
        <button v-else class="primary" type="button" :disabled="submitting" @click="submit">
          {{ submitting ? '建立中...' : submitLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import SearchableSelect from './SearchableSelect.vue';
import { createEnrollment } from '../lib/universalSchedulerApi';
import { checkTeacherScope } from '../lib/constants';

const props = defineProps({
  title: { type: String, default: '新生入班精靈' },
  submitLabel: { type: String, default: '建立入班資料' },
  branchId: { type: [Number, String], default: null },
  students: { type: Array, default: () => [] },
  teachers: { type: Array, default: () => [] },
  rooms: { type: Array, default: () => [] },
  initialStudentId: { type: [Number, String], default: '' },
});

const emit = defineEmits(['success', 'cancel']);

const steps = [
  { value: 1, label: '1. 學生' },
  { value: 2, label: '2. 主約' },
  { value: 3, label: '3. 排課' },
  { value: 4, label: '4. 確認' },
];

const weekdayOptions = [
  { value: 1, label: '週一' },
  { value: 2, label: '週二' },
  { value: 3, label: '週三' },
  { value: 4, label: '週四' },
  { value: 5, label: '週五' },
  { value: 6, label: '週六' },
  { value: 7, label: '週日' },
];
const weekdayText = ['一', '二', '三', '四', '五', '六', '日'];
const settlementDayOptions = Array.from({ length: 31 }, (_, index) => index + 1);
const halfHourTimeOptions = buildHalfHourTimeOptions();
const gradeOptions = [
  { value: 'P1', label: '國小一年級' },
  { value: 'P2', label: '國小二年級' },
  { value: 'P3', label: '國小三年級' },
  { value: 'P4', label: '國小四年級' },
  { value: 'P5', label: '國小五年級' },
  { value: 'P6', label: '國小六年級' },
  { value: 'J1', label: '國一' },
  { value: 'J2', label: '國二' },
  { value: 'J3', label: '國三' },
  { value: 'H1', label: '高一' },
  { value: 'H2', label: '高二' },
  { value: 'H3', label: '高三' },
];
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

const step = ref(1);
const studentMode = ref(props.initialStudentId ? 'existing' : 'new');
const existingStudentId = ref(props.initialStudentId ? Number(props.initialStudentId) : '');
const currentMonth = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const submitting = ref(false);

const studentForm = reactive({
  name: '',
  grade: 'J1',
  school: '',
  phone: '',
  parent_name: '',
  parent_phone: '',
  notes: '',
});

const courseForm = reactive({
  teacher_id: '',
  subject: 'Math',
  class_type: 'one_on_one',
  price_per_session: 500,
  payment_type: 'session',
  total_classes: 8,
  settlement_day: null,
  monthly_sessions: 4,
  room_id: '',
  memo: '',
});

const scheduleForm = reactive({
  confirmed_dates: [],
  days_of_week: [],
  day_time_slots: [],
  start_time: '16:00',
  duration_hours: 2,
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

const selectedTeacherObj = computed(() => {
  const tid = Number(courseForm.teacher_id);
  return tid > 0 ? (props.teachers || []).find((t) => Number(t.id) === tid) : null;
});

const enrollmentStudentGrade = computed(() => {
  if (studentMode.value === 'new') return studentForm.grade || null;
  const sid = Number(existingStudentId.value);
  const s = sid > 0 ? (props.students || []).find((st) => Number(st.id) === sid) : null;
  return s?.grade || s?.ClassID || null;
});

const scopeWarning = computed(() => {
  const teacher = selectedTeacherObj.value;
  if (!teacher) return null;
  return checkTeacherScope(teacher, courseForm.subject, enrollmentStudentGrade.value, subjectOptions);
});

const plannedSessions = computed(() => (
  courseForm.payment_type === 'monthly'
    ? Math.max(1, Number(courseForm.monthly_sessions) || 1)
    : Math.max(1, Number(courseForm.total_classes) || 1)
));
const plannedCountLabel = computed(() => (
  courseForm.payment_type === 'monthly' ? '本月預排堂數' : '購買總堂數'
));
const manualDates = computed(() => sortDates(scheduleForm.confirmed_dates || []));
const manualDateSet = computed(() => new Set(manualDates.value));
const confirmedDates = computed(() => manualDates.value.filter((date) => isManualDateConfirmed(date)));
const manualScheduledDates = computed(() => manualDates.value.filter((date) => !isManualDateConfirmed(date)));
const futureSessionCount = computed(() => manualScheduledDates.value.length + futureDates.value.length);
const monthlyAvailableTotal = computed(() => manualDates.value.length + futureDates.value.length);
const monthlyCapacityHint = computed(() => {
  if (courseForm.payment_type !== 'monthly') return '';
  if ((scheduleForm.days_of_week || []).length === 0) return '';
  return `本月依目前星期設定最多可排 ${monthlyAvailableTotal.value} 堂（含手動指定）。`;
});
const monthlyCapacityWarning = computed(() => {
  if (courseForm.payment_type !== 'monthly') return '';
  const requested = Number(plannedSessions.value || 0);
  if (requested <= 0) return '';
  if (requested <= monthlyAvailableTotal.value) return '';
  return '目前設定超出本月可排上限，請降低本月預排堂數或調整固定星期。';
});

const futureDates = computed(() => {
  const manual = manualDates.value;
  const remaining = plannedSessions.value - manual.length;
  if (remaining <= 0) return [];

  const daySet = new Set((scheduleForm.days_of_week || []).map((day) => Number(day)).filter((day) => day >= 1 && day <= 7));
  if (daySet.size === 0) return [];

  const todayYmd = getCurrentTodayYmd();
  const lastManual = manual.length > 0 ? manual[manual.length - 1] : null;
  const effectiveAnchor = lastManual || todayYmd;
  const targetMonthYm = courseForm.payment_type === 'monthly' ? effectiveAnchor.slice(0, 7) : '';
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
      && (courseForm.payment_type !== 'monthly' || ymd.slice(0, 7) === targetMonthYm)
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
const monthLabel = computed(() => {
  const year = currentMonth.value.getFullYear();
  const month = String(currentMonth.value.getMonth() + 1).padStart(2, '0');
  return `${year}-${month}`;
});
const calendarCells = computed(() => {
  const first = new Date(currentMonth.value.getFullYear(), currentMonth.value.getMonth(), 1);
  const firstWeekday = (first.getDay() + 6) % 7;
  const start = new Date(first);
  start.setDate(first.getDate() - firstWeekday);

  const cells = [];
  for (let index = 0; index < 42; index += 1) {
    const date = new Date(start);
    date.setDate(start.getDate() + index);
    const ymd = toYmd(date);

    let status = '';
    if (manualDateSet.value.has(ymd)) status = 'manual';
    else if (futureDateSet.value.has(ymd)) status = 'future';

    cells.push({
      key: ymd,
      ymd,
      day: date.getDate(),
      inMonth: date.getMonth() === currentMonth.value.getMonth(),
      status,
    });
  }
  return cells;
});

const selectedStudentLabel = computed(() => {
  const match = studentOptions.value.find((student) => String(student.value) === String(existingStudentId.value || ''));
  return match?.label || '未選擇';
});
const selectedTeacherLabel = computed(() => (
  props.teachers.find((teacher) => String(teacher.id) === String(courseForm.teacher_id || ''))?.name
  || props.teachers.find((teacher) => String(teacher.id) === String(courseForm.teacher_id || ''))?.Name
  || props.teachers.find((teacher) => String(teacher.id) === String(courseForm.teacher_id || ''))?.T_Name
  || props.teachers.find((teacher) => String(teacher.id) === String(courseForm.teacher_id || ''))?.username
  || props.teachers.find((teacher) => String(teacher.id) === String(courseForm.teacher_id || ''))?.LoginName
  || '未選擇'
));
const selectedSubjectLabel = computed(() => (
  subjectOptions.find((subject) => subject.value === courseForm.subject)?.label || courseForm.subject
));
const selectedRoomLabel = computed(() => (
  props.rooms.find((room) => String(room.id) === String(courseForm.room_id || ''))?.name || '未指定'
));
const weekdaySummary = computed(() => (
  (scheduleForm.days_of_week || []).map((day) => weekdayOptions.find((item) => item.value === Number(day))?.label).filter(Boolean).join('、')
));

const sortedSelectedDays = computed(() => (
  [...new Set((scheduleForm.days_of_week || []).map((d) => Number(d)).filter((d) => d >= 1 && d <= 7))].sort((a, b) => a - b)
));

const slotStartTimeByDay = computed(() => {
  const map = {};
  for (const slot of (scheduleForm.day_time_slots || [])) {
    const day = Number(slot?.day || 0);
    if (day >= 1 && day <= 7) map[day] = String(slot?.start_time || '').slice(0, 5);
  }
  return map;
});
const slotDurationByDay = computed(() => {
  const map = {};
  for (const slot of (scheduleForm.day_time_slots || [])) {
    const day = Number(slot?.day || 0);
    const dur = Number(slot?.duration_hours || 0);
    if (day >= 1 && day <= 7 && dur > 0) map[day] = dur;
  }
  return map;
});
const hasPerDayDuration = computed(() => {
  const vals = Object.values(slotDurationByDay.value);
  if (vals.length < 2) return false;
  return new Set(vals.map((v) => v.toFixed(1))).size > 1;
});

const weekdaySummaryWithTimes = computed(() => (
  sortedSelectedDays.value.map((d) => {
    const label = weekdayOptions.find((w) => w.value === d)?.label || `週${d}`;
    const time = slotStartTimeByDay.value[d] || scheduleForm.start_time;
    const dur = slotDurationByDay.value[d] ?? scheduleForm.duration_hours;
    return `${label} ${time} ${dur}h`;
  }).join('、')
));

watch(
  () => scheduleForm.days_of_week,
  () => { syncDayTimeSlotsFromSelection(); },
  { deep: true, immediate: true }
);

watch(
  () => scheduleForm.day_time_slots,
  (slots) => {
    if (Array.isArray(slots) && slots.length > 0 && slots[0]?.start_time) {
      scheduleForm.start_time = String(slots[0].start_time).slice(0, 5);
    }
  },
  { deep: true }
);

function syncDayTimeSlotsFromSelection() {
  const existing = new Map(
    (scheduleForm.day_time_slots || [])
      .map((slot) => [Number(slot?.day || 0), slot])
      .filter(([day]) => day >= 1 && day <= 7)
  );
  const firstSlot = existing.size > 0 ? [...existing.values()][0] : null;
  const baseTime = firstSlot ? String(firstSlot.start_time || '16:00').slice(0, 5) : '16:00';
  scheduleForm.day_time_slots = sortedSelectedDays.value.map((day) => {
    const ex = existing.get(day);
    return {
      day,
      start_time: ex ? String(ex.start_time || '16:00').slice(0, 5) : baseTime,
      duration_hours: ex?.duration_hours || scheduleForm.duration_hours,
    };
  });
}

function updateSlotTime(day, nextTime) {
  const d = Number(day);
  if (d < 1 || d > 7) return;
  const t = String(nextTime || '').slice(0, 5);
  scheduleForm.day_time_slots = (scheduleForm.day_time_slots || []).map((slot) => (
    Number(slot?.day) === d ? { ...slot, start_time: t } : slot
  ));
}

function updateSlotDuration(day, rawValue) {
  const d = Number(day);
  if (d < 1 || d > 7) return;
  const dur = Math.max(0.5, Math.round(Number(rawValue) * 2) / 2);
  scheduleForm.day_time_slots = (scheduleForm.day_time_slots || []).map((slot) => (
    Number(slot?.day) === d ? { ...slot, duration_hours: dur } : slot
  ));
}

function computeEndTimeDisplay(startRaw, durHours) {
  const start = String(startRaw || '');
  const duration = Number(durHours != null ? durHours : scheduleForm.duration_hours) || 0;
  if (!start || !duration) return '—';
  const [hRaw, mRaw] = start.split(':');
  const h = Number(hRaw);
  const m = Number(mRaw);
  if (!Number.isFinite(h) || !Number.isFinite(m)) return '—';
  const totalMins = h * 60 + m + duration * 60;
  const endH = Math.floor(totalMins / 60) % 24;
  const endM = totalMins % 60;
  return `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
}

function handleBack() {
  if (step.value === 1) {
    emit('cancel');
    return;
  }
  step.value -= 1;
}

function goNext() {
  if (step.value === 1 && !validateStudentStep()) return;
  if (step.value === 2 && !validateCourseStep()) return;
  if (step.value === 3 && !validateScheduleStep()) return;
  step.value += 1;
}

function validateStudentStep() {
  if (studentMode.value === 'existing' && !existingStudentId.value) {
    alert('請先選擇學生');
    return false;
  }
  if (studentMode.value === 'new' && !studentForm.name.trim()) {
    alert('請先輸入學生姓名');
    return false;
  }
  return true;
}

function validateCourseStep() {
  const branchId = Number(props.branchId || 0);
  if (branchId <= 0) {
    alert('請先選擇分校後再建立課程');
    return false;
  }
  if (!courseForm.teacher_id) {
    alert('請選擇老師');
    return false;
  }
  const selectedTeacher = (props.teachers || []).find((teacher) => String(teacher.id) === String(courseForm.teacher_id || ''));
  if (selectedTeacher) {
    const branchIds = Array.isArray(selectedTeacher.branch_ids)
      ? selectedTeacher.branch_ids.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0)
      : [];
    const teacherBranch = Number(selectedTeacher.branch_id || 0);
    if (branchIds.length > 0 && !branchIds.includes(branchId) && teacherBranch !== branchId) {
      alert('所選老師未綁定目前分校，請改選其他老師');
      return false;
    }
  }
  if (courseForm.payment_type === 'monthly') {
    if (!courseForm.settlement_day) {
      alert('月結課請選擇結算日');
      return false;
    }
    if ((Number(courseForm.monthly_sessions) || 0) <= 0) {
      alert('月結課請輸入本月預排堂數');
      return false;
    }
  } else if ((Number(courseForm.total_classes) || 0) <= 0) {
    alert('堂數制請輸入購買總堂數');
    return false;
  }
  return true;
}

function validateScheduleStep() {
  const durationMinutes = durationHoursToMinutes(scheduleForm.duration_hours);
  if (durationMinutes < 30 || durationMinutes > 480) {
    alert('上課時長需介於 0.5 至 8 小時');
    return false;
  }
  if (manualDates.value.length > plannedSessions.value) {
    alert(`手動指定日期不可超過${plannedCountLabel.value}`);
    return false;
  }
  const remaining = plannedSessions.value - manualDates.value.length;
  if (remaining > 0 && (scheduleForm.days_of_week || []).length === 0) {
    alert('尚有未排堂次，請先設定固定上課星期');
    return false;
  }
  if ((manualDates.value.length + futureDates.value.length) !== plannedSessions.value) {
    alert(`系統無法補齊至${plannedCountLabel.value}，請調整固定上課星期或手動指定日期`);
    return false;
  }
  if (courseForm.payment_type === 'monthly') {
    const targetYm = toYmd(currentMonth.value).slice(0, 7);
    const allDates = sortDates([...manualDates.value, ...futureDates.value]);
    const hasCrossMonth = allDates.some((date) => String(date).slice(0, 7) !== targetYm);
    if (hasCrossMonth) {
      alert('月結課程僅能建立在同一月份，請調整手動日期或月份。');
      return false;
    }
  }
  return true;
}

async function submit() {
  if (!validateStudentStep() || !validateCourseStep() || !validateScheduleStep()) return;

  submitting.value = true;
  try {
    const durationMinutes = durationHoursToMinutes(scheduleForm.duration_hours);
    const manualConfirmedDates = confirmedDates.value;
    const manualFutureDates = manualScheduledDates.value;
    const payload = {
      branch_id: props.branchId != null && props.branchId !== '' ? Number(props.branchId) : null,
      teacher_id: Number(courseForm.teacher_id),
      subject: courseForm.subject,
      class_type: courseForm.class_type,
      confirmed_dates: manualConfirmedDates,
      future_dates: sortDates([...manualFutureDates, ...futureDates.value]),
      days_of_week: [...(scheduleForm.days_of_week || [])].map((day) => Number(day)).filter((day) => day >= 1 && day <= 7),
      day_time_slots: (scheduleForm.day_time_slots || []).map((slot) => {
        const perDayDur = Number(slot?.duration_hours || 0);
        return {
          day: Number(slot?.day || 0),
          start_time: normalizeHalfHourTime(String(slot?.start_time || scheduleForm.start_time || '16:00')),
          duration_minutes: perDayDur > 0 ? durationHoursToMinutes(perDayDur) : durationMinutes,
        };
      }).filter((slot) => slot.day >= 1 && slot.day <= 7),
      start_time: normalizeHalfHourTime(scheduleForm.start_time || '00:00'),
      duration_minutes: durationMinutes,
      rate_unit: hasPerDayDuration.value ? 'hour' : 'session',
      price_per_session: Math.max(0, Number(courseForm.price_per_session) || 0),
      payment_type: courseForm.payment_type || 'session',
      settlement_day: courseForm.payment_type === 'monthly' ? Number(courseForm.settlement_day) || null : null,
      monthly_sessions: courseForm.payment_type === 'monthly' ? plannedSessions.value : null,
      room_id: courseForm.room_id ? Number(courseForm.room_id) : null,
      memo: courseForm.memo || null,
      mode: 'enrollment',
    };

    if (courseForm.payment_type === 'session') {
      payload.total_classes = plannedSessions.value;
    }

    if (studentMode.value === 'existing') {
      payload.student_id = Number(existingStudentId.value);
    } else {
      payload.student = {
        name: studentForm.name.trim(),
        grade: studentForm.grade,
        school: studentForm.school || '',
        phone: studentForm.phone || '',
        parent_name: studentForm.parent_name || '',
        parent_phone: studentForm.parent_phone || '',
        notes: studentForm.notes || '',
        status: 'active',
      };
    }

    const result = await createEnrollment(payload);
    let msg = `已建立學生課程與 ${Number(result?.created_sessions || 0)} 堂課`;
    if (result?.scope_warning) {
      msg += `\n\n⚠️ 學段提示：${result.scope_warning}`;
    }
    alert(msg);
    emit('success', result);
  } catch (error) {
    alert(error?.message || '入班失敗，請稍後再試');
  } finally {
    submitting.value = false;
  }
}

function toYmd(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
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

function sortDates(values) {
  return [...new Set(values)].sort((left, right) => left.localeCompare(right));
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
  const dayDur = slotDurationByDay.value[weekday] ?? scheduleForm.duration_hours;
  const normalizedStart = normalizeHalfHourTime(slotStartTimeByDay.value[weekday] || scheduleForm.start_time || '00:00');
  const durationMinutes = durationHoursToMinutes(dayDur);
  if (durationMinutes < 30) return null;
  const [year, month, day] = String(ymd || '').split('-').map(Number);
  const [hour, minute] = normalizedStart.split(':').map(Number);
  if (!year || !month || !day || !Number.isFinite(hour) || !Number.isFinite(minute)) return null;
  const endAt = new Date(year, month - 1, day, hour, minute, 0, 0);
  endAt.setMinutes(endAt.getMinutes() + durationMinutes);
  return endAt;
}

function isTodaySessionEnded() {
  const todayYmd = getCurrentTodayYmd();
  const sessionEndAt = getSessionEndDateTime(todayYmd);
  if (!sessionEndAt) return false;
  return new Date() >= sessionEndAt;
}

function isManualDateConfirmed(ymd) {
  const todayYmd = getCurrentTodayYmd();
  if (ymd < todayYmd) return true;
  if (ymd > todayYmd) return false;
  return isTodaySessionEnded();
}

function onDateClick(cell) {
  if (!cell?.ymd) return;
  if (courseForm.payment_type === 'monthly') {
    const targetYm = toYmd(currentMonth.value).slice(0, 7);
    if (cell.ymd.slice(0, 7) !== targetYm) {
      alert('月結課程僅可選擇同一月份日期。');
      return;
    }
  }
  const current = new Set(manualDateSet.value);
  if (current.has(cell.ymd)) {
    current.delete(cell.ymd);
    scheduleForm.confirmed_dates = sortDates([...current]);
    return;
  }

  if (current.size >= plannedSessions.value) {
    alert(`手動指定日期不可超過${plannedCountLabel.value}（${plannedSessions.value}）。`);
    return;
  }

  current.add(cell.ymd);
  scheduleForm.confirmed_dates = sortDates([...current]);
}

function classTypeLabel(type) {
  return {
    one_on_one: '一對一',
    one_on_two: '一對二',
    one_on_three: '一對三',
    tutoring: '輔導',
  }[type] || type;
}
</script>

<style scoped>
.enrollment-modal {
  width: min(1120px, 96vw);
  max-height: 94vh;
  overflow-y: auto;
  padding: 0;
  border-radius: 20px;
  background: #f9fafb;
}

.wizard-header {
  padding: 22px 24px 16px;
  border-bottom: 1px solid #e5e7eb;
  background: #fff;
}

.wizard-subtitle {
  margin-top: 6px;
  color: var(--text-light);
  font-size: 13px;
}

.wizard-steps {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 14px;
}

.wizard-step {
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid #d6deeb;
  font-size: 12px;
  color: #60758f;
  background: #f8fbff;
}

.wizard-step.active {
  border-color: #4b8ef7;
  background: #ecf4ff;
  color: #1858bc;
}

.wizard-step.done {
  border-color: #52b36f;
  background: #ecf9ef;
  color: #177a3c;
}

.wizard-section {
  padding: 18px 20px;
}

.wizard-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
  gap: 16px;
}

.panel-stack {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.wizard-card {
  border: 1px solid #e5e7eb;
  border-radius: 18px;
  padding: 16px;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}

.wizard-card h4 {
  margin-bottom: 12px;
  font-size: 14px;
}

.wizard-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.summary-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-group-full {
  grid-column: 1 / -1;
}

.toggle-row {
  display: flex;
  gap: 8px;
  margin-bottom: 14px;
}

.mode-chip {
  border: 1px solid #d6deeb;
  border-radius: 999px;
  padding: 8px 14px;
  background: #fff;
  cursor: pointer;
}

.mode-chip.active {
  background: #ecf4ff;
  border-color: #4b8ef7;
  color: #1858bc;
}

.weekday-row {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 12px;
}

.weekday-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 11px;
  border-radius: 999px;
  border: 1px solid #d6deeb;
  background: #fff;
  cursor: pointer;
}

.weekday-chip.selected {
  background: #ecf4ff;
  border-color: #6ea8fe;
}

.day-time-slots {
  margin-top: 12px;
  display: grid;
  gap: 8px;
}

.day-time-slot-row {
  display: grid;
  grid-template-columns: 52px minmax(120px, 170px) auto;
  align-items: center;
  gap: 8px;
}
.day-time-slot-row.per-day-row {
  grid-template-columns: 52px minmax(100px, 150px) 70px auto;
}
.per-day-dur {
  width: 70px;
  text-align: center;
  padding: 5px 4px;
  border: 1px solid #d6deeb;
  border-radius: 8px;
  font-size: 13px;
}

.day-time-slot-label {
  font-size: 13px;
  color: var(--text-light, #64748b);
}

.day-time-slot-end {
  font-size: 13px;
  color: var(--text-light, #64748b);
}

.field-note,
.hint-text {
  font-size: 12px;
  color: var(--text-light);
  line-height: 1.55;
}
.warning-text {
  color: #b45309;
  font-weight: 600;
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

.wizard-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 0 20px 18px;
}

@media (max-width: 980px) {
  .wizard-layout,
  .wizard-grid,
  .summary-grid,
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
