<template>
  <div>
    <!-- 主任尚未有分校權限時提示 -->
    <div v-if="branchId == null" class="card" style="max-width: 480px; margin: 2rem auto; padding: 2rem; text-align: center;">
      <h2 style="margin-bottom: 1rem;">📌 尚無分校資料</h2>
      <p style="color: var(--text-light);">系統尚未載入您的分校權限，或您尚未被指派到任何分校。</p>
      <p style="margin-top: 1rem; font-size: 0.9rem;">請聯繫系統管理員設定您的分校權限後重新整理頁面。</p>
    </div>

    <template v-else>
    <!-- System Guide -->
    <HelpGuide
      title="📖 系統操作總說明 — 第一次使用請先閱讀"
      :defaultOpen="true"
      :items="[
        '<strong>總覽儀表板</strong>：繳費提醒、今日排課、待審核評量、本月堂數。',
        '<strong>智慧排課</strong>：週課表 / 老師視角，點空白時段新增、點色塊編輯，可篩選老師。',
        '<strong>學生管理</strong>：新增學生、課程與加購堂數；點學生列展開課程明細。',
        '<strong>老師管理</strong>：正式/待審核分頁、核准、編輯主分校與跨校支援；依所選分校篩選老師。',
        '<strong>課程管理</strong>：所有學生課程總覽、篩選、補登舊資料。',
        '<strong>科目數統計</strong>：依學生課程計算各老師科目數與佔比，可切換月份。',
        '<strong>出缺勤管理</strong>：手動登記到/離班，處理未識別刷卡。',
        '<strong>學習評量表</strong>：新增與審核學生學習評量。',
        '<strong>家長入口</strong>：家長以學生代號+手機登入查詢堂數與紀錄。',
        '<strong>主任審核</strong>（僅超級管理員）：審核主任自行申請的帳號，通過/拒絕。'
      ]"
      tip="左側可切換分校（主任僅見所屬分校）；每頁頂端有「💡 使用說明」可展開查看。"
    />

    <div class="dashboard-container">
      <div class="card summary-stats">
        <h3>{{ branchName }} — 總覽 (Overview)</h3>
        <div class="stats-grid">
          <div class="stat-item danger-glow">
            <div class="stat-icon">⚠️</div>
            <label>繳費提醒</label>
            <div class="value red">{{ lowBalanceStudents.length }}</div>
          </div>
          <div class="stat-item">
            <div class="stat-icon">📊</div>
            <label>今日排課</label>
            <div class="value">{{ todaySchedules.length }}</div>
          </div>
          <div class="stat-item">
            <div class="stat-icon">📝</div>
            <label>待審核評量</label>
            <div class="value">{{ pendingEvaluations.length }}</div>
          </div>
          <div class="stat-item">
            <div class="stat-icon">📊</div>
            <label>本月總堂數</label>
            <div class="value">{{ totalSessionsThisMonth }}</div>
          </div>
        </div>
      </div>

      <div class="dashboard-columns">
        <!-- Left: Payment Alerts & Attendance -->
        <div class="column">
          <h3>1. 繳費通知與排課</h3>

          <div class="section-box">
            <h4>⚠️ 繳費提醒（剩餘堂數 ≤ 2）</h4>
            <div v-if="lowBalanceStudents.length === 0" class="empty-text">無</div>
            <div v-for="s in lowBalanceStudents" :key="s.id" class="alert-item">
              <span>{{ s.name }}</span>
              <span class="badge-red">{{ s.remaining_lessons }} 堂</span>
              <button class="copy-btn" :title="'複製 ' + s.name + ' 的繳費通知'" @click="copyPaymentMessage(s)">📋 複製</button>
            </div>
          </div>

          <div class="section-box">
            <h4>💳 繳費通知</h4>
            <div v-if="unpaidCourses.length === 0" class="empty-text">無</div>
            <div v-for="c in unpaidCourses" :key="c.id" class="alert-item">
              <span>{{ c.student_name }} — {{ c.subject }}</span>
              <span class="badge-orange">未繳費</span>
            </div>
          </div>

          <div class="section-box">
            <h4>📅 今日排課 (Today)</h4>
            <div v-if="todaySchedules.length === 0" class="empty-text">無課程</div>
            <div v-for="schedule in todaySchedules" :key="schedule.id" class="mini-task-card">
              <div class="row">
                <strong>{{ formatTime(schedule.start_time) }}</strong>
                <span>{{ schedule.student?.name }}</span>
                <span class="tag">{{ schedule.subject }}</span>
              </div>
              <div class="row right" v-if="schedule.status === 'scheduled'">
                <button class="primary xs" @click="markAttended(schedule)">到班</button>
                <button class="danger xs" @click="markCancelled(schedule)">請假</button>
              </div>
              <div class="row right" v-else>
                <span class="status-text">{{ schedule.status }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Center: Evaluation Review -->
        <div class="column">
          <h3>2. 待審核學習評量</h3>
          <p class="hint">核准後老師科目數自動累計。</p>
          <div v-if="pendingEvaluations.length === 0" class="empty-text">無待審核評量</div>
          <div v-for="evalItem in pendingEvaluations" :key="evalItem.id" class="review-card">
            <div class="card-header">
              <strong>{{ evalItem.student_name }}</strong>
              <span class="tag">{{ evalItem.Subject }}</span>
              <span class="teacher-name">{{ evalItem.teacher_name }}</span>
            </div>
            <div class="card-body">
              <p><strong>日期:</strong> {{ evalItem.SessionDate }}</p>
              <p v-if="evalItem.Progress"><strong>進度:</strong> {{ evalItem.Progress }}</p>
              <p v-if="evalItem.Comment"><strong>評語:</strong> {{ evalItem.Comment }}</p>
            </div>
            <div class="card-actions">
              <button class="primary small" @click="approveEvaluation(evalItem)">核准</button>
              <button class="danger small" style="margin-left:6px;" @click="rejectEvaluation(evalItem)">退回</button>
            </div>
          </div>
        </div>

        <!-- Right: Teacher Stats -->
        <div class="column">
          <h3>3. 老師科目數統計 (Stats)</h3>
          <p class="hint">本月累計（詳細請至「科目數統計」頁面）</p>
          <table class="simple-table">
            <thead>
              <tr>
                <th>老師</th>
                <th>科目</th>
                <th>堂數</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="stat in teacherStats" :key="stat.id">
                <td>{{ stat.name }}</td>
                <td>{{ stat.subject }}</td>
                <td><strong>{{ stat.count }}</strong></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { supabase } from '../supabase';
import { getBranchName } from '../lib/useBranches';
import HelpGuide from '../components/HelpGuide.vue';

const props = defineProps({
  branchId: [String, Number]
});

const todaySchedules = ref([]);
const pendingEvaluations = ref([]);
const teacherStats = ref([]);
const lowBalanceStudents = ref([]);
const unpaidCourses = ref([]);

const branchName = computed(() => {
  return getBranchName(props.branchId);
});

const pendingAttendanceCount = computed(() => {
  return todaySchedules.value.filter(s => s.status === 'scheduled').length;
});

const totalSessionsThisMonth = computed(() => {
  return teacherStats.value.reduce((acc, curr) => acc + curr.count, 0);
});

const formatTime = (timeStr) => {
  // start_time is already a simple "HH:MM" string
  return timeStr || '--:--';
};

const loadData = async () => {
  if (!props.branchId) return;

  // Load low balance and unpaid alerts directly from the AlertController backend
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  const baseUrl = import.meta.env.VITE_API_BASE || '/api';

  try {
    const alertsResp = await fetch(`${baseUrl}/v1/alerts/tuition`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
      }
    });

    if (alertsResp.ok) {
      const alertsJson = await alertsResp.json();
      // AlertController returns a plain array; support both array and { low_balance: [...] }
      const alertList = Array.isArray(alertsJson) ? alertsJson : (alertsJson.low_balance || []);
      lowBalanceStudents.value = alertList.filter(c => c.student_name).map(c => ({
        id: c.id || c.class_id,
        student_id: c.student_id || null,
        raw_name: c.student_name,
        name: `${c.student_name} — ${c.subject || getSubjectLabel(c.SubjectID) || ''}`,
        remaining_lessons: c.remaining_sessions ?? c.RemainingSessions ?? 0
      }));
    }
  } catch (err) {
    console.error('Failed to load alerts:', err);
  }

  // Find unpaid courses
  const { data: unpaidData } = await supabase
    .from('student-classes')
    .select('*, student:students(name)')
    .eq('branch_id', props.branchId)
    .eq('payment_status', 'unpaid');

  unpaidCourses.value = (unpaidData || [])
    .filter(c => c.status !== 'inactive')
    .map(c => ({
      id: c.id,
      student_name: c.student?.name || c.student_name || '?',
      subject: getSubjectLabel(c.subject)
    }));

  // Today's schedules: filter by schedule_date or day_of_week
  const today = new Date().toISOString().split('T')[0];
  const todayDow = new Date().getDay() || 7; // 1=Mon..7=Sun

  // Try schedule_date first, then fall back to day_of_week
  const { data: dateSchedules } = await supabase
    .from('schedules')
    .select('*')
    .eq('branch_id', props.branchId)
    .eq('schedule_date', today);

  const { data: dowSchedules } = await supabase
    .from('schedules')
    .select('*')
    .eq('branch_id', props.branchId)
    .eq('day_of_week', todayDow)
    .is('schedule_date', null);

  const allTodaySchedules = [...(dateSchedules || []), ...(dowSchedules || [])];
  // Deduplicate by id and filter only active statuses
  const seenIds = new Set();
  todaySchedules.value = allTodaySchedules
    .filter(s => {
      if (seenIds.has(s.id)) return false;
      seenIds.add(s.id);
      return s.status === 'scheduled' || s.status === 'attended';
    })
    .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));

  // Pending evaluations from learning-records API
  try {
    const pendingRes = await fetch(`${baseUrl}/v1/learning-records?branch_id=${props.branchId}&status=pending&per_page=50&sort=session_date`, {
      headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' }
    });
    if (pendingRes.ok) {
      const pendingJson = await pendingRes.json();
      pendingEvaluations.value = (pendingJson.data || []);
    }
  } catch (err) {
    console.error('Failed to load pending evaluations:', err);
  }

  calculateTeacherStats();
};

const getSubjectLabel = (val) => {
  const map = {
    '1': '國文', '2': '英文', '3': '數學', '4': '自然', '5': '社會',
    'Chinese': '國文', 'English': '英文', 'Math': '數學',
    'Science': '自然', 'Social': '社會'
  };
  return map[val] || val;
};

const calculateTeacherStats = async () => {
  try {
    const now = new Date();
    const startDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;
    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
    const endDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;

    const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const token = session?.access_token || '';
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';

    const params = new URLSearchParams({ start: startDate, end: endDate });
    const res = await fetch(`${baseUrl}/v1/finance/subject-units?${params}`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
      }
    });

    if (!res.ok) return;
    const json = await res.json();

    // Map to dashboard summary format (teacher name + subject count)
    teacherStats.value = (json.teachers || []).map(t => ({
      id: t.teacher_id,
      name: t.teacher_name,
      subject: `科目數: ${t.subject_count_with}`,
      count: t.total_hours,
    }));
  } catch (e) {
    console.error('Failed to load teacher stats:', e);
  }
};

const markAttended = async (schedule) => {
  const { error } = await supabase.from('schedules').update({ status: 'attended' }).eq('id', schedule.id);
  if (!error) loadData();
};

const markCancelled = async (schedule) => {
  if (!confirm('確定取消?')) return;
  const { error } = await supabase.from('schedules').update({ status: 'cancelled' }).eq('id', schedule.id);
  if (!error) loadData();
};

const approveEvaluation = async (evalItem) => {
  if (!confirm('確認核准此評量？')) return;
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  const baseUrl = import.meta.env.VITE_API_BASE || '/api';
  try {
    const res = await fetch(`${baseUrl}/v1/learning-records/${evalItem.id}/approve`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ DirectorID: session?.user?.id })
    });
    if (res.ok) {
      loadData();
    } else {
      const err = await res.json();
      alert('核准失敗: ' + (err.message || ''));
    }
  } catch (e) { alert('核准失敗'); }
};

const rejectEvaluation = async (evalItem) => {
  const note = prompt('退回原因：');
  if (!note) return;
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  const baseUrl = import.meta.env.VITE_API_BASE || '/api';
  try {
    await fetch(`${baseUrl}/v1/learning-records/${evalItem.id}/reject`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ ReviewNote: note })
    });
    loadData();
  } catch (e) { console.error(e); }
};

const copyPaymentMessage = async (student) => {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  const baseUrl = import.meta.env.VITE_API_BASE || '/api';

  // Extract student id from the alert item (id field is the class_id, we need student id)
  // The lowBalanceStudents are mapped from alerts which include student_id or class_id
  const studentId = student.student_id;
  if (!studentId) {
    // Build a generic message from local data
    const msg = `親愛的家長您好，\n\n${student.raw_name || student.name.split(' — ')[0]} 同學的課程剩餘 ${student.remaining_lessons} 堂，請盡速繳費，以免影響上課。\n\n如有疑問，歡迎聯繫補習班，謝謝！`;
    try {
      await navigator.clipboard.writeText(msg);
      alert('繳費通知已複製到剪貼簿！');
    } catch {
      prompt('請手動複製以下訊息：', msg);
    }
    return;
  }

  try {
    const res = await fetch(`${baseUrl}/v1/parent/payment-message/${studentId}`, {
      headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' }
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || '取得失敗');
    const msg = data.message;
    try {
      await navigator.clipboard.writeText(msg);
      alert('繳費通知已複製到剪貼簿！');
    } catch {
      prompt('請手動複製以下訊息：', msg);
    }
  } catch (e) {
    alert('無法取得繳費通知：' + (e.message || ''));
  }
};

watch(() => props.branchId, loadData);
onMounted(loadData);
</script>

<style scoped>
.dashboard-container {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.summary-stats .stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-top: 16px;
}

.stat-item {
  background: #FAFAFA;
  border: 1px solid var(--border);
  padding: 16px;
  border-radius: 10px;
  text-align: center;
  transition: var(--transition);
}

.stat-item:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-hover);
}

.stat-item.danger-glow {
  background: #FFF8E1;
  border-color: #FFE082;
}

.stat-icon {
  font-size: 24px;
  margin-bottom: 6px;
}

.stat-item label {
  font-size: 12px;
  color: var(--text-light);
  text-align: center;
}

.stat-item .value {
  font-size: 28px;
  font-weight: 800;
  color: var(--text);
  margin-top: 4px;
}

.stat-item .value.red {
  color: var(--danger);
}

.dashboard-columns {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}

.column {
  background: var(--card-bg);
  border-radius: var(--radius);
  padding: 20px;
  box-shadow: var(--shadow);
  overflow-y: auto;
  max-height: 600px;
}

.column h3 {
  font-size: 15px;
  font-weight: 700;
  border-bottom: 2px solid var(--accent);
  padding-bottom: 10px;
  margin-bottom: 16px;
  color: var(--primary);
}

.section-box {
  margin-bottom: 20px;
}

.section-box h4 {
  font-size: 13px;
  color: var(--text);
  background: #FAFAFA;
  padding: 8px 10px;
  border-radius: 6px;
  margin-bottom: 8px;
  font-weight: 600;
}

.alert-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 8px;
  border-bottom: 1px solid #F5F5F5;
  font-size: 14px;
}

.mini-task-card {
  padding: 10px 8px;
  border-bottom: 1px solid #F5F5F5;
  font-size: 13px;
}

.mini-task-card .row {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 4px;
}

.mini-task-card .right {
  justify-content: flex-end;
}

.review-card {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 14px;
  margin-bottom: 12px;
  background: #FAFAFA;
  transition: var(--transition);
}

.review-card:hover {
  box-shadow: var(--shadow);
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
  flex-wrap: wrap;
  gap: 6px;
}

.teacher-name {
  font-size: 12px;
  color: var(--text-light);
}

.card-body p {
  margin: 4px 0;
  font-size: 13px;
  color: #555;
}

.card-actions {
  margin-top: 10px;
  display: flex;
  justify-content: flex-end;
}

.simple-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.simple-table th {
  text-align: left;
  font-size: 11px;
  padding: 8px 6px;
}

.simple-table td {
  padding: 8px 6px;
}

.status-text {
  font-size: 12px;
  color: var(--text-light);
  font-style: italic;
}

.copy-btn {
  font-size: 11px;
  padding: 2px 8px;
  background: #e3f2fd;
  color: #1565c0;
  border: 1px solid #90caf9;
  border-radius: 4px;
  cursor: pointer;
  margin-left: auto;
  white-space: nowrap;
  flex-shrink: 0;
}
.copy-btn:hover {
  background: #bbdefb;
}
</style>
