<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal pe-modal">
      <div class="pe-header">
        <h3>登記收款</h3>
        <button class="pe-close" @click="$emit('close')" title="關閉">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <div class="pe-info">
        <span class="pe-info-item"><strong>學生</strong>{{ row?.student_name }}</span>
        <span class="pe-info-item"><strong>科目</strong>{{ row?.subject }}</span>
        <span v-if="row?.billing_period" class="pe-info-item"><strong>期別</strong>{{ row.billing_period }}</span>
        <span v-if="row?.payable_status !== 'unbilled'" class="pe-info-item"><strong>{{ row?.payable_status === 'invoiced' ? '帳單應繳' : '應繳' }}</strong>NT$ {{ Number(row?.payable_amount ?? 0).toLocaleString('zh-TW') }}</span>
        <span v-else class="pe-info-item pe-info-item--pending"><strong>應繳</strong>還沒開帳單</span>
        <span v-if="row?.estimated_amount != null" class="pe-info-item pe-info-item--muted"><strong>估算</strong>NT$ {{ Number(row.estimated_amount).toLocaleString('zh-TW') }}</span>
        <span v-if="row?.billing_period" class="pe-info-item"><strong>帳務期間</strong>{{ row.billing_period }}</span>
      </div>

      <form @submit.prevent="submit">
        <div class="pe-field">
          <label>繳費日期 <span class="required">*</span></label>
          <input type="date" v-model="form.payment_date" required :max="today" />
        </div>

        <div class="pe-field">
          <label>繳費方式 <span class="required">*</span></label>
          <div class="pe-radio-group">
            <label class="pe-radio" :class="{ active: form.payment_method === 'transfer' }">
              <input type="radio" v-model="form.payment_method" value="transfer" />
              <span class="material-symbols-outlined" style="font-size:18px">account_balance</span>
              匯款
            </label>
            <label class="pe-radio" :class="{ active: form.payment_method === 'cash' }">
              <input type="radio" v-model="form.payment_method" value="cash" />
              <span class="material-symbols-outlined" style="font-size:18px">payments</span>
              現金
            </label>
          </div>
        </div>

        <div class="pe-field" v-if="form.payment_method === 'transfer'">
          <label>帳號後5碼（選填）</label>
          <input
            type="text"
            v-model="form.account_last5"
            maxlength="5"
            inputmode="numeric"
            pattern="[0-9]*"
            placeholder="例如 45688"
            autocomplete="off"
          />
        </div>

        <div class="pe-field">
          <label>繳費金額 <span class="required">*</span></label>
          <div class="pe-amount-wrap">
            <span class="pe-amount-prefix">NT$</span>
            <input
              type="number"
              v-model.number="form.amount"
              required
              min="0"
              max="999999"
              step="1"
            />
          </div>
          <label v-if="isZeroCharge && !legacyBackfill" class="pe-check">
            <input type="checkbox" v-model="form.free_gift" data-testid="pe-free-gift" />
            這期是「{{ FREE_GIFT_LABEL }}」：登錄一張 NT$ 0 帳單（不是收款）
          </label>
          <p v-if="isZeroCharge && !legacyBackfill" class="pe-hint">沒填費用不算免費。免費才勾，要收費請先到課程管理設定。</p>
        </div>

        <div class="pe-workflow-hint" role="note" data-testid="pe-workflow-hint">
          <span class="material-symbols-outlined" aria-hidden="true">info</span>
          <span v-if="legacyBackfill" data-testid="pe-legacy-hint">送出即入帳，不需要再確認：會建立已確認的收款紀錄（沒有帳單時一併開帳單）。收齊時維持已收；少於帳單未收額時，剩下的會列為未繳。</span>
          <span v-else-if="form.free_gift">送出後直接留下一張 NT$ 0「{{ FREE_GIFT_LABEL }}」帳單，不是收款，不需要確認入帳。</span>
          <span v-else>送出後是「{{ TUITION_STATUS_CONFIG.pending_report.label }}」，按「確認入帳」才算已收。</span>
        </div>

        <div class="pe-field">
          <label>備註</label>
          <textarea v-model="form.note" rows="2" placeholder="選填…"></textarea>
        </div>

        <div v-if="submitError" class="pe-error" role="alert">{{ submitError }}</div>

        <div class="pe-actions">
          <button type="button" class="ghost" @click="$emit('close')">取消</button>
          <button type="submit" class="primary" :disabled="submitting">
            <span v-if="submitting" class="material-symbols-outlined spin" style="font-size:16px">progress_activity</span>
            {{ submitting ? '處理中…' : (form.free_gift ? `登錄${FREE_GIFT_LABEL}帳單` : '登記收款') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, computed } from 'vue';
import { backfillLegacyPayment, recordPayment } from '../lib/paymentActions.js';
import { FREE_GIFT_LABEL, REPORT_STATUS_LABELS, TUITION_STATUS_CONFIG } from '../lib/courseMoneyState.js';

const props = defineProps({
  show: { type: Boolean, default: false },
  row: { type: Object, default: null },
  // 'legacy-backfill' (Founder GO 2026-10-21): 已收過，補登收款 for a course flagged paid with no payment record.
  // Same form; the server records and confirms in one step (director-record legacy_flag_backfill).
  mode: { type: String, default: 'record' },
});
const legacyBackfill = computed(() => props.mode === 'legacy-backfill');
// One key per opened form, kept across failed submits: a retry after a lost response replays instead of paying twice.
const idempotencyKey = ref('');
function newKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  const b = globalThis.crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

const emit = defineEmits(['close', 'confirmed', 'pending', 'conflict']);

const today = computed(() => new Date().toISOString().slice(0, 10));

const form = reactive({
  payment_date: '',
  payment_method: 'transfer',
  account_last5: '',
  amount: 0,
  note: '',
  free_gift: false,
});

const submitting = ref(false);
const submitError = ref('');
const isZeroCharge = computed(() => Number(props.row?.charge ?? 0) === 0);

watch(() => props.show, (val) => {
  if (val && props.row) {
    form.payment_date = today.value;
    form.payment_method = 'transfer';
    form.account_last5 = '';
    // 補登收款 with no bill yet prefills the contract amount (editable); the server caps it by any open bill.
    form.amount = legacyBackfill.value && props.row.payable_status === 'unbilled'
      ? (Number(props.row.charge) || '')
      : props.row.payable_status === 'unbilled'
      ? ''
      : (props.row.payable_outstanding ?? props.row.payable_amount ?? '');
    form.note = '';
    form.free_gift = false;
    submitError.value = '';
    idempotencyKey.value = legacyBackfill.value ? newKey() : '';
  }
});


async function submit() {
  submitError.value = '';

  const amount = Number(form.amount);
  if (!form.payment_date || form.amount === '' || form.amount == null || !Number.isFinite(amount) || amount < 0) {
    submitError.value = '請填寫繳費日期與金額，金額不可小於 0';
    return;
  }
  // #3875: NT$0 is never a report on a course that charges; only free courses settle at 0.
  if (amount === 0 && !isZeroCharge.value) {
    submitError.value = '金額要大於 0。';
    return;
  }
  // #3991 (Founder 2026-10-13): a missing or 0 fee is never free by itself; NT$0 needs the explicit 免費／贈送 mark.
  if (amount === 0 && !form.free_gift) {
    submitError.value = `金額為 0 時請勾選「${FREE_GIFT_LABEL}」；若這門課要收費，請先到課程管理設定費用。`;
    return;
  }
  if (form.free_gift && amount !== 0) {
    submitError.value = `勾選「${FREE_GIFT_LABEL}」時金額必須是 0。`;
    return;
  }

  submitting.value = true;
  try {
    const body = {
      student_class_id: props.row.id,
      payment_date: form.payment_date,
      payment_method: form.payment_method,
      amount,
    };
    if (form.free_gift) {
      body.free_gift = true;
    }
    // Pay the bill whose outstanding prefilled the amount (payable_invoice_id), not a display invoice_id (#3875, #3888).
    const invoiceId = props.row.payable_invoice_id ?? props.row.invoice_id;
    if (invoiceId) { // the backfill pays the bill shown too (#4367), so the server cannot pick another
      body.invoice_id = invoiceId;
    }
    if (form.payment_method === 'transfer' && form.account_last5) {
      body.account_last5 = form.account_last5;
    }
    if (form.note.trim()) {
      body.note = form.note.trim();
    }

    const res = legacyBackfill.value
      ? await backfillLegacyPayment({ ...body, idempotency_key: idempotencyKey.value })
      : await recordPayment(body);
    if (!res.ok) {
      if (res.code === 'pending_report_exists') {
        submitError.value = `這門課已有${REPORT_STATUS_LABELS.pending}的回報。請先「確認入帳」或「退回」，再登記。`;
        // Refresh authoritative owner data without closing or resetting the entered amount.
        emit('conflict', res.data);
        return;
      }
      if (res.code === 'amount_exceeds_outstanding') {
        const expected = res.data?.expected_amount;
        const detail = expected != null && expected !== '' && Number.isFinite(Number(expected))
          ? `目前帳單未收餘額為 NT$${Number(expected).toLocaleString('zh-TW')}。`
          : (res.message ? `${res.message}。` : '');
        submitError.value = `${detail}這筆金額超過帳單未收餘額。請先核對實收款項與應繳金額，不要為了送出而改小實收金額。帳單金額有誤，請先核對約定費用並走帳務更正流程；款項屬於其他帳單，請分別登記。尚未完成核對前，請勿確認入帳。`;
        emit('conflict', res.data);
        return;
      }
      throw new Error(res.message);
    }

    const result = res.data;
    emit('confirmed', result);
  } catch (e) {
    submitError.value = e.message || '登錄失敗';
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.pe-modal {
  max-width: 440px;
  padding: 24px;
  animation: slideUp 0.2s ease;
}
@keyframes slideUp {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}

.pe-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.pe-header h3 { margin: 0; font-size: 18px; }
.pe-close {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--text-light);
  display: flex;
  padding: 4px;
  border-radius: 6px;
  transition: background 0.15s;
}
.pe-close:hover { background: var(--bg); color: var(--text); }

.pe-info {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  padding: 12px 14px;
  background: var(--bg);
  border-radius: 10px;
  margin-bottom: 20px;
  font-size: 14px;
}
.pe-info-item strong {
  margin-right: 6px;
  color: var(--text-light);
  font-weight: 600;
  font-size: 12px;
}
.pe-info-item--pending { color: var(--ds-warning); }
.pe-info-item--muted { color: var(--text-light); }

.pe-field {
  margin-bottom: 14px;
}
.pe-workflow-hint {
  display: flex;
  align-items: flex-start;
  gap: 7px;
  margin: 2px 0 14px;
  padding: 10px 12px;
  border: 1px solid var(--ds-warning);
  border-radius: 8px;
  background: var(--ds-warning-wash);
  color: var(--ds-ink);
  font-size: 12px;
  line-height: 1.5;
}
.pe-workflow-hint .material-symbols-outlined {
  flex: 0 0 auto;
  font-size: 17px;
  color: var(--ds-warning);
}
.pe-field label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 5px;
  color: var(--text);
}
.required { color: var(--danger); }
.pe-field input[type="date"],
.pe-field input[type="text"],
.pe-field input[type="number"],
.pe-field textarea {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  background: var(--card-bg);
  color: var(--text);
  transition: border-color 0.15s;
  outline: none;
  box-sizing: border-box;
}
.pe-field input:focus,
.pe-field textarea:focus {
  border-color: var(--primary-light);
  box-shadow: 0 0 0 2px var(--primary-bg);
}
.pe-field textarea { resize: vertical; }

.pe-radio-group {
  display: flex;
  gap: 8px;
}
.pe-radio {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 10px 12px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
  transition: all 0.15s;
  background: var(--card-bg);
  color: var(--text);
}
.pe-radio input { display: none; }
.pe-radio:hover { border-color: var(--primary-light); }
.pe-radio.active {
  border-color: var(--primary);
  background: var(--primary-bg);
  color: var(--ds-primary-text);
  font-weight: 600;
}

.pe-amount-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.pe-amount-prefix {
  position: absolute;
  left: 12px;
  font-size: 14px;
  font-weight: 600;
  color: var(--text-light);
  pointer-events: none;
}
.pe-amount-wrap input {
  padding-left: 44px !important;
  font-variant-numeric: tabular-nums;
}
.pe-check {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
}
.pe-hint {
  margin: 6px 0 0;
  font-size: 12px;
  color: var(--text-light);
}

.pe-error {
  color: var(--danger);
  font-size: 13px;
  margin-bottom: 12px;
  padding: 8px 12px;
  background: var(--danger-bg);
  border-radius: 8px;
}

.pe-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 8px;
}

.spin { animation: rotate 1s linear infinite; }
@keyframes rotate { to { transform: rotate(360deg); } }
</style>
