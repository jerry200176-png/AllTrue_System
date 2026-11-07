<script setup>
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { DialogTitle } from "reka-ui";

const props = defineProps({
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});

const delegatedProps = reactiveOmit(props, "class");
</script>

<template>
  <DialogTitle
    data-slot="sheet-title"
    :class="cn('tw:text-foreground tw:font-semibold', props.class)"
    v-bind="delegatedProps"
  >
    <slot />
  </DialogTitle>
</template>
