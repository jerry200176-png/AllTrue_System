<script setup>
import { useTextSize } from '../composables/useTextSize.js';

// 字級：標準／大. One component for 個人設定 and the account menu (and the director home).
const { sizes, current, setSize } = useTextSize();

function onKeydown(event, index) {
  const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
  if (!step) return;
  event.preventDefault();
  const next = (index + step + sizes.length) % sizes.length;
  setSize(sizes[next].key);
  event.currentTarget.parentElement.querySelectorAll('[role="radio"]')[next]?.focus();
}
</script>

<template>
  <div role="radiogroup" aria-label="字級" class="tw:inline-flex tw:rounded-full tw:border tw:border-border tw:bg-card tw:p-1" data-testid="text-size">
    <button
      v-for="(s, index) in sizes"
      :key="s.key"
      type="button"
      role="radio"
      :aria-checked="current === s.key"
      :tabindex="current === s.key ? 0 : -1"
      :data-size="s.key"
      class="tw:h-14 tw:min-w-20 tw:cursor-pointer tw:rounded-full tw:border-0 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-bold tw:outline-none tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50"
      :class="current === s.key ? 'tw:bg-primary tw:text-primary-foreground' : 'tw:bg-transparent tw:text-tpl-secondary'"
      @click="setSize(s.key)"
      @keydown="onKeydown($event, index)"
    >{{ s.label }}</button>
  </div>
</template>
