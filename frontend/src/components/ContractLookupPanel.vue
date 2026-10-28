<template>
  <div class="contract-lookup" data-testid="contract-lookup">
    <h4>用編號找合約</h4>
    <p class="muted">
      工程人員只會給你合約編號（純數字）。貼在這裡，就能看到是哪位學生的哪一份合約，並直接打開那位學生的帳務。
      每次查詢都會留下紀錄；一次最多 {{ MAX_CONTRACT_IDS }} 個。
    </p>

    <AtField label="合約編號" html-for="contract-lookup-input" hint="用逗號、空格或換行分開，例：101, 102">
      <AtTextarea id="contract-lookup-input" v-model="text" :rows="3" :error="!!inputError" placeholder="101&#10;102" />
    </AtField>
    <AtInlineAlert v-if="inputError" tone="danger"><span data-testid="lookup-error">{{ inputError }}</span></AtInlineAlert>
    <AtSubmitButton
      class="lookup-submit"
      shape="rect"
      variant="primary"
      :action="search"
      pending-text="查詢中…"
      success-text="查詢完成"
      error-text="查詢失敗"
      data-testid="lookup-submit"
    >查詢</AtSubmitButton>

    <template v-if="rows.length > 0">
      <table class="lookup-table" data-testid="lookup-table">
        <thead>
          <tr>
            <th v-for="h in HEADERS" :key="h" scope="col">{{ h }}</th>
            <th scope="col"><span class="visually-hidden">帳務</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id" data-testid="lookup-row">
            <td>{{ r.id }}</td>
            <template v-if="r.found">
              <td>{{ r.campus_name || '—' }}</td>
              <td>{{ r.student_name || '—' }}</td>
              <td>{{ r.subject }}</td>
              <td>{{ r.teacher_name || '—' }}</td>
              <td>{{ period(r) }} <AtBadge v-if="!r.enabled" tone="neutral" label="已停課" /></td>
              <td>{{ money(r.charge) }}</td>
              <td>{{ formula(r) }}</td>
              <td>{{ r.money_note || '—' }}</td>
              <td><AtButton shape="rect" size="sm" variant="secondary" data-testid="lookup-open" @click="openLedger(r)">打開帳務</AtButton></td>
            </template>
            <td v-else :colspan="HEADERS.length" data-testid="lookup-missing">找不到這個編號</td>
          </tr>
        </tbody>
      </table>

      <ul class="lookup-cards" data-testid="lookup-cards">
        <li v-for="r in rows" :key="r.id" class="lookup-card" data-testid="lookup-card">
          <strong>編號 {{ r.id }}</strong>
          <p v-if="!r.found" class="muted">找不到這個編號</p>
          <template v-else>
            <dl>
              <div v-for="[label, value] in cardFields(r)" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div>
            </dl>
            <AtButton shape="rect" size="sm" variant="secondary" data-testid="lookup-open-card" @click="openLedger(r)">打開帳務</AtButton>
          </template>
        </li>
      </ul>
    </template>

    <AccountingLedgerModal :show="ledgerOpen" :student-class-id="ledgerClassId" :branch-id="ledgerCampusId" @close="ledgerOpen = false" />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import AccountingLedgerModal from './AccountingLedgerModal.vue';
import AtBadge from './design-system/AtBadge.vue';
import AtButton from './design-system/AtButton.vue';
import AtField from './design-system/AtField.vue';
import AtInlineAlert from './design-system/AtInlineAlert.vue';
import AtSubmitButton from './design-system/AtSubmitButton.vue';
import AtTextarea from './design-system/AtTextarea.vue';
import { lookupContracts, MAX_CONTRACT_IDS, parseContractIds } from '../lib/contractLookup';

// Super admin only (the parent renders it for super_admin; the API refuses everyone else). Read-only; the ledger is the
// existing student billing view, opened for the contract's own campus.
const props = defineProps({
  token: { type: String, default: '' },
});

const HEADERS = ['編號', '分校', '學生', '科目', '老師', '期間', '目前總費用', '單價×堂數', '備註'];

const text = ref('');
const rows = ref([]);
const inputError = ref('');
const ledgerOpen = ref(false);
const ledgerClassId = ref(null);
const ledgerCampusId = ref(null);

const money = (n) => (n == null ? '—' : `NT$ ${Number(n).toLocaleString('zh-TW')}`);
const period = (r) => `${r.start_date || '—'} ~ ${r.end_date || '—'}`;
const formula = (r) => (r.rate_x_sessions == null ? '—' : `${r.rate} × ${r.quantity}${r.rate_unit === 'hour' ? ' 小時' : ' 堂'} = ${money(r.rate_x_sessions)}`);
const cardFields = (r) => [
  ['分校', r.campus_name || '—'], ['學生', r.student_name || '—'], ['科目', r.subject], ['老師', r.teacher_name || '—'],
  ['期間', period(r) + (r.enabled ? '' : '（已停課）')], ['目前總費用', money(r.charge)], ['單價×堂數', formula(r)], ['備註', r.money_note || '—'],
];

function openLedger(r) {
  ledgerClassId.value = r.id;
  ledgerCampusId.value = r.campus_id;
  ledgerOpen.value = true;
}

// A stable setup function: AtSubmitButton joins one in-flight run per action. Resolves true only once the rows arrived.
async function search() {
  const { ids, invalid } = parseContractIds(text.value);
  if (invalid.length > 0) {
    inputError.value = `這些不是合約編號：${invalid.slice(0, 5).join('、')}${invalid.length > 5 ? '…' : ''}。編號只有數字。`;
    return false;
  }
  if (ids.length === 0 || ids.length > MAX_CONTRACT_IDS) {
    inputError.value = ids.length === 0 ? '請先貼上至少一個合約編號。' : `一次最多 ${MAX_CONTRACT_IDS} 個編號，現在有 ${ids.length} 個。`;
    return false;
  }
  inputError.value = '';
  try {
    rows.value = await lookupContracts(props.token, ids);
    return true;
  } catch (e) {
    inputError.value = e.message || '查詢失敗，請稍後再試。';
    return false;
  }
}
</script>

<style scoped>
.contract-lookup { margin-top: var(--ds-space-5); padding-top: var(--ds-space-4); border-top: 1px solid var(--border); display: grid; gap: var(--ds-space-3); }
.contract-lookup h4 { margin: 0; }
.contract-lookup .muted { margin: 0; }
.lookup-submit { justify-self: start; }
.lookup-table { display: none; width: 100%; border-collapse: collapse; }
.lookup-table th,
.lookup-table td { padding: var(--ds-space-2); border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; overflow-wrap: anywhere; }
.lookup-table th { color: var(--ds-text-tertiary); font-size: var(--ds-font-size-sm); font-weight: 600; }
.lookup-cards { display: grid; gap: var(--ds-space-2); margin: 0; padding: 0; list-style: none; }
.lookup-card { display: grid; gap: var(--ds-space-2); padding: var(--ds-space-3); border: 1px solid var(--border); border-radius: var(--ds-radius-md); }
.lookup-card dl { display: grid; gap: var(--ds-space-1); margin: 0; }
.lookup-card dl div { display: grid; grid-template-columns: 6em minmax(0, 1fr); gap: var(--ds-space-2); }
.lookup-card dt { color: var(--ds-text-tertiary); }
.lookup-card dd { margin: 0; overflow-wrap: anywhere; }
.lookup-card .at-btn { justify-self: start; }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
@media (min-width: 768px) {
  .lookup-table { display: table; }
  .lookup-cards { display: none; }
}
</style>
