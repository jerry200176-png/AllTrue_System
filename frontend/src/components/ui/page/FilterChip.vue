<script setup>
// Filter chip (PAGE_TEMPLATE §5): 44px pill with its count; the active one is the ink pill. Sits in a toolbar above the card it filters.
import { cn } from '@/lib/utils';

const props = defineProps({
  active: { type: Boolean, default: false },
  count: { type: [String, Number], default: null },
  // false for a chip that navigates instead of filtering (no pressed state to announce).
  toggle: { type: Boolean, default: true },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
</script>

<template>
  <button
    type="button"
    data-slot="filter-chip"
    :aria-pressed="props.toggle ? props.active : undefined"
    :class="cn(
      'tw:inline-flex tw:h-11 tw:shrink-0 tw:cursor-pointer tw:items-center tw:gap-1.5 tw:rounded-full tw:border-0 tw:px-4 tw:text-tpl-caption tw:font-semibold tw:whitespace-nowrap tw:transition-colors tw:outline-none tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50',
      props.active ? 'tw:bg-primary tw:text-primary-foreground' : 'tw:bg-card tw:text-tpl-secondary tw:shadow-[inset_0_0_0_1px_var(--ds-hairline)] tw:hover:text-foreground',
      props.class,
    )"
  ><slot /><template v-if="props.count != null">{{ ' ' }}<span class="tw:tabular-nums tw:opacity-80">{{ props.count }}</span></template></button>
</template>
