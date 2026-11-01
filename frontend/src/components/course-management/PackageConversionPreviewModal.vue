<template>
  <div v-if="show" class="modal-overlay" @click.self="!loading && emit('close')">
    <div
      class="modal package-conversion-preview-modal"
      data-testid="package-conversion-preview"
      role="dialog"
      aria-modal="true"
      aria-labelledby="package-conversion-preview-title"
      tabindex="-1"
      @keydown.esc="emit('close')"
    >
      <h3 id="package-conversion-preview-title" class="modal-title">轉成多科共用方案</h3>
      <p class="package-conversion-preview__lead">
        先檢查這門單科堂數制是否有上課、點名、學習或帳務歷史；這個畫面只讀取資料，不會修改合約。
      </p>
      <AtInlineAlert v-if="loading" tone="info" title="正在進行唯讀預檢">
        <p>正在確認合約、帳務與扣堂紀錄…</p>
      </AtInlineAlert>
      <AtInlineAlert v-else-if="error" tone="warning" title="預檢失敗">
        <p>{{ error }}</p>
      </AtInlineAlert>
      <template v-else-if="preview">
        <div class="package-conversion-preview__source">
          <strong>{{ course?.subject_name || course?.subject || '目前課程' }}</strong>
          <span v-if="course?.student_name">／{{ course.student_name }}</span>
          <span> · {{ preview.student_class?.remaining_sessions ?? 0 }} 堂剩餘</span>
        </div>
        <AtInlineAlert v-if="preview.can_convert" tone="success" title="通過安全預檢">
          <p>目前沒有發現已使用或帳務歷史。真正轉換仍須由受控流程執行，原課程識別與稽核紀錄會保留。</p>
        </AtInlineAlert>
        <AtInlineAlert v-else tone="warning" title="不建議直接轉換">
          <p>為避免堂數、點名或帳務歷史被改寫，請保留原合約並建立新的多科方案。</p>
          <ul class="package-conversion-preview__reasons">
            <li v-for="reason in preview.blocking_reasons" :key="reason.code">{{ reason.message }}</li>
          </ul>
        </AtInlineAlert>
        <p class="package-conversion-preview__next-step">{{ preview.next_step }}</p>
      </template>
      <div class="actions">
        <button class="ghost" :disabled="loading" @click="emit('close')">關閉</button>
        <button
          v-if="error && !loading"
          class="ghost"
          type="button"
          @click="emit('retry')"
        >重新檢查</button>
        <button
          v-if="preview && !loading"
          class="primary"
          type="button"
          @click="preview.can_convert ? emit('convert') : emit('new-package')"
        >{{ preview.can_convert ? '繼續轉成多科共用方案' : '前往建立多科方案' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import AtInlineAlert from '../design-system/AtInlineAlert.vue';

defineProps({
  show: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  preview: { type: Object, default: null },
  course: { type: Object, default: null },
});
const emit = defineEmits(['close', 'retry', 'convert', 'new-package']);
</script>

<style scoped>
.modal-title {
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 8px;
}
[data-theme="dark"] .modal-title {
  color: var(--ds-ink);
}
.package-conversion-preview-modal {
  width: 100%;
  max-width: 560px;
}
.package-conversion-preview__lead,
.package-conversion-preview__next-step {
  color: var(--text-light);
  font-size: 13px;
  line-height: 1.55;
}
.package-conversion-preview__source {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  margin: 0 0 12px;
  color: var(--text);
  font-size: 14px;
}
.package-conversion-preview__reasons {
  margin: 8px 0 0;
  padding-left: 20px;
}
.package-conversion-preview__reasons li + li { margin-top: 4px; }
</style>
