<script setup>
// Selection bar (PAGE_TEMPLATE §4): at the top of a SectionCard when rows are picked. Default slot = what was picked, `actions` = cancel + the batch action.
import { cn } from '@/lib/utils';

const props = defineProps({
  label: { type: String, default: '清單操作' },
  // Read out politely when it changes (for example 已選 2 筆).
  announce: { type: String, default: '' },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
</script>

<template>
  <div data-slot="selection-bar" role="toolbar" :aria-label="props.label" :class="cn('tw:flex tw:min-h-14 tw:flex-wrap tw:items-center tw:gap-x-3 tw:gap-y-2 tw:bg-[var(--ds-primary-wash)] tw:px-4 tw:py-2 tw:md:px-5', props.class)">
    <span v-if="props.announce" class="tw:sr-only" role="status" aria-live="polite">{{ props.announce }}</span>
    <span class="tw:min-w-0 tw:text-tpl-body tw:font-semibold tw:text-pretty"><slot /></span>
    <span class="tw:flex tw:flex-1 tw:flex-wrap tw:justify-end tw:gap-2"><slot name="actions" /></span>
  </div>
</template>
