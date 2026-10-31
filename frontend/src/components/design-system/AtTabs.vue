<script setup>
// Tab list with ONE indicator that slides to the selected tab (#4697 item 1).
// tabs: [{ value, label, icon?, badge?, disabled?, id?, controls?, testid? }]
// Arrow/Home/End keys move focus and selection; roving tabindex; aria-selected/aria-controls.
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  tabs: { type: Array, required: true },
  label: { type: String, default: '' },
  idPrefix: { type: String, default: '' },
  variant: { type: String, default: 'underline' }, // 'underline' | 'segmented'
});
const emit = defineEmits(['update:modelValue']);

const root = ref(null);
const pos = ref({ x: 0, w: 0, ready: false });
let ro = null;

function measure() {
  const el = root.value?.querySelector('[role="tab"][aria-selected="true"]');
  if (!el) { pos.value = { x: 0, w: 0, ready: false }; return; }
  // Skip the first transition so the indicator does not slide in from 0.
  pos.value = { x: el.offsetLeft, w: el.offsetWidth, ready: true };
}

function select(tab) {
  if (tab.disabled) return;
  emit('update:modelValue', tab.value);
}

function onKeydown(e) {
  const enabled = props.tabs.filter((t) => !t.disabled);
  const i = enabled.findIndex((t) => t.value === props.modelValue);
  let next = null;
  if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = enabled[(i + 1) % enabled.length];
  else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = enabled[(i - 1 + enabled.length) % enabled.length];
  else if (e.key === 'Home') next = enabled[0];
  else if (e.key === 'End') next = enabled[enabled.length - 1];
  if (!next) return;
  e.preventDefault();
  select(next);
  nextTick(() => root.value?.querySelector('[role="tab"][aria-selected="true"]')?.focus());
}

const tabId = (t) => t.id || (props.idPrefix ? `${props.idPrefix}-tab-${t.value}` : undefined);
const panelId = (t) => t.controls || (props.idPrefix ? `${props.idPrefix}-panel-${t.value}` : undefined);

watch(() => [props.modelValue, props.tabs], () => nextTick(measure), { deep: true });
onMounted(() => {
  measure();
  if (typeof ResizeObserver !== 'undefined') {
    ro = new ResizeObserver(measure);
    ro.observe(root.value);
  }
});
onBeforeUnmount(() => ro?.disconnect());
</script>

<template>
  <div
    ref="root"
    class="at-tabs at-tabs-slide"
    :class="`at-tabs-slide--${variant}`"
    role="tablist"
    :aria-label="label || undefined"
    :style="{ '--at-tabs-x': `${pos.x}px`, '--at-tabs-w': `${pos.w}px` }"
    @keydown="onKeydown"
  >
    <button
      v-for="t in tabs"
      :id="tabId(t)"
      :key="t.value"
      type="button"
      role="tab"
      class="at-tab"
      :class="{ active: t.value === modelValue }"
      :aria-selected="t.value === modelValue"
      :aria-controls="panelId(t)"
      :tabindex="t.value === modelValue ? 0 : -1"
      :disabled="t.disabled || undefined"
      :data-testid="t.testid"
      @click="select(t)"
    >
      <slot name="tab" :tab="t">
        <span v-if="t.icon" class="material-symbols-outlined" aria-hidden="true">{{ t.icon }}</span>
        {{ t.label }}
        <span v-if="t.badge" class="at-tabs-slide__badge">{{ t.badge }}</span>
      </slot>
    </button>
    <span class="at-tabs-slide__indicator" :class="{ 'is-ready': pos.ready }" aria-hidden="true"></span>
  </div>
</template>

<style scoped>
.at-tabs-slide { position: relative; }
/* The sliding indicator replaces each tab's own selected border. */
.at-tabs-slide .at-tab { position: relative; z-index: 1; border-bottom-color: transparent; }
.at-tabs-slide .at-tab:disabled { opacity: 0.5; cursor: not-allowed; }
.at-tabs-slide__badge {
  min-width: 18px; padding: 0 6px; border-radius: 9px; background: var(--ds-surface-subtle);
  color: var(--ds-ink); font-size: 12px; line-height: 18px; text-align: center;
}
.at-tabs-slide__indicator {
  position: absolute; left: 0; pointer-events: none; opacity: 0;
  width: var(--at-tabs-w); transform: translateX(var(--at-tabs-x));
}
.at-tabs-slide__indicator.is-ready {
  opacity: 1;
  transition: transform var(--ds-motion-base) var(--ds-ease-standard), width var(--ds-motion-base) var(--ds-ease-standard);
}
.at-tabs-slide--underline .at-tabs-slide__indicator { bottom: -1px; height: 2px; background: var(--ds-cta); }

.at-tabs-slide--segmented {
  display: inline-flex; padding: 3px; gap: 0; border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-md, 10px); background: var(--ds-surface-subtle); overflow: hidden;
}
.at-tabs-slide--segmented .at-tab { margin-bottom: 0; border-radius: calc(var(--ds-radius-md, 10px) - 3px); }
.at-tabs-slide--segmented .at-tabs-slide__indicator {
  top: 3px; bottom: 3px; left: 0; border-radius: calc(var(--ds-radius-md, 10px) - 3px);
  background: var(--ds-surface); box-shadow: var(--ds-shadow-1);
}

@media (prefers-reduced-motion: reduce) {
  .at-tabs-slide__indicator.is-ready { transition: none; }
}
</style>
