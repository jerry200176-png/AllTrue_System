<script setup>
// −/+ stepper around a native <input type="number"> (#4697 item 4). The input keeps its own
// value semantics, min/max/step and v-model; a button press also rolls the number briefly.
import { computed, ref } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  modelValue: { type: [Number, String], default: '' },
  min: { type: Number, default: undefined },
  max: { type: Number, default: undefined },
  step: { type: Number, default: 1 },
  disabled: { type: Boolean, default: false },
  decreaseLabel: { type: String, default: '減少' },
  increaseLabel: { type: String, default: '增加' },
});
const emit = defineEmits(['update:modelValue']);

const roll = ref({ dir: 0, flip: false });
const current = computed(() => (props.modelValue === '' || props.modelValue == null ? NaN : Number(props.modelValue)));

const atMin = computed(() => props.min !== undefined && current.value <= props.min);
const atMax = computed(() => props.max !== undefined && current.value >= props.max);

function clamp(n) {
  if (props.min !== undefined) n = Math.max(props.min, n);
  if (props.max !== undefined) n = Math.min(props.max, n);
  return n;
}

function bump(dir) {
  const base = Number.isNaN(current.value) ? (props.min ?? 0) - (dir > 0 ? props.step : 0) : current.value;
  const digits = (String(props.step).split('.')[1] || '').length;
  const next = clamp(Number((base + dir * props.step).toFixed(digits)));
  if (next === current.value) return;
  roll.value = { dir, flip: !roll.value.flip };
  emit('update:modelValue', next);
}

function onInput(e) {
  emit('update:modelValue', e.target.value === '' ? '' : Number(e.target.value));
}
</script>

<template>
  <span class="at-stepper" :class="$attrs.class">
    <button
      type="button"
      class="at-stepper__btn"
      :aria-label="decreaseLabel"
      :disabled="disabled || atMin"
      @click="bump(-1)"
    ><span class="material-symbols-outlined" aria-hidden="true">remove</span></button>
    <input
      v-bind="{ ...$attrs, class: undefined }"
      class="at-stepper__input"
      :class="roll.dir ? (roll.flip ? 'is-roll-a' : 'is-roll-b') : null"
      :style="{ '--at-stepper-from': `${roll.dir * -6}px` }"
      type="number"
      :value="modelValue"
      :min="min"
      :max="max"
      :step="step"
      :disabled="disabled"
      @input="onInput"
    />
    <button
      type="button"
      class="at-stepper__btn"
      :aria-label="increaseLabel"
      :disabled="disabled || atMax"
      @click="bump(1)"
    ><span class="material-symbols-outlined" aria-hidden="true">add</span></button>
  </span>
</template>

<style scoped>
.at-stepper { display: inline-flex; align-items: stretch; gap: var(--ds-space-1, 4px); width: 100%; }
.at-stepper__input {
  flex: 1; min-width: 0; text-align: center; appearance: textfield; -moz-appearance: textfield;
}
.at-stepper__input::-webkit-outer-spin-button,
.at-stepper__input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.at-stepper__btn {
  display: inline-flex; align-items: center; justify-content: center; flex: none;
  min-width: var(--ds-control-height-touch, 44px); border: 1px solid var(--ds-hairline-input, var(--ds-hairline));
  border-radius: var(--ds-radius-lg); background: var(--ds-surface); color: var(--ds-ink); cursor: pointer;
  transition: background var(--ds-motion-fast) var(--ds-ease-standard);
}
.at-stepper__btn:hover:not(:disabled) { background: var(--ds-surface-subtle); }
.at-stepper__btn:focus-visible { outline: 3px solid var(--ds-focus-ring); outline-offset: 2px; }
.at-stepper__btn:disabled { opacity: 0.4; cursor: not-allowed; }
/* Short number roll: the digits slide in from the direction of travel. Two identical keyframes restart it. */
.at-stepper__input.is-roll-a { animation: at-stepper-roll-a var(--ds-motion-base) var(--ds-ease-standard); }
.at-stepper__input.is-roll-b { animation: at-stepper-roll-b var(--ds-motion-base) var(--ds-ease-standard); }
@keyframes at-stepper-roll-a { from { transform: translateY(var(--at-stepper-from)); opacity: 0.4; } to { transform: none; opacity: 1; } }
@keyframes at-stepper-roll-b { from { transform: translateY(var(--at-stepper-from)); opacity: 0.4; } to { transform: none; opacity: 1; } }
@media (prefers-reduced-motion: reduce) {
  .at-stepper__btn { transition: none; }
  .at-stepper__input.is-roll-a, .at-stepper__input.is-roll-b { animation: none; }
}
</style>
