<template>
  <div class="course-form-grid">
    <div class="form-group">
      <label>科目</label>
      <select v-model="form.subject">
        <option v-for="s in subjects" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>
    </div>

    <div class="form-group">
      <label>老師</label>
      <select v-model="form.teacher_id">
        <option value="">請選擇</option>
        <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
      </select>
    </div>

    <div v-if="scopeWarning" class="scope-warning-banner" style="grid-column: 1 / -1;">
      ⚠️ {{ scopeWarning }}（仍可儲存）
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
      </select>
    </div>

    <div class="form-group">
      <label>{{ hasPerDayDuration ? '每小時費用（元）' : '單堂費用（元）' }}</label>
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
      <input v-model.number="form.sessions_purchased" type="number" min="0" placeholder="8" />
    </div>

    <template v-if="form.payment_type === 'monthly'">
      <div class="form-group">
        <label>結算日（每月幾號）</label>
        <select v-model.number="form.settlement_day">
          <option :value="null">請選擇</option>
          <option v-for="d in settlementDayOptions" :key="d" :value="d">每月 {{ d }} 號</option>
        </select>
      </div>
      <div class="form-group">
        <label>每月堂數（選填）</label>
        <input v-model.number="form.monthly_sessions" type="number" min="0" placeholder="依學生個案" />
      </div>
    </template>

    <div v-if="showRemaining" class="form-group">
      <label>剩餘堂數</label>
      <input v-model.number="form.remaining_sessions" type="number" />
    </div>

    <div class="form-group span-full">
      <label>固定排課日（可多選）</label>
      <div class="day-checkbox-group">
        <label
          v-for="d in dayOptions"
          :key="d.value"
          :class="['day-chip', { selected: (form.days_of_week || []).includes(d.value) }]"
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
      <div v-if="(form.days_of_week || []).length > 0" class="day-time-slots">
        <div
          v-for="(slot, idx) in (form.day_time_slots || [])"
          :key="`slot-${idx}-${slot.day}-${slot.start_time}`"
          class="day-time-slot-row per-day-row"
        >
          <select
            v-if="sortedSelectedDays.length > 1"
            class="day-inline-select"
            :value="Number(slot.day)"
            @change="updateSlotDay(idx, $event.target.value)"
          >
            <option v-for="d in sortedSelectedDays" :key="`opt-${idx}-${d}`" :value="d">週{{ dayLabelMap[d] }}</option>
          </select>
          <span v-else class="day-time-slot-label">週{{ dayLabelMap[Number(slot.day)] || '?' }}</span>
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
      </div>
    </div>

    <!-- start_time auto-derived from first day_time_slot -->

    <div class="form-group span-full">
      <label>上課地點（教室）</label>
      <select v-model="form.room_id">
        <option :value="null">請選擇教室</option>
        <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.name }}{{ r.memo ? ' — ' + r.memo : '' }}</option>
      </select>
    </div>

    <div class="form-group span-full">
      <label>備註（選填）</label>
      <textarea v-model="form.memo" rows="2" placeholder="課程或地點補充"></textarea>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, reactive, watch } from 'vue';
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
});

const emit = defineEmits(['update:modelValue']);

const defaultForm = {
  subject: 'Math',
  teacher_id: '',
  class_type: 'one_on_one',
  rate_per_30min: 0,
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
};

const form = reactive({ ...defaultForm, ...(props.modelValue || {}) });
let syncingFromParent = false;
let lastEmittedModel = null;

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
const hasPerDayDuration = computed(() => {
  const vals = (form.day_time_slots || [])
    .map((s) => Number(s?.duration_hours || 0))
    .filter((v) => v > 0);
  if (vals.length < 2) return false;
  return new Set(vals.map((v) => v.toFixed(1))).size > 1;
});

watch(
  () => props.modelValue,
  (next) => {
    if (next === lastEmittedModel) return;
    syncingFromParent = true;
    Object.assign(form, defaultForm, next || {});
    if (!Array.isArray(form.days_of_week)) form.days_of_week = [];
    if (!Array.isArray(form.day_time_slots)) form.day_time_slots = [];
    syncDayTimeSlotsFromSelection();
    // Keep parent-sync guard for this microtask so deep form watcher
    // does not emit immediately and bounce the same payload back.
    nextTick(() => {
      syncingFromParent = false;
    });
  },
  { immediate: true }
);

watch(
  form,
  () => {
    if (syncingFromParent) return;
    const payload = {
      ...form,
      rate_unit: hasPerDayDuration.value ? 'hour' : (form.rate_unit || 'session'),
      days_of_week: [...(form.days_of_week || [])],
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
  () => form.days_of_week,
  () => {
    if (syncingFromParent) return;
    syncDayTimeSlotsFromSelection();
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

  // API 偶爾只回 day_time_slots、days_of_week 為空：勿把多段時段整批刪掉；從時段還原勾選的星期
  if (chipDays.size === 0 && slots.length > 0 && syncingFromParent) {
    form.days_of_week = [...new Set(slots.map((s) => Number(s?.day || 0)).filter((d) => d >= 1 && d <= 7))].sort((a, b) => a - b);
    chipDays = new Set(form.days_of_week);
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
  const days = sortedSelectedDays.value;
  const day = days.length ? days[days.length - 1] : 1;
  const baseTime = String(form.start_time || '16:00').slice(0, 5);
  form.day_time_slots = [...(form.day_time_slots || []), {
    day,
    start_time: baseTime,
    duration_hours: form.duration_hours || 2,
  }];
}

function removeSlot(idx) {
  form.day_time_slots = (form.day_time_slots || []).filter((_, i) => i !== idx);
  syncDayTimeSlotsFromSelection();
}

function updateSlotDay(idx, dayVal) {
  const d = Number(dayVal);
  if (d < 1 || d > 7) return;
  form.day_time_slots = (form.day_time_slots || []).map((slot, i) => (
    i === idx ? { ...slot, day: d } : slot
  ));
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
.course-form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px 16px;
}

.span-full {
  grid-column: 1 / -1;
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

@media (max-width: 720px) {
  .course-form-grid {
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
