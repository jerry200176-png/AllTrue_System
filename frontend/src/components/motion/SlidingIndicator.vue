<script setup>
/*
 * Sliding active-tab marker (Apple segmented-control feel).
 *   concept: Inspira UI AnimatedTabs (https://github.com/unovue/inspira-ui,
 *            app/components/inspira/ui/animated-tabs/AnimatedTabs.vue, commit eec920b047589058b4b02ba64deb614869e7237a, MIT,
 *            full text in ./LICENSE-inspira-ui). Upstream uses motion-v shared-layout animation; this is a re-implementation:
 *            measure the active tab once, then move one bar with a CSS transform transition.
 * Changes: plain JS, no dependency; ResizeObserver keeps it aligned; first paint has no slide; prefers-reduced-motion
 * moves it without a transition. Pointer events pass through.
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
  as: { type: String, default: 'div' },
  active: { type: [String, Number], default: '' }, // any value that changes when the active tab changes
  selector: { type: String, default: '[role="tab"][aria-selected="true"]' },
});

const root = ref(null);
const pos = ref({ x: 0, w: 0, ready: false });
let ro = null;

function measure() {
  const el = root.value?.querySelector(props.selector);
  if (!el) { pos.value = { x: 0, w: 0, ready: false }; return; }
  pos.value = { x: el.offsetLeft, w: el.offsetWidth, ready: true };
}
watch(() => props.active, () => nextTick(measure));
onMounted(() => {
  measure();
  if (typeof ResizeObserver !== 'undefined') { ro = new ResizeObserver(measure); ro.observe(root.value); }
});
onBeforeUnmount(() => ro?.disconnect());
</script>

<template>
  <component :is="as" ref="root" class="sliding-root">
    <slot />
    <i class="sliding-indicator" :class="{ 'is-ready': pos.ready }" :style="{ '--si-x': `${pos.x}px`, '--si-w': `${pos.w}px` }" aria-hidden="true" />
  </component>
</template>

<style scoped>
/* Zero specificity, so a caller's own position wins. */
:where(.sliding-root) { position: relative; }
.sliding-indicator {
  position: absolute;
  top: 0;
  left: 0;
  width: var(--si-w);
  height: 3px;
  border-radius: var(--ds-radius-pill);
  background: var(--ds-brand-ink);
  transform: translateX(var(--si-x));
  pointer-events: none;
  opacity: 0;
}
.sliding-indicator.is-ready { opacity: 1; transition: transform 240ms cubic-bezier(0.2, 0.8, 0.2, 1), width 240ms cubic-bezier(0.2, 0.8, 0.2, 1); }
@media (prefers-reduced-motion: reduce) {
  .sliding-indicator.is-ready { transition: none; }
}
</style>
