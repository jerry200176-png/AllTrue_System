<script setup>
// #4971 (in-app #508): one hand-entered 行政加給 (amount + reason) per part-time teacher per month.
import { ref, watch } from 'vue';
import { saveAdminBonus, deleteAdminBonus } from '../lib/parttimePayrollApi.js';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  teacherId: { type: Number, required: true },
  month: { type: String, required: true },
  amount: { type: Number, default: 0 },
  reason: { type: String, default: '' },
  locked: { type: Boolean, default: false },
});
const emit = defineEmits(['saved']);

const amt = ref(props.amount || '');
const why = ref(props.reason || '');
const busy = ref(false);
const err = ref('');
watch(() => [props.amount, props.reason], ([a, r]) => { amt.value = a || ''; why.value = r || ''; });

async function run(fn) {
  busy.value = true;
  err.value = '';
  try {
    await fn();
    emit('saved');
  } catch (e) {
    err.value = e.message;
  } finally {
    busy.value = false;
  }
}
const base = () => ({ branchId: Number(props.branchId), teacherId: props.teacherId, month: props.month });
const save = () => {
  const n = Number(amt.value);
  if (!Number.isInteger(n) || n < 0 || n > 100000) { err.value = '金額須為 0 到 100000 的整數'; return; }
  if (!why.value.trim()) { err.value = '請填寫加給原因'; return; }
  return run(() => saveAdminBonus({ ...base(), amount: n, reason: why.value.trim() }));
};
const remove = () => run(() => deleteAdminBonus(base()));
</script>

<template>
  <div class="admin-bonus" data-testid="admin-bonus">
    <label class="ab-field">行政加給 (NT$)
      <input v-model="amt" type="number" inputmode="numeric" min="0" max="100000" step="1" :disabled="locked || busy" data-testid="ab-amount" />
    </label>
    <label class="ab-field ab-reason">原因
      <input v-model="why" type="text" maxlength="100" :disabled="locked || busy" data-testid="ab-reason" />
    </label>
    <button v-if="!locked" type="button" class="ab-btn" :disabled="busy" data-testid="ab-save" @click="save">儲存</button>
    <button v-if="!locked && props.amount > 0" type="button" class="ab-btn ab-del" :disabled="busy" data-testid="ab-delete" @click="remove">刪除</button>
    <span v-if="locked" class="ab-note">本月已鎖帳，無法異動</span>
    <span v-if="err" class="ab-err" role="alert" data-testid="ab-error">{{ err }}</span>
  </div>
</template>

<style scoped>
.admin-bonus { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin-bottom: 12px; }
.ab-field { display: flex; flex-direction: column; font-size: var(--ds-font-size-xs); gap: 2px; }
.ab-field input { padding: 6px 8px; border: 1px solid var(--ds-hairline-input); border-radius: var(--ds-radius-sm); min-width: 0; width: 8rem; }
.ab-reason { flex: 1 1 10rem; }
.ab-reason input { width: 100%; }
.ab-btn { padding: 7px 14px; border-radius: var(--ds-radius-sm); border: 1px solid var(--ds-hairline-input); background: var(--ds-surface-0); min-height: 36px; }
.ab-del { color: var(--ds-danger-text); }
.ab-note, .ab-err { font-size: var(--ds-font-size-xs); flex-basis: 100%; }
.ab-err { color: var(--ds-danger-text); }
</style>
