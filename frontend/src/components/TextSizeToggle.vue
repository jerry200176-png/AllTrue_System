<script setup>
import { useTextSize } from '../composables/useTextSize.js';

// 字級：標準／大. One component for 個人設定 and the account menu (and the director home).
const { sizes, current, setSize } = useTextSize();
</script>

<template>
  <div role="radiogroup" aria-label="字級" class="tw:inline-flex tw:rounded-full tw:border tw:border-border tw:bg-card tw:p-1" data-testid="text-size">
    <button
      v-for="s in sizes"
      :key="s.key"
      type="button"
      role="radio"
      :aria-checked="current === s.key"
      :data-size="s.key"
      class="tw:h-14 tw:min-w-20 tw:cursor-pointer tw:rounded-full tw:border-0 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-bold tw:outline-none tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50"
      :class="current === s.key ? 'tw:bg-primary tw:text-primary-foreground' : 'tw:bg-transparent tw:text-tpl-secondary'"
      @click="setSize(s.key)"
    >{{ s.label }}</button>
  </div>
</template>
