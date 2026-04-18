<template>
  <div v-if="modelValue" class="stp-overlay" @click.self="close">
    <div class="stp-modal" role="dialog" aria-modal="true">
      <header class="stp-head">
        <h3>👤 換代課老師</h3>
        <button class="stp-close" type="button" aria-label="關閉" @click="close">✕</button>
      </header>

      <section class="stp-meta">
        <div class="stp-meta__row">
          <span class="stp-meta__label">學生</span>
          <span class="stp-meta__value">{{ context.student_name || '—' }}</span>
        </div>
        <div class="stp-meta__row">
          <span class="stp-meta__label">科目</span>
          <span class="stp-meta__value">{{ context.subject_label || '—' }}</span>
        </div>
        <div class="stp-meta__row">
          <span class="stp-meta__label">日期 / 時段</span>
          <span class="stp-meta__value">
            {{ context.session_date }} {{ context.start_time }}~{{ context.end_time }}
          </span>
        </div>
        <div class="stp-meta__row">
          <span class="stp-meta__label">正班老師</span>
          <span class="stp-meta__value">{{ context.original_teacher_name || '—' }}</span>
        </div>
      </section>

      <div class="stp-search">
        <span class="stp-search__icon" aria-hidden="true">🔍</span>
        <input
          v-model="search"
          type="text"
          class="stp-search__input"
          placeholder="搜尋老師姓名（中文 / 英文）"
          aria-label="搜尋老師"
        />
        <button v-if="search" class="stp-search__clear" type="button" @click="search = ''">清除</button>
      </div>

      <section class="stp-list" aria-live="polite">
        <div v-if="loadingAvailability" class="stp-skel">
          <div v-for="n in 3" :key="n" class="stp-skel__row">
            <div class="stp-skel__circle" />
            <div class="stp-skel__lines">
              <div class="stp-skel__line stp-skel__line--w60" />
              <div class="stp-skel__line stp-skel__line--w40" />
            </div>
          </div>
        </div>
        <template v-else-if="filteredTeachers.length === 0">
          <div class="stp-empty">
            <div class="stp-empty__emoji" aria-hidden="true">🔎</div>
            <div class="stp-empty__title">找不到符合條件的老師</div>
            <div class="stp-empty__desc">請調整搜尋字或清除篩選</div>
            <button v-if="search" type="button" class="stp-empty__cta" @click="search = ''">清除搜尋</button>
          </div>
        </template>
        <template v-else>
          <div
            v-for="t in filteredTeachers"
            :key="t.id"
            class="stp-card"
            :class="[
              selectedTeacherId === t.id && 'stp-card--selected',
              t.conflict && 'stp-card--conflict',
              t.crossCampusWarn && !t.conflict && 'stp-card--cross',
              t.conflict && 'stp-card--disabled',
            ]"
            :tabindex="t.conflict ? -1 : 0"
            :aria-disabled="t.conflict"
            @click="selectTeacher(t)"
            @keydown.enter.prevent="selectTeacher(t)"
            @keydown.space.prevent="selectTeacher(t)"
          >
            <div class="stp-card__avatar" :style="{ background: avatarColor(t.id) }" aria-hidden="true">
              {{ (t.name || '老').charAt(0) }}
            </div>
            <div class="stp-card__body">
              <div class="stp-card__name">{{ t.name }}</div>
              <div class="stp-card__tags">
                <span class="stp-tag stp-tag--branch" :title="t.branchLabel">
                  {{ t.branchLabel || '未綁分校' }}
                </span>
                <span v-if="t.conflict" class="stp-tag stp-tag--conflict" :title="t.conflictTooltip">
                  衝堂
                </span>
                <span v-else-if="t.crossCampusWarn" class="stp-tag stp-tag--cross">
                  跨分校協調
                </span>
                <span v-if="t.teachesSubject === true" class="stp-tag stp-tag--subject">授此科</span>
                <span v-else-if="t.teachesSubject === false" class="stp-tag stp-tag--subject-muted">—</span>
              </div>
            </div>
            <div class="stp-card__pick">
              <span v-if="selectedTeacherId === t.id" class="stp-pick">已選</span>
            </div>
          </div>
        </template>
      </section>

      <section class="stp-reason">
        <label class="stp-reason__label">原因（選填）</label>
        <input
          v-model="reason"
          type="text"
          maxlength="120"
          placeholder="例：正班老師請假"
          class="stp-reason__input"
        />
      </section>

      <div v-if="inlineError" class="stp-error" role="alert">{{ inlineError }}</div>

      <footer class="stp-actions">
        <button class="stp-btn stp-btn--ghost" type="button" @click="close">取消</button>
        <button
          class="stp-btn stp-btn--primary"
          type="button"
          :disabled="!canSubmit || submitting"
          :title="!canSubmit ? '請先選擇代課老師' : ''"
          @click="submit"
        >
          <span v-if="submitting" class="stp-spinner" aria-hidden="true"></span>
          {{ submitting ? '處理中…' : '確認代課' }}
        </button>
      </footer>
    </div>
  </div>
</template>

<script setup>
// SubstituteTeacherPickerModal — 代課流程 UX 優化（PRD 9c058f19 FR-001~004a）
// 卡片式老師選擇；跨分校衝堂標籤；搜尋；inline 錯誤；skeleton；送出後交由上層 handleSubmit 呼叫 API。
import { computed, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  context: {
    type: Object,
    required: true,
    // { student_name, subject_label, subject_id, session_date, start_time, end_time,
    //   original_teacher_name, original_teacher_id, session_campus_id }
  },
  teachers: { type: Array, default: () => [] }, // [{ id, name, branch_ids, branch_id }]
  branchNameMap: { type: Object, default: () => ({}) }, // { [campus_id]: '台北總校' }
  // (teacherId, date) => Promise<{ busy_slots: [{start_time, end_time, campus_id}] }>
  fetchAvailability: { type: Function, required: true },
  // (teacherId) => boolean|null    null = 未知 → 顯示 '—'
  teachesSubjectFn: { type: Function, default: null },
});

const emit = defineEmits(['update:modelValue', 'submit']);

const search = ref('');
const reason = ref('');
const selectedTeacherId = ref(null);
const submitting = ref(false);
const inlineError = ref('');
const loadingAvailability = ref(false);
const teacherBusyMap = ref({}); // teacherId -> busy_slots

function close() {
  if (submitting.value) return;
  emit('update:modelValue', false);
}

watch(
  () => props.modelValue,
  async (v) => {
    if (!v) return;
    search.value = '';
    reason.value = '';
    selectedTeacherId.value = null;
    inlineError.value = '';
    await refreshAvailability();
  }
);

async function refreshAvailability() {
  if (!props.context?.session_date) return;
  loadingAvailability.value = true;
  teacherBusyMap.value = {};
  try {
    const date = props.context.session_date;
    const results = await Promise.allSettled(
      (props.teachers || []).map(async (t) => {
        try {
          const r = await props.fetchAvailability(t.id, date);
          return [t.id, Array.isArray(r?.busy_slots) ? r.busy_slots : []];
        } catch (e) {
          return [t.id, []];
        }
      })
    );
    const out = {};
    for (const r of results) {
      if (r.status === 'fulfilled' && Array.isArray(r.value)) {
        const [tid, slots] = r.value;
        out[tid] = slots;
      }
    }
    teacherBusyMap.value = out;
  } finally {
    loadingAvailability.value = false;
  }
}

function overlaps(aStart, aEnd, bStart, bEnd) {
  if (!aStart || !aEnd || !bStart || !bEnd) return false;
  return aStart < bEnd && bStart < aEnd;
}

const enriched = computed(() => {
  const ctx = props.context || {};
  const sessionCampus = Number(ctx.session_campus_id || 0);
  const originalId = Number(ctx.original_teacher_id || 0);
  return (props.teachers || [])
    .filter((t) => Number(t.id) !== originalId)
    .map((t) => {
      const branchIds = Array.isArray(t.branch_ids)
        ? t.branch_ids
        : t.branch_id
        ? [t.branch_id]
        : [];
      const branchLabel =
        (branchIds.length > 0
          ? branchIds.map((bid) => props.branchNameMap[bid] || `分校#${bid}`).join(' · ')
          : '') || '未綁分校';
      const crossCampusWarn =
        sessionCampus > 0 && branchIds.length > 0 && !branchIds.includes(sessionCampus);

      const slots = teacherBusyMap.value[t.id] || [];
      let conflict = false;
      let conflictCampusId = 0;
      for (const s of slots) {
        if (overlaps(ctx.start_time, ctx.end_time, s.start_time, s.end_time)) {
          conflict = true;
          conflictCampusId = Number(s.campus_id || 0);
          break;
        }
      }
      const conflictTooltip = conflict
        ? `於 ${props.branchNameMap[conflictCampusId] || `分校#${conflictCampusId}`} 有課`
        : '';

      let teachesSubject = null;
      if (typeof props.teachesSubjectFn === 'function') {
        try {
          teachesSubject = props.teachesSubjectFn(t.id, ctx.subject_id);
        } catch (e) {
          teachesSubject = null;
        }
      }
      return {
        ...t,
        branch_ids: branchIds,
        branchLabel,
        crossCampusWarn,
        conflict,
        conflictTooltip,
        teachesSubject,
      };
    });
});

const filteredTeachers = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return enriched.value;
  return enriched.value.filter((t) => {
    const name = (t.name || '').toLowerCase();
    const uname = (t.username || '').toLowerCase();
    return name.includes(q) || uname.includes(q);
  });
});

function selectTeacher(t) {
  if (t.conflict) return;
  selectedTeacherId.value = t.id;
  inlineError.value = '';
}

const canSubmit = computed(() => selectedTeacherId.value != null);

async function submit() {
  if (!canSubmit.value || submitting.value) return;
  inlineError.value = '';
  submitting.value = true;
  try {
    await Promise.resolve(
      emit('submit', {
        substitute_teacher_id: selectedTeacherId.value,
        reason: reason.value.trim() || null,
      })
    );
  } catch (e) {
    inlineError.value = e?.message || '代課設定失敗，請稍後再試';
  } finally {
    submitting.value = false;
  }
}

function setError(message) {
  inlineError.value = message || '';
}

function avatarColor(id) {
  const palette = ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#ec4899', '#0ea5e9'];
  return palette[Math.abs(Number(id) || 0) % palette.length];
}

defineExpose({ setError });
</script>

<style scoped>
.stp-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.5);
  z-index: 9990;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.stp-modal {
  width: 520px;
  max-width: 100%;
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 24px 48px rgba(15, 23, 42, 0.24);
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 32px);
  overflow: hidden;
}
.stp-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #f3f4f6;
}
.stp-head h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #111827;
}
.stp-close {
  background: transparent;
  border: 0;
  font-size: 16px;
  color: #6b7280;
  cursor: pointer;
}
.stp-meta {
  padding: 12px 20px;
  background: #f9fafb;
  border-bottom: 1px solid #f3f4f6;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px 16px;
}
.stp-meta__row { display: flex; align-items: baseline; gap: 8px; font-size: 13px; }
.stp-meta__label { color: #6b7280; min-width: 74px; }
.stp-meta__value { color: #111827; font-weight: 500; }

.stp-search {
  position: relative;
  padding: 12px 20px 0 20px;
}
.stp-search__icon {
  position: absolute;
  left: 32px;
  top: 24px;
  color: #9ca3af;
  font-size: 14px;
}
.stp-search__input {
  width: 100%;
  height: 40px;
  padding: 0 72px 0 36px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  font-size: 14px;
  box-sizing: border-box;
}
.stp-search__input:focus { border-color: #2563eb; outline: none; }
.stp-search__clear {
  position: absolute;
  right: 28px;
  top: 18px;
  background: transparent;
  border: 0;
  color: #6b7280;
  font-size: 12px;
  cursor: pointer;
}

.stp-list {
  flex: 1;
  overflow-y: auto;
  padding: 12px 12px;
  min-height: 200px;
  max-height: 360px;
}

.stp-card {
  display: grid;
  grid-template-columns: 44px 1fr auto;
  align-items: center;
  gap: 12px;
  padding: 12px;
  margin: 0 8px 8px 8px;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  cursor: pointer;
  background: #fff;
  transition: transform 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
}
.stp-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
}
.stp-card:focus-visible {
  outline: 2px solid #2563eb;
  outline-offset: 2px;
}
.stp-card--selected {
  border-color: #2563eb;
  box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.18);
}
.stp-card--cross { border-color: #f59e0b; }
.stp-card--conflict { border-color: #ef4444; background: #fef2f2; }
.stp-card--disabled {
  cursor: not-allowed;
  opacity: 0.7;
}
.stp-card--disabled:active {
  animation: stp-shake 150ms ease;
}
@keyframes stp-shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-3px); }
  75% { transform: translateX(3px); }
}
.stp-card__avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-weight: 700;
  font-size: 18px;
  letter-spacing: 0.5px;
}
.stp-card__name { font-size: 14px; font-weight: 600; color: #111827; }
.stp-card__tags { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
.stp-tag {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 999px;
  white-space: nowrap;
}
.stp-tag--branch { background: #eef2ff; color: #3730a3; }
.stp-tag--conflict { background: #fee2e2; color: #b91c1c; font-weight: 600; }
.stp-tag--cross { background: #fef3c7; color: #92400e; }
.stp-tag--subject { background: #d1fae5; color: #065f46; }
.stp-tag--subject-muted { background: #f3f4f6; color: #6b7280; }
.stp-pick {
  color: #2563eb;
  font-size: 12px;
  font-weight: 700;
}

.stp-skel { padding: 4px 12px; }
.stp-skel__row { display: flex; gap: 12px; padding: 12px; }
.stp-skel__circle { width: 44px; height: 44px; border-radius: 50%; background: #e5e7eb; }
.stp-skel__lines { flex: 1; display: flex; flex-direction: column; gap: 6px; }
.stp-skel__line { height: 10px; background: #e5e7eb; border-radius: 4px; }
.stp-skel__line--w60 { width: 60%; }
.stp-skel__line--w40 { width: 40%; }

.stp-empty { padding: 32px 16px; text-align: center; color: #6b7280; }
.stp-empty__emoji { font-size: 32px; }
.stp-empty__title { font-weight: 600; color: #111827; margin-top: 8px; }
.stp-empty__desc { font-size: 13px; margin-top: 4px; }
.stp-empty__cta {
  margin-top: 10px;
  background: transparent;
  color: #2563eb;
  border: 1px solid rgba(37, 99, 235, 0.4);
  border-radius: 8px;
  padding: 6px 14px;
  cursor: pointer;
}

.stp-reason {
  padding: 10px 20px;
  border-top: 1px solid #f3f4f6;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.stp-reason__label { font-size: 12px; color: #6b7280; }
.stp-reason__input {
  height: 36px;
  padding: 0 10px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  font-size: 13px;
  box-sizing: border-box;
}
.stp-reason__input:focus { border-color: #2563eb; outline: none; }

.stp-error {
  margin: 0 20px 10px 20px;
  padding: 8px 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #b91c1c;
  border-radius: 8px;
  font-size: 13px;
}
.stp-actions {
  padding: 12px 20px 16px 20px;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  border-top: 1px solid #f3f4f6;
}
.stp-btn {
  min-height: 40px;
  padding: 0 18px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.stp-btn--ghost {
  background: #fff;
  color: #374151;
  border-color: #e5e7eb;
}
.stp-btn--ghost:hover { background: #f9fafb; }
.stp-btn--primary {
  background: #2563eb;
  color: #fff;
}
.stp-btn--primary:hover { background: #1d4ed8; }
.stp-btn--primary:disabled {
  background: #cbd5f5;
  color: #fff;
  cursor: not-allowed;
}
.stp-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255,255,255,0.4);
  border-top-color: #fff;
  border-radius: 50%;
  display: inline-block;
  animation: stp-spin 800ms linear infinite;
}
@keyframes stp-spin { to { transform: rotate(360deg); } }

@media (max-width: 768px) {
  .stp-modal {
    width: 100%;
    max-height: 100vh;
    height: 100vh;
    border-radius: 0;
  }
  .stp-meta { grid-template-columns: 1fr; }
  .stp-list { max-height: none; }
  .stp-btn { min-height: 44px; }
}
</style>
