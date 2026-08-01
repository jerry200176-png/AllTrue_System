<template>
  <article class="learning-record-preview" aria-label="評量內容預覽">
    <div class="learning-record-preview__header">
      <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
      <strong>內容預覽</strong>
      <span class="learning-record-preview__hint">只讀摘要，不會修改評量</span>
    </div>

    <div v-if="preview.hasContent" class="learning-record-preview__body">
      <dl v-if="preview.signals.length" class="learning-record-preview__signals">
        <div v-for="signal in preview.signals" :key="signal.label">
          <dt>{{ signal.label }}</dt>
          <dd>{{ signal.value }}</dd>
        </div>
      </dl>

      <dl v-if="preview.details.length" class="learning-record-preview__details">
        <div v-for="detail in preview.details" :key="detail.label">
          <dt>{{ detail.label }}</dt>
          <dd>{{ detail.value }}</dd>
        </div>
      </dl>
    </div>

    <p v-else class="learning-record-preview__empty">尚未填寫評量內容</p>
  </article>
</template>

<script setup>
import { computed } from 'vue';
import { buildLearningRecordPreview } from '../../lib/learningRecordPreview';

const props = defineProps({
  record: {
    type: Object,
    required: true,
  },
});

const preview = computed(() => buildLearningRecordPreview(props.record));
</script>

<style scoped>
.learning-record-preview {
  margin-top: 12px;
  border: 1px solid var(--ds-hairline);
  border-radius: 10px;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
  overflow: hidden;
}

.learning-record-preview__header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 10px;
  border-bottom: 1px solid var(--ds-hairline);
  color: var(--ds-ink-secondary);
  font-size: 12px;
}

.learning-record-preview__header .material-symbols-outlined {
  font-size: 16px;
  color: var(--ds-primary);
}

.learning-record-preview__hint {
  margin-left: auto;
  color: var(--ds-ink-mute);
  font-size: 11px;
  font-weight: 400;
}

.learning-record-preview__body {
  padding: 10px;
}

.learning-record-preview dl {
  margin: 0;
}

.learning-record-preview__signals {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 10px !important;
}

.learning-record-preview__signals > div {
  min-width: 0;
  padding: 7px 8px;
  border: 1px solid var(--ds-hairline);
  border-radius: 8px;
  background: var(--ds-canvas);
}

.learning-record-preview dt {
  color: var(--ds-ink-mute);
  font-size: 11px;
  font-weight: 600;
}

.learning-record-preview dd {
  margin: 3px 0 0;
  color: var(--ds-ink);
  font-size: 13px;
  line-height: 1.55;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.learning-record-preview__signals dd {
  font-weight: 650;
}

.learning-record-preview__details {
  display: grid;
  gap: 7px;
}

.learning-record-preview__details > div {
  display: grid;
  grid-template-columns: 110px minmax(0, 1fr);
  gap: 10px;
  align-items: start;
}

.learning-record-preview__details dt {
  padding-top: 2px;
}

.learning-record-preview__empty {
  margin: 0;
  padding: 12px 10px;
  color: var(--ds-ink-mute);
  font-size: 12px;
}

@media (max-width: 640px) {
  .learning-record-preview__hint {
    display: none;
  }

  .learning-record-preview__signals {
    gap: 6px;
  }

  .learning-record-preview__signals > div {
    padding: 6px;
  }

  .learning-record-preview__details > div {
    grid-template-columns: 1fr;
    gap: 1px;
  }
}
</style>
