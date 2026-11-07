<script setup>
import { ChevronDown } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { SelectScrollDownButton, useForwardProps } from "reka-ui";

const props = defineProps({
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});

const delegatedProps = reactiveOmit(props, "class");

const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
  <SelectScrollDownButton
    data-slot="select-scroll-down-button"
    v-bind="forwardedProps"
    :class="
      cn('tw:flex tw:cursor-default tw:items-center tw:justify-center tw:py-1', props.class)
    "
  >
    <slot>
      <ChevronDown class="tw:size-4" />
    </slot>
  </SelectScrollDownButton>
</template>
