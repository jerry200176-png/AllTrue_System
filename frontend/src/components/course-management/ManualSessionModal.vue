<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal manual-session-modal" role="dialog" aria-modal="true">
      <h3 class="modal-title">{{ isMonthly ? '新增月結堂次' : '新增下一堂' }}</h3>
      <p class="modal-desc">{{ isMonthly ? '補入區間內的單堂課；月結依實際上課計費，不會扣除購買堂數。' : '逐堂手動排課：每次只新增一堂，課程時長沿用課程設定。' }}</p>

      <div class="manual-session-grid">
        <label class="form-group">
          <span>上課日期</span>
          <input v-model="form.session_date" type="date" :min="today" @change="$emit('check')" />
        </label>
        <label class="form-group">
          <span>開始時間</span>
          <input v-model="form.start_time" type="time" step="1800" @change="$emit('check')" />
        </label>
      </div>

      <div v-if="checking" class="manual-session-state">檢查額度與衝堂中…</div>
      <div v-else-if="result" class="manual-session-result" :class="result.can_add ? 'ok' : 'error'">
        <strong>{{ result.can_add ? '可以預約' : (result.message || '無法預約') }}</strong>
        <span>預計時段 {{ result.start_time }}–{{ result.end_time }}（{{ result.duration_minutes }} 分鐘）；{{ isMonthly ? '月結會依實際上課計費' : `可預約額度 ${result.available_sessions ?? 0} 堂` }}</span>
        <span v-if="result.conflict_detail">衝堂詳情：{{ result.conflict_detail.message || result.conflict_detail.type || '已有其他排課' }}</span>
      </div>

      <button
        v-if="isMonthly && ['monthly_date_range_required', 'after_course_end'].includes(result?.error_code)"
        type="button"
        class="ghost small manual-session-edit-course"
        @click="$emit('edit-course')"
      >先設定月結結束日</button>

      <div class="actions">
        <button type="button" class="ghost" :disabled="submitting" @click="$emit('close')">取消</button>
        <button type="button" class="primary" :disabled="submitting || checking || !result?.can_add" @click="$emit('submit')">
          {{ submitting ? '建立中…' : '建立這一堂' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  show: Boolean,
  form: { type: Object, required: true },
  result: { type: Object, default: null },
  checking: Boolean,
  submitting: Boolean,
  isMonthly: Boolean,
  today: { type: String, required: true },
});
defineEmits(['close', 'check', 'submit', 'edit-course']);
</script>

<style scoped>
.manual-session-modal { max-width: 460px; }
.manual-session-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.manual-session-grid .form-group { display: flex; flex-direction: column; gap: 6px; }
.manual-session-grid span { font-size: .86rem; font-weight: 600; color: var(--ds-text-secondary); }
.manual-session-state, .manual-session-result { margin-top: 14px; padding: 12px; border-radius: 10px; display: grid; gap: 4px; font-size: .88rem; }
.manual-session-result.ok { background: var(--ds-success-wash); color: var(--ds-success); }
.manual-session-result.error { background: var(--ds-danger-wash); color: var(--ds-danger); }
.manual-session-edit-course { margin-top: 10px; width: 100%; }
@media (max-width: 560px) { .manual-session-grid { grid-template-columns: 1fr; } }
</style>
