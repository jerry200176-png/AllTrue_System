<template>
  <div v-if="modelValue" class="tlb-overlay" @click.self="close">
    <div class="tlb-modal" role="dialog" aria-modal="true">
      <header class="tlb-head">
        <h3>🗓️ 老師請假批次代課</h3>
        <div class="tlb-stepper" aria-hidden="true">
          <span class="tlb-step" :class="step === 1 && 'tlb-step--active'">1 填資訊</span>
          <span class="tlb-step__divider">—</span>
          <span class="tlb-step" :class="step === 2 && 'tlb-step--active'">2 確認指派</span>
        </div>
        <button class="tlb-close" type="button" aria-label="關閉" @click="close">✕</button>
      </header>

      <!-- Step 1 -->
      <section v-if="step === 1" class="tlb-step1">
        <div class="tlb-field">
          <label>請假老師 <span class="tlb-req">*</span></label>
          <select v-model.number="leaveTeacherId" class="tlb-input">
            <option :value="null">請選擇</option>
            <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
        </div>
        <div class="tlb-field-row">
          <div class="tlb-field">
            <label>開始日期 <span class="tlb-req">*</span></label>
            <input v-model="dateStart" type="date" class="tlb-input" />
          </div>
          <div class="tlb-field">
            <label>結束日期 <span class="tlb-req">*</span></label>
            <input v-model="dateEnd" type="date" class="tlb-input" />
          </div>
        </div>
        <div v-if="step1Error" class="tlb-error" role="alert">{{ step1Error }}</div>
        <footer class="tlb-actions">
          <button class="tlb-btn tlb-btn--ghost" type="button" @click="close">取消</button>
          <button
            class="tlb-btn tlb-btn--primary"
            type="button"
            :disabled="!canPreview || previewing"
            @click="doPreview"
          >
            <span v-if="previewing" class="tlb-spinner" aria-hidden="true"></span>
            {{ previewing ? '載入受影響堂次…' : '查看受影響堂次' }}
          </button>
        </footer>
      </section>

      <!-- Step 2 -->
      <section v-else class="tlb-step2">
        <aside class="tlb-left">
          <h4>請假資訊</h4>
          <div class="tlb-info">
            <div><span>老師</span><strong>{{ teacherName(leaveTeacherId) }}</strong></div>
            <div><span>區間</span><strong>{{ dateStart }} ~ {{ dateEnd }}</strong></div>
            <div><span>堂次數</span><strong>{{ sessions.length }}</strong></div>
          </div>

          <h4 class="tlb-title">全部指派同一代課老師</h4>
          <select v-model.number="bulkTeacherId" class="tlb-input" @change="onBulkAssign">
            <option :value="null">— 不使用 —</option>
            <option v-for="t in substituteCandidates" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
          <p v-if="bulkConflictCount > 0" class="tlb-warn">
            此老師於 {{ bulkConflictCount }} 堂衝堂，請改逐堂指派。
          </p>

          <div class="tlb-summary">
            <div class="tlb-summary__row">
              <span>已指派</span><strong>{{ assignedCount }} / {{ sessions.length }}</strong>
            </div>
            <div class="tlb-summary__row" v-if="conflictAssignedCount > 0">
              <span>衝堂需調整</span><strong class="tlb-summary__bad">{{ conflictAssignedCount }}</strong>
            </div>
          </div>
        </aside>

        <main class="tlb-right">
          <div v-if="sessions.length === 0" class="tlb-empty">
            <div class="tlb-empty__emoji">🌿</div>
            <div class="tlb-empty__title">這天這位老師沒有排課</div>
            <div class="tlb-empty__desc">無需代課，您可以關閉這個視窗。</div>
          </div>
          <div v-else class="tlb-session-list">
            <div
              v-for="(s, idx) in sessions"
              :key="s.class_session_id"
              class="tlb-row"
              :class="[
                rowStatusClass(s, idx),
              ]"
            >
              <div class="tlb-row__meta">
                <div class="tlb-row__date">{{ s.session_date }} · {{ s.start_time }}~{{ s.end_time }}</div>
                <div class="tlb-row__student">{{ s.student_name }} · {{ s.subject_name || '課程' }}</div>
              </div>
              <select
                v-model.number="assignments[idx]"
                class="tlb-row__select"
                @change="clearConflict(idx)"
              >
                <option :value="null">未指派</option>
                <option
                  v-for="t in substituteCandidates"
                  :key="t.id"
                  :value="t.id"
                >
                  {{ t.name }}
                </option>
              </select>
              <span v-if="rowConflict[idx]" class="tlb-row__tag tlb-row__tag--bad">衝堂</span>
              <span v-else-if="assignments[idx]" class="tlb-row__tag tlb-row__tag--ok">已指派</span>
            </div>
          </div>
        </main>

        <footer class="tlb-actions tlb-actions--wide">
          <div v-if="unassignedCount > 0" class="tlb-note">
            還有 <strong>{{ unassignedCount }}</strong> 堂未指派代課老師
          </div>
          <button class="tlb-btn tlb-btn--ghost" type="button" :disabled="submitting" @click="step = 1">返回</button>
          <button
            class="tlb-btn tlb-btn--primary"
            type="button"
            :disabled="!canSubmit || submitting"
            @click="confirmAndSubmit"
          >
            <span v-if="submitting" class="tlb-spinner" aria-hidden="true"></span>
            {{ submitting ? '送出中…' : '送出批次代課' }}
          </button>
        </footer>
      </section>

      <!-- Confirm dialog -->
      <div v-if="confirmingOpen" class="tlb-confirm-overlay" @click.self="confirmingOpen = false">
        <div class="tlb-confirm">
          <h4>確認送出？</h4>
          <ul>
            <li>受影響堂次：{{ sessions.length }}</li>
            <li>已指派：{{ assignedCount }}</li>
            <li>衝堂待調整：{{ conflictAssignedCount }}</li>
            <li>未指派：{{ unassignedCount }}</li>
          </ul>
          <p class="tlb-confirm__note">
            整批原子性：若任何一堂失敗將整批回滾，請先確認指派無誤。
          </p>
          <div class="tlb-actions">
            <button class="tlb-btn tlb-btn--ghost" type="button" @click="confirmingOpen = false">再看看</button>
            <button class="tlb-btn tlb-btn--primary" type="button" @click="submit">確認送出</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
// TeacherLeaveBatchModal — 老師請假批次代課（PRD 9c058f19 FR-006~009）
// 左右分欄（桌機）、手機 stepper；兩步驟：填資訊 → 確認指派；
// 提供「全部指派同一老師」快速操作，也可逐堂微調；送出前二次確認。
import { computed, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  teachers: { type: Array, default: () => [] },
  // async ({ teacher_id, date_start, date_end }) => { sessions: [{ class_session_id, student_name, session_date, start_time, end_time, subject_name, class_type, student_class_id, campus_id }], truncated }
  fetchPreview: { type: Function, required: true },
  // async ({ assignments: [{ class_session_id, substitute_teacher_id, reason }] }) => { summary, results }
  submitBatch: { type: Function, required: true },
  // async (teacherId, date) => { busy_slots: [...] }
  fetchAvailability: { type: Function, required: true },
});
const emit = defineEmits(['update:modelValue', 'submitted']);

const step = ref(1);
const previewing = ref(false);
const submitting = ref(false);
const step1Error = ref('');
const confirmingOpen = ref(false);

const leaveTeacherId = ref(null);
const dateStart = ref(new Date().toISOString().slice(0, 10));
const dateEnd = ref(new Date().toISOString().slice(0, 10));

const sessions = ref([]);
const assignments = ref([]); // parallel array, values = teacherId or null
const rowConflict = ref([]); // parallel array of boolean
const bulkTeacherId = ref(null);
const bulkConflictCount = ref(0);

const canPreview = computed(
  () =>
    !!leaveTeacherId.value && !!dateStart.value && !!dateEnd.value && dateStart.value <= dateEnd.value
);
const assignedCount = computed(() => assignments.value.filter((v) => !!v).length);
const conflictAssignedCount = computed(() =>
  rowConflict.value.reduce((acc, v) => acc + (v ? 1 : 0), 0)
);
const unassignedCount = computed(() => sessions.value.length - assignedCount.value);
const canSubmit = computed(
  () => sessions.value.length > 0 && assignedCount.value > 0 && conflictAssignedCount.value === 0 && unassignedCount.value === 0
);

const substituteCandidates = computed(() =>
  (props.teachers || []).filter((t) => Number(t.id) !== Number(leaveTeacherId.value))
);

function teacherName(id) {
  const t = (props.teachers || []).find((x) => Number(x.id) === Number(id));
  return t?.name || '—';
}

function close() {
  if (submitting.value) return;
  emit('update:modelValue', false);
}

watch(
  () => props.modelValue,
  (v) => {
    if (!v) return;
    step.value = 1;
    step1Error.value = '';
    leaveTeacherId.value = null;
    sessions.value = [];
    assignments.value = [];
    rowConflict.value = [];
    bulkTeacherId.value = null;
    bulkConflictCount.value = 0;
  }
);

async function doPreview() {
  step1Error.value = '';
  if (!canPreview.value) return;
  previewing.value = true;
  try {
    const r = await props.fetchPreview({
      teacher_id: leaveTeacherId.value,
      date_start: dateStart.value,
      date_end: dateEnd.value,
    });
    sessions.value = Array.isArray(r?.sessions) ? r.sessions : [];
    assignments.value = new Array(sessions.value.length).fill(null);
    rowConflict.value = new Array(sessions.value.length).fill(false);
    step.value = 2;
  } catch (e) {
    step1Error.value = e?.message || '查詢失敗，請稍後再試';
  } finally {
    previewing.value = false;
  }
}

function overlaps(aStart, aEnd, bStart, bEnd) {
  return !!(aStart && aEnd && bStart && bEnd && aStart < bEnd && bStart < aEnd);
}

async function onBulkAssign() {
  if (!bulkTeacherId.value) {
    bulkConflictCount.value = 0;
    for (let i = 0; i < sessions.value.length; i++) {
      assignments.value[i] = null;
      rowConflict.value[i] = false;
    }
    return;
  }
  // 對每堂查 availability 並標記衝堂
  const dates = Array.from(new Set(sessions.value.map((s) => s.session_date)));
  const perDate = {};
  await Promise.all(
    dates.map(async (d) => {
      try {
        const r = await props.fetchAvailability(bulkTeacherId.value, d);
        perDate[d] = Array.isArray(r?.busy_slots) ? r.busy_slots : [];
      } catch (e) {
        perDate[d] = [];
      }
    })
  );
  let conflicts = 0;
  for (let i = 0; i < sessions.value.length; i++) {
    const s = sessions.value[i];
    const slots = perDate[s.session_date] || [];
    const clash = slots.some((x) => overlaps(s.start_time, s.end_time, x.start_time, x.end_time));
    // stagger animation
    await new Promise((r) => setTimeout(r, 30));
    assignments.value[i] = bulkTeacherId.value;
    rowConflict.value[i] = clash;
    if (clash) conflicts++;
  }
  bulkConflictCount.value = conflicts;
}

function clearConflict(idx) {
  rowConflict.value[idx] = false;
}

function rowStatusClass(s, idx) {
  if (rowConflict.value[idx]) return 'tlb-row--bad';
  if (assignments.value[idx]) return 'tlb-row--ok';
  return 'tlb-row--idle';
}

function confirmAndSubmit() {
  if (!canSubmit.value) return;
  confirmingOpen.value = true;
}

async function submit() {
  confirmingOpen.value = false;
  if (!canSubmit.value) return;
  submitting.value = true;
  try {
    const payloads = sessions.value.map((s, idx) => ({
      class_session_id: s.class_session_id,
      substitute_teacher_id: assignments.value[idx],
      reason: '老師請假批次代課',
    }));
    const r = await props.submitBatch({ assignments: payloads });
    emit('submitted', r);
    emit('update:modelValue', false);
  } catch (e) {
    step1Error.value = e?.message || '批次送出失敗';
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.tlb-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9992;
  padding: 16px;
}
.tlb-modal {
  width: min(900px, 100%);
  max-height: calc(100vh - 32px);
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 24px 48px rgba(15, 23, 42, 0.28);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.tlb-head {
  display: grid;
  grid-template-columns: 1fr auto 24px;
  gap: 12px;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 1px solid #f3f4f6;
}
.tlb-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: #111827; }
.tlb-stepper { font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 6px; }
.tlb-step--active { color: var(--ds-primary-deep, #e65100); font-weight: 700; }
.tlb-close { background: transparent; border: 0; color: #6b7280; cursor: pointer; font-size: 14px; }

/* Step 1 */
.tlb-step1 { padding: 16px 20px; display: flex; flex-direction: column; gap: 12px; }
.tlb-field { display: flex; flex-direction: column; gap: 6px; }
.tlb-field label { font-size: 12px; color: #6b7280; }
.tlb-req { color: #ef4444; }
.tlb-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.tlb-input {
  height: 40px;
  padding: 0 10px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  font-size: 14px;
  background: #fff;
}
.tlb-input:focus { border-color: var(--ds-primary, #ef6c00); outline: none; }

/* Step 2 */
.tlb-step2 {
  display: grid;
  grid-template-columns: 280px 1fr;
  grid-template-rows: 1fr auto;
  gap: 0;
  min-height: 400px;
}
.tlb-left {
  border-right: 1px solid #f3f4f6;
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: #fafafa;
}
.tlb-left h4 { margin: 0; font-size: 13px; color: #111827; font-weight: 700; }
.tlb-info { display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: #374151; }
.tlb-info div { display: flex; justify-content: space-between; align-items: baseline; }
.tlb-info div span { color: #6b7280; }
.tlb-title { margin-top: 12px; }
.tlb-warn { color: #b45309; font-size: 12px; margin: 0; }
.tlb-summary { margin-top: auto; border-top: 1px dashed #e5e7eb; padding-top: 10px; font-size: 13px; }
.tlb-summary__row { display: flex; justify-content: space-between; padding: 2px 0; }
.tlb-summary__bad { color: #b91c1c; }

.tlb-right {
  padding: 8px 16px;
  overflow-y: auto;
  max-height: 50vh;
}
.tlb-empty { text-align: center; color: #6b7280; padding: 40px 16px; }
.tlb-empty__emoji { font-size: 32px; }
.tlb-empty__title { margin-top: 8px; font-weight: 600; color: #111827; }
.tlb-empty__desc { font-size: 13px; margin-top: 2px; }

.tlb-session-list { display: flex; flex-direction: column; gap: 8px; padding: 8px 0; }
.tlb-row {
  display: grid;
  grid-template-columns: 1fr 180px auto;
  gap: 10px;
  align-items: center;
  padding: 10px 12px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fff;
  transition: border-color 120ms ease, background 120ms ease;
}
.tlb-row--ok { border-color: #10b981; background: #f0fdf4; }
.tlb-row--bad { border-color: #ef4444; background: #fef2f2; }
.tlb-row__date { font-size: 13px; color: #111827; font-weight: 600; }
.tlb-row__student { font-size: 12px; color: #6b7280; margin-top: 2px; }
.tlb-row__select {
  height: 36px;
  padding: 0 8px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  font-size: 13px;
}
.tlb-row__tag {
  font-size: 11px;
  padding: 3px 10px;
  border-radius: 999px;
  font-weight: 600;
}
.tlb-row__tag--ok { background: #d1fae5; color: #065f46; }
.tlb-row__tag--bad { background: #fee2e2; color: #b91c1c; }

.tlb-actions {
  grid-column: 1 / -1;
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: flex-end;
  padding: 12px 20px;
  border-top: 1px solid #f3f4f6;
  background: #fff;
}
.tlb-actions--wide { }
.tlb-note { flex: 1; color: #b91c1c; font-size: 13px; }
.tlb-btn {
  min-height: 40px;
  padding: 0 16px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.tlb-btn--ghost { background: #fff; color: #374151; border-color: #e5e7eb; }
.tlb-btn--ghost:hover { background: #f9fafb; }
.tlb-btn--primary { background: var(--ds-primary, #ef6c00); color: #fff; }
.tlb-btn--primary:hover { background: var(--ds-primary-press, #d84315); }
.tlb-btn--primary:disabled { background: #cbd5f5; cursor: not-allowed; }
.tlb-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255,255,255,0.4);
  border-top-color: #fff;
  border-radius: 50%;
  display: inline-block;
  animation: tlb-spin 800ms linear infinite;
}
@keyframes tlb-spin { to { transform: rotate(360deg); } }

.tlb-error {
  padding: 8px 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
  color: #b91c1c;
  font-size: 13px;
}

.tlb-confirm-overlay {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 2;
}
.tlb-confirm {
  background: #fff;
  border-radius: 12px;
  padding: 16px 20px;
  width: 360px;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.22);
}
.tlb-confirm h4 { margin: 0 0 10px 0; }
.tlb-confirm ul { margin: 0 0 8px 18px; padding: 0; font-size: 13px; color: #374151; }
.tlb-confirm__note { font-size: 12px; color: #6b7280; margin: 8px 0 12px 0; }

@media (max-width: 768px) {
  .tlb-step2 {
    grid-template-columns: 1fr;
  }
  .tlb-left {
    border-right: 0;
    border-bottom: 1px solid #f3f4f6;
  }
  .tlb-row {
    grid-template-columns: 1fr;
    gap: 8px;
  }
  .tlb-btn { min-height: 44px; }
}
</style>
