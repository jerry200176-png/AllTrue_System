<script setup>
/*
 * Adapted from Inspira UI NumberTicker (https://github.com/unovue/inspira-ui, MIT)
 * Copyright (c) 2024-2026 rahulv.dev. Permission notice: https://github.com/unovue/inspira-ui/blob/main/LICENSE
 * Changes: plain JS, tw: prefix, count-up via motion-v `animate` (instead of @vueuse useTransition),
 * and prefers-reduced-motion shows the final value at once.
 */
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import { animate } from "motion-v";
import { cn } from "@/lib/utils";

const props = defineProps({
  value: { type: Number, default: 0 },
  duration: { type: Number, default: 0.9 },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});

const shown = ref(0);
let controls = null;
const reduced = () => typeof window !== "undefined" && window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches;

function run(to) {
  controls?.stop();
  const target = Number.isFinite(to) ? to : 0;
  if (reduced() || shown.value === target) { shown.value = target; return; }
  controls = animate(shown.value, target, {
    duration: props.duration,
    ease: "easeOut",
    onUpdate: (v) => { shown.value = Math.round(v); },
    onComplete: () => { shown.value = target; },
  });
}

onMounted(() => run(props.value));
watch(() => props.value, run);
onBeforeUnmount(() => controls?.stop());
</script>

<template>
  <span :class="cn('tw:inline-block tw:tabular-nums', props.class)" :data-value="value">{{ shown.toLocaleString('en-US') }}</span>
</template>
