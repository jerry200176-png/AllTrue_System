<script setup>
/*
 * Adapted from Inspira UI NumberTicker
 *   repo:    https://github.com/unovue/inspira-ui
 *   path:    app/components/inspira/ui/number-ticker/NumberTicker.vue
 *   commit:  eec920b047589058b4b02ba64deb614869e7237a
 *   licence: MIT, Copyright (c) 2024-2026 rahulv.dev (full text in ./LICENSE-inspira-ui)
 * Changes: plain JS; @vueuse useTransition replaced by requestAnimationFrame (ease-out cubic), no new dependency;
 * counts on mount and on value change (no in-view gate); ends on the exact value; prefers-reduced-motion shows the
 * final value at once.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { cn } from '@/lib/utils';
import { prefersReducedMotion } from './reduced';

const props = defineProps({
  value: { type: Number, default: 0 },
  duration: { type: Number, default: 800 }, // ms; count-up is a reveal, it never blocks input
  decimals: { type: Number, default: 0 },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});

const target = computed(() => (Number.isFinite(props.value) ? props.value : 0));
const shown = ref(target.value); // final value until mounted, so server/first render and no-JS never show 0
let raf = 0;

const fmt = (n) => n.toLocaleString('en-US', { minimumFractionDigits: props.decimals, maximumFractionDigits: props.decimals });
const stop = () => { if (raf) cancelAnimationFrame(raf); raf = 0; };

function run(to) {
  stop();
  if (prefersReducedMotion() || props.duration <= 0 || shown.value === to) { shown.value = to; return; }
  const from = shown.value;
  const t0 = performance.now();
  const tick = (now) => {
    const p = Math.min(1, (now - t0) / props.duration);
    shown.value = p >= 1 ? to : from + (to - from) * (1 - (1 - p) ** 3);
    raf = p >= 1 ? 0 : requestAnimationFrame(tick);
  };
  raf = requestAnimationFrame(tick);
}

onMounted(() => {
  if (!prefersReducedMotion()) shown.value = 0;
  run(target.value);
});
watch(target, run);
onBeforeUnmount(stop);
</script>

<template>
  <span :class="cn('tw:inline-block tw:tabular-nums', props.class)" :data-value="target">{{ fmt(shown) }}</span>
</template>
