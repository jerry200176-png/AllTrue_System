<script setup>
// Overflow menu on dropdown-menu (Reka: keyboard, typeahead, Esc, focus return, collision flip). Same props/emit as
// components/ActionMenu.vue so pages swap the import only. groups: [{ id, label, items: [{ id, label, danger, disabled, reason, title, confirm }] }].
import { MoreHorizontal } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel,
  DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

defineProps({
  groups: { type: Array, required: true },
  label: { type: String, default: '更多操作' },
  triggerText: { type: String, default: '' }, // empty = ••• icon
  triggerClass: { type: String, default: '' },
});
const emit = defineEmits(['select']);
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child @click.stop>
      <Button variant="ghost" size="icon" :class="triggerClass" :aria-label="label">
        <template v-if="triggerText">{{ triggerText }}</template>
        <MoreHorizontal v-else />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="tw:min-w-56" @click.stop>
      <template v-for="(g, gi) in groups" :key="g.id">
        <DropdownMenuSeparator v-if="gi > 0" />
        <DropdownMenuGroup>
          <DropdownMenuLabel v-if="g.label">{{ g.label }}</DropdownMenuLabel>
          <DropdownMenuItem
            v-for="item in g.items"
            :key="item.id"
            :data-action="item.id"
            :variant="item.danger ? 'destructive' : 'default'"
            :disabled="item.disabled"
            :title="item.title || item.reason || undefined"
            class="tw:min-h-10 tw:sm:min-h-8"
            @select="emit('select', item.id)"
          >
            <span>{{ item.label }}{{ item.confirm ? '…' : '' }}</span>
            <span v-if="item.disabled && item.reason" class="tw:block tw:text-xs tw:text-muted-foreground">{{ item.reason }}</span>
          </DropdownMenuItem>
        </DropdownMenuGroup>
      </template>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
