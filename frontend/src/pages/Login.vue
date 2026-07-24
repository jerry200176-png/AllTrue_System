<template>
  <div v-if="mode === 'login'" class="login-wrap">
    <div class="login-card">
      <div class="login-brand-bar" aria-hidden="true" />
      <div class="login-header">
        <div class="logo-wrapper">
          <img :src="logoUrl" alt="全真一對一 Logo" class="login-logo" onerror="this.style.display='none'" />
        </div>
        <h1 class="login-title">台北全真一對一</h1>
        <p class="login-subtitle">教務管理系統</p>
        <p class="login-trust">教務專用 · 請使用校方核發帳號登入</p>
      </div>

      <div v-if="error" class="login-error" role="alert">
        <div>{{ error }}</div>
        <span v-if="lockSeconds > 0" class="login-error-hint">系統已暫時鎖定，請待倒數結束後再嘗試。</span>
      </div>

      <form class="login-form" @submit.prevent="handleLogin">
        <div class="form-group">
          <label id="login-role-label">登入身分</label>
          <div class="login-role-switch" role="radiogroup" aria-labelledby="login-role-label">
            <button
              type="button"
              class="role-btn"
              :class="{ active: selectedRole === 'teacher' }"
              :aria-pressed="selectedRole === 'teacher'"
              @click="selectedRole = 'teacher'"
            >
              <span class="role-title">老師</span>
              <span class="role-subtitle">課表與評量</span>
            </button>
            <button
              type="button"
              class="role-btn"
              :class="{ active: selectedRole === 'director' }"
              :aria-pressed="selectedRole === 'director'"
              @click="selectedRole = 'director'"
            >
              <span class="role-title">主任/櫃台</span>
              <span class="role-subtitle">含管理員 · 同入口</span>
            </button>
          </div>
        </div>
        <div class="form-group">
          <label for="login-account">{{ accountLabel }}</label>
          <input
            id="login-account"
            v-model="account"
            type="text"
            :placeholder="accountPlaceholder"
            autocomplete="username"
          />
        </div>
        <div class="form-group">
          <label for="login-password">{{ passwordLabel }}</label>
          <input
            id="login-password"
            v-model="password"
            type="password"
            :placeholder="passwordPlaceholder"
            autocomplete="current-password"
          />
        </div>
        <button type="submit" class="login-btn" :disabled="isLoginDisabled">
          {{ loginButtonText }}
        </button>
      </form>

      <div class="login-footer">
          <button type="button" class="login-footer-btn" @click="mode = 'register'">老師註冊</button>
          <button type="button" class="login-footer-btn" @click="mode = 'director-register'">主任申請</button>
          <button type="button" class="login-footer-btn" @click="openForgotPassword">忘記密碼</button>
      </div>
    </div>
  </div>

  <Register v-else-if="mode === 'register'" @switch-mode="mode = $event" />
  <DirectorRegister v-else-if="mode === 'director-register'" @switch-mode="mode = $event" />
  <div v-else-if="mode === 'forgot-password'" class="login-wrap">
    <div class="login-card">
      <div class="login-brand-bar" aria-hidden="true" />
      <div class="login-header">
        <div class="logo-wrapper">
          <img :src="logoUrl" alt="全真一對一 Logo" class="login-logo" onerror="this.style.display='none'" />
        </div>
        <h1 class="login-title">忘記密碼</h1>
        <p class="login-subtitle">送出申請後請由主任或管理員協助重設</p>
        <p class="login-trust">申請送出後不會自動改密碼，需管理端協助</p>
      </div>

      <div v-if="forgotError" class="login-error" role="alert">
        {{ forgotError }}
      </div>
      <div v-if="forgotSuccess" class="login-first-time" role="status">
        {{ forgotSuccess }}
      </div>

      <form class="login-form" @submit.prevent="handleForgotPassword">
        <div class="form-group">
          <label for="forgot-account">帳號（登入帳號或姓名）</label>
          <input
            id="forgot-account"
            v-model="forgotAccount"
            type="text"
            placeholder="請輸入登入帳號"
            autocomplete="username"
            required
          />
        </div>
        <div class="form-group">
          <label for="forgot-role">身份</label>
          <select id="forgot-role" v-model="forgotRole">
            <option value="teacher">老師</option>
            <option value="director">主任/櫃台</option>
          </select>
        </div>
        <div class="form-group">
          <label for="forgot-note">補充說明（選填）</label>
          <textarea
            id="forgot-note"
            v-model="forgotNote"
            rows="3"
            maxlength="255"
            placeholder="例如：急需今天登入排課，請協助重設。"
          />
        </div>
        <button type="submit" class="login-btn" :disabled="forgotLoading">
          {{ forgotLoading ? '送出中...' : '送出重設申請' }}
        </button>
      </form>

      <div class="login-footer">
        <button type="button" class="login-footer-btn" @click="mode = 'login'">返回登入</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { supabase } from '../supabase';
import { parentLogin } from '../api';
import Register from './Register.vue';
import DirectorRegister from './DirectorRegister.vue';
import logoUrl from '../assets/logo.png';

const mode = ref('login');
const account = ref('');
const password = ref('');
const selectedRole = ref('director');
const loading = ref(false);
const error = ref('');
const lockSeconds = ref(0);

const forgotAccount = ref('');
const forgotRole = ref('teacher');
const forgotNote = ref('');
const forgotLoading = ref(false);
const forgotError = ref('');
const forgotSuccess = ref('');

const emit = defineEmits(['login-success']);
let cooldownTimer = null;

const isLoginDisabled = computed(() => loading.value || lockSeconds.value > 0);
const isParentRole = computed(() => selectedRole.value === 'parent');
const accountLabel = computed(() => (isParentRole.value ? '帳號（學生姓名或學號）' : '帳號'));
const accountPlaceholder = computed(() => (isParentRole.value ? '請輸入學生姓名或學號' : '請輸入登入帳號或姓名'));
const passwordLabel = computed(() => (isParentRole.value ? '密碼（手機號碼）' : '密碼'));
const passwordPlaceholder = computed(() => (isParentRole.value ? '請輸入家長聯絡手機號碼' : '請輸入密碼'));
const loginButtonText = computed(() => {
  if (loading.value) return '登入中...';
  if (lockSeconds.value > 0) return `請稍候 ${lockSeconds.value} 秒`;
  return '登入';
});

const stopCooldown = () => {
  if (cooldownTimer) {
    clearInterval(cooldownTimer);
    cooldownTimer = null;
  }
};

const startCooldown = (seconds) => {
  const total = Math.max(0, Number(seconds) || 0);
  if (total <= 0) return;
  lockSeconds.value = total;
  stopCooldown();
  cooldownTimer = setInterval(() => {
    lockSeconds.value = Math.max(0, lockSeconds.value - 1);
    if (lockSeconds.value <= 0) {
      stopCooldown();
    }
  }, 1000);
};

const openForgotPassword = () => {
  forgotAccount.value = account.value.trim();
  forgotRole.value = selectedRole.value === 'director' ? 'director' : 'teacher';
  forgotNote.value = '';
  forgotError.value = '';
  forgotSuccess.value = '';
  mode.value = 'forgot-password';
};

const handleLogin = async () => {
  if (lockSeconds.value > 0) return;

  const accountText = account.value.trim();
  const passwordText = password.value.trim();
  if (!accountText || !passwordText) {
    error.value = isParentRole.value
      ? '請輸入學生姓名（或學號）與手機號碼'
      : '請輸入帳號與密碼';
    return;
  }

  loading.value = true;
  error.value = '';

  try {
    if (isParentRole.value) {
      const payload = { Phone: passwordText };
      if (/^\d+$/.test(accountText) && Number(accountText) > 0) {
        payload.StudentID = Number(accountText);
      } else {
        payload.Name = accountText;
      }

      const result = await parentLogin(payload);
      if (!result?.token) throw new Error('家長登入回應異常');
      localStorage.setItem('parent_portal_token', result.token);
      const target = `${window.location.pathname}#/parent`;
      window.location.assign(target);
      return;
    }

    const result = await supabase.auth.signInWithPassword({
      account: accountText,
      password: passwordText,
      // 主任入口同時允許 director / super_admin
      role: selectedRole.value === 'teacher' ? 'teacher' : null,
    });

    if (result.error) {
      if (Number(result.error?.retry_after_seconds) > 0) {
        startCooldown(result.error.retry_after_seconds);
      }
      throw new Error(result.error?.message || '登入失敗');
    }
    const session = result.data?.session;
    if (!session?.user) throw new Error('登入回應異常');
    const resolvedRole = String(session.user.role || '');
    if (selectedRole.value === 'director' && !['director', 'super_admin', 'admin'].includes(resolvedRole)) {
      await supabase.auth.signOut();
      throw new Error('此帳號不是主任或管理員，請切換「老師」登入');
    }
    if (selectedRole.value === 'teacher' && resolvedRole !== 'teacher') {
      await supabase.auth.signOut();
      throw new Error('此帳號不是老師，請切換「主任/櫃台」登入');
    }

    // 後端已在 session.user 帶入 role（主任/老師/super_admin），不 bypass、不另查 profiles
    emit('login-success', { user: session.user, profile: session.user });
  } catch (err) {
    error.value = err.message || '登入失敗';
  } finally {
    loading.value = false;
  }
};

const handleForgotPassword = async () => {
  forgotLoading.value = true;
  forgotError.value = '';
  forgotSuccess.value = '';

  try {
    const result = await supabase.auth.forgotPasswordRequest({
      account: forgotAccount.value,
      role: forgotRole.value,
      note: forgotNote.value,
    });
    if (result.error) throw new Error(result.error?.message || '送出失敗');
    forgotSuccess.value = result.data?.message || '已送出申請，請等待管理端協助重設';
    forgotNote.value = '';
  } catch (err) {
    forgotError.value = err.message || '送出失敗';
  } finally {
    forgotLoading.value = false;
  }
};

onBeforeUnmount(() => {
  stopCooldown();
});
</script>

<style scoped>
/* Login pilot — brand wash + DS tokens only (Epic #687). No glass / mesh / landing motion. */
.login-wrap {
  min-height: 100vh;
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 16px;
  background-color: var(--ds-canvas-soft);
  background-image: linear-gradient(
    180deg,
    var(--ds-primary-wash) 0%,
    var(--ds-canvas-soft) 38%,
    var(--ds-canvas-soft) 100%
  );
}

.login-card {
  position: relative;
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  box-shadow: var(--ds-shadow-2);
  width: 100%;
  max-width: 420px;
  overflow: hidden;
}

.login-brand-bar {
  height: 4px;
  width: 100%;
  background: var(--ds-brand-gradient);
}

.login-header {
  padding: 28px 24px 16px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.logo-wrapper {
  width: 112px;
  height: 112px;
  margin-bottom: 16px;
  border-radius: 50%;
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  box-shadow: var(--ds-shadow-1);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.login-logo {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transform: scale(1.15);
}

.login-title {
  font-size: 1.375rem;
  font-weight: 700;
  color: var(--ds-ink);
  margin: 0 0 4px;
  letter-spacing: -0.02em;
  text-wrap: balance;
}

.login-subtitle {
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--ds-ink-mute);
  margin: 0;
}

.login-trust {
  margin: 10px 0 0;
  max-width: 28ch;
  font-size: 0.75rem;
  font-weight: 500;
  line-height: 1.4;
  color: var(--ds-ink-secondary);
  text-wrap: pretty;
}

.login-error {
  margin: 0 24px;
  padding: 12px 14px;
  background: var(--ds-danger-wash);
  color: var(--ds-danger);
  border-radius: 8px;
  border-left: 3px solid var(--ds-danger);
  font-size: 0.875rem;
  line-height: 1.4;
  font-weight: 500;
}
.login-error-hint {
  display: block;
  margin-top: 8px;
  color: var(--ds-ink-secondary);
  font-weight: 500;
}

.login-first-time {
  margin: 12px 24px 0;
  padding: 12px 14px;
  background: var(--ds-warning-wash);
  color: var(--ds-warning);
  border-radius: 8px;
  border-left: 3px solid var(--ds-warning);
  font-size: 0.8125rem;
  text-align: center;
  font-weight: 600;
}

.login-form {
  padding: 16px 24px 24px;
}

.login-form .form-group {
  margin-bottom: 16px;
}

.login-role-switch {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.role-btn {
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
  border-radius: 8px;
  padding: 12px 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  cursor: pointer;
  transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}

.role-btn:hover {
  border-color: var(--ds-brand-orange);
  background: var(--ds-primary-wash);
}

.role-btn.active {
  border-color: var(--ds-primary);
  background: var(--ds-primary-wash);
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

.role-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

.role-title {
  font-size: 0.9375rem;
  font-weight: 700;
  color: var(--ds-ink);
}

.role-subtitle {
  font-size: 0.75rem;
  color: var(--ds-ink-mute);
}

.login-form .form-group label {
  display: block;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--ds-ink-secondary);
  margin-bottom: 6px;
}

.login-form .form-group input,
.login-form .form-group select,
.login-form .form-group textarea {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--ds-hairline-input);
  border-radius: 8px;
  font-size: 1rem;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
  font-weight: 500;
  transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}

.login-form .form-group input::placeholder,
.login-form .form-group textarea::placeholder {
  color: var(--ds-ink-mute);
  font-weight: 400;
}

.login-form .form-group input:hover,
.login-form .form-group select:hover,
.login-form .form-group textarea:hover {
  border-color: var(--ds-brand-orange);
}

.login-form .form-group input:focus,
.login-form .form-group select:focus,
.login-form .form-group textarea:focus {
  outline: none;
  background: var(--ds-canvas);
  border-color: var(--ds-primary);
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

.login-form .form-group input:focus-visible,
.login-form .form-group select:focus-visible,
.login-form .form-group textarea:focus-visible,
.login-btn:focus-visible,
.login-footer-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

.login-form .form-group textarea {
  resize: vertical;
  min-height: 88px;
}

.login-btn {
  width: 100%;
  margin-top: 4px;
  padding: 12px 16px;
  font-size: 1rem;
  font-weight: 700;
  color: var(--ds-on-primary);
  background: var(--ds-brand-gradient);
  border: none;
  border-radius: 999px;
  cursor: pointer;
  box-shadow: var(--ds-shadow-1);
  transition: background-color 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
  letter-spacing: 0.01em;
}

.login-btn:hover:not(:disabled) {
  filter: brightness(0.97);
  box-shadow: var(--ds-shadow-2);
}

.login-btn:active:not(:disabled) {
  filter: brightness(0.94);
}

.login-btn:disabled {
  background: var(--ds-hairline);
  color: var(--ds-ink-mute);
  box-shadow: none;
  cursor: not-allowed;
  filter: none;
}

.login-footer {
  padding: 16px 24px 20px;
  display: flex;
  gap: 8px;
  justify-content: center;
  flex-wrap: wrap;
  border-top: 1px solid var(--ds-hairline);
  background: var(--ds-canvas-soft);
}

.login-footer-btn {
  padding: 8px 14px;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--ds-primary);
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: 999px;
  cursor: pointer;
  transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.login-footer-btn:hover {
  background: var(--ds-primary-wash);
  border-color: var(--ds-brand-orange);
  color: var(--ds-primary-deep);
}

@media (max-width: 560px) {
  .login-role-switch {
    grid-template-columns: 1fr;
  }
  .login-header {
    padding: 24px 20px 12px;
  }
  .login-form {
    padding: 12px 20px 20px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .role-btn,
  .login-form .form-group input,
  .login-form .form-group select,
  .login-form .form-group textarea,
  .login-btn,
  .login-footer-btn {
    transition: none;
  }
}
</style>
