<script setup>
// Activity ring (done / total) with a gradient stroke and a draw-in. Small SVG instead of a chart library (bundle size);
// the draw-in is skipped under prefers-reduced-motion.
import { computed, onMounted, ref, useId } from "vue";

const props = defineProps({
  done: { type: Number, default: 0 },
  total: { type: Number, default: 0 },
  size: { type: Number, default: 112 },
  label: { type: String, default: "" },
});

const R = 44;
const C = 2 * Math.PI * R;
const id = `ring-${useId()}`;
const drawn = ref(false);
const reduced = () => typeof window !== "undefined" && window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches;
onMounted(() => { drawn.value = true; });

const ratio = computed(() => (props.total > 0 ? Math.min(1, Math.max(0, props.done / props.total)) : 0));
const offset = computed(() => (drawn.value ? C * (1 - ratio.value) : C));
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 112 112" role="img" :aria-label="label" class="tw:shrink-0">
    <defs>
      <linearGradient :id="id" x1="0" x2="1">
        <stop offset="0" style="stop-color: var(--ds-brand-sun)" />
        <stop offset="1" style="stop-color: var(--ds-brand-amber)" />
      </linearGradient>
    </defs>
    <circle cx="56" cy="56" r="44" fill="none" stroke-width="14" style="stroke: color-mix(in srgb, var(--ds-brand-amber) 18%, transparent)" />
    <circle
      cx="56" cy="56" r="44" fill="none" stroke-width="14" stroke-linecap="round"
      :stroke="`url(#${id})`" :stroke-dasharray="C" :stroke-dashoffset="reduced() ? C * (1 - ratio) : offset"
      transform="rotate(-90 56 56)"
      :style="reduced() ? '' : 'transition: stroke-dashoffset 1s cubic-bezier(.2,.8,.2,1)'"
    />
  </svg>
</template>
