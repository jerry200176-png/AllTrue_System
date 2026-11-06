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
    data-slot="card-action"
    :class="
      cn(
        'tw:col-start-2 tw:row-span-2 tw:row-start-1 tw:self-start tw:justify-self-end',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
