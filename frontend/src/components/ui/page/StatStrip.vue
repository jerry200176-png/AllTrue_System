<script setup>
// Summary strip (PAGE_TEMPLATE §3): ONE card split by hairlines, not a row of separate cards. Children are StatItem.
import { cn } from '@/lib/utils';
import { surface } from './tones.js';

const props = defineProps({ class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true } });
</script>

<template>
  <dl
    data-slot="stat-strip"
    :class="cn(surface.card, 'tw:m-0 tw:grid tw:grid-cols-2 tw:gap-px tw:overflow-hidden tw:bg-[var(--tpl-line)] tw:lg:auto-cols-fr tw:lg:grid-flow-col tw:lg:grid-cols-none', props.class)"
  >
    <slot />
  </dl>
</template>
