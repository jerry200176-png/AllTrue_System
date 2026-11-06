<script setup>
import { cn } from "@/lib/utils";
import { SelectLabel } from "reka-ui";

const props = defineProps({
  for: { type: String, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});
</script>

<template>
  <SelectLabel
    data-slot="select-label"
    :class="cn('tw:text-muted-foreground tw:px-2 tw:py-1.5 tw:text-xs', props.class)"
  >
    <slot />
  </SelectLabel>
</template>
