<script setup>
/*
 * Adapted from Inspira UI AuroraBackground (drifting soft bands over a gradient)
 *   repo:    https://github.com/unovue/inspira-ui
 *   path:    app/components/inspira/ui/aurora-background/AuroraBackground.vue
 *   commit:  eec920b047589058b4b02ba64deb614869e7237a
 *   licence: MIT, Copyright (c) 2024-2026 rahulv.dev (full text in ./LICENSE-inspira-ui)
 * Changes: plain JS; no slot (the parent owns the layout): this is a decorative layer. Upstream's blue palette,
 * blur(10px), invert and mix-blend-difference are dropped (heavy on old phones); the base is the app's orange
 * gradient and the drift is one pale band layer moved with `transform: translateX` only (compositor, no repaint),
 * seamless 25 s loop, low amplitude; prefers-reduced-motion stops it. Place it first inside a `position: relative`
 * element with `overflow: hidden`.
 */
</script>

<template>
  <div class="aurora" aria-hidden="true"><div class="aurora__bands" /></div>
</template>

<style scoped>
.aurora {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
  background: linear-gradient(-45deg, var(--ds-brand-amber-light), var(--ds-primary-soft), var(--ds-brand-orange), var(--ds-primary));
}
.aurora__bands {
  position: absolute;
  inset: 0 auto 0 0;
  width: 200%;
  background: linear-gradient(90deg, transparent 0%, color-mix(in srgb, var(--ds-canvas) 24%, transparent) 30%, transparent 60%, transparent 100%) 0 0 / 25% 100% repeat-x;
  animation: aurora-drift 25s linear infinite;
  will-change: transform;
}
@keyframes aurora-drift { to { transform: translateX(-50%); } }
@media (prefers-reduced-motion: reduce) {
  .aurora__bands { animation: none; will-change: auto; }
}
</style>
