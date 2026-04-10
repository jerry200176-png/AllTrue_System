<template>
  <div class="login-container">
    <div class="login-card">
      <h2>主任申請 (Director Application)</h2>
      <p class="subtitle">填寫後由超級管理員審核，通過即可登入</p>

      <div v-if="branchesError" class="error-msg">{{ branchesError }}</div>
      <div v-if="error" class="error-msg">{{ error }}</div>
      <div v-if="success" class="success-msg">
        已送出申請，請等候超級管理員審核。<br />
        <a href="#" @click.prevent="$emit('switch-mode', 'login')">返回登入</a>
      </div>

      <form v-else @submit.prevent="submit">
        <div class="form-group">
          <label>分校</label>
          <select v-model="form.campus_id" required :disabled="branchesLoading">
            <option value="">{{ branchesLoading ? '載入中...' : '請選擇分校' }}</option>
            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>姓名</label>
          <input v-model="form.name" type="text" placeholder="您的姓名" required />
        </div>
        <div class="form-group">
          <label>登入帳號</label>
          <input v-model="form.account" type="text" placeholder="例如：director001" required />
        </div>
        <div class="form-group">
          <label>密碼</label>
          <input v-model="form.password" type="password" placeholder="至少 4 個字元" required minlength="4" />
        </div>
        <button type="submit" class="primary full-width" :disabled="loading">
          {{ loading ? '送出中...' : '送出申請' }}
        </button>
        <div class="footer-links">
          <a href="#" @click.prevent="$emit('switch-mode', 'login')">返回登入</a>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { officialBranchesFromApi } from '../lib/useBranches';

const emit = defineEmits(['switch-mode']);

// 主任申請頁專用：進入頁面時直接向 API 拉取分校列表，避免快取或首屏未載入導致只顯示大安
const branches = ref([]);
const branchesLoading = ref(true);
const branchesError = ref('');
const FALLBACK_BRANCHES = [
  { id: 1, name: '興隆分校', code: 'xinglong' },
  { id: 2, name: '新店分校', code: 'xindian' },
  { id: 3, name: '大安分校', code: 'daan' },
  { id: 4, name: '木柵分校', code: 'muzha' },
  { id: 5, name: '東湖分校', code: 'donghu' },
  { id: 6, name: '大直分校', code: 'dazhi' },
  { id: 7, name: '汐止分校', code: 'xizhi' },
  { id: 8, name: '內湖分校', code: 'neihu' },
];

const form = reactive({
  campus_id: '',
  name: '',
  account: '',
  password: '',
});

onMounted(async () => {
  branchesLoading.value = true;
  branchesError.value = '';
  try {
    const res = await fetch('/api/v1/branches', { cache: 'no-store' });
    const text = await res.text();
    if (!res.ok) {
      branchesError.value = '無法載入分校列表，請稍後再試';
      branches.value = FALLBACK_BRANCHES;
    } else {
      try {
        const data = JSON.parse(text);
        branches.value = Array.isArray(data) ? officialBranchesFromApi(data) : FALLBACK_BRANCHES;
        if (!form.campus_id && branches.value.length > 0) {
          form.campus_id = branches.value[0].id;
        }
      } catch {
        branches.value = FALLBACK_BRANCHES;
      }
    }
  } catch (e) {
    branchesError.value = '網路連線異常，請檢查後重試';
    branches.value = FALLBACK_BRANCHES;
  } finally {
    branchesLoading.value = false;
  }
});

const loading = ref(false);
const error = ref('');
const success = ref(false);

async function submit() {
  error.value = '';
  if (!form.name || !form.account || !form.password || !form.campus_id) {
    error.value = '請填寫所有欄位';
    return;
  }
  if (form.password.length < 4) {
    error.value = '密碼至少 4 個字元';
    return;
  }
  loading.value = true;
  try {
    const res = await fetch('/api/v1/directors/register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: form.name,
        account: form.account,
        password: form.password,
        campus_id: Number(form.campus_id),
      }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      error.value = data?.message || '送出失敗';
      return;
    }
    success.value = true;
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.login-container {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  background-color: #f5f5f5;
  background-image: linear-gradient(135deg, #FFF8E1 0%, #FFE0B2 100%);
}
.login-card {
  background: #fff;
  padding: 40px;
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.1);
  width: 100%;
  max-width: 400px;
}
h2 { color: #E65100; margin-bottom: 8px; }
.subtitle { color: #666; margin-bottom: 24px; font-size: 0.9em; }
.form-group { margin-bottom: 16px; text-align: left; }
.form-group label { display: block; margin-bottom: 6px; font-weight: bold; color: #333; }
.form-group input, .form-group select {
  width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px;
}
.form-group select {
  appearance: none;
  -webkit-appearance: none;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 10px center;
  background-size: 16px;
  padding-right: 32px;
}
.error-msg { background: #ffebee; color: #c62828; padding: 10px; border-radius: 6px; margin-bottom: 16px; font-size: 0.9em; }
.success-msg { background: #e8f5e9; color: #2e7d32; padding: 16px; border-radius: 6px; margin-bottom: 16px; }
.success-msg a { color: #2e7d32; font-weight: 600; }
.full-width { width: 100%; padding: 12px; font-size: 1.1em; margin-top: 8px; }
.footer-links { margin-top: 16px; font-size: 0.9em; }
.footer-links a { color: #666; text-decoration: none; }
</style>
