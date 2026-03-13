<template>
  <div class="att-page">
    <HelpGuide
      title="出缺勤管理 — 使用說明"
      :items="[
        '「依節次點名」：列出已結束且尚未點名的節次，選擇狀態（到班/遲到/請假/缺席）後送出；點名成功才會扣堂。',
        '「+ 手動登記」：選擇人員、日期時間、狀態後送出（需對應到該節課程才會扣堂）。',
        '下方列表可篩選日期、搜尋姓名，查看即時到班與離班紀錄。',
        '「未識別刷卡」：處理刷卡機讀到但尚未對應到學生/老師的紀錄，可手動指定人員。'
      ]"
      tip="該節結束後才能點名；點名成功才扣堂。評量可後補，審核通過後加老師科目數。"
    />
    <!-- Page Header -->
    <div class="page-header att-header">
      <div>
        <h2>出缺勤管理</h2>
        <p class="page-desc">即時追蹤學生與老師的到班 / 離班狀態</p>
      </div>
      <div class="att-header-btns">
        <button
          class="primary"
          :class="{ active: showSessionMark }"
          @click="showSessionMark = !showSessionMark; if (showSessionMark) { showManualEntry = false; fetchEndedSessions(); }"
        >
          {{ showSessionMark ? '收起' : '依節次點名' }}
        </button>
        <button class="primary" @click="showManualEntry = !showManualEntry; if (showManualEntry) showSessionMark = false">
          {{ showManualEntry ? '收起' : '+ 手動登記' }}
        </button>
      </div>
    </div>

    <!-- Manual Entry Card -->
    <div v-if="showManualEntry" class="card att-manual-card">
      <div class="att-section-title">手動登記出缺勤</div>
      <div class="att-manual-grid">
        <div class="form-group">
          <label>選擇人員 <span class="att-required">*</span></label>
          <SearchableSelect
            v-model="manualForm.personKey"
            :options="personOptions"
            placeholder="搜尋學生或老師姓名..."
          />
        </div>
        <div class="form-group">
          <label>到班日期 <span class="att-required">*</span></label>
          <input v-model="manualForm.date" type="date" />
        </div>
        <div class="form-group">
          <label>到班時間 <span class="att-required">*</span></label>
          <input v-model="manualForm.time" type="time" />
        </div>
        <div class="form-group">
          <label>狀態</label>
          <select v-model="manualForm.status">
            <option value="present">到班</option>
            <option value="late">遲到</option>
            <option value="excused">請假</option>
            <option value="absent">缺席</option>
            <option value="leave">離班</option>
          </select>
        </div>
        <div class="form-group">
          <label>備註</label>
          <input v-model="manualForm.memo" type="text" placeholder="選填..." />
        </div>
        <div class="form-group att-submit-wrap">
          <label>&nbsp;</label>
          <button class="primary" @click="submitManual">登記</button>
        </div>
      </div>
      <p v-if="manualMsg" class="att-msg" :class="manualMsgType">{{ manualMsg }}</p>
    </div>

    <!-- 依節次點名 Card -->
    <div v-if="showSessionMark" class="card att-manual-card">
      <div class="att-section-title">依節次點名（已結束且尚未點名的節次）</div>
      <p class="att-hint">僅該節結束後才可點名；點名成功且狀態為到班/遲到時會扣堂。</p>
      <div v-if="!branchId" class="att-msg">請先選擇分校</div>
      <template v-else>
        <button class="ghost xs" style="margin-bottom:8px;" @click="fetchEndedSessions">重新整理</button>
        <div v-if="endedSessionsLoading" class="att-msg">載入中…</div>
        <div v-else-if="endedSessions.length === 0" class="att-msg">目前沒有已結束且尚未點名的節次</div>
      <div v-else class="att-table-scroll">
        <table>
          <thead>
            <tr>
              <th>日期</th>
              <th>時段</th>
              <th>學生</th>
              <th>科目</th>
              <th>狀態</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in endedSessions" :key="s.class_session_id">
              <td>{{ s.session_date }}</td>
              <td>{{ s.start_time }}–{{ s.end_time }}</td>
              <td>{{ s.student_name }}</td>
              <td>{{ s.subject_name }}</td>
              <td>
                <select v-model="sessionMarkStatus[s.class_session_id]" class="att-status-select">
                  <option value="present">到班</option>
                  <option value="late">遲到</option>
                  <option value="excused">請假</option>
                  <option value="absent">缺席</option>
                </select>
              </td>
              <td>
                <button
                  class="primary small"
                  :disabled="sessionMarkSubmitting[s.class_session_id]"
                  @click="submitSessionMark(s)"
                >
                  {{ sessionMarkSubmitting[s.class_session_id] ? '送出中…' : '點名' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="sessionMarkMsg" class="att-msg" :class="sessionMarkMsgType">{{ sessionMarkMsg }}</p>
      </template>
    </div>

    <!-- Today's Overview -->
    <div class="card att-overview-card">
      <div class="att-overview-header">
        <div class="att-section-title">今日出缺勤紀錄</div>
        <div class="att-overview-controls">
          <input
            v-model="searchName"
            type="text"
            placeholder="搜尋姓名..."
            class="att-search-input"
          />
          <select v-model="filterStatus" class="att-filter-select">
            <option value="">全部狀態</option>
            <option value="present">到班</option>
            <option value="late">遲到</option>
            <option value="excused">請假</option>
            <option value="absent">缺席</option>
            <option value="leave">離班</option>
          </select>
          <button class="ghost xs" @click="fetchRecords">重新整理</button>
        </div>
      </div>

      <div class="att-table-scroll">
        <table>
          <thead>
            <tr>
              <th>時間</th>
              <th>姓名</th>
              <th>身分</th>
              <th>分校</th>
              <th>狀態</th>
              <th>備註</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in filteredRecords" :key="record.id">
              <td>
                <span class="att-time">{{ formatTime(record.SignInDT) }}</span>
              </td>
              <td>
                <span class="att-person-name">{{ record.person_name }}</span>
              </td>
              <td>
                <span class="tag" :class="record.PersonType === 'teacher' ? 'tag-teacher' : ''">
                  {{ record.person_type_label }}
                </span>
              </td>
              <td>{{ record.campus_name }}</td>
              <td>
                <span class="status-tag" :class="statusTagClass(record.Status)">
                  {{ record.status_label }}
                </span>
              </td>
              <td class="att-memo">{{ record.Memo !== 'swipe' ? record.Memo : '' }}</td>
            </tr>
            <tr v-if="filteredRecords.length === 0">
              <td colspan="6" class="empty-text">今日尚無出缺勤紀錄</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="att-refresh-hint">
        每 30 秒自動更新 &middot; 上次更新：{{ lastRefresh }}
      </div>
    </div>

    <!-- Pending Swipes -->
    <div v-if="pendingSwipes.length > 0" class="card att-pending-card">
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
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { supabase } from '../supabase';
import SearchableSelect from '../components/SearchableSelect.vue';
import HelpGuide from '../components/HelpGuide.vue';

const props = defineProps(['branchId', 'userRole', 'userId']);

// ── State ──
const records = ref([]);
const pendingSwipes = ref([]);
const teacherList = ref([]);
const studentList = ref([]);
const showManualEntry = ref(false);
const showSessionMark = ref(false);
const searchName = ref('');
const filterStatus = ref('');
const lastRefresh = ref('');
const manualMsg = ref('');
const manualMsgType = ref('');
const endedSessions = ref([]);
const endedSessionsLoading = ref(false);
const sessionMarkStatus = ref({});
const sessionMarkSubmitting = ref({});
const sessionMarkMsg = ref('');
const sessionMarkMsgType = ref('');
let refreshTimer = null;

const manualForm = ref({
  personKey: '',
  date: new Date().toISOString().split('T')[0],
  time: new Date().toTimeString().slice(0, 5),
  status: 'present',
  memo: ''
});

// ── Auth ──
const getToken = async () => {
  const { data: { session } } = await supabase.auth.getSession();
  return session?.access_token;
};

// ── Dropdown options: combined students + teachers ──
const personOptions = computed(() => {
  const students = studentList.value.map(s => ({
    value: `student:${s.id}`,
    label: `${s.name}（學生）`
  }));
  const teachers = teacherList.value.map(t => ({
    value: `teacher:${t.id}`,
    label: `${t.T_Name}（老師）`
  }));
  return [...students, ...teachers];
});

// ── Filtered records (client-side search + status) ──
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

// ── Fetch functions ──
const fetchRecords = async () => {
  try {
    const token = await getToken();
    if (!token) return;

    const today = new Date().toISOString().split('T')[0];
    const res = await fetch(`/api/v1/attendance?date=${today}&per_page=200`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });

    if (res.ok) {
      const data = await res.json();
      records.value = data.data || [];
    }
  } catch (e) {
    console.error('fetchRecords', e);
  }
  lastRefresh.value = new Date().toLocaleTimeString('zh-TW');
};

const fetchPending = async () => {
  try {
    const token = await getToken();
    if (!token) return;

    const res = await fetch('/api/v1/pending-swipes', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const data = await res.json();
      pendingSwipes.value = data.data || data || [];
    }
  } catch (e) {
    console.error('fetchPending', e);
  }
};

const fetchStudents = async () => {
  try {
    const token = await getToken();
    if (!token) return;

    const res = await fetch('/api/v1/students?per_page=1000', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const data = await res.json();
      studentList.value = data.data || data || [];
    }
  } catch (e) {
    console.error('fetchStudents', e);
  }
};

const fetchTeachers = async () => {
  try {
    const token = await getToken();
    if (!token) return;

    const res = await fetch('/api/v1/teachers', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      teacherList.value = await res.json();
    }
  } catch (e) {
    console.error('fetchTeachers', e);
  }
};

// ── 依節次點名：已結束且尚未點名的節次 ──
const fetchEndedSessions = async () => {
  if (!props.branchId) return;
  endedSessionsLoading.value = true;
  sessionMarkMsg.value = '';
  try {
    const token = await getToken();
    if (!token) return;
    const res = await fetch(`/api/v1/attendance/ended-sessions?branch_id=${props.branchId}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const data = await res.json();
      endedSessions.value = Array.isArray(data) ? data : (data.data || []);
      sessionMarkStatus.value = {};
      endedSessions.value.forEach(s => {
        sessionMarkStatus.value[s.class_session_id] = 'present';
      });
    } else {
      endedSessions.value = [];
    }
  } catch (e) {
    console.error('fetchEndedSessions', e);
    endedSessions.value = [];
  } finally {
    endedSessionsLoading.value = false;
  }
};

const submitSessionMark = async (s) => {
  sessionMarkMsg.value = '';
  const status = sessionMarkStatus.value[s.class_session_id] ?? 'present';
  sessionMarkSubmitting.value = { ...sessionMarkSubmitting.value, [s.class_session_id]: true };
  try {
    const token = await getToken();
    if (!token) {
      sessionMarkMsg.value = '請先登入';
      sessionMarkMsgType.value = 'error';
      return;
    }
    const res = await fetch('/api/v1/attendance', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({
        StudentID: s.student_id,
        StudentClassID: s.student_class_id,
        TeacherID: s.teacher_id,
        ClassSessionID: s.class_session_id,
        Status: status
      })
    });
    if (res.ok) {
      sessionMarkMsg.value = `已點名：${s.student_name} ${s.session_date} ${status === 'present' ? '到班' : status === 'late' ? '遲到' : status === 'excused' ? '請假' : '缺席'}`;
      sessionMarkMsgType.value = 'success';
      fetchEndedSessions();
      fetchRecords();
    } else {
      const err = await res.json();
      sessionMarkMsg.value = '點名失敗：' + (err.message || '未知錯誤');
      sessionMarkMsgType.value = 'error';
    }
  } catch (e) {
    sessionMarkMsg.value = '點名失敗：網路錯誤';
    sessionMarkMsgType.value = 'error';
  } finally {
    sessionMarkSubmitting.value = { ...sessionMarkSubmitting.value, [s.class_session_id]: false };
  }
};

// ── Manual submit ──
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
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
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

// ── Dismiss pending swipe ──
const handleDismiss = async (id) => {
  if (!confirm('確定忽略此刷卡紀錄？')) return;
  try {
    const token = await getToken();
    await fetch(`/api/v1/pending-swipes/${id}`, {
      method: 'DELETE',
      headers: { 'Authorization': `Bearer ${token}` }
    });
    fetchPending();
  } catch (e) {
    console.error(e);
  }
};

// ── Helpers ──
const formatTime = (dt) => {
  if (!dt) return '-';
  try {
    const d = new Date(dt);
    return d.toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' });
  } catch {
    return dt;
  }
};

const maskRfid = (rfid) => {
  if (!rfid || rfid.length <= 4) return rfid || '-';
  return rfid.slice(0, 2) + '****' + rfid.slice(-2);
};

const statusTagClass = (status) => {
  const map = {
    present: 'active',
    late: 'pending',
    excused: 'excused',
    absent: 'rejected',
    leave: 'leave'
  };
  return map[status] || '';
};

const reasonLabel = (reason) => {
  const map = {
    unknown_rfid: '未綁定卡片',
    student_not_found: '查無此學生',
    campus_mismatch: '分校不符',
    no_session: '無排課',
    no_match_in_window: '無匹配時段',
    ambiguous_session: '多堂課衝突'
  };
  return map[reason] || reason;
};

// ── Lifecycle ──
onMounted(() => {
  fetchRecords();
  fetchPending();
  fetchStudents();
  fetchTeachers();
  // Auto-refresh every 30 seconds
  refreshTimer = setInterval(() => {
    fetchRecords();
    fetchPending();
  }, 30000);
});

onBeforeUnmount(() => {
  if (refreshTimer) clearInterval(refreshTimer);
});
</script>

<style scoped>
/* ── Page ── */
.att-page {
  max-width: 1200px;
}

.att-header-btns {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.att-header-btns button.active {
  opacity: 1;
  font-weight: 600;
}
.att-hint {
  font-size: 0.9rem;
  color: var(--text-light, #666);
  margin-bottom: 12px;
}
.att-status-select {
  padding: 4px 8px;
  border-radius: 4px;
  border: 1px solid var(--border-color, #ddd);
}
.att-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 12px;
}

/* ── Section Title ── */
.att-section-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--primary);
  margin-bottom: 16px;
  letter-spacing: 0.3px;
}

.att-required {
  color: var(--danger);
}

/* ── Manual Entry ── */
.att-manual-card {
  padding: 20px 24px;
}

.att-manual-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px 16px;
  align-items: end;
}

.att-submit-wrap {
  display: flex;
  flex-direction: column;
}

.att-submit-wrap button {
  width: 100%;
}

.att-msg {
  margin-top: 12px;
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
}

.att-msg.success {
  background: var(--success-bg);
  color: var(--success);
}

.att-msg.error {
  background: var(--danger-bg);
  color: var(--danger);
}

/* ── Overview Card ── */
.att-overview-card {
  padding: 20px 24px;
}

.att-overview-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;
}

.att-overview-header .att-section-title {
  margin-bottom: 0;
}

.att-overview-controls {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}

.att-search-input {
  width: 160px;
  padding: 7px 12px;
  font-size: 13px;
}

.att-filter-select {
  width: 120px;
  padding: 7px 10px;
  font-size: 13px;
}

/* ── Table ── */
.att-table-scroll {
  overflow-x: auto;
}

.att-time {
  font-weight: 600;
  font-size: 14px;
}

.att-person-name {
  font-weight: 600;
  font-size: 13.5px;
}

.att-memo {
  font-size: 12.5px;
  color: var(--text-light);
  max-width: 150px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.att-rfid {
  font-family: 'Courier New', monospace;
  font-size: 13px;
  letter-spacing: 1px;
  color: var(--text-light);
}

/* ── Tag variants ── */
.tag-teacher {
  background: #E3F2FD;
  color: #1565C0;
}

/* ── Status tag variants ── */
.status-tag.excused {
  background: #E3F2FD;
  color: #1565C0;
}

.status-tag.rejected {
  background: var(--danger-bg);
  color: var(--danger);
}

.status-tag.leave {
  background: #ECEFF1;
  color: #546E7A;
}

/* ── Refresh hint ── */
.att-refresh-hint {
  margin-top: 12px;
  font-size: 12px;
  color: var(--text-light);
  text-align: right;
}

/* ── Pending Card ── */
.att-pending-card {
  padding: 20px 24px;
  border-left: 4px solid var(--warning);
}

/* ── Responsive ── */
@media (max-width: 768px) {
  .att-manual-grid {
    grid-template-columns: 1fr;
  }

  .att-overview-header {
    flex-direction: column;
    align-items: stretch;
  }

  .att-overview-controls {
    flex-direction: column;
  }

  .att-search-input,
  .att-filter-select {
    width: 100%;
  }

  .att-header {
    flex-direction: column;
  }

  .att-header button {
    width: 100%;
  }
}

@media (max-width: 480px) {
  .att-manual-card,
  .att-overview-card,
  .att-pending-card {
    padding: 16px;
  }
}
</style>
