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
  <span
    data-slot="dropdown-menu-shortcut"
    :class="
      cn('tw:text-muted-foreground tw:ml-auto tw:text-xs tw:tracking-widest', props.class)
    "
  >
    <slot />
  </span>
</template>
