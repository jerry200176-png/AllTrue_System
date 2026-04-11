<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal" style="max-width: 460px;">
      <h3 class="modal-title">連假批次請假</h3>
      <p class="modal-desc">一次將該分校指定日期區間內所有課程標記請假，並自動順延補堂</p>
      <div class="form-group">
        <label>開始日期</label>
        <input type="date" v-model="form.start_date" />
      </div>
      <div class="form-group">
        <label>結束日期</label>
        <input type="date" v-model="form.end_date" />
      </div>
      <p v-if="form.start_date && form.end_date" class="hint" style="margin-top:6px;">
        將對「{{ form.start_date }}」至「{{ form.end_date }}」區間所有可請假堂次執行批次請假。
      </p>
      <div v-if="result" class="bulk-leave-result">
        <p style="font-weight:600;margin-bottom:4px;">{{ result.message }}</p>
        <p v-if="result.skipped && result.skipped.length">
          略過原因：
          <span v-for="(s, i) in result.skipped" :key="i" style="display:block;font-size:12px;color:#6b7280;">
            課程 #{{ s.course_id }} {{ s.session_date }}：{{ s.reason }}
          </span>
        </p>
      </div>
      <div class="actions">
        <button class="ghost" @click="$emit('close')">關閉</button>
        <button
          class="primary"
          @click="$emit('submit')"
          :disabled="!form.start_date || !form.end_date || submitting"
        >{{ submitting ? '處理中…' : '確認批次請假' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({ show: Boolean, form: Object, result: Object, submitting: Boolean });
defineEmits(['close', 'submit']);
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
.bulk-leave-result {
  margin-top: 12px; padding: 10px; border-radius: 8px;
  background: #f0fdf4; border: 1px solid #bbf7d0;
}
</style>
