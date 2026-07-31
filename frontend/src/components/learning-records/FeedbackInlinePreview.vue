<template>
  <div v-if="open" class="lr-feedback-inline-preview" @click.stop>
    <div class="lr-feedback-inline-preview__content">{{ previewContent }}</div>
    <div class="lr-feedback-inline-preview__time">{{ formattedTime }}</div>
    <button
      type="button"
      class="ghost xs lr-feedback-inline-preview__reply-btn"
      @click="$emit('reply', record)"
    >回覆家長</button>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { formatParentFeedbackTime } from '../../lib/parentFeedbackFormat';

const props = defineProps({
  record: { type: Object, required: true },
  open: { type: Boolean, default: false },
});
defineEmits(['reply']);

const previewContent = computed(() => {
  const content = props.record?.parent_feedback?.content || '';
  return content.length > 100 ? `${content.slice(0, 100)}…` : content;
});

const formattedTime = computed(() => formatParentFeedbackTime(props.record?.parent_feedback?.updated_at));
</script>

<style scoped>
.lr-feedback-inline-preview { margin-top:8px; padding:8px 10px; background:var(--ds-warning-wash); border:1px solid var(--ds-warning-wash); border-radius:8px; font-size:12px; }
.lr-feedback-inline-preview__content { color:var(--ds-ink); line-height:1.5; white-space:pre-wrap; }
.lr-feedback-inline-preview__time { margin-top:4px; color:var(--ds-ink-mute); font-size:11px; }
.lr-feedback-inline-preview__reply-btn { margin-top: 6px; }
</style>
