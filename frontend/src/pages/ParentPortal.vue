<template>
  <div class="parent-portal">
    <HelpGuide
      title="家長入口 — 使用說明"
      :items="[
        '使用<strong>學生姓名</strong>與<strong>聯絡手機號碼</strong>登入。',
        '透過 LINE 官方帳號綁定後，可直接從 LINE 進入查看。',
        '登入後可查看：剩餘堂數、學習評量表、出缺勤紀錄、繳費通知。',
        '學習評量內容可包含測驗或考試分數。',
        '如有問題請聯繫所屬分校主任。'
      ]"
      tip="姓名與手機請與報名時留的資料一致；若有多位子女請分別登入。若無法登入，可能是學生資料尚未填寫聯絡手機，請向分校確認。"
    />

    <!-- Login -->
    <div class="card" v-if="!token">
      <h2>👪 家長 / 學生入口</h2>
      <div class="grid">
        <div>
          <label>學生姓名</label>
          <input v-model="loginForm.Name" type="text" placeholder="請輸入學生姓名" />
        </div>
        <div>
          <label>聯絡手機號碼</label>
          <input v-model="loginForm.Phone" type="tel" placeholder="請輸入手機號碼" />
        </div>
      </div>
      <div style="margin-top: 12px; display: flex; gap: 8px;">
        <button class="primary" @click="login">登入</button>
        <button class="line-btn" @click="loginWithLine" v-if="liffAvailable">
          <span style="font-weight:600;">LINE 登入</span>
        </button>
      </div>
      <p class="hint error" v-if="loginError">{{ loginError }}</p>
    </div>

    <!-- Logged in header -->
    <div class="card" v-if="token && dashboard">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <div>
          <h2 style="margin:0;">👋 {{ dashboard.student?.name || '學生' }}</h2>
          <p class="hint" style="margin:4px 0 0;">剩餘堂數（堂數制）：<strong>{{ dashboard.remaining_sessions_total }}</strong> 堂</p>
        </div>
        <button @click="logout" class="small">登出</button>
      </div>
    </div>

    <!-- Payment alerts -->
    <div class="card alert-card" v-if="token && dashboard && (dashboard.payment_alerts || []).length > 0">
      <h3>💰 繳費通知</h3>
      <div v-for="alert in dashboard.payment_alerts" :key="alert.class_id" class="alert-item">
        <span class="subject-tag">{{ alert.subject || '課程' }}</span>
        <span v-if="alert.remaining_sessions <= 0" class="badge danger">已用完</span>
        <span v-else class="badge warning">剩餘 {{ alert.remaining_sessions }} 堂</span>
        <span v-if="!alert.paid" class="badge danger">未繳費</span>
      </div>
    </div>

    <!-- Upcoming sessions -->
    <div class="card" v-if="token && dashboard && (dashboard.upcoming_sessions || []).length > 0">
      <h3>📅 近期課程</h3>
      <table>
        <thead>
          <tr><th>日期</th><th>時間</th><th>科目</th><th>狀態</th><th>操作</th></tr>
        </thead>
        <tbody>
          <tr v-for="s in dashboard.upcoming_sessions" :key="s.id">
            <td>{{ s.SessionDate }}</td>
            <td>{{ s.StartTime }}~{{ s.EndTime }}</td>
            <td>{{ s.Subject || '-' }}</td>
            <td>
              <span :class="['status-tag', s.Status]">{{ statusLabel(s.Status) }}</span>
            </td>
            <td>
              <button v-if="s.Status === 'scheduled' || s.Status === 'rescheduled'"
                      class="small" @click="requestLeave(s.id)">請假</button>
              <span v-else-if="s.Status === 'leave_requested'" class="hint">已申請</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Courses breakdown -->
    <div class="card" v-if="token && dashboard && (dashboard.classes || []).length > 0">
      <h3>📘 課程與堂數</h3>
      <table>
        <thead>
          <tr><th>科目</th><th>購買堂數</th><th>已使用</th><th>剩餘</th><th>狀態</th></tr>
        </thead>
        <tbody>
          <tr v-for="c in dashboard.classes" :key="c.id">
            <td>{{ c.subject || '-' }}</td>
            <td>{{ c.sessions_purchased ?? '-' }}</td>
            <td>{{ c.used_sessions ?? '-' }}</td>
            <td><strong>{{ c.remaining_sessions ?? '-' }}</strong></td>
            <td>
              <span v-if="c.is_stopped" class="badge danger">已停課</span>
              <span v-else-if="c.paid" class="badge success">已繳費</span>
              <span v-else class="badge warning">未繳費</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Learning records -->
    <div class="card" v-if="token && dashboard">
      <h3>📝 已核准學習評量</h3>
      <table v-if="(dashboard.learning_records || []).length">
        <thead>
          <tr><th>日期</th><th>科目</th><th>測驗分數</th><th>授課老師</th><th>授課進度</th><th>學習建議</th></tr>
        </thead>
        <tbody>
          <tr v-for="record in dashboard.learning_records" :key="record.id">
            <td>{{ record.SessionDate }}</td>
            <td>{{ record.Subject }}</td>
            <td>{{ record.QuizScore != null && record.QuizScore !== '' ? record.QuizScore : '—' }}</td>
            <td>{{ record.teacher_name || '-' }}</td>
            <td>{{ record.Progress || record.Content || '-' }}</td>
            <td>{{ record.Comment || '-' }}</td>
          </tr>
        </tbody>
      </table>
      <p class="hint" v-else>無已核准紀錄</p>
    </div>

    <!-- Attendance -->
    <div class="card" v-if="token && dashboard">
      <h3>📋 出缺勤紀錄</h3>
      <table v-if="(dashboard.attendance_history || []).length">
        <thead>
          <tr><th>日期</th><th>狀態</th></tr>
        </thead>
        <tbody>
          <tr v-for="a in dashboard.attendance_history" :key="a.id">
            <td>{{ a.SignInDT }}</td>
            <td>{{ a.Status === 'present' ? '出席' : a.Status === 'absent' ? '缺席' : a.Status }}</td>
          </tr>
        </tbody>
      </table>
      <p class="hint" v-else>無出缺勤紀錄</p>
    </div>

    <!-- LINE binding info -->
    <div class="card" v-if="token && dashboard">
      <h3>🔗 LINE 綁定</h3>
      <p v-if="lineLinked" class="hint" style="color:#2e7d32;">
        ✅ 已綁定 LINE，可直接從 LINE 官方帳號進入查看
      </p>
      <div v-else>
        <p class="hint">尚未綁定 LINE。請加入補習班 LINE 官方帳號，並輸入：</p>
        <code style="display:block; background:#f5f5f5; padding:8px; border-radius:4px; margin:8px 0;">
          綁定 {{ dashboard.student?.name || '學生姓名' }} 手機號碼
        </code>
        <p class="hint">綁定後即可透過 LINE 查看剩餘堂數與學習評量。</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref, computed } from 'vue';
import { getParentDashboard, parentLogin, parentLoginLine, parentRequestLeave } from '../api';
import HelpGuide from '../components/HelpGuide.vue';

const props = defineProps({
  standalone: { type: Boolean, default: false },
});

const tokenKey = 'parent_portal_token';
const token = ref(localStorage.getItem(tokenKey) || '');
const loginForm = ref({ Name: '', Phone: '' });
const loginError = ref('');
const dashboard = ref(null);
const liffAvailable = ref(false);
const lineLinked = ref(false);

const statusLabel = (s) => {
  const map = { scheduled: '排定', rescheduled: '已調課', leave_requested: '已請假', cancelled: '已取消', completed: '已完成' };
  return map[s] || s;
};

const loadDashboard = async () => {
  if (!token.value) return;
  try {
    dashboard.value = await getParentDashboard(token.value);
  } catch (e) {
    console.error('Dashboard load failed:', e);
    token.value = '';
    localStorage.removeItem(tokenKey);
  }
};

const login = async () => {
  loginError.value = '';
  if (!loginForm.value.Name?.trim() || !loginForm.value.Phone?.trim()) {
    loginError.value = '請輸入學生姓名和手機號碼';
    return;
  }
  try {
    const result = await parentLogin(loginForm.value);
    token.value = result.token;
    localStorage.setItem(tokenKey, result.token);
    await loadDashboard();
  } catch (error) {
    loginError.value = '登入失敗，請確認學生姓名及手機號碼是否正確';
  }
};

const loginWithLine = async () => {
  loginError.value = '';
  try {
    if (window.liff && window.liff.isLoggedIn()) {
      const profile = await window.liff.getProfile();
      const result = await parentLoginLine(profile.userId);
      token.value = result.token;
      localStorage.setItem(tokenKey, result.token);
      lineLinked.value = true;
      await loadDashboard();
    } else if (window.liff) {
      window.liff.login();
    } else {
      loginError.value = '請從 LINE 應用程式開啟此頁面';
    }
  } catch (e) {
    loginError.value = e.message || 'LINE 登入失敗';
  }
};

const requestLeave = async (sessionId) => {
  if (!confirm('確定要請假嗎？')) return;
  try {
    await parentRequestLeave(token.value, sessionId);
    await loadDashboard();
    alert('請假申請已送出');
  } catch (e) {
    alert('請假申請失敗：' + (e.message || ''));
  }
};

const logout = () => {
  token.value = '';
  localStorage.removeItem(tokenKey);
  dashboard.value = null;
};

onMounted(async () => {
  // Try LIFF initialization
  if (window.liff) {
    try {
      const liffId = import.meta.env.VITE_LIFF_ID;
      if (liffId) {
        await window.liff.init({ liffId });
        liffAvailable.value = true;
        if (window.liff.isLoggedIn() && !token.value) {
          await loginWithLine();
        }
      }
    } catch (e) {
      console.warn('LIFF init failed:', e);
    }
  }
  loadDashboard();
});
</script>

<style scoped>
.parent-portal { max-width: 800px; margin: 0 auto; padding: 16px; }
.card { background: #fff; border-radius: 8px; padding: 16px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.hint { color: #666; font-size: 0.9em; }
.hint.error { color: #c62828; }
label { font-weight: 600; display: block; margin-bottom: 4px; font-size: 0.9em; }
input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
button.primary { background: #1976d2; color: #fff; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; }
button.small { padding: 4px 12px; font-size: 0.85em; background: #eee; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; }
.line-btn { background: #06C755; color: #fff; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 0.9em; }
th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #eee; }
th { background: #f5f5f5; font-weight: 600; }
.subject-tag { background: #e3f2fd; color: #1565c0; padding: 2px 8px; border-radius: 4px; font-size: 0.85em; margin-right: 6px; }
.badge { padding: 2px 8px; border-radius: 10px; font-size: 0.8em; font-weight: 600; }
.badge.success { background: #e8f5e9; color: #2e7d32; }
.badge.warning { background: #fff3e0; color: #e65100; }
.badge.danger { background: #ffebee; color: #c62828; }
.status-tag { font-size: 0.85em; }
.status-tag.scheduled { color: #1565c0; }
.status-tag.rescheduled { color: #f57c00; }
.status-tag.leave_requested { color: #7b1fa2; }
.alert-card { border-left: 4px solid #ff9800; }
.alert-item { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid #f5f5f5; flex-wrap: wrap; }
code { font-family: monospace; }

@media (max-width: 640px) {
  .parent-portal { padding: 8px; }
  .card { padding: 12px; }
  .grid { grid-template-columns: 1fr; }
  table { font-size: 0.8em; }
  th, td { padding: 4px 6px; }
}
</style>
