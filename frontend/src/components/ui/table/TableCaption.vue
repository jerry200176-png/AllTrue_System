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
  <caption
    data-slot="table-caption"
    :class="cn('tw:text-muted-foreground tw:mt-4 tw:text-sm', props.class)"
  >
    <slot />
  </caption>
</template>
