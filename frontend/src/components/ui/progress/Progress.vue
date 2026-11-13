<script setup>
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { ProgressIndicator, ProgressRoot } from "reka-ui";

const props = defineProps({
  modelValue: { type: [Number, null], required: false, default: 0 },
  max: { type: Number, required: false },
  getValueLabel: { type: Function, required: false },
  getValueText: { type: Function, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
  // Extra classes for the filled part (colour, transition). Default unchanged: the fill is `bg-primary`.
  indicatorClass: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});

const delegatedProps = reactiveOmit(props, "class", "indicatorClass");
</script>

<template>
  <ProgressRoot
    data-slot="progress"
    v-bind="delegatedProps"
    :class="
      cn(
        'tw:bg-primary/20 tw:relative tw:h-2 tw:w-full tw:overflow-hidden tw:rounded-full',
        props.class,
      )
    "
  >
    <ProgressIndicator
      data-slot="progress-indicator"
      :class="cn('tw:bg-primary tw:h-full tw:w-full tw:flex-1 tw:transition-all', props.indicatorClass)"
      :style="`transform: translateX(-${100 - ((props.modelValue ?? 0) / (props.max || 100)) * 100}%);`"
    />
  </ProgressRoot>
</template>
