<script setup>
/*
 * Adapted from Inspira UI BlurReveal (same blur + rise entrance)
 *   repo:    https://github.com/unovue/inspira-ui
 *   path:    app/components/inspira/ui/blur-reveal/BlurReveal.vue
 *   commit:  eec920b047589058b4b02ba64deb614869e7237a
 *   licence: MIT, Copyright (c) 2024-2026 rahulv.dev (full text in ./LICENSE-inspira-ui)
 * Changes: plain JS; motion-v replaced by one CSS transition (opacity, filter, transform); staggering is the caller's
 * `delay`; prefers-reduced-motion renders the final state with no transition; never blocks pointer or keyboard input.
 */
import { onMounted, ref } from 'vue';
import { prefersReducedMotion } from './reduced';

defineProps({
  as: { type: String, default: 'div' },
  delay: { type: Number, default: 0 }, // ms
});

const shown = ref(prefersReducedMotion());
onMounted(() => {
  if (!shown.value) requestAnimationFrame(() => requestAnimationFrame(() => { shown.value = true; }));
});
</script>

<template>
  <component :is="as" class="blur-fade" :class="{ 'blur-fade--in': shown }" :style="{ '--blur-fade-delay': `${delay}ms` }">
    <slot />
  </component>
</template>

<style scoped>
.blur-fade {
  opacity: 0;
  filter: blur(4px);
  transform: translateY(6px);
  transition: opacity 240ms ease-out var(--blur-fade-delay), filter 240ms ease-out var(--blur-fade-delay), transform 240ms ease-out var(--blur-fade-delay);
}
.blur-fade--in { opacity: 1; filter: none; transform: none; }
@media (prefers-reduced-motion: reduce) {
  .blur-fade { opacity: 1; filter: none; transform: none; transition: none; }
}
</style>
