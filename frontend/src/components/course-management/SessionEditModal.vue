<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal session-edit-modal" style="max-width: 520px;">
      <h3 class="modal-title">單堂課操作</h3>
      <p class="modal-desc">{{ form.student_name }} — {{ subjectLabel }}</p>

      <div class="session-edit-info">
        <div class="se-row"><span class="se-label">日期</span><span>{{ form.session_date }}</span></div>
        <div class="se-row"><span class="se-label">時段</span><span>{{ form.start_time || '—' }} ~ {{ form.end_time || '—' }}</span></div>
        <div class="se-row"><span class="se-label">老師</span><span>{{ form.teacher_name || '—' }}</span></div>
        <div class="se-row">
          <span class="se-label">目前狀態</span>
          <span :class="['se-status-badge', 'se-st-' + form.current_status]">{{ statusLabel(form.current_status) }}</span>
        </div>
        <div v-if="form.attendance_time" class="se-row"><span class="se-label">點名時間</span><span>{{ form.attendance_time }}</span></div>
        <div v-if="form.lr_status && form.lr_status !== 'missing'" class="se-row"><span class="se-label">評量</span><span>{{ form.lr_status }}</span></div>
      </div>

      <div v-if="mode === 'menu'" class="session-edit-actions">
        <h4 class="se-section-title">操作</h4>
        <div class="se-action-grid se-action-grid-compact">
          <button v-if="canTransition('scheduled')" class="se-action-btn se-btn-scheduled" @click="$emit('status-change', 'scheduled')">改為未上</button>
          <button v-if="canTransition('leave') || canTransition('leave_adjusted')" class="se-action-btn se-btn-leave" @click="canTransition('leave_adjusted') ? $emit('start-retro-leave') : $emit('status-change', 'leave')">標記請假</button>
          <button class="se-action-btn se-btn-reschedule" @click="$emit('start-reschedule')">調課</button>
        </div>
        <div v-if="secondaryOptions.length" class="se-secondary-action">
          <label>其他狀態</label>
          <div class="se-secondary-row">
            <select v-model="localSecondary">
              <option value="">請選擇</option>
              <option v-for="opt in secondaryOptions" :key="opt" :value="opt">{{ statusLabel(opt) }}</option>
            </select>
            <button class="small ghost" :disabled="!localSecondary" @click="applySecondary">套用</button>
          </div>
        </div>
      </div>

      <div v-if="mode === 'retro-leave'" class="session-edit-retro">
        <h4 class="se-section-title">補請假確認</h4>
        <div class="retro-leave-warning">
          <strong>此堂已上課/已點名</strong>，確認後將沖回堂數並作廢該堂出缺勤與評量記錄。
        </div>
        <div class="form-group">
          <label>補請假原因（選填）</label>
          <input v-model="form.reason" type="text" placeholder="例：家長臨時通知" style="width: 100%;" />
        </div>
        <div class="actions">
          <button class="ghost" @click="$emit('set-mode', 'menu')">返回</button>
          <button class="primary" @click="$emit('do-retro-leave')" :disabled="submitting">確認補請假</button>
        </div>
      </div>

      <div v-if="mode === 'reschedule'" class="session-edit-reschedule">
        <h4 class="se-section-title">調課 — 選擇新時段</h4>
        <div class="se-reschedule-grid">
          <div class="form-group">
            <label>新日期</label>
            <input v-model="form.new_date" type="date" :min="todayYmd" />
          </div>
          <div class="form-group">
            <label>新開始時間</label>
            <select v-model="form.new_start">
              <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>預計新結束</label>
            <p class="computed-end-time">{{ computedEndTime || '—' }}</p>
          </div>
        </div>
        <div style="margin: 8px 0;">
          <button class="small ghost btn-makeup-query" @click="$emit('fetch-makeup')" :disabled="makeupLoading">
            {{ makeupLoading ? '查詢中…' : '查詢老師可補課時段' }}
          </button>
        </div>
        <div class="actions">
          <button class="ghost" @click="$emit('set-mode', 'menu')">返回</button>
          <button class="primary" @click="$emit('do-reschedule')" :disabled="submitting || !form.new_date">確認調課</button>
        </div>
      </div>

      <div v-if="mode === 'menu'" class="actions" style="margin-top: 16px; justify-content: space-between;">
        <button class="ghost" @click="$emit('close')">關閉</button>
        <button class="small ghost" @click="$emit('add-session')">+ 新增堂次</button>
      </div>

      <div v-if="submitting" class="se-loading">處理中…</div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const SESSION_STATUS_LABELS = {
  scheduled: '排課中', attended: '已上', completed: '已上', late: '遲到', absent: '缺席',
  excused: '請假', leave: '請假', leave_adjusted: '請假', cancelled: '已取消',
};
const SESSION_STATUS_TRANSITIONS = {
  scheduled:      ['attended', 'late', 'absent', 'excused', 'leave', 'cancelled'],
  attended:       ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'excused', 'cancelled'],
  completed:      ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'excused', 'cancelled'],
  late:           ['leave', 'leave_adjusted', 'scheduled', 'attended', 'absent', 'excused', 'cancelled'],
  absent:         ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'excused', 'cancelled'],
  excused:        ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'absent', 'cancelled'],
  leave:          ['scheduled', 'cancelled'],
  leave_adjusted: ['cancelled'],
  cancelled:      ['scheduled'],
};

const props = defineProps({
  show: Boolean,
  form: Object,
  mode: String,
  submitting: Boolean,
  timeOptions: Array,
  todayYmd: String,
  makeupLoading: Boolean,
  computeEndTime: Function,
});
const emit = defineEmits([
  'close', 'set-mode', 'status-change', 'start-retro-leave', 'do-retro-leave',
  'start-reschedule', 'do-reschedule', 'fetch-makeup', 'add-session',
]);

const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
const statusLabel = (s) => SESSION_STATUS_LABELS[s] || s || '—';
const canTransition = (target) => {
  const allowed = SESSION_STATUS_TRANSITIONS[props.form?.current_status] || [];
  return allowed.includes(target);
};
const secondaryOptions = computed(() => {
  const allowed = SESSION_STATUS_TRANSITIONS[props.form?.current_status] || [];
  const hidden = new Set(['scheduled', 'leave', 'leave_adjusted']);
  return allowed.filter((s) => !hidden.has(s));
});
const computedEndTime = computed(() => props.computeEndTime?.(props.form?.new_start, props.form?.duration_hours) || '');

const localSecondary = ref('');
function applySecondary() {
  if (!localSecondary.value) return;
  emit('status-change', localSecondary.value);
  localSecondary.value = '';
}
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
.session-edit-info { background: #f8fafc; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; flex-direction: column; gap: 6px; }
.se-row { display: flex; align-items: center; gap: 8px; font-size: 0.93em; }
.se-label { font-weight: 600; color: #475569; min-width: 70px; }
.se-status-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.85em; font-weight: 600; }
.se-st-scheduled { background: #e0f2fe; color: #0369a1; }
.se-st-attended, .se-st-late { background: #dcfce7; color: #166534; }
.se-st-absent { background: #fee2e2; color: #991b1b; }
.se-st-excused, .se-st-leave { background: #fef3c7; color: #92400e; }
.se-st-leave_adjusted { background: #ffedd5; color: #9a3412; }
.se-st-cancelled { background: #f3f4f6; color: #6b7280; }
.se-section-title { font-size: 0.95em; font-weight: 600; color: #334155; margin: 0 0 10px; }
.se-action-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
.se-action-grid-compact { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.se-secondary-action { margin-top: 10px; }
.se-secondary-action label { display: block; font-size: 0.85em; color: #64748b; margin-bottom: 6px; }
.se-secondary-row { display: flex; gap: 8px; align-items: center; }
.se-secondary-row select { flex: 1; }
.se-action-btn {
  padding: 8px 12px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff;
  font-size: 0.88em; font-weight: 500; cursor: pointer; text-align: center; transition: all 0.15s ease;
}
.se-action-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.se-btn-leave { border-color: #fcd34d; color: #b45309; } .se-btn-leave:hover { background: #fef3c7; }
.se-btn-scheduled { border-color: #93c5fd; color: #1d4ed8; } .se-btn-scheduled:hover { background: #dbeafe; }
.se-btn-reschedule { border-color: #a78bfa; color: #6d28d9; } .se-btn-reschedule:hover { background: #ede9fe; }
.se-loading { text-align: center; color: #64748b; padding: 8px 0; font-size: 0.9em; }
.session-edit-reschedule .se-reschedule-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.session-edit-reschedule .se-reschedule-grid .form-group:last-child { grid-column: 1 / -1; }
.retro-leave-warning { margin: 8px 0; padding: 10px 14px; border-radius: 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 0.92em; }
.computed-end-time { margin: 0; font-weight: 600; font-size: 1rem; }
.btn-makeup-query {
  width: 100%; padding: 8px 12px !important; font-size: 13px !important; font-weight: 600;
  border: 1px dashed var(--primary) !important; color: var(--primary) !important;
  border-radius: 8px; transition: var(--transition);
}
.btn-makeup-query:hover:not(:disabled) { background: var(--primary-bg) !important; }
.btn-makeup-query:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
