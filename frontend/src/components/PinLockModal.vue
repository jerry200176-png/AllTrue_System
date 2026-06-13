<template>
  <div class="pin-lock-layer" role="dialog" aria-modal="true" aria-labelledby="pin-lock-title">
    <div class="pin-lock-card card">
      <div class="pin-lock-head">
        <span class="pin-lock-icon material-symbols-rounded" aria-hidden="true">lock</span>
        <h2 id="pin-lock-title" class="pin-lock-title">{{ title }}</h2>
        <p class="pin-lock-sub">{{ subtitle }}</p>
      </div>

      <div v-if="state === 'loading'" class="pin-lock-loading">
        <span class="material-symbols-rounded spin" aria-hidden="true">progress_activity</span>
      </div>

      <!-- 首次設定 PIN -->
      <form v-else-if="state === 'set'" class="pin-lock-form" @submit.prevent="submitSet">
        <label class="pin-lock-label" for="pin-set">設定 4–6 位數字 PIN</label>
        <input
          id="pin-set"
          ref="firstInputRef"
          v-model="pin"
          class="pin-lock-input"
          type="password"
          inputmode="numeric"
          autocomplete="off"
          maxlength="6"
          placeholder="••••"
          @input="onPinInput"
        />
        <label class="pin-lock-label" for="pin-set-confirm">再次輸入確認</label>
        <input
          id="pin-set-confirm"
          v-model="pinConfirm"
          class="pin-lock-input"
          type="password"
          inputmode="numeric"
          autocomplete="off"
          maxlength="6"
          placeholder="••••"
          @input="onPinInput"
        />
        <p v-if="error" class="pin-lock-error">{{ error }}</p>
        <button class="pin-lock-btn primary" type="submit" :disabled="busy">設定並解鎖</button>
        <button class="pin-lock-link" type="button" @click="$emit('skip')">暫不啟用，直接進入</button>
      </form>

      <!-- 輸入 PIN 解鎖 -->
      <form v-else-if="state === 'verify'" class="pin-lock-form" @submit.prevent="submitVerify">
        <label class="pin-lock-label" for="pin-verify">輸入 PIN 以存取此頁</label>
        <input
          id="pin-verify"
          ref="firstInputRef"
          v-model="pin"
          class="pin-lock-input"
          type="password"
          inputmode="numeric"
          autocomplete="off"
          maxlength="6"
          placeholder="••••"
          @input="onPinInput"
        />
        <p v-if="error" class="pin-lock-error">{{ error }}</p>
        <button class="pin-lock-btn primary" type="submit" :disabled="busy">解鎖</button>
        <button class="pin-lock-link" type="button" @click="goReset">忘記 PIN？用帳號密碼重設</button>
      </form>

      <!-- 鎖定中 -->
      <div v-else-if="state === 'locked'" class="pin-lock-form">
        <p class="pin-lock-locked-msg">嘗試次數過多，請稍後再試。</p>
        <button class="pin-lock-link" type="button" @click="goReset">用帳號密碼立即重設 PIN</button>
      </div>

      <!-- 帳密重設 PIN -->
      <form v-else-if="state === 'reset'" class="pin-lock-form" @submit.prevent="submitReset">
        <label class="pin-lock-label" for="pin-reset-pw">帳號登入密碼</label>
        <input
          id="pin-reset-pw"
          ref="firstInputRef"
          v-model="password"
          class="pin-lock-input"
          type="password"
          autocomplete="current-password"
          placeholder="輸入登入密碼"
        />
        <label class="pin-lock-label" for="pin-reset-pin">新的 4–6 位數字 PIN</label>
        <input
          id="pin-reset-pin"
          v-model="pin"
          class="pin-lock-input"
          type="password"
          inputmode="numeric"
          autocomplete="off"
          maxlength="6"
          placeholder="••••"
          @input="onPinInput"
        />
        <label class="pin-lock-label" for="pin-reset-confirm">再次輸入新 PIN</label>
        <input
          id="pin-reset-confirm"
          v-model="pinConfirm"
          class="pin-lock-input"
          type="password"
          inputmode="numeric"
          autocomplete="off"
          maxlength="6"
          placeholder="••••"
          @input="onPinInput"
        />
        <p v-if="error" class="pin-lock-error">{{ error }}</p>
        <button class="pin-lock-btn primary" type="submit" :disabled="busy">重設並解鎖</button>
        <button class="pin-lock-link" type="button" @click="backFromReset">返回</button>
      </form>

      <button class="pin-lock-dismiss" type="button" @click="$emit('dismiss')">
        返回非敏感頁
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { pinFormatError } from '../lib/pinGate';

const props = defineProps({
  token: { type: String, required: true },
});
const emit = defineEmits(['unlocked', 'dismiss', 'skip']);

const apiBase = `${import.meta.env.VITE_API_BASE || '/api'}/v1/me/pin`;

const state = ref('loading'); // loading | set | verify | locked | reset
const pin = ref('');
const pinConfirm = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);
const firstInputRef = ref(null);

const title = computed(() => {
  switch (state.value) {
    case 'set': return '設定安全 PIN';
    case 'verify': return '此頁需要 PIN 驗證';
    case 'locked': return '已暫時鎖定';
    case 'reset': return '重設 PIN';
    default: return '安全驗證';
  }
});
const subtitle = computed(() => {
  switch (state.value) {
    case 'set': return '為保護薪資／財務／個資頁面，請設定一組僅你知道的 PIN。';
    case 'verify': return '為確認是本人操作，請輸入你的 PIN。';
    case 'locked': return '為了帳號安全，多次錯誤後已暫時鎖定。';
    case 'reset': return '以帳號登入密碼驗證後即可重設 PIN 並解除鎖定。';
    default: return '';
  }
});

function authHeaders() {
  return {
    Authorization: `Bearer ${props.token}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };
}

function onPinInput() {
  // 僅保留數字
  pin.value = pin.value.replace(/\D/g, '').slice(0, 6);
  pinConfirm.value = pinConfirm.value.replace(/\D/g, '').slice(0, 6);
  error.value = '';
}

async function focusFirst() {
  await nextTick();
  firstInputRef.value?.focus?.();
}

async function loadStatus() {
  state.value = 'loading';
  try {
    const res = await fetch(`${apiBase}/status`, { headers: authHeaders() });
    if (!res.ok) {
      // 401 等 → 交給上層的 session 處理；此處保守進 verify
      state.value = 'verify';
      await focusFirst();
      return;
    }
    const data = await res.json();
    if (data.verified) {
      emit('unlocked');
      return;
    }
    if (data.locked) {
      state.value = 'locked';
    } else if (!data.pin_set) {
      state.value = 'set';
    } else {
      state.value = 'verify';
    }
    await focusFirst();
  } catch (_) {
    state.value = 'verify';
    await focusFirst();
  }
}

function localPinError(value) {
  return pinFormatError(value) || '';
}

async function submitSet() {
  error.value = '';
  const e = localPinError(pin.value);
  if (e) { error.value = e; return; }
  if (pin.value !== pinConfirm.value) { error.value = '兩次輸入的 PIN 不一致'; return; }
  busy.value = true;
  try {
    const res = await fetch(`${apiBase}/set`, {
      method: 'POST', headers: authHeaders(), body: JSON.stringify({ pin: pin.value }),
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok) { resetFields(); emit('unlocked'); return; }
    if (res.status === 409) { error.value = 'PIN 已設定，請改用「忘記 PIN」重設'; state.value = 'verify'; return; }
    error.value = data.message || 'PIN 設定失敗，請再試一次';
  } catch (_) {
    error.value = '網路異常，請稍後再試';
  } finally {
    busy.value = false;
  }
}

async function submitVerify() {
  error.value = '';
  if (!/^\d{4,6}$/.test(pin.value)) { error.value = 'PIN 須為 4 到 6 位數字'; return; }
  busy.value = true;
  try {
    const res = await fetch(`${apiBase}/verify`, {
      method: 'POST', headers: authHeaders(), body: JSON.stringify({ pin: pin.value }),
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok) { resetFields(); emit('unlocked'); return; }
    if (res.status === 429) { state.value = 'locked'; pin.value = ''; return; }
    error.value = data.message || 'PIN 錯誤';
    pin.value = '';
    await focusFirst();
  } catch (_) {
    error.value = '網路異常，請稍後再試';
  } finally {
    busy.value = false;
  }
}

async function submitReset() {
  error.value = '';
  if (!password.value) { error.value = '請輸入帳號登入密碼'; return; }
  const e = localPinError(pin.value);
  if (e) { error.value = e; return; }
  if (pin.value !== pinConfirm.value) { error.value = '兩次輸入的 PIN 不一致'; return; }
  busy.value = true;
  try {
    const res = await fetch(`${apiBase}/reset`, {
      method: 'POST', headers: authHeaders(),
      body: JSON.stringify({ password: password.value, pin: pin.value }),
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok) { resetFields(); emit('unlocked'); return; }
    if (res.status === 401) { error.value = '帳號密碼錯誤'; return; }
    error.value = data.message || 'PIN 重設失敗，請再試一次';
  } catch (_) {
    error.value = '網路異常，請稍後再試';
  } finally {
    busy.value = false;
  }
}

function goReset() { error.value = ''; pin.value = ''; pinConfirm.value = ''; password.value = ''; state.value = 'reset'; focusFirst(); }
function backFromReset() { error.value = ''; loadStatus(); }
function resetFields() { pin.value = ''; pinConfirm.value = ''; password.value = ''; error.value = ''; }

onMounted(loadStatus);
</script>

<style scoped>
.pin-lock-layer {
  position: fixed;
  inset: 0;
  z-index: 10050;
  display: grid;
  place-items: center;
  padding: 24px;
  background:
    radial-gradient(circle at 50% 38%, rgba(255, 179, 0, 0.12), transparent 34%),
    rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}

.pin-lock-card {
  width: 100%;
  max-width: 380px;
  padding: 28px 26px 22px;
  background: var(--card-bg);
  border-radius: 18px;
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.32);
  display: grid;
  gap: 18px;
}

.pin-lock-head { display: grid; justify-items: center; gap: 6px; text-align: center; }
.pin-lock-icon {
  font-size: 34px;
  color: var(--ds-primary);
  background: var(--ds-primary-wash, rgba(31, 58, 95, 0.08));
  width: 56px; height: 56px;
  display: grid; place-items: center;
  border-radius: 50%;
  margin-bottom: 4px;
}
.pin-lock-title { margin: 0; font-size: 19px; font-weight: 700; color: var(--text); }
.pin-lock-sub { margin: 0; font-size: 13px; line-height: 1.5; color: var(--text-light); }

.pin-lock-form { display: grid; gap: 10px; }
.pin-lock-label { font-size: 12.5px; font-weight: 600; color: var(--text-light); }
.pin-lock-input {
  width: 100%;
  padding: 12px 14px;
  font-size: 18px;
  letter-spacing: 0.3em;
  text-align: center;
  border: 1.5px solid var(--border);
  border-radius: 12px;
  background: var(--input-bg);
  color: var(--text);
  box-sizing: border-box;
}
.pin-lock-input:focus { outline: none; border-color: var(--ds-primary); }

.pin-lock-error { margin: 2px 0 0; font-size: 12.5px; color: var(--ds-danger); text-align: center; }
.pin-lock-locked-msg { margin: 0; font-size: 14px; color: var(--text); text-align: center; line-height: 1.6; }

.pin-lock-btn {
  margin-top: 4px;
  padding: 12px 16px;
  font-size: 15px;
  font-weight: 600;
  border: none;
  border-radius: 12px;
  cursor: pointer;
}
.pin-lock-btn.primary { background: var(--ds-primary); color: var(--ds-on-primary); }
.pin-lock-btn.primary:disabled { opacity: 0.6; cursor: not-allowed; }

.pin-lock-link {
  background: none; border: none; cursor: pointer;
  font-size: 12.5px; color: var(--ds-primary);
  text-decoration: underline; padding: 4px;
}
.pin-lock-dismiss {
  justify-self: center;
  background: none; border: none; cursor: pointer;
  font-size: 12.5px; color: var(--text-light);
  padding: 2px 6px;
}

.pin-lock-loading { display: grid; place-items: center; padding: 18px; }
.spin { font-size: 30px; color: var(--ds-primary); animation: pinSpin 1s linear infinite; }
@keyframes pinSpin { to { transform: rotate(360deg); } }
</style>
