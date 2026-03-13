<template>
  <div class="login-container">
    <div class="login-card">
      <h2>老師註冊 (Teacher Register)</h2>
      <p class="subtitle">Join Taipei AllTrue Team</p>
      
      <div v-if="error" class="error-msg">{{ error }}</div>
      <div v-if="success" class="success-msg">註冊成功！請通知主任審核您的帳號。<br><a href="#" @click.prevent="$emit('switch-mode', 'login')">返回登入</a></div>

      <div v-else>
        <div class="form-group">
            <label>姓名 (Name)</label>
            <input v-model="form.username" placeholder="Your Name" />
        </div>

        <div class="form-group">
            <label>Email</label>
            <input v-model="form.email" type="email" placeholder="email@example.com" />
        </div>

        <div class="form-group">
            <label>密碼 (Password)</label>
            <input v-model="form.password" type="password" placeholder="至少 4 個字元" />
        </div>

        <div class="form-group">
            <label>確認密碼 (Confirm Password)</label>
            <input v-model="form.confirmPassword" type="password" placeholder="再輸入一次密碼" />
        </div>

        <div class="form-group">
            <label>預設分校 (Home Branch)</label>
            <select v-model="form.branch_id">
                <option v-for="b in BRANCHES" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>聯絡電話 (Phone)</label>
            <input v-model="form.phone" placeholder="09xxxxxxxx" />
        </div>

        <button class="primary full-width" @click="handleRegister" :disabled="loading">
            {{ loading ? '註冊中...' : '註冊 (Sign Up)' }}
        </button>

        <div class="footer-links">
            <a href="#" @click.prevent="$emit('switch-mode', 'login')">已有帳號? 登入</a>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { supabase } from '../supabase';
import { branches as BRANCHES, loadBranches } from '../lib/useBranches';

const emit = defineEmits(['switch-mode']);

const form = ref({
    username: '',
    email: '',
    password: '',
    confirmPassword: '',
    branch_id: null,
    phone: ''
});

// Load branches on mount (public endpoint, no auth needed)
onMounted(async () => {
    await loadBranches();
    if (!form.value.branch_id && BRANCHES.value.length > 0) {
        form.value.branch_id = BRANCHES.value[0].id;
    }
});

const loading = ref(false);
const error = ref('');
const success = ref(false);

const handleRegister = async () => {
    if (!form.value.username || !form.value.email || !form.value.password) {
        error.value = '請填寫完整資訊';
        return;
    }
    if (form.value.password.length < 4) {
        error.value = '密碼至少 4 個字元';
        return;
    }
    if (form.value.password !== form.value.confirmPassword) {
        error.value = '兩次密碼不一致，請重新確認';
        return;
    }
    
    loading.value = true;
    error.value = '';

    try {
        // Register — backend creates the profile with username directly
        const { data, error: authError } = await supabase.auth.signUp({
            email: form.value.email,
            password: form.value.password,
            options: {
                data: {
                    username: form.value.username,
                    role: 'teacher'
                }
            }
        });

        if (authError) throw authError;

        if (data.user) {
            // Update the profile with branch_id and phone (these weren't set during register)
            await supabase.from('profiles').update({
                branch_id: form.value.branch_id,
                phone: form.value.phone || ''
            }).eq('id', data.user.id);
            
            success.value = true;
        }

    } catch (err) {
        error.value = '註冊失敗: ' + err.message;
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
/* Reuse Login styles */
.login-container {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 100vh;
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
  text-align: center;
}

h2 {
  color: #E65100;
  margin-bottom: 8px;
}

.subtitle {
  color: #666;
  margin-bottom: 24px;
  font-size: 0.9em;
}

.form-group {
  margin-bottom: 16px;
  text-align: left;
}

.form-group label {
  display: block;
  margin-bottom: 6px;
  font-weight: bold;
  color: #333;
}

.form-group input, .form-group select {
  width: 100%;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 1em;
}

.error-msg {
  background: #ffebee;
  color: #c62828;
  padding: 10px;
  border-radius: 6px;
  margin-bottom: 16px;
}

.success-msg {
    background: #e8f5e9;
    color: #2e7d32;
    padding: 16px;
    border-radius: 6px;
    margin-bottom: 16px;
}

.full-width {
  width: 100%;
  padding: 12px;
  font-size: 1.1em;
  margin-top: 8px;
}

.footer-links {
  margin-top: 16px;
  font-size: 0.9em;
}

.footer-links a {
  color: #666;
  text-decoration: none;
}
</style>
