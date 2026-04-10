<template>
  <div class="login-container">
    <div class="login-card">
      <h2>老師註冊 (Teacher Register)</h2>
      <p class="subtitle">Join Taipei AllTrue Team</p>
      
      <div v-if="error" ref="errorRef" class="error-msg">{{ error }}</div>
      <div v-if="success" class="success-msg">
        {{ successMessage }}
        <br>
        <a href="#" @click.prevent="$emit('switch-mode', 'login')">返回登入</a>
      </div>

      <div v-else>
        <div class="form-group">
            <label>姓名 (Name)</label>
            <input v-model="form.username" placeholder="Your Name" />
        </div>

        <div class="form-group">
            <label>登入帳號 (Account)</label>
            <input v-model="form.account" type="text" placeholder="例如：teacher001" />
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
          <label>可授課科目 (Subjects)</label>
          <div class="chip-wrap">
            <label
              v-for="s in subjects"
              :key="'subj-'+s.id"
              :class="['chip', { selected: form.subject_ids.includes(s.id) }]"
            >
              <input type="checkbox" :value="s.id" v-model="form.subject_ids" />
              <span>{{ s.name }}</span>
            </label>
          </div>
        </div>

        <div class="form-group" v-if="selectedSubjects.length > 0">
          <label>授課學段 (Teaching Levels)</label>
          <table class="scope-table">
            <thead>
              <tr>
                <th>科目</th>
                <th v-for="lv in LEVEL_OPTIONS" :key="'hdr-'+lv.value">{{ lv.label }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="subject in selectedSubjects" :key="'scope-'+subject.id">
                <td>{{ subject.name }}</td>
                <td v-for="lv in LEVEL_OPTIONS" :key="'scope-'+subject.id+'-'+lv.value">
                  <input
                    type="checkbox"
                    :checked="hasScope(subject.id, lv.value)"
                    @change="toggleScope(subject.id, lv.value)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
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
import { computed, ref, onMounted, nextTick } from 'vue';
import { supabase } from '../supabase';
import { branches as BRANCHES, loadBranches } from '../lib/useBranches';

const emit = defineEmits(['switch-mode']);

const form = ref({
    username: '',
    account: '',
    password: '',
    confirmPassword: '',
    branch_id: null,
    phone: '',
    subject_ids: [],
    subject_level_scopes: [],
});

const subjects = ref([]);
const LEVEL_OPTIONS = [
  { value: 'elementary', label: '國小' },
  { value: 'junior', label: '國中' },
  { value: 'high', label: '高中' },
];
const selectedSubjects = computed(() =>
  subjects.value.filter((s) => form.value.subject_ids.includes(s.id))
);

function hasScope(subjectId, level) {
  return form.value.subject_level_scopes.some(
    (s) => Number(s.subject_id) === Number(subjectId) && s.level === level
  );
}

function toggleScope(subjectId, level) {
  const next = [...form.value.subject_level_scopes];
  const idx = next.findIndex(
    (s) => Number(s.subject_id) === Number(subjectId) && s.level === level
  );
  if (idx >= 0) {
    next.splice(idx, 1);
  } else {
    next.push({ subject_id: Number(subjectId), level });
  }
  form.value.subject_level_scopes = next;
}

// Load branches on mount (public endpoint, no auth needed)
onMounted(async () => {
    await Promise.all([loadBranches(), loadSubjects()]);
    if (!form.value.branch_id && BRANCHES.value.length > 0) {
        form.value.branch_id = BRANCHES.value[0].id;
    }
});

async function loadSubjects() {
  try {
    const res = await fetch('/api/v1/subjects-public', { headers: { Accept: 'application/json' } });
    const json = await res.json().catch(() => []);
    subjects.value = Array.isArray(json)
      ? json.map((s) => ({ id: Number(s.id), name: s.name || s.Subject_Name || String(s.id) })).filter((s) => Number.isFinite(s.id))
      : [];
  } catch {
    subjects.value = [];
  }
}

const loading = ref(false);
const error = ref('');
const success = ref(false);
const successMessage = ref('註冊成功！請通知主任審核您的帳號。');
const errorRef = ref(null);

function showError(msg) {
    error.value = msg;
    nextTick(() => {
        errorRef.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
}

const handleRegister = async () => {
    if (!form.value.username || !form.value.account || !form.value.password) {
        showError('請填寫完整資訊');
        return;
    }
    if (form.value.password.length < 4) {
        showError('密碼至少 4 個字元');
        return;
    }
    if (form.value.password !== form.value.confirmPassword) {
        showError('兩次密碼不一致，請重新確認');
        return;
    }
    if (!form.value.branch_id) {
        showError('請選擇預設分校');
        return;
    }
    
    loading.value = true;
    error.value = '';

    try {
        const { data, error: authError } = await supabase.auth.signUp({
            account: form.value.account,
            password: form.value.password,
            options: {
                data: {
                    name: form.value.username,
                    role: 'teacher',
                    branch_id: form.value.branch_id,
                    phone: form.value.phone || '',
                    subject_ids: (form.value.subject_ids || [])
                      .map((id) => Number(id))
                      .filter((id) => Number.isFinite(id) && id > 0),
                    subject_level_scopes: (form.value.subject_level_scopes || [])
                      .map((s) => ({ subject_id: Number(s.subject_id), level: s.level }))
                      .filter((s) => Number.isFinite(s.subject_id) && s.subject_id > 0 && LEVEL_OPTIONS.some((lv) => lv.value === s.level))
                }
            }
        });

        if (authError) throw authError;
        if (data) {
            if (data?.already_exists) {
                success.value = false;
                showError(`${data.message || '此帳號已存在'}；若忘記密碼請回登入頁點「忘記密碼」。`);
                return;
            }
            successMessage.value = data?.message || '註冊成功！請通知主任審核您的帳號。';
            success.value = true;
        }

    } catch (err) {
        showError('註冊失敗: ' + err.message);
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
  align-items: flex-start;
  min-height: 100vh;
  padding: 40px 16px;
  background-color: #f5f5f5;
  background-image: linear-gradient(135deg, #FFF8E1 0%, #FFE0B2 100%);
  box-sizing: border-box;
}

.login-card {
  background: #fff;
  padding: 40px;
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.1);
  width: 100%;
  max-width: 560px;
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

.chip-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: 1px solid #ddd;
  border-radius: 999px;
  padding: 6px 10px;
  background: #fafafa;
  font-size: 0.9rem;
}

.chip.selected {
  border-color: #E65100;
  background: #fff3e0;
}

.chip.disabled {
  opacity: 0.6;
}

.scope-table {
  width: 100%;
  border-collapse: collapse;
}

.scope-table th,
.scope-table td {
  border: 1px solid #eee;
  padding: 8px;
  text-align: center;
}

.scope-table th:first-child,
.scope-table td:first-child {
  text-align: left;
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
