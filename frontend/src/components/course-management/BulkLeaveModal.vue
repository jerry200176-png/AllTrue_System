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
      <div v-if="form.start_date && form.end_date" class="impact-preview" role="note" aria-label="批次請假影響預覽">
        <div class="impact-preview__head">
          <span class="impact-preview__icon" aria-hidden="true">!</span>
          <div>
            <strong>{{ previewTitle }}</strong>
            <p>{{ previewSummary }}</p>
          </div>
        </div>
        <ul class="impact-preview__list">
          <li v-for="item in previewItems" :key="item">{{ item }}</li>
        </ul>
        <label class="impact-confirm">
          <input v-model="impactAccepted" type="checkbox" />
          <span>我已確認此批次操作會影響多堂課，確定要送出</span>
        </label>
      </div>
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
          :disabled="!form.start_date || !form.end_date || !impactAccepted || submitting"
        >{{ submitting ? '處理中…' : '確認批次請假' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
  show: Boolean,
  form: Object,
  result: Object,
  submitting: Boolean,
  impactPreview: Object,
});
defineEmits(['close', 'submit']);

const impactAccepted = ref(false);
const previewTitle = computed(() => props.impactPreview?.title || '批次請假影響預覽');
const previewSummary = computed(() => props.impactPreview?.summary || '系統會掃描區間內可請假的堂次，逐筆執行請假與順延。');
const previewItems = computed(() => {
  const items = props.impactPreview?.items;
  if (Array.isArray(items) && items.length) return items;
  return ['會影響區間內多位學生與多堂課', '已有核准評量或不可處理的堂次會被略過', '完成後請查看略過清單並個別處理'];
});

watch(
  () => [props.show, props.form?.start_date, props.form?.end_date],
  () => { impactAccepted.value = false; },
);
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
.bulk-leave-result {
  margin-top: 12px; padding: 10px; border-radius: 8px;
  background: #f0fdf4; border: 1px solid #bbf7d0;
}
.impact-preview {
  margin: 12px 0;
  padding: 12px 14px;
  border-radius: 12px;
  border: 1px solid #fdba74;
  background: #fff7ed;
  color: #9a3412;
}
.impact-preview__head {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}
.impact-preview__icon {
  width: 22px;
  height: 22px;
  display: inline-grid;
  place-items: center;
  flex: 0 0 auto;
  border-radius: 999px;
  background: #ea580c;
  color: #fff;
  font-weight: 800;
  font-size: 13px;
}
.impact-preview__head strong {
  display: block;
  font-size: 14px;
  margin-bottom: 2px;
}
.impact-preview__head p {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
}
.impact-preview__list {
  margin: 10px 0;
  padding-left: 20px;
  font-size: 13px;
  line-height: 1.6;
}
.impact-confirm {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  font-size: 13px;
  font-weight: 600;
}
.impact-confirm input {
  margin-top: 2px;
}
</style>
