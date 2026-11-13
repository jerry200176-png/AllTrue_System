<script setup>
// Page template shell (docs/design/PAGE_TEMPLATE.md §1): 1280 max, 16px phone gutter (the app shell gives 10px), room above the phone tab bar.
import { cn } from '@/lib/utils';

const props = defineProps({ class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true } });
</script>

<template>
  <div
    data-slot="page-shell"
    :class="cn('tw:mx-auto tw:flex tw:w-full tw:max-w-7xl tw:flex-col tw:gap-4 tw:px-[calc(var(--ds-space-4)-10px)] tw:pb-24 tw:text-tpl-body tw:text-foreground tw:md:px-0 tw:md:pb-10', props.class)"
  >
    <slot />
  </div>
</template>
