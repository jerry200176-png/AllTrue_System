<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && $emit('close')">
    <div class="modal course-modal transfer-sessions-modal">
      <h3 class="modal-title">轉移堂次紀錄</h3>
      <p class="modal-desc">來源課程／{{ studentName }}／{{ subjectLabel }}</p>
      <p class="source-course-meta">
        來源剩餘 {{ sourceCourse?.remaining_sessions ?? sourceCourse?.RemainingSessions ?? '—' }} 堂
        <span v-if="sourceCourse?.start_date || sourceCourse?.StartDate">／開始 {{ sourceCourse?.start_date || sourceCourse?.StartDate }}</span>
        <span v-if="sourceCourse?.start_time">／{{ sourceCourse.start_time }}<span v-if="sourceCourse?.end_time">–{{ sourceCourse.end_time }}</span></span>
      </p>
      <p class="period-hint">
        把已上課、已填評量的堂次搬到另一門課程，評量與點名紀錄會一起跟過去，不用重填。
        <strong>不會</strong>異動任一課程的堂數／金額，帳務對帳仍照原本流程。
      </p>

      <div class="form-group">
        <label for="transfer-target-course">目標課程</label>
        <input
          id="transfer-target-course"
          v-model.trim="targetCourseQuery"
          type="text"
          placeholder="搜尋學生的其他課程"
          autocomplete="off"
          :disabled="submitting"
          @input="onTargetCourseInput"
        />
        <span class="hint">先在學生管理建立新一期課程，再從下面點選；不會自動建立課程，也不能只貼未確認的代碼。</span>
        <div v-if="targetCoursesLoading" class="hint">正在找這位學生的其他課程…</div>
        <div v-else-if="filteredTargetCourses.length > 0" class="target-course-list" role="listbox" aria-label="可選的目標課程">
          <button
            v-for="course in filteredTargetCourses"
            :key="course.id"
            type="button"
            class="target-course-option"
            :class="{ selected: String(course.id) === targetCourseId }"
            :aria-selected="String(course.id) === targetCourseId"
            @click="selectTargetCourse(course)"
          >
            <span class="target-course-copy">
              <strong>{{ targetCourseTitle(course) }}</strong>
            <small>{{ targetCourseMeta(course) }}</small>
            </span>
            <span class="target-course-check material-symbols-outlined" aria-hidden="true">
              {{ String(course.id) === targetCourseId ? 'check_circle' : 'radio_button_unchecked' }}
            </span>
          </button>
        </div>
        <div v-else class="hint target-course-empty">目前找不到可選課程；請確認已為同一學生建立同科目的新課程。</div>
        <div v-if="selectedTargetCourse" class="selected-target-hint">
          已選目標：<strong>{{ targetCourseTitle(selectedTargetCourse) }}</strong>／{{ targetCourseMeta(selectedTargetCourse) }}
        </div>
      </div>

      <div class="form-group">
        <label>選擇要搬的堂次（{{ selectedIds.length }} / {{ sessions.length }}）</label>
        <div v-if="sessions.length === 0" class="hint">這門課目前沒有可轉移的已上課堂次。</div>
        <div v-else class="session-pick-list">
          <label v-for="s in sessions" :key="s.id" class="session-pick-row">
            <input type="checkbox" :value="s.id" v-model="selectedIds" :disabled="submitting" />
            <span class="session-pick-date">{{ s.date }}<span v-if="s.startTime"> {{ s.startTime }}<span v-if="s.endTime">–{{ s.endTime }}</span></span></span>
            <span class="session-pick-status" :class="`st-${s.status}`">{{ statusLabel(s.status) }}</span>
          </label>
        </div>
      </div>

      <div v-if="hasRecoverySelection" class="recovery-warning" role="status">
        已選取仍保留評量／點名證據的「已取消」堂次。送出後會恢復為「已上課」，並將評量、點名與扣堂台帳一起移轉。
      </div>
      <div v-if="hasRecoverySelection" class="form-group">
        <label for="transfer-recovery-reason">恢復原因（必填）</label>
        <textarea
          id="transfer-recovery-reason"
          v-model.trim="recoveryReason"
          rows="2"
          maxlength="255"
          :disabled="submitting"
          placeholder="例如：原合約誤將已完成堂次標示為已取消，依評量／點名紀錄恢復並移轉"
        />
      </div>

      <p v-if="errorMessage" class="transfer-error" role="alert">{{ errorMessage }}</p>

      <div class="actions">
        <button class="ghost" :disabled="submitting" @click="$emit('close')">取消</button>
        <button
          class="primary"
          :disabled="submitting || selectedIds.length === 0 || !targetCourseId || (hasRecoverySelection && !recoveryReason)"
          @click="onSubmit"
        >{{ submitting ? '轉移中…' : `轉移 ${selectedIds.length} 堂` }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({
  show: Boolean,
  studentName: { type: String, default: '' },
  subject: { type: String, default: '' },
  sourceCourse: { type: Object, default: null },
  sessions: { type: Array, default: () => [] }, // [{ id, date, status }]
  targetCourses: { type: Array, default: () => [] },
  targetCoursesLoading: { type: Boolean, default: false },
  submitting: { type: Boolean, default: false },
  errorMessage: { type: String, default: '' },
});
const emit = defineEmits(['close', 'submit']);

const subjectLabel = computed(() => getSubjectLabel(props.subject));
const targetCourseId = ref('');
const targetCourseQuery = ref('');
const selectedIds = ref([]);
const recoveryReason = ref('');

const hasRecoverySelection = computed(() => props.sessions.some((session) => (
  selectedIds.value.includes(Number(session.id)) && session.recoverableCancelled
)));

const filteredTargetCourses = computed(() => {
  const q = targetCourseQuery.value.trim().toLowerCase();
  if (!q || /^\d+$/.test(q)) return props.targetCourses.slice(0, 8);
  return props.targetCourses.filter((course) => {
    const haystack = [
      course.subject_name,
      course.subject,
      course.teacher_name,
      course.start_date,
      course.id,
    ].filter(Boolean).join(' ').toLowerCase();
    return haystack.includes(q);
  }).slice(0, 8);
});
const selectedTargetCourse = computed(() => props.targetCourses.find((course) => String(course.id) === targetCourseId.value) || null);

// Reset the form every time the modal opens for a (possibly different) course.
watch(() => props.show, (isShown) => {
  if (isShown) {
    targetCourseId.value = '';
    targetCourseQuery.value = '';
    selectedIds.value = [];
    recoveryReason.value = '';
  }
});

const STATUS_LABELS = {
  attended: '已上', late: '已上（遲到）', leave: '請假', absent: '缺席', scheduled: '未上',
  cancelled_recoverable: '已取消（可恢復）',
};
function statusLabel(status) {
  return STATUS_LABELS[status] || status || '';
}

function targetCourseTitle(course) {
  const subject = course.subject_name || getSubjectLabel(course.subject) || '未命名科目';
  return `${subject}｜${course.teacher_name || '尚未指定老師'}`;
}

function targetCourseMeta(course) {
  const start = course.start_date ? `開始 ${course.start_date}` : '開始日期未設定';
  const time = course.start_time ? `／${course.start_time}${course.end_time ? `–${course.end_time}` : ''}` : '';
  const remaining = `／剩餘 ${course.remaining_sessions ?? course.RemainingSessions ?? '—'} 堂`;
  const student = course.student_name ? `／學生 ${course.student_name}` : '';
  return `${student}${start}${time}${remaining}`;
}

function selectTargetCourse(course) {
  targetCourseId.value = String(course.id);
  targetCourseQuery.value = '';
}

function onTargetCourseInput(event) {
  const value = String(event?.target?.value || '').trim();
  targetCourseQuery.value = value;
  targetCourseId.value = '';
}

function onSubmit() {
  const targetId = Number(targetCourseId.value);
  if (!targetId || targetId <= 0) return;
  if (hasRecoverySelection.value && !recoveryReason.value) return;
  emit('submit', {
    targetCourseId: targetId,
    sessionIds: selectedIds.value.map(Number),
    ...(hasRecoverySelection.value ? { reason: recoveryReason.value } : {}),
  });
}
</script>

<style scoped>
.course-modal { width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.2rem; font-weight: 800; color: var(--text); margin: 0 0 4px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin: 0 0 12px; }
.period-hint {
  margin: 0 0 14px;
  padding: 10px 12px;
  border-radius: 10px;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
  font-size: 12px;
  line-height: 1.5;
}
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.form-group input[type="text"] {
  width: 100%; padding: 8px 10px; border: 1px solid var(--ds-hairline); border-radius: 8px; font-size: 14px;
}
.form-group textarea {
  width: 100%; padding: 8px 10px; border: 1px solid var(--ds-hairline); border-radius: 8px; font-size: 13px; resize: vertical;
}
.recovery-warning {
  margin: 8px 0 14px; padding: 10px 12px; border: 1px solid var(--ds-warning); border-radius: 8px;
  background: var(--ds-warning-wash); color: var(--ds-warning); font-size: 12px; line-height: 1.5;
}
.hint { display: block; margin-top: 6px; font-size: 12px; color: var(--text-light); }
.session-pick-list {
  display: flex; flex-direction: column; gap: 4px; max-height: 240px; overflow-y: auto;
  border: 1px solid var(--ds-hairline); border-radius: 8px; padding: 6px;
}
.session-pick-row {
  display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 6px; font-size: 13px; cursor: pointer;
}
.session-pick-row:hover { background: var(--ds-canvas-soft); }
.session-pick-date { flex: 1; }
.session-pick-status { font-size: 12px; color: var(--ds-ink-mute); }
.st-attended, .st-late { color: var(--ds-success); }
.st-absent { color: var(--ds-danger); }
.transfer-error {
  margin: 0 0 12px; padding: 8px 10px; border-radius: 8px;
  background: var(--ds-danger-wash); color: var(--ds-danger); font-size: 13px;
}
.actions {
  position: sticky; bottom: 0; margin-top: 4px; padding-top: 10px;
  background: linear-gradient(180deg, rgba(248, 250, 252, 0) 0%, rgba(248, 250, 252, 0.98) 24%);
  display: flex; justify-content: flex-end; gap: 8px;
}
</style>
