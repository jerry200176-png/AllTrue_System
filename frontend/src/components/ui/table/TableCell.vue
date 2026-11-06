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
  <td
    data-slot="table-cell"
    :class="
      cn(
        'tw:p-2 tw:align-middle tw:whitespace-nowrap tw:[&:has([role=checkbox])]:pr-0 tw:*:[[role=checkbox]]:translate-y-0.5',
        props.class,
      )
    "
  >
    <slot />
  </td>
</template>
