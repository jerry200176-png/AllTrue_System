<template>
  <ConfirmDialog
    :open="live && !!state"
    :title="state?.title || ''"
    :description="state?.message || ''"
    :confirm-label="state?.confirmLabel"
    :cancel-label="state?.cancelLabel"
    :destructive="state?.danger"
    @update:open="onOpen"
    @confirm="settleConfirm(true)"
  />
</template>

<script setup>
// Host for composables/useConfirmDialog's shared confirmState, rendered by the app-overlay ConfirmDialog (alert-dialog).
// Focus starts on 取消 for every confirm (the safe default); no outside-click close.
import { ref, onActivated, onDeactivated } from 'vue';
import ConfirmDialog from '../app-overlay/ConfirmDialog.vue';
import { confirmState as state, settleConfirm } from '../../composables/useConfirmDialog';

// Every page has a host on the shared confirmState; a hidden kept-alive page's host must not open too (Codex P2 on #4894).
const live = ref(true);
onActivated(() => { live.value = true; });
onDeactivated(() => { live.value = false; });
// Reka closes the dialog around the action click; the microtask lets settleConfirm(true) from @confirm win that race.
const onOpen = (v) => { if (!v) queueMicrotask(() => settleConfirm(false)); };
</script>
