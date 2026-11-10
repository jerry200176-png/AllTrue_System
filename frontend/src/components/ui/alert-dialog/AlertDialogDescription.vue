<script setup>
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { AlertDialogDescription, useForwardProps } from "reka-ui";

const props = defineProps({
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
const forwardedProps = useForwardProps(reactiveOmit(props, "class"));
</script>

<template>
  <AlertDialogDescription
    data-slot="alert-dialog-description"
    v-bind="forwardedProps"
    :class="cn('tw:text-muted-foreground tw:text-sm', props.class)"
  >
    <slot />
  </AlertDialogDescription>
</template>
