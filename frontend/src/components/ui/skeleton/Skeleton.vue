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
    data-slot="skeleton"
    :class="cn('tw:animate-pulse tw:rounded-md tw:bg-primary/10', props.class)"
  />
</template>
