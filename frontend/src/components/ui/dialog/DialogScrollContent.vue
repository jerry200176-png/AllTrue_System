<script setup>
import { X } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import {
  DialogClose,
  DialogContent,
  DialogOverlay,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui";

defineOptions({ inheritAttrs: false });

const props = defineProps({
  forceMount: { type: Boolean, required: false },
  disableOutsidePointerEvents: { type: Boolean, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});
const emits = defineEmits([
  "escapeKeyDown",
  "pointerDownOutside",
  "focusOutside",
  "interactOutside",
  "openAutoFocus",
  "closeAutoFocus",
]);

const delegatedProps = reactiveOmit(props, "class");

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
  <DialogPortal>
    <DialogOverlay
      class="tw:fixed tw:inset-0 tw:z-50 tw:grid tw:place-items-center tw:overflow-y-auto tw:bg-black/80 tw:data-[state=open]:animate-in tw:data-[state=closed]:animate-out tw:data-[state=closed]:fade-out-0 tw:data-[state=open]:fade-in-0"
    >
      <DialogContent
        :class="
          cn(
            'tw:relative tw:z-50 tw:grid tw:w-full tw:max-w-lg tw:my-8 tw:gap-4 tw:border tw:border-border tw:bg-background tw:p-6 tw:shadow-lg tw:duration-200 tw:sm:rounded-lg tw:md:w-full',
            props.class,
          )
        "
        v-bind="{ ...$attrs, ...forwarded }"
        @pointer-down-outside="
          (event) => {
            const originalEvent = event.detail.originalEvent;
            const target = originalEvent.target;
            if (
              originalEvent.offsetX > target.clientWidth ||
              originalEvent.offsetY > target.clientHeight
            ) {
              event.preventDefault();
            }
          }
        "
      >
        <slot />

        <DialogClose
          class="tw:absolute tw:top-4 tw:right-4 tw:p-0.5 tw:transition-colors tw:rounded-md tw:hover:bg-secondary"
        >
          <X class="tw:w-4 tw:h-4" />
          <span class="tw:sr-only">Close</span>
        </DialogClose>
      </DialogContent>
    </DialogOverlay>
  </DialogPortal>
</template>
