<script setup>
// Collapsible group header (PAGE_TEMPLATE §4/§5): a list-row header inside a SectionCard. The whole title area is one toggle button
// (aria-expanded + aria-controls); the action slot sits beside it, never inside it, so a nested control is not a button-in-button.
// Slots: `title` (name, badges), `meta` (counts; second line on phone, right edge on desktop), `actions` (a 44px control), default (what follows the header).
import { ChevronRight } from 'lucide-vue-next';
import { cn } from '@/lib/utils';

const props = defineProps({
  expanded: { type: Boolean, default: false },
  toggleId: { type: String, default: undefined },
  controls: { type: String, default: undefined },
  toggleClass: { type: [String, Object, Array], default: '' },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
const emit = defineEmits(['toggle']);
</script>

<template>
  <div data-slot="collapsible-group" :class="cn('tw:min-w-0', props.class)">
    <div class="tw:flex tw:items-center tw:gap-3 tw:px-4">
      <button
        type="button"
        data-slot="collapsible-group-toggle"
        :id="props.toggleId"
        :aria-expanded="props.expanded"
        :aria-controls="props.controls"
        :class="cn('tw:flex tw:min-h-18 tw:min-w-0 tw:flex-1 tw:cursor-pointer tw:flex-wrap tw:items-center tw:gap-x-2 tw:gap-y-0.5 tw:rounded-2xl tw:border-0 tw:bg-transparent tw:p-0 tw:text-left tw:text-tpl-list tw:font-bold tw:text-foreground tw:outline-none tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50', props.toggleClass)"
        @click="emit('toggle')"
      >
        <ChevronRight :size="22" aria-hidden="true" :class="['tw:shrink-0 tw:text-tpl-secondary tw:transition-transform tw:motion-reduce:transition-none', props.expanded ? 'tw:rotate-90' : '']" />
        <span class="tw:inline-flex tw:min-w-0 tw:flex-wrap tw:items-center tw:gap-x-2 tw:gap-y-1 tw:text-tpl-list tw:font-bold"><slot name="title" /></span>
        <span v-if="$slots.meta" class="tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary tw:max-md:basis-full tw:max-md:pl-[30px] tw:md:ml-auto"><slot name="meta" /></span>
      </button>
      <div v-if="$slots.actions" class="tw:shrink-0"><slot name="actions" /></div>
    </div>
    <slot />
  </div>
</template>
