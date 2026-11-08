<script setup>
// Overflow menu on dropdown-menu (Reka: keyboard, typeahead, Esc, focus return, collision flip). Same props/emit as
// components/ActionMenu.vue so pages swap the import only. groups: [{ id, label, items: [{ id, label, danger, disabled, reason, title, confirm }] }].
// Parity with the legacy menu: <=640px renders a bottom sheet with 48px targets; disabled items stay focusable
// (aria-disabled, never `disabled`) so their reason line is readable, and selecting one does nothing.
import { MoreHorizontal } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel,
  DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';

defineProps({
  groups: { type: Array, required: true },
  label: { type: String, default: '更多操作' },
  triggerText: { type: String, default: '' }, // empty = ••• icon
  triggerClass: { type: String, default: '' },
});
const emit = defineEmits(['select']);

// Reka's item owns `aria-disabled` (it renders it only for its own `disabled` prop, which also drops focus), so set it by hand.
const setAria = (el, { value }) => (value ? el.setAttribute('aria-disabled', 'true') : el.removeAttribute('aria-disabled'));
const vAriaDisabled = { mounted: setAria, updated: setAria };
const uid = useId();
const reasonId = (item) => (item.disabled && item.reason ? `${uid}-${item.id}` : undefined);

const phone = ref(false);
const sheetOpen = ref(false);
let mq = null;
const sync = () => { phone.value = Boolean(mq?.matches); };
onMounted(() => {
  mq = window.matchMedia?.('(max-width: 640px)') || null;
  sync();
  mq?.addEventListener?.('change', sync);
});
onBeforeUnmount(() => mq?.removeEventListener?.('change', sync));

function pick(item, e) {
  if (item.disabled) { e?.preventDefault?.(); return; }
  sheetOpen.value = false;
  emit('select', item.id);
}
function sheetKey(e) {
  const list = [...e.currentTarget.querySelectorAll('[role=menuitem]')];
  const i = list.indexOf(document.activeElement);
  const to = { ArrowDown: i + 1, ArrowUp: i - 1, Home: 0, End: list.length - 1 }[e.key];
  if (to === undefined) return;
  e.preventDefault();
  list[(to + list.length) % list.length]?.focus();
}
</script>

<template>
  <Sheet v-if="phone" v-model:open="sheetOpen">
    <Button variant="ghost" size="icon" :class="triggerClass" :aria-label="label" aria-haspopup="menu" :aria-expanded="sheetOpen" @click.stop="sheetOpen = true">
      <template v-if="triggerText">{{ triggerText }}</template>
      <MoreHorizontal v-else />
    </Button>
    <SheetContent side="bottom" class="tw:max-h-[80dvh] tw:gap-0 tw:rounded-t-lg tw:p-2 tw:pb-[calc(0.5rem+env(safe-area-inset-bottom))]" @click.stop>
      <SheetTitle class="tw:sr-only">{{ label }}</SheetTitle>
      <SheetDescription class="tw:sr-only">{{ label }}</SheetDescription>
      <div role="menu" :aria-label="label" class="tw:overflow-y-auto" @keydown="sheetKey">
        <template v-for="(g, gi) in groups" :key="g.id">
          <hr v-if="gi > 0" class="tw:my-1.5 tw:border-border" role="separator">
          <p v-if="g.label" class="tw:px-3 tw:py-1 tw:text-xs tw:text-muted-foreground" role="presentation">{{ g.label }}</p>
          <button
            v-for="item in g.items"
            :key="item.id"
            type="button"
            role="menuitem"
            :data-action="item.id"
            :aria-disabled="item.disabled ? 'true' : undefined"
            :aria-describedby="reasonId(item)"
            :title="item.title || undefined"
            class="tw:block tw:min-h-12 tw:w-full tw:rounded-md tw:px-3 tw:py-2 tw:text-left tw:text-sm tw:hover:bg-accent tw:focus-visible:bg-accent tw:focus-visible:outline-none tw:aria-disabled:cursor-not-allowed tw:aria-disabled:opacity-70"
            :class="item.danger && 'tw:text-destructive'"
            @click="pick(item, $event)"
          >{{ item.label }}{{ item.confirm ? '…' : '' }}<span v-if="reasonId(item)" :id="reasonId(item)" class="tw:block tw:text-xs tw:text-muted-foreground">{{ item.reason }}</span></button>
        </template>
      </div>
    </SheetContent>
  </Sheet>
  <DropdownMenu v-else>
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
            v-aria-disabled="item.disabled"
            :aria-describedby="reasonId(item)"
            :title="item.title || undefined"
            class="tw:min-h-8 tw:aria-disabled:cursor-not-allowed tw:aria-disabled:opacity-70"
            @select="pick(item, $event)"
          >
            <span>{{ item.label }}{{ item.confirm ? '…' : '' }}</span>
            <span v-if="reasonId(item)" :id="reasonId(item)" class="tw:block tw:text-xs tw:text-muted-foreground">{{ item.reason }}</span>
          </DropdownMenuItem>
        </DropdownMenuGroup>
      </template>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
