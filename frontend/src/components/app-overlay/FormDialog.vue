<script setup>
// Form template (dialog): title + 1-line description, sticky footer [取消] [主要動作], loading state,
// unsaved-changes guard on Esc / outside click (only while `dirty`). Focus starts on the first field. X is top-right (DialogContent).
import { nextTick, ref, shallowRef } from 'vue';
import { useResizeObserver } from '@vueuse/core';
import { Button } from '@/components/ui/button';
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import ConfirmDialog from './ConfirmDialog.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  description: { type: String, required: true },
  size: { type: String, default: 'md', validator: (v) => ['sm', 'md'].includes(v) },
  submitLabel: { type: String, default: '儲存' }, // verb, never 「確定」
  cancelLabel: { type: String, default: '取消' },
  loading: { type: Boolean, default: false },
  dirty: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'submit']);

const askDiscard = ref(false);
const panelElement = shallowRef(null);
const scrollWholeForm = ref(false);

// Keep a useful field viewport; oversized chrome must scroll with the fields instead of covering them.
function measureLayout() {
  const panel = panelElement.value;
  const head = panel?.querySelector('[data-slot="dialog-header"]');
  const foot = panel?.querySelector('[data-slot="dialog-footer"]');
  if (!(panel instanceof HTMLElement) || !head || !foot) return;
  const maximumHeight = parseFloat(getComputedStyle(panel).maxHeight);
  const minimumBodyHeight = parseFloat(getComputedStyle(document.documentElement).fontSize) * 4;
  scrollWholeForm.value = head.offsetHeight + foot.offsetHeight + minimumBodyHeight > maximumHeight;
}
useResizeObserver(() => [
  panelElement.value,
  panelElement.value?.querySelector('[data-slot="dialog-header"]'),
  panelElement.value?.querySelector('[data-slot="dialog-footer"]'),
], measureLayout);

function requestClose() {
  if (props.loading) return;
  if (props.dirty) askDiscard.value = true;
  else emit('update:open', false);
}
// Esc and outside click route through the guard; the X and 取消 buttons do too.
const guard = (e) => { e.preventDefault(); requestClose(); };
function discard() {
  askDiscard.value = false;
  emit('update:open', false);
}
function focusFirstField(e) {
  panelElement.value = e.target;
  measureLayout();
  const el = panelElement.value?.querySelector?.('input:not([type=hidden]):not(:disabled), select:not(:disabled), textarea:not(:disabled)');
  if (el) {
    e.preventDefault();
    nextTick(() => el.focus());
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="(v) => (v ? emit('update:open', true) : requestClose())">
    <DialogContent
      :class="[
        size === 'sm' ? 'tw:sm:max-w-sm' : 'tw:sm:max-w-xl',
        scrollWholeForm ? 'tw:block tw:overflow-y-auto' : 'tw:grid-rows-[auto_minmax(0,1fr)_auto] tw:overflow-hidden',
      ]"
      class="tw:max-h-[90dvh] tw:gap-0 tw:p-0"
      @escape-key-down="dirty && guard($event)"
      @interact-outside="dirty && guard($event)"
      @open-auto-focus="focusFirstField"
    >
      <form class="tw:contents" @submit.prevent="emit('submit')">
        <DialogHeader class="tw:p-6 tw:pb-4">
          <DialogTitle>{{ title }}</DialogTitle>
          <DialogDescription>{{ description }}</DialogDescription>
        </DialogHeader>
        <div :class="scrollWholeForm ? 'tw:overflow-visible' : 'tw:overflow-y-auto'" class="tw:px-6 tw:pb-4"><slot /></div>
        <DialogFooter :class="scrollWholeForm ? 'tw:static' : 'tw:sticky tw:bottom-0'" class="tw:border-t tw:bg-background tw:p-4 tw:sm:px-6">
          <Button type="button" variant="outline" :disabled="loading" @click="requestClose">{{ cancelLabel }}</Button>
          <Button type="submit" :disabled="loading" :aria-busy="loading || undefined">{{ loading ? `${submitLabel}中…` : submitLabel }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
  <ConfirmDialog
    v-model:open="askDiscard"
    title="放棄未儲存的變更？"
    description="離開後，剛才填的內容不會保留。"
    confirm-label="放棄變更"
    cancel-label="繼續編輯"
    destructive
    @confirm="discard"
  />
</template>
