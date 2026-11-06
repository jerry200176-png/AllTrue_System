<script setup>
import { ChevronRight } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { DropdownMenuSubTrigger, useForwardProps } from "reka-ui";

const props = defineProps({
  graceDuration: { type: Number, required: false },
  disabled: { type: Boolean, required: false },
  textValue: { type: String, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
  inset: { type: Boolean, required: false },
});

const delegatedProps = reactiveOmit(props, "class", "inset");
const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
  <DropdownMenuSubTrigger
    data-slot="dropdown-menu-sub-trigger"
    v-bind="forwardedProps"
    :data-inset="inset ? '' : undefined"
    :class="
      cn(
        `tw:relative tw:flex tw:cursor-default tw:items-center tw:gap-2 tw:rounded-sm tw:px-2 tw:py-1.5 tw:text-sm tw:outline-hidden tw:select-none tw:focus:bg-accent tw:focus:text-accent-foreground tw:data-[disabled]:pointer-events-none tw:data-[disabled]:opacity-50 tw:data-[inset]:pl-8 tw:data-[variant=destructive]:text-destructive tw:data-[variant=destructive]:focus:bg-destructive/10 tw:data-[variant=destructive]:focus:text-destructive tw:dark:data-[variant=destructive]:focus:bg-destructive/20 tw:[&_svg]:pointer-events-none tw:[&_svg]:shrink-0 tw:[&_svg:not([class*='size-'])]:size-4 tw:[&_svg:not([class*='text-'])]:text-muted-foreground tw:data-[variant=destructive]:*:[svg]:text-destructive!`,
        props.class,
      )
    "
  >
    <slot />
    <ChevronRight class="tw:ml-auto tw:size-4" />
  </DropdownMenuSubTrigger>
</template>
