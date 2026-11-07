<script setup>
/*
 * iOS-style navigation bar: sticky, translucent (thin material) once the large title has collapsed into `title`.
 *   concept: Apple HIG Navigation bars + Materials; scroll-edge behaviour of iOS 26 Liquid Glass bars.
 * Material comes from the shared .ds-glass utility (ui.css). The glass layer fades in (opacity only) so no blur is animated;
 * reduced motion = instant; reduced transparency / no backdrop-filter = solid (handled by .ds-glass).
 */
defineProps({
  collapsed: { type: Boolean, default: false },
  title: { type: String, default: '' },
});
</script>

<template>
  <header class="glass-navbar" :class="{ 'is-collapsed': collapsed }">
    <i class="glass-navbar__bg ds-glass ds-glass--regular" aria-hidden="true" />
    <div class="glass-navbar__side"><slot name="leading" /></div>
    <p class="glass-navbar__title" aria-hidden="true">{{ title }}</p>
    <div class="glass-navbar__side glass-navbar__side--end"><slot name="trailing" /></div>
  </header>
</template>

<style scoped>
.glass-navbar {
  position: sticky;
  top: 0;
  z-index: var(--ds-z-sticky, 10);
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 8px;
  min-height: 48px;
  padding: env(safe-area-inset-top) 12px 0;
}
.glass-navbar__bg {
  position: absolute;
  inset: 0;
  z-index: -1;
  box-shadow: 0 0.5px 0 var(--ds-glass-hairline);
  opacity: 0;
  pointer-events: none;
}
.glass-navbar.is-collapsed .glass-navbar__bg { opacity: 1; }
.glass-navbar__side { display: flex; align-items: center; min-width: 0; }
.glass-navbar__side--end { justify-content: flex-end; }
.glass-navbar__title {
  margin: 0;
  max-width: 60vw;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 17px;
  font-weight: 600;
  opacity: 0;
  transform: translateY(6px);
}
.glass-navbar.is-collapsed .glass-navbar__title { opacity: 1; transform: none; }
.glass-navbar__bg, .glass-navbar__title { transition: opacity 200ms var(--ds-ease-ios), transform 240ms var(--ds-ease-ios); }
@media (prefers-reduced-motion: reduce) {
  .glass-navbar__bg, .glass-navbar__title { transition: none; }
}
</style>
