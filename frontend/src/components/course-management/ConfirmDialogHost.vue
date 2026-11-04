<template>
  <AtDialog
    :open="live && !!state"
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
import { ref, onActivated, onDeactivated } from 'vue';
import AtDialog from '../design-system/AtDialog.vue';
import { confirmState as state, settleConfirm } from '../../composables/useConfirmDialog';

// Every page has a host on the shared confirmState; a hidden kept-alive page's host must not open too (Codex P2 on #4894).
const live = ref(true);
onActivated(() => { live.value = true; });
onDeactivated(() => { live.value = false; });
</script>

<style scoped>
.cdh__message { margin: 0; white-space: pre-line; line-height: 1.6; }
</style>
