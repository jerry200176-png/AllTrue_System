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
    data-slot="dialog-header"
    :class="cn('tw:flex tw:flex-col tw:gap-2 tw:text-center tw:sm:text-left', props.class)"
  >
    <slot />
  </div>
</template>
