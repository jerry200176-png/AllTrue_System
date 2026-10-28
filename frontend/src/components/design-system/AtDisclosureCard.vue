<script setup>
// Card that expands in place (#4488 疊卡展開). The toggle is a real <button> with
// aria-expanded / aria-controls (APG disclosure). `actions` holds other header controls
// (they sit beside the toggle, never inside it) and also names the toggle.
// The body stays mounted while collapsed, so inputs and scroll state survive.
import { ref, useId, useSlots, watch } from 'vue';

const props = defineProps({
  // Show stacked card edges under the collapsed card (the group holds several items).
  stacked: { type: Boolean, default: false },
});
const open = defineModel('open', { type: Boolean, default: false });

const id = `at-disclosure-${useId()}`;
const slots = useSlots();
const toggleEl = ref(null);
const bodyEl = ref(null);

// Collapsing makes the body inert; bring focus back to the toggle instead of losing it.
watch(open, (isOpen) => {
  if (!isOpen && bodyEl.value?.contains(document.activeElement)) toggleEl.value?.focus();
});
</script>

<template>
  <section :class="['at-disclosure', { 'is-open': open, 'at-disclosure--stacked': props.stacked }]">
    <div class="at-disclosure__head">
      <div v-if="slots.actions" :id="`${id}-actions`" class="at-disclosure__actions">
        <slot name="actions" />
      </div>
      <button
        :id="`${id}-toggle`"
        ref="toggleEl"
        type="button"
        class="at-disclosure__toggle"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="`${id}-body`"
        :aria-labelledby="slots.actions ? `${id}-actions ${id}-toggle` : undefined"
        @click="open = !open"
      >
        <span class="at-disclosure__summary"><slot name="summary" :open="open" /></span>
        <span class="material-symbols-outlined at-disclosure__chevron" aria-hidden="true">expand_more</span>
      </button>
    </div>
    <div :id="`${id}-body`" ref="bodyEl" class="at-disclosure__body" :inert="!open || undefined">
      <div class="at-disclosure__inner"><slot /></div>
    </div>
  </section>
</template>

<style scoped>
.at-disclosure {
  position: relative;
  transition: margin-bottom var(--ds-motion-base) var(--ds-ease-standard);
}

/* Two card edges peek out under a collapsed stack; they sit below the card, never over it. */
.at-disclosure--stacked::before,
.at-disclosure--stacked::after {
  content: '';
  position: absolute;
  top: calc(100% + 1px);
  border: 1px solid var(--ds-hairline);
  border-top: none;
  border-radius: 0 0 var(--ds-radius-lg) var(--ds-radius-lg);
  background: var(--ds-canvas);
  pointer-events: none;
  transition: opacity var(--ds-motion-base) var(--ds-ease-standard),
    transform var(--ds-motion-base) var(--ds-ease-standard);
}
/* ::after paints over ::before, so the nearer, wider edge is ::after. */
.at-disclosure--stacked::before { left: 16px; right: 16px; height: 10px; }
.at-disclosure--stacked::after { left: 8px; right: 8px; height: 5px; }
.at-disclosure--stacked:not(.is-open) { margin-bottom: 10px; }
.at-disclosure.is-open::before,
.at-disclosure.is-open::after {
  opacity: 0;
  transform: translateY(-6px);
}

.at-disclosure__head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 14px;
  border-radius: inherit;
}
.at-disclosure.is-open > .at-disclosure__head {
  border-bottom: 1px solid var(--ds-hairline);
  border-bottom-left-radius: 0;
  border-bottom-right-radius: 0;
}

.at-disclosure__actions {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
}

.at-disclosure__toggle {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
  border-radius: var(--ds-radius-md);
}
.at-disclosure__toggle:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

.at-disclosure__summary {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.at-disclosure__chevron {
  flex: 0 0 auto;
  font-size: var(--ds-font-size-xl);
  color: var(--ds-ink-mute);
  transition: transform var(--ds-motion-base) var(--ds-ease-standard);
}
.at-disclosure.is-open .at-disclosure__chevron { transform: rotate(180deg); }

/* 0fr → 1fr animates to the content's real height without measuring it. */
.at-disclosure__body {
  display: grid;
  grid-template-rows: 0fr;
  border-bottom-left-radius: inherit;
  border-bottom-right-radius: inherit;
  overflow: hidden;
  transition: grid-template-rows var(--ds-motion-base) var(--ds-ease-standard);
}
.at-disclosure.is-open > .at-disclosure__body { grid-template-rows: 1fr; }
.at-disclosure__inner {
  min-height: 0;
  overflow: hidden;
  visibility: hidden;
  transition: visibility 0s linear var(--ds-motion-base);
}
.at-disclosure.is-open .at-disclosure__inner {
  visibility: visible;
  transition-delay: 0s;
}

@media (prefers-reduced-motion: reduce) {
  .at-disclosure,
  .at-disclosure::before,
  .at-disclosure::after,
  .at-disclosure__chevron,
  .at-disclosure__body,
  .at-disclosure__inner {
    transition: none;
  }
}
</style>
