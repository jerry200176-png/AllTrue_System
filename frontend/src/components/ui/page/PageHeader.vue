<script setup>
// Page header (PAGE_TEMPLATE §2): h2 title, one context line under it, actions on the right (full-width row on phone).
import { cn } from '@/lib/utils';

const props = defineProps({
  title: { type: String, required: true },
  context: { type: String, default: '' },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
</script>

<template>
  <header data-slot="page-header" :class="cn('tw:flex tw:flex-wrap tw:items-end tw:justify-between tw:gap-x-4 tw:gap-y-3', props.class)">
    <div class="tw:min-w-0">
      <h2 class="tw:m-0 tw:text-tpl-page tw:font-bold tw:tracking-tight tw:text-foreground tw:text-balance">{{ props.title }}</h2>
      <p v-if="props.context || $slots.context" data-slot="page-context" class="tw:mt-1 tw:text-tpl-caption tw:text-tpl-secondary tw:text-pretty">
        <slot name="context">{{ props.context }}</slot>
      </p>
    </div>
    <div v-if="$slots.actions" data-slot="page-actions" class="tw:flex tw:w-full tw:flex-wrap tw:items-center tw:gap-2 tw:md:w-auto">
      <slot name="actions" />
    </div>
  </header>
</template>
