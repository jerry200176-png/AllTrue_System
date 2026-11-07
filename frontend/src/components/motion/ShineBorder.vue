<script setup>
/*
 * Adapted from Inspira UI BorderBeam (animated gradient border on a masked ring)
 *   repo:    https://github.com/unovue/inspira-ui
 *   path:    app/components/inspira/ui/border-beam/BorderBeam.vue
 *   commit:  eec920b047589058b4b02ba64deb614869e7237a
 *   licence: MIT, Copyright (c) 2024-2026 rahulv.dev (full text in ./LICENSE-inspira-ui)
 * Changes: plain JS; the offset-path beam is a conic-gradient ring whose angle is a registered CSS property
 * (@property), so it is pure CSS with no JS or dependency; default colours are brand gold/amber tokens;
 * prefers-reduced-motion keeps a still ring and stops the spin. The ring never takes pointer events.
 */
defineProps({
  duration: { type: Number, default: 4 }, // seconds per turn
  width: { type: Number, default: 2 }, // ring thickness in px
  radius: { type: String, default: 'var(--ds-radius-pill)' }, // match the wrapped control's corner radius
  colors: { type: Array, default: () => ['var(--ds-brand-sun)', 'var(--ds-brand-amber)'] },
});
</script>

<template>
  <span class="shine-border" :style="{ '--sb-duration': `${duration}s`, '--sb-width': `${width}px`, '--ds-radius-shine': radius, '--sb-from': colors[0], '--sb-to': colors[1] || colors[0] }">
    <slot />
    <i class="shine-border__ring" aria-hidden="true" />
  </span>
</template>

<style scoped>
@property --sb-angle {
  syntax: '<angle>';
  inherits: false;
  initial-value: 0deg;
}
.shine-border { position: relative; display: block; border-radius: var(--ds-radius-shine); }
.shine-border__ring {
  position: absolute;
  inset: calc(var(--sb-width) * -1.5);
  padding: var(--sb-width);
  border-radius: var(--ds-radius-shine);
  pointer-events: none;
  background: conic-gradient(from var(--sb-angle), transparent 0 55%, var(--sb-from) 80%, var(--sb-to) 95%, transparent);
  -webkit-mask: linear-gradient(black 0 0) content-box, linear-gradient(black 0 0);
  -webkit-mask-composite: xor;
  mask: linear-gradient(black 0 0) content-box, linear-gradient(black 0 0);
  mask-composite: exclude;
  animation: shine-border-spin var(--sb-duration) linear infinite;
}
@keyframes shine-border-spin { to { --sb-angle: 360deg; } }
@media (prefers-reduced-motion: reduce) {
  .shine-border__ring { animation: none; }
}
</style>
