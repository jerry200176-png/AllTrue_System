<script setup>
// Copy-to-clipboard button: icon turns into a check and the label into 「已複製」
// for ~1.5 s, announced politely to screen readers (#4697 item 2).
import { onBeforeUnmount, ref } from 'vue';
import AtButton from './AtButton.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  text: { type: String, default: '' },
  label: { type: String, default: '複製' },
  copiedLabel: { type: String, default: '已複製' },
  variant: { type: String, default: 'secondary' },
  size: { type: String, default: 'sm' },
  resetMs: { type: Number, default: 1500 },
});
const emit = defineEmits(['copied', 'error']);

const copied = ref(false);
let timer = null;

async function copy() {
  try {
    await navigator.clipboard.writeText(props.text);
  } catch (e) {
    emit('error', e);
    return;
  }
  copied.value = true;
  emit('copied');
  clearTimeout(timer);
  timer = setTimeout(() => { copied.value = false; }, props.resetMs);
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
  <span class="at-copy">
    <AtButton
      shape="rect"
      :variant="variant"
      :size="size"
      :icon="copied ? 'check' : 'content_copy'"
      :class="{ 'at-copy__btn--done': copied }"
      v-bind="$attrs"
      @click="copy"
    >{{ copied ? copiedLabel : label }}</AtButton>
    <span class="at-copy__live" aria-live="polite">{{ copied ? copiedLabel : '' }}</span>
  </span>
</template>

<style scoped>
.at-copy { display: inline-flex; position: relative; }
.at-copy__live {
  position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0;
  overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
}
.at-copy :deep(.at-btn__icon) { transition: transform var(--ds-motion-base) var(--ds-ease-standard); }
.at-copy__btn--done :deep(.at-btn__icon) {
  color: var(--ds-success, currentColor);
  animation: at-copy-pop var(--ds-motion-slow) var(--ds-ease-standard);
}
@keyframes at-copy-pop { from { transform: scale(0.6); } to { transform: scale(1); } }
@media (prefers-reduced-motion: reduce) {
  .at-copy :deep(.at-btn__icon) { transition: none; }
  .at-copy__btn--done :deep(.at-btn__icon) { animation: none; }
}
</style>
