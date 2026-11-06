<script setup>
import { Check } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import {
  DropdownMenuCheckboxItem,
  DropdownMenuItemIndicator,
  useForwardPropsEmits,
} from "reka-ui";

const props = defineProps({
  modelValue: { type: [Boolean, String], required: false },
  disabled: { type: Boolean, required: false },
  textValue: { type: String, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});
const emits = defineEmits(["select", "update:modelValue"]);

const delegatedProps = reactiveOmit(props, "class");

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
  <DropdownMenuCheckboxItem
    data-slot="dropdown-menu-checkbox-item"
    v-bind="forwarded"
    :class="
      cn(
        `tw:focus:bg-accent tw:focus:text-accent-foreground tw:relative tw:flex tw:cursor-default tw:items-center tw:gap-2 tw:rounded-sm tw:py-1.5 tw:pr-2 tw:pl-8 tw:text-sm tw:outline-hidden tw:select-none tw:data-[disabled]:pointer-events-none tw:data-[disabled]:opacity-50 tw:[&_svg]:pointer-events-none tw:[&_svg]:shrink-0 tw:[&_svg:not([class*='size-'])]:size-4`,
        props.class,
      )
    "
  >
    <span
      class="tw:pointer-events-none tw:absolute tw:left-2 tw:flex tw:size-3.5 tw:items-center tw:justify-center"
    >
      <DropdownMenuItemIndicator>
        <slot name="indicator-icon">
          <Check class="tw:size-4" />
        </slot>
      </DropdownMenuItemIndicator>
    </span>
    <slot />
  </DropdownMenuCheckboxItem>
</template>
