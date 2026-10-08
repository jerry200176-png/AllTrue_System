<template>
  <section class="alloc" data-testid="alloc" aria-labelledby="alloc-title">
    <header class="alloc__head">
      <button type="button" class="alloc__back" data-testid="alloc-back" @click="$emit('close')">← 回{{ studentName }}的帳務</button>
      <h4 id="alloc-title">登記收款・{{ studentName }}</h4>
    </header>

    <!-- Step 1: fill in -->
    <form v-if="view === 'form'" class="alloc__form" @submit.prevent="submit">
      <div class="alloc__fields">
        <label class="alloc__field">繳費日期
          <input v-model="form.payment_date" type="date" :max="today" required data-testid="alloc-date" />
        </label>
        <fieldset class="alloc__field alloc__method">
          <legend>方式</legend>
          <label><input v-model="form.payment_method" type="radio" value="transfer" /> 匯款</label>
          <label><input v-model="form.payment_method" type="radio" value="cash" /> 現金</label>
        </fieldset>
        <label v-if="form.payment_method === 'transfer'" class="alloc__field">帳號後 5 碼（選填）
          <input v-model="form.account_last5" inputmode="numeric" maxlength="5" pattern="[0-9]*" data-testid="alloc-last5" />
        </label>
      </div>

      <label v-if="payable.length > 1" class="alloc__field">實收總額（選填，從最舊的合約開始分配）
        <input v-model="form.total" type="number" inputmode="numeric" min="0" step="1" data-testid="alloc-total" @input="applyTotal" />
      </label>

      <div class="alloc__rows" role="group" aria-label="每份合約這次收多少">
        <div v-for="c in payable" :key="c.id" class="alloc__row" :data-testid="`alloc-row-${c.id}`">
          <div class="alloc__what">
            <strong>{{ c.subject || '課程' }}</strong>
            <small>{{ period(c) }}</small>
          </div>
          <div class="alloc__owed">
            <small>未繳</small>
            <span>{{ c.owed > 0 ? money(c.owed) : '還沒開帳單' }}</span>
          </div>
          <label class="alloc__amount">
            <small>這次收</small>
            <input v-model="amounts[c.id]" type="number" inputmode="numeric" min="0" step="1" :aria-label="`${c.subject || '課程'} 這次收多少`" :aria-invalid="!!errors[c.id]" :data-testid="`alloc-amount-${c.id}`" />
          </label>
          <p v-if="errors[c.id]" class="alloc__err" role="alert" :data-testid="`alloc-error-${c.id}`">{{ errors[c.id] }}</p>
        </div>
      </div>

      <p v-if="totalError" class="alloc__err" role="alert" data-testid="alloc-total-error">{{ totalError }}</p>

      <label class="alloc__field">備註（選填）
        <input v-model="form.note" maxlength="500" data-testid="alloc-note" />
      </label>

      <p class="alloc__hint">送出後會顯示「家長說繳了，等你確認」，你在這裡按「確認入帳」才算收到。</p>
      <p v-if="submitError" class="alloc__err" role="alert" data-testid="alloc-submit-error">{{ submitError }}</p>

      <footer class="alloc__foot">
        <span class="alloc__sum" data-testid="alloc-sum">合計 <strong>{{ money(total) }}</strong></span>
        <AtButton variant="secondary" shape="rect" @click="$emit('close')">取消</AtButton>
        <AtButton type="submit" shape="rect" :disabled="!canSubmit" :loading="submitting" data-testid="alloc-submit">登記收款</AtButton>
      </footer>
    </form>

    <!-- Step 2: per-contract result (the batch is not all-or-nothing) -->
    <div v-else class="alloc__result" data-testid="alloc-result">
      <p class="alloc__verdict" role="status" data-testid="alloc-verdict">{{ verdict }}</p>
      <ul class="alloc__list">
        <li v-for="r in results" :key="r.id" :class="r.ok ? 'is-ok' : 'is-fail'" :data-testid="`alloc-result-${r.id}`">
          <span v-if="r.ok">{{ r.subject }} 已登記 {{ money(r.amount) }}</span>
          <span v-else>{{ r.subject }} 沒有登記：{{ r.message }}</span>
        </li>
      </ul>
      <footer class="alloc__foot">
        <AtButton v-if="failed.length && accepted" variant="secondary" shape="rect" :loading="submitting" data-testid="alloc-retry" @click="retryFailed">再試一次（只送沒登記的）</AtButton>
        <AtButton v-if="failed.length && !accepted" variant="secondary" shape="rect" data-testid="alloc-edit" @click="view = 'form'">回去修改</AtButton>
        <AtButton v-if="accepted" shape="rect" data-testid="alloc-done" @click="$emit('done')">完成</AtButton>
      </footer>
    </div>
  </section>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import AtButton from '../design-system/AtButton.vue';
import { recordPaymentBatch } from '../../lib/paymentActions.js';
import { humanizeApiErrorMessage } from '../../lib/humanizeApiErrorMessage.js';
import { formatDate } from '../../lib/billingDocumentView.js';

// PRD v2 D18/D19: one amount box per contract, the oldest filled first, never above what is owed.
// Sends the existing director-record-batch request; no money rule lives here beyond the default allocation.
const props = defineProps({
  studentName: { type: String, default: '學生' },
  // Oldest first: { id, subject, start_date, end_date, owed, invoice_id }
  contracts: { type: Array, default: () => [] },
});
defineEmits(['close', 'done']);

const today = new Date().toISOString().slice(0, 10);
const money = (v) => 'NT$ ' + Number(v || 0).toLocaleString('zh-TW');
const period = (c) => [c.start_date, c.end_date].filter(Boolean).map(formatDate).join('–');

const payable = computed(() => props.contracts);
const form = reactive({ payment_date: today, payment_method: 'transfer', account_last5: '', note: '', total: '' });
const amounts = reactive({});
// Default: the oldest contract that has something owed gets its full balance.
const firstOwing = payable.value.find((c) => c.owed > 0);
payable.value.forEach((c) => { amounts[c.id] = c === firstOwing ? String(c.owed) : ''; });

const view = ref('form');
const submitting = ref(false);
const submitError = ref('');
const results = ref([]);
const entryBody = ref(null);

const num = (v) => (v === '' || v == null ? 0 : Number(v));
const errors = computed(() => Object.fromEntries(payable.value.map((c) => {
  const n = num(amounts[c.id]);
  if (!Number.isFinite(n) || n < 0 || !Number.isInteger(n)) return [c.id, '請填 0 以上的整數'];
  if (c.owed > 0 && n > c.owed) return [c.id, `超過這份合約未繳的 ${money(c.owed)}，請改成 ${c.owed.toLocaleString('zh-TW')} 以下`];
  return [c.id, ''];
}).filter(([, e]) => e)));
const total = computed(() => payable.value.reduce((t, c) => t + (errors.value[c.id] ? 0 : num(amounts[c.id])), 0));

// 實收總額 redistributes oldest-first; anything above the combined balance is refused (D19).
const totalError = ref('');
function applyTotal() {
  totalError.value = '';
  if (form.total === '') return;
  let left = num(form.total);
  for (const c of payable.value) {
    const take = c.owed > 0 ? Math.min(c.owed, left) : 0;
    amounts[c.id] = take > 0 ? String(take) : '';
    left -= take;
  }
  if (left > 0) {
    const all = payable.value.reduce((t, c) => t + c.owed, 0);
    totalError.value = `超過全部合約未繳的合計 ${money(all)}，請改成 ${all.toLocaleString('zh-TW')} 以下`;
  }
}

const canSubmit = computed(() => !submitting.value && !!form.payment_date && form.payment_date <= today
  && total.value > 0 && !Object.keys(errors.value).length && !totalError.value);

function buildEntries(ids) {
  return payable.value.filter((c) => (!ids || ids.includes(c.id)) && num(amounts[c.id]) > 0).map((c) => ({
    student_class_id: c.id,
    amount: num(amounts[c.id]),
    ...(c.invoice_id ? { invoice_id: c.invoice_id } : {}),
    ...(form.payment_method === 'transfer' && form.account_last5 ? { account_last5: form.account_last5 } : {}),
  }));
}

const reasonOf = (r) => (r.code === 'pending_report_exists'
  ? '這份合約已經有一筆等你確認的回報，請先確認或退回'
  : humanizeApiErrorMessage(r.message || '登記失敗'));

async function send(entries) {
  submitting.value = true;
  submitError.value = '';
  try {
    entryBody.value = {
      payment_date: form.payment_date,
      payment_method: form.payment_method,
      ...(form.note.trim() ? { note: form.note.trim() } : {}),
      entries,
    };
    const res = await recordPaymentBatch(entryBody.value);
    const list = Array.isArray(res.data?.results) ? res.data.results : null;
    if (!res.ok && !list) { submitError.value = res.message || '登記失敗，請稍後再試'; return false; }
    const byClass = new Map((list || []).map((r) => [Number(r.student_class_id), r]));
    const next = entries.map((e) => {
      const c = payable.value.find((x) => x.id === e.student_class_id);
      const r = byClass.get(Number(e.student_class_id));
      const ok = Boolean(r) && r.http_status < 300;
      return { id: e.student_class_id, subject: c?.subject || '課程', amount: e.amount, ok, message: ok ? '' : reasonOf(r || {}) };
    });
    // keep earlier successes when retrying only the failed rows
    const kept = results.value.filter((r) => r.ok && !next.some((n) => n.id === r.id));
    results.value = [...kept, ...next];
    view.value = 'result';
    return true;
  } catch {
    submitError.value = '連線失敗，請稍後再試';
    return false;
  } finally {
    submitting.value = false;
  }
}

function submit() {
  if (!canSubmit.value) return;
  results.value = [];
  return send(buildEntries());
}
function retryFailed() {
  const ids = results.value.filter((r) => !r.ok).map((r) => r.id);
  return send(buildEntries(ids));
}

const accepted = computed(() => results.value.filter((r) => r.ok).length);
const failed = computed(() => results.value.filter((r) => !r.ok));
const verdict = computed(() => {
  if (!failed.value.length) return `已登記 ${accepted.value} 份合約，等你確認。`;
  if (!accepted.value) return '都沒有登記成功，沒有任何金額被記下。';
  return `只有部分登記成功：${accepted.value} 份已登記、${failed.value.length} 份沒有登記。`;
});
</script>

<style scoped>
.alloc{display:grid;gap:14px}
.alloc__head{display:grid;gap:6px}
.alloc__head h4{margin:0;font-size:18px}
.alloc__back{justify-self:start;min-height:44px;border:0;background:transparent;color:var(--ds-primary-text);font:inherit;font-weight:600;cursor:pointer;padding:0}
.alloc__form,.alloc__result{display:grid;gap:14px}
.alloc__fields{display:flex;flex-wrap:wrap;gap:10px 16px}
.alloc__field{display:grid;gap:4px;font-size:13px;color:var(--ds-ink-mute);border:0;padding:0;margin:0}
.alloc__field input:not([type=radio]){min-height:44px;font:inherit;padding:6px 10px;box-sizing:border-box}
.alloc__method{display:flex;gap:12px;align-items:center}
.alloc__method legend{float:left;margin-right:8px}
.alloc__method label{white-space:nowrap;display:flex;gap:4px;align-items:center;min-height:44px;color:var(--ds-ink)}
.alloc__rows{display:grid;gap:8px}
.alloc__row{display:grid;grid-template-columns:1fr auto 9em;gap:4px 12px;align-items:center;padding:10px 12px;border:1px solid var(--ds-border);border-radius:var(--ds-radius-lg,8px)}
.alloc__what{display:grid;min-width:0}
.alloc__what small,.alloc__owed small,.alloc__amount small{font-size:12px;color:var(--ds-ink-mute)}
.alloc__owed{display:grid;justify-items:end;font-variant-numeric:tabular-nums}
.alloc__amount{display:grid;gap:2px}
.alloc__amount input{min-height:44px;font:inherit;text-align:right;padding:6px 10px;box-sizing:border-box;width:100%;font-variant-numeric:tabular-nums}
.alloc__err{grid-column:1/-1;margin:0;color:var(--ds-danger);font-size:13px}
.alloc__hint{margin:0;font-size:13px;color:var(--ds-ink-mute)}
.alloc__foot{position:sticky;bottom:-32px;display:flex;align-items:center;justify-content:flex-end;gap:8px;margin:0 -22px -32px;padding:10px 22px calc(10px + env(safe-area-inset-bottom,0px));background:var(--surface,var(--ds-canvas));border-top:1px solid var(--ds-border);z-index:2}
.alloc__sum{margin-right:auto;font-size:14px}
.alloc__sum strong{font-size:18px;font-variant-numeric:tabular-nums}
.alloc__verdict{margin:0;font-weight:700}
.alloc__list{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.alloc__list li{padding:8px 12px;border:1px solid var(--ds-border);border-radius:var(--ds-radius-lg,8px)}
.alloc__list li.is-ok{color:var(--ds-success)}
.alloc__list li.is-fail{color:var(--ds-danger)}
@media (max-width:768px){
  .alloc__row{grid-template-columns:1fr 1fr}
  .alloc__what{grid-column:1/-1}
  .alloc__foot{bottom:-16px;margin:0 -16px -16px;padding:10px 16px calc(10px + env(safe-area-inset-bottom,0px))}
}
</style>
