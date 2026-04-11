<template>
  <div class="att-page">
    <div class="page-header att-header">
      <div>
        <h2>出缺勤管理</h2>
        <p class="page-desc">
          {{ isTeacher ? '查看你今日堂次並即時點名' : '追蹤學生到班狀態、點名核課' }}
        </p>
      </div>
      <div class="att-header-btns">
        <button class="primary" @click="refreshAll">重新整理今日堂次</button>
      </div>
    </div>

    <!-- Stats Summary -->
    <div class="att-stats">
      <div class="att-stat-card">
        <div class="att-stat-num">{{ markedSessionsCount }}</div>
        <div class="att-stat-label">已點名 / 今日課表 {{ todaySessionTotal }}</div>
      </div>
      <div class="att-stat-card stat-present">
        <div class="att-stat-num">{{ stats.present }}</div>
        <div class="att-stat-label">到班</div>
      </div>
      <div class="att-stat-card stat-late">
        <div class="att-stat-num">{{ stats.late }}</div>
        <div class="att-stat-label">遲到</div>
      </div>
      <div class="att-stat-card stat-absent">
        <div class="att-stat-num">{{ stats.absent + stats.excused }}</div>
        <div class="att-stat-label">缺席/請假</div>
      </div>
    </div>

    <!-- Unified Check-in Panel -->
    <div class="card att-checkin-card">
      <div class="att-checkin-header">
        <div class="att-section-title">今日待點名堂次</div>
        <span v-if="pendingSessions.length > 0" class="att-badge">{{ pendingSessions.length }}</span>
      </div>
      <p class="att-hint">
        {{ isTeacher
          ? '你今天尚未點名的堂次。點名後立即核課並扣堂。'
          : '該分校今日已結束但尚未點名的堂次。點名後到班/遲到會自動扣堂。' }}
      </p>
      <div v-if="!branchId" class="att-empty">請先選擇分校</div>
      <div v-else-if="pendingLoading" class="att-empty">載入中…</div>
      <div v-else-if="pendingSessions.length === 0" class="att-empty">今日沒有待點名堂次</div>
      <div v-else class="att-table-scroll">
        <table>
          <thead>
            <tr>
              <th>日期</th>
              <th>時段</th>
              <th>學生</th>
              <th>科目</th>
              <th>老師</th>
              <th>狀態</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in pendingSessions" :key="s.class_session_id">
              <td>{{ s.session_date }}</td>
              <td class="att-time-range">{{ s.start_time }}–{{ s.end_time }}</td>
              <td><span class="att-person-name">{{ s.student_name || '—' }}</span></td>
              <td>{{ s.subject_name || '—' }}</td>
              <td>{{ s.teacher_name || '—' }}</td>
              <td>
                <select v-model="pendingMarkStatus[s.class_session_id]" class="att-status-select">
                  <option value="present">到班</option>
                  <option value="late">遲到</option>
                  <option value="excused">請假</option>
                  <option value="absent">缺席</option>
                </select>
              </td>
              <td style="text-align:right">
                <button
                  class="primary small"
                  :disabled="pendingMarkSubmitting[s.class_session_id]"
                  @click="submitPendingMark(s)"
                >
                  {{ pendingMarkSubmitting[s.class_session_id] ? '送出中…' : '點名' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="pendingMarkMsg" class="att-msg" :class="pendingMarkMsgType">{{ pendingMarkMsg }}</p>

      <!-- Manual Entry (collapsed) -->
      <details v-if="!isTeacher" class="att-manual-details">
        <summary class="att-manual-toggle">+ 手動登記（非排課堂次）</summary>
        <div class="att-manual-grid">
          <div class="form-group">
            <label>選擇學生 <span class="att-required">*</span></label>
            <SearchableSelect
              v-model="manualForm.personKey"
              :options="personOptions"
              placeholder="搜尋學生姓名..."
            />
          </div>
          <div class="form-group">
            <label>日期</label>
            <input v-model="manualForm.date" type="date" />
          </div>
          <div class="form-group">
            <label>時間</label>
            <input v-model="manualForm.time" type="time" />
          </div>
          <div class="form-group">
            <label>狀態</label>
            <select v-model="manualForm.status">
              <option value="present">到班</option>
              <option value="late">遲到</option>
              <option value="excused">請假</option>
              <option value="absent">缺席</option>
            </select>
          </div>
          <div class="form-group">
            <label>備註</label>
            <input v-model="manualForm.memo" type="text" placeholder="選填…" />
          </div>
          <div class="form-group att-submit-wrap">
            <label>&nbsp;</label>
            <button class="primary" @click="submitManual">登記</button>
          </div>
        </div>
        <p v-if="manualMsg" class="att-msg" :class="manualMsgType">{{ manualMsg }}</p>
      </details>
    </div>

    <!-- Today's Records -->
    <div class="card att-records-card">
      <div class="att-records-header">
        <div class="att-section-title">今日出缺勤紀錄</div>
        <div class="att-records-controls">
          <input v-model="searchName" type="text" placeholder="搜尋姓名…" class="att-search-input" />
          <select v-model="filterStatus" class="att-filter-select">
            <option value="">全部</option>
            <option value="present">到班</option>
            <option value="late">遲到</option>
            <option value="excused">請假</option>
            <option value="absent">缺席</option>
          </select>
        </div>
      </div>

      <div class="att-table-scroll">
        <table>
          <thead>
            <tr>
              <th>時段</th>
              <th>學生</th>
              <th>科目</th>
              <th>老師</th>
              <th>分校</th>
              <th>狀態</th>
              <th v-if="!isTeacher" style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in filteredRecords" :key="record.id" class="att-record-row">
              <td>
                <span class="att-time-range">{{ formatTime(record.SignInDT) }}</span>
                <span v-if="record.SignOutDT" class="att-time-sep">–{{ formatTime(record.SignOutDT) }}</span>
              </td>
              <td><span class="att-person-name">{{ record.person_name }}</span></td>
              <td>{{ record.subject_name || '—' }}</td>
              <td>{{ record.course_teacher_name || record.teacher_name || '—' }}</td>
              <td>{{ record.campus_name || '—' }}</td>
              <td>
                <span class="status-tag" :class="statusTagClass(record.Status)">
                  {{ record.status_label }}
                </span>
              </td>
              <td v-if="!isTeacher" style="text-align:right">
                <button v-if="!record._editing" class="ghost xs" @click="record._editing = true">修改</button>
                <div v-else class="att-inline-edit">
                  <select v-model="record._newStatus" class="att-status-select">
                    <option value="present">到班</option>
                    <option value="late">遲到</option>
                    <option value="excused">請假</option>
                    <option value="absent">缺席</option>
                  </select>
                  <button class="primary xs" :disabled="record._saving" @click="saveStatusEdit(record)">
                    {{ record._saving ? '…' : '✓' }}
                  </button>
                  <button class="ghost xs" @click="record._editing = false">✕</button>
                </div>
              </td>
            </tr>
            <tr v-if="filteredRecords.length === 0">
              <td :colspan="isTeacher ? 6 : 7" class="empty-text">今日尚無出缺勤紀錄</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="att-refresh-hint">
        每 30 秒自動更新 · 上次更新：{{ lastRefresh }}
      </div>
    </div>

    <!-- Pending Swipes -->
    <div v-if="!isTeacher && pendingSwipes.length > 0" class="card att-pending-card">
      <div class="att-section-title">未識別刷卡紀錄</div>
      <div class="att-table-scroll">
        <table>
          <thead>
            <tr>
              <th>時間</th>
              <th>卡片 UID</th>
              <th>原因</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="swipe in pendingSwipes" :key="swipe.id">
              <td>{{ formatTime(swipe.SwipeAt) }}</td>
              <td class="att-rfid">{{ maskRfid(swipe.RFID) }}</td>
              <td>{{ reasonLabel(swipe.Reason) }}</td>
              <td style="text-align:right">
                <button class="ghost xs" @click="handleDismiss(swipe.id)">忽略</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { supabase } from '../supabase';
import SearchableSelect from '../components/SearchableSelect.vue';

const props = defineProps({
  branchId: [String, Number],
  userRole: String,
  userId: [String, Number],
});
const isTeacher = computed(() => props.userRole === 'teacher');

const records = ref([]);
const pendingSwipes = ref([]);
const studentList = ref([]);
const searchName = ref('');
const filterStatus = ref('');
const lastRefresh = ref('');
const manualMsg = ref('');
const manualMsgType = ref('');

const pendingSessions = ref([]);
const pendingLoading = ref(false);
const pendingMarkStatus = ref({});
const pendingMarkSubmitting = ref({});
const pendingMarkMsg = ref('');
const pendingMarkMsgType = ref('');
const todaySessionTotal = ref(0);

let refreshTimer = null;

const manualForm = ref({
  personKey: '',
  date: new Date().toISOString().split('T')[0],
  time: new Date().toTimeString().slice(0, 5),
  status: 'present',
  memo: ''
});

const getToken = async () => {
  const { data: { session } } = await supabase.auth.getSession();
  return session?.access_token;
};

const personOptions = computed(() =>
  studentList.value.map(s => ({ value: `student:${s.id}`, label: `${s.name}（學生）` }))
);

const stats = computed(() => {
  const list = records.value;
  return {
    total: list.length,
    present: list.filter(r => r.Status === 'present').length,
    late: list.filter(r => r.Status === 'late').length,
    absent: list.filter(r => r.Status === 'absent').length,
    excused: list.filter(r => r.Status === 'excused').length,
  };
});

const markedSessionsCount = computed(() => {
  const ids = new Set(
    records.value
      .map((r) => Number(r?.ClassSessionID || 0))
      .filter((id) => id > 0)
  );
  return ids.size;
});

const filteredRecords = computed(() => {
  let list = records.value;
  if (searchName.value) {
    const q = searchName.value.toLowerCase();
    list = list.filter(r => (r.person_name || '').toLowerCase().includes(q));
  }
  if (filterStatus.value) {
    list = list.filter(r => r.Status === filterStatus.value);
  }
  return list;
});

const localTodayYmd = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const fetchRecords = async () => {
  try {
    const token = await getToken();
    if (!token) return;
    const today = localTodayYmd();
    const params = new URLSearchParams({ date: today, per_page: '200' });
    if (props.branchId) params.set('branch_id', String(props.branchId));
    const res = await fetch(`/api/v1/attendance?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const data = await res.json();
      records.value = (data.data || []).map(r => ({ ...r, _editing: false, _newStatus: r.Status, _saving: false }));
    }
  } catch (e) {
    console.error('fetchRecords', e);
  }
  lastRefresh.value = new Date().toLocaleTimeString('zh-TW');
};

const fetchPendingSessions = async () => {
  if (!props.branchId) { pendingSessions.value = []; return; }
  pendingLoading.value = true;
  pendingMarkMsg.value = '';
  try {
    const token = await getToken();
    if (!token) return;
    const today = localTodayYmd();
    const qs = new URLSearchParams({ branch_id: String(props.branchId), start: today, end: today, per_page: '500' });
    const classSessionsRes = await fetch(`/api/v1/class-sessions?${qs}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (classSessionsRes.ok) {
      const classSessionsJson = await classSessionsRes.json().catch(() => ({}));
      const classSessionRows = Array.isArray(classSessionsJson?.data) ? classSessionsJson.data : [];
      todaySessionTotal.value = classSessionRows.filter((row) => {
        const status = String(row?.status || '').toLowerCase();
        return !['cancelled', 'leave', 'leave_adjusted'].includes(status);
      }).length;
    } else {
      todaySessionTotal.value = 0;
    }

    let pending = [];
    if (isTeacher.value) {
      const res = await fetch(`/api/v1/class-sessions?${qs}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (!res.ok) { pendingSessions.value = []; return; }
      const json = await res.json().catch(() => ({}));
      const rows = Array.isArray(json?.data) ? json.data : [];
      pending = rows
        .filter(r => String(r?.status || '').toLowerCase() === 'scheduled')
        .map(r => ({
          class_session_id: Number(r.id || 0),
          student_id: Number(r.student_id || 0),
          student_class_id: Number(r.student_class_id || 0),
          teacher_id: Number(r.teacher_id || props.userId || 0),
          session_date: String(r.session_date || '').slice(0, 10),
          start_time: String(r.start_time || '').slice(0, 5),
          end_time: String(r.end_time || '').slice(0, 5),
          student_name: r.student_name || '',
          subject_name: r.subject_name || '',
          teacher_name: r.teacher_name || '',
        }))
        .filter(r => r.class_session_id > 0 && r.student_id > 0 && r.student_class_id > 0)
        .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));
    } else {
      // Director/admin view should allow marking at class start.
      // Use today's scheduled sessions (same source as teacher view),
      // instead of ended-sessions which only returns sessions after EndTime.
      const res = await fetch(`/api/v1/class-sessions?${qs}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (!res.ok) { pendingSessions.value = []; return; }
      const json = await res.json().catch(() => ({}));
      const rows = Array.isArray(json?.data) ? json.data : [];
      pending = rows
        .filter(r => String(r?.status || '').toLowerCase() === 'scheduled')
        .map((r) => ({
          class_session_id: Number(r.id || 0),
          student_id: Number(r.student_id || 0),
          student_class_id: Number(r.student_class_id || 0),
          teacher_id: Number(r.teacher_id || 0),
          session_date: String(r.session_date || '').slice(0, 10),
          start_time: String(r.start_time || '').slice(0, 5),
          end_time: String(r.end_time || '').slice(0, 5),
          student_name: r.student_name || '',
          subject_name: r.subject_name || '',
          teacher_name: r.teacher_name || '',
        }))
        .filter(r => r.class_session_id > 0 && r.student_id > 0 && r.student_class_id > 0)
        .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));
    }
    pendingSessions.value = pending;
    const next = {};
    pending.forEach(r => { next[r.class_session_id] = pendingMarkStatus.value[r.class_session_id] || 'present'; });
    pendingMarkStatus.value = next;
  } catch (e) {
    console.error('fetchPendingSessions', e);
    pendingSessions.value = [];
  } finally {
    pendingLoading.value = false;
  }
};

const submitPendingMark = async (s) => {
  pendingMarkMsg.value = '';
  const status = pendingMarkStatus.value[s.class_session_id] ?? 'present';
  pendingMarkSubmitting.value = { ...pendingMarkSubmitting.value, [s.class_session_id]: true };
  try {
    const token = await getToken();
    if (!token) { pendingMarkMsg.value = '請先登入'; pendingMarkMsgType.value = 'error'; return; }
    const res = await fetch('/api/v1/attendance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        StudentID: s.student_id,
        StudentClassID: s.student_class_id,
        TeacherID: s.teacher_id || props.userId || null,
        ClassSessionID: s.class_session_id,
        Status: status,
        mark_mode: 'arrival',
      })
    });
    if (res.ok) {
      const statusLabel = { present: '到班', late: '遲到', excused: '請假', absent: '缺席' }[status] || status;
      pendingMarkMsg.value = `已核課：${s.student_name} ${statusLabel}`;
      pendingMarkMsgType.value = 'success';
      await Promise.all([fetchPendingSessions(), fetchRecords()]);
    } else {
      const err = await res.json().catch(() => ({}));
      pendingMarkMsg.value = '核課失敗：' + (err.message || '未知錯誤');
      pendingMarkMsgType.value = 'error';
    }
  } catch (e) {
    pendingMarkMsg.value = '核課失敗：網路錯誤';
    pendingMarkMsgType.value = 'error';
  } finally {
    pendingMarkSubmitting.value = { ...pendingMarkSubmitting.value, [s.class_session_id]: false };
  }
};

const saveStatusEdit = async (record) => {
  if (record._newStatus === record.Status) { record._editing = false; return; }
  record._saving = true;
  try {
    const token = await getToken();
    if (!token) return;
    if (!record.ClassSessionID) {
      alert('此記錄缺少堂次關聯，無法修改狀態');
      record._saving = false;
      return;
    }
    const statusMap = { present: 'attended', late: 'late', excused: 'excused', absent: 'absent' };
    const csStatus = statusMap[record._newStatus] || 'attended';
    const res = await fetch(`/api/v1/class-sessions/${record.ClassSessionID}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ status: csStatus }),
    });
    if (res.ok) {
      record.Status = record._newStatus;
      record.status_label = { present: '到班', late: '遲到', excused: '請假', absent: '缺席' }[record._newStatus] || record._newStatus;
      record._editing = false;
    } else {
      const err = await res.json().catch(() => ({}));
      alert('修改失敗：' + (err.message || '未知錯誤'));
    }
  } catch (e) {
    alert('修改失敗：' + (e?.message || '網路錯誤'));
  } finally {
    record._saving = false;
  }
};

const submitManual = async () => {
  manualMsg.value = '';
  if (!manualForm.value.personKey) {
    manualMsg.value = '請選擇人員';
    manualMsgType.value = 'error';
    return;
  }
  const [personType, personIdStr] = manualForm.value.personKey.split(':');
  const personId = parseInt(personIdStr);
  const signInDT = `${manualForm.value.date} ${manualForm.value.time}:00`;
  try {
    const token = await getToken();
    const res = await fetch('/api/v1/attendance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        PersonType: personType,
        PersonID: personId,
        SignInDT: signInDT,
        Status: manualForm.value.status,
        Memo: manualForm.value.memo
      })
    });
    if (res.ok) {
      const data = await res.json();
      manualMsg.value = `已登記：${data.person_name || ''}`;
      manualMsgType.value = 'success';
      manualForm.value.personKey = '';
      manualForm.value.memo = '';
      fetchRecords();
    } else {
      const err = await res.json();
      manualMsg.value = '登記失敗：' + (err.message || '未知錯誤');
      manualMsgType.value = 'error';
    }
  } catch (e) {
    manualMsg.value = '登記失敗：網路錯誤';
    manualMsgType.value = 'error';
  }
};

const fetchPending = async () => {
  if (isTeacher.value) { pendingSwipes.value = []; return; }
  try {
    const token = await getToken();
    if (!token) return;
    const res = await fetch('/api/v1/pending-swipes', { headers: { 'Authorization': `Bearer ${token}` } });
    if (res.ok) {
      const data = await res.json();
      pendingSwipes.value = data.data || data || [];
    }
  } catch (e) { console.error('fetchPending', e); }
};

const fetchStudents = async () => {
  if (isTeacher.value) { studentList.value = []; return; }
  try {
    const token = await getToken();
    if (!token) return;
    const res = await fetch(`/api/v1/students?per_page=500&branch_id=${props?.branchId || ''}`, { headers: { 'Authorization': `Bearer ${token}` } });
    if (res.ok) {
      const data = await res.json();
      studentList.value = data.data || data || [];
    }
  } catch (e) { console.error('fetchStudents', e); }
};

const handleDismiss = async (id) => {
  if (!confirm('確定忽略此刷卡紀錄？')) return;
  try {
    const token = await getToken();
    await fetch(`/api/v1/pending-swipes/${id}`, { method: 'DELETE', headers: { 'Authorization': `Bearer ${token}` } });
    fetchPending();
  } catch (e) { console.error(e); }
};

const refreshAll = () => {
  fetchRecords();
  fetchPendingSessions();
  if (!isTeacher.value) fetchPending();
};

const formatTime = (dt) => {
  if (!dt) return '—';
  try {
    const d = new Date(dt);
    return d.toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' });
  } catch { return dt; }
};

const maskRfid = (rfid) => {
  if (!rfid || rfid.length <= 4) return rfid || '-';
  return rfid.slice(0, 2) + '****' + rfid.slice(-2);
};

const statusTagClass = (status) => {
  const map = { present: 'active', late: 'pending', excused: 'excused', absent: 'rejected' };
  return map[status] || '';
};

const reasonLabel = (reason) => {
  const map = {
    unknown_rfid: '未綁定卡片', student_not_found: '查無此學生',
    campus_mismatch: '分校不符', no_session: '無排課',
    no_match_in_window: '無匹配時段', ambiguous_session: '多堂課衝突'
  };
  return map[reason] || reason;
};

onMounted(() => {
  fetchRecords();
  fetchPendingSessions();
  if (!isTeacher.value) {
    fetchPending();
    fetchStudents();
  }
  refreshTimer = setInterval(() => {
    fetchRecords();
    fetchPendingSessions();
    if (!isTeacher.value) fetchPending();
  }, 30000);
});

onBeforeUnmount(() => { if (refreshTimer) clearInterval(refreshTimer); });

watch(() => props.branchId, () => {
  fetchRecords();
  fetchPendingSessions();
  if (!isTeacher.value) fetchPending();
});
</script>

<style scoped>
.att-page { max-width: 1200px; }

.att-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  flex-wrap: wrap; gap: 12px;
}
.att-header-btns { display: flex; gap: 8px; flex-wrap: wrap; }

/* Stats */
.att-stats {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px;
}
.att-stat-card {
  background: #fff; border-radius: 12px; padding: 16px 20px; text-align: center;
  border: 1px solid rgba(148,163,184,0.18); box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.att-stat-num { font-size: 28px; font-weight: 800; color: #334155; }
.att-stat-label { font-size: 12px; font-weight: 600; color: #94a3b8; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.5px; }
.stat-present .att-stat-num { color: #16a34a; }
.stat-late .att-stat-num { color: #d97706; }
.stat-absent .att-stat-num { color: #dc2626; }

/* Section */
.att-section-title {
  font-size: 15px; font-weight: 700; color: var(--primary);
  letter-spacing: 0.3px;
}
.att-checkin-header {
  display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
}
.att-badge {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 22px; height: 22px; border-radius: 999px; padding: 0 6px;
  font-size: 12px; font-weight: 700; color: #fff; background: var(--primary);
}
.att-hint {
  font-size: 0.88rem; color: var(--text-light, #666); margin-bottom: 12px;
}
.att-empty {
  padding: 24px; text-align: center; font-size: 14px; color: #94a3b8;
}
.att-required { color: var(--danger); }

/* Check-in card */
.att-checkin-card { padding: 20px 24px; }
.att-status-select {
  padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border-color, #ddd);
  font-size: 13px;
}

/* Manual entry (collapsed) */
.att-manual-details {
  margin-top: 16px; border-top: 1px solid rgba(148,163,184,0.15); padding-top: 12px;
}
.att-manual-toggle {
  cursor: pointer; font-size: 13px; font-weight: 600; color: var(--primary);
  padding: 6px 0; user-select: none;
}
.att-manual-toggle:hover { text-decoration: underline; }
.att-manual-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 10px 14px; align-items: end; margin-top: 12px;
}
.att-submit-wrap { display: flex; flex-direction: column; }
.att-submit-wrap button { width: 100%; }

/* Records card */
.att-records-card { padding: 20px 24px; }
.att-records-header {
  display: flex; justify-content: space-between; align-items: center;
  flex-wrap: wrap; gap: 12px; margin-bottom: 16px;
}
.att-records-controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.att-search-input { width: 150px; padding: 7px 12px; font-size: 13px; }
.att-filter-select { width: 100px; padding: 7px 10px; font-size: 13px; }

/* Table */
.att-table-scroll { overflow-x: auto; }
.att-time-range { font-weight: 600; font-size: 13.5px; white-space: nowrap; }
.att-time-sep { color: var(--text-light); font-size: 13px; }
.att-person-name { font-weight: 600; font-size: 13.5px; }
.att-rfid { font-family: 'Courier New', monospace; font-size: 13px; letter-spacing: 1px; color: var(--text-light); }

.att-record-row:hover { background: rgba(59,130,246,0.03); }

/* Inline edit */
.att-inline-edit { display: flex; gap: 4px; align-items: center; justify-content: flex-end; }
.att-inline-edit .att-status-select { font-size: 12px; padding: 2px 4px; }

/* Tags */
.status-tag.excused { background: #E3F2FD; color: #1565C0; }
.status-tag.rejected { background: var(--danger-bg); color: var(--danger); }

/* Messages */
.att-msg {
  margin-top: 10px; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 500;
}
.att-msg.success { background: var(--success-bg); color: var(--success); }
.att-msg.error { background: var(--danger-bg); color: var(--danger); }

/* Refresh hint */
.att-refresh-hint {
  margin-top: 12px; font-size: 12px; color: var(--text-light); text-align: right;
}

/* Pending card */
.att-pending-card { padding: 20px 24px; border-left: 4px solid var(--warning); }

/* Responsive */
@media (max-width: 768px) {
  .att-stats { grid-template-columns: repeat(2, 1fr); }
  .att-manual-grid { grid-template-columns: 1fr; }
  .att-records-header { flex-direction: column; align-items: stretch; }
  .att-records-controls { flex-direction: column; }
  .att-search-input, .att-filter-select { width: 100%; }
  .att-header { flex-direction: column; }
  .att-header-btns button { width: 100%; }
}
@media (max-width: 480px) {
  .att-stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .att-stat-card { padding: 12px; }
  .att-stat-num { font-size: 22px; }
  .att-checkin-card, .att-records-card, .att-pending-card { padding: 16px; }
}
</style>
