<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';

defineProps({ label: { type: String, default: '更多操作' } });

const root = ref(null);
const close = () => { if (root.value) root.value.open = false; };
const onDocClick = (e) => { if (root.value?.open && !root.value.contains(e.target)) close(); };
onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));
</script>

<template>
  <details ref="root" class="at-row-menu" data-testid="at-row-menu" @keydown.esc="close">
    <summary :aria-label="label" :title="label">
      <span class="material-symbols-outlined" aria-hidden="true">more_horiz</span>
    </summary>
    <div class="at-row-menu__panel" role="group" :aria-label="label" @click="close">
      <slot />
    </div>
  </details>
</template>

<style scoped>
.at-row-menu { position: relative; display: inline-block; }
.at-row-menu > summary {
  list-style: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--ds-control-height-md);
  height: var(--ds-control-height-md);
  border-radius: var(--ds-radius-md);
  color: var(--ds-ink-mute);
  cursor: pointer;
}
.at-row-menu > summary::-webkit-details-marker { display: none; }
.at-row-menu > summary:hover, .at-row-menu[open] > summary { background: var(--ds-hairline); color: var(--ds-ink); }
.at-row-menu > summary:focus-visible { outline: 3px solid var(--ds-primary-wash); outline-offset: 2px; }
.at-row-menu__panel {
  position: absolute;
  right: 0;
  z-index: 20;
  min-width: 140px;
  margin-top: 4px;
  padding: 4px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-md);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}
.at-row-menu__panel :deep(button) {
  width: 100%;
  justify-content: flex-start;
  text-align: left;
  white-space: nowrap;
}
</style>
