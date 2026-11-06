<script setup>
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { TabsList } from "reka-ui";

const props = defineProps({
  loop: { type: Boolean, required: false },
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
  <TabsList
    data-slot="tabs-list"
    v-bind="delegatedProps"
    :class="
      cn(
        'tw:bg-muted tw:text-muted-foreground tw:inline-flex tw:h-9 tw:w-fit tw:items-center tw:justify-center tw:rounded-lg tw:p-0.75',
        props.class,
      )
    "
  >
    <slot />
  </TabsList>
</template>
