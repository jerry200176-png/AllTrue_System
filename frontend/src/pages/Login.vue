<template>
  <div v-if="mode === 'login'" class="login-wrap">
    <div class="login-card">
      <div class="login-header">
        <div class="logo-wrapper">
          <img :src="logoUrl" alt="全真一對一 Logo" class="login-logo" onerror="this.style.display='none'" />
        </div>
        <h1 class="login-title">台北全真一對一</h1>
        <p class="login-subtitle">教務管理系統</p>
      </div>

      <div v-if="error" class="login-error" role="alert">
        <div>{{ error }}</div>
        <span v-if="lockSeconds > 0" class="login-error-hint">系統已暫時鎖定，請待倒數結束後再嘗試。</span>
      </div>

      <form class="login-form" @submit.prevent="handleLogin">
        <div class="form-group">
          <label>登入身分</label>
          <div class="login-role-switch" role="radiogroup" aria-label="登入身分">
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
              <span class="role-title">主任 / 櫃台</span>
              <span class="role-subtitle">含管理員</span>
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
      <div class="login-header">
        <div class="logo-wrapper">
          <img :src="logoUrl" alt="全真一對一 Logo" class="login-logo" onerror="this.style.display='none'" />
        </div>
        <h1 class="login-title">忘記密碼</h1>
        <p class="login-subtitle">送出申請後請由主任或管理員協助重設</p>
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
/* iOS-inspired login v0 — see .cursor/plans/ios-simplify-direction_2026-05-24.md
 * Goals: system font stack, generous whitespace, soft shadows, single brand accent,
 * 44px+ touch targets, no animated gradient, no emoji role icons. */

.login-wrap {
  min-height: 100vh;
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--ios-spacing-4) var(--ios-spacing-3);
  /* Soft warm cream — keeps brand warmth without aggressive saturation. */
  background: radial-gradient(120% 80% at 50% 0%, #FFF7E8 0%, #FFEFD2 45%, #FCE3B6 100%);
  font-family: var(--ios-font-family);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

.login-card {
  width: 100%;
  max-width: 420px;
  background: var(--ios-bg-card);
  border: 1px solid var(--ios-border-faint);
  border-radius: 22px;
  box-shadow: var(--ios-shadow-card);
  overflow: hidden;
  animation: floatUp 0.45s var(--ios-ease);
}

@keyframes floatUp {
  0%   { transform: translateY(16px); opacity: 0; }
  100% { transform: translateY(0);    opacity: 1; }
}

.login-header {
  padding: 36px 28px 20px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.logo-wrapper {
  width: 88px;
  height: 88px;
  margin-bottom: 16px;
  border-radius: 22px;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  box-shadow: 0 0 0 1px var(--ios-border-faint);
}

.login-logo {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transform: scale(1.1);
}

.login-title {
  font-family: var(--ios-font-family);
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--ios-text-primary);
  margin: 0 0 4px;
  letter-spacing: -0.01em;
}

.login-subtitle {
  font-size: 0.95rem;
  font-weight: 400;
  color: var(--ios-text-secondary);
  margin: 0;
}

.login-error {
  margin: 0 var(--ios-spacing-4) 0;
  padding: var(--ios-spacing-2) var(--ios-spacing-3);
  background: #FFF0F0;
  color: #C82828;
  border-radius: var(--ios-radius-control);
  font-size: 0.875rem;
  line-height: 1.45;
  font-weight: 500;
}
.login-error-hint { display: block; margin-top: 6px; color: #A02020; }
.login-error-hint a { color: inherit; text-decoration: underline; font-weight: 600; }

.login-first-time {
  margin: var(--ios-spacing-3) var(--ios-spacing-4) 0;
  padding: var(--ios-spacing-2) var(--ios-spacing-3);
  background: #FFF8E8;
  border-radius: var(--ios-radius-control);
  font-size: 0.85rem;
  text-align: center;
  color: #8A4F00;
  font-weight: 500;
}
.login-first-time a { color: var(--ios-accent-brand); text-decoration: none; font-weight: 600; }
.login-first-time a:hover { text-decoration: underline; }

.login-form {
  padding: var(--ios-spacing-4) var(--ios-spacing-4) var(--ios-spacing-4);
  font-family: var(--ios-font-family);
}

.login-form .form-group {
  margin-bottom: var(--ios-spacing-3);
}

.login-form .form-group label {
  display: block;
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--ios-text-secondary);
  margin-bottom: var(--ios-spacing-1);
  margin-left: 2px;
  letter-spacing: 0.01em;
}

.login-role-switch {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--ios-spacing-1);
  padding: 4px;
  background: #F2F2F7;
  border-radius: var(--ios-radius-control);
}

.role-btn {
  min-height: var(--ios-touch-target);
  border: 1px solid transparent;
  background: transparent;
  border-radius: 10px;
  padding: 10px 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  cursor: pointer;
  transition: background 0.2s var(--ios-ease), box-shadow 0.2s var(--ios-ease);
  color: var(--ios-text-secondary);
}

.role-btn.active {
  background: var(--ios-bg-card);
  color: var(--ios-text-primary);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08), 0 0 0 0.5px rgba(0, 0, 0, 0.04);
}

.role-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(245, 124, 0, 0.25);
}

.role-title {
  font-size: 0.95rem;
  font-weight: 600;
  letter-spacing: -0.005em;
}
.role-subtitle {
  font-size: 0.75rem;
  color: var(--ios-text-tertiary);
  font-weight: 400;
}
.role-btn.active .role-subtitle { color: var(--ios-text-secondary); }

.login-form .form-group input,
.login-form .form-group select,
.login-form .form-group textarea {
  width: 100%;
  min-height: var(--ios-touch-target);
  padding: 12px 14px;
  border: 1px solid var(--ios-border-default);
  border-radius: var(--ios-radius-control);
  font-size: 1rem;
  background: var(--ios-bg-card);
  color: var(--ios-text-primary);
  font-weight: 400;
  font-family: inherit;
  transition: border-color 0.18s var(--ios-ease), box-shadow 0.18s var(--ios-ease);
}

.login-form .form-group input::placeholder,
.login-form .form-group textarea::placeholder {
  color: var(--ios-text-tertiary);
  font-weight: 400;
}

.login-form .form-group input:hover,
.login-form .form-group select:hover {
  border-color: rgba(0, 0, 0, 0.18);
}

.login-form .form-group input:focus,
.login-form .form-group select:focus,
.login-form .form-group textarea:focus {
  outline: none;
  border-color: var(--ios-accent-brand);
  box-shadow: 0 0 0 3px rgba(245, 124, 0, 0.16);
}

.login-form .form-group textarea {
  resize: vertical;
  min-height: 88px;
  padding: 12px 14px;
}

.login-btn {
  width: 100%;
  margin-top: var(--ios-spacing-2);
  min-height: var(--ios-touch-target);
  padding: 12px;
  font-size: 1rem;
  font-weight: 600;
  font-family: inherit;
  color: #fff;
  background: var(--ios-accent-brand);
  border: none;
  border-radius: var(--ios-radius-control);
  cursor: pointer;
  transition: background 0.18s var(--ios-ease), transform 0.1s var(--ios-ease);
  letter-spacing: 0.005em;
}

.login-btn:hover:not(:disabled) {
  background: #E76F00;
}

.login-btn:active:not(:disabled) {
  transform: scale(0.985);
  background: #D86600;
}

.login-btn:disabled {
  background: #E5E5EA;
  color: #A1A1A6;
  cursor: not-allowed;
}

.login-footer {
  padding: var(--ios-spacing-3) var(--ios-spacing-4) var(--ios-spacing-4);
  display: flex;
  gap: var(--ios-spacing-3);
  justify-content: center;
  flex-wrap: wrap;
  border-top: 1px solid var(--ios-border-faint);
  background: transparent;
}
.login-parent-hint {
  width: 100%;
  margin: 0;
  font-size: 0.8125rem;
  color: var(--ios-text-secondary);
  text-align: center;
}

.login-footer-btn {
  padding: 8px 4px;
  font-size: 0.875rem;
  font-weight: 500;
  font-family: inherit;
  color: var(--ios-accent-brand);
  background: transparent;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: color 0.15s var(--ios-ease);
}

.login-footer-btn:hover {
  color: #D86600;
}
.login-footer-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(245, 124, 0, 0.2);
}

@media (max-width: 560px) {
  .login-role-switch {
    grid-template-columns: 1fr;
  }
}
</style>
