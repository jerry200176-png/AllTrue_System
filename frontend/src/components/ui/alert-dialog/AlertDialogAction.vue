<script setup>
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { AlertDialogAction } from "reka-ui";
import { buttonVariants } from "@/components/ui/button";

const props = defineProps({
  variant: { type: null, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
const delegatedProps = reactiveOmit(props, "class", "variant");
</script>

<template>
  <AlertDialogAction
    data-slot="alert-dialog-action"
    v-bind="delegatedProps"
    :class="cn(buttonVariants({ variant }), props.class)"
  >
    <slot />
  </AlertDialogAction>
</template>
