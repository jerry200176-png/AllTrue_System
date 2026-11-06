<script setup>
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { SwitchRoot, SwitchThumb, useForwardPropsEmits } from "reka-ui";

const props = defineProps({
  defaultValue: { type: null, required: false },
  modelValue: { type: null, required: false },
  disabled: { type: Boolean, required: false },
  id: { type: String, required: false },
  value: { type: String, required: false },
  trueValue: { type: null, required: false },
  falseValue: { type: null, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  name: { type: String, required: false },
  required: { type: Boolean, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});

const emits = defineEmits(["update:modelValue"]);

const delegatedProps = reactiveOmit(props, "class");

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
  <SwitchRoot
    v-slot="slotProps"
    data-slot="switch"
    v-bind="forwarded"
    :class="
      cn(
        'tw:peer tw:data-[state=checked]:bg-primary tw:data-[state=unchecked]:bg-input tw:focus-visible:border-ring tw:focus-visible:ring-ring/50 tw:dark:data-[state=unchecked]:bg-input/80 tw:inline-flex tw:h-[1.15rem] tw:w-8 tw:shrink-0 tw:items-center tw:rounded-full tw:border tw:border-transparent tw:shadow-xs tw:transition-all tw:outline-none tw:focus-visible:ring-3 tw:disabled:cursor-not-allowed tw:disabled:opacity-50',
        props.class,
      )
    "
  >
    <SwitchThumb
      data-slot="switch-thumb"
      :class="
        cn(
          'tw:bg-background tw:dark:data-[state=unchecked]:bg-foreground tw:dark:data-[state=checked]:bg-primary-foreground tw:pointer-events-none tw:block tw:size-4 tw:rounded-full tw:ring-0 tw:transition-transform tw:data-[state=checked]:translate-x-[calc(100%-2px)] tw:data-[state=unchecked]:translate-x-0',
        )
      "
    >
      <slot name="thumb" v-bind="slotProps" />
    </SwitchThumb>
  </SwitchRoot>
</template>
