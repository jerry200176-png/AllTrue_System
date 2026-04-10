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
              <span class="role-icon">👩‍🏫</span>
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
              <span class="role-icon">🧑‍💼</span>
              <span class="role-title">主任</span>
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
            <option value="director">主任</option>
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
      throw new Error('此帳號不是老師，請切換「主任」登入');
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
/* 品牌專屬：高質感金桔/暖黃色彩漸層背景 */
.login-wrap {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 24px;
  background: linear-gradient(-45deg, #FFD54F, #FFB300, #F57C00, #EF6C00);
  background-size: 400% 400%;
  animation: gradientBG 15s ease infinite;
}

@keyframes gradientBG {
  0% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

/* 玻璃擬態 (Glassmorphism) 卡片設計 - 深沉冷冽感 */
.login-card {
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border: 1px solid rgba(255, 255, 255, 0.4);
  border-radius: 20px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.2) inset;
  width: 100%;
  max-width: 420px;
  overflow: hidden;
  animation: floatUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes floatUp {
  0% { transform: translateY(30px); opacity: 0; }
  100% { transform: translateY(0); opacity: 1; }
}

/* 頂部區域與 Logo */
.login-header {
  padding: 36px 32px 24px;
  text-align: center;
  background: transparent;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.logo-wrapper {
  width: 140px;
  height: 140px;
  margin-bottom: 20px;
  border-radius: 50%;
  background: #fff;
  padding: 0px; /* 移除白邊 */
  box-shadow: 0 6px 16px rgba(245, 124, 0, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden; /* 確保圖片不超出圓角 */
}

.login-logo {
  width: 100%;
  height: 100%;
  object-fit: cover; /* 讓 Logo 填滿整個圓形區塊，消除透明留白 */
  transform: scale(1.15); /* 微微放大抵消圖片自帶的留白 */
}

.login-title {
  font-size: 1.5rem;
  font-weight: 800;
  color: #37474f;
  margin: 0 0 6px;
  letter-spacing: 0.03em;
}

.login-subtitle {
  font-size: 0.85rem;
  font-weight: 500;
  color: #78909c;
  margin: 0;
  letter-spacing: 0.01em;
}

.login-error {
  margin: 0 24px;
  padding: 12px 16px;
  background: rgba(255, 235, 238, 0.9);
  color: #d32f2f;
  border-radius: 12px;
  border-left: 4px solid #d32f2f;
  font-size: 0.875rem;
  line-height: 1.4;
  font-weight: 500;
}
.login-error-hint { display: block; margin-top: 8px; }
.login-error-hint a { color: #b71c1c; text-decoration: underline; font-weight: 600; }

.login-first-time {
  margin: 16px 24px 0;
  padding: 12px 16px;
  background: rgba(255, 243, 224, 0.9);
  border-radius: 12px;
  border-left: 4px solid #ff9800;
  font-size: 0.8125rem;
  text-align: center;
  font-weight: 600;
}
.login-first-time a {
  color: #e65100;
  text-decoration: none;
}
.login-first-time a:hover { text-decoration: underline; }

.login-form {
  padding: 24px 28px 32px;
}

.login-form .form-group {
  margin-bottom: 22px;
}
.login-role-switch {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}
.role-btn {
  border: 2px solid rgba(120, 144, 156, 0.25);
  background: rgba(255, 255, 255, 0.85);
  border-radius: 14px;
  padding: 12px 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  transition: all 0.22s ease;
}
.role-btn:hover {
  transform: translateY(-1px);
  border-color: rgba(245, 124, 0, 0.55);
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
}
.role-btn.active {
  border-color: #F57C00;
  background: linear-gradient(180deg, #FFF8E1 0%, #FFFFFF 100%);
  box-shadow: 0 0 0 4px rgba(245, 124, 0, 0.14);
}
.role-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 4px rgba(245, 124, 0, 0.2);
}
.role-icon {
  font-size: 1.35rem;
  line-height: 1;
}
.role-title {
  font-size: 0.95rem;
  font-weight: 800;
  color: #37474f;
}
.role-subtitle {
  font-size: 0.74rem;
  color: #78909c;
}

.login-form .form-group label {
  display: block;
  font-size: 0.85rem;
  font-weight: 600;
  color: #455a64;
  margin-bottom: 8px;
  margin-left: 4px;
}

.login-form .form-group input,
.login-form .form-group select,
.login-form .form-group textarea {
  width: 100%;
  padding: 14px 16px;
  border: 2px solid transparent;
  border-radius: 12px;
  font-size: 1rem;
  background: rgba(55, 71, 79, 0.04);
  color: #263238;
  font-weight: 500;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.login-form .form-group input::placeholder,
.login-form .form-group textarea::placeholder {
  color: #90a4ae;
  font-weight: 400;
}

.login-form .form-group input:hover,
.login-form .form-group select:hover,
.login-form .form-group textarea:hover {
  background: rgba(55, 71, 79, 0.08);
}

.login-form .form-group input:focus,
.login-form .form-group select:focus,
.login-form .form-group textarea:focus {
  outline: none;
  background: #fff;
  border-color: #F57C00;
  box-shadow: 0 0 0 4px rgba(245, 124, 0, 0.15);
}

.login-form .form-group textarea {
  resize: vertical;
  min-height: 88px;
}

.login-btn {
  width: 100%;
  margin-top: 12px;
  padding: 14px;
  font-size: 1.05rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, #FFB300 0%, #F57C00 100%);
  border: none;
  border-radius: 12px;
  cursor: pointer;
  box-shadow: 0 4px 15px rgba(245, 124, 0, 0.3);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  letter-spacing: 0.02em;
}

.login-btn:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(245, 124, 0, 0.4);
  background: linear-gradient(135deg, #FFCA28 0%, #EF6C00 100%);
}

.login-btn:active:not(:disabled) {
  transform: translateY(1px);
  box-shadow: 0 2px 10px rgba(245, 124, 0, 0.3);
}

.login-btn:disabled {
  background: #cfd8dc;
  color: #90a4ae;
  box-shadow: none;
  cursor: not-allowed;
  transform: none;
}

.login-footer {
  padding: 20px 28px 28px;
  display: flex;
  gap: 16px;
  justify-content: center;
  flex-wrap: wrap;
  border-top: 1px solid rgba(0, 0, 0, 0.06);
  background: rgba(255, 255, 255, 0.4);
}
.login-parent-hint {
  width: 100%;
  margin: 0;
  font-size: 0.85rem;
  color: #546e7a;
  text-align: center;
}

.login-footer-btn {
  padding: 10px 20px;
  font-size: 0.85rem;
  font-weight: 600;
  color: #F57C00;
  background: #fff;
  border: 1px solid rgba(245, 124, 0, 0.3);
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
}

.login-footer-btn:hover {
  background: #FFF8E1;
  border-color: #FFB300;
  color: #EF6C00;
  transform: translateY(-1px);
  box-shadow: 0 4px 10px rgba(245, 124, 0, 0.1);
}
@media (max-width: 560px) {
  .login-role-switch {
    grid-template-columns: 1fr;
  }
}
</style>
