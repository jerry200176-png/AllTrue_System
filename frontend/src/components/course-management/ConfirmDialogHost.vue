<template>
  <AtDialog
    :open="!!state"
    :title="state?.title || ''"
    title-id="confirm-dialog-title"
    size="sm"
    initial-focus="[data-initial-focus]"
    @close="settleConfirm(false)"
  >
    <p class="cdh__message" data-testid="confirm-dialog-message">{{ state?.message }}</p>
    <template #actions>
      <button type="button" class="ghost" data-testid="confirm-dialog-cancel" :data-initial-focus="state?.danger ? '' : undefined" @click="settleConfirm(false)">{{ state?.cancelLabel }}</button>
      <button
        type="button"
        :class="state?.danger ? 'danger' : 'primary'"
        data-testid="confirm-dialog-confirm"
        :data-initial-focus="state?.danger ? undefined : ''"
        @click="settleConfirm(true)"
      >{{ state?.confirmLabel }}</button>
    </template>
  </AtDialog>
</template>

<script setup>
// Destructive confirms start on 取消 (safe default); other confirms start on the action button.
import AtDialog from '../design-system/AtDialog.vue';
import { confirmState as state, settleConfirm } from '../../composables/useConfirmDialog';
</script>

<style scoped>
.cdh__message { margin: 0; white-space: pre-line; line-height: 1.6; }
</style>
