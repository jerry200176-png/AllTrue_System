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
  <tfoot
    data-slot="table-footer"
    :class="
      cn('tw:bg-muted/50 tw:border-t tw:font-medium tw:[&>tr]:last:border-b-0', props.class)
    "
  >
    <slot />
  </tfoot>
</template>
