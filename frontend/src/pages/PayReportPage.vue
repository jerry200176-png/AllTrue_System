<template>
  <div class="pr-page">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet" />

    <!-- Loading -->
    <div class="pr-card pr-center" v-if="loading">
      <span class="material-symbols-outlined spin" style="font-size:40px;color:var(--primary)">progress_activity</span>
      <p style="color:var(--text-light);margin-top:12px">載入中…</p>
    </div>

    <!-- Error (expired / invalid) -->
    <div class="pr-card pr-center" v-else-if="pageError">
      <span class="material-symbols-outlined" style="font-size:48px;color:var(--danger)">link_off</span>
      <h3 style="margin:12px 0 4px">無法開啟</h3>
      <p class="pr-error-text">{{ pageError }}</p>
      <p style="font-size:13px;color:var(--text-light);margin-top:12px">請聯繫老師重新產生繳費回報連結</p>
    </div>

    <!-- Already submitted -->
    <div class="pr-card pr-center" v-else-if="submitted">
      <span class="material-symbols-outlined" style="font-size:56px;color:var(--success)">check_circle</span>
      <h3 style="margin:12px 0 4px">繳費回報已送出</h3>
      <p style="color:var(--text-light)">請等待會計確認入帳後，系統將開立電子收據</p>
    </div>

    <!-- Form -->
    <template v-else-if="formData">
      <div class="pr-card">
        <div class="pr-brand-bar">
          <span class="material-symbols-outlined" style="font-size:24px">receipt_long</span>
          <span>繳費回報</span>
        </div>

        <!-- Read-only info -->
        <div class="pr-info-grid">
          <div class="pr-info-item">
            <span class="pr-info-label">學生姓名</span>
            <span class="pr-info-value">{{ formData.student_name }}</span>
          </div>
          <div class="pr-info-item">
            <span class="pr-info-label">科目</span>
            <span class="pr-info-value">{{ formData.subject }}</span>
          </div>
          <div class="pr-info-item" v-if="formData.period_start && formData.period_end">
            <span class="pr-info-label">學費期間</span>
            <span class="pr-info-value">{{ formData.period_start }} ~ {{ formData.period_end }}</span>
          </div>
          <div class="pr-info-item" v-if="formData.session_count">
            <span class="pr-info-label">堂數</span>
            <span class="pr-info-value">{{ formData.session_count }} 堂</span>
          </div>
          <div class="pr-info-item">
            <span class="pr-info-label">應繳金額</span>
            <span class="pr-info-value pr-amount">NT$ {{ Number(formData.charge || 0).toLocaleString('zh-TW') }}</span>
          </div>
        </div>

        <div class="pr-divider"></div>

        <!-- Editable fields -->
        <div class="pr-form">
          <div class="pr-field">
            <label>繳費日期 <span class="required">*</span></label>
            <input type="date" v-model="form.payment_date" :max="todayStr" />
          </div>

          <div class="pr-field">
            <label>繳費方式 <span class="required">*</span></label>
            <div class="pr-method-btns">
              <button
                :class="['pr-method-btn', { active: form.payment_method === 'transfer' }]"
                @click="form.payment_method = 'transfer'"
              >
                <span class="material-symbols-outlined">account_balance</span>
                匯款
              </button>
              <button
                :class="['pr-method-btn', { active: form.payment_method === 'cash' }]"
                @click="form.payment_method = 'cash'"
              >
                <span class="material-symbols-outlined">payments</span>
                現金
              </button>
            </div>
          </div>

          <div class="pr-field" v-if="form.payment_method === 'transfer'">
            <label>帳號後5碼 <span class="required">*</span></label>
            <input
              type="text"
              v-model="form.account_last5"
              maxlength="5"
              inputmode="numeric"
              placeholder="請輸入匯款帳號後5碼"
              pattern="[0-9]*"
            />
          </div>

          <div class="pr-field">
            <label>實際繳費金額 <span class="required">*</span></label>
            <div class="pr-amount-input-wrap">
              <span class="pr-currency">NT$</span>
              <input
                type="number"
                v-model.number="form.reported_amount"
                min="1"
                inputmode="numeric"
                placeholder="請輸入金額"
              />
            </div>
          </div>

          <p v-if="submitError" class="pr-submit-error">{{ submitError }}</p>

          <button class="pr-submit-btn" @click="submitReport" :disabled="submitting">
            <span v-if="submitting" class="material-symbols-outlined spin" style="font-size:18px">progress_activity</span>
            <span class="material-symbols-outlined" v-else>send</span>
            {{ submitting ? '提交中…' : '送出繳費回報' }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const loading = ref(true);
const pageError = ref('');
const formData = ref(null);
const submitted = ref(false);
const submitting = ref(false);
const submitError = ref('');

const todayStr = new Date().toISOString().slice(0, 10);
const form = ref({
  payment_date: todayStr,
  payment_method: 'transfer',
  account_last5: '',
  reported_amount: null,
});

let tokenFromUrl = '';

onMounted(async () => {
  const params = new URLSearchParams(window.location.search);
  tokenFromUrl = params.get('token') || '';

  if (!tokenFromUrl) {
    pageError.value = '缺少驗證 token';
    loading.value = false;
    return;
  }

  try {
    const resp = await fetch(`/api/v1/pay-report?token=${encodeURIComponent(tokenFromUrl)}`, {
      headers: { Accept: 'application/json' },
    });
    if (!resp.ok) {
      const body = await resp.json().catch(() => ({}));
      pageError.value = body.message || '連結已失效';
      loading.value = false;
      return;
    }
    const json = await resp.json();
    formData.value = json;
    if (json.charge) {
      form.value.reported_amount = json.charge;
    }
  } catch (e) {
    pageError.value = e.message || '載入失敗';
  } finally {
    loading.value = false;
  }
});

async function submitReport() {
  submitError.value = '';

  if (!form.value.payment_date) {
    submitError.value = '請選擇繳費日期';
    return;
  }
  if (!form.value.reported_amount || form.value.reported_amount < 1) {
    submitError.value = '請輸入繳費金額';
    return;
  }
  if (form.value.payment_method === 'transfer' && (!form.value.account_last5 || form.value.account_last5.length < 3)) {
    submitError.value = '請輸入帳號後5碼（至少3碼）';
    return;
  }

  submitting.value = true;
  try {
    const resp = await fetch('/api/v1/pay-report', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        token: tokenFromUrl,
        payment_date: form.value.payment_date,
        payment_method: form.value.payment_method,
        reported_amount: form.value.reported_amount,
        account_last5: form.value.payment_method === 'transfer' ? form.value.account_last5 : null,
      }),
    });
    if (!resp.ok) {
      const body = await resp.json().catch(() => ({}));
      submitError.value = body.message || '提交失敗';
      return;
    }
    submitted.value = true;
  } catch (e) {
    submitError.value = e.message || '提交失敗';
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.pr-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  padding: 24px 12px;
  background: var(--bg, #f5f5f5);
  font-family: 'Noto Sans TC', 'Inter', sans-serif;
}
.pr-card {
  background: var(--card-bg, #fff);
  border-radius: 16px;
  box-shadow: 0 2px 16px rgba(0,0,0,0.06);
  width: 100%;
  max-width: 480px;
  overflow: hidden;
}
.pr-center {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 48px 24px;
  text-align: center;
}
.pr-error-text {
  color: var(--danger, #c62828);
  font-size: 14px;
}

.pr-brand-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 16px 20px;
  background: linear-gradient(135deg, #1565C0, #42A5F5);
  color: #fff;
  font-size: 18px;
  font-weight: 600;
}

.pr-info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0;
  padding: 16px 20px;
}
.pr-info-item {
  padding: 8px 0;
  border-bottom: 1px solid var(--border, #eee);
}
.pr-info-item:nth-last-child(-n+2) {
  border-bottom: none;
}
.pr-info-label {
  display: block;
  font-size: 11px;
  color: var(--text-light, #90a4ae);
  font-weight: 500;
  margin-bottom: 2px;
}
.pr-info-value {
  font-size: 15px;
  font-weight: 600;
  color: var(--text, #263238);
}
.pr-amount {
  color: var(--primary, #1565C0);
  font-size: 18px;
}

.pr-divider {
  height: 1px;
  background: var(--border, #E0E0E0);
  margin: 0 20px;
}

.pr-form {
  padding: 20px;
}
.pr-field {
  margin-bottom: 18px;
}
.pr-field label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: var(--text, #263238);
  margin-bottom: 6px;
}
.required {
  color: var(--danger, #c62828);
}
.pr-field input[type="date"],
.pr-field input[type="text"],
.pr-field input[type="number"] {
  width: 100%;
  padding: 10px 14px;
  border: 1px solid var(--border, #ccc);
  border-radius: 10px;
  font-size: 15px;
  font-family: inherit;
  background: var(--bg, #fafafa);
  color: var(--text, #263238);
  outline: none;
  transition: border-color 0.15s;
  box-sizing: border-box;
}
.pr-field input:focus {
  border-color: var(--primary, #1565C0);
  box-shadow: 0 0 0 2px rgba(21,101,192,0.12);
}

.pr-method-btns {
  display: flex;
  gap: 10px;
}
.pr-method-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 12px;
  border: 2px solid var(--border, #ddd);
  border-radius: 12px;
  background: var(--bg, #fafafa);
  font-size: 15px;
  font-weight: 600;
  color: var(--text-light, #78909c);
  cursor: pointer;
  transition: all 0.15s;
  font-family: inherit;
}
.pr-method-btn:hover {
  border-color: var(--primary-light, #90caf9);
}
.pr-method-btn.active {
  border-color: var(--primary, #1565C0);
  background: var(--primary-bg, #e3f2fd);
  color: var(--primary, #1565C0);
}
.pr-method-btn .material-symbols-outlined {
  font-size: 20px;
}

.pr-amount-input-wrap {
  display: flex;
  align-items: center;
  gap: 0;
  border: 1px solid var(--border, #ccc);
  border-radius: 10px;
  background: var(--bg, #fafafa);
  overflow: hidden;
}
.pr-currency {
  padding: 10px 12px;
  font-size: 14px;
  font-weight: 600;
  color: var(--text-light, #78909c);
  background: var(--border, #eee);
}
.pr-amount-input-wrap input {
  border: none !important;
  border-radius: 0 !important;
  background: transparent !important;
  flex: 1;
  box-shadow: none !important;
}

.pr-submit-error {
  color: var(--danger, #c62828);
  font-size: 13px;
  margin-bottom: 12px;
  padding: 8px 12px;
  background: var(--danger-bg, #ffebee);
  border-radius: 8px;
}

.pr-submit-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 14px;
  border: none;
  border-radius: 12px;
  background: linear-gradient(135deg, #1565C0, #42A5F5);
  color: #fff;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.15s;
  font-family: inherit;
}
.pr-submit-btn:hover {
  opacity: 0.9;
}
.pr-submit-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.spin { animation: rotate 1s linear infinite; }
@keyframes rotate { to { transform: rotate(360deg); } }
</style>
