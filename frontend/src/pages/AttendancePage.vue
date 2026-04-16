<template>
  <div class="att-page">
    <div class="page-header att-header">
      <div>
        <h2>出缺勤管理</h2>
        <p class="page-desc">
          {{ isTeacher ? '查看你今日堂次並即時點名，也可補登過去堂次' : '追蹤學生到班狀態、點名核課、補登過往堂次' }}
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

    <div v-if="fetchError" class="att-msg error" style="margin-bottom:12px">{{ fetchError }}</div>

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
      <div v-if="!isTeacher && !branchId" class="att-empty">請先選擇分校</div>
      <div v-else-if="pendingLoading" class="att-empty">載入中…</div>
      <div v-else-if="pendingSessions.length === 0" class="att-empty">今日沒有待點名堂次</div>
      <template v-else>
        <!-- Batch action bar -->
        <div class="att-batch-bar">
          <label class="att-check-all">
            <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" />
            <span>全選</span>
          </label>
          <button
            v-if="selectedIds.length > 0"
            class="primary small"
            :disabled="batchSubmitting"
            @click="batchMarkAllPresent"
          >
            {{ batchSubmitting ? '送出中…' : `全部到班（${selectedIds.length}）` }}
          </button>
          <span v-if="selectedIds.length > 0" class="att-batch-hint">
            或逐列選擇其他狀態
          </span>
        </div>

        <!-- Desktop table (hidden on mobile) -->
        <div class="att-table-scroll att-desktop-only">
          <table>
            <thead>
              <tr>
                <th style="width:36px"></th>
                <th>時段</th>
                <th>學生</th>
                <th>科目</th>
                <th>老師</th>
                <th>狀態</th>
                <th style="text-align:right">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in pendingSessions" :key="s.class_session_id" :class="{ 'att-row-selected': selectedSet.has(s.class_session_id) }">
                <td>
                  <input type="checkbox" :checked="selectedSet.has(s.class_session_id)" @change="toggleSelect(s.class_session_id)" />
                </td>
                <td class="att-time-range">{{ s.start_time }}–{{ s.end_time }}</td>
                <td><span class="att-person-name">{{ s.student_name || '—' }}</span></td>
                <td>{{ s.subject_name || '—' }}</td>
                <td>{{ s.teacher_name || '—' }}</td>
                <td>
                  <div class="att-status-group">
                    <button
                      v-for="opt in statusOptions" :key="opt.value"
                      :class="['att-status-btn', `att-st-${opt.value}`, { active: pendingMarkStatus[s.class_session_id] === opt.value }]"
                      @click="setStatus(s.class_session_id, opt.value)"
                    >{{ opt.short }}</button>
                  </div>
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

        <!-- Mobile cards (hidden on desktop) -->
        <div class="att-mobile-only att-cards" :style="selectedIds.length > 0 ? { paddingBottom: '72px' } : {}">
          <div
            v-for="s in pendingSessions" :key="'m-' + s.class_session_id"
            class="att-card"
            :class="{ 'att-card-selected': selectedSet.has(s.class_session_id) }"
          >
            <div class="att-card-top">
              <input type="checkbox" :checked="selectedSet.has(s.class_session_id)" @change="toggleSelect(s.class_session_id)" class="att-card-check" />
              <div class="att-card-info">
                <div class="att-card-student">{{ s.student_name || '—' }}</div>
                <div class="att-card-meta">
                  <span class="att-card-time">{{ s.start_time }}–{{ s.end_time }}</span>
                  <span class="att-card-subject">{{ s.subject_name || '—' }}</span>
                  <span v-if="s.teacher_name" class="att-card-teacher">{{ s.teacher_name }}</span>
                </div>
              </div>
            </div>
            <div class="att-card-actions">
              <div class="att-status-group att-status-group-mobile">
                <button
                  v-for="opt in statusOptions" :key="opt.value"
                  :class="['att-status-btn', `att-st-${opt.value}`, { active: pendingMarkStatus[s.class_session_id] === opt.value }]"
                  @click="setStatus(s.class_session_id, opt.value)"
                >{{ opt.label }}</button>
              </div>
              <button
                class="primary small att-card-submit"
                :disabled="pendingMarkSubmitting[s.class_session_id]"
                @click="submitPendingMark(s)"
              >
                {{ pendingMarkSubmitting[s.class_session_id] ? '…' : '確認' }}
              </button>
            </div>
          </div>
        </div>

        <!-- Sticky batch bar (mobile) -->
        <div v-if="selectedIds.length > 0" class="att-sticky-batch att-mobile-only">
          <span>已選 {{ selectedIds.length }} 堂</span>
          <button class="primary small" :disabled="batchSubmitting" @click="batchMarkAllPresent">
            {{ batchSubmitting ? '送出中…' : '全部到班' }}
          </button>
        </div>
      </template>

      <p v-if="pendingMarkMsg" class="att-msg" :class="pendingMarkMsgType">{{ pendingMarkMsg }}</p>

      <!-- Batch result detail -->
      <div v-if="batchResults.length > 0" class="att-batch-results">
        <div v-for="r in batchResults" :key="r.class_session_id" :class="['att-batch-result-item', r.success ? 'success' : 'error']">
          <span>{{ r.student_name || `#${r.class_session_id}` }}</span>
          <span>{{ r.success ? '✓' : ('✕ ' + (r.error || '')) }}</span>
        </div>
      </div>

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
              <option value="leave">請假</option>
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
            <option value="leave">請假</option>
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
              <th style="text-align:right">操作</th>
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
              <td>{{ record.teacher_name || record.course_teacher_name || '—' }}</td>
              <td>{{ record.campus_name || '—' }}</td>
              <td>
                <span class="status-tag" :class="statusTagClass(record.Status)">
                  {{ record.status_label }}
                </span>
              </td>
              <td style="text-align:right">
                <button v-if="!record._editing" class="ghost xs" @click="record._editing = true">修改</button>
                <div v-else class="att-inline-edit">
                  <select v-model="record._newStatus" class="att-status-select">
                    <option value="present">到班</option>
                    <option value="late">遲到</option>
                    <option value="leave">請假</option>
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
              <td colspan="7" class="empty-text">今日尚無出缺勤紀錄</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="att-refresh-hint">
        每 30 秒自動更新 · 上次更新：{{ lastRefresh }}
      </div>
    </div>

    <!-- Makeup Attendance (事後補點名) -->
    <div class="card att-checkin-card">
      <div class="att-checkin-header">
        <div class="att-section-title">待補點名（已結束節次）</div>
        <span v-if="makeupSessions.length > 0" class="att-badge att-badge-warn">{{ makeupTotal }}</span>
      </div>
      <p class="att-hint">
        {{ isTeacher
          ? '你過去尚未點名的已結束堂次。選擇日期範圍查詢，補登後會依狀態自動扣堂或請假順延。'
          : '過去尚未點名的已結束堂次。可選擇日期範圍查詢，補登後會依狀態自動扣堂或請假順延。' }}
      </p>
      <div class="att-makeup-filters">
        <div class="form-group">
          <label>起始日期</label>
          <input v-model="makeupStartDate" type="date" />
        </div>
        <div class="form-group">
          <label>結束日期</label>
          <input v-model="makeupEndDate" type="date" />
        </div>
        <div class="form-group att-submit-wrap">
          <label>&nbsp;</label>
          <button class="primary" :disabled="makeupLoading" @click="fetchMakeupSessions">查詢</button>
        </div>
      </div>
      <div v-if="!isTeacher && !branchId" class="att-empty">請先選擇分校</div>
      <div v-else-if="makeupLoading" class="att-empty">載入中…</div>
      <div v-else-if="makeupSessions.length === 0" class="att-empty">此期間沒有待補點名的已結束節次</div>
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
            <tr v-for="s in makeupSessions" :key="s.class_session_id">
              <td>{{ s.session_date }}</td>
              <td class="att-time-range">{{ s.start_time }}–{{ s.end_time }}</td>
              <td><span class="att-person-name">{{ s.student_name || '—' }}</span></td>
              <td>{{ s.subject_name || '—' }}</td>
              <td>{{ s.teacher_name || '—' }}</td>
              <td>
                <select v-model="makeupMarkStatus[s.class_session_id]" class="att-status-select">
                  <option value="present">到班</option>
                  <option value="late">遲到</option>
                  <option v-if="s.session_status === 'scheduled'" value="leave">請假</option>
                  <option value="absent">缺席</option>
                </select>
              </td>
              <td style="text-align:right">
                <button
                  class="primary small"
                  :disabled="makeupMarkSubmitting[s.class_session_id]"
                  @click="submitMakeupMark(s)"
                >
                  {{ makeupMarkSubmitting[s.class_session_id] ? '送出中…' : '補登' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="makeupHasMore" class="att-load-more">
          <button class="ghost small" :disabled="makeupLoading" @click="fetchMakeupSessions(makeupPage + 1)">載入更多</button>
        </div>
      </div>
      <p v-if="makeupMsg" class="att-msg" :class="makeupMsgType">{{ makeupMsg }}</p>
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

  <!-- Teleport to body so z-index beats the fixed bottom nav (z:10000) -->
  <Teleport to="body">
    <div v-if="confirmDialog.visible" class="att-confirm-overlay" @click.self="!confirmDialog.submitting && (confirmDialog.visible = false)">
      <div class="att-confirm-sheet">
        <div class="att-confirm-title">{{ confirmDialog.title }}</div>
        <div class="att-confirm-body">{{ confirmDialog.body }}</div>
        <div v-if="confirmDialog.error" class="att-msg error" style="margin-bottom:12px;font-size:13px">{{ confirmDialog.error }}</div>
        <div class="att-confirm-actions">
          <button class="ghost" :disabled="confirmDialog.submitting" @click="confirmDialog.visible = false">取消</button>
          <button class="primary" :disabled="confirmDialog.submitting" @click="handleConfirmSubmit">
            {{ confirmDialog.submitting ? '送出中…' : '確認送出' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, reactive, onMounted, onBeforeUnmount, watch } from 'vue';
import { supabase } from '../supabase';
import SearchableSelect from '../components/SearchableSelect.vue';

const props = defineProps({
  branchId: [String, Number],
  userRole: String,
  userId: [String, Number],
});
const isTeacher = computed(() => props.userRole === 'teacher');

const statusOptions = [
  { value: 'present', label: '到班', short: '到' },
  { value: 'late', label: '遲到', short: '遲' },
  { value: 'leave', label: '請假', short: '假' },
  { value: 'absent', label: '缺席', short: '缺' },
];
const statusLabelMap = { present: '到班', late: '遲到', leave: '請假', excused: '請假', absent: '缺席' };

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
const fetchError = ref('');

// Batch selection
const selectedIds = ref([]);
const selectedSet = computed(() => new Set(selectedIds.value));
const allSelected = computed(() => pendingSessions.value.length > 0 && selectedIds.value.length === pendingSessions.value.length);
const batchSubmitting = ref(false);
const batchResults = ref([]);

const confirmDialog = reactive({ visible: false, title: '', body: '', onConfirm: () => {}, submitting: false, error: '' });

async function handleConfirmSubmit() {
  confirmDialog.submitting = true;
  confirmDialog.error = '';
  try {
    await confirmDialog.onConfirm();
    confirmDialog.visible = false;
  } catch (e) {
    confirmDialog.error = e?.message || '送出失敗，請稍後再試';
  } finally {
    confirmDialog.submitting = false;
  }
}

const makeupSessions = ref([]);
const makeupLoading = ref(false);
const makeupMarkStatus = ref({});
const makeupMarkSubmitting = ref({});
const makeupMsg = ref('');
const makeupMsgType = ref('');
const makeupPage = ref(1);
const makeupHasMore = ref(false);
const makeupTotal = ref(0);
const makeupStartDate = ref((() => {
  const d = new Date(); d.setDate(d.getDate() - 7);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})());
const makeupEndDate = ref((() => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})());

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
    excused: list.filter(r => r.Status === 'leave' || r.Status === 'excused').length,
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

// --- Selection helpers ---
function toggleSelectAll() {
  if (allSelected.value) {
    selectedIds.value = [];
  } else {
    selectedIds.value = pendingSessions.value.map(s => s.class_session_id);
  }
}

function toggleSelect(id) {
  const idx = selectedIds.value.indexOf(id);
  if (idx >= 0) {
    selectedIds.value = selectedIds.value.filter(x => x !== id);
  } else {
    selectedIds.value = [...selectedIds.value, id];
  }
}

function setStatus(sessionId, status) {
  pendingMarkStatus.value = { ...pendingMarkStatus.value, [sessionId]: status };
}

// --- API calls ---
const fetchRecords = async () => {
  try {
    const token = await getToken();
    if (!token) return;
    const today = localTodayYmd();
    const params = new URLSearchParams({ date: today, per_page: '200' });
    if (!isTeacher.value && props.branchId) params.set('branch_id', String(props.branchId));
    const res = await fetch(`/api/v1/attendance?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const data = await res.json();
      records.value = (data.data || []).map(r => ({ ...r, _editing: false, _newStatus: r.Status, _saving: false }));
    } else if (res.status === 403) {
      fetchError.value = '無此分校的存取權限，請確認分校設定';
    } else {
      fetchError.value = `載入出缺勤記錄失敗（HTTP ${res.status}），請重新整理`;
    }
  } catch (e) {
    console.error('fetchRecords', e);
  }
  lastRefresh.value = new Date().toLocaleTimeString('zh-TW');
};

// Single API call for both todaySessionTotal and pendingSessions (fixes duplicate fetch)
const fetchPendingSessions = async () => {
  if (!isTeacher.value && !props.branchId) { pendingSessions.value = []; return; }
  pendingLoading.value = true;
  pendingMarkMsg.value = '';
  fetchError.value = '';
  try {
    const token = await getToken();
    if (!token) return;
    const today = localTodayYmd();
    const qs = new URLSearchParams({ start: today, end: today, per_page: '500' });
    if (!isTeacher.value && props.branchId) qs.set('branch_id', String(props.branchId));

    const res = await fetch(`/api/v1/class-sessions?${qs}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });

    if (!res.ok) {
      pendingSessions.value = [];
      todaySessionTotal.value = 0;
      if (res.status === 403) {
        fetchError.value = '無此分校的存取權限，請確認分校設定';
      } else {
        fetchError.value = `載入待點名堂次失敗（HTTP ${res.status}），請重新整理`;
      }
      return;
    }

    const json = await res.json().catch(() => ({}));
    const rows = Array.isArray(json?.data) ? json.data : [];

    todaySessionTotal.value = rows.filter((row) => {
      const status = String(row?.status || '').toLowerCase();
      return !['cancelled', 'leave', 'leave_adjusted'].includes(status);
    }).length;

    const pending = rows
      .filter(r => String(r?.status || '').toLowerCase() === 'scheduled')
      .map(r => ({
        class_session_id: Number(r.id || 0),
        student_id: Number(r.student_id || 0),
        student_class_id: Number(r.student_class_id || 0),
        teacher_id: Number(r.teacher_id || (isTeacher.value ? props.userId : 0) || 0),
        session_date: String(r.session_date || '').slice(0, 10),
        start_time: String(r.start_time || '').slice(0, 5),
        end_time: String(r.end_time || '').slice(0, 5),
        student_name: r.student_name || '',
        subject_name: r.subject_name || '',
        teacher_name: r.teacher_name || '',
      }))
      .filter(r => r.class_session_id > 0 && r.student_id > 0 && r.student_class_id > 0)
      .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));

    pendingSessions.value = pending;
    const next = {};
    pending.forEach(r => { next[r.class_session_id] = pendingMarkStatus.value[r.class_session_id] || 'present'; });
    pendingMarkStatus.value = next;

    // Prune selection for removed sessions
    const validIds = new Set(pending.map(s => s.class_session_id));
    selectedIds.value = selectedIds.value.filter(id => validIds.has(id));
  } catch (e) {
    console.error('fetchPendingSessions', e);
    pendingSessions.value = [];
  } finally {
    pendingLoading.value = false;
  }
};

// Single-item submit with confirmation for non-present
const submitPendingMark = async (s) => {
  const status = pendingMarkStatus.value[s.class_session_id] ?? 'present';

  if (status !== 'present' && status !== 'late') {
    confirmDialog.title = `確認${statusLabelMap[status]}`;
    confirmDialog.body = `${s.student_name}（${s.start_time}–${s.end_time} ${s.subject_name}）\n狀態：${statusLabelMap[status]}\n${status === 'leave' ? '請假將不扣堂並順延課程。' : '缺席將扣堂。'}`;
    confirmDialog.onConfirm = () => doSubmitPendingMark(s, status);
    confirmDialog.visible = true;
    return;
  }

  await doSubmitPendingMark(s, status).catch(() => {});
};

function extractApiError(err) {
  if (err.errors) {
    const first = Object.values(err.errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return err.message || '未知錯誤';
}

async function doSubmitPendingMark(s, status) {
  pendingMarkMsg.value = '';
  pendingMarkSubmitting.value = { ...pendingMarkSubmitting.value, [s.class_session_id]: true };
  try {
    const token = await getToken();
    if (!token) {
      const msg = '請先登入';
      pendingMarkMsg.value = msg; pendingMarkMsgType.value = 'error';
      throw new Error(msg);
    }
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
      const json = await res.json().catch(() => ({}));
      const label = statusLabelMap[status] || status;
      if (status === 'leave' && json.extended_end_date) {
        pendingMarkMsg.value = `已請假並順延：${s.student_name}，課程延至 ${json.extended_end_date}`;
      } else {
        pendingMarkMsg.value = `已核課：${s.student_name} ${label}`;
      }
      pendingMarkMsgType.value = 'success';
      await Promise.all([fetchPendingSessions(), fetchRecords()]);
    } else {
      const err = await res.json().catch(() => ({}));
      let msg;
      if (res.status === 428 && err.code === 'PASSWORD_CHANGE_REQUIRED') {
        msg = '請先至帳號設定變更密碼後再操作';
      } else if (res.status === 403) {
        msg = err.message === 'Forbidden' ? '無此課程的操作權限（非授課或代課老師）' : (err.message || '權限不足');
      } else {
        msg = extractApiError(err);
      }
      pendingMarkMsg.value = '核課失敗：' + msg;
      pendingMarkMsgType.value = 'error';
      throw new Error(msg);
    }
  } catch (e) {
    if (!pendingMarkMsg.value) {
      pendingMarkMsg.value = '核課失敗：網路錯誤';
      pendingMarkMsgType.value = 'error';
    }
    throw e;
  } finally {
    pendingMarkSubmitting.value = { ...pendingMarkSubmitting.value, [s.class_session_id]: false };
  }
}

// Batch mark all selected as "present" using the backend batch API
async function batchMarkAllPresent() {
  if (selectedIds.value.length === 0) return;
  batchSubmitting.value = true;
  batchResults.value = [];
  pendingMarkMsg.value = '';
  try {
    const token = await getToken();
    if (!token) { pendingMarkMsg.value = '請先登入'; pendingMarkMsgType.value = 'error'; return; }

    const sessionMap = {};
    pendingSessions.value.forEach(s => { sessionMap[s.class_session_id] = s; });

    const items = selectedIds.value.map(id => {
      const s = sessionMap[id];
      return {
        ClassSessionID: id,
        StudentID: s.student_id,
        StudentClassID: s.student_class_id,
        TeacherID: s.teacher_id || props.userId || null,
        Status: 'present',
        mark_mode: 'arrival',
      };
    });

    const res = await fetch('/api/v1/attendance/batch-mark', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ items }),
    });

    const json = await res.json().catch(() => ({}));

    if (json.results) {
      batchResults.value = json.results.map(r => ({
        ...r,
        student_name: sessionMap[r.class_session_id]?.student_name || '',
        error: r.success ? '' : (r.data?.message || '未知錯誤'),
      }));
    }

    if (json.success_count > 0) {
      pendingMarkMsg.value = `批次完成：${json.success_count} 成功` + (json.fail_count > 0 ? `，${json.fail_count} 失敗` : '');
      pendingMarkMsgType.value = json.fail_count > 0 ? 'error' : 'success';
    } else {
      pendingMarkMsg.value = '批次送出失敗';
      pendingMarkMsgType.value = 'error';
    }

    selectedIds.value = [];
    await Promise.all([fetchPendingSessions(), fetchRecords()]);
  } catch (e) {
    pendingMarkMsg.value = '批次送出失敗：網路錯誤';
    pendingMarkMsgType.value = 'error';
  } finally {
    batchSubmitting.value = false;
  }
}

const fetchMakeupSessions = async (page = 1) => {
  if (!isTeacher.value && !props.branchId) { makeupSessions.value = []; return; }
  makeupLoading.value = true;
  makeupMsg.value = '';
  try {
    const token = await getToken();
    if (!token) return;
    const qs = new URLSearchParams({
      start_date: makeupStartDate.value,
      end_date: makeupEndDate.value,
      per_page: '50',
      page: String(page),
    });
    if (props.branchId) qs.set('branch_id', String(props.branchId));
    const res = await fetch(`/api/v1/attendance/ended-sessions?${qs}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const json = await res.json().catch(() => ({}));
      const rows = (Array.isArray(json?.data) ? json.data : []).map(r => ({
        class_session_id: Number(r.class_session_id || r.id || 0),
        student_id: Number(r.student_id || 0),
        student_class_id: Number(r.student_class_id || 0),
        teacher_id: Number(r.teacher_id || 0),
        session_date: String(r.session_date || '').slice(0, 10),
        start_time: String(r.start_time || '').slice(0, 5),
        end_time: String(r.end_time || '').slice(0, 5),
        student_name: r.student_name || '',
        subject_name: r.subject_name || '',
        teacher_name: r.teacher_name || '',
        session_status: String(r.session_status || 'scheduled').toLowerCase(),
      })).filter(r => r.class_session_id > 0 && r.student_id > 0 && r.student_class_id > 0);

      if (page === 1) {
        makeupSessions.value = rows;
      } else {
        makeupSessions.value = [...makeupSessions.value, ...rows];
      }
      makeupPage.value = page;
      makeupTotal.value = json.total ?? makeupSessions.value.length;
      makeupHasMore.value = json.current_page < json.last_page;
      const next = {};
      makeupSessions.value.forEach(r => { next[r.class_session_id] = makeupMarkStatus.value[r.class_session_id] || 'present'; });
      makeupMarkStatus.value = next;
    } else if (res.status === 403) {
      makeupMsg.value = '無此分校的存取權限';
      makeupMsgType.value = 'error';
    } else {
      const err = await res.json().catch(() => ({}));
      makeupMsg.value = '查詢失敗：' + (err.message || `HTTP ${res.status}`);
      makeupMsgType.value = 'error';
    }
  } catch (e) {
    console.error('fetchMakeupSessions', e);
    makeupMsg.value = '查詢失敗：網路錯誤';
    makeupMsgType.value = 'error';
  } finally {
    makeupLoading.value = false;
  }
};

const submitMakeupMark = async (s) => {
  makeupMsg.value = '';
  const status = makeupMarkStatus.value[s.class_session_id] ?? 'present';
  makeupMarkSubmitting.value = { ...makeupMarkSubmitting.value, [s.class_session_id]: true };
  try {
    const token = await getToken();
    if (!token) { makeupMsg.value = '請先登入'; makeupMsgType.value = 'error'; return; }
    const res = await fetch('/api/v1/attendance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        StudentID: s.student_id,
        StudentClassID: s.student_class_id,
        TeacherID: s.teacher_id || null,
        ClassSessionID: s.class_session_id,
        Status: status,
      })
    });
    if (res.ok) {
      const json = await res.json().catch(() => ({}));
      const label = statusLabelMap[status] || status;
      if (status === 'leave' && json.extended_end_date) {
        makeupMsg.value = `已補登請假並順延：${s.student_name}，課程延至 ${json.extended_end_date}`;
      } else {
        makeupMsg.value = `已補登：${s.student_name} ${label}`;
      }
      makeupMsgType.value = 'success';
      makeupSessions.value = makeupSessions.value.filter(r => r.class_session_id !== s.class_session_id);
      makeupTotal.value = Math.max(0, makeupTotal.value - 1);
      fetchRecords();
    } else {
      const err = await res.json().catch(() => ({}));
      let msg;
      const staleKeywords = ['找不到可請假的堂次', '課程尚無堂次可請假', '該堂已完成請假登記', '已完成堂次不可請假', 'Attendance already recorded'];
      const errText = err.message || '';
      const isStale = (res.status === 422 || res.status === 409) && staleKeywords.some(k => errText.includes(k));
      if (res.status === 428 && err.code === 'PASSWORD_CHANGE_REQUIRED') {
        msg = '請先至帳號設定變更密碼後再操作';
      } else if (res.status === 403) {
        msg = err.message === 'Forbidden' ? '無此課程的操作權限（非授課或代課老師）' : (err.message || '權限不足');
      } else if (isStale) {
        msg = '此堂次狀態已變更，清單已自動更新';
      } else {
        msg = extractApiError(err);
      }
      makeupMsg.value = '補登失敗：' + msg;
      makeupMsgType.value = 'error';
      if (isStale) {
        fetchMakeupSessions();
      }
    }
  } catch (e) {
    if (!makeupMsg.value) {
      makeupMsg.value = '補登失敗：網路錯誤';
      makeupMsgType.value = 'error';
    }
  } finally {
    makeupMarkSubmitting.value = { ...makeupMarkSubmitting.value, [s.class_session_id]: false };
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

    if (record._newStatus === 'leave') {
      const isAttended = ['present', 'late'].includes(String(record.Status || '').toLowerCase());
      const confirmMsg = isAttended
        ? `此堂已登記到班，確定要補請假？\n（將作廢出缺勤記錄與評量記錄、沖回堂數，並補回一堂）`
        : `確定要將此堂標記為「請假」？\n系統將自動順延後續課程並補回一堂。`;
      if (!confirm(confirmMsg)) {
        record._saving = false;
        return;
      }

      let res;
      if (isAttended) {
        const sessionDate = String(record.SignInDT || '').slice(0, 10);
        res = await fetch('/api/v1/schedules/retro-leave', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify({
            student_course_id: record.StudentClassID,
            session_date: sessionDate,
            reason: '出缺勤頁補請假',
          }),
        });
      } else {
        res = await fetch('/api/v1/schedules/leave-by-session', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify({ class_session_id: record.ClassSessionID }),
        });
      }

      const json = await res.json().catch(() => ({}));
      if (res.ok) {
        record.Status = 'leave';
        record.status_label = '請假';
        record._editing = false;
        const endDate = json.extended_end_date ? `，課程延至 ${json.extended_end_date}` : '';
        pendingMarkMsg.value = isAttended
          ? `補請假完成，堂數已沖回${endDate}`
          : `已請假並順延後續課程${endDate}`;
        pendingMarkMsgType.value = 'success';
        await Promise.all([fetchPendingSessions(), fetchRecords()]);
      } else {
        alert('請假失敗：' + (json.message || '未知錯誤'));
      }
      return;
    }

    const statusMap = { present: 'attended', late: 'late', absent: 'absent' };
    const csStatus = statusMap[record._newStatus] || 'attended';
    const res = await fetch(`/api/v1/class-sessions/${record.ClassSessionID}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ status: csStatus }),
    });
    if (res.ok) {
      record.Status = record._newStatus;
      record.status_label = { present: '到班', late: '遲到', absent: '缺席' }[record._newStatus] || record._newStatus;
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
  fetchError.value = '';
  batchResults.value = [];
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
  const map = { present: 'active', late: 'pending', leave: 'excused', excused: 'excused', absent: 'rejected' };
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
  fetchMakeupSessions();
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
  fetchMakeupSessions();
  if (!isTeacher.value) {
    fetchPending();
  }
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
  background: var(--card-bg, #fff); border-radius: 12px; padding: 16px 20px; text-align: center;
  border: 1px solid rgba(148,163,184,0.18); box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.att-stat-num { font-size: 28px; font-weight: 800; color: var(--text, #334155); }
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

/* Batch action bar */
.att-batch-bar {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  margin-bottom: 14px; padding: 10px 12px;
  background: var(--primary-bg, rgba(232,121,36,0.06)); border-radius: 10px;
}
.att-check-all {
  display: flex; align-items: center; gap: 6px; cursor: pointer;
  font-size: 13px; font-weight: 600; color: var(--text);
  user-select: none;
}
.att-check-all input { width: 16px; height: 16px; accent-color: var(--primary); }
.att-batch-hint { font-size: 12px; color: var(--text-light); }

/* Status button group (replaces dropdown) */
.att-status-group {
  display: inline-flex; border-radius: 8px; overflow: hidden;
  border: 1px solid var(--border-color, #ddd);
}
.att-status-btn {
  padding: 4px 10px; font-size: 12px; font-weight: 600;
  border: none; background: var(--card-bg, #fff); color: var(--text-light);
  cursor: pointer; transition: all 0.15s; min-height: 30px;
  border-right: 1px solid var(--border-color, #ddd);
}
.att-status-btn:last-child { border-right: none; }
.att-status-btn:hover { background: rgba(0,0,0,0.04); }
.att-status-btn.active.att-st-present { background: #16a34a; color: #fff; }
.att-status-btn.active.att-st-late { background: #d97706; color: #fff; }
.att-status-btn.active.att-st-excused, .att-status-btn.active.att-st-leave { background: #1565C0; color: #fff; }
.att-status-btn.active.att-st-absent { background: #dc2626; color: #fff; }

/* Row selected highlight */
.att-row-selected { background: rgba(232,121,36,0.04); }
.att-row-selected td { background: transparent; }

/* Mobile card layout */
.att-cards { display: flex; flex-direction: column; gap: 10px; }
.att-card {
  border: 1.5px solid var(--border, rgba(148,163,184,0.2)); border-radius: 12px;
  padding: 14px; background: var(--card-bg, #fff); transition: border-color 0.15s;
}
.att-card-selected { border-color: var(--primary); background: var(--primary-bg, rgba(232,121,36,0.04)); }
.att-card-top { display: flex; align-items: flex-start; gap: 10px; }
.att-card-check { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--primary); flex-shrink: 0; }
.att-card-info { flex: 1; min-width: 0; }
.att-card-student { font-size: 15px; font-weight: 700; color: var(--text); }
.att-card-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; font-size: 13px; color: var(--text-light); }
.att-card-time { font-weight: 600; }
.att-card-actions { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
.att-status-group-mobile { flex: 1; }
.att-status-group-mobile .att-status-btn { flex: 1; padding: 8px 4px; font-size: 13px; min-height: 40px; }
.att-card-submit { min-height: 40px; min-width: 56px; }

/* Sticky batch bar (mobile) */
.att-sticky-batch {
  position: fixed; bottom: calc(56px + env(safe-area-inset-bottom, 0px)); left: 0; right: 0; z-index: 50;
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 20px;
  background: var(--card-bg, #fff); border-top: 1px solid var(--border);
  box-shadow: 0 -2px 12px rgba(0,0,0,0.08);
  font-size: 14px; font-weight: 600; color: var(--text);
  will-change: transform; transform: translateZ(0);
}
.att-sticky-batch button { min-width: 100px; }

/* Batch results */
.att-batch-results {
  margin-top: 10px; display: flex; flex-direction: column; gap: 4px;
  max-height: 200px; overflow-y: auto;
}
.att-batch-result-item {
  display: flex; justify-content: space-between; padding: 6px 10px;
  border-radius: 6px; font-size: 13px;
}
.att-batch-result-item.success { background: var(--success-bg); color: var(--success); }
.att-batch-result-item.error { background: var(--danger-bg); color: var(--danger); }

/* Confirm dialog styles moved to non-scoped block (Teleport renders outside component root) */

/* Desktop / Mobile visibility */
.att-desktop-only { display: block; }
.att-mobile-only { display: none; }

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
.status-tag.excused, .status-tag.leave { background: #E3F2FD; color: #1565C0; }
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

/* Makeup attendance filters */
.att-makeup-filters {
  display: flex; gap: 12px; align-items: end; flex-wrap: wrap; margin-bottom: 16px;
}
.att-makeup-filters .form-group { min-width: 140px; }
.att-badge-warn { background: #d97706; }
.att-load-more { text-align: center; padding: 12px 0; }

/* Pending card */
.att-pending-card { padding: 20px 24px; border-left: 4px solid var(--warning); }

/* ──────── Responsive ──────── */
@media (max-width: 768px) {
  .att-stats { grid-template-columns: repeat(2, 1fr); }
  .att-manual-grid { grid-template-columns: 1fr; }
  .att-records-header { flex-direction: column; align-items: stretch; }
  .att-records-controls { flex-direction: column; }
  .att-search-input, .att-filter-select { width: 100%; }
  .att-header { flex-direction: column; }
  .att-header-btns button { width: 100%; }

  .att-desktop-only { display: none; }
  .att-mobile-only { display: flex; }
  .att-sticky-batch { display: flex; }

}

@media (max-width: 480px) {
  .att-stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .att-stat-card { padding: 12px; }
  .att-stat-num { font-size: 22px; }
  .att-checkin-card, .att-records-card, .att-pending-card { padding: 16px; }
  .att-card { padding: 12px; }
  .att-status-group-mobile .att-status-btn { padding: 8px 2px; font-size: 12px; }
}
</style>

<!-- Non-scoped: Teleport'd confirm dialog renders outside component root -->
<style>
.att-confirm-overlay {
  position: fixed; inset: 0; z-index: 10100; background: rgba(0,0,0,0.4);
  display: flex; align-items: flex-end; justify-content: center;
}
.att-confirm-sheet {
  background: var(--card-bg, #fff); border-radius: 16px 16px 0 0;
  padding: 24px; padding-bottom: calc(24px + env(safe-area-inset-bottom, 0px));
  width: 100%; max-width: 480px;
  box-shadow: 0 -4px 24px rgba(0,0,0,0.12);
}
.att-confirm-title { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 12px; }
.att-confirm-body { font-size: 14px; color: var(--text-light); white-space: pre-line; margin-bottom: 20px; line-height: 1.6; }
.att-confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
.att-confirm-actions button { min-width: 80px; min-height: 40px; }

@media (max-width: 768px) {
  .att-confirm-overlay { align-items: flex-end; }
}
@media (min-width: 769px) {
  .att-confirm-overlay { align-items: center; }
  .att-confirm-sheet { border-radius: 16px; }
}
</style>
