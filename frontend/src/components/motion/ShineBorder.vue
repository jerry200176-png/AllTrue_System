<script setup>
/*
 * Adapted from Inspira UI BorderBeam (animated gradient border on a masked ring)
 *   repo:    https://github.com/unovue/inspira-ui
 *   path:    app/components/inspira/ui/border-beam/BorderBeam.vue
 *   commit:  eec920b047589058b4b02ba64deb614869e7237a
 *   licence: MIT, Copyright (c) 2024-2026 rahulv.dev (full text in ./LICENSE-inspira-ui)
 * Changes: plain JS; the offset-path beam is a conic-gradient ring whose angle is a registered CSS property
 * (@property), so it is pure CSS with no JS or dependency; default colours are ink tokens;
 * one quiet turn at low opacity on first paint (no loop); prefers-reduced-motion hides the ring. The ring never takes pointer events.
 */
defineProps({
  duration: { type: Number, default: 2.4 }, // seconds; the beam runs one turn on first paint, then fades out
  width: { type: Number, default: 1.5 }, // ring thickness in px
  radius: { type: String, default: 'var(--ds-radius-pill)' }, // match the wrapped control's corner radius
  colors: { type: Array, default: () => ['var(--ds-brand-ink)', 'var(--ds-brand-ink-2)'] },
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
.shine-border {
  position: relative;
  display: block;
  border-radius: var(--ds-radius-shine);
  /* concentric: ring sits --sb-width * 1.5 outside the control, so its radius grows by the same amount */
  --ds-radius-ring: calc(var(--ds-radius-shine) + var(--sb-width) * 1.5);
}
.shine-border__ring {
  position: absolute;
  inset: calc(var(--sb-width) * -1.5);
  padding: var(--sb-width);
  border-radius: var(--ds-radius-ring);
  pointer-events: none;
  background: conic-gradient(from var(--sb-angle), transparent 0 55%, var(--sb-from) 80%, var(--sb-to) 95%, transparent);
  -webkit-mask: linear-gradient(black 0 0) content-box, linear-gradient(black 0 0);
  -webkit-mask-composite: xor;
  mask: linear-gradient(black 0 0) content-box, linear-gradient(black 0 0);
  mask-composite: exclude;
  opacity: 0;
  animation: shine-border-spin var(--sb-duration) ease-out 0.3s 1 both, shine-border-fade var(--sb-duration) ease-out 0.3s 1 both;
}
@keyframes shine-border-spin { to { --sb-angle: 360deg; } }
@keyframes shine-border-fade { 0% { opacity: 0; } 25% { opacity: 0.55; } 100% { opacity: 0; } }
@media (prefers-reduced-motion: reduce) {
  .shine-border__ring { display: none; } /* reduced motion = no ring at all */
}
</style>
