<template>
  <!-- Standalone parent portal (accessible without login via #/parent or ?parent=1) -->
  <ParentPortal v-if="isStandaloneParent" :standalone="true" />

  <div v-else-if="loading" class="loading-screen">
    <div class="spinner"></div>
    <span>載入中...</span>
  </div>

  <Login v-else-if="!session" @login-success="handleLoginSuccess" />

  <div v-else class="app-layout">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="sidebar-brand">
        <div class="brand-logo-container">
          <img :src="logoUrl" alt="全真一對一 Logo" class="brand-logo" onerror="this.style.display='none'" />
        </div>
        <div>
          <h1>全真一對一</h1>
          <div class="brand-sub">教務管理系統</div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <template v-if="isDirector">
          <button @click="active = 'director'" :class="{ active: active === 'director' }">
            <span class="nav-icon">📊</span>總覽儀表板
          </button>
          <button @click="active = 'calendar'" :class="{ active: active === 'calendar' }">
            <span class="nav-icon">📅</span>智慧排課
          </button>
          <button @click="active = 'students'" :class="{ active: active === 'students' }">
            <span class="nav-icon">🎓</span>學生管理
          </button>
          <button @click="active = 'teachers'" :class="{ active: active === 'teachers' }">
            <span class="nav-icon">👨‍🏫</span>老師管理
          </button>
          <button @click="active = 'course-mgmt'" :class="{ active: active === 'course-mgmt' }">
            <span class="nav-icon">📋</span>課程管理
          </button>
          <button @click="active = 'classroom'" :class="{ active: active === 'classroom' }">
            <span class="nav-icon">🏫</span>教室管理
          </button>
          <button @click="active = 'subject-units'" :class="{ active: active === 'subject-units' }">
            <span class="nav-icon">📐</span>科目數統計
          </button>
          <div class="nav-divider"></div>
          <button @click="active = 'attendance'" :class="{ active: active === 'attendance' }">
            <span class="nav-icon">🔖</span>出缺勤管理
          </button>
          <button @click="active = 'learning'" :class="{ active: active === 'learning' }">
            <span class="nav-icon">📝</span>學習評量表
          </button>
          <div class="nav-divider"></div>
          <button @click="active = 'parent'" :class="{ active: active === 'parent' }">
            <span class="nav-icon">👪</span>家長入口
          </button>
          <button v-if="role === 'super_admin'" @click="active = 'director-accounts'" :class="{ active: active === 'director-accounts' }">
            <span class="nav-icon">👤</span>主任審核
          </button>
          <div class="nav-divider"></div>
          <button @click="showProfileModal = true">
            <span class="nav-icon">⚙️</span>帳號設定
          </button>
        </template>

        <template v-if="isTeacher">
          <button @click="active = 'learning'" :class="{ active: active === 'learning' }">
            <span class="nav-icon">📅</span>課表 & 評量
          </button>
          <div class="nav-divider"></div>
          <button @click="showProfileModal = true">
            <span class="nav-icon">⚙️</span>帳號設定
          </button>
        </template>

        <template v-if="!isDirector && !isTeacher">
          <div class="nav-no-role-hint">無選單（身分未設定）</div>
        </template>
      </nav>

      <div class="sidebar-footer">
        <div class="user-block">
          <div class="user-avatar">{{ avatarLetter }}</div>
          <div>
            <div class="user-name">{{ userProfile?.username || session?.user?.name || 'User' }}</div>
            <div class="user-role">{{ roleLabel }}</div>
            <div v-if="role === 'super_admin'" class="user-role-hint">可檢視所有分校</div>
          </div>
        </div>
        <div v-if="isDirector" class="branch-switcher">
          <div class="branch-switcher-label">切換分校</div>
          <div class="branch-buttons">
            <button
              v-for="b in branches"
              :key="b.id"
              :class="['branch-btn', { active: currentBranch === b.id }]"
              @click="currentBranch = b.id"
            >{{ b.name.split('(')[0].trim() }}</button>
          </div>
        </div>
        <button class="logout-btn" @click="logout">🚪 登出系統</button>
      </div>
    </aside>

    <!-- Main Content -->
    <div class="main-content">
      <!-- 手機版：頂部分校選擇列（小螢幕時側欄分校區塊會被隱藏，改由此選擇） -->
      <div v-if="isDirector && branches.length > 0" class="mobile-branch-bar">
        <label class="mobile-branch-label" for="mobile-branch-select">分校</label>
        <select
          id="mobile-branch-select"
          class="mobile-branch-select"
          :value="currentBranch"
          @change="(e) => { const v = (e.target).value; if (v !== '') currentBranch = Number(v); }"
        >
          <option value="">請選擇分校</option>
          <option v-for="b in branches" :key="b.id" :value="b.id">
            {{ b.name.split('(')[0].trim() }}
          </option>
        </select>
      </div>
      <DirectorDashboard v-if="isDirector && active === 'director'" :branch-id="currentBranch" />
      <SmartCalendar v-if="active === 'calendar'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" :initial-teacher-id="initialTeacherIdForNav" @clear-initial-teacher="initialTeacherIdForNav = null" />
      <StudentsList v-if="isDirector && active === 'students'" :branch-id="currentBranch" />
      <TeachersList v-if="isDirector && active === 'teachers'" :branch-id="currentBranch" @navigate-to-schedule="onNavigateToSchedule" />
      <CourseManagement v-if="isDirector && active === 'course-mgmt'" :branch-id="currentBranch" :initial-teacher-id="initialTeacherIdForNav" @clear-initial-teacher="initialTeacherIdForNav = null" />
      <ClassroomManagement v-if="isDirector && active === 'classroom'" :branch-id="currentBranch" />
      <SubjectUnitsPage v-if="isDirector && active === 'subject-units'" :branch-id="currentBranch" />

      <AttendancePage v-if="isDirector && active === 'attendance'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" />
      <LearningRecordsPage v-if="active === 'learning'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" />
      <ParentPortal v-if="active === 'parent'" />
      <DirectorAccountsPage v-if="role === 'super_admin' && active === 'director-accounts'" :token="session?.access_token ?? ''" />

      <!-- 身分無法辨識時顯示說明，避免登入後一片空白 -->
      <div v-if="!isDirector && !isTeacher" class="card" style="max-width: 480px; margin: 2rem auto; padding: 2rem; text-align: center;">
        <h2 style="margin-bottom: 1rem;">⚠️ 無法顯示功能</h2>
        <p style="color: var(--text-light); margin-bottom: 1rem;">您的帳號尚未設定為「主任」或「老師」身分，因此無法顯示操作選單。</p>
        <p style="font-size: 0.9rem; color: var(--text-light);">請聯繫系統管理員，在後台將您的帳號身分設為主任或老師後再登入。</p>
        <p style="margin-top: 1.5rem; font-size: 0.85rem;">目前辨識到的身分：<strong>{{ role || '（未取得）' }}</strong></p>
      </div>
    </div>

    <!-- Teacher Profile Settings Modal -->
    <div v-if="showProfileModal" class="profile-overlay" @click.self="showProfileModal = false">
      <div class="profile-modal">
        <h3>⚙️ 帳號設定</h3>
        <div v-if="profileSaveMsg" :class="['profile-msg', profileSaveMsg.type]">{{ profileSaveMsg.text }}</div>
        <div class="profile-form-group">
          <label>姓名（同時也是登入帳號）</label>
          <input v-model="profileForm.username" placeholder="您的姓名" />
        </div>
        <div class="profile-form-group">
          <label>Email</label>
          <input v-model="profileForm.email" type="text" placeholder="選填" />
        </div>
        <div class="profile-form-group">
          <label>新密碼（不改請留空）</label>
          <input v-model="profileForm.newPassword" type="password" placeholder="輸入新密碼" />
        </div>
        <div class="profile-form-group">
          <label>確認新密碼</label>
          <input v-model="profileForm.confirmPassword" type="password" placeholder="再次輸入新密碼" />
        </div>
        <div class="profile-actions">
          <button class="ghost" @click="showProfileModal = false">取消</button>
          <button class="primary" @click="saveProfile">儲存</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import { supabase } from './supabase';
import { branches, loadBranches, loadBranchesForDirector, getDefaultBranchId } from './lib/useBranches';
import logoUrl from './assets/logo.png';

// Pages
import Login from './pages/Login.vue';

import StudentsList from './pages/StudentsList.vue';
import LearningRecordsPage from './pages/LearningRecordsPage.vue';
import SmartCalendar from './pages/SmartCalendar.vue';
import DirectorDashboard from './pages/DirectorDashboard.vue';
import ParentPortal from './pages/ParentPortal.vue';
import CourseManagement from './pages/CourseManagement.vue';
import ClassroomManagement from './pages/ClassroomManagement.vue';
import TeachersList from './pages/TeachersList.vue';
import AttendancePage from './pages/AttendancePage.vue';
import SubjectUnitsPage from './pages/SubjectUnitsPage.vue';
import DirectorAccountsPage from './pages/DirectorAccountsPage.vue';

// Detect standalone parent portal access via URL hash or query param
const isStandaloneParent = computed(() => {
  const hash = window.location.hash;
  const params = new URLSearchParams(window.location.search);
  return hash === '#/parent' || params.get('parent') === '1';
});

const session = ref(null);
const userProfile = ref(null);
const loading = ref(true);

const active = ref('director');
const currentBranch = ref(null); // Will be set after branches load
const initialTeacherIdForNav = ref(null);

function onNavigateToSchedule({ teacherId, target }) {
  initialTeacherIdForNav.value = teacherId ?? null;
  if (target === 'calendar') active.value = 'calendar';
  else active.value = 'course-mgmt';
}

// Prefer role from session (set by backend at login); profile API only returns teachers, so directors/super_admin would get wrong role otherwise
const role = computed(() => session.value?.user?.role ?? userProfile.value?.role ?? 'student');
const isDirector = computed(() => role.value === 'director' || role.value === 'admin' || role.value === 'super_admin');
const isTeacher = computed(() => role.value === 'teacher');
const roleLabel = computed(() => {
  if (role.value === 'super_admin') return '超級管理員 Super Admin';
  if (isDirector.value) return '主任 Director';
  if (isTeacher.value) return '老師 Teacher';
  return role.value;
});
const avatarLetter = computed(() => {
  const name = userProfile.value?.username || session.value?.user?.name || 'U';
  return name.charAt(0).toUpperCase();
});

// When director/super_admin: load branches from authenticated /api/v1/campuses and set currentBranch
async function ensureDirectorBranches() {
    const s = session.value;
    if (!s?.user) return;
    const r = s.user.role ?? userProfile.value?.role;
    if (r !== 'director' && r !== 'super_admin') return;

    let list = [];
    if (s?.access_token) list = await loadBranchesForDirector(s.access_token);
    // Fallback for super_admin: use public branches if /campuses fails or returns empty
    if (list.length === 0 && r === 'super_admin') {
        await loadBranches();
        list = branches.value;
    }
    if (list.length > 0) {
        branches.value = list;
        const savedBranch = localStorage.getItem('app_branch');
        if (savedBranch) {
            const savedInt = parseInt(savedBranch, 10);
            const matchById = list.find(b => b.id === savedInt);
            const matchByCode = list.find(b => b.code === savedBranch);
            currentBranch.value = matchById ? matchById.id : (matchByCode ? matchByCode.id : list[0].id);
        } else {
            currentBranch.value = list[0].id;
        }
    }
}

// Auth Listener
onMounted(async () => {
    // Load branches from API (public endpoint, no auth needed)
    await loadBranches();

    // Restore saved branch or use first branch as default
    const savedBranch = localStorage.getItem('app_branch');
    if (savedBranch) {
        const savedInt = parseInt(savedBranch, 10);
        const matchById = branches.value.find(b => b.id === savedInt);
        const matchByCode = branches.value.find(b => b.code === savedBranch);
        currentBranch.value = matchById ? matchById.id : (matchByCode ? matchByCode.id : getDefaultBranchId());
    } else {
        currentBranch.value = getDefaultBranchId();
    }

    const { data } = await supabase.auth.getSession();
    session.value = data.session;

    if (session.value) {
        await fetchProfile(session.value.user.id);
        await ensureDirectorBranches();
    }
    loading.value = false;

    supabase.auth.onAuthStateChange(async (_event, _session) => {
        session.value = _session;
        if (_session) {
            await fetchProfile(_session.user.id);
            await ensureDirectorBranches();
        } else {
            userProfile.value = null;
        }
    });
});

const fetchProfile = async (uid) => {
    const { data } = await supabase.from('profiles').select('*').eq('id', uid).single();
    if (data) {
        userProfile.value = data;
        if (data.role === 'teacher') active.value = 'learning';
        else if (data.role === 'director') active.value = 'director';
    }
};

const handleLoginSuccess = async ({ user, profile }) => {
    // Session is already set by supabase.auth (signInWithPassword stores it)
    const { data } = await supabase.auth.getSession();
    session.value = data.session;
    userProfile.value = profile ?? null;

    if ((profile?.role ?? session.value?.user?.role) === 'teacher') active.value = 'learning';
    else if ((profile?.role ?? session.value?.user?.role) === 'director' || session.value?.user?.role === 'super_admin') active.value = 'director';
    await ensureDirectorBranches();
};

const logout = async () => {
    await supabase.auth.signOut();
};

// --- Teacher Profile Settings ---
const showProfileModal = ref(false);
const profileForm = ref({ username: '', email: '', newPassword: '', confirmPassword: '' });
const profileSaveMsg = ref(null);

watch(showProfileModal, async (val) => {
  if (!val) return;
  profileSaveMsg.value = null;
  const r = role.value;
  if (r === 'director' || r === 'super_admin') {
    const token = session.value?.access_token;
    if (token) {
      try {
        const res = await fetch('/api/v1/me', { headers: { Authorization: `Bearer ${token}` } });
        const me = await res.json();
        if (res.ok && me) {
          profileForm.value = { username: me.name || '', email: me.email || '', newPassword: '', confirmPassword: '' };
        } else {
          profileForm.value = { username: session.value?.user?.name || '', email: session.value?.user?.email || '', newPassword: '', confirmPassword: '' };
        }
      } catch {
        profileForm.value = { username: session.value?.user?.name || '', email: session.value?.user?.email || '', newPassword: '', confirmPassword: '' };
      }
    } else {
      profileForm.value = { username: session.value?.user?.name || '', email: session.value?.user?.email || '', newPassword: '', confirmPassword: '' };
    }
  } else if (userProfile.value) {
    profileForm.value = {
      username: userProfile.value.username || '',
      email: userProfile.value.email || '',
      newPassword: '',
      confirmPassword: ''
    };
  }
});

const saveProfile = async () => {
  profileSaveMsg.value = null;
  if (!profileForm.value.username) {
    profileSaveMsg.value = { type: 'error', text: '請輸入姓名' };
    return;
  }
  if (profileForm.value.newPassword && profileForm.value.newPassword !== profileForm.value.confirmPassword) {
    profileSaveMsg.value = { type: 'error', text: '兩次密碼不一致' };
    return;
  }
  if (profileForm.value.newPassword && profileForm.value.newPassword.length < 4) {
    profileSaveMsg.value = { type: 'error', text: '密碼至少 4 個字元' };
    return;
  }

  const r = role.value;
  if (r === 'director' || r === 'super_admin') {
    const token = session.value?.access_token;
    if (!token) {
      profileSaveMsg.value = { type: 'error', text: '請重新登入' };
      return;
    }
    const body = { name: profileForm.value.username, email: profileForm.value.email || null };
    if (profileForm.value.newPassword) body.password = profileForm.value.newPassword;
    const res = await fetch('/api/v1/me', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(body)
    });
    const data = await res.json();
    if (!res.ok) {
      profileSaveMsg.value = { type: 'error', text: data?.message || '儲存失敗' };
      return;
    }
    if (session.value?.user) {
      session.value.user.name = data.name;
      session.value.user.email = data.email;
    }
    profileSaveMsg.value = { type: 'success', text: '儲存成功！下次登入請使用新的帳號密碼。' };
    profileForm.value.newPassword = '';
    profileForm.value.confirmPassword = '';
    return;
  }

  const profileId = userProfile.value?.id;
  if (!profileId) {
    profileSaveMsg.value = { type: 'error', text: '無法取得帳號 ID，請重新登入' };
    return;
  }

  const updates = {
    username: profileForm.value.username,
    email: profileForm.value.email
  };
  if (profileForm.value.newPassword) {
    updates.password = profileForm.value.newPassword;
  }

  const { data, error } = await supabase.from('profiles').update(updates).eq('id', profileId);
  if (error) {
    profileSaveMsg.value = { type: 'error', text: '儲存失敗: ' + error.message };
    return;
  }
  if (!data) {
    profileSaveMsg.value = { type: 'error', text: '儲存失敗：找不到帳號資料，請重新登入後再試' };
    return;
  }
  userProfile.value = { ...userProfile.value, ...data };
  profileSaveMsg.value = { type: 'success', text: '儲存成功！下次登入請使用新的帳號密碼。' };
  profileForm.value.newPassword = '';
  profileForm.value.confirmPassword = '';
};

watch(currentBranch, (value) => {
  localStorage.setItem('app_branch', value);
});
</script>

<style scoped>
.user-role-hint {
  font-size: 0.7rem;
  color: #2e7d32;
  margin-top: 2px;
}
.nav-no-role-hint {
  padding: 12px 20px;
  font-size: 12px;
  color: #90a4ae;
  text-align: center;
}
</style>
