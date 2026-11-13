<script setup>
// Section card (PAGE_TEMPLATE §4): the 今日工作台 glass card. Title row (h3, optional count, actions), body flush to the edges, optional footer.
import { useId } from 'vue';
import { cn } from '@/lib/utils';
import { surface } from './tones.js';

const props = defineProps({
  title: { type: String, default: '' },
  count: { type: [String, Number], default: null },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
const titleId = useId();
</script>

<template>
  <section data-slot="section-card" :aria-labelledby="props.title ? titleId : undefined" :class="cn(surface.card, 'tw:overflow-hidden', props.class)">
    <header v-if="props.title || $slots.actions" class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-2 tw:px-5 tw:pt-4 tw:pb-3">
      <h3 v-if="props.title" :id="titleId" class="tw:m-0 tw:text-tpl-list tw:font-bold tw:text-foreground">
        {{ props.title }}<span v-if="props.count != null" class="tw:font-semibold tw:text-tpl-secondary tw:tabular-nums">（{{ props.count }}）</span>
      </h3>
      <div v-if="$slots.actions" class="tw:flex tw:flex-wrap tw:items-center tw:gap-2"><slot name="actions" /></div>
    </header>
    <slot />
    <footer v-if="$slots.footer" class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-t tw:border-border tw:px-5 tw:py-3 tw:text-tpl-caption tw:text-tpl-secondary">
      <slot name="footer" />
    </footer>
  </section>
</template>
