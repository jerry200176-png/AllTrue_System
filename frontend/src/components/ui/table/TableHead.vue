<script setup>
import { cn } from "@/lib/utils";

const props = defineProps({
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});
</script>

<template>
  <th
    data-slot="table-head"
    :class="
      cn(
        'tw:text-foreground tw:h-10 tw:px-2 tw:text-left tw:align-middle tw:font-medium tw:whitespace-nowrap tw:[&:has([role=checkbox])]:pr-0 tw:*:[[role=checkbox]]:translate-y-0.5',
        props.class,
      )
    "
  >
    <slot />
  </th>
</template>
