<script setup>
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { AlertDialogTitle, useForwardProps } from "reka-ui";

const props = defineProps({
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
const forwardedProps = useForwardProps(reactiveOmit(props, "class"));
</script>

<template>
  <AlertDialogTitle
    data-slot="alert-dialog-title"
    v-bind="forwardedProps"
    :class="cn('tw:text-lg tw:font-semibold', props.class)"
  >
    <slot />
  </AlertDialogTitle>
</template>
