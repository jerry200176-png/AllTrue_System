<!--
  CalendarSessionEditModal（#740 Modals — 單堂檢視 / 編輯）
  Presentational：僅在 editingCourseId 時顯示。建立新課程走 UniversalClassScheduler。
  5 props：show / form / ratePer2h / session / options（Meta/Linear 分組）。
  CSS 自 SmartCalendar.vue 搬移（session-info / schedule-actions / eval-summary / conflict）。
-->
<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal session-edit-modal" style="width: 500px;">
      <h3>單堂檢視</h3>

      <div v-if="session.actionDate" class="session-info-card">
        <div class="session-info-row">
          <span class="session-info-key">本堂日期</span>
          <span class="session-info-val"><strong>{{ session.actionDate }}</strong>（{{ session.dayName }}）</span>
        </div>
        <div class="session-info-row">
          <span class="session-info-key">上課時間</span>
          <span class="session-info-val">{{ form.start_time }} ~ {{ session.endTime }}（{{ form.duration_hours }} 小時）</span>
        </div>
        <div v-if="session.chargeDisplay" class="session-info-row">
          <span class="session-info-key">本堂費用</span>
          <span class="session-info-val">
            <strong>NT$ {{ session.chargeDisplay.value.toLocaleString() }}</strong>
            <span v-if="session.chargeDisplay.isAdjusted" class="session-charge-adjusted">（已依實際時長調整）</span>
            <span v-else class="session-charge-standard">（標準費用）</span>
          </span>
        </div>
      </div>
      <p class="hint occurrence-hint">
        僅可進行單堂操作（請假／調課／加課）。如需修改整門課設定，請至「課程管理」。
      </p>

      <div v-if="session.conflictWarning" class="conflict-box conflict-box-prominent">
        <span class="material-symbols-outlined conflict-icon">warning</span>
        <div>
          <strong>衝堂警告</strong>
          <p>{{ session.conflictWarning }}</p>
        </div>
      </div>

      <div class="schedule-actions-box">
        <div class="schedule-actions-title">單堂操作</div>
        <div class="schedule-actions-btns">
          <button class="action-btn leave" @click="$emit('leave')">📋 請假</button>
          <button class="action-btn reschedule" @click="$emit('reschedule')">🔄 調課</button>
          <button
            v-if="!session.isTeacher"
            class="action-btn substitute"
            @click="$emit(session.featureSubstituteV2 ? 'substitute-v2' : 'substitute')"
          >👤 換代課老師</button>
          <button
            v-if="!session.isTeacher && session.canCancelSession"
            class="action-btn cancel-session"
            @click="$emit('show-cancel-confirm')"
          >🚫 取消本堂</button>
        </div>
        <div v-if="session.cancelState.show" class="cancel-session-confirm">
          <p>確定取消這堂課？<br><small>此操作無法自動還原（可至課程管理手動設回「排程中」）。</small></p>
          <div class="cancel-session-confirm-btns">
            <button class="action-btn" style="background:#e2e8f0;color:#475569;" @click="$emit('dismiss-cancel-confirm')">不取消</button>
            <button class="action-btn cancel-session" :disabled="session.cancelState.loading" @click="$emit('confirm-cancel')">
              {{ session.cancelState.loading ? '處理中...' : '確定取消本堂' }}
            </button>
          </div>
        </div>
      </div>

      <div class="modal-form-sections course-ref-section">
        <div class="form-section-label">課程資料（僅供參考）</div>
        <p class="course-ref-hint">以下為整門課設定，不可在此修改。如需調整請至「課程管理」。</p>
        <div class="ref-grid">
          <div class="form-group">
            <label>學生</label>
            <SearchableSelect
              v-model="form.student_id"
              :options="options.studentSelectOptions"
              disabled
              placeholder="輸入學生姓名搜尋..."
            />
          </div>
          <div class="form-group">
            <label>科目</label>
            <select v-model="form.subject" disabled>
              <option v-for="s in options.subjectOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>老師</label>
            <select v-model="form.teacher_id" disabled @change="$emit('teacher-change')">
              <option value="">請選擇</option>
              <option v-for="t in options.teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>類型</label>
            <select v-model="form.class_type" disabled>
              <option value="one_on_one">一對一</option>
              <option value="one_on_two">一對二</option>
              <option value="one_on_three">一對三</option>
              <option value="tutoring">輔導</option>
              <option value="trial">試聽</option>
            </select>
          </div>
        </div>

        <div class="form-section-label">時段與費用</div>
        <div class="ref-grid">
          <div class="form-group">
            <label>課程時長</label>
            <p class="computed-time">{{ form.duration_hours }} 小時</p>
          </div>
          <div class="form-group">
            <label>一堂課費用</label>
            <p class="computed-time">${{ ratePer2h }}</p>
          </div>
        </div>

        <div class="form-section-label">繳費狀態（僅供參考）</div>
        <div class="ref-grid">
          <div class="form-group">
            <label>繳費方式</label>
            <select v-model="form.payment_type" disabled>
              <option value="session">堂數制</option>
              <option value="monthly">月結</option>
            </select>
          </div>
          <template v-if="form.payment_type === 'monthly'">
            <div class="form-group">
              <label>結算日</label>
              <select v-model.number="form.settlement_day" disabled>
                <option :value="null">請選擇</option>
                <option v-for="day in options.settlementDayOptions" :key="day" :value="day">每月 {{ day }} 號</option>
              </select>
            </div>
            <div class="form-group">
              <label>本月預排堂數</label>
              <input v-model.number="form.monthly_sessions" type="number" min="1" disabled />
            </div>
          </template>
          <div v-if="form.payment_type === 'session'" class="form-group">
            <label>剩餘堂數</label>
            <input v-model.number="form.remaining_sessions" type="number" disabled />
          </div>
        </div>
      </div>

      <div v-if="session.evalRecords.length > 0" class="eval-summary-box">
        <div class="eval-summary-title">評量表紀錄（今日前已上課程）</div>
        <table class="eval-summary-table">
          <thead>
            <tr><th>日期</th><th>科目</th><th>老師</th><th>狀態</th></tr>
          </thead>
          <tbody>
            <tr v-for="ev in session.evalRecords" :key="ev.id">
              <td>{{ ev.session_date }}</td>
              <td>{{ ev.subject }}</td>
              <td>{{ ev.teacher_name }}</td>
              <td>
                <span :class="['eval-status-tag', ev.status]">
                  {{ ev.status === 'approved' ? '已審核' : ev.status === 'pending' ? '待審核' : ev.status }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else-if="session.evalLoading" class="eval-loading">載入評量紀錄中…</div>
      <div v-else class="eval-empty">（尚無評量紀錄）</div>

      <div class="actions">
        <button v-if="session.editingException && session.editingExceptionIsExtra" class="danger" @click="$emit('cancel-makeup')">取消補課</button>
        <button v-if="session.editingException && !session.editingExceptionIsExtra" class="danger" @click="$emit('delete-exception')">刪除此調課</button>
        <button v-if="!session.editingException" class="danger" @click="$emit('delete-course')">刪除整門課</button>
        <div style="flex:1"></div>
        <button class="ghost" @click="$emit('close')">關閉</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import './calendarModalRwd.css';
import SearchableSelect from '../../SearchableSelect.vue';

defineProps({
  show: { type: Boolean, default: false },
  form: { type: Object, required: true },
  ratePer2h: { type: Number, default: 0 },
  session: {
    type: Object,
    default: () => ({
      actionDate: '',
      dayName: '',
      endTime: '',
      chargeDisplay: null,
      conflictWarning: null,
      isTeacher: false,
      featureSubstituteV2: false,
      canCancelSession: false,
      cancelState: { show: false, loading: false },
      editingException: false,
      editingExceptionIsExtra: false,
      evalRecords: [],
      evalLoading: false,
    }),
  },
  options: {
    type: Object,
    default: () => ({
      studentSelectOptions: [],
      subjectOptions: [],
      teachers: [],
      settlementDayOptions: [],
    }),
  },
});
defineEmits([
  'close', 'leave', 'reschedule', 'substitute', 'substitute-v2',
  'show-cancel-confirm', 'dismiss-cancel-confirm', 'confirm-cancel',
  'delete-exception', 'delete-course', 'cancel-makeup', 'teacher-change',
]);
</script>

<style scoped>
.session-edit-modal h3 {
  margin: 0 0 16px 0;
  font-size: 1.125rem;
  font-weight: 700;
  line-height: 1.35;
}
.conflict-box {
  background: #FFEBEE;
  border: 1px solid #EF9A9A;
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 16px;
  color: #C62828;
  font-size: 13px;
  line-height: 1.5;
}
.conflict-box-prominent {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  background: #ffcdd2;
  border: 2px solid #e57373;
  padding: 14px 16px;
  margin-bottom: 16px;
  border-radius: 8px;
  color: #b71c1c;
  font-size: 14px;
  line-height: 1.5;
}
.conflict-box-prominent strong { font-size: 14px; }
.conflict-box-prominent p { margin: 4px 0 0 0; font-size: 13px; line-height: 1.45; }
.conflict-icon { font-size: 20px; line-height: 1; flex-shrink: 0; }
.occurrence-hint { margin: 0 0 12px 0; padding: 8px 10px; background: var(--ds-canvas-soft, #f8f9fb); color: var(--ds-ink-mute, #555); border-radius: 6px; font-size: 13px; }
.session-info-card {
  background: #E8F5E9;
  border: 1px solid #A5D6A7;
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 12px;
}
.session-info-row {
  display: flex;
  align-items: baseline;
  gap: 10px;
  padding: 3px 0;
}
.session-info-key {
  font-size: 12px;
  font-weight: 600;
  color: #2E7D32;
  min-width: 60px;
  flex-shrink: 0;
}
.session-info-val {
  font-size: 14px;
  color: #1B5E20;
}
.session-charge-adjusted {
  margin-left: 6px;
  font-size: 12px;
  color: #C2410C;
  font-weight: 600;
}
.session-charge-standard {
  margin-left: 6px;
  font-size: 12px;
  color: #64748B;
}
.course-ref-section {
  background: #FAFAFA;
  border: 1px solid #E0E0E0;
  border-radius: 8px;
  padding: 0 12px 12px;
  margin-top: 12px;
}
.course-ref-hint {
  font-size: 12px;
  color: #9E9E9E;
  margin: 0 0 8px 0;
  line-height: 1.4;
}
.modal-form-sections { margin-top: 4px; }
.ref-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.form-section-label {
  font-size: 12px;
  font-weight: 600;
  line-height: 1.4;
  color: var(--text-light);
  letter-spacing: 0.04em;
  margin: 20px 0 10px 0;
  padding-bottom: 6px;
  border-bottom: 1px solid var(--border-color);
}
.form-section-label:first-child { margin-top: 0; }
.modal-form-sections .form-group { margin-bottom: 0; }
.modal-form-sections label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
  margin-bottom: 6px;
  color: var(--text-color);
}
.modal-form-sections select,
.modal-form-sections input {
  font-size: 14px;
  line-height: 1.4;
  padding: 8px 10px;
  width: 100%;
}
.computed-time {
  margin: 0;
  padding: 10px 12px;
  background: var(--bg-muted, #f5f5f5);
  border-radius: 8px;
  font-weight: 600;
  font-size: 15px;
  line-height: 1.4;
  color: var(--text);
}
.schedule-actions-box {
  margin-top: 16px;
  padding: 12px;
  background: #F5F5F5;
  border-radius: 8px;
  border: 1px solid #E0E0E0;
}
.schedule-actions-title {
  font-size: 12px;
  font-weight: 700;
  color: #616161;
  margin-bottom: 8px;
}
.schedule-actions-btns {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.action-btn {
  flex: 1;
  min-width: 0;
  padding: 8px 12px;
  border: 2px solid transparent;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  text-align: center;
  font-family: inherit;
  line-height: 1.4;
}
.action-btn.leave { background: #FFF3E0; color: #E65100; border-color: #FFCC80; }
.action-btn.leave:hover { background: #FFE0B2; }
.action-btn.reschedule { background: #F3E5F5; color: #6A1B9A; border-color: #CE93D8; }
.action-btn.reschedule:hover { background: #E1BEE7; }
.action-btn.substitute { background: #ecfeff; color: #0e7490; border-color: #67e8f9; }
.action-btn.substitute:hover { background: #cffafe; }
.action-btn.cancel-session { background: #fff1f2; color: #be123c; border-color: #fda4af; }
.action-btn.cancel-session:hover:not(:disabled) { background: #ffe4e6; }
.action-btn.cancel-session:disabled { opacity: 0.6; cursor: not-allowed; }
.cancel-session-confirm {
  margin-top: 10px;
  padding: 12px 14px;
  background: #fff1f2;
  border: 1.5px solid #fda4af;
  border-radius: 8px;
  font-size: 13px;
  color: #be123c;
}
.cancel-session-confirm p { margin: 0 0 10px; line-height: 1.5; }
.cancel-session-confirm small { color: #9f1239; }
.cancel-session-confirm-btns { display: flex; gap: 8px; }
.eval-summary-box {
  margin-top: 16px;
  padding: 12px;
  background: var(--ds-canvas-soft, #f8f9fb);
  border-radius: 8px;
  border: 1px solid var(--ds-hairline, #e5e7eb);
}
.eval-summary-title {
  font-size: 12px;
  font-weight: 700;
  color: var(--ds-ink, #1a1a1a);
  margin-bottom: 8px;
}
.eval-summary-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.eval-summary-table th, .eval-summary-table td {
  padding: 4px 8px;
  text-align: left;
  border-bottom: 1px solid var(--ds-hairline, #e5e7eb);
}
.eval-summary-table th { color: #555; font-weight: 600; font-size: 11px; }
.eval-status-tag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.eval-status-tag.approved { background: #e6f4ea; color: #2e7d32; }
.eval-status-tag.pending { background: #fff3e0; color: #e65100; }
.eval-loading, .eval-empty {
  padding: 8px;
  font-size: 13px;
  color: #888;
}
.eval-empty { color: #aaa; }
.actions {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--border-color, #e2e8f0);
}
@media (max-width: 768px) {
  .ref-grid { grid-template-columns: 1fr !important; }
  .session-edit-modal {
    width: 100% !important;
    max-width: 100vw !important;
    max-height: 100vh !important;
    max-height: 100dvh !important;
    border-radius: 0 !important;
  }
}
@media (max-width: 640px) {
  .session-edit-modal {
    border-radius: 16px 16px 0 0 !important;
  }
}
</style>
