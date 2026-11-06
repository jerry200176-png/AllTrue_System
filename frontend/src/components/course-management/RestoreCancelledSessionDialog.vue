<template>
  <AtDialog :open="!!unit" title="復原已取消的課" title-id="restore-cancelled-title" size="sm" :close-on-backdrop="!recovery.submitting" @close="close">
    <p v-if="recovery.loading" class="modal-desc" data-testid="restore-checking">正在檢查是否可安全復原…</p>
    <template v-else-if="recovery.available">
      <p class="modal-desc">取消前狀態：{{ recovery.previousStatusLabel || recovery.previous_status }}。會同步排課、評量／點名與堂數。</p>
      <label for="restore-cancelled-reason">復原原因</label>
      <input id="restore-cancelled-reason" v-model="recovery.reason" maxlength="255" placeholder="例如：主任誤取消" />
    </template>
    <p v-else class="modal-desc" data-testid="restore-unavailable">{{ recovery.message || '這堂課目前無法復原。' }}</p>
    <p v-if="error" class="modal-desc" role="alert" data-testid="restore-error">{{ error }}</p>
    <template #actions>
      <button class="ghost" type="button" :disabled="recovery.submitting" @click="close">關閉</button>
      <button v-if="recovery.available" class="primary" type="button" data-testid="restore-submit" :disabled="!recovery.reason?.trim() || recovery.submitting" @click="submit">
        {{ recovery.submitting ? '復原中...' : '復原這堂課' }}
      </button>
    </template>
  </AtDialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import AtDialog from '../design-system/AtDialog.vue';
import { useSessionRecovery } from '../../composables/useSessionRecovery.js';

const props = defineProps({ unit: { type: Object, default: null } });
const emit = defineEmits(['close', 'restored']);
const { recovery, reset, load, restore } = useSessionRecovery();
const error = ref('');

watch(() => props.unit?.id, (id) => {
  error.value = '';
  if (id) load(id); else reset();
}, { immediate: true });

const close = () => { if (!recovery.value.submitting) emit('close'); };
const submit = async () => {
  error.value = '';
  try {
    await restore(props.unit.id);
    emit('restored');
  } catch (e) {
    error.value = e.message || '復原失敗，請重新整理後再試';
  }
};
</script>
