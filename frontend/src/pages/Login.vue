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
        {{ error }}
      </div>

      <form class="login-form" @submit.prevent="handleLogin">
        <div class="form-group">
          <label for="login-email">帳號</label>
          <input id="login-email" v-model="email" type="text" placeholder="Email 或姓名" autocomplete="username" />
        </div>
        <div class="form-group">
          <label for="login-password">密碼</label>
          <input id="login-password" v-model="password" type="password" placeholder="請輸入密碼" autocomplete="current-password" />
        </div>
        <button type="submit" class="login-btn" :disabled="loading">
          {{ loading ? '登入中...' : '登入' }}
        </button>
      </form>

      <div class="login-footer">
        <button type="button" class="login-footer-btn" @click="mode = 'register'">老師註冊</button>
        <button type="button" class="login-footer-btn" @click="mode = 'director-register'">主任申請</button>
      </div>
    </div>
  </div>

  <Register v-else-if="mode === 'register'" @switch-mode="mode = $event" />
  <DirectorRegister v-else-if="mode === 'director-register'" @switch-mode="mode = $event" />
</template>

<script setup>
import { ref } from 'vue';
import { supabase } from '../supabase';
import Register from './Register.vue';
import DirectorRegister from './DirectorRegister.vue';
import logoUrl from '../assets/logo.png';

const mode = ref('login');
const email = ref('');
const password = ref('');
const loading = ref(false);
const error = ref('');

const emit = defineEmits(['login-success']);

const handleLogin = async () => {
  loading.value = true;
  error.value = '';

  try {
    const result = await supabase.auth.signInWithPassword({
      email: email.value,
      password: password.value,
    });

    if (result.error) throw new Error(result.error?.message || '登入失敗');
    const session = result.data?.session;
    if (!session?.user) throw new Error('登入回應異常');

    // 後端已在 session.user 帶入 role（主任/老師/super_admin），不 bypass、不另查 profiles
    emit('login-success', { user: session.user, profile: session.user });
  } catch (err) {
    error.value = err.message || '登入失敗';
  } finally {
    loading.value = false;
  }
};
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

.login-form .form-group label {
  display: block;
  font-size: 0.85rem;
  font-weight: 600;
  color: #455a64;
  margin-bottom: 8px;
  margin-left: 4px;
}

.login-form .form-group input {
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

.login-form .form-group input::placeholder {
  color: #90a4ae;
  font-weight: 400;
}

.login-form .form-group input:hover {
  background: rgba(55, 71, 79, 0.08);
}

.login-form .form-group input:focus {
  outline: none;
  background: #fff;
  border-color: #F57C00;
  box-shadow: 0 0 0 4px rgba(245, 124, 0, 0.15);
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
</style>
