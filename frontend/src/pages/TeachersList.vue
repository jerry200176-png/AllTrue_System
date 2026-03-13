<template>
  <div>
    <HelpGuide
      title="老師管理 — 使用說明"
      :items="[
        '左側切換分校後，此頁只顯示<strong>主分校為本分校</strong>或<strong>跨校支援含本分校</strong>的老師。',
        '「正式老師」：可被排課；「待審核」：新註冊老師，核准後才會出現在排課名單。',
        '點「編輯」可改姓名、主分校、跨校支援、電話、Line、可授課科目、狀態、RFID。',
        '「+ 新增老師」：由主任建立老師帳號，填寫 Email、密碼、主分校與跨校支援。'
      ]"
      tip="老師須為正式（Active）狀態才能被排課；跨校支援僅影響「在哪些分校可被選為授課老師」。"
    />
  <div class="card">
    <div class="header-actions">
      <h2>👨‍🏫 老師管理</h2>
      <div class="tabs">
          <button :class="{ active: tab === 'active' }" @click="tab = 'active'">正式老師 (Active)</button>
          <button :class="{ active: tab === 'pending' }" @click="tab = 'pending'">待審核 (Pending) <span v-if="pendingCount > 0" class="badge">{{ pendingCount }}</span></button>
      </div>
      <button class="primary" @click="showAddModal = true">+ 新增老師</button>
    </div>

    <div class="filter-row">
      <div>
        <label>搜尋（姓名／電話）</label>
        <input v-model="searchQ" placeholder="輸入姓名或電話..." @input="debouncedLoad" />
      </div>
      <div>
        <label>狀態</label>
        <select v-model="filterStatus" @change="loadTeachers">
          <option value="">全部</option>
          <option value="active">Active</option>
          <option value="pending">Pending</option>
          <option value="suspended">Suspended</option>
        </select>
      </div>
      <div>
        <label>科目</label>
        <select v-model="filterSubjectId" @change="loadTeachers">
          <option value="">全部</option>
          <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="hint">Loading...</div>

    <table class="teacher-table" v-else>
      <thead>
        <tr>
          <th>姓名</th>
          <th>主分校</th>
          <th>跨校支援</th>
          <th>電話</th>
          <th>可授課科目</th>
          <th>RFID</th>
          <th>狀態</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="teacher in filteredTeachers" :key="teacher.id">
          <td>{{ teacher.username }}</td>
          <td>{{ getBranchName(teacher.branch_id) }}</td>
          <td>
            <template v-if="getMultiBranchList(teacher).length > 0">
              <span v-for="bName in getMultiBranchList(teacher)" :key="bName" class="branch-tag">{{ bName }}</span>
            </template>
            <span v-else class="no-support">無</span>
          </td>
          <td>{{ teacher.phone || '—' }}</td>
          <td>
            <template v-if="(teacher.subject_names || []).length > 0">
              <span v-for="n in teacher.subject_names" :key="n" class="branch-tag">{{ n }}</span>
            </template>
            <span v-else class="no-support">—</span>
          </td>
          <td>
            <span v-if="teacher.rfid" class="rfid-tag">{{ teacher.rfid }}</span>
            <button class="small ghost" @click="editTeacher(teacher)">{{ teacher.rfid ? '重新綁定' : '綁定' }}</button>
          </td>
          <td>
             <span :class="['status-tag', teacher.status]">{{ teacher.status }}</span>
          </td>
          <td>
            <button class="small" @click="editTeacher(teacher)">編輯</button>
            <button v-if="teacher.status === 'pending'" class="small primary" @click="approveTeacher(teacher)">核准 (Approve)</button>
            <button class="small ghost" @click="navigateToSchedule(teacher.id, 'course-mgmt')">課程</button>
            <button class="small ghost" @click="navigateToSchedule(teacher.id, 'calendar')">日曆</button>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Modal -->
    <div v-if="showModal || showAddModal" class="modal-overlay">
      <div class="modal">
        <h3>{{ isEditing ? '編輯老師' : '新增老師' }}</h3>
        
        <div v-if="!isEditing" class="form-group">
          <label>Email（登入帳號）</label>
          <input v-model="form.email" type="email" placeholder="teacher@example.com" />
        </div>

        <div v-if="!isEditing" class="form-group">
          <label>密碼</label>
          <input v-model="form.password" type="text" placeholder="預設密碼" />
        </div>

        <div class="form-group">
          <label>姓名</label>
          <input v-model="form.username" />
        </div>

        <div class="form-group">
          <label>電話</label>
          <input v-model="form.phone" type="text" placeholder="選填" />
        </div>
        <div class="form-group">
          <label>Line ID</label>
          <input v-model="form.line_id" type="text" placeholder="選填" />
        </div>
        <div class="form-group">
          <label>主分校 (Home Branch)</label>
          <select v-model="form.branch_id">
             <option v-for="b in BRANCHES" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </div>
        
        <div class="form-group">
           <label>跨校支援 (Multi-Branch Access)</label>
           <p class="hint">可勾選「除主分校以外」的其他分校，主分校不需重複勾選。</p>
           <div class="branch-chip-group">
             <label
               v-for="b in BRANCHES"
               :key="'multi-'+b.id"
               :class="['branch-chip', { selected: form.multi_branches.includes(b.id), disabled: form.branch_id === b.id }]"
             >
               <input type="checkbox" :value="b.id" v-model="form.multi_branches" class="chip-checkbox" :disabled="form.branch_id === b.id" />
               <span class="chip-check">{{ form.multi_branches.includes(b.id) ? '✓' : (form.branch_id === b.id ? '主' : '') }}</span>
               {{ b.name.split('(')[0].trim() }}{{ form.branch_id === b.id ? '（主分校）' : '' }}
             </label>
           </div>
        </div>

        <div class="form-group">
          <label>RFID 卡片</label>
          <div class="rfid-bind-row">
            <input v-model="form.rfid" readonly placeholder="刷卡後點「綁定卡片」" />
            <button type="button" class="small" @click="bindRfidFromTemp">{{ form.rfid ? '重新綁定卡片' : '綁定卡片' }}</button>
          </div>
        </div>

        <div class="form-group">
          <label>可授課科目</label>
          <div class="branch-chip-group">
            <label
              v-for="s in subjects"
              :key="'subj-'+s.id"
              :class="['branch-chip', { selected: form.subject_ids.includes(s.id) }]"
            >
              <input type="checkbox" :value="s.id" v-model="form.subject_ids" class="chip-checkbox" />
              <span class="chip-check">{{ form.subject_ids.includes(s.id) ? '✓' : '' }}</span>
              {{ s.name }}
            </label>
          </div>
        </div>
        <div class="form-group">
          <label>狀態</label>
          <select v-model="form.status">
              <option value="active">Active (正式)</option>
              <option value="pending">Pending (待審核)</option>
              <option value="suspended">Suspended (停用)</option>
          </select>
        </div>

        <div v-if="formError" class="form-error">{{ formError }}</div>

        <div class="actions">
          <button @click="closeModal">取消</button>
          <button class="primary" @click="submitForm">儲存</button>
        </div>
      </div>
    </div>
  </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { supabase } from '../supabase';
import { branches as BRANCHES, getBranchName as _getBranchName } from '../lib/useBranches';
import HelpGuide from '../components/HelpGuide.vue';

const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';

const props = defineProps({ branchId: [String, Number] });
const emit = defineEmits(['navigate-to-schedule']);

const teachers = ref([]);
const subjects = ref([]);
const loading = ref(false);
const showAddModal = ref(false);
const editingId = ref(null);
const tab = ref('active');
const searchQ = ref('');
const filterStatus = ref('');
const filterSubjectId = ref('');

const form = ref({
  username: '',
  email: '',
  password: 'teacher123',
  phone: '',
  line_id: '',
  branch_id: props.branchId || (BRANCHES.value.length > 0 ? BRANCHES.value[0].id : null),
  multi_branches: [],
  subject_ids: [],
  status: 'active',
  rfid: ''
});

const formError = ref('');
const isEditing = computed(() => !!editingId.value);
const showModal = computed(() => !!editingId.value);

const filteredTeachers = computed(() => {
    if (tab.value === 'active') return teachers.value.filter(t => t.status === 'active');
    return teachers.value.filter(t => t.status === 'pending');
});

const pendingCount = computed(() => teachers.value.filter(t => t.status === 'pending').length);

const getBranchName = (bid) => _getBranchName(bid);

function getMultiBranchList(teacher) {
  const ids = teacher.branch_ids || [];
  const mainId = teacher.branch_id != null ? Number(teacher.branch_id) : null;
  const others = mainId != null ? ids.filter(b => Number(b) !== mainId) : ids;
  return others.map(bid => getBranchName(bid).split('(')[0].trim());
}

async function getAuthHeaders() {
  const { data: { session } } = await supabase.auth.getSession();
  const token = session?.access_token;
  const h = { 'Content-Type': 'application/json' };
  if (token) h['Authorization'] = `Bearer ${token}`;
  return h;
}

let loadTimeout = null;
function debouncedLoad() {
  if (loadTimeout) clearTimeout(loadTimeout);
  loadTimeout = setTimeout(loadTeachers, 300);
}

const loadTeachers = async () => {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    params.set('per_page', 'all');
    if (props.branchId != null) params.set('branch_id', String(props.branchId));
    if (searchQ.value.trim()) params.set('q', searchQ.value.trim());
    if (filterStatus.value) params.set('status', filterStatus.value);
    if (filterSubjectId.value) params.set('subject_id', filterSubjectId.value);
    const headers = await getAuthHeaders();
    const res = await fetch(`${API_BASE}/teachers?${params}`, { headers });
    const data = await res.json().catch(() => ({}));
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    if (!res.ok) {
      alert('載入老師資料失敗：' + (data?.message || res.statusText));
      return;
    }
    teachers.value = list;
  } catch (err) {
    console.error('loadTeachers error:', err);
    alert('載入老師資料時發生錯誤');
  } finally {
    loading.value = false;
  }
};

async function loadSubjects() {
  try {
    const headers = await getAuthHeaders();
    const res = await fetch(`${API_BASE}/subjects`, { headers });
    const data = await res.json().catch(() => []);
    subjects.value = Array.isArray(data) ? data : [];
  } catch (_) {
    subjects.value = [];
  }
}

const editTeacher = (teacher) => {
  editingId.value = teacher.id;
  const mainId = teacher.branch_id != null ? Number(teacher.branch_id) : null;
  const branchIds = teacher.branch_ids || [];
  const multiOnly = mainId != null ? branchIds.filter(b => Number(b) !== mainId) : branchIds;
  form.value = {
    username: teacher.username,
    phone: teacher.phone || '',
    line_id: teacher.line_id || '',
    branch_id: teacher.branch_id,
    multi_branches: multiOnly,
    subject_ids: [...(teacher.subject_ids || [])],
    status: teacher.status || 'active',
    rfid: teacher.rfid || ''
  };
};

const approveTeacher = async (teacher) => {
  if (!confirm('確認核准此老師？核准後將成為正式老師，可進行排課。')) return;
  try {
    const headers = await getAuthHeaders();
    const res = await fetch(`${API_BASE}/profiles/${teacher.id}`, {
      method: 'PUT',
      headers,
      body: JSON.stringify({ status: 'active' })
    });
    if (!res.ok) {
      const j = await res.json().catch(() => ({}));
      alert('核准失敗：' + (j?.message || res.statusText));
      return;
    }
    tab.value = 'active';
    await loadTeachers();
  } catch (e) {
    alert('核准失敗，請稍後再試');
  }
};

function navigateToSchedule(teacherId, target) {
  emit('navigate-to-schedule', { teacherId, target: target || 'course-mgmt' });
}

const closeModal = () => {
  showAddModal.value = false;
  editingId.value = null;
  formError.value = '';
  const branchId = props.branchId || (BRANCHES.value.length > 0 ? BRANCHES.value[0].id : null);
  form.value = {
    username: '', email: '', password: 'teacher123', phone: '', line_id: '',
    branch_id: branchId, multi_branches: [], subject_ids: [], status: 'active', rfid: ''
  };
};

const bindRfidFromTemp = async () => {
  if (!props.branchId) { formError.value = '請先選擇分校'; return; }
  try {
    const headers = await getAuthHeaders();
    const res = await fetch(`${API_BASE}/temp-rfid?campus_id=${props.branchId}`, { headers });
    const json = await res.json();
    if (json?.data?.rfid) {
      form.value.rfid = json.data.rfid;
      formError.value = '';
    } else {
      formError.value = '暫無刷卡資料，請先刷卡後 5 分鐘內點擊綁定';
    }
  } catch (e) {
    formError.value = '取得暫存 RFID 失敗';
  }
};

const submitForm = async () => {
  formError.value = '';
  if (!form.value.username) { formError.value = '請輸入姓名'; return; }
  const headers = await getAuthHeaders();

  try {
    if (isEditing.value) {
      const body = {
        username: form.value.username,
        branch_id: form.value.branch_id,
        multi_branches: form.value.multi_branches || [],
        status: form.value.status,
        phone: form.value.phone || null,
        line_id: form.value.line_id || null,
        rfid: form.value.rfid || null,
        subject_ids: form.value.subject_ids || []
      };
      const res = await fetch(`${API_BASE}/profiles/${editingId.value}`, {
        method: 'PUT',
        headers,
        body: JSON.stringify(body)
      });
      const j = await res.json().catch(() => ({}));
      if (!res.ok) {
        formError.value = j?.message || '更新失敗';
        return;
      }
    } else {
      if (!form.value.email) { formError.value = '請輸入 Email'; return; }
      if (!form.value.password) { formError.value = '請輸入密碼'; return; }
      const body = {
        name: form.value.username,
        email: form.value.email,
        password: form.value.password,
        role: 'teacher',
        campus_id: form.value.branch_id,
        branch_id: form.value.branch_id,
        multi_branches: form.value.multi_branches || [],
        status: form.value.status,
        phone: form.value.phone || null,
        line_id: form.value.line_id || null,
        subject_ids: form.value.subject_ids || []
      };
      const res = await fetch(`${API_BASE}/profiles`, {
        method: 'POST',
        headers,
        body: JSON.stringify(body)
      });
      const j = await res.json().catch(() => ({}));
      if (!res.ok) {
        formError.value = j?.message || '新增失敗';
        return;
      }
    }
    closeModal();
    await loadTeachers();
  } catch (err) {
    formError.value = err?.message || '儲存失敗，請稍後再試';
  }
};

watch(() => props.branchId, loadTeachers);
onMounted(() => {
  loadTeachers();
  loadSubjects();
});
</script>

<style scoped>
.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  align-items: flex-end;
  margin-bottom: 16px;
}
.filter-row label {
  display: block;
  font-size: 12px;
  color: #666;
  margin-bottom: 4px;
}
.filter-row input, .filter-row select {
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  min-width: 140px;
}

.tabs {
    display: flex;
    gap: 8px;
}
.tabs button {
    background: transparent;
    border: none;
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 2px solid transparent;
}
.tabs button.active {
    border-bottom: 2px solid #FFA726;
    color: #E65100;
    font-weight: bold;
}

.teacher-table {
  width: 100%;
  border-collapse: collapse;
}

.teacher-table th, .teacher-table td {
  padding: 12px;
  border-bottom: 1px solid #eee;
  text-align: left;
}

.status-tag {
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.8em;
}
.status-tag.active { background: #e8f5e9; color: #2e7d32; }
.status-tag.pending { background: #fff3e0; color: #ef6c00; }
.status-tag.suspended { background: #ffebee; color: #c62828; }

.badge {
    background: #d32f2f;
    color: white;
    border-radius: 50%;
    padding: 2px 6px;
    font-size: 0.75em;
}

/* Modal styles reused */
.modal-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}
.modal {
  background: #fff;
  padding: 24px;
  border-radius: 8px;
  width: 450px;
  max-height: 90vh;
  overflow-y: auto;
}
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: bold; }
.form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
.checkbox-group { display: flex; flex-wrap: wrap; gap: 8px; }

.branch-chip-group {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.branch-chip {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 8px 14px;
  border-radius: 20px;
  border: 2px solid #E0E0E0;
  background: #FAFAFA;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: #616161;
  transition: all 0.2s;
  user-select: none;
}

.branch-chip:hover {
  border-color: #90CAF9;
  background: #E3F2FD;
}

.branch-chip.selected {
  border-color: #1565C0;
  background: #E3F2FD;
  color: #1565C0;
  font-weight: 700;
}
.branch-chip.disabled {
  opacity: 0.7;
  cursor: default;
  border-color: #BDBDBD;
  background: #EEEEEE;
  color: #757575;
}
.branch-chip.disabled:hover { border-color: #BDBDBD; background: #EEEEEE; }
.form-group .hint { font-size: 12px; color: #78909c; margin-bottom: 8px; font-weight: normal; }

.rfid-bind-row {
  display: flex;
  gap: 8px;
  align-items: center;
}
.rfid-bind-row input {
  flex: 1;
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
}
.rfid-bind-row input[readonly] {
  background: #f5f5f5;
  color: #333;
  cursor: default;
}

.rfid-tag {
  font-size: 0.8em;
  font-family: monospace;
  color: var(--primary);
}

.chip-checkbox {
  display: none;
}

.chip-check {
  font-size: 12px;
  width: 14px;
  color: #1565C0;
}
.actions { display: flex; justify-content: flex-end; gap: 8px; }

.form-error {
  background: #FFEBEE;
  color: #C62828;
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 13px;
  margin-bottom: 12px;
}

.branch-tag {
  display: inline-block;
  background: #E3F2FD;
  color: #1565C0;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 0.8em;
  margin: 2px 4px 2px 0;
  white-space: nowrap;
}

.no-support {
  color: #999;
  font-size: 0.85em;
}
</style>
