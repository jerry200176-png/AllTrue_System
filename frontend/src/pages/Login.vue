<template>
  <div v-if="mode === 'login'" class="login-wrap">
    <LoginFx />
    <BlurFade class="login-card-enter">
    <div class="login-card">
      <div class="login-header">
        <LogoReveal>
          <div class="logo-wrapper">
            <img :src="logoUrl" alt="全真一對一 Logo" class="login-logo" onerror="this.style.display='none'" />
          </div>
        </LogoReveal>
        <h1 class="login-title"><TextReveal text="台北全真一對一" :delay="900" /></h1>
        <p class="login-subtitle">教務管理系統</p>
      </div>

      <div v-if="error" class="login-error" role="alert">
        <div>{{ error }}</div>
        <span v-if="lockSeconds > 0" class="login-error-hint">系統已暫時鎖定，請待倒數結束後再嘗試。</span>
      </div>

      <div v-if="choice" class="login-form" data-testid="account-choice">
        <p class="choice-prompt">你有兩個身分，要用哪一個登入？</p>
        <div class="login-role-switch" role="group" aria-label="選擇登入身分">
          <button
            v-for="c in choice.choices"
            :key="c.choice_id"
            type="button"
            class="role-btn"
            :disabled="loading"
            @click="handleChoose(c.choice_id)"
          >
            <span class="role-title">{{ c.role_label }}</span>
          </button>
        </div>
        <button type="button" class="login-footer-btn" @click="choice = null">返回</button>
      </div>

      <form v-else class="login-form" @submit.prevent="handleLogin">
        <div class="form-group">
          <label for="login-account">帳號</label>
          <input
            id="login-account"
            v-model="account"
            type="text"
            placeholder="請輸入登入帳號或姓名"
            autocomplete="username"
          />
        </div>
        <div class="form-group">
          <label for="login-password">密碼</label>
          <input
            id="login-password"
            v-model="password"
            type="password"
            placeholder="請輸入密碼"
            autocomplete="current-password"
          />
        </div>
        <ShineBorder class="login-btn-wrap" radius="var(--ds-radius-lg)">
          <button type="submit" class="login-btn" :disabled="isLoginDisabled">
            {{ loginButtonText }}
          </button>
        </ShineBorder>
      </form>

      <div class="login-footer">
          <button type="button" class="login-footer-btn" @click="mode = 'register'">老師註冊</button>
          <button type="button" class="login-footer-btn" @click="mode = 'director-register'">主任申請</button>
          <button type="button" class="login-footer-btn" @click="openForgotPassword">忘記密碼</button>
      </div>
    </div>
    </BlurFade>
  </div>

  <Register v-else-if="mode === 'register'" @switch-mode="mode = $event" />
  <DirectorRegister v-else-if="mode === 'director-register'" @switch-mode="mode = $event" />
  <div v-else-if="mode === 'forgot-password'" class="login-wrap">
    <AuroraBackground />
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
import Register from './Register.vue';
import DirectorRegister from './DirectorRegister.vue';
import logoUrl from '../assets/logo.png';
import { AuroraBackground, BlurFade, LoginFx, LogoReveal, ShineBorder, TextReveal } from '../components/motion';

const mode = ref('login');
const account = ref('');
const password = ref('');
const choice = ref(null); // { token, choices } in memory only; never persisted
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
  forgotNote.value = '';
  forgotError.value = '';
  forgotSuccess.value = '';
  mode.value = 'forgot-password';
};

const finishLogin = (result, cooldown = true) => {
  if (result.error) {
    if (cooldown && Number(result.error?.retry_after_seconds) > 0) {
      startCooldown(result.error.retry_after_seconds);
    }
    throw new Error(result.error?.message || '登入失敗');
  }
  const session = result.data?.session;
  if (!session?.user) throw new Error('登入回應異常');
  choice.value = null;
  // 後端已在 session.user 帶入 role（主任/老師/super_admin），不 bypass、不另查 profiles
  emit('login-success', { user: session.user, profile: session.user });
};

const handleLogin = async () => {
  if (lockSeconds.value > 0) return;

  const accountText = account.value.trim();
  const passwordText = password.value.trim();
  if (!accountText || !passwordText) {
    error.value = '請輸入帳號與密碼';
    return;
  }

  loading.value = true;
  error.value = '';

  try {
    const result = await supabase.auth.signInWithPassword({ account: accountText, password: passwordText });
    if (!result.error && result.data?.requires_account_choice) {
      choice.value = { token: result.data.choice_token, choices: result.data.choices || [] };
      password.value = '';
      return;
    }
    finishLogin(result);
  } catch (err) {
    error.value = err.message || '登入失敗';
  } finally {
    loading.value = false;
  }
};

const handleChoose = async (choiceId) => {
  loading.value = true;
  error.value = '';
  try {
    finishLogin(await supabase.auth.chooseAccount({ choiceToken: choice.value.token, choiceId }), false);
  } catch (err) {
    choice.value = null; // token is single-use; start over
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
/* Founder 2026-11-07: original orange back, slow aurora drift (AuroraBackground); clean white card, gold 登入 button. */
.login-wrap {
  min-height: 100vh;
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 24px;
  position: relative;
  overflow: hidden;
}
.login-card-enter { position: relative; width: 100%; max-width: 420px; }

/* 玻璃擬態 (Glassmorphism) 卡片設計 */
.login-card {
  position: relative;
  background: var(--ds-canvas);
  border: none;
  border-radius: 20px;
  box-shadow: 0 24px 60px color-mix(in srgb, var(--ds-brand-ink) 28%, transparent);
  width: 100%;
  max-width: 420px;
  overflow: hidden;
}

/* 頂部區域與 Logo */
.login-header {
  padding: 44px 32px 28px;
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
  background: var(--ds-canvas);
  padding: 0px; /* 移除白邊 */
  box-shadow: none;
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
  font-weight: 700;
  color: var(--ds-ink);
  text-wrap: balance;
  margin: 0 0 6px;
  letter-spacing: 0.03em;
}

.login-subtitle {
  font-size: 1rem;
  font-weight: 600;
  color: var(--ds-ink-mute);
  margin: 0;
  letter-spacing: 0.01em;
}

.login-error {
  margin: 0 24px;
  padding: 12px 16px;
  background: rgba(255, 235, 238, 0.9);
  color: #d32f2f;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--ds-danger) 40%, transparent);
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
  border: 1px solid color-mix(in srgb, var(--ds-warning) 40%, transparent);
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
  padding: 24px 32px 36px;
}

.login-form .form-group {
  margin-bottom: 22px;
}
.choice-prompt {
  margin: 0 0 12px;
  font-weight: 600;
  text-align: center;
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
.role-icon-dual {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 2px;
}
.role-icon-dual .role-icon-part {
  font-size: 1.1rem;
  line-height: 1;
}
.role-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: #37474f;
}
.role-subtitle {
  font-size: 0.74rem;
  color: #78909c;
}

.login-form .form-group label {
  display: block;
  font-size: 1rem;
  font-weight: 600;
  color: var(--ds-ink-secondary);
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
  color: var(--ds-ink);
  font-weight: 500;
  transition: background-color 0.2s ease-out, border-color 0.2s ease-out, box-shadow 0.2s ease-out;
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
  background: var(--ds-canvas);
  border-color: var(--ds-ink);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--ds-ink) 8%, transparent);
}

.login-form .form-group textarea {
  resize: vertical;
  min-height: 88px;
}

.login-btn-wrap { margin-top: 12px; }
.login-btn {
  width: 100%;
  min-height: 48px;
  padding: 14px;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--ds-brand-glyph);
  background: var(--ds-brand-sun);
  border: none;
  border-radius: var(--ds-radius-lg);
  cursor: pointer;
  box-shadow: none;
  transition: background-color 0.2s ease-out, transform 0.15s ease-out;
  letter-spacing: 0.02em;
}
.login-btn:hover:not(:disabled) { background: var(--ds-brand-gold); }
.login-btn:active:not(:disabled) { transform: scale(0.96); }
.login-btn:focus-visible { outline: 3px solid var(--ds-ink); outline-offset: 3px; } /* theme-aware: 3:1+ on the light and the dark card */
.login-btn:disabled {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
  box-shadow: none;
  cursor: not-allowed;
}
@media (prefers-reduced-motion: reduce) {
  .login-btn { transition: none; }
  .login-btn:active:not(:disabled) { transform: none; }
}

.login-footer {
  padding: 20px 28px 28px;
  display: flex;
  gap: 16px;
  justify-content: center;
  flex-wrap: wrap;
  border-top: 1px solid var(--ds-hairline);
  background: transparent;
}
.login-footer-btn {
  padding: 10px 20px;
  font-size: 1rem;
  font-weight: 600;
  color: var(--ds-ink-mute);
  background: transparent;
  border: none;
  border-radius: 10px;
  min-height: 44px;
  cursor: pointer;
  transition: background-color 0.2s ease-out, color 0.2s ease-out;
}

.login-footer-btn:hover {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
}
@media (max-width: 560px) {
  .login-role-switch {
    grid-template-columns: 1fr;
  }
}
</style>
