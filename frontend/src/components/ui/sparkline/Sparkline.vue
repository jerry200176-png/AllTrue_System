<script setup>
// Tiny area+line sparkline. `values` = one number per day; drawn only when there is something to show.
import { computed, useId } from "vue";

const props = defineProps({
  values: { type: Array, default: () => [] },
  tone: { type: String, default: "amber" }, // brand hue: amber | bronze | gold
  label: { type: String, default: "" },
});
const id = `spark-${useId()}`;
const pts = computed(() => {
  const v = props.values.map(Number);
  const max = Math.max(1, ...v);
  return v.map((n, i) => [(i / Math.max(1, v.length - 1)) * 100, 34 - (n / max) * 28]);
});
const line = computed(() => pts.value.map(([x, y], i) => `${i ? "L" : "M"}${x.toFixed(1)} ${y.toFixed(1)}`).join(" "));
const area = computed(() => `${line.value} L100 40 L0 40Z`);
const color = computed(() => `var(--ds-brand-${props.tone})`);
</script>

<template>
  <svg v-if="values.length > 1" viewBox="0 0 100 40" preserveAspectRatio="none" role="img" :aria-label="label" class="tw:mt-2 tw:block tw:h-10 tw:w-full">
    <defs>
      <linearGradient :id="id" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" :style="{ stopColor: color, stopOpacity: 0.3 }" />
        <stop offset="1" :style="{ stopColor: color, stopOpacity: 0 }" />
      </linearGradient>
    </defs>
    <path :d="area" :fill="`url(#${id})`" />
    <path :d="line" fill="none" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" :style="{ stroke: color }" />
  </svg>
</template>
