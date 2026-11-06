<script setup>
/*
 * Adapted from Inspira UI NumberTicker (https://github.com/unovue/inspira-ui, MIT)
 * MIT License. Copyright (c) 2024-2026 rahulv.dev
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 * documentation files (the "Software"), to deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit
 * persons to whom the Software is furnished to do so, subject to the following conditions: The above copyright notice
 * and this permission notice shall be included in all copies or substantial portions of the Software.
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
 * WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 * COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
 * OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 * Source: https://github.com/unovue/inspira-ui
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
