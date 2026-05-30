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
          <button
            class="se-action-btn se-btn-substitute"
            @click="featureSubstituteV2 ? $emit('open-substitute-v2') : $emit('start-substitute')"
          >換代課老師</button>
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
        <h4 class="se-section-title">備註 / 調整時段</h4>
        <div v-if="form.lr_status === 'approved'" class="se-info-banner se-banner-info">
          此堂已有核准評量，修改結束時間後評量記錄時間也會同步更新。
        </div>
        <div class="se-time-grid">
          <div class="form-group">
            <label>開始時間</label>
            <input v-model="form.edit_start_time" type="time" step="1800" class="se-time-input" />
          </div>
          <div class="form-group">
            <label>結束時間</label>
            <input v-model="form.edit_end_time" type="time" step="1800" class="se-time-input" />
          </div>
        </div>
        <p v-if="timeRangeError" class="se-inline-error" role="alert">{{ timeRangeError }}</p>

        <div class="se-charge-preview" :class="chargePreviewClass" aria-live="polite">
          <template v-if="chargePreview.kind === 'ok'">
            <span class="se-charge-label">此堂費用</span>
            <span class="se-charge-value">NT$ {{ chargePreview.value.toLocaleString() }}</span>
            <span v-if="chargePreview.unit === 'hour' && chargePreview.deltaText" class="se-charge-delta">{{ chargePreview.deltaText }}</span>
            <small v-if="chargePreview.unit === 'hour'" class="se-charge-hint">實際 {{ chargePreview.actualMinutes }} 分鐘 / 標準 {{ chargePreview.standardMinutes }} 分鐘</small>
            <small v-else class="se-charge-hint">按堂計費：每堂固定金額，時段調整不影響收費</small>
          </template>
          <template v-else-if="chargePreview.kind === 'no-rate'">
            <span class="se-charge-label">此堂費用</span>
            <span class="se-charge-empty">費率未設定，無法計算</span>
          </template>
          <template v-else>
            <span class="se-charge-label">此堂費用</span>
            <span class="se-charge-empty">請輸入有效的時段</span>
          </template>
        </div>

        <div class="form-group">
          <label>備註</label>
          <input v-model="form.note" type="text" placeholder="例：今日加課 1 小時，已收費" style="width: 100%;" maxlength="200" />
        </div>
        <small v-if="chargePreview.unit === 'hour'" class="field-note">修改後評量表的結束時間也會同步；按時計費預覽僅供核帳參考，不會在此自動改課程總費用。</small>
        <small v-else class="field-note">修改後評量表的結束時間也會同步；按堂計費：時段調整不影響本堂費用。</small>
        <div class="actions">
          <button class="ghost" @click="$emit('set-mode', 'menu')">返回</button>
          <button class="primary" @click="onSaveClick" :disabled="submitting || !!timeRangeError">儲存</button>
        </div>
      </div>

      <div v-if="mode === 'menu'" class="actions" style="margin-top: 16px; justify-content: space-between;">
        <button class="ghost" @click="$emit('close')">關閉</button>
        <button class="small ghost se-secondary-add" @click="$emit('add-session')" title="亦可在課程管理列表直接操作">+ 補課 / 補登</button>
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
  // PRD 9c058f19：啟用代課 V2 卡片式 Modal（由父層處理 open-substitute-v2 事件）
  featureSubstituteV2: { type: Boolean, default: false },
});
const emit = defineEmits([
  'close', 'set-mode', 'status-change', 'start-retro-leave', 'do-retro-leave',
  'start-reschedule', 'do-reschedule', 'fetch-makeup', 'add-session',
  'start-substitute', 'do-substitute', 'open-substitute-v2',
  'start-edit-note-time', 'do-edit-note-time',
]);

const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
const statusLabel = (s) => SESSION_STATUS_LABELS[s] || s || '—';
const canTransition = (target) => {
  const allowed = SESSION_STATUS_TRANSITIONS[props.form?.current_status] || [];
  return allowed.includes(target);
};
const computedEndTime = computed(() => props.computeEndTime?.(props.form?.new_start, props.form?.duration_hours) || '');

function minutesOf(hhmm) {
  if (!hhmm || typeof hhmm !== 'string') return null;
  const m = hhmm.match(/^(\d{2}):(\d{2})$/);
  if (!m) return null;
  return Number(m[1]) * 60 + Number(m[2]);
}
function diffMinutes(start, end) {
  const s = minutesOf(start);
  const e = minutesOf(end);
  if (s == null || e == null) return null;
  const d = e - s;
  return d > 0 ? d : null;
}

const timeRangeError = computed(() => {
  const s = props.form?.edit_start_time;
  const e = props.form?.edit_end_time;
  if (!s || !e) return '';
  const sMin = minutesOf(s);
  const eMin = minutesOf(e);
  if (sMin == null || eMin == null) return '';
  if (eMin <= sMin) return '結束時間必須晚於開始時間';
  return '';
});

const chargePreview = computed(() => {
  const rate = Number(props.form?.contract_rate ?? 0);
  const dur = Number(props.form?.contract_session_duration ?? 0);
  const unit = String(props.form?.contract_rate_unit || 'session').toLowerCase();
  if (!(rate > 0) || !(dur > 0)) {
    return { kind: 'no-rate' };
  }
  const actual = diffMinutes(props.form?.edit_start_time, props.form?.edit_end_time);
  if (actual == null) return { kind: 'no-time' };

  // 按堂計費（session mode）：費用固定等於合約 Rate，不隨時段長度縮放。
  // 業界慣例：按堂收費是「一堂多少錢」的契約，時段微調不影響收費。
  if (unit !== 'hour') {
    const value = Math.round(rate);
    return {
      kind: 'ok',
      unit: 'session',
      value,
      standard: value,
      deltaText: '',
      tone: 'standard',
      actualMinutes: actual,
      standardMinutes: dur,
      deviationRatio: 0,
    };
  }

  // 按時計費（hour mode）：依實際時長按小時比例計費。
  const value = Math.round(rate * (actual / 60));
  const standard = Math.round(rate * (dur / 60));
  const deltaAbs = value - standard;
  let deltaText = '';
  let tone = 'standard';
  if (deltaAbs > 0) { deltaText = `+NT$ ${deltaAbs.toLocaleString()}（高於標準）`; tone = 'higher'; }
  else if (deltaAbs < 0) { deltaText = `-NT$ ${Math.abs(deltaAbs).toLocaleString()}（低於標準）`; tone = 'lower'; }

  return {
    kind: 'ok',
    unit: 'hour',
    value,
    standard,
    deltaText,
    tone,
    actualMinutes: actual,
    standardMinutes: dur,
    deviationRatio: standard > 0 ? Math.abs(deltaAbs) / standard : 0,
  };
});

const chargePreviewClass = computed(() => {
  const p = chargePreview.value;
  if (!p || p.kind !== 'ok') return 'se-charge-neutral';
  if (p.tone === 'higher') return 'se-charge-higher';
  if (p.tone === 'lower') return 'se-charge-lower';
  return 'se-charge-standard';
});

function onSaveClick() {
  if (timeRangeError.value) return;
  const p = chargePreview.value;
  // 僅按時計費（hour mode）才檢查偏離標準提示；按堂計費費用固定，不需提醒。
  if (p?.kind === 'ok' && p.unit === 'hour' && (p.deviationRatio ?? 0) >= 0.5) {
    const ok = window.confirm(
      `此堂費用 NT$ ${p.value.toLocaleString()}，明顯偏離標準費用 NT$ ${p.standard.toLocaleString()}（差異 ${Math.round(p.deviationRatio * 100)}%）。確定儲存嗎？`
    );
    if (!ok) return;
  }
  emit('do-edit-note-time');
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
.se-banner-info { background: var(--ds-canvas-soft, #f6f9fc); border: 1px solid var(--ds-hairline, #e3e8ee); color: var(--ds-ink, #1a1a1a); }
.field-note { display: block; margin-top: 4px; font-size: 0.8em; color: #64748b; }
.se-secondary-add { font-size: 0.82em !important; color: #94a3b8 !important; border-color: transparent !important; }
.se-secondary-add:hover { color: #64748b !important; }

/* 單堂時間費率自動計算 — SessionEditModal 精緻化 */
.se-time-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.se-time-input {
  width: 100%;
  min-height: 44px;
  padding: 8px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  font-size: 15px;
}
.se-time-input:focus { outline: none; border-color: var(--primary, #ef6c00); box-shadow: var(--ds-focus-ring, 0 0 0 3px rgba(245,124,0,0.22)); }
.se-inline-error {
  margin: 6px 0 10px;
  padding: 8px 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
  color: #991b1b;
  font-size: 0.85em;
}
.se-charge-preview {
  margin: 12px 0 14px;
  padding: 12px 14px;
  border-radius: 10px;
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px 12px;
  border: 1px solid transparent;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.se-charge-label { font-size: 0.85em; font-weight: 600; color: #475569; min-width: 64px; }
.se-charge-value { font-size: 1.25rem; font-weight: 700; letter-spacing: 0.3px; }
.se-charge-delta { font-size: 0.85em; font-weight: 600; }
.se-charge-hint { width: 100%; font-size: 0.78em; color: #64748b; }
.se-charge-empty { font-size: 0.9em; color: #94a3b8; font-style: italic; }
.se-charge-standard { background: #eff6ff; border-color: #bfdbfe; }
.se-charge-standard .se-charge-value { color: #1d4ed8; }
.se-charge-higher { background: #fff7ed; border-color: #fed7aa; }
.se-charge-higher .se-charge-value { color: #c2410c; }
.se-charge-higher .se-charge-delta { color: #c2410c; }
.se-charge-lower { background: #eff6ff; border-color: #bfdbfe; }
.se-charge-lower .se-charge-value { color: #1e40af; }
.se-charge-lower .se-charge-delta { color: #1e40af; }
.se-charge-neutral { background: #f8fafc; border-color: #e2e8f0; }

@media (max-width: 520px) {
  .se-time-grid { grid-template-columns: 1fr; }
}
</style>
