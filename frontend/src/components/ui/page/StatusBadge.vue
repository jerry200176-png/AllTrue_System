<script setup>
// Status pill (PAGE_TEMPLATE §6): wash background + AA text + a dot, so the state never rests on colour alone.
import { cn } from '@/lib/utils';
import { toneBadge } from './tones.js';

const props = defineProps({
  tone: { type: String, default: 'neutral' },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
</script>

<template>
  <span
    data-slot="status-badge"
    :data-tone="props.tone"
    :class="cn('tw:inline-flex tw:items-center tw:gap-1.5 tw:rounded-full tw:px-2.5 tw:py-0.5 tw:text-tpl-caption tw:font-semibold tw:whitespace-nowrap', toneBadge[props.tone] || toneBadge.neutral, props.class)"
  >
    <span class="tw:size-1.5 tw:shrink-0 tw:rounded-full tw:bg-current" aria-hidden="true"></span>
    <slot />
  </span>
</template>
