<script setup>
// Page template shell (docs/design/PAGE_TEMPLATE.md §1): 1280 max, 16px phone gutter (the app shell gives 10px), room above the phone tab bar.
import { cn } from '@/lib/utils';
import { pageShell } from './tones.js';

const props = defineProps({ class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true } });
</script>

<template>
  <div
    data-slot="page-shell"
    :class="cn(pageShell, props.class)"
  >
    <slot />
  </div>
</template>
