<script setup>
// Compact ⓘ disclosure for a page's "how this page works" / SOP text.
// Lives in AtPageHeader's `help` slot; the panel floats so it never pushes data down.
defineProps({
  label: { type: String, default: '這一頁怎麼用' },
});
</script>

<template>
  <details class="at-help" data-testid="at-help">
    <summary class="at-help__trigger" :aria-label="label" :title="label">
      <span class="material-symbols-outlined" aria-hidden="true">info</span>
    </summary>
    <div class="at-help__panel" role="note">
      <slot />
    </div>
  </details>
</template>

<style scoped>
/* Panel anchors to AtPageHeader's title row (position: relative), not the icon. */
.at-help {
  flex: 0 0 auto;
}

.at-help__trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: var(--ds-radius-pill, 999px);
  color: var(--ds-text-tertiary);
  cursor: pointer;
  list-style: none;
}

.at-help__trigger::-webkit-details-marker {
  display: none;
}

.at-help__trigger:hover,
.at-help[open] > .at-help__trigger {
  color: var(--ds-text-primary);
  background: var(--ds-surface-2, var(--ds-canvas-soft));
}

.at-help__trigger:focus-visible {
  outline: 3px solid var(--ds-focus-ring);
  outline-offset: 1px;
}

.at-help__trigger .material-symbols-outlined {
  font-size: 20px;
}

.at-help__panel {
  position: absolute;
  z-index: 30;
  top: calc(100% + var(--ds-space-2));
  left: 0;
  width: min(480px, calc(100vw - 32px));
  max-height: 60vh;
  overflow: auto;
  padding: var(--ds-space-3) var(--ds-space-4);
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg, 12px);
  background: var(--ds-canvas);
  box-shadow: var(--ds-shadow-2, var(--ds-shadow-1));
  font-size: var(--ds-font-size-base);
  font-weight: var(--ds-font-weight-regular, 400);
  line-height: var(--ds-line-base);
  color: var(--ds-text-secondary);
}

.at-help__panel :slotted(ol),
.at-help__panel :slotted(ul) {
  margin: var(--ds-space-2) 0 0;
  padding-left: 1.25em;
}

.at-help__panel :slotted(p) {
  margin: var(--ds-space-1) 0 0;
}
</style>
