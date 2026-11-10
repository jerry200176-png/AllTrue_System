<script setup>
// Detail template (sheet): right panel on desktop, full-screen on phone. Header = title + status chip + ••• menu
// (X stays top-right); optional tabs (slots `tab-<id>`); sticky footer slot. Do not open another sheet/dialog from here
// except a ConfirmDialog.
import { Badge } from '@/components/ui/badge';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import ActionMenu from './ActionMenu.vue';

defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  description: { type: String, default: '' }, // screen-reader summary; sr-only
  status: { type: String, default: '' },
  statusVariant: { type: String, default: 'secondary' },
  menuGroups: { type: Array, default: () => [] }, // ActionMenu groups; omit for no ••• menu
  tabs: { type: Array, default: () => [] }, // [{ id, label }]
  tab: { type: String, default: '' },
});
const emit = defineEmits(['update:open', 'update:tab', 'menu-select']);
</script>

<template>
  <Sheet :open="open" @update:open="emit('update:open', $event)">
    <SheetContent class="tw:w-full tw:gap-0 tw:p-0 tw:sm:w-[32rem] tw:sm:max-w-[32rem]">
      <SheetHeader class="tw:flex-row tw:items-center tw:gap-2 tw:border-b tw:p-4 tw:pr-12">
        <SheetTitle class="tw:min-w-0 tw:flex-1 tw:truncate">{{ title }}</SheetTitle>
        <Badge v-if="status" :variant="statusVariant">{{ status }}</Badge>
        <ActionMenu v-if="menuGroups.length" :groups="menuGroups" label="更多操作" @select="emit('menu-select', $event)" />
        <SheetDescription class="tw:sr-only">{{ description || title }}</SheetDescription>
      </SheetHeader>
      <Tabs
        v-if="tabs.length"
        :model-value="tab || tabs[0].id"
        class="tw:min-h-0 tw:flex-1 tw:gap-0"
        @update:model-value="emit('update:tab', $event)"
      >
        <TabsList class="tw:mx-4 tw:mt-3 tw:w-fit">
          <TabsTrigger v-for="t in tabs" :key="t.id" :value="t.id">{{ t.label }}</TabsTrigger>
        </TabsList>
        <TabsContent v-for="t in tabs" :key="t.id" :value="t.id" class="tw:overflow-y-auto tw:p-4">
          <slot :name="`tab-${t.id}`" />
        </TabsContent>
      </Tabs>
      <div v-else class="tw:min-h-0 tw:flex-1 tw:overflow-y-auto tw:p-4"><slot /></div>
      <SheetFooter v-if="$slots.footer" class="tw:sticky tw:bottom-0 tw:flex-row tw:justify-end tw:border-t tw:bg-background tw:p-4">
        <slot name="footer" />
      </SheetFooter>
    </SheetContent>
  </Sheet>
</template>
