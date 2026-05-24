<template>
  <div class="course-form-wrapper">
    <p v-if="contextTitle" class="edit-context-title">{{ contextTitle }}</p>

    <div v-if="packageInfo" class="package-info-banner">
      <span class="package-info-icon">📦</span>
      <div>
        <strong>{{ packageInfo.name || '共用方案' }}</strong>
        <span class="package-info-pool">方案池剩餘 {{ packageInfo.remaining_sessions ?? 0 }} / {{ packageInfo.total_sessions ?? 0 }} 堂</span>
      </div>
    </div>

    <div v-if="scopeWarning" class="scope-warning-banner">
      ⚠️ {{ scopeWarning }}（仍可儲存）
    </div>

    <!-- Section 1: 課程基本資訊 -->
    <section class="form-section">
      <h4 class="form-section-title">課程基本資訊</h4>
      <div class="form-section-grid">
        <div class="form-group" :class="{ 'field-has-error': fieldErrors.subject }">
          <label>科目 <span class="required-star">*</span></label>
          <select v-model="form.subject">
            <option v-for="s in subjects" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
          <span v-if="fieldErrors.subject" class="field-error">{{ fieldErrors.subject }}</span>
        </div>

        <div class="form-group" :class="{ 'field-has-error': fieldErrors.teacher_id }">
          <label>老師 <span class="required-star">*</span></label>
          <select v-model="form.teacher_id">
            <option value="">請選擇</option>
            <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
          </select>
          <span v-if="fieldErrors.teacher_id" class="field-error">{{ fieldErrors.teacher_id }}</span>
          <div v-if="form.teacher_id" class="teacher-schedule-hint">
            <span v-if="teacherScheduleLoading" class="teacher-schedule-meta">載入排課中…</span>
            <template v-else-if="teacherSchedule.length">
              <span class="teacher-schedule-meta">近兩週排課：</span>
              <span v-for="s in teacherScheduleDisplay" :key="s.key" class="teacher-busy-chip">{{ s.label }}</span>
            </template>
            <span v-else class="teacher-schedule-meta teacher-schedule-empty">近兩週無排課</span>
          </div>
          <p v-if="teacherChanged" class="field-hint field-hint--warning">
            已更換正班老師；儲存後未來未上堂次會改由新老師負責，已點名／已核准歷史堂次不改。
          </p>
        </div>

        <div class="form-group">
          <label>開課日</label>
          <input v-model="form.first_class_date" type="date" />
        </div>

        <div class="form-group">
          <label>類型</label>
          <select v-model="form.class_type">
            <option value="one_on_one">一對一</option>
            <option value="one_on_two">一對二</option>
            <option value="one_on_three">一對三</option>
            <option value="tutoring">輔導</option>
            <option value="trial">試聽</option>
          </select>
        </div>

        <div class="form-group span-full">
          <label>上課地點（教室）</label>
          <select v-model="form.room_id">
            <option :value="null">請選擇教室</option>
            <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.name }}{{ r.memo ? ' — ' + r.memo : '' }}</option>
          </select>
        </div>
      </div>
    </section>

    <!-- Section 2: 費用與繳費 -->
    <section class="form-section">
      <h4 class="form-section-title">費用與繳費</h4>
      <div class="form-section-grid">
        <div class="form-group">
          <label>費率計算方式</label>
          <div class="rate-unit-toggle">
            <button
              type="button"
              class="rate-unit-btn"
              :class="{ active: effectiveRateUnit === 'session' }"
              @click="form.rate_unit = 'session'"
              :disabled="hasPerDayDuration"
            >按堂計費</button>
            <button
              type="button"
              class="rate-unit-btn"
              :class="{ active: effectiveRateUnit === 'hour' }"
              @click="form.rate_unit = 'hour'"
            >按時計費</button>
          </div>
          <span v-if="hasPerDayDuration" class="rate-unit-hint">不同時段時長不一，自動切換為按時計費</span>
        </div>

        <div class="form-group">
          <label>{{ effectiveRateUnit === 'hour' ? '每小時費用（元）' : '單堂費用（元）' }}</label>
          <input v-model.number="form.rate_per_30min" type="number" min="0" step="1" placeholder="1500" />
        </div>

        <div class="form-group">
          <label>預設上課時長（小時）</label>
          <select v-model.number="form.duration_hours">
            <option :value="1">1 小時</option>
            <option :value="1.5">1.5 小時</option>
            <option :value="2">2 小時</option>
            <option :value="2.5">2.5 小時</option>
            <option :value="3">3 小時</option>
          </select>
        </div>

        <div class="form-group">
          <label>繳費方式</label>
          <select v-model="form.payment_type">
            <option value="session">堂數制</option>
            <option value="monthly">月結</option>
          </select>
        </div>

        <div v-if="form.payment_type === 'session'" class="form-group">
          <label>購買堂數</label>
          <input
            v-model.number="form.sessions_purchased"
            type="number"
            min="0"
            placeholder="8"
            :disabled="!!packageInfo"
          />
          <span v-if="packageInfo" class="field-hint field-hint--info">此欄位由共用方案管理，不可直接修改</span>
        </div>

        <template v-if="form.payment_type === 'monthly'">
          <div class="form-group" :class="{ 'field-has-warning': fieldWarnings.settlement_day }">
            <label>結算日（每月幾號）</label>
            <select v-model.number="form.settlement_day">
              <option :value="null">請選擇</option>
              <option v-for="d in settlementDayOptions" :key="d" :value="d">每月 {{ d }} 號</option>
            </select>
            <span v-if="fieldWarnings.settlement_day" class="field-warning">{{ fieldWarnings.settlement_day }}</span>
          </div>
          <div class="form-group">
            <label>每月堂數（選填）</label>
            <input v-model.number="form.monthly_sessions" type="number" min="0" placeholder="依學生個案" />
          </div>
        </template>

        <div class="form-group">
          <label>繳費日期（選填）</label>
          <input v-model="form.paid_at" type="date" />
          <p v-if="form.paid_at" class="field-hint field-hint--success">已填寫繳費日期，儲存後將自動標示為已繳費</p>
          <p v-else class="field-hint field-hint--warning">清空繳費日期儲存後，將改為未繳費</p>
        </div>

        <div v-if="showRemaining && !packageInfo" class="form-group">
          <label>剩餘堂數</label>
          <input v-model.number="form.remaining_sessions" type="number" />
        </div>
      </div>
    </section>

    <!-- Section 3: 排課時段 -->
    <section class="form-section">
      <h4 class="form-section-title">排課時段</h4>
      <div class="form-section-grid">
        <div class="form-group span-full">
          <label>固定排課日（可多選）</label>
          <div class="day-checkbox-group">
            <label
              v-for="d in dayOptions"
              :key="d.value"
              :class="['day-chip', { selected: selectedDaySet.has(d.value) }]"
            >
              <input
                type="checkbox"
                :value="d.value"
                v-model="form.days_of_week"
                style="position:absolute;opacity:0;width:0;height:0;pointer-events:none;"
              />
              {{ d.label }}
            </label>
          </div>
          <div class="day-time-slots">
            <div
              v-for="(slot, idx) in (form.day_time_slots || [])"
              :key="`slot-${idx}-${slot.day}-${slot.start_time}`"
              class="day-time-slot-row per-day-row"
            >
              <select
                class="day-inline-select"
                :value="Number(slot.day)"
                @change="updateSlotDay(idx, $event.target.value)"
              >
                <option v-for="d in dayOptions" :key="`opt-${idx}-${d.value}`" :value="d.value">週{{ d.label }}</option>
              </select>
              <select
                :value="String(slot.start_time || form.start_time).slice(0, 5)"
                @change="updateSlotTime(idx, $event.target.value)"
              >
                <option v-for="t in timeOptions" :key="`${idx}-${t}`" :value="t">{{ t }}</option>
              </select>
              <input
                type="number"
                class="per-day-dur"
                :value="Number(slot.duration_hours ?? form.duration_hours)"
                min="0.5"
                step="0.5"
                inputmode="decimal"
                @change="updateSlotDuration(idx, $event.target.value)"
              />
              <span class="day-time-slot-end">~ {{ computeEndTime(slot.start_time || form.start_time, slot.duration_hours ?? form.duration_hours) || '—' }}</span>
              <button
                v-if="(form.day_time_slots || []).length > 1"
                type="button"
                class="btn-remove-slot"
                @click="removeSlot(idx)"
              >
                移除
              </button>
            </div>
            <button
              v-if="(form.day_time_slots || []).length < 7"
              type="button"
              class="btn-add-slot"
              @click="addTimeSlot"
            >
              ＋ 新增時段（同日可排多段，最多 7 段）
            </button>
            <p class="slot-help-text">
              每一列就是一個固定時段，可直接新增一列並選週日；儲存後系統會重排未來未上堂次，已點名／已核准堂次保留原狀。
            </p>
          </div>
        </div>

        <div class="form-group span-full">
          <label>備註（選填）</label>
          <textarea v-model="form.memo" rows="2" placeholder="課程或地點補充"></textarea>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { checkTeacherScope } from '../lib/constants';

const props = defineProps({
  modelValue: { type: Object, required: true },
  teachers: { type: Array, default: () => [] },
  rooms: { type: Array, default: () => [] },
  subjects: { type: Array, default: () => [] },
  dayOptions: { type: Array, default: () => [] },
  timeOptions: { type: Array, default: () => [] },
  settlementDayOptions: { type: Array, default: () => [] },
  showRemaining: { type: Boolean, default: false },
  studentGrade: { type: [String, Number], default: null },
  packageInfo: { type: Object, default: null },
  contextTitle: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const defaultForm = {
  subject: 'Math',
  teacher_id: '',
  class_type: 'one_on_one',
  rate_per_30min: 0,
  rate_unit: 'session',
  duration_hours: 2,
  payment_type: 'session',
  sessions_purchased: 8,
  settlement_day: null,
  monthly_sessions: null,
  day_of_week: 0,
  days_of_week: [],
  day_time_slots: [],
  start_time: '16:00',
  end_time: '18:00',
  first_class_date: '',
  room_id: null,
  memo: '',
  remaining_sessions: 0,
  paid_at: '',
  original_paid_at: '',
  original_teacher_id: '',
};

const form = reactive({ ...defaultForm, ...(props.modelValue || {}) });
let syncingFromParent = false;
let lastEmittedModel = null;

const teacherSchedule = ref([]);
const teacherScheduleLoading = ref(false);

const teacherScheduleDisplay = computed(() => {
  const dayNames = ['', '一', '二', '三', '四', '五', '六', '日'];
  const seen = new Set();
  return teacherSchedule.value
    .map((s, i) => {
      const d = new Date(s.date + 'T00:00:00');
      const dow = d.getDay() || 7;
      const mm = d.getMonth() + 1;
      const dd = d.getDate();
      return { key: i, label: `${mm}/${dd}(${dayNames[dow]}) ${s.start}` };
    })
    .filter((s) => { if (seen.has(s.label)) return false; seen.add(s.label); return true; });
});

const selectedTeacher = computed(() => {
  const tid = Number(form.teacher_id);
  return tid > 0 ? (props.teachers || []).find((t) => Number(t.id) === tid) : null;
});

const scopeWarning = computed(() => {
  const teacher = selectedTeacher.value;
  if (!teacher) return null;
  return checkTeacherScope(teacher, form.subject, props.studentGrade, props.subjects);
});

const dayLabelMap = { 1: '一', 2: '二', 3: '三', 4: '四', 5: '五', 6: '六', 7: '日' };
const sortedSelectedDays = computed(() => (
  [...new Set((form.days_of_week || []).map((d) => Number(d)).filter((d) => d >= 1 && d <= 7))].sort((a, b) => a - b)
));
const selectedDaySet = computed(() => new Set(sortedSelectedDays.value));
const hasPerDayDuration = computed(() => {
  const vals = (form.day_time_slots || [])
    .map((s) => Number(s?.duration_hours || 0))
    .filter((v) => v > 0);
  if (vals.length < 2) return false;
  return new Set(vals.map((v) => v.toFixed(1))).size > 1;
});
const effectiveRateUnit = computed(() => {
  if (hasPerDayDuration.value) return 'hour';
  return form.rate_unit === 'hour' ? 'hour' : 'session';
});
const teacherChanged = computed(() => {
  if (!form.original_teacher_id || !form.teacher_id) return false;
  return String(form.original_teacher_id) !== String(form.teacher_id);
});

const fieldErrors = computed(() => {
  const errors = {};
  if (!form.subject) errors.subject = '科目為必填';
  if (!form.teacher_id) errors.teacher_id = '老師為必填';
  return errors;
});

const fieldWarnings = computed(() => {
  const warnings = {};
  if (form.payment_type === 'monthly' && !form.settlement_day) {
    warnings.settlement_day = '月結方式建議設定結算日';
  }
  return warnings;
});

const hasErrors = computed(() => Object.keys(fieldErrors.value).length > 0);

defineExpose({ hasErrors, fieldErrors });

watch(
  () => form.teacher_id,
  async (tid) => {
    teacherSchedule.value = [];
    if (!tid) return;
    teacherScheduleLoading.value = true;
    try {
      const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
      const token = session?.access_token;
      if (!token) return;
      const today = new Date();
      const from = today.toISOString().slice(0, 10);
      const toDate = new Date(today);
      toDate.setDate(today.getDate() + 13);
      const to = toDate.toISOString().slice(0, 10);
      const resp = await fetch(
        `/api/v1/class-sessions?teacher_id=${tid}&start=${from}&end=${to}&status=scheduled&per_page=200`,
        { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }
      );
      if (!resp.ok) return;
      const json = await resp.json();
      teacherSchedule.value = (json.data || []).map((s) => ({
        date: s.session_date || s.SessionDate || '',
        start: String(s.start_time || s.StartTime || '').slice(0, 5),
        end: String(s.end_time || s.EndTime || '').slice(0, 5),
      }));
    } catch (_) {
      // silently ignore
    } finally {
      teacherScheduleLoading.value = false;
    }
  }
);

watch(
  () => props.modelValue,
  (next) => {
    if (next === lastEmittedModel) return;
    syncingFromParent = true;
    Object.assign(form, defaultForm, next || {});
    if (!Array.isArray(form.days_of_week)) form.days_of_week = [];
    if (!Array.isArray(form.day_time_slots)) form.day_time_slots = [];
    syncDayTimeSlotsFromSelection();
    nextTick(() => {
      syncingFromParent = false;
    });
  },
  { immediate: true }
);

watch(
  () => form.days_of_week,
  () => {
    if (syncingFromParent) return;
    syncDayTimeSlotsFromSelection();
  },
  { deep: true }
);

watch(
  form,
  () => {
    if (syncingFromParent) return;
    const payload = {
      ...form,
      rate_unit: effectiveRateUnit.value,
      days_of_week: sortedSelectedDays.value,
      day_time_slots: [...(form.day_time_slots || [])].map((slot) => ({
        day: Number(slot?.day || 0),
        start_time: String(slot?.start_time || '').slice(0, 5),
        duration_hours: Number(slot?.duration_hours || 0) || undefined,
        duration_minutes: Number(slot?.duration_hours || 0) > 0 ? Math.round(Number(slot.duration_hours) * 60) : undefined,
      })),
    };
    lastEmittedModel = payload;
    emit('update:modelValue', payload);
  },
  { deep: true }
);

watch(
  () => form.day_time_slots,
  (slots) => {
    if (syncingFromParent) return;
    if (Array.isArray(slots) && slots.length > 0 && slots[0]?.start_time) {
      form.start_time = String(slots[0].start_time).slice(0, 5);
    }
  },
  { deep: true }
);

function syncDayTimeSlotsFromSelection() {
  let chipDays = new Set(
    [...new Set((form.days_of_week || []).map((d) => Number(d)).filter((d) => d >= 1 && d <= 7))]
  );
  let slots = [...(form.day_time_slots || [])];

  if (chipDays.size === 0 && slots.length > 0 && syncingFromParent) {
    form.days_of_week = [...new Set(slots.map((s) => Number(s?.day || 0)).filter((d) => d >= 1 && d <= 7))].sort((a, b) => a - b);
    chipDays = new Set(form.days_of_week);
  }

  if (syncingFromParent && slots.length > 0) {
    const fromSlots = slots.map((s) => Number(s?.day || 0)).filter((d) => d >= 1 && d <= 7);
    const fromParent = Array.isArray(form.days_of_week) ? form.days_of_week.map(Number).filter((d) => d >= 1 && d <= 7) : [];
    const mergedDays = [...new Set([...fromParent, ...fromSlots])].sort((a, b) => a - b);
    form.days_of_week = mergedDays;
    chipDays = new Set(mergedDays);
  }

  if (chipDays.size > 0) {
    slots = slots.filter((s) => chipDays.has(Number(s?.day || 0)));
  } else if (!syncingFromParent) {
    slots = [];
  }

  const baseTime = String(form.start_time || '16:00').slice(0, 5);
  const dur = form.duration_hours || 2;
  for (const day of [...chipDays].sort((a, b) => a - b)) {
    if (!slots.some((s) => Number(s.day) === day)) {
      slots.push({
        day,
        start_time: baseTime,
        duration_hours: dur,
      });
    }
  }
  slots.sort((a, b) => Number(a.day) - Number(b.day) || String(a.start_time || '').localeCompare(String(b.start_time || '')));
  form.day_time_slots = slots;
}

function addTimeSlot() {
  if ((form.day_time_slots || []).length >= 7) return;
  const usedDays = new Set((form.day_time_slots || []).map((slot) => Number(slot?.day || 0)));
  const firstUnused = (props.dayOptions || []).map((d) => Number(d.value)).find((day) => day >= 1 && day <= 7 && !usedDays.has(day));
  const days = sortedSelectedDays.value;
  const day = firstUnused || (days.length ? days[days.length - 1] : 1);
  const baseTime = String(form.start_time || '16:00').slice(0, 5);
  form.day_time_slots = [...(form.day_time_slots || []), {
    day,
    start_time: baseTime,
    duration_hours: form.duration_hours || 2,
  }];
  form.days_of_week = [...new Set([...(form.days_of_week || []).map(Number), day])]
    .filter((d) => d >= 1 && d <= 7)
    .sort((a, b) => a - b);
}

function removeSlot(idx) {
  const slots = form.day_time_slots || [];
  const target = slots[idx];
  if (!target) return;
  const targetDay = Number(target.day || 0);
  const sameDayCount = slots.filter((s) => Number(s?.day || 0) === targetDay).length;
  form.day_time_slots = slots.filter((_, i) => i !== idx);
  if (sameDayCount <= 1 && targetDay >= 1 && targetDay <= 7) {
    form.days_of_week = (form.days_of_week || []).filter((d) => Number(d) !== targetDay);
  }
  syncDayTimeSlotsFromSelection();
}

function updateSlotDay(idx, dayVal) {
  const d = Number(dayVal);
  if (d < 1 || d > 7) return;
  const currentSlots = form.day_time_slots || [];
  const oldDay = Number(currentSlots[idx]?.day || 0);
  form.day_time_slots = (form.day_time_slots || []).map((slot, i) => (
    i === idx ? { ...slot, day: d } : slot
  ));
  const remainingOldDay = form.day_time_slots.some((slot, i) => i !== idx && Number(slot?.day || 0) === oldDay);
  const nextDays = new Set((form.days_of_week || []).map(Number).filter((day) => day >= 1 && day <= 7));
  nextDays.add(d);
  if (oldDay >= 1 && oldDay <= 7 && !remainingOldDay) nextDays.delete(oldDay);
  form.days_of_week = [...nextDays].sort((a, b) => a - b);
}

function updateSlotTime(idx, nextTime) {
  const t = String(nextTime || '').slice(0, 5);
  form.day_time_slots = (form.day_time_slots || []).map((slot, i) => (
    i === idx ? { ...slot, start_time: t } : slot
  ));
}

function updateSlotDuration(idx, rawValue) {
  const dur = Math.max(0.5, Math.round(Number(rawValue) * 2) / 2);
  form.day_time_slots = (form.day_time_slots || []).map((slot, i) => (
    i === idx ? { ...slot, duration_hours: dur } : slot
  ));
}

function computeEndTime(startRaw, durHours) {
  const start = String(startRaw || '');
  const duration = Number(durHours != null ? durHours : form.duration_hours) || 0;
  if (!start || !duration) return '';
  const [hRaw, mRaw] = start.split(':');
  const h = Number(hRaw);
  const m = Number(mRaw);
  if (!Number.isFinite(h) || !Number.isFinite(m)) return '';
  const totalMins = h * 60 + m + duration * 60;
  const endH = Math.floor(totalMins / 60) % 24;
  const endM = totalMins % 60;
  return `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
}

</script>

<style scoped>
.course-form-wrapper {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.edit-context-title {
  margin: 0;
  font-size: 13px;
  color: #64748b;
  line-height: 1.4;
}

.package-info-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: #f0fdf4;
  border: 1px solid #86efac;
  border-radius: 8px;
  font-size: 13px;
  color: #166534;
}
.package-info-icon { font-size: 18px; }
.package-info-pool {
  display: block;
  font-size: 12px;
  color: #15803d;
  margin-top: 2px;
}

.form-section {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 16px;
  background: #fff;
}

.form-section-title {
  margin: 0 0 14px 0;
  font-size: 14px;
  font-weight: 600;
  color: #334155;
  padding-bottom: 8px;
  border-bottom: 1px solid #f1f5f9;
}

.form-section-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px 16px;
}

.rate-unit-toggle {
  display: flex;
  gap: 0;
  border: 1px solid var(--border-main, #d1d5db);
  border-radius: 6px;
  overflow: hidden;
  width: fit-content;
}
.rate-unit-btn {
  padding: 5px 14px;
  font-size: 0.85rem;
  border: none;
  background: #fff;
  cursor: pointer;
  color: #374151;
  transition: background 0.15s, color 0.15s;
  &:first-child { border-right: 1px solid var(--border-main, #d1d5db); }
  &.active { background: var(--color-primary, #1a56db); color: #fff; }
  &:disabled { opacity: 0.5; cursor: default; }
}
.rate-unit-hint {
  font-size: 0.75rem;
  color: #6b7280;
  margin-top: 2px;
  display: block;
}

.span-full {
  grid-column: 1 / -1;
}

.required-star {
  color: #dc2626;
  font-weight: 700;
}

.field-has-error select,
.field-has-error input {
  border-color: #dc2626 !important;
}
.field-error {
  display: block;
  font-size: 12px;
  color: #dc2626;
  margin-top: 4px;
}

.field-has-warning select,
.field-has-warning input {
  border-color: #f59e0b !important;
}
.field-warning {
  display: block;
  font-size: 12px;
  color: #b45309;
  margin-top: 4px;
}

.day-checkbox-group {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.day-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 36px;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid var(--border, #d1d5db);
  cursor: pointer;
  user-select: none;
  font-size: 13px;
}

.day-chip.selected {
  background: var(--primary-bg, #e8f0ff);
  border-color: var(--primary, #2563eb);
  color: var(--primary, #2563eb);
  font-weight: 700;
}

.day-time-slots {
  margin-top: 10px;
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
  grid-template-columns: minmax(52px, 88px) minmax(100px, 150px) 70px 1fr auto;
  align-items: center;
}
.day-inline-select {
  max-width: 88px;
  padding: 6px 8px;
  border-radius: 8px;
  border: 1px solid var(--border, #d1d5db);
  font-size: 13px;
}
.btn-add-slot {
  margin-top: 4px;
  padding: 8px 12px;
  border-radius: 8px;
  border: 1px dashed var(--border, #cbd5e1);
  background: var(--surface-2, #f8fafc);
  color: var(--primary, #2563eb);
  font-size: 13px;
  cursor: pointer;
  width: fit-content;
}
.btn-add-slot:hover {
  border-color: var(--primary, #2563eb);
  background: var(--primary-bg, #eef2ff);
}
.slot-help-text {
  margin: 0;
  color: #64748b;
  font-size: 12px;
  line-height: 1.5;
}
.btn-remove-slot {
  padding: 4px 10px;
  font-size: 12px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: #64748b;
  cursor: pointer;
}
.btn-remove-slot:hover {
  color: #b91c1c;
  background: #fef2f2;
}
.per-day-dur {
  width: 70px;
  text-align: center;
  padding: 5px 4px;
  border: 1px solid var(--border, #d1d5db);
  border-radius: 8px;
  font-size: 13px;
}

.day-time-slot-label {
  font-size: 12px;
  color: var(--text-light, #64748b);
}

.day-time-slot-end {
  font-size: 12px;
  color: var(--text-light, #64748b);
}

.computed-end-time {
  margin: 0;
  min-height: 38px;
  display: flex;
  align-items: center;
  padding: 8px 10px;
  border-radius: 6px;
  background: var(--primary-bg, #eef2ff);
  color: var(--primary, #1d4ed8);
  font-weight: 600;
}

.field-hint { font-size: 12px; margin-top: 4px; line-height: 1.4; }
.field-hint--success { color: #2e7d32; }
.field-hint--warning { color: #b26a00; }
.field-hint--info { color: #1e40af; font-style: italic; }

@media (max-width: 720px) {
  .form-section-grid {
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

.teacher-schedule-hint {
  margin-top: 6px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
}
.teacher-schedule-meta {
  font-size: 11px;
  color: var(--text-light, #64748b);
  white-space: nowrap;
}
.teacher-schedule-empty { font-style: italic; }
.teacher-busy-chip {
  display: inline-block;
  font-size: 11px;
  padding: 2px 7px;
  border-radius: 999px;
  background: #fef3c7;
  color: #92400e;
  white-space: nowrap;
}
</style>
