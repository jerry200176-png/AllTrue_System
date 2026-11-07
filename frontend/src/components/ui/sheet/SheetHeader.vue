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
    data-slot="sheet-header"
    :class="cn('tw:flex tw:flex-col tw:gap-1.5 tw:p-4', props.class)"
  >
    <slot />
  </div>
</template>
