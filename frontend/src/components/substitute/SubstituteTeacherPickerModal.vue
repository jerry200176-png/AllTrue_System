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

      <div class="stp-body">

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

      <section v-if="canRestoreOriginal" class="stp-restore">
        <div>
          <strong>要改回正班老師？</strong>
          <span>這會清除此堂代課設定，回到 {{ originalTeacherName }}。</span>
        </div>
        <button type="button" class="stp-restore__btn" :disabled="submitting" @click="restoreOriginalTeacher">
          回正班老師
        </button>
      </section>

      <!-- PRD f0cce4d5 5b：換時間變更後的即時刷新細進度條（避免整個 list layout shift） -->
      <div v-if="loadingAvailability" class="stp-progress" aria-hidden="true">
        <div class="stp-progress__bar"></div>
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
              t.capacityWarn && !t.conflict && 'stp-card--warn',
              t.crossCampusWarn && !t.conflict && !t.capacityWarn && 'stp-card--cross',
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
                <!-- FR-006 容量狀態標籤：三態顯示，availability 查詢中不顯示 -->
                <template v-if="!loadingAvailability && t.hasAvailabilityData">
                  <span
                    v-if="t.conflict"
                    class="stp-tag stp-cap-tag stp-cap-tag--full"
                    :title="t.conflictTooltip || '此時段老師已達上限，無法安排'"
                  >
                    <span class="stp-cap-tag__long">已滿 ✗</span>
                    <span class="stp-cap-tag__short">滿</span>
                  </span>
                  <span
                    v-else-if="t.capacityWarn"
                    class="stp-tag stp-cap-tag stp-cap-tag--warn"
                    title="此時段尚有容量，可繼續"
                  >
                    <span class="stp-cap-tag__long">尚有容量 ⚠</span>
                    <span class="stp-cap-tag__short">有</span>
                  </span>
                  <span
                    v-else
                    class="stp-tag stp-cap-tag stp-cap-tag--ok"
                    title="此時段老師有空"
                  >
                    <span class="stp-cap-tag__long">有空 ✓</span>
                    <span class="stp-cap-tag__short">空</span>
                  </span>
                </template>
                <span
                  v-else-if="!loadingAvailability && !t.hasAvailabilityData"
                  class="stp-tag stp-cap-tag stp-cap-tag--unknown"
                  title="無法取得可用性資訊"
                >─</span>
                <span class="stp-tag stp-tag--branch" :title="t.branchLabel">
                  {{ t.branchLabel || '未綁分校' }}
                </span>
                <span v-if="t.conflict" class="stp-tag stp-tag--conflict" :title="t.conflictTooltip">
                  衝堂
                </span>
                <span v-else-if="t.capacityWarn" class="stp-tag stp-tag--capacity-warn">
                  此時段有重疊
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

      <!-- PRD f0cce4d5：合併代課 + 換時間（選填） -->
      <section class="stp-resched">
        <button
          type="button"
          class="stp-resched__toggle"
          :aria-expanded="showReschedule"
          @click="toggleReschedule"
        >
          <span class="stp-resched__chev" :class="{ 'stp-resched__chev--open': showReschedule }" aria-hidden="true">▸</span>
          <span v-if="!showReschedule">+ 同時調整上課時間（選填）</span>
          <span v-else-if="newDate && newStart">已選：{{ newDate }} {{ newStart }}~{{ computedNewEnd }}</span>
          <span v-else>同時調整上課時間（選填）</span>
        </button>
        <div class="stp-resched__panel" :class="{ 'stp-resched__panel--open': showReschedule }">
          <div v-if="showReschedule" class="stp-resched__inner">
            <div class="stp-resched__row">
              <label class="stp-resched__label">
                <span>新日期</span>
                <input
                  v-model="newDate"
                  type="date"
                  :min="todayYmd"
                  class="stp-resched__input"
                  @change="onReschedChange"
                />
              </label>
              <label class="stp-resched__label">
                <span>新開始時間</span>
                <select
                  v-model="newStart"
                  class="stp-resched__input"
                  @change="onReschedChange"
                >
                  <option value="">請選擇</option>
                  <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
                </select>
              </label>
              <label class="stp-resched__label">
                <span>新結束時間</span>
                <input
                  :value="computedNewEnd"
                  type="text"
                  readonly
                  class="stp-resched__input stp-resched__input--readonly"
                  placeholder="依原課時長自動計算"
                  title="依原課時長自動計算"
                />
              </label>
            </div>
            <div v-if="reschedFieldError" class="stp-resched__err" role="alert">
              {{ reschedFieldError }}
            </div>
            <div class="stp-resched__hint">
              結束時間依原課時長（{{ origDurationLabel }}）自動計算。改變日期或開始時間時，系統會即時重新檢查所有老師的可用性。
            </div>
          </div>
        </div>
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

      <!-- FR-004: warning type for no_class_session, red error for other failures -->
      <div
        v-if="inlineError"
        class="stp-error"
        :class="{ 'stp-error--warning': inlineErrorType === 'warning' }"
        role="alert"
      >{{ inlineError }}</div>

      </div><!-- /stp-body -->

      <footer class="stp-actions">
        <div v-if="isRescheduleActive && !reschedFieldError" class="stp-actions__summary">
          將調整至 {{ newDate }} {{ newStart }}~{{ computedNewEnd }}，由 {{ selectedTeacherName || '所選老師' }} 代課
        </div>
        <div class="stp-actions__row">
          <button class="stp-btn stp-btn--ghost" type="button" @click="close">取消</button>
          <button
            class="stp-btn stp-btn--primary"
            type="button"
            :disabled="!canSubmit || submitting"
            :title="submitDisabledTooltip"
            @click="submit"
          >
            <span v-if="submitting" class="stp-spinner" aria-hidden="true"></span>
            {{ submitButtonLabel }}
          </button>
        </div>
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
const inlineErrorType = ref('error'); // 'error' | 'warning' — controls colour of inline message
const loadingAvailability = ref(false);
const teacherBusyMap = ref({}); // teacherId -> busy_slots (now includes remaining_capacity)

// PRD f0cce4d5：「同時調整上課時間」狀態
const showReschedule = ref(false);
const newDate = ref('');
const newStart = ref('');
const reschedFieldError = ref('');
let availabilityReqToken = 0; // 防重：保留最新一次 refreshAvailability 的結果

function close() {
  if (submitting.value) return;
  emit('update:modelValue', false);
}

function toggleReschedule() {
  showReschedule.value = !showReschedule.value;
  if (!showReschedule.value) {
    // 收合時清掉欄位，確保只提交純代課
    newDate.value = '';
    newStart.value = '';
    reschedFieldError.value = '';
    // 立即重查以回到原時段的衝堂標記
    refreshAvailability();
  }
}

function onReschedChange() {
  validateReschedFields();
  refreshAvailability();
}

function validateReschedFields() {
  reschedFieldError.value = '';
  // 只填一半 → 提示（送出時也會擋）
  if ((newDate.value && !newStart.value) || (!newDate.value && newStart.value)) {
    reschedFieldError.value = '請同時填寫新日期與新開始時間';
    return false;
  }
  if (newDate.value && newDate.value < todayYmd.value) {
    reschedFieldError.value = '新日期不可為過去日期';
    return false;
  }
  return true;
}

watch(
  () => props.modelValue,
  async (v) => {
    if (!v) return;
    search.value = '';
    reason.value = '';
    selectedTeacherId.value = null;
    inlineError.value = '';
    showReschedule.value = false;
    newDate.value = '';
    newStart.value = '';
    reschedFieldError.value = '';
    await refreshAvailability();
  }
);

// PRD f0cce4d5：生效的查詢時段（預設 context 原時段；換時啟用時用新時段）
const effectiveDate = computed(() => {
  if (isRescheduleActive.value) return newDate.value;
  return props.context?.session_date || '';
});
const effectiveStart = computed(() => {
  if (isRescheduleActive.value) return newStart.value;
  return props.context?.start_time || '';
});
const effectiveEnd = computed(() => {
  if (isRescheduleActive.value) return computedNewEnd.value;
  return props.context?.end_time || '';
});

async function refreshAvailability() {
  const date = effectiveDate.value;
  if (!date) return;
  const token = ++availabilityReqToken;
  loadingAvailability.value = true;
  try {
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
    // 若在請求期間又觸發新的 refresh，捨棄本次結果避免 race condition
    if (token !== availabilityReqToken) return;
    const out = {};
    for (const r of results) {
      if (r.status === 'fulfilled' && Array.isArray(r.value)) {
        const [tid, slots] = r.value;
        out[tid] = slots;
      }
    }
    teacherBusyMap.value = out;
  } finally {
    if (token === availabilityReqToken) {
      loadingAvailability.value = false;
    }
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
  const checkStart = effectiveStart.value;
  const checkEnd = effectiveEnd.value;
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

      // FR-006: capacity-aware conflict detection.
      // - conflict=true → ALL overlapping busy_slots are fully booked (remaining_capacity === 0)
      // - capacityWarn=true → at least one overlapping slot has remaining_capacity > 0
      //   (teacher has room; allow selection with orange warning)
      // Backward compat: if remaining_capacity is missing, treat as 0 (full) to be safe.
      const slots = teacherBusyMap.value[t.id] || [];
      const hasAvailabilityData = teacherBusyMap.value[t.id] !== undefined;
      let conflict = false;
      let capacityWarn = false;
      let conflictCampusId = 0;
      for (const s of slots) {
        if (overlaps(checkStart, checkEnd, s.start_time, s.end_time)) {
          const rc = s.remaining_capacity !== undefined ? Number(s.remaining_capacity) : 0;
          if (rc <= 0) {
            conflict = true;
            conflictCampusId = Number(s.campus_id || 0);
          } else {
            capacityWarn = true;
          }
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
        capacityWarn,
        hasAvailabilityData,
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

// PRD f0cce4d5：今日 YYYY-MM-DD（date input 的 min）
const todayYmd = computed(() => {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${dd}`;
});

// 原課時長（毫秒）用來推算新結束時間
const origDurationMs = computed(() => {
  const s = props.context?.start_time || '';
  const e = props.context?.end_time || '';
  if (!s || !e) return 2 * 60 * 60 * 1000;
  const [sh, sm] = s.split(':').map(Number);
  const [eh, em] = e.split(':').map(Number);
  const diff = ((eh * 60 + (em || 0)) - (sh * 60 + (sm || 0))) * 60 * 1000;
  return diff > 0 ? diff : 2 * 60 * 60 * 1000;
});

const origDurationLabel = computed(() => {
  const mins = origDurationMs.value / 60000;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  if (h > 0 && m === 0) return `${h} 小時`;
  if (h === 0) return `${m} 分鐘`;
  return `${h} 小時 ${m} 分鐘`;
});

const computedNewEnd = computed(() => {
  if (!newStart.value) return '';
  const [sh, sm] = newStart.value.split(':').map(Number);
  const startMs = (sh * 60 + (sm || 0)) * 60 * 1000;
  const endMs = startMs + origDurationMs.value;
  const total = Math.floor(endMs / 60000);
  const eh = Math.floor(total / 60) % 24;
  const em = total % 60;
  return `${String(eh).padStart(2, '0')}:${String(em).padStart(2, '0')}`;
});

// 時間選項：07:00 ~ 22:30 半小時為單位
const timeOptions = (() => {
  const opts = [];
  for (let h = 7; h <= 22; h++) {
    opts.push(`${String(h).padStart(2, '0')}:00`);
    if (h < 22) opts.push(`${String(h).padStart(2, '0')}:30`);
  }
  return opts;
})();

const isRescheduleActive = computed(() =>
  showReschedule.value && !!newDate.value && !!newStart.value && !!computedNewEnd.value && !reschedFieldError.value
);

const selectedTeacher = computed(() =>
  enriched.value.find((t) => t.id === selectedTeacherId.value) || null
);
const selectedTeacherName = computed(() => selectedTeacher.value?.name || '');
const originalTeacherId = computed(() => Number(props.context?.original_teacher_id || 0));
const currentTeacherId = computed(() => Number(props.context?.current_teacher_id || 0));
const originalTeacherName = computed(() => props.context?.original_teacher_name || `老師#${originalTeacherId.value}`);
const canRestoreOriginal = computed(() =>
  originalTeacherId.value > 0
  && currentTeacherId.value > 0
  && originalTeacherId.value !== currentTeacherId.value
);

const canSubmit = computed(() => {
  if (selectedTeacherId.value == null) return false;
  if (showReschedule.value) {
    // 展開換時區塊時：必須同填或同省
    const filled = (newDate.value ? 1 : 0) + (newStart.value ? 1 : 0);
    if (filled === 1) return false;
    if (reschedFieldError.value) return false;
  }
  return true;
});

const submitDisabledTooltip = computed(() => {
  if (selectedTeacherId.value == null) return '請先選擇代課老師';
  if (showReschedule.value) {
    const filled = (newDate.value ? 1 : 0) + (newStart.value ? 1 : 0);
    if (filled === 1) return '請同時填寫新日期與新開始時間，或收合換時間區塊';
    if (reschedFieldError.value) return reschedFieldError.value;
  }
  return '';
});

const submitButtonLabel = computed(() => {
  if (submitting.value) return '處理中…';
  return isRescheduleActive.value ? '確認代課 + 換時' : '確認代課';
});

async function submit() {
  if (!canSubmit.value || submitting.value) return;
  inlineError.value = '';
  if (showReschedule.value && !validateReschedFields()) {
    return;
  }
  submitting.value = true;
  try {
    const payload = {
      substitute_teacher_id: selectedTeacherId.value,
      reason: reason.value.trim() || null,
    };
    if (isRescheduleActive.value) {
      payload.new_date = newDate.value;
      payload.new_start_time = newStart.value;
      payload.new_end_time = computedNewEnd.value;
    }
    await Promise.resolve(emit('submit', payload));
  } catch (e) {
    inlineError.value = e?.message || '代課設定失敗，請稍後再試';
  } finally {
    submitting.value = false;
  }
}

async function restoreOriginalTeacher() {
  if (!canRestoreOriginal.value || submitting.value) return;
  inlineError.value = '';
  submitting.value = true;
  try {
    await Promise.resolve(emit('submit', {
      substitute_teacher_id: originalTeacherId.value,
      reason: reason.value.trim() || '回復正班老師',
    }));
  } catch (e) {
    inlineError.value = e?.message || '回復正班老師失敗，請稍後再試';
  } finally {
    submitting.value = false;
  }
}

// FR-004: setError now accepts type so SmartCalendar can surface no_class_session as a warning
// ('warning' → orange/amber) rather than as a hard error ('error' → red).
function setError(message, type = 'error') {
  inlineError.value = message || '';
  inlineErrorType.value = type === 'warning' ? 'warning' : 'error';
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
.stp-search__input:focus { border-color: var(--ds-primary, #ef6c00); outline: none; }
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
.stp-restore {
  margin: 10px 20px 0;
  padding: 10px 12px;
  border: 1px solid var(--ds-hairline, #e3e8ee);
  border-radius: 12px;
  background: var(--ds-canvas-soft, #f6f9fc);
  color: var(--ds-ink, #1a1a1a);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 13px;
}
.stp-restore strong {
  display: block;
  margin-bottom: 2px;
}
.stp-restore__btn {
  flex: 0 0 auto;
  border: 0;
  border-radius: 999px;
  background: var(--ds-primary, #ef6c00);
  color: #fff;
  font-weight: 700;
  padding: 8px 12px;
  cursor: pointer;
}
.stp-restore__btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.stp-body {
  flex: 1;
  overflow-y: auto;
  min-height: 0; /* 讓 flex 子元素能正確收縮 */
}

.stp-list {
  padding: 12px 12px;
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
  outline: 2px solid var(--ds-primary, #ef6c00);
  outline-offset: 2px;
}
.stp-card--selected {
  border-color: var(--ds-primary, #ef6c00);
  box-shadow: 0 0 0 2px rgba(245, 124, 0, 0.18);
}
.stp-card--cross { border-color: #f59e0b; }
.stp-card--warn { border-color: #f59e0b; background: #fffbeb; }
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
.stp-tag--branch { background: var(--ds-canvas-soft, #f3f4f6); color: var(--ds-ink-secondary, #4b5563); }
.stp-tag--conflict { background: #fee2e2; color: #b91c1c; font-weight: 600; }
.stp-tag--capacity-warn { background: #fef3c7; color: #92400e; font-weight: 600; }
.stp-tag--cross { background: #fef3c7; color: #92400e; }
.stp-tag--subject { background: #d1fae5; color: #065f46; }
.stp-tag--subject-muted { background: #f3f4f6; color: #6b7280; }
/* FR-006 三態容量標籤 */
.stp-cap-tag { font-weight: 600; min-width: 56px; text-align: center; }
.stp-cap-tag--ok { background: #d1fae5; color: #065f46; }
.stp-cap-tag--warn { background: #fef3c7; color: #92400e; }
.stp-cap-tag--full { background: #fee2e2; color: #b91c1c; }
.stp-cap-tag--unknown { background: #f3f4f6; color: #6b7280; }
.stp-cap-tag__short { display: none; }
@media (max-width: 375px) {
  .stp-cap-tag { min-width: 28px; }
  .stp-cap-tag__long { display: none; }
  .stp-cap-tag__short { display: inline; }
}
.stp-pick {
  color: var(--ds-primary-deep, #e65100);
  font-size: 12px;
  font-weight: 700;
}

.stp-progress {
  position: relative;
  height: 2px;
  overflow: hidden;
  background: #e5e7eb;
  margin: 0 20px;
  border-radius: 2px;
}
.stp-progress__bar {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 40%;
  background: var(--ds-primary, #ef6c00);
  animation: stp-progress-slide 1.2s ease-in-out infinite;
}
@keyframes stp-progress-slide {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(300%); }
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
  color: var(--ds-primary-deep, #e65100);
  border: 1px solid rgba(245, 124, 0, 0.4);
  border-radius: 8px;
  padding: 6px 14px;
  cursor: pointer;
}

/* PRD f0cce4d5：合併代課 + 換時間區塊 */
.stp-resched {
  padding: 6px 20px 0 20px;
  border-top: 1px solid #f3f4f6;
}
.stp-resched__toggle {
  background: transparent;
  border: 0;
  padding: 8px 0;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: #334155;
  width: 100%;
  text-align: left;
  min-height: 36px;
}
.stp-resched__toggle:hover { color: #1e293b; }
.stp-resched__chev {
  display: inline-block;
  width: 14px;
  transition: transform 200ms ease;
  color: #94a3b8;
}
.stp-resched__chev--open { transform: rotate(90deg); }
.stp-resched__panel {
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.2s ease;
}
.stp-resched__panel--open { max-height: 260px; }
.stp-resched__inner {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 12px;
  margin-bottom: 8px;
}
.stp-resched__row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 10px;
}
.stp-resched__label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
  color: #475569;
}
.stp-resched__label > span { font-weight: 500; }
.stp-resched__input {
  height: 36px;
  padding: 0 10px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 13px;
  background: #fff;
  box-sizing: border-box;
}
.stp-resched__input:focus { border-color: var(--ds-primary, #ef6c00); outline: 2px solid rgba(245, 124, 0, 0.2); }
.stp-resched__input--readonly {
  background: #f1f5f9;
  color: #64748b;
  cursor: not-allowed;
}
.stp-resched__err {
  margin-top: 8px;
  padding: 6px 10px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #b91c1c;
  border-radius: 6px;
  font-size: 12px;
}
.stp-resched__hint {
  margin-top: 8px;
  font-size: 11px;
  color: #64748b;
  line-height: 1.5;
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
.stp-reason__input:focus { border-color: var(--ds-primary, #ef6c00); outline: none; }

.stp-error {
  margin: 0 20px 10px 20px;
  padding: 8px 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #b91c1c;
  border-radius: 8px;
  font-size: 13px;
}
/* FR-004：no_class_session 屬資料前提錯誤，用 warning 色系區別於紅色衝堂 */
.stp-error--warning {
  background: #fffbeb;
  border-color: #fde68a;
  color: #92400e;
}
.stp-actions {
  padding: 10px 20px 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  border-top: 1px solid #f3f4f6;
  min-height: 66px; /* 預留 summary 行避免換時 toggle 造成 layout shift */
  justify-content: flex-end;
}
.stp-actions__summary {
  font-size: 13px;
  color: #64748b;
  text-align: right;
}
.stp-actions__row {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
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
  background: var(--ds-primary, #ef6c00);
  color: #fff;
}
.stp-btn--primary:hover { background: var(--ds-primary-press, #d84315); }
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
  .stp-btn { min-height: 44px; }
}
@media (max-width: 480px) {
  /* 換時區塊欄位垂直排列，行動裝置觸控目標 >= 44px */
  .stp-resched__row { grid-template-columns: 1fr; }
  .stp-resched__input { height: 44px; font-size: 14px; }
  .stp-resched__toggle { min-height: 44px; }
  .stp-resched__panel--open { max-height: 420px; }
}
</style>
