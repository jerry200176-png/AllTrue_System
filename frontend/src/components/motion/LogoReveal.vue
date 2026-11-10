<script setup>
/*
 * Logo entrance: a gold ring draws itself around the logo, then the logo (ring and calligraphy) fades in and the
 * drawn ring fades away. CSS only (stroke-dashoffset + opacity). Wraps the existing logo element via the slot.
 *   concept: Vue Bits / Inspira "draw-in" reveals (SVG path dashoffset); no code copied.
 * prefers-reduced-motion: the logo is simply shown, the ring is hidden. Never blocks input.
 */
</script>

<template>
  <span class="logo-reveal">
    <span class="logo-reveal__logo"><slot /></span>
    <svg class="logo-reveal__ring" viewBox="0 0 100 100" aria-hidden="true">
      <circle cx="50" cy="50" r="47" pathLength="1" />
    </svg>
  </span>
</template>

<style scoped>
.logo-reveal { position: relative; display: inline-flex; }
.logo-reveal__logo { display: inline-flex; animation: logo-reveal-in 600ms ease-out 650ms both; }
.logo-reveal__ring { position: absolute; inset: -4px; width: calc(100% + 8px); height: calc(100% + 8px); pointer-events: none; transform: rotate(-90deg); }
.logo-reveal__ring circle {
  fill: none;
  stroke: var(--ds-brand-sun);
  stroke-width: 2.5;
  stroke-linecap: round;
  stroke-dasharray: 1;
  stroke-dashoffset: 1;
  animation: logo-ring-draw 700ms cubic-bezier(0.4, 0, 0.2, 1) 100ms forwards, logo-ring-out 400ms ease-out 1100ms forwards;
}
@keyframes logo-reveal-in { from { opacity: 0; transform: scale(0.92); } to { opacity: 1; transform: none; } }
@keyframes logo-ring-draw { to { stroke-dashoffset: 0; } }
@keyframes logo-ring-out { to { opacity: 0; } }
@media (prefers-reduced-motion: reduce) {
  .logo-reveal__logo { animation: none; }
  .logo-reveal__ring { display: none; }
}
</style>
