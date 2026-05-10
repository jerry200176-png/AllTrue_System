<template>
  <div class="profile-center-page">
    <div class="page-header" data-guide="profile-header">
      <h2>個人資料管理</h2>
      <div class="page-desc">統一管理個人資料、安全設定與通知偏好。</div>
      <div v-if="forcePasswordChange" class="force-banner">首次登入需先修改密碼，完成前其他功能將暫時鎖定。</div>
    </div>

    <div class="profile-tabs card" data-guide="profile-tabs">
      <button :class="['tab-btn', { active: activeTab === 'profile' }]" :disabled="forcePasswordChange" @click="setActiveTab('profile')">基本資料</button>
      <button :class="['tab-btn', { active: activeTab === 'security' }]" @click="setActiveTab('security')">安全性</button>
      <button :class="['tab-btn', { active: activeTab === 'notifications' }]" :disabled="forcePasswordChange" @click="setActiveTab('notifications')">通知偏好</button>
    </div>

    <div v-if="loading" class="card">載入中...</div>

    <template v-else>
      <section v-if="activeTab === 'profile'" class="card" data-guide="profile-active-panel">
        <h3>基本資料</h3>
        <div class="muted">更新姓名、登入帳號、手機與個人頭像。</div>

        <div class="profile-layout">
          <div class="avatar-panel">
            <div class="avatar-box">
              <img v-if="avatarUrl" :src="avatarUrl" alt="avatar" />
              <div v-else class="avatar-fallback">{{ avatarLetter }}</div>
            </div>
            <div v-if="avatarUrl" class="avatar-sidebar-preview-wrap">
              <span class="avatar-preview-label">側欄預覽（與左下角相同比例）</span>
              <div class="avatar-sidebar-preview">
                <img :src="avatarUrl" alt="" />
              </div>
            </div>
            <input
              ref="avatarInputRef"
              type="file"
              accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
              @change="onAvatarSelected"
            />
            <p class="avatar-hint">
              選檔後會開啟裁切：可拖曳與縮放選擇呈現區域。原始檔支援 JPEG／PNG／WebP，最多 8MB；iPhone HEIC 請先轉 JPEG。
            </p>
            <p v-if="uploadingAvatar" class="avatar-uploading">上傳中…</p>
          </div>

          <div class="form-panel">
            <div v-if="profileMsg" :class="['section-msg', profileMsg.type]">{{ profileMsg.text }}</div>
            <div class="form-group">
              <label>姓名</label>
              <input v-model.trim="profileForm.name" type="text" placeholder="請輸入姓名" />
            </div>
            <div class="form-group">
              <label>登入帳號</label>
              <input v-model.trim="profileForm.email" type="text" placeholder="請輸入登入帳號" />
            </div>
            <div class="form-group">
              <label>手機（選填）</label>
              <input v-model.trim="profileForm.phone" type="text" placeholder="09xxxxxxxx" />
            </div>
            <div class="actions">
              <button class="primary" :disabled="savingProfile" @click="submitProfile">
                {{ savingProfile ? '儲存中...' : '儲存基本資料' }}
              </button>
            </div>
          </div>
        </div>

        <AvatarCropModal
          v-model="showAvatarCrop"
          :image-src="avatarCropSrc"
          @confirm="onAvatarCropped"
          @cancel="revokeAvatarCropSrc"
        />

        <div v-if="isTeacher" class="teacher-settings-block">
          <h4>教學設定</h4>
          <div class="muted">老師可自行調整跨校支援、可授課科目與授課學段。</div>
          <div v-if="teachingMsg" :class="['section-msg', teachingMsg.type]">{{ teachingMsg.text }}</div>

          <div class="form-group">
            <label class="switch-row">
              <input
                type="checkbox"
                :checked="teacherUiSfxEnabled"
                @change="onTeacherUiSfxToggle($event)"
              />
              <span>介面操作音效</span>
            </label>
            <p class="muted" style="margin-top: 0.35rem; font-size: 0.85rem; line-height: 1.45">
              切換頁面、側欄收合、從工作台開啟待填評量時播放極短提示音（Web Audio 合成，非取樣音檔）。與工作台「待辦提醒音」分開；預設關閉以免教室干擾。
            </p>
          </div>

          <div class="form-group">
            <label class="switch-row">
              <input
                type="checkbox"
                :checked="teacherStreakDisplayEnabled"
                @change="onTeacherStreakDisplayToggle($event)"
              />
              <span>顯示連續使用天數（僅本機）</span>
            </label>
            <p class="muted" style="margin-top: 0.35rem; font-size: 0.85rem; line-height: 1.45">
              依登入日本機日曆計算連續使用天數，僅存於此瀏覽器；不上傳伺服器。關閉後教學工作台不再顯示該摘要（計數仍保留）。與
              <a href="https://github.com/jsjoeio/use-streak" target="_blank" rel="noopener noreferrer">use-streak</a>
              等 OSS 一樣採 local-first；職場情境建議維持預設關閉、由老師自行開啟（ethical opt-in）。
            </p>
          </div>

          <div class="form-group">
            <label>主分校</label>
            <select v-model.number="teachingForm.branch_id">
              <option v-for="b in branchOptions" :key="'tb-main-'+b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>

          <div class="form-group">
            <label>跨校支援</label>
            <div class="chip-wrap">
              <label
                v-for="b in branchOptions"
                :key="'tb-'+b.id"
                :class="['chip', { selected: teachingForm.multi_branches.includes(b.id), disabled: Number(teachingForm.branch_id) === Number(b.id) }]"
              >
                <input
                  type="checkbox"
                  :value="b.id"
                  v-model="teachingForm.multi_branches"
                  :disabled="Number(teachingForm.branch_id) === Number(b.id)"
                />
                <span>{{ b.name }}</span>
              </label>
            </div>
          </div>

          <div class="form-group">
            <label>可授課科目</label>
            <div class="chip-wrap">
              <label
                v-for="s in subjects"
                :key="'tb-subj-'+s.id"
                :class="['chip', { selected: teachingForm.subject_ids.includes(s.id) }]"
              >
                <input type="checkbox" :value="s.id" v-model="teachingForm.subject_ids" />
                <span>{{ s.name }}</span>
              </label>
            </div>
          </div>

          <div class="form-group" v-if="selectedTeachingSubjects.length > 0">
            <label>授課學段</label>
            <table class="scope-table">
              <thead>
                <tr>
                  <th>科目</th>
                  <th v-for="lv in LEVEL_OPTIONS" :key="'tb-hdr-'+lv.value">{{ lv.label }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="subject in selectedTeachingSubjects" :key="'tb-scope-'+subject.id">
                  <td>{{ subject.name }}</td>
                  <td v-for="lv in LEVEL_OPTIONS" :key="'tb-scope-'+subject.id+'-'+lv.value">
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
            <button class="primary" :disabled="savingTeaching" @click="submitTeaching">
              {{ savingTeaching ? '儲存中...' : '儲存教學設定' }}
            </button>
          </div>
        </div>
      </section>

      <section v-if="activeTab === 'security'" class="card" data-guide="profile-active-panel">
        <h3>安全性</h3>
        <div class="muted">修改密碼與查看最近登入記錄。</div>

        <div v-if="passwordMsg" :class="['section-msg', passwordMsg.type]">{{ passwordMsg.text }}</div>
        <div class="grid two">
          <div class="form-group">
            <label>目前密碼</label>
            <input v-model="passwordForm.currentPassword" type="password" autocomplete="current-password" />
          </div>
          <div class="form-group">
            <label>新密碼</label>
            <input v-model="passwordForm.newPassword" type="password" autocomplete="new-password" />
          </div>
        </div>
        <div class="form-group">
          <label>確認新密碼</label>
          <input v-model="passwordForm.confirmPassword" type="password" autocomplete="new-password" />
        </div>
        <div class="actions">
          <button class="primary" :disabled="savingPassword" @click="submitPassword">
            {{ savingPassword ? '更新中...' : '更新密碼' }}
          </button>
        </div>

        <div class="security-block">
          <div class="security-header">
            <h4>最近登入</h4>
            <button class="ghost small" @click="refreshSecurity">重新整理</button>
          </div>
          <div v-if="securityMsg" :class="['section-msg', securityMsg.type]">{{ securityMsg.text }}</div>
          <table v-if="securitySummary.recent_logins.length > 0">
            <thead>
              <tr>
                <th>時間</th>
                <th>裝置</th>
                <th>IP</th>
                <th>結果</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in securitySummary.recent_logins" :key="item.id">
                <td>{{ formatDateTime(item.login_at) }}</td>
                <td>{{ item.device_label || 'Unknown Device' }}</td>
                <td>{{ item.ip_address || '-' }}</td>
                <td>
                  <span :class="['status-tag', item.success ? 'active' : 'suspended']">
                    {{ item.success ? '成功' : '失敗' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else class="empty-text">目前尚無登入紀錄。</div>
        </div>

        <div class="security-block">
          <div class="security-header">
            <h4>目前登入裝置</h4>
            <button class="ghost small" :disabled="revokingOtherSessions" @click="handleLogoutOtherDevices">
              {{ revokingOtherSessions ? '處理中...' : '登出其他裝置' }}
            </button>
          </div>
          <table v-if="securitySummary.active_sessions.length > 0">
            <thead>
              <tr>
                <th>裝置</th>
                <th>建立時間</th>
                <th>到期時間</th>
                <th>狀態</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="session in securitySummary.active_sessions" :key="session.token_id">
                <td>{{ session.device_label || 'Unknown Device' }}</td>
                <td>{{ formatDateTime(session.created_at) }}</td>
                <td>{{ formatDateTime(session.expires_at) }}</td>
                <td>
                  <span :class="['status-tag', session.is_current ? 'active' : 'pending']">
                    {{ session.is_current ? '目前裝置' : '仍有效' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else class="empty-text">目前沒有可用的登入裝置資訊。</div>
        </div>
      </section>

      <section v-if="activeTab === 'notifications'" class="card" data-guide="profile-active-panel">
        <h3>通知偏好</h3>
        <div class="muted">目前僅儲存偏好設定，暫不影響既有通知流程。</div>
        <div v-if="prefMsg" :class="['section-msg', prefMsg.type]">{{ prefMsg.text }}</div>

        <div class="prefs-layout">
          <label class="switch-row">
            <input v-model="prefs.in_app_enabled" type="checkbox" />
            <span>站內通知</span>
          </label>
          <label class="switch-row">
            <input v-model="prefs.email_enabled" type="checkbox" />
            <span>Email 通知</span>
          </label>
          <label class="switch-row">
            <input v-model="prefs.line_enabled" type="checkbox" />
            <span>LINE 通知</span>
          </label>
        </div>

        <div class="grid two">
          <div class="form-group">
            <label>勿擾開始時間</label>
            <input v-model="prefs.quiet_hours_start" type="time" />
          </div>
          <div class="form-group">
            <label>勿擾結束時間</label>
            <input v-model="prefs.quiet_hours_end" type="time" />
          </div>
        </div>

        <h4>事件類型</h4>
        <div class="prefs-layout">
          <label class="switch-row">
            <input v-model="prefs.event_tuition" type="checkbox" />
            <span>學費提醒</span>
          </label>
          <label class="switch-row">
            <input v-model="prefs.event_learning_review" type="checkbox" />
            <span>評量審核</span>
          </label>
          <label class="switch-row">
            <input v-model="prefs.event_attendance" type="checkbox" />
            <span>出缺勤事件</span>
          </label>
          <label class="switch-row">
            <input v-model="prefs.event_system" type="checkbox" />
            <span>系統通知</span>
          </label>
        </div>

        <div class="actions">
          <button class="primary" :disabled="savingPrefs" @click="submitPrefs">
            {{ savingPrefs ? '儲存中...' : '儲存通知偏好' }}
          </button>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AvatarCropModal from '../components/AvatarCropModal.vue';
import {
  getMe,
  getNotificationPrefs,
  getSecuritySummary,
  logoutOtherSessions,
  updateMe,
  updateNotificationPrefs,
  uploadAvatar,
} from '../lib/profileService';
import {
  isTeacherUiSfxEnabled,
  setTeacherUiSfxEnabled,
  playTeacherUiSfx,
} from '../lib/teacherUiSfx';
import {
  isTeacherStreakDisplayEnabled,
  setTeacherStreakDisplayEnabled,
  notifyTeacherStreakRefresh,
} from '../lib/teacherLoginStreak';

const props = defineProps({
  token: {
    type: String,
    default: '',
  },
  forcePasswordChange: {
    type: Boolean,
    default: false,
  },
  initialTab: {
    type: String,
    default: 'profile',
  },
});

const emit = defineEmits(['profile-updated', 'password-change-complete']);

const loading = ref(true);
const activeTab = ref(props.forcePasswordChange ? 'security' : props.initialTab || 'profile');
const forcePasswordChange = computed(() => props.forcePasswordChange);
const isTeacher = ref(false);
const teacherUiSfxEnabled = ref(false);
const teacherStreakDisplayEnabled = ref(false);
const avatarUrl = ref('');
const avatarInputRef = ref(null);
const showAvatarCrop = ref(false);
const avatarCropSrc = ref('');

const savingProfile = ref(false);
const savingPassword = ref(false);
const savingPrefs = ref(false);
const uploadingAvatar = ref(false);
const savingTeaching = ref(false);
const revokingOtherSessions = ref(false);

const profileMsg = ref(null);
const passwordMsg = ref(null);
const prefMsg = ref(null);
const securityMsg = ref(null);
const teachingMsg = ref(null);

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

const prefs = ref(defaultPrefs());

const securitySummary = ref({
  recent_logins: [],
  active_sessions: [],
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

const selectedTeachingSubjects = computed(() =>
  subjects.value.filter((s) => teachingForm.value.subject_ids.includes(s.id))
);

const avatarLetter = computed(() => {
  const source = profileForm.value.name || profileForm.value.email || 'U';
  return source.charAt(0).toUpperCase();
});

function defaultPrefs() {
  return {
    in_app_enabled: true,
    email_enabled: false,
    line_enabled: false,
    quiet_hours_start: '22:00',
    quiet_hours_end: '08:00',
    event_tuition: true,
    event_learning_review: true,
    event_attendance: true,
    event_system: true,
  };
}

function setActiveTab(tab) {
  if (props.forcePasswordChange && tab !== 'security') {
    activeTab.value = 'security';
    return;
  }
  activeTab.value = tab;
}

function onTeacherUiSfxToggle(ev) {
  const on = Boolean(ev?.target?.checked);
  teacherUiSfxEnabled.value = on;
  setTeacherUiSfxEnabled(on);
  if (on) {
    playTeacherUiSfx('nav');
  }
}

function onTeacherStreakDisplayToggle(ev) {
  const on = Boolean(ev?.target?.checked);
  teacherStreakDisplayEnabled.value = on;
  setTeacherStreakDisplayEnabled(on);
  notifyTeacherStreakRefresh();
}

async function loadData() {
  loading.value = true;
  profileMsg.value = null;
  passwordMsg.value = null;
  prefMsg.value = null;
  securityMsg.value = null;

  if (!props.token) {
    loading.value = false;
    profileMsg.value = { type: 'error', text: '登入已失效，請重新登入。' };
    return;
  }

  if (props.forcePasswordChange) {
    activeTab.value = 'security';
  }

  try {
    const me = await getMe(props.token);
    isTeacher.value = (me?.role === 'teacher');
    teacherUiSfxEnabled.value = isTeacher.value && isTeacherUiSfxEnabled();
    teacherStreakDisplayEnabled.value = isTeacher.value && isTeacherStreakDisplayEnabled();
    profileForm.value = {
      name: me?.name || '',
      email: me?.email || '',
      phone: me?.phone || '',
    };
    avatarUrl.value = me?.avatar_url || '';
    hydrateTeachingConfig(me?.teacher_config || null);
    if (isTeacher.value) {
      await loadTeachingOptions();
    }

    if (me?.notification_preferences) {
      prefs.value = { ...defaultPrefs(), ...me.notification_preferences };
    } else {
      try {
        const prefRes = await getNotificationPrefs(props.token);
        prefs.value = { ...defaultPrefs(), ...(prefRes?.data || {}) };
      } catch {
        prefs.value = defaultPrefs();
      }
    }

    if (me?.security_summary) {
      securitySummary.value = normalizeSecuritySummary(me.security_summary);
    } else {
      await refreshSecurity();
    }
  } catch (error) {
    profileMsg.value = { type: 'error', text: error.message || '讀取資料失敗。' };
  } finally {
    loading.value = false;
  }
}

function hydrateTeachingConfig(config) {
  const branchId = Number(config?.branch_id);
  const branchIds = Array.isArray(config?.branch_ids) ? config.branch_ids.map((id) => Number(id)) : [];
  teachingForm.value = {
    branch_id: Number.isFinite(branchId) && branchId > 0 ? branchId : null,
    multi_branches: branchIds.filter((id) => id !== branchId),
    subject_ids: Array.isArray(config?.subject_ids) ? config.subject_ids.map((id) => Number(id)).filter((id) => Number.isFinite(id)) : [],
    subject_level_scopes: Array.isArray(config?.subject_level_scopes)
      ? config.subject_level_scopes.map((s) => ({ subject_id: Number(s.subject_id), level: s.level }))
      : [],
  };
}

async function loadTeachingOptions() {
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

function normalizeSecuritySummary(raw) {
  return {
    recent_logins: Array.isArray(raw?.recent_logins) ? raw.recent_logins : [],
    active_sessions: Array.isArray(raw?.active_sessions) ? raw.active_sessions : [],
  };
}

function revokeAvatarCropSrc() {
  if (avatarCropSrc.value) {
    URL.revokeObjectURL(avatarCropSrc.value);
    avatarCropSrc.value = '';
  }
}

function onAvatarSelected(event) {
  const file = event.target.files?.[0];
  if (avatarInputRef.value) avatarInputRef.value.value = '';
  if (!file) return;
  revokeAvatarCropSrc();
  avatarCropSrc.value = URL.createObjectURL(file);
  showAvatarCrop.value = true;
}

async function onAvatarCropped(file) {
  revokeAvatarCropSrc();
  await submitAvatarFile(file);
}

async function submitAvatarFile(file) {
  if (!file) return;
  uploadingAvatar.value = true;
  profileMsg.value = null;
  try {
    const result = await uploadAvatar(props.token, file);
    avatarUrl.value = result?.avatar_url || '';
    profileMsg.value = { type: 'success', text: '頭像更新成功。' };
    emit('profile-updated', { avatar_url: avatarUrl.value });
  } catch (error) {
    profileMsg.value = { type: 'error', text: error.message || '頭像上傳失敗。' };
    try {
      const me = await getMe(props.token);
      const next = me?.avatar_url || '';
      if (next && next !== avatarUrl.value) {
        avatarUrl.value = next;
        emit('profile-updated', { avatar_url: avatarUrl.value });
        profileMsg.value = {
          type: 'success',
          text: '頭像已更新（連線中斷或逾時時仍可能成功，已為您同步顯示）。',
        };
      }
    } catch {
      /* keep upload error */
    }
  } finally {
    uploadingAvatar.value = false;
  }
}

onBeforeUnmount(() => {
  revokeAvatarCropSrc();
});

async function submitProfile() {
  profileMsg.value = null;
  if (!profileForm.value.name) {
    profileMsg.value = { type: 'error', text: '請輸入姓名。' };
    return;
  }
  if (!profileForm.value.email) {
    profileMsg.value = { type: 'error', text: '請輸入登入信箱。' };
    return;
  }

  savingProfile.value = true;
  try {
    const payload = {
      name: profileForm.value.name,
      email: profileForm.value.email,
      phone: profileForm.value.phone || null,
    };
    const data = await updateMe(props.token, payload);
    profileMsg.value = { type: 'success', text: '基本資料已更新。' };
    emit('profile-updated', {
      name: data?.name || profileForm.value.name,
      email: data?.email || profileForm.value.email,
      avatar_url: data?.avatar_url || avatarUrl.value,
    });
  } catch (error) {
    profileMsg.value = { type: 'error', text: error.message || '儲存失敗。' };
  } finally {
    savingProfile.value = false;
  }
}

async function submitTeaching() {
  teachingMsg.value = null;
  if (!isTeacher.value) return;
  const branchId = Number(teachingForm.value.branch_id);
  if (!Number.isFinite(branchId) || branchId <= 0) {
    teachingMsg.value = { type: 'error', text: '請先選擇主分校。' };
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
    const data = await updateMe(props.token, payload);
    hydrateTeachingConfig(data?.teacher_config || payload);
    teachingMsg.value = { type: 'success', text: '教學設定已更新。' };
  } catch (error) {
    teachingMsg.value = { type: 'error', text: error.message || '教學設定更新失敗。' };
  } finally {
    savingTeaching.value = false;
  }
}

async function submitPassword() {
  passwordMsg.value = null;
  if (!passwordForm.value.currentPassword) {
    passwordMsg.value = { type: 'error', text: '請輸入目前密碼。' };
    return;
  }
  if (!passwordForm.value.newPassword || passwordForm.value.newPassword.length < 6) {
    passwordMsg.value = { type: 'error', text: '新密碼至少 6 碼。' };
    return;
  }
  if (passwordForm.value.newPassword !== passwordForm.value.confirmPassword) {
    passwordMsg.value = { type: 'error', text: '確認密碼不一致。' };
    return;
  }

  savingPassword.value = true;
  try {
    const data = await updateMe(props.token, {
      current_password: passwordForm.value.currentPassword,
      password: passwordForm.value.newPassword,
      password_confirmation: passwordForm.value.confirmPassword,
    });
    // Backend revokes all old tokens and issues a fresh one; update localStorage
    // so the current session stays valid (other devices are logged out).
    if (data?.new_token) {
      const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
      session.access_token = data.new_token;
      localStorage.setItem('alltrue_session', JSON.stringify(session));
    }
    passwordMsg.value = { type: 'success', text: '密碼已更新，其他裝置已登出。' };
    passwordForm.value = { currentPassword: '', newPassword: '', confirmPassword: '' };
    emit('profile-updated', {
      must_change_password: Boolean(data?.must_change_password),
    });
    emit('password-change-complete');
  } catch (error) {
    passwordMsg.value = { type: 'error', text: error.message || '密碼更新失敗。' };
  } finally {
    savingPassword.value = false;
  }
}

async function submitPrefs() {
  prefMsg.value = null;
  savingPrefs.value = true;
  try {
    const res = await updateNotificationPrefs(props.token, prefs.value);
    prefs.value = { ...defaultPrefs(), ...(res?.data || {}) };
    prefMsg.value = { type: 'success', text: '通知偏好已儲存。' };
  } catch (error) {
    prefMsg.value = { type: 'error', text: error.message || '儲存通知偏好失敗。' };
  } finally {
    savingPrefs.value = false;
  }
}

async function refreshSecurity() {
  securityMsg.value = null;
  try {
    const res = await getSecuritySummary(props.token);
    securitySummary.value = normalizeSecuritySummary(res?.data || {});
  } catch (error) {
    securityMsg.value = { type: 'error', text: error.message || '讀取安全資料失敗。' };
  }
}

async function handleLogoutOtherDevices() {
  securityMsg.value = null;
  revokingOtherSessions.value = true;
  try {
    const res = await logoutOtherSessions(props.token);
    const revoked = Number(res?.revoked_count || 0);
    securityMsg.value = {
      type: 'success',
      text: revoked > 0 ? `已登出其他裝置（${revoked} 筆）。` : '目前沒有其他有效裝置。',
    };
    await refreshSecurity();
  } catch (error) {
    securityMsg.value = { type: 'error', text: error.message || '登出其他裝置失敗。' };
  } finally {
    revokingOtherSessions.value = false;
  }
}

function formatDateTime(value) {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('zh-TW', { hour12: false });
}

watch(
  () => [props.forcePasswordChange, props.initialTab],
  ([forceMode, initialTab]) => {
    activeTab.value = forceMode ? 'security' : (initialTab || 'profile');
  },
);

onMounted(loadData);
</script>

<style scoped>
.profile-center-page {
  display: grid;
  gap: 16px;
}

.force-banner {
  margin-top: 8px;
  padding: 8px 10px;
  border-radius: 8px;
  border: 1px solid #ffcc80;
  background: #fff3e0;
  color: #8d4e00;
  font-size: 13px;
}

.profile-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 0;
  padding: 12px;
}

.tab-btn {
  padding: 10px 16px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #fff;
  font-size: 13px;
  font-weight: 600;
  color: var(--text);
}

.tab-btn.active {
  border-color: var(--accent);
  color: var(--accent-hover);
  background: var(--primary-bg);
}

.tab-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.muted {
  color: var(--text-light);
  font-size: 13px;
  margin-bottom: 14px;
}

.profile-layout {
  display: grid;
  grid-template-columns: 220px 1fr;
  gap: 24px;
}

.avatar-panel {
  display: grid;
  gap: 10px;
  align-content: start;
}

.avatar-box {
  width: 140px;
  height: 140px;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid var(--border);
  background: #fafafa;
  display: grid;
  place-items: center;
}

.avatar-box img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center center;
}

.avatar-sidebar-preview-wrap {
  display: grid;
  gap: 6px;
}

.avatar-preview-label {
  font-size: 12px;
  color: var(--text-light);
}

.avatar-sidebar-preview {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid var(--border);
  background: #fafafa;
}

.avatar-sidebar-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center center;
  display: block;
}

.avatar-uploading {
  font-size: 13px;
  color: var(--primary, #f97316);
  margin: 0;
}

.avatar-fallback {
  font-size: 40px;
  font-weight: 700;
  color: #fff;
  width: 100%;
  height: 100%;
  background: linear-gradient(135deg, #ff8a65, #fb8c00);
  display: grid;
  place-items: center;
}

.avatar-hint {
  font-size: 12px;
  color: var(--text-light);
  line-height: 1.45;
  margin: 0;
  max-width: 220px;
}

.form-panel {
  min-width: 0;
}

.section-msg {
  padding: 9px 12px;
  border-radius: 8px;
  margin-bottom: 12px;
  font-size: 13px;
}

.section-msg.success {
  background: var(--success-bg);
  color: var(--success);
}

.section-msg.error {
  background: var(--danger-bg);
  color: var(--danger);
}

.grid.two {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.security-block {
  margin-top: 18px;
}

.teacher-settings-block {
  margin-top: 20px;
  border-top: 1px solid var(--border);
  padding-top: 16px;
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
  border: 1px solid var(--border);
  border-radius: 999px;
  padding: 6px 10px;
  background: #fafafa;
  font-size: 13px;
}

.chip.selected {
  border-color: var(--accent);
  background: var(--primary-bg);
}

.chip.disabled {
  opacity: 0.65;
}

.scope-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.scope-table th,
.scope-table td {
  border: 1px solid var(--border);
  padding: 8px 10px;
  text-align: center;
}

.scope-table th:first-child,
.scope-table td:first-child {
  text-align: left;
}

.security-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.security-header h4 {
  margin: 0;
}

.prefs-layout {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 14px;
  margin-bottom: 16px;
}

.switch-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
}

.switch-row input[type='checkbox'] {
  width: 16px;
  height: 16px;
}

@media (max-width: 900px) {
  .profile-layout {
    grid-template-columns: 1fr;
    gap: 14px;
  }

  .prefs-layout,
  .grid.two {
    grid-template-columns: 1fr;
  }
}
</style>
