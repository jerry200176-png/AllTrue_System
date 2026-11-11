<script setup>
import { Badge } from '@/components/ui/badge';

// 課表回報管理 (#4989): one status chip for the row status and the tab counts. The text is always shown, so tone is never colour alone.
defineProps({ status: { type: String, default: '' } });

const TONE = {
  pending: 'tw:border-[var(--ds-warning-line)] tw:bg-[var(--ds-warning-wash)] tw:text-[color:var(--ds-warning)]',
  acknowledged: 'tw:border-border tw:bg-secondary tw:text-foreground',
  resolved: 'tw:border-[var(--ds-success-line)] tw:bg-[var(--ds-success-wash)] tw:text-[color:var(--ds-success-text)]',
};
</script>

<template>
  <Badge
    variant="outline"
    :data-status="status"
    :class="['tw:px-3 tw:py-1 tw:text-[length:var(--tpl-text-caption)] tw:font-semibold tw:tabular-nums', TONE[status] || 'tw:bg-secondary tw:text-tpl-secondary']"
  >
    <slot />
  </Badge>
</template>
