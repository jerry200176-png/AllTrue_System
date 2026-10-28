<script setup>
// #4488 完成按鈕反饋: idle → pending → success | error → idle.
// `action` must resolve `true` only after the server confirmed the write; `false` shows the
// error face; anything else returns to idle without claiming success.
import { onBeforeUnmount, ref } from 'vue';
import AtButton from './AtButton.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  action: { type: Function, required: true },
  icon: { type: String, default: '' },
  pendingText: { type: String, default: '' },
  successText: { type: String, default: '已完成' },
  errorText: { type: String, default: '未完成' },
  disabled: { type: Boolean, default: false },
});

const SUCCESS_MS = 1600;
const ERROR_MS = 3200;
const state = ref('idle');
let timer;

function settle(next, ms) {
  state.value = next;
  timer = setTimeout(() => { state.value = 'idle'; }, ms);
}

async function run() {
  if (state.value === 'pending' || props.disabled) return;
  clearTimeout(timer);
  state.value = 'pending';
  try {
    const ok = await props.action();
    if (ok === true) settle('success', SUCCESS_MS);
    else if (ok === false) settle('error', ERROR_MS);
    else state.value = 'idle';
  } catch (e) {
    console.error(e);
    settle('error', ERROR_MS);
  }
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
  <AtButton
    v-bind="$attrs"
    :class="['at-submit', `at-submit--${state}`]"
    :disabled="disabled"
    :aria-disabled="state === 'pending' ? 'true' : undefined"
    :aria-busy="state === 'pending' ? 'true' : undefined"
    @click="run"
  >
    <!-- All faces share one grid cell, so the button keeps the widest face's width. Only the
         visible face names the button; the polite live region announces the state change. -->
    <span class="at-submit__stack" aria-live="polite">
      <span class="at-submit__face" :class="{ 'is-on': state === 'idle' }">
        <span v-if="icon" class="material-symbols-outlined at-submit__icon" aria-hidden="true">{{ icon }}</span>
        <slot />
      </span>
      <span class="at-submit__face" :class="{ 'is-on': state === 'pending' }">
        <span class="material-symbols-outlined at-submit__icon at-submit__spin" aria-hidden="true">progress_activity</span>
        <template v-if="pendingText">{{ pendingText }}</template>
        <slot v-else />
      </span>
      <span class="at-submit__face" :class="{ 'is-on': state === 'success' }">
        <span class="material-symbols-outlined at-submit__icon" aria-hidden="true">check</span>{{ successText }}
      </span>
      <span class="at-submit__face" :class="{ 'is-on': state === 'error' }">
        <span class="material-symbols-outlined at-submit__icon" aria-hidden="true">error</span>{{ errorText }}
      </span>
    </span>
  </AtButton>
</template>

<style scoped>
.at-submit__stack {
  display: inline-grid;
}

.at-submit__face {
  grid-area: 1 / 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  opacity: 0;
  visibility: hidden;
  transform: translateY(2px);
}

/* Outgoing face leaves at once; the incoming one fades up, so labels never overlap. */
.at-submit__face.is-on {
  opacity: 1;
  visibility: visible;
  transform: none;
  transition: opacity var(--ds-motion-base) var(--ds-ease-standard),
    transform var(--ds-motion-base) var(--ds-ease-standard);
}

.at-submit__icon {
  font-size: var(--ds-font-size-xl);
  line-height: 1;
}

.at-submit__spin {
  animation: at-submit-spin 0.8s linear infinite;
}

@keyframes at-submit-spin {
  to { transform: rotate(360deg); }
}

.at-submit.at-submit--pending {
  cursor: progress;
}

/* Outranks AtButton's variant :hover/:active rules (one more class), so a pointer still resting
   on the button cannot put result text on the variant's hover fill. */
.at-btn.at-submit.at-submit--success:not(:disabled) {
  background: var(--ds-success-wash);
  border-color: var(--ds-success-line);
  color: var(--ds-success-text);
  filter: none;
}

.at-btn.at-submit.at-submit--error:not(:disabled) {
  background: var(--ds-danger-wash);
  border-color: var(--ds-danger-line);
  color: var(--ds-danger-text);
  filter: none;
}

@media (prefers-reduced-motion: reduce) {
  .at-submit__face,
  .at-submit__face.is-on {
    transition: none;
    transform: none;
  }
  .at-submit__spin {
    animation: none;
  }
}
</style>
