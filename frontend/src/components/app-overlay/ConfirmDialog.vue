<script setup>
// Confirm template: question, existing consequence/impact text, safe cancel and explicit action.
// Focus starts on 取消 (safe default); no outside-click close (Reka AlertDialog). Primary is on the right.
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';

defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true }, // a question: 「刪除這門課？」
  description: { type: String, default: '' }, // may contain a multi-lesson renewal impact list
  confirmLabel: { type: String, default: '確認' }, // pass a verb: 「刪除」「鎖帳」
  cancelLabel: { type: String, default: '取消' },
  destructive: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'confirm']);
</script>

<template>
  <AlertDialog :open="open" @update:open="emit('update:open', $event)">
    <AlertDialogContent class="tw:max-h-[calc(100dvh-2rem)] tw:auto-rows-max tw:overflow-y-auto">
      <AlertDialogHeader>
        <AlertDialogTitle>{{ title }}</AlertDialogTitle>
        <AlertDialogDescription class="tw:whitespace-pre-line" data-testid="confirm-dialog-message">{{ description }}</AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel data-testid="confirm-dialog-cancel" :disabled="loading">{{ cancelLabel }}</AlertDialogCancel>
        <AlertDialogAction
          data-testid="confirm-dialog-confirm"
          :variant="destructive ? 'destructive' : 'default'"
          :disabled="loading"
          :aria-busy="loading || undefined"
          @click="emit('confirm')"
        >{{ confirmLabel }}</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
