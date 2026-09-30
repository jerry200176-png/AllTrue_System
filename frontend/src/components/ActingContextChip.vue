<template>
  <div ref="root" class="acting-chip-wrap" @keydown.esc="open = false">
    <span class="acting-chip-live" role="status" aria-live="polite">{{ label }}</span>
    <button
      type="button"
      class="acting-chip"
      :class="`acting-chip--${context}`"
      data-guide="app-acting-context-chip"
      :aria-expanded="String(open)"
      aria-haspopup="menu"
      @click="open = !open"
    >
      <span aria-hidden="true">{{ label }}</span>
      <span class="material-symbols-outlined" aria-hidden="true">swap_horiz</span>
    </button>
    <div v-if="open" class="acting-chip-menu" role="menu" aria-label="切換工作身分">
      <button
        v-for="opt in options"
        :key="opt.value"
        type="button"
        class="acting-chip-option"
        role="menuitemradio"
        :aria-checked="String(opt.value === context)"
        @click="pick(opt.value)"
      >{{ opt.label }}</button>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { formatContextChipLabel } from '../lib/staffActingContext.js';

const props = defineProps({
  context: { type: String, required: true },
  campusMap: { type: Object, default: null },
  campusNames: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['switch']);
const open = ref(false);
const root = ref(null);
const onOutside = (e) => { if (root.value && !root.value.contains(e.target)) open.value = false; };
onMounted(() => document.addEventListener('click', onOutside));
onBeforeUnmount(() => document.removeEventListener('click', onOutside));
const options = [{ value: 'director', label: '主任' }, { value: 'teacher', label: '老師' }];
const label = computed(() => formatContextChipLabel(props.context, props.campusMap?.[props.context], props.campusNames));
function pick(value) {
  open.value = false;
  if (value !== props.context) emit('switch', value);
}
</script>

<style scoped>
.acting-chip-wrap { position: relative; }
.acting-chip {
  display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
  border: 1px solid var(--ds-hairline); border-radius: 999px;
  font-size: 0.85rem; font-weight: 600; cursor: pointer;
}
.acting-chip--director { background: var(--ds-ink); color: var(--ds-canvas); }
.acting-chip--teacher { background: var(--ds-primary-wash); color: var(--ds-ink); border-color: var(--ds-primary); }
.acting-chip-live { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.acting-chip .material-symbols-outlined { font-size: 1rem; }
.acting-chip-menu {
  position: absolute; right: 0; top: calc(100% + 4px); z-index: 20; display: flex; flex-direction: column;
  background: var(--ds-canvas); border: 1px solid var(--ds-hairline); border-radius: 8px; padding: 4px;
}
.acting-chip-option {
  padding: 6px 14px; border: 0; background: transparent; color: var(--ds-ink); text-align: left; cursor: pointer;
}
.acting-chip-option[aria-checked='true'] { font-weight: 700; background: var(--ds-canvas-soft); }
</style>
