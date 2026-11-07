<script setup>
// Confirm template (alert-dialog): question title, ONE consequence line, [取消] [verb button; red when destructive].
// Focus starts on 取消 (safe default); no outside-click close (Reka AlertDialog). Primary is on the right.
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';

defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true }, // a question: 「刪除這門課？」
  description: { type: String, default: '' }, // one consequence line
  confirmLabel: { type: String, default: '確認' }, // pass a verb: 「刪除」「鎖帳」
  cancelLabel: { type: String, default: '取消' },
  destructive: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'confirm']);
</script>

<template>
  <AlertDialog :open="open" @update:open="emit('update:open', $event)">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>{{ title }}</AlertDialogTitle>
        <AlertDialogDescription>{{ description }}</AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel :disabled="loading">{{ cancelLabel }}</AlertDialogCancel>
        <AlertDialogAction
          :variant="destructive ? 'destructive' : 'default'"
          :disabled="loading"
          :aria-busy="loading || undefined"
          @click="emit('confirm')"
        >{{ confirmLabel }}</AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
