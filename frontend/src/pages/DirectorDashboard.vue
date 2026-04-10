<template>
  <div>
    <!-- 主任尚未有分校權限時提示 -->
    <div v-if="branchId == null" class="card" style="max-width: 480px; margin: 2rem auto; padding: 2rem; text-align: center;">
      <h2 style="margin-bottom: 1rem;">📌 尚無分校資料</h2>
      <p style="color: var(--text-light);">系統尚未載入您的分校權限，或您尚未被指派到任何分校。</p>
      <p style="margin-top: 1rem; font-size: 0.9rem;">請聯繫系統管理員設定您的分校權限後重新整理頁面。</p>
    </div>

    <template v-else>
    <div class="dashboard-container">
      <div class="card summary-stats" data-guide="director-summary">
        <h3>{{ branchName }} — 總覽 (Overview)</h3>
        <div class="stats-grid">
          <div class="stat-item danger-glow">
            <div class="stat-icon">⚠️</div>
            <label>未繳費提醒</label>
            <div class="value red">{{ lowBalanceStudents.length }}</div>
          </div>
          <div class="stat-item">
            <div class="stat-icon">📊</div>
            <label>今日課程</label>
            <div class="value">{{ todaySchedules.length }}</div>
            <div class="value-sub">待到班：{{ pendingAttendanceCount }}</div>
          </div>
          <div class="stat-item">
            <div class="stat-icon">📝</div>
            <label>待審核評量</label>
            <div class="value">{{ pendingEvaluations.length }}</div>
          </div>
          <div class="stat-item">
            <div class="stat-icon">📊</div>
            <label>本月總科目數（含輔導）</label>
            <div class="value">{{ monthlySubjectCountWith }}</div>
            <div class="value-sub">不含輔導：{{ monthlySubjectCountWithout }}</div>
          </div>
        </div>
      </div>

      <div class="dashboard-columns">
        <!-- Left: Payment Alerts & Attendance -->
        <div class="column">
          <h3>1. 繳費通知與排課</h3>

          <div class="section-box" data-guide="director-alerts">
            <h4>⚠️ 未繳費提醒（已繳費不通知）</h4>
            <div v-if="lowBalanceStudents.length === 0" class="empty-text">無</div>
            <div v-for="s in lowBalanceStudents" :key="s.id" class="alert-item">
              <span>{{ s.name }}</span>
              <span class="badge-red">{{ s.remaining_lessons }} 堂</span>
              <button class="copy-btn" :title="'複製 ' + s.name + ' 的繳費通知'" @click="copyPaymentMessage(s)">📋 複製</button>
            </div>
          </div>

          <div class="section-box">
            <h4>🔔 通知中心摘要</h4>
            <div class="summary-row">
              <span>未讀通知</span>
              <span class="badge-orange">{{ unreadNotificationCount }}</span>
            </div>
            <div v-if="notificationSummary.length === 0" class="empty-text">目前無未讀通知</div>
            <div v-for="n in notificationSummary" :key="n.id" class="alert-item">
              <span>{{ n.title }}</span>
              <span class="badge-blue">{{ n.typeLabel }}</span>
            </div>
            <div class="summary-actions">
              <button class="primary xs" @click="goToNotifications">前往通知中心</button>
            </div>
          </div>

          <div class="section-box">
            <h4>📅 今日課表與到班處理</h4>
            <p class="hint">這裡顯示今天所有課程；「到班」代表已報到，「請假」代表該堂取消。</p>
            <div v-if="todaySchedules.length === 0" class="empty-text">無課程</div>
            <div v-for="schedule in todaySchedules" :key="schedule.id" class="mini-task-card">
              <div class="row">
                <strong>{{ formatTime(schedule.start_time) }}</strong>
                <span>{{ schedule.student_name || schedule.student?.name || '—' }}</span>
                <span class="tag">{{ schedule.subject || schedule.subject_name || '課程' }}</span>
              </div>
              <div class="row right" v-if="schedule.status === 'scheduled'">
                <button class="primary xs" @click="goToAttendance">前往出缺勤處理</button>
              </div>
              <div class="row right" v-else>
                <span class="status-text">{{ formatScheduleStatus(schedule.status) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Center: Evaluation Review -->
        <div class="column" data-guide="director-pending-evals">
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
        <div class="column" data-guide="director-teacher-stats">
          <h3>3. 老師科目數統計 (Stats)</h3>
          <p class="hint">本月累計（詳細請至「科目數統計」頁面）</p>
          <table class="simple-table">
            <thead>
              <tr>
                <th>老師</th>
                <th>含輔導科目數</th>
                <th>不含輔導科目數</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="stat in teacherStats" :key="stat.id">
                <td>{{ stat.name }}</td>
                <td><strong>{{ stat.subjectCountWith }}</strong></td>
                <td><strong>{{ stat.subjectCountWithout }}</strong></td>
              </tr>
            </tbody>
          </table>

          <div v-if="levelBreakdownTotals.length > 0" class="level-chips">
            <span v-for="lb in levelBreakdownTotals" :key="'lv-'+lb.level" class="level-chip">
              {{ lb.levelLabel }}：{{ lb.totalHours }}h / {{ lb.unitsWith }} 科目數
            </span>
          </div>
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
import { getSubjectLabel as getSubjectText } from '../lib/constants';

const props = defineProps({
  branchId: [String, Number]
});
const emit = defineEmits(['navigate']);

const todaySchedules = ref([]);
const pendingEvaluations = ref([]);
const teacherStats = ref([]);
const subjectTotals = ref({
  subjectCountWith: 0,
  subjectCountWithout: 0,
});
const levelBreakdownTotals = ref([]);
const lowBalanceStudents = ref([]);
const unreadNotificationCount = ref(0);
const notificationSummary = ref([]);

const branchName = computed(() => {
  return getBranchName(props.branchId);
});

const pendingAttendanceCount = computed(() => {
  return todaySchedules.value.filter(s => s.status === 'scheduled').length;
});

const monthlySubjectCountWith = computed(() => Number(subjectTotals.value.subjectCountWith || 0).toFixed(2));
const monthlySubjectCountWithout = computed(() => Number(subjectTotals.value.subjectCountWithout || 0).toFixed(2));

const formatTime = (timeStr) => {
  // start_time is already a simple "HH:MM" string
  return timeStr || '--:--';
};

const formatScheduleStatus = (status) => {
  const map = {
    scheduled: '待到班',
    attended: '已到班',
    completed: '已下課',
    cancelled: '已取消',
    leave: '已請假',
  };
  return map[String(status || '').toLowerCase()] || String(status || '—');
};

const localTodayYmd = () => {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

const loadData = async () => {
  if (!props.branchId) return;

  // Load unpaid tuition alerts directly from the AlertController backend
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  const baseUrl = import.meta.env.VITE_API_BASE || '/api';

  try {
    const alertsParams = new URLSearchParams({ branch_id: String(props.branchId) });
    const alertsResp = await fetch(`${baseUrl}/v1/alerts/tuition?${alertsParams.toString()}`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
      }
    });

    if (alertsResp.ok) {
      const alertsJson = await alertsResp.json();
      // AlertController returns a plain array; support both array and { low_balance: [...] }
      const alertList = Array.isArray(alertsJson) ? alertsJson : (alertsJson.low_balance || []);
      const currentBranchId = Number(props.branchId) || 0;
      lowBalanceStudents.value = alertList
        .filter(c => {
          if (!c.student_name) return false;
          // Defensive frontend guard: keep current-branch data only.
          const campusId = Number(c.campus_id ?? c.CampusID ?? 0);
          return !currentBranchId || !campusId || campusId === currentBranchId;
        })
        .map(c => ({
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

  await loadNotificationSummary(token, baseUrl);

  // Today's schedules: use ClassSession as source of truth (no time-gating).
  // If API fails, fallback to legacy schedules table.
  const today = localTodayYmd();
  try {
    const params = new URLSearchParams({
      branch_id: String(props.branchId),
      start: today,
      end: today,
      per_page: '500',
    });
    const res = await fetch(`${baseUrl}/v1/class-sessions?${params.toString()}`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
      }
    });
    if (res.ok) {
      const json = await res.json().catch(() => ({}));
      const rows = Array.isArray(json?.data) ? json.data : [];
      todaySchedules.value = rows
        .map((row) => ({
          id: Number(row?.id || 0),
          class_session_id: Number(row?.id || 0),
          student_class_id: Number(row?.student_class_id || 0),
          student_name: row?.student_name || '',
          teacher_name: row?.teacher_name || '',
          start_time: String(row?.start_time || '').slice(0, 5),
          end_time: String(row?.end_time || '').slice(0, 5),
          status: String(row?.status || '').toLowerCase(),
          subject: row?.subject || '',
          subject_name: row?.subject_name || '',
        }))
        .filter((s) => s.id > 0 && !['cancelled', 'leave'].includes(s.status))
        .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));
    } else {
      todaySchedules.value = [];
    }
  } catch (_) {
    const todayDow = new Date().getDay() || 7; // 1=Mon..7=Sun
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
    const seenIds = new Set();
    todaySchedules.value = allTodaySchedules
      .filter(s => {
        if (seenIds.has(s.id)) return false;
        seenIds.add(s.id);
        return !['cancelled', 'leave'].includes(String(s.status || '').toLowerCase());
      })
      .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));
  }

  // Pending evaluations from learning-records API
  try {
    const pendingRes = await fetch(`${baseUrl}/v1/learning-records?branch_id=${props.branchId}&status=pending&only_due=1&per_page=50&sort=session_date`, {
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

const notificationTypeLabel = (type) => {
  const map = {
    tuition: '繳費',
    learning_review: '評量',
    pending_swipe: '刷卡',
  };
  return map[type] || '通知';
};

const loadNotificationSummary = async (token, baseUrl) => {
  try {
    const params = new URLSearchParams({
      branch_id: String(props.branchId),
      read: 'unread',
      per_page: '3',
    });
    const res = await fetch(`${baseUrl}/v1/notifications?${params.toString()}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (!res.ok) {
      unreadNotificationCount.value = 0;
      notificationSummary.value = [];
      return;
    }
    const json = await res.json();
    unreadNotificationCount.value = Number(json.unread_count || 0);
    notificationSummary.value = (json.data || []).map((item) => ({
      id: item.id,
      title: item.Title || '通知',
      typeLabel: notificationTypeLabel(item.Type),
    }));
  } catch (err) {
    console.error('Failed to load notification summary:', err);
    unreadNotificationCount.value = 0;
    notificationSummary.value = [];
  }
};

const goToNotifications = () => {
  emit('navigate', { target: 'notifications' });
};

const goToAttendance = () => {
  emit('navigate', { target: 'attendance' });
};

const getSubjectLabel = (val) => {
  const map = {
    '1': '國文', '2': '英文', '3': '數學', '4': '理化', '5': '社會',
    'Chinese': '國文', 'English': '英文', 'Math': '數學',
    'Science': '理化', 'Physics': '物理', 'Chemistry': '化學', 'Biology': '生物', 'Social': '社會'
  };
  return map[val] || getSubjectText(val);
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

    const params = new URLSearchParams({
      start: startDate,
      end: endDate,
      branch_id: String(props.branchId),
      include_level: '1',
    });
    const res = await fetch(`${baseUrl}/v1/finance/subject-units?${params}`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
      }
    });

    if (!res.ok) {
      teacherStats.value = [];
      subjectTotals.value = { subjectCountWith: 0, subjectCountWithout: 0 };
      return;
    }
    const json = await res.json();

    subjectTotals.value = {
      subjectCountWith: Number(json?.totals?.subject_count_with || 0),
      subjectCountWithout: Number(json?.totals?.subject_count_without || 0),
    };

    teacherStats.value = (json.teachers || []).map(t => ({
      id: t.teacher_id,
      name: t.teacher_name,
      subjectCountWith: Number(t.subject_count_with || 0).toFixed(2),
      subjectCountWithout: Number(t.subject_count_without || 0).toFixed(2),
      totalHours: Number(t.total_hours || 0),
    })).sort((a, b) => Number(b.subjectCountWith) - Number(a.subjectCountWith));

    levelBreakdownTotals.value = (json.level_breakdown_totals || []).map(lb => ({
      level: lb.level,
      levelLabel: lb.level_label,
      totalHours: lb.total_hours,
      unitsWith: lb.subject_count_with,
    }));
  } catch (e) {
    console.error('Failed to load teacher stats:', e);
    teacherStats.value = [];
    subjectTotals.value = { subjectCountWith: 0, subjectCountWithout: 0 };
    levelBreakdownTotals.value = [];
  }
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

.value-sub {
  margin-top: 4px;
  font-size: 12px;
  color: var(--text-light);
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

.summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 4px 0 8px 0;
  color: var(--text-light);
  font-size: 13px;
}

.summary-actions {
  margin-top: 10px;
  display: flex;
  justify-content: flex-end;
}

.badge-blue {
  background: #e3f2fd;
  color: #1565c0;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 10px;
  margin-left: 8px;
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

@media (max-width: 1024px) {
  .dashboard-columns {
    grid-template-columns: 1fr 1fr;
  }
  .dashboard-columns .column:last-child {
    grid-column: 1 / -1;
  }
}

@media (max-width: 768px) {
  .summary-stats .stats-grid {
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
  }
  .dashboard-columns {
    grid-template-columns: 1fr;
  }
  .dashboard-columns .column:last-child {
    grid-column: auto;
  }
  .column {
    max-height: none;
  }
}

@media (max-width: 480px) {
  .summary-stats .stats-grid {
    grid-template-columns: 1fr;
  }
  .stat-item {
    padding: 12px;
  }
  .column {
    padding: 14px;
  }
}

.level-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}
.level-chip {
  display: inline-block;
  background: #f5f3ff;
  color: #6d28d9;
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  border: 1px solid #ddd6fe;
}
</style>
