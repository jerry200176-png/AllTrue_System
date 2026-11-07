<script setup>
// Reka closes the dialog around the Action click; the microtask lets settle(true) from @confirm win that race.
import ConfirmDialog from './ConfirmDialog.vue';
import { confirmState, settle } from './useConfirm.js';
const onOpen = (v) => { if (!v) queueMicrotask(() => settle(false)); };
</script>

<template>
  <ConfirmDialog
    :open="!!confirmState"
    :title="confirmState?.title || ''"
    :description="confirmState?.description || ''"
    :confirm-label="confirmState?.confirmLabel"
    :destructive="confirmState?.destructive"
    @update:open="onOpen"
    @confirm="settle(true)"
  />
</template>
