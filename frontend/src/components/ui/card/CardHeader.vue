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
  <div
    data-slot="card-header"
    :class="
      cn(
        'tw:@container/card-header tw:grid tw:auto-rows-min tw:grid-rows-[auto_auto] tw:items-start tw:gap-1.5 tw:px-6 tw:has-data-[slot=card-action]:grid-cols-[1fr_auto] tw:[.border-b]:pb-6',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
