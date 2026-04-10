<template>
  <div class="teacher-profile-page">
    <div class="card profile-card">
      <div class="card-header">
        <h2>個人管理</h2>
        <p>可修改姓名、登入帳號、密碼，以及教學設定。</p>
      </div>

      <div v-if="loading" class="loading-row">載入中...</div>

      <template v-else>
        <section class="section-block">
          <h3>基本資料</h3>
          <div v-if="profileMessage" :class="['message', profileMessage.type]">{{ profileMessage.text }}</div>
          <div class="field-row">
            <label for="profile-name">姓名</label>
            <input id="profile-name" v-model.trim="profileForm.name" type="text" placeholder="請輸入姓名" />
          </div>
          <div class="field-row">
            <label for="profile-email">登入帳號</label>
            <input id="profile-email" v-model.trim="profileForm.email" type="text" placeholder="請輸入登入帳號" />
          </div>
          <div class="field-row">
            <label for="profile-phone">手機（選填）</label>
            <input id="profile-phone" v-model.trim="profileForm.phone" type="text" placeholder="09xxxxxxxx" />
          </div>
          <div class="actions">
            <button class="primary" :disabled="savingProfile" @click="saveProfile">
              {{ savingProfile ? '儲存中...' : '儲存基本資料' }}
            </button>
          </div>
        </section>

        <section class="section-block">
          <h3>教學設定</h3>
          <div v-if="teachingMessage" :class="['message', teachingMessage.type]">{{ teachingMessage.text }}</div>

          <div class="field-row">
            <label for="teacher-main-branch">主分校</label>
            <select id="teacher-main-branch" v-model.number="teachingForm.branch_id">
              <option v-for="b in branchOptions" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>

          <div class="field-row">
            <label>跨校支援</label>
            <div class="chip-wrap">
              <label
                v-for="b in branchOptions"
                :key="'tb-'+b.id"
                :class="['chip', { selected: teachingForm.multi_branches.includes(b.id), disabled: teachingForm.branch_id === b.id }]"
              >
                <input
                  type="checkbox"
                  :value="b.id"
                  v-model="teachingForm.multi_branches"
                  :disabled="teachingForm.branch_id === b.id"
                />
                <span>{{ b.name }}</span>
              </label>
            </div>
          </div>

          <div class="field-row">
            <label>可授課科目</label>
            <div class="chip-wrap">
              <label
                v-for="s in subjects"
                :key="'sub-'+s.id"
                :class="['chip', { selected: teachingForm.subject_ids.includes(s.id) }]"
              >
                <input type="checkbox" :value="s.id" v-model="teachingForm.subject_ids" />
                <span>{{ s.name }}</span>
              </label>
            </div>
          </div>

          <div class="field-row" v-if="selectedSubjects.length > 0">
            <label>授課學段</label>
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

          <div class="actions">
            <button class="primary" :disabled="savingTeaching" @click="saveTeaching">
              {{ savingTeaching ? '儲存中...' : '儲存教學設定' }}
            </button>
          </div>
        </section>

        <section class="section-block">
          <h3>修改密碼</h3>
          <div v-if="passwordMessage" :class="['message', passwordMessage.type]">{{ passwordMessage.text }}</div>
          <div class="field-row">
            <label for="current-password">目前密碼</label>
            <input
              id="current-password"
              v-model="passwordForm.currentPassword"
              type="password"
              autocomplete="current-password"
              placeholder="請輸入目前密碼"
            />
          </div>
          <div class="field-row">
            <label for="new-password">新密碼</label>
            <input
              id="new-password"
              v-model="passwordForm.newPassword"
              type="password"
              autocomplete="new-password"
              placeholder="至少 6 碼"
            />
          </div>
          <div class="field-row">
            <label for="confirm-password">確認新密碼</label>
            <input
              id="confirm-password"
              v-model="passwordForm.confirmPassword"
              type="password"
              autocomplete="new-password"
              placeholder="再次輸入新密碼"
            />
          </div>
          <div class="actions">
            <button class="primary" :disabled="savingPassword" @click="savePassword">
              {{ savingPassword ? '更新中...' : '更新密碼' }}
            </button>
          </div>
        </section>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({
  token: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['profile-updated']);

const loading = ref(true);
const savingProfile = ref(false);
const savingPassword = ref(false);
const savingTeaching = ref(false);
const profileMessage = ref(null);
const passwordMessage = ref(null);
const teachingMessage = ref(null);

const profileForm = ref({
  name: '',
  email: '',
  phone: '',
});

const passwordForm = ref({
  currentPassword: '',
  newPassword: '',
  confirmPassword: '',
});

const LEVEL_OPTIONS = [
  { value: 'elementary', label: '國小' },
  { value: 'junior', label: '國中' },
  { value: 'high', label: '高中' },
];

const branchOptions = ref([]);
const subjects = ref([]);
const teachingForm = ref({
  branch_id: null,
  multi_branches: [],
  subject_ids: [],
  subject_level_scopes: [],
});

const selectedSubjects = computed(() =>
  subjects.value.filter((s) => teachingForm.value.subject_ids.includes(s.id))
);

function hasScope(subjectId, level) {
  return teachingForm.value.subject_level_scopes.some(
    (s) => Number(s.subject_id) === Number(subjectId) && s.level === level
  );
}

function toggleScope(subjectId, level) {
  const next = [...teachingForm.value.subject_level_scopes];
  const idx = next.findIndex(
    (s) => Number(s.subject_id) === Number(subjectId) && s.level === level
  );
  if (idx >= 0) {
    next.splice(idx, 1);
  } else {
    next.push({ subject_id: Number(subjectId), level });
  }
  teachingForm.value.subject_level_scopes = next;
}

async function loadProfile() {
  loading.value = true;
  profileMessage.value = null;
  passwordMessage.value = null;

  if (!props.token) {
    loading.value = false;
    profileMessage.value = { type: 'error', text: '請重新登入後再試。' };
    return;
  }

  try {
    const res = await fetch('/api/v1/me', {
      headers: {
        Authorization: `Bearer ${props.token}`,
        Accept: 'application/json',
      },
    });
    const json = await res.json();
    if (!res.ok) {
      profileMessage.value = { type: 'error', text: json?.message || '讀取資料失敗。' };
      return;
    }

    profileForm.value = {
      name: json?.name || '',
      email: json?.email || '',
      phone: json?.phone || '',
    };
    const teacherConfig = json?.teacher_config || {};
    const branchId = Number(teacherConfig.branch_id);
    const branchIds = Array.isArray(teacherConfig.branch_ids) ? teacherConfig.branch_ids.map((id) => Number(id)) : [];
    teachingForm.value = {
      branch_id: Number.isFinite(branchId) ? branchId : null,
      multi_branches: branchIds.filter((id) => id !== branchId),
      subject_ids: (teacherConfig.subject_ids || []).map((id) => Number(id)).filter((id) => Number.isFinite(id)),
      subject_level_scopes: Array.isArray(teacherConfig.subject_level_scopes)
        ? teacherConfig.subject_level_scopes.map((s) => ({ subject_id: Number(s.subject_id), level: s.level }))
        : [],
    };
  } catch {
    profileMessage.value = { type: 'error', text: '讀取資料失敗，請稍後再試。' };
  } finally {
    loading.value = false;
  }
}

async function loadTeachingOptions() {
  if (!props.token) return;
  try {
    const [branchRes, subjectRes] = await Promise.all([
      fetch('/api/v1/branches', { headers: { Accept: 'application/json' } }),
      fetch('/api/v1/subjects', {
        headers: {
          Authorization: `Bearer ${props.token}`,
          Accept: 'application/json',
        },
      }),
    ]);
    const branchJson = await branchRes.json().catch(() => []);
    const subjectJson = await subjectRes.json().catch(() => []);
    branchOptions.value = Array.isArray(branchJson)
      ? branchJson.map((b) => ({ id: Number(b.id), name: b.name })).filter((b) => Number.isFinite(b.id))
      : [];
    subjects.value = Array.isArray(subjectJson)
      ? subjectJson.map((s) => ({ id: Number(s.id), name: s.name || s.Subject_Name || String(s.id) })).filter((s) => Number.isFinite(s.id))
      : [];
  } catch {
    branchOptions.value = [];
    subjects.value = [];
  }
}

async function saveProfile() {
  profileMessage.value = null;
  if (!profileForm.value.name) {
    profileMessage.value = { type: 'error', text: '請輸入姓名。' };
    return;
  }
  if (!profileForm.value.email) {
    profileMessage.value = { type: 'error', text: '請輸入登入信箱。' };
    return;
  }
  if (!props.token) {
    profileMessage.value = { type: 'error', text: '登入已失效，請重新登入。' };
    return;
  }

  savingProfile.value = true;
  try {
    const payload = {
      name: profileForm.value.name,
      email: profileForm.value.email,
      phone: profileForm.value.phone || null,
    };
    const res = await fetch('/api/v1/me', {
      method: 'PUT',
      headers: {
        Authorization: `Bearer ${props.token}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (!res.ok) {
      profileMessage.value = { type: 'error', text: json?.message || '儲存失敗。' };
      return;
    }

    profileMessage.value = { type: 'success', text: '基本資料已更新。' };
    emit('profile-updated', {
      name: json?.name || profileForm.value.name,
      email: json?.email || profileForm.value.email,
      phone: json?.phone ?? profileForm.value.phone ?? '',
    });
  } catch {
    profileMessage.value = { type: 'error', text: '儲存失敗，請稍後再試。' };
  } finally {
    savingProfile.value = false;
  }
}

async function saveTeaching() {
  teachingMessage.value = null;
  if (!props.token) {
    teachingMessage.value = { type: 'error', text: '登入已失效，請重新登入。' };
    return;
  }
  const branchId = Number(teachingForm.value.branch_id);
  if (!Number.isFinite(branchId) || branchId <= 0) {
    teachingMessage.value = { type: 'error', text: '請先選擇主分校。' };
    return;
  }
  savingTeaching.value = true;
  try {
    const payload = {
      branch_id: branchId,
      multi_branches: (teachingForm.value.multi_branches || [])
        .map((id) => Number(id))
        .filter((id) => Number.isFinite(id) && id > 0 && id !== branchId),
      subject_ids: (teachingForm.value.subject_ids || [])
        .map((id) => Number(id))
        .filter((id) => Number.isFinite(id) && id > 0),
      subject_level_scopes: (teachingForm.value.subject_level_scopes || [])
        .map((s) => ({ subject_id: Number(s.subject_id), level: s.level }))
        .filter((s) => Number.isFinite(s.subject_id) && s.subject_id > 0 && LEVEL_OPTIONS.some((lv) => lv.value === s.level)),
    };
    const res = await fetch('/api/v1/me', {
      method: 'PUT',
      headers: {
        Authorization: `Bearer ${props.token}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(payload),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      teachingMessage.value = { type: 'error', text: json?.message || '教學設定更新失敗。' };
      return;
    }
    teachingMessage.value = { type: 'success', text: '教學設定已更新。' };
    const teacherConfig = json?.teacher_config || {};
    const nextBranchId = Number(teacherConfig.branch_id || payload.branch_id);
    const nextBranchIds = Array.isArray(teacherConfig.branch_ids) ? teacherConfig.branch_ids.map((id) => Number(id)) : [nextBranchId];
    teachingForm.value = {
      branch_id: nextBranchId,
      multi_branches: nextBranchIds.filter((id) => id !== nextBranchId),
      subject_ids: Array.isArray(teacherConfig.subject_ids) ? teacherConfig.subject_ids.map((id) => Number(id)) : payload.subject_ids,
      subject_level_scopes: Array.isArray(teacherConfig.subject_level_scopes) ? teacherConfig.subject_level_scopes : payload.subject_level_scopes,
    };
  } catch {
    teachingMessage.value = { type: 'error', text: '教學設定更新失敗，請稍後再試。' };
  } finally {
    savingTeaching.value = false;
  }
}

async function savePassword() {
  passwordMessage.value = null;
  if (!passwordForm.value.currentPassword) {
    passwordMessage.value = { type: 'error', text: '請輸入目前密碼。' };
    return;
  }
  if (!passwordForm.value.newPassword) {
    passwordMessage.value = { type: 'error', text: '請輸入新密碼。' };
    return;
  }
  if (passwordForm.value.newPassword.length < 6) {
    passwordMessage.value = { type: 'error', text: '新密碼至少 6 碼。' };
    return;
  }
  if (passwordForm.value.newPassword !== passwordForm.value.confirmPassword) {
    passwordMessage.value = { type: 'error', text: '新密碼與確認密碼不一致。' };
    return;
  }
  if (!props.token) {
    passwordMessage.value = { type: 'error', text: '登入已失效，請重新登入。' };
    return;
  }

  savingPassword.value = true;
  try {
    const res = await fetch('/api/v1/me', {
      method: 'PUT',
      headers: {
        Authorization: `Bearer ${props.token}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        current_password: passwordForm.value.currentPassword,
        password: passwordForm.value.newPassword,
        password_confirmation: passwordForm.value.confirmPassword,
      }),
    });
    const json = await res.json();
    if (!res.ok) {
      passwordMessage.value = { type: 'error', text: json?.message || '密碼更新失敗。' };
      return;
    }

    passwordMessage.value = { type: 'success', text: '密碼已更新。' };
    passwordForm.value = {
      currentPassword: '',
      newPassword: '',
      confirmPassword: '',
    };
  } catch {
    passwordMessage.value = { type: 'error', text: '密碼更新失敗，請稍後再試。' };
  } finally {
    savingPassword.value = false;
  }
}

watch(
  () => props.token,
  async (nextToken, prevToken) => {
    if (!nextToken || nextToken === prevToken) return;
    await Promise.all([loadTeachingOptions(), loadProfile()]);
  }
);

onMounted(async () => {
  await Promise.all([loadTeachingOptions(), loadProfile()]);
});
</script>

<style scoped>
.teacher-profile-page {
  max-width: 900px;
  margin: 0 auto;
  padding: 16px;
}

.profile-card {
  padding: 24px;
}

.card-header {
  margin-bottom: 18px;
}

.card-header h2 {
  margin: 0;
}

.card-header p {
  margin: 6px 0 0;
  color: var(--text-light);
}

.section-block {
  border-top: 1px solid #eceff1;
  padding-top: 20px;
  margin-top: 20px;
}

.section-block h3 {
  margin: 0 0 12px;
}

.field-row {
  display: grid;
  gap: 8px;
  margin-bottom: 12px;
}

.field-row input,
.field-row select {
  width: 100%;
  border: 1px solid #d6dbe1;
  border-radius: 8px;
  padding: 10px 12px;
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
  border: 1px solid #d6dbe1;
  border-radius: 999px;
  padding: 6px 10px;
  background: #fafafa;
  font-size: 0.9rem;
}

.chip.selected {
  border-color: #1976d2;
  background: #e3f2fd;
}

.chip.disabled {
  opacity: 0.6;
}

.scope-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.92rem;
}

.scope-table th,
.scope-table td {
  border: 1px solid #e0e0e0;
  padding: 8px 10px;
  text-align: center;
}

.scope-table th:first-child,
.scope-table td:first-child {
  text-align: left;
}

.actions {
  margin-top: 14px;
}

.primary {
  padding: 10px 14px;
  border: none;
  border-radius: 8px;
  background: #1976d2;
  color: #fff;
  cursor: pointer;
}

.primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.message {
  margin-bottom: 10px;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 0.95rem;
}

.message.success {
  background: #ecf8ef;
  color: #1b5e20;
}

.message.error {
  background: #ffebee;
  color: #b71c1c;
}

.loading-row {
  color: var(--text-light);
}
</style>
