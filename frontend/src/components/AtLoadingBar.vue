<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { pending, SHOW_DELAY_MS } from '../lib/loadingBar';

const visible = ref(false);
const width = ref(0);
let showTimer = null;
let creepTimer = null;
let hideTimer = null;

const stopCreep = () => { clearInterval(creepTimer); creepTimer = null; };

const startCreep = () => {
  stopCreep();
  // creep toward 90% and never past it while work is still running
  creepTimer = setInterval(() => { width.value += (90 - width.value) * 0.1; }, 200);
};

watch(pending, (n) => {
  if (n > 0) {
    if (hideTimer) {
      // New work arrived while the bar was finishing: drop back and keep creeping (Codex P2).
      clearTimeout(hideTimer);
      hideTimer = null;
      width.value = 10;
      startCreep();
      return;
    }
    if (!visible.value && !showTimer) {
      showTimer = setTimeout(() => {
        showTimer = null;
        width.value = 10;
        visible.value = true;
        startCreep();
      }, SHOW_DELAY_MS);
    }
  } else {
    clearTimeout(showTimer);
    showTimer = null;
    if (!visible.value) return;
    stopCreep();
    width.value = 100;
    hideTimer = setTimeout(() => { hideTimer = null; visible.value = false; width.value = 0; }, 250);
  }
}, { immediate: true }); // work tracked before the bar mounted still shows it (Codex P2)

onBeforeUnmount(() => { clearTimeout(showTimer); clearTimeout(hideTimer); stopCreep(); });
</script>

<template>
  <div
    v-if="visible"
    class="at-loading-bar"
    role="progressbar"
    aria-label="載入中"
    aria-busy="true"
    :style="{ width: width + '%' }"
  />
</template>

<style scoped>
.at-loading-bar {
  position: fixed;
  top: 0;
  left: 0;
  height: 3px;
  background: var(--primary);
  z-index: 10000;
  pointer-events: none;
  transition: width 0.2s ease-out;
}
@media (prefers-reduced-motion: reduce) {
  .at-loading-bar { transition: none; }
}
</style>
