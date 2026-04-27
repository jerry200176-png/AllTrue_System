<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal pe-modal">
      <div class="pe-header">
        <h3>核帳登記</h3>
        <button class="pe-close" @click="$emit('close')" title="關閉">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <div class="pe-info">
        <span class="pe-info-item"><strong>學生</strong>{{ row?.student_name }}</span>
        <span class="pe-info-item"><strong>科目</strong>{{ row?.subject }}</span>
        <span class="pe-info-item"><strong>應繳</strong>NT$ {{ Number(row?.charge || 0).toLocaleString('zh-TW') }}</span>
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
          <p v-if="isZeroCharge" class="pe-hint">免費課程，金額為 NT$ 0，確認後會標記為已結算。</p>
        </div>

        <div class="pe-field">
          <label>備註</label>
          <textarea v-model="form.note" rows="2" placeholder="選填…"></textarea>
        </div>

        <div v-if="submitError" class="pe-error">{{ submitError }}</div>

        <div class="pe-actions">
          <button type="button" class="ghost" @click="$emit('close')">取消</button>
          <button type="submit" class="primary" :disabled="submitting">
            <span v-if="submitting" class="material-symbols-outlined spin" style="font-size:16px">progress_activity</span>
            {{ submitting ? '處理中…' : '確認核帳' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, computed } from 'vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  row: { type: Object, default: null },
});

const emit = defineEmits(['close', 'confirmed']);

const today = computed(() => new Date().toISOString().slice(0, 10));

const form = reactive({
  payment_date: '',
  payment_method: 'transfer',
  account_last5: '',
  amount: 0,
  note: '',
});

const submitting = ref(false);
const submitError = ref('');
const isZeroCharge = computed(() => Number(props.row?.charge ?? 0) === 0);

watch(() => props.show, (val) => {
  if (val && props.row) {
    form.payment_date = today.value;
    form.payment_method = 'transfer';
    form.account_last5 = '';
    form.amount = props.row.charge || 0;
    form.note = '';
    submitError.value = '';
  }
});

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

async function submit() {
  submitError.value = '';

  const amount = Number(form.amount);
  if (!form.payment_date || form.amount === '' || form.amount == null || !Number.isFinite(amount) || amount < 0) {
    submitError.value = '請填寫繳費日期與金額，金額不可小於 0';
    return;
  }

  submitting.value = true;
  try {
    const token = getToken();
    if (!token) { submitError.value = '請先登入'; return; }

    const body = {
      student_class_id: props.row.id,
      payment_date: form.payment_date,
      payment_method: form.payment_method,
      amount,
    };
    if (form.payment_method === 'transfer' && form.account_last5) {
      body.account_last5 = form.account_last5;
    }
    if (form.note.trim()) {
      body.note = form.note.trim();
    }

    const resp = await fetch('/api/v1/payment-reports/director-record', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify(body),
    });

    if (!resp.ok) {
      const data = await resp.json().catch(() => ({}));
      throw new Error(data.message || `核帳失敗（${resp.status}）`);
    }

    const result = await resp.json();
    emit('confirmed', result);
  } catch (e) {
    submitError.value = e.message || '核帳失敗';
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

.pe-field {
  margin-bottom: 14px;
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
  color: var(--primary);
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
