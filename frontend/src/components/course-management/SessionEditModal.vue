<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal session-edit-modal" style="max-width: 520px;">
      <h3 class="modal-title">單堂檢視</h3>
      <p class="modal-desc">{{ form.student_name }} — {{ subjectLabel }}</p>

      <div class="session-edit-info">
        <div class="se-row"><span class="se-label">本堂日期</span><span>{{ form.session_date }}</span></div>
        <div class="se-row"><span class="se-label">上課時間</span><span>{{ form.start_time || '—' }} ~ {{ form.end_time || '—' }}</span></div>
        <div class="se-row"><span class="se-label">老師</span><span>{{ form.teacher_name || '—' }}</span></div>
        <div class="se-row">
          <span class="se-label">目前狀態</span>
          <span :class="['se-status-badge', 'se-st-' + form.current_status]">{{ statusLabel(form.current_status) }}</span>
        </div>
        <div v-if="form.attendance_time" class="se-row"><span class="se-label">點名時間</span><span>{{ form.attendance_time }}</span></div>
        <div v-if="form.lr_status && form.lr_status !== 'missing'" class="se-row"><span class="se-label">評量</span><span>{{ form.lr_status }}</span></div>
      </div>

      <div v-if="mode === 'menu'" class="session-edit-actions">
        <h4 class="se-section-title">單堂操作</h4>
        <div class="se-action-grid se-action-grid-compact">
          <button v-if="canTransition('scheduled')" class="se-action-btn se-btn-scheduled" @click="$emit('status-change', 'scheduled')">改為未上</button>
          <button v-if="canTransition('attended')" class="se-action-btn se-btn-attended" @click="$emit('status-change', 'attended')">標記已上</button>
          <button v-if="canTransition('leave') || canTransition('leave_adjusted')" class="se-action-btn se-btn-leave" @click="canTransition('leave_adjusted') ? $emit('start-retro-leave') : $emit('status-change', 'leave')">標記請假</button>
          <button v-if="canTransition('absent')" class="se-action-btn se-btn-absent" @click="$emit('status-change', 'absent')">缺席</button>
          <button v-if="canTransition('late')" class="se-action-btn se-btn-late" @click="$emit('status-change', 'late')">遲到</button>
          <button v-if="canTransition('cancelled')" class="se-action-btn se-btn-cancelled" @click="$emit('status-change', 'cancelled')">取消</button>
          <button class="se-action-btn se-btn-reschedule" @click="$emit('start-reschedule')">調課</button>
          <button class="se-action-btn se-btn-substitute" @click="$emit('start-substitute')">換代課老師</button>
          <button class="se-action-btn se-btn-edit-note" @click="$emit('start-edit-note-time')">備註 / 時段</button>
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

      <div v-if="mode === 'substitute'" class="session-edit-substitute">
        <h4 class="se-section-title">換代課老師</h4>
        <p class="se-sub-hint">僅替換此堂授課老師，不影響課程主檔與後續排課。</p>
        <div class="form-group">
          <label>代課老師</label>
          <select v-model="form.substitute_teacher_id">
            <option value="">請選擇</option>
            <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>原因（選填）</label>
          <input v-model="form.substitute_reason" type="text" placeholder="例：正班老師請假" style="width: 100%;" />
        </div>
        <div class="actions">
          <button class="ghost" @click="$emit('set-mode', 'menu')">返回</button>
          <button class="primary" @click="$emit('do-substitute')" :disabled="submitting || !form.substitute_teacher_id">確認代課</button>
        </div>
      </div>

      <div v-if="mode === 'reschedule'" class="session-edit-reschedule">
        <h4 class="se-section-title">調課 — 選擇新時段</h4>
        <div class="se-reschedule-grid">
          <div class="form-group">
            <label>新日期</label>
            <input v-model="form.new_date" type="date" />
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

      <div v-if="mode === 'edit-note-time'" class="session-edit-note-time">
        <h4 class="se-section-title">備註 / 調整結束時間</h4>
        <div v-if="form.lr_status === 'approved'" class="se-info-banner se-banner-info">
          此堂已有核准評量，修改結束時間後評量記錄時間也會同步更新。
        </div>
        <div class="form-group">
          <label>結束時間</label>
          <input v-model="form.edit_end_time" type="time" step="1800" style="width: 100%;" />
          <small class="field-note">修改後評量表的結束時間也會同步。</small>
        </div>
        <div class="form-group">
          <label>備註</label>
          <input v-model="form.note" type="text" placeholder="例：今日加課 1 小時，已收費" style="width: 100%;" maxlength="200" />
        </div>
        <div class="actions">
          <button class="ghost" @click="$emit('set-mode', 'menu')">返回</button>
          <button class="primary" @click="$emit('do-edit-note-time')" :disabled="submitting">儲存</button>
        </div>
      </div>

      <div v-if="mode === 'menu'" class="actions" style="margin-top: 16px; justify-content: space-between;">
        <button class="ghost" @click="$emit('close')">關閉</button>
        <button class="small ghost se-secondary-add" @click="$emit('add-session')" title="亦可在課程管理列表直接操作">+ 新增堂次</button>
      </div>

      <div v-if="submitting" class="se-loading">處理中…</div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const SESSION_STATUS_LABELS = {
  scheduled: '排課中', attended: '已上', completed: '已上', late: '遲到', absent: '缺席',
  excused: '請假', leave: '請假', leave_adjusted: '請假', cancelled: '已取消',
};
const SESSION_STATUS_TRANSITIONS = {
  scheduled:      ['attended', 'late', 'absent', 'leave', 'cancelled'],
  attended:       ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'cancelled'],
  completed:      ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'cancelled'],
  late:           ['leave', 'leave_adjusted', 'scheduled', 'attended', 'absent', 'cancelled'],
  absent:         ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'cancelled'],
  leave:          ['scheduled', 'attended', 'late', 'absent', 'cancelled'],
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
  teachers: { type: Array, default: () => [] },
});
const emit = defineEmits([
  'close', 'set-mode', 'status-change', 'start-retro-leave', 'do-retro-leave',
  'start-reschedule', 'do-reschedule', 'fetch-makeup', 'add-session',
  'start-substitute', 'do-substitute',
  'start-edit-note-time', 'do-edit-note-time',
]);

const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
const statusLabel = (s) => SESSION_STATUS_LABELS[s] || s || '—';
const canTransition = (target) => {
  const allowed = SESSION_STATUS_TRANSITIONS[props.form?.current_status] || [];
  return allowed.includes(target);
};
const computedEndTime = computed(() => props.computeEndTime?.(props.form?.new_start, props.form?.duration_hours) || '');
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
.se-action-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; }
.se-action-grid-compact { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.se-action-btn {
  padding: 8px 12px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff;
  font-size: 0.88em; font-weight: 500; cursor: pointer; text-align: center; transition: all 0.15s ease;
}
.se-action-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.se-btn-leave { border-color: #fcd34d; color: #b45309; } .se-btn-leave:hover { background: #fef3c7; }
.se-btn-scheduled { border-color: #93c5fd; color: #1d4ed8; } .se-btn-scheduled:hover { background: #dbeafe; }
.se-btn-reschedule { border-color: #a78bfa; color: #6d28d9; } .se-btn-reschedule:hover { background: #ede9fe; }
.se-btn-substitute { border-color: #67e8f9; color: #0e7490; } .se-btn-substitute:hover { background: #ecfeff; }
.se-sub-hint { font-size: 0.85em; color: #64748b; margin: 0 0 12px; }
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
.se-btn-edit-note { border-color: #86efac; color: #166534; }
.se-btn-edit-note:hover { background: #dcfce7; }
.se-btn-attended { border-color: #6ee7b7; color: #065f46; } .se-btn-attended:hover { background: #d1fae5; }
.se-btn-late { border-color: #fca5a5; color: #b91c1c; } .se-btn-late:hover { background: #fee2e2; }
.se-btn-absent { border-color: #f87171; color: #991b1b; } .se-btn-absent:hover { background: #fecaca; }
.se-btn-cancelled { border-color: #d1d5db; color: #4b5563; } .se-btn-cancelled:hover { background: #f3f4f6; }
.se-info-banner { margin: 0 0 12px; padding: 10px 14px; border-radius: 8px; font-size: 0.88em; }
.se-banner-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
.field-note { display: block; margin-top: 4px; font-size: 0.8em; color: #64748b; }
.se-secondary-add { font-size: 0.82em !important; color: #94a3b8 !important; border-color: transparent !important; }
.se-secondary-add:hover { color: #64748b !important; }
</style>
