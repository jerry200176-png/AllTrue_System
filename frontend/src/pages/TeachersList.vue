<template>
  <div class="teachers-page">
  <div class="card teachers-card">
    <div class="header-actions" data-guide="teachers-header">
      <div class="title-group">
        <h2>老師管理</h2>
        <p class="title-sub">管理老師資料、分校配置與登入帳號操作</p>
      </div>
      <div class="tabs">
          <button :class="{ active: tab === 'active' }" @click="tab = 'active'">正式老師</button>
          <button :class="{ active: tab === 'pending' }" @click="tab = 'pending'">待審核 <span v-if="pendingCount > 0" class="badge">{{ pendingCount }}</span></button>
          <button :class="{ active: tab === 'suspended' }" @click="tab = 'suspended'">停用 <span v-if="suspendedCount > 0" class="badge">{{ suspendedCount }}</span></button>
      </div>
      <div class="header-btns">
        <button class="ghost" @click="openBulkModal">批次新增老師</button>
        <button class="primary" @click="showAddModal = true">+ 新增老師</button>
      </div>
    </div>

    <div class="filter-row" data-guide="teachers-filters">
      <div class="filter-item filter-item-search">
        <label>搜尋（姓名／電話）</label>
        <input v-model="searchQ" placeholder="輸入姓名或電話..." @input="debouncedLoad" />
      </div>
      <div class="filter-item">
        <label>狀態</label>
        <select v-model="filterStatus" @change="loadTeachers">
          <option value="">全部</option>
          <option value="active">在職</option>
          <option value="pending">待審核</option>
          <option value="suspended">停用</option>
        </select>
      </div>
      <div class="filter-item">
        <label>科目</label>
        <select v-model="filterSubjectId">
          <option value="">全部</option>
          <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </div>
    </div>

    <div v-if="teachers.length > 0" class="teacher-chips-row" role="group" aria-label="快速篩選老師（可多選）">
      <span class="teacher-chips-label">老師</span>
      <div class="teacher-chips-scroll">
        <button
          v-for="t in chipTeacherOptions"
          :key="t.id"
          type="button"
          :aria-pressed="selectedTeacherIdSet.has(String(t.id))"
          :class="['teacher-chip', { active: selectedTeacherIdSet.has(String(t.id)) }]"
          :style="selectedTeacherIdSet.has(String(t.id)) ? { background: teacherAvatarColor(t.id), borderColor: teacherAvatarColor(t.id), color: 'var(--ds-on-primary)' } : {}"
          @click="toggleTeacherChip(t.id)"
        >{{ t.name }}</button>
      </div>
      <button v-if="selectedTeacherIds.length > 0" type="button" class="teacher-chip-clear" @click="selectedTeacherIds = []">全清除</button>
    </div>

    <div class="teacher-summary">
      <div class="summary-card">
        <div class="summary-label">全部老師</div>
        <div class="summary-value">{{ teachers.length }}</div>
      </div>
      <div class="summary-card">
        <div class="summary-label">正式老師</div>
        <div class="summary-value active">{{ activeTeachersCount }}</div>
      </div>
      <div class="summary-card">
        <div class="summary-label">待審核</div>
        <div class="summary-value pending">{{ pendingCount }}</div>
      </div>
      <div class="summary-card">
        <div class="summary-label">目前列表</div>
        <div class="summary-value">{{ filteredTeachers.length }}</div>
      </div>
    </div>

    <div v-if="loading" class="hint">Loading...</div>
    <div v-else-if="filteredTeachers.length === 0" class="empty-state">
      目前沒有符合條件的老師資料。
    </div>

    <div
      v-else
      class="teacher-card-grid"
      data-guide="teachers-cards"
    >
      <article
        v-for="teacher in filteredTeachers"
        :key="'tc-' + teacher.id"
        class="teacher-profile-card"
      >
        <header class="tpc-head">
          <div
            class="tpc-avatar"
            :style="{ background: teacherAvatarColor(teacher.id) }"
            aria-hidden="true"
          >
            {{ teacherInitial(teacher) }}
          </div>
          <div class="tpc-head-text">
            <h3 class="tpc-name">{{ teacher.username }}</h3>
            <p class="tpc-account mono">{{ teacher.account || teacher.email || '—' }}</p>
          </div>
          <span :class="['status-tag', 'tpc-status', teacher.status]">{{ teacherStatusLabel(teacher.status) }}</span>
          <span v-if="teacher.employment_type === 'part_time'" class="status-tag tpc-parttime">兼職</span>
        </header>

        <div class="tpc-body">
          <div class="tpc-row">
            <span class="tpc-label">電話</span>
            <span class="tpc-value">{{ teacher.phone || '—' }}</span>
          </div>
          <div class="tpc-row">
            <span class="tpc-label">主分校</span>
            <span class="tpc-value">{{ getBranchName(teacher.branch_id) }}</span>
          </div>
          <div class="tpc-row tpc-row-tags">
            <span class="tpc-label">跨校</span>
            <div class="tpc-tags">
              <template v-if="getMultiBranchList(teacher).length > 0">
                <span v-for="bName in getMultiBranchList(teacher)" :key="bName" class="branch-tag">{{ bName }}</span>
              </template>
              <span v-else class="no-support">無</span>
            </div>
          </div>
          <div class="tpc-row tpc-row-tags">
            <span class="tpc-label">科目</span>
            <div class="tpc-tags">
              <template v-if="teacherSubjectLabels(teacher).length > 0">
                <span v-for="n in teacherSubjectLabels(teacher)" :key="n" class="branch-tag">{{ n }}</span>
              </template>
              <span v-else class="no-support">—</span>
            </div>
          </div>
          <div class="tpc-row tpc-scope-summary">
            <span class="tpc-label">學段</span>
            <div class="tpc-tags tpc-scope-chips">
              <template v-if="teacherScopeChips(teacher).length > 0">
                <span
                  v-for="chip in teacherScopeChips(teacher)"
                  :key="chip.subjectId + chip.levelKey"
                  class="scope-chip"
                  :style="scopeSubjectStyle(chip.subjectId)"
                >{{ chip.subject }}・{{ chip.label }}</span>
              </template>
              <span v-else class="no-support">未設定</span>
            </div>
          </div>
          <div class="tpc-row tpc-rfid-block">
            <span class="tpc-label">RFID</span>
            <div class="tpc-rfid-inner">
              <div v-if="teacher.rfid_by_branch && Object.keys(teacher.rfid_by_branch).length" class="rfid-multi-cell">
                <div v-for="(v, bid) in teacher.rfid_by_branch" :key="'crfid-' + teacher.id + '-' + bid" class="rfid-line">
                  <span class="rfid-branch-mini">{{ getBranchName(Number(bid)).split('(')[0].trim() }}</span>
                  <span class="rfid-tag">{{ v }}</span>
                </div>
              </div>
              <template v-else>
                <span v-if="teacher.rfid" class="rfid-tag">{{ teacher.rfid }}</span>
                <span v-else class="no-support">—</span>
              </template>
              <button type="button" class="small ghost tpc-rfid-btn" @click="editTeacher(teacher)">
                {{ teacherHasAnyRfid(teacher) ? '重新綁定' : '綁定' }}
              </button>
            </div>
          </div>
          <div class="tpc-row">
            <span class="tpc-label">臨時密碼</span>
            <span class="tpc-value mono tpc-temp-pw">{{ temporaryPasswords[teacher.id] || '重設後顯示' }}</span>
          </div>
        </div>

        <footer class="tpc-actions">
          <button type="button" class="small" @click="editTeacher(teacher)">編輯</button>
          <button type="button" class="small warning" @click="resetTeacherPassword(teacher)">重設密碼</button>
          <button v-if="teacher.status === 'pending'" type="button" class="small primary" @click="approveTeacher(teacher)">核准</button>
          <button type="button" class="small ghost" @click="navigateToSchedule(teacher.id, 'course-mgmt')">課程</button>
          <button type="button" class="small ghost" @click="navigateToSchedule(teacher.id, 'calendar')">日曆</button>
          <button type="button" class="small danger" @click="deleteTeacher(teacher)">刪除</button>
        </footer>
      </article>
    </div>


    <!-- Modal -->
    <div v-if="showModal || showAddModal" class="modal-overlay">
      <div class="modal">
        <h3>{{ isEditing ? '編輯老師' : '新增老師' }}</h3>
        
        <div class="form-group">
          <label>登入帳號</label>
          <input v-model="form.account" type="text" :placeholder="isEditing ? '請輸入登入帳號' : '例如：teacher001'" />
        </div>

        <div v-if="!isEditing" class="form-group">
          <label>密碼</label>
          <input v-model="form.password" type="text" placeholder="至少 8 個字元" />
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
             <option v-for="b in formBranchOptions" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </div>
        
        <div class="form-group">
           <label>跨校支援 (Multi-Branch Access)</label>
           <p class="hint">可勾選「除主分校以外」的其他分校，主分校不需重複勾選。</p>
           <div class="branch-chip-group">
             <label
               v-for="b in formBranchOptions"
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
          <label>RFID 卡片（依分校）</label>
          <p class="hint">老師所屬的每一間分校可分別綁定不同卡片：請到該分校刷卡後，點該列的「讀取暫存」。</p>
          <div v-for="bid in formTeacherCampusIds" :key="'form-rfid-' + bid" class="rfid-branch-block">
            <div class="rfid-branch-title">{{ getBranchName(bid).split('(')[0].trim() }}</div>
            <div class="rfid-bind-row">
              <input
                v-model="form.rfid_by_branch[String(bid)]"
                readonly
                placeholder="刷卡後點「讀取暫存」"
              />
              <button type="button" class="small" @click="bindRfidFromTempForCampus(bid)">
                {{ form.rfid_by_branch[String(bid)] ? '重新讀取' : '讀取暫存' }}
              </button>
            </div>
          </div>
          <p v-if="formTeacherCampusIds.length === 0" class="hint">請先選擇主分校（必要時加選跨校支援）。</p>
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
          <p v-if="subjects.length === 0" class="hint">目前沒有可選科目，請先確認科目資料已建立。</p>
        </div>

        <div v-if="selectedSubjectsForForm.length > 0" class="form-group">
          <label>授課學段（依科目勾選國小／國中／高中）</label>
          <p class="hint">與「帳號設定」內邏輯相同；影響排課／入班時的學段檢核（<code>teacher_subject_levels</code>）。</p>
          <div class="scope-table-wrap">
            <table class="scope-table">
              <thead>
                <tr>
                  <th>科目</th>
                  <th v-for="lv in LEVEL_OPTIONS" :key="'hdr-' + lv.value">{{ lv.label }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="subject in selectedSubjectsForForm" :key="'scope-' + subject.id">
                  <td>{{ subject.name }}</td>
                  <td v-for="lv in LEVEL_OPTIONS" :key="'scope-' + subject.id + '-' + lv.value">
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
        </div>

        <div class="form-group">
          <label>聘用類型</label>
          <select v-model="form.employment_type">
            <option value="full_time">正職</option>
            <option value="part_time">兼職</option>
          </select>
        </div>

        <div class="form-group">
          <label>狀態</label>
          <select v-model="form.status">
              <option value="active">在職</option>
              <option value="pending">待審核</option>
              <option value="suspended">停用</option>
          </select>
        </div>

        <div v-if="formError" class="form-error">{{ formError }}</div>

        <div class="actions">
          <button @click="closeModal">取消</button>
          <button class="primary" @click="submitForm">儲存</button>
        </div>
      </div>
    </div>

    <div v-if="showBulkModal" class="modal-overlay">
      <div class="modal modal-wide">
        <h3>批次新增老師</h3>
        <p class="hint">貼上資料或 CSV 內容（建議欄位：account,name,phone,branch_id,subject_ids,multi_branches,line_id,status）。未提供 branch_id 會使用下方預設主分校。</p>

        <div class="bulk-row">
          <div class="form-group">
            <label>預設主分校</label>
            <select v-model="bulkDefaultBranchId">
              <option v-for="b in formBranchOptions" :key="'bulk-default-'+b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>預設狀態</label>
            <select v-model="bulkDefaultStatus">
              <option value="active">在職</option>
              <option value="pending">待審核</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>批次資料</label>
          <textarea
            v-model="bulkInputText"
            rows="10"
            placeholder="account,name,phone,branch_id,subject_ids,multi_branches,line_id,status&#10;teacher001,王老師,0912345678,1,數學|英文,2|3,line001,active"
          />
          <div class="hint">subject_ids 支援科目名稱（國文/英文/數學...）或 id；multi_branches 用 |、,、; 分隔。</div>
        </div>

        <div v-if="bulkParseSummary.rowCount > 0" class="bulk-preview">
          <div class="bulk-preview-title">預覽：{{ bulkParseSummary.rowCount }} 筆</div>
          <table class="teacher-table compact">
            <thead>
              <tr>
                <th>#</th>
                <th>帳號</th>
                <th>姓名</th>
                <th>主分校</th>
                <th>科目</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in bulkParseSummary.previewRows" :key="'bulk-preview-'+item.row">
                <td>{{ item.row }}</td>
                <td>{{ item.account || '—' }}</td>
                <td>{{ item.name || '—' }}</td>
                <td>{{ getBranchName(item.branch_id) }}</td>
                <td>{{ item.subject_ids.length > 0 ? item.subject_ids.join(',') : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="bulkParseSummary.errors.length > 0" class="form-error">
          <div v-for="(msg, idx) in bulkParseSummary.errors.slice(0, 5)" :key="'bulk-parse-err-'+idx">{{ msg }}</div>
        </div>

        <div v-if="bulkError" class="form-error">{{ bulkError }}</div>

        <div v-if="bulkResult" class="bulk-result">
          <div class="bulk-result-header">
            <strong>結果：{{ bulkResult.summary?.created || 0 }} 成功 / {{ bulkResult.summary?.failed || 0 }} 失敗</strong>
            <div class="header-btns">
              <button class="small" @click="copyBulkCredentials" :disabled="!bulkResult.created?.length">複製帳密</button>
              <button class="small" @click="downloadBulkCredentialsCsv" :disabled="!bulkResult.created?.length">下載 CSV</button>
              <button class="small ghost" @click="refillBulkWithFailedRows" :disabled="!bulkResult.failed?.length">僅保留失敗筆</button>
            </div>
          </div>

          <table v-if="bulkResult.created?.length" class="teacher-table compact">
            <thead>
              <tr>
                <th>#</th>
                <th>帳號</th>
                <th>姓名</th>
                <th>初始密碼</th>
                <th>主分校</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in bulkResult.created" :key="'bulk-created-'+item.row">
                <td>{{ item.row }}</td>
                <td>{{ item.account }}</td>
                <td>{{ item.name || '—' }}</td>
                <td class="mono">{{ item.initial_password }}</td>
                <td>{{ getBranchName(item.branch_id) }}</td>
              </tr>
            </tbody>
          </table>

          <table v-if="bulkResult.failed?.length" class="teacher-table compact">
            <thead>
              <tr>
                <th>#</th>
                <th>帳號</th>
                <th>錯誤</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in bulkResult.failed" :key="'bulk-failed-'+item.row">
                <td>{{ item.row }}</td>
                <td>{{ item.account || '—' }}</td>
                <td>{{ item.error || '建立失敗' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="actions">
          <button @click="closeBulkModal">關閉</button>
          <button class="primary" @click="submitBulkTeachers" :disabled="bulkSubmitting">
            {{ bulkSubmitting ? '送出中...' : '送出批次建立' }}
          </button>
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

const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';

const props = defineProps({ branchId: [String, Number] });
const emit = defineEmits(['navigate-to-schedule']);

const teachers = ref([]);
const subjects = ref([]);
const allBranchOptions = ref([]);
const loading = ref(false);
const showAddModal = ref(false);
const showBulkModal = ref(false);
const editingId = ref(null);
const tab = ref('active');
const searchQ = ref('');
const filterStatus = ref('');
const filterSubjectId = ref('');
const selectedTeacherIds = ref([]);
const selectedTeacherIdSet = computed(() => new Set(selectedTeacherIds.value.map(String)));

const chipTeacherOptions = computed(() =>
  teachers.value
    .filter(t => t.status === 'active')
    .map(t => ({ id: t.id, name: t.username || t.name || `#${t.id}` }))
    .sort((a, b) => a.name.localeCompare(b.name))
);

/** API 仍用 active/pending/suspended；畫面顯示中文 */
function teacherStatusLabel(status) {
  const s = String(status || '').toLowerCase();
  if (s === 'active') return '在職';
  if (s === 'pending') return '待審核';
  if (s === 'suspended') return '停用';
  return status ? String(status) : '—';
}

function toggleTeacherChip(teacherId) {
  const tid = String(teacherId);
  const idx = selectedTeacherIds.value.findIndex(id => String(id) === tid);
  if (idx >= 0) {
    selectedTeacherIds.value.splice(idx, 1);
  } else {
    selectedTeacherIds.value.push(teacherId);
  }
}


// 老師頭像識別色板：每位老師依 id 取一色作辨識（如 GitHub/Slack 頭像）。屬「功能性
// 多態識別色」，設計系統 token 不足以表達（TD-064）；刻意保留 raw hex，不套單一主色
// 否則所有頭像同色失去辨識。非裝飾、非狀態，故不受 §7「≤2 accent」限制。
const TEACHER_AVATAR_PALETTE = [
  '#1565C0', '#6A1B9A', '#00838F', '#2E7D32', '#C62828', '#EF6C00', '#5D4037', '#37474F',
  '#00695C', '#4527A0', '#AD1457', '#283593',
];

function teacherAvatarColor(teacherId) {
  const n = Math.abs(Number(teacherId) || 0);
  return TEACHER_AVATAR_PALETTE[n % TEACHER_AVATAR_PALETTE.length];
}

function teacherInitial(teacher) {
  const name = String(teacher?.username || teacher?.account || '?').trim();
  return name ? name.charAt(0) : '?';
}

const formBranchOptions = computed(() => {
  if (allBranchOptions.value.length > 0) return allBranchOptions.value;
  return BRANCHES.value || [];
});

function resolveDefaultFormBranchId() {
  if (props.branchId != null && props.branchId !== '') return Number(props.branchId);
  return formBranchOptions.value.length > 0 ? formBranchOptions.value[0].id : null;
}

const LEVEL_OPTIONS = [
  { value: 'elementary', label: '國小' },
  { value: 'junior', label: '國中' },
  { value: 'high', label: '高中' },
];
const LEVEL_LABEL_LOOKUP = { elementary: '國小', junior: '國中', high: '高中' };

const form = ref({
  username: '',
  account: '',
  password: 'teacher123',
  phone: '',
  line_id: '',
  branch_id: resolveDefaultFormBranchId(),
  multi_branches: [],
  subject_ids: [],
  subject_level_scopes: [],
  status: 'active',
  rfid_by_branch: {}
});

const selectedSubjectsForForm = computed(() =>
  subjects.value.filter((s) => (form.value.subject_ids || []).map(Number).includes(Number(s.id)))
);

function hasScope(subjectId, level) {
  return (form.value.subject_level_scopes || []).some(
    (s) => Number(s.subject_id) === Number(subjectId) && s.level === level
  );
}

function toggleScope(subjectId, level) {
  const next = [...(form.value.subject_level_scopes || [])];
  const idx = next.findIndex(
    (s) => Number(s.subject_id) === Number(subjectId) && s.level === level
  );
  if (idx >= 0) {
    next.splice(idx, 1);
  } else {
    next.push({ subject_id: Number(subjectId), level });
  }
  form.value.subject_level_scopes = next;
}

function subjectNameByIdForDisplay(sid) {
  const found = subjects.value.find((s) => Number(s.id) === Number(sid));
  return found?.name || `科目#${sid}`;
}

// 科目範圍 chip 為分類標籤（非狀態）→ 依設計系統用中性 token chip，去 8 色 rainbow
// （§6/§7）。文字本身已標示科目＋學制，毋須色彩編碼。
function scopeSubjectStyle() {
  return {
    background: 'var(--ds-canvas-soft)',
    color: 'var(--ds-ink-mute)',
    border: '1px solid var(--ds-hairline)',
  };
}

function teacherScopeChips(teacher) {
  const scopes = teacher?.subject_level_scopes;
  if (!Array.isArray(scopes) || scopes.length === 0) return [];
  return scopes
    .filter((s) => Number.isFinite(Number(s.subject_id)) && Number(s.subject_id) > 0 && LEVEL_LABEL_LOOKUP[s.level])
    .map((s) => ({
      subjectId: Number(s.subject_id),
      subject: subjectNameByIdForDisplay(Number(s.subject_id)),
      label: LEVEL_LABEL_LOOKUP[s.level],
      levelKey: s.level,
    }));
}

function teacherScopeSummary(teacher) {
  const scopes = teacher?.subject_level_scopes;
  if (!Array.isArray(scopes) || scopes.length === 0) {
    return '未設定';
  }
  const bySubject = new Map();
  scopes.forEach((s) => {
    const id = Number(s.subject_id);
    if (!Number.isFinite(id) || id <= 0) return;
    const lv = LEVEL_LABEL_LOOKUP[s.level] || s.level;
    if (!bySubject.has(id)) bySubject.set(id, new Set());
    bySubject.get(id).add(lv);
  });
  if (bySubject.size === 0) return '未設定';
  const parts = [];
  bySubject.forEach((set, sid) => {
    parts.push(`${subjectNameByIdForDisplay(sid)}（${[...set].join('、')}）`);
  });
  return parts.join('；');
}

function normalizedSubjectLevelScopesForSubmit() {
  const allowed = new Set((form.value.subject_ids || []).map(Number));
  return (form.value.subject_level_scopes || [])
    .filter((s) => allowed.has(Number(s.subject_id)))
    .map((s) => ({ subject_id: Number(s.subject_id), level: String(s.level) }))
    .filter((s) => Number.isFinite(s.subject_id) && s.subject_id > 0 && ['elementary', 'junior', 'high'].includes(s.level));
}

watch(
  () => [...(form.value.subject_ids || [])],
  () => {
    const allowed = new Set((form.value.subject_ids || []).map(Number));
    form.value.subject_level_scopes = (form.value.subject_level_scopes || []).filter((s) =>
      allowed.has(Number(s.subject_id)));
  }
);

const formTeacherCampusIds = computed(() => {
  const main = form.value.branch_id != null ? Number(form.value.branch_id) : null;
  const ids = new Set();
  if (Number.isFinite(main) && main > 0) ids.add(main);
  (form.value.multi_branches || []).forEach((b) => {
    const n = Number(b);
    if (Number.isFinite(n) && n > 0) ids.add(n);
  });
  return Array.from(ids).sort((a, b) => a - b);
});

function teacherHasAnyRfid(teacher) {
  if (teacher?.rfid) return true;
  const rb = teacher?.rfid_by_branch;
  if (rb && typeof rb === 'object' && Object.keys(rb).length > 0) return true;
  return false;
}

const formError = ref('');
const isEditing = computed(() => !!editingId.value);
const showModal = computed(() => !!editingId.value);

const bulkDefaultBranchId = ref(resolveDefaultFormBranchId());
const bulkDefaultStatus = ref('active');
const bulkInputText = ref('');
const bulkError = ref('');
const bulkSubmitting = ref(false);
const bulkResult = ref(null);
const bulkLastSubmittedRows = ref([]);
const temporaryPasswords = ref({});

const SUBJECT_CATEGORY_CONFIG = [
  { key: 'chinese', label: '國文', aliases: ['chinese', '國文'] },
  { key: 'english', label: '英文', aliases: ['english', '英文'] },
  { key: 'math', label: '數學', aliases: ['math', 'mathematics', '數學'] },
  { key: 'science', label: '自然', aliases: ['science', 'physics', 'chemistry', 'biology', '自然', '物理', '化學', '生物'] },
  { key: 'social', label: '社會', aliases: ['social', 'history', 'geography', 'civics', '社會', '歷史', '地理', '公民'] }
];
const SUBJECT_ALIAS_TO_KEY = (() => {
  const map = new Map();
  SUBJECT_CATEGORY_CONFIG.forEach((item) => {
    item.aliases.forEach((alias) => map.set(normalizeSubjectText(alias), item.key));
  });
  return map;
})();
const subjectIdToKey = ref({});
const subjectKeyToId = ref({});

function normalizeSubjectText(text) {
  return String(text || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '')
    .replace(/[()_\-]/g, '');
}

function resolveSubjectKey(name) {
  return SUBJECT_ALIAS_TO_KEY.get(normalizeSubjectText(name)) || null;
}

function teacherSubjectKeys(teacher) {
  const keys = new Set();
  (teacher?.subject_ids || []).forEach((sid) => {
    const key = subjectIdToKey.value[Number(sid)];
    if (key) keys.add(key);
  });
  (teacher?.subject_names || []).forEach((name) => {
    const key = resolveSubjectKey(name);
    if (key) keys.add(key);
  });
  return Array.from(keys);
}

function teacherSubjectLabels(teacher) {
  const ids = (teacher?.subject_ids || []).map(Number).filter((n) => Number.isFinite(n) && n > 0);
  const labels = ids
    .map((id) => subjects.value.find((s) => Number(s.id) === id)?.name || null)
    .filter(Boolean);
  if (labels.length > 0) return [...new Set(labels)];
  // fallback: raw subject_names when IDs not yet resolved
  return [...new Set((teacher?.subject_names || []).map((n) => String(n || '').trim()).filter(Boolean))];
}

function normalizeTeacherSubjectIds(teacher) {
  const keys = new Set(teacherSubjectKeys(teacher));
  return Array.from(keys)
    .map((key) => subjectKeyToId.value[key])
    .filter((id) => Number.isFinite(id));
}

const BULK_FIELD_ORDER = ['account', 'name', 'phone', 'branch_id', 'subject_ids', 'multi_branches', 'line_id', 'status'];
const BULK_HEADER_ALIASES = {
  account: ['account', 'login', 'loginname', 'email', '帳號', '登入帳號'],
  name: ['name', 'username', '姓名', '老師姓名'],
  phone: ['phone', 'mobile', '電話', '手機'],
  branch_id: ['branch', 'branchid', 'branch_id', 'campus', 'campusid', '主分校', '分校'],
  subject_ids: ['subject', 'subjects', 'subjectid', 'subject_ids', '科目', '可授課科目'],
  multi_branches: ['multibranches', 'multi_branches', '跨校', '跨校支援', '支援分校'],
  line_id: ['line', 'lineid', 'line_id', 'line帳號', 'lineid帳號'],
  status: ['status', '狀態'],
};

function normalizeHeaderToken(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '')
    .replace(/[_-]/g, '');
}

function detectDelimiter(raw) {
  if (raw.includes('\t')) return '\t';
  if (raw.includes(',')) return ',';
  return ',';
}

function parseDelimitedLine(raw, delimiter) {
  const cells = [];
  let current = '';
  let inQuotes = false;

  for (let i = 0; i < raw.length; i += 1) {
    const char = raw[i];
    const next = raw[i + 1];
    if (char === '"' && inQuotes && next === '"') {
      current += '"';
      i += 1;
      continue;
    }
    if (char === '"') {
      inQuotes = !inQuotes;
      continue;
    }
    if (char === delimiter && !inQuotes) {
      cells.push(current.trim());
      current = '';
      continue;
    }
    current += char;
  }
  cells.push(current.trim());

  return cells;
}

function resolveHeaderMap(firstRow) {
  const headerMap = {};
  let matchedCount = 0;

  firstRow.forEach((cell, index) => {
    const token = normalizeHeaderToken(cell);
    const field = Object.keys(BULK_HEADER_ALIASES).find((key) => BULK_HEADER_ALIASES[key].includes(token));
    if (field) {
      headerMap[field] = index;
      matchedCount += 1;
    }
  });

  if (
    matchedCount >= 2
    && (Object.prototype.hasOwnProperty.call(headerMap, 'account')
      || Object.prototype.hasOwnProperty.call(headerMap, 'name'))
  ) {
    return headerMap;
  }
  return null;
}

function splitListTokens(value) {
  return String(value || '')
    .split(/[|,;/、]+/)
    .map((item) => item.trim())
    .filter(Boolean);
}

function resolveBranchId(inputValue, fallbackBranchId = null) {
  const raw = String(inputValue || '').trim();
  if (!raw) return fallbackBranchId;
  const numeric = Number(raw);
  if (Number.isFinite(numeric) && numeric > 0) return numeric;

  const normalized = normalizeHeaderToken(raw);
  const found = formBranchOptions.value.find((branch) => {
    const branchName = String(branch.name || '');
    const shortName = branchName.split('(')[0].trim();
    return normalizeHeaderToken(branchName) === normalized
      || normalizeHeaderToken(shortName) === normalized
      || normalizeHeaderToken(branch.code || '') === normalized;
  });
  return found ? Number(found.id) : null;
}

function resolveSubjectIds(value) {
  const tokens = splitListTokens(value);
  const ids = [];
  const unknown = [];

  tokens.forEach((token) => {
    const numeric = Number(token);
    if (Number.isFinite(numeric) && numeric > 0) {
      ids.push(numeric);
      return;
    }
    const key = resolveSubjectKey(token);
    const mappedId = key ? subjectKeyToId.value[key] : null;
    if (Number.isFinite(mappedId)) {
      ids.push(Number(mappedId));
      return;
    }
    unknown.push(token);
  });

  return { ids: Array.from(new Set(ids)), unknown };
}

function parseBulkRows(rawInput) {
  const lines = String(rawInput || '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);

  if (lines.length === 0) {
    return { rows: [], errors: [] };
  }

  const delimiter = detectDelimiter(lines[0]);
  const parsedLines = lines.map((line) => parseDelimitedLine(line, delimiter));
  const headerMap = resolveHeaderMap(parsedLines[0]);
  const startIndex = headerMap ? 1 : 0;
  const errors = [];
  const rows = [];
  const fallbackBranchId = resolveBranchId(bulkDefaultBranchId.value, null);

  for (let i = startIndex; i < parsedLines.length; i += 1) {
    const rowNumber = i + 1;
    const cells = parsedLines[i];
    const rowObj = {};

    BULK_FIELD_ORDER.forEach((field, index) => {
      const cellIndex = headerMap ? headerMap[field] : index;
      rowObj[field] = cellIndex == null ? '' : (cells[cellIndex] ?? '');
    });

    const account = String(rowObj.account || '').trim();
    const name = String(rowObj.name || '').trim();
    const statusRaw = String(rowObj.status || '').trim().toLowerCase();
    const status = ['active', 'pending', 'suspended'].includes(statusRaw) ? statusRaw : bulkDefaultStatus.value;
    const branchId = resolveBranchId(rowObj.branch_id, fallbackBranchId);
    const multiBranches = splitListTokens(rowObj.multi_branches)
      .map((token) => resolveBranchId(token, null))
      .filter((id) => Number.isFinite(id) && id > 0 && id !== branchId);
    const { ids: subjectIds, unknown } = resolveSubjectIds(rowObj.subject_ids);

    if (!account) errors.push(`第 ${rowNumber} 列缺少 account`);
    if (!name) errors.push(`第 ${rowNumber} 列缺少 name`);
    if (!Number.isFinite(branchId) || branchId <= 0) errors.push(`第 ${rowNumber} 列分校無法辨識`);
    if (unknown.length > 0) errors.push(`第 ${rowNumber} 列有無法辨識的科目：${unknown.join('、')}`);

    rows.push({
      row: rowNumber,
      account,
      name,
      phone: String(rowObj.phone || '').trim() || null,
      line_id: String(rowObj.line_id || '').trim() || null,
      branch_id: Number.isFinite(branchId) ? Number(branchId) : null,
      multi_branches: Array.from(new Set(multiBranches)),
      subject_ids: subjectIds,
      status,
    });
  }

  return { rows, errors };
}

const bulkParseSummary = computed(() => {
  const parsed = parseBulkRows(bulkInputText.value);
  return {
    ...parsed,
    rowCount: parsed.rows.length,
    previewRows: parsed.rows.slice(0, 8),
  };
});

const filteredTeachers = computed(() => {
    // #145：原本只有 active/pending 兩條路徑，停用(suspended)老師被隱藏。
    // 狀態下拉（含「停用」）若有選取則優先生效；否則依分頁（含新增的停用分頁）。
    let list;
    if (filterStatus.value) {
      list = teachers.value.filter(t => t.status === filterStatus.value);
    } else if (tab.value === 'suspended') {
      list = teachers.value.filter(t => t.status === 'suspended');
    } else if (tab.value === 'active') {
      list = teachers.value.filter(t => t.status === 'active');
    } else {
      list = teachers.value.filter(t => t.status === 'pending');
    }

    if (selectedTeacherIds.value.length > 0) {
      list = list.filter(t => selectedTeacherIdSet.value.has(String(t.id)));
    }

    if (filterSubjectId.value) {
      const filterId = Number(filterSubjectId.value);
      const selectedKey = subjectIdToKey.value[filterId];
      if (selectedKey) {
        list = list.filter((teacher) => teacherSubjectKeys(teacher).includes(selectedKey));
      } else {
        list = list.filter((teacher) => (teacher.subject_ids || []).map(Number).includes(filterId));
      }
    }

    return list;
});

const pendingCount = computed(() => teachers.value.filter(t => t.status === 'pending').length);
const activeTeachersCount = computed(() => teachers.value.filter(t => t.status === 'active').length);
const suspendedCount = computed(() => teachers.value.filter(t => t.status === 'suspended').length);

function getBranchName(bid) {
  const numericId = Number(bid);
  const found = formBranchOptions.value.find((b) => Number(b.id) === numericId);
  return found?.name || _getBranchName(bid);
}

function getMultiBranchList(teacher) {
  const ids = teacher.branch_ids || [];
  const mainId = teacher.branch_id != null ? Number(teacher.branch_id) : null;
  const others = mainId != null ? ids.filter(b => Number(b) !== mainId) : ids;
  return others.map(bid => getBranchName(bid).split('(')[0].trim());
}

async function getAuthHeaders() {
  const { data: { session } } = await supabase.auth.getSession();
  const fallbackSession = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  const token = session?.access_token || fallbackSession?.access_token;
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
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      subjects.value = [];
      return;
    }
    const list = Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : []);

    // Build key mappings for CSV import / filter backward compat
    const idToKey = {};
    const keyToId = {};
    list.forEach((item) => {
      const id = Number(item.id);
      const rawName = item.name || item.Subject_Name || '';
      if (!Number.isFinite(id) || !rawName) return;
      const key = resolveSubjectKey(rawName);
      if (key) {
        idToKey[id] = key;
        if (!keyToId[key]) keyToId[key] = id;
      }
    });
    subjectIdToKey.value = idToKey;
    subjectKeyToId.value = keyToId;

    // Use API subjects directly — no hardcoded category filtering
    // Server already returns sorted by name (alphabetical)
    subjects.value = list
      .map((item) => ({ id: Number(item.id), name: item.name || item.Subject_Name || '' }))
      .filter((item) => Number.isFinite(item.id) && item.id > 0 && item.name !== '');
  } catch (_) {
    subjects.value = [];
    subjectIdToKey.value = {};
    subjectKeyToId.value = {};
  }
}

async function loadAllBranchOptions() {
  try {
    const res = await fetch(`${API_BASE}/branches`, { cache: 'no-store' });
    const data = await res.json().catch(() => []);
    if (!res.ok || !Array.isArray(data) || data.length === 0) {
      allBranchOptions.value = BRANCHES.value || [];
      return;
    }
    allBranchOptions.value = data
      .map((b) => ({
        ...b,
        id: Number(b.id),
      }))
      .filter((b) => Number.isFinite(b.id));
  } catch (_) {
    allBranchOptions.value = BRANCHES.value || [];
  }
}

const editTeacher = (teacher) => {
  editingId.value = teacher.id;
  const mainId = teacher.branch_id != null ? Number(teacher.branch_id) : null;
  const branchIds = teacher.branch_ids || [];
  const multiOnly = mainId != null ? branchIds.filter(b => Number(b) !== mainId) : branchIds;
  const rb = {};
  if (teacher.rfid_by_branch && typeof teacher.rfid_by_branch === 'object') {
    Object.entries(teacher.rfid_by_branch).forEach(([k, v]) => {
      if (v != null && String(v).trim() !== '') rb[k] = String(v).trim();
    });
  }
  if (teacher.rfid && mainId != null && Number.isFinite(mainId)) {
    const key = String(mainId);
    if (!rb[key]) rb[key] = String(teacher.rfid).trim();
  }
  form.value = {
    username: teacher.username,
    account: teacher.account || teacher.email || '',
    phone: teacher.phone || '',
    line_id: teacher.line_id || '',
    branch_id: teacher.branch_id,
    multi_branches: multiOnly,
    subject_ids: (teacher.subject_ids || []).map(Number).filter((id) => subjects.value.some((s) => Number(s.id) === id)),
    subject_level_scopes: Array.isArray(teacher.subject_level_scopes)
      ? teacher.subject_level_scopes
        .map((s) => ({ subject_id: Number(s.subject_id), level: String(s.level) }))
        .filter((s) => Number.isFinite(s.subject_id) && s.subject_id > 0 && ['elementary', 'junior', 'high'].includes(s.level))
      : [],
    status: teacher.status || 'active',
    employment_type: teacher.employment_type || 'full_time',
    rfid_by_branch: rb
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
    window.dispatchEvent(new CustomEvent('alltrue-refresh-badges'));
  } catch (e) {
    alert('核准失敗，請稍後再試');
  }
};

function navigateToSchedule(teacherId, target) {
  emit('navigate-to-schedule', { teacherId, target: target || 'course-mgmt' });
}

async function resetTeacherPassword(teacher) {
  const name = teacher?.username || '老師';
  const account = teacher?.account || teacher?.email || '';
  if (!confirm(`確認重設「${name}」的密碼？`)) return;

  try {
    const headers = await getAuthHeaders();
    const res = await fetch(`${API_BASE}/profiles/${teacher.id}/reset-password`, {
      method: 'POST',
      headers,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      alert(`重設失敗：${json?.message || res.statusText}`);
      return;
    }

    const newPassword = String(json?.temporary_password || '').trim();
    if (!newPassword) {
      alert('重設成功，但未取得新密碼，請稍後重試。');
      return;
    }

    temporaryPasswords.value = {
      ...temporaryPasswords.value,
      [teacher.id]: newPassword,
    };
    try {
      await navigator.clipboard.writeText(`${json?.account || account},${newPassword}`);
      alert(`已重設密碼。\n帳號：${json?.account || account}\n新密碼：${newPassword}\n（已複製到剪貼簿）`);
    } catch (_) {
      alert(`已重設密碼。\n帳號：${json?.account || account}\n新密碼：${newPassword}`);
    }
  } catch (error) {
    alert(`重設失敗：${error?.message || '未知錯誤'}`);
  }
}

async function deleteTeacher(teacher) {
  const name = teacher?.username || '老師';
  const account = teacher?.account || teacher?.email || '';
  const confirmed = confirm(
    `確認刪除「${name}」？\n帳號：${account || '—'}\n\n提醒：已有授課/評量資料的老師不可刪除，請改用停用。`,
  );
  if (!confirmed) return;

  try {
    const headers = await getAuthHeaders();
    const res = await fetch(`${API_BASE}/profiles/${teacher.id}`, {
      method: 'DELETE',
      headers,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const dep = json?.dependency_counts;
      if (dep && typeof dep === 'object') {
        const lines = Object.entries(dep)
          .filter(([, count]) => Number(count) > 0)
          .map(([table, count]) => `${table}: ${count}`);
        alert(`無法刪除：已有歷史資料\n${lines.join('\n')}`);
      } else {
        alert(`刪除失敗：${json?.message || res.statusText}`);
      }
      return;
    }

    const next = { ...temporaryPasswords.value };
    delete next[teacher.id];
    temporaryPasswords.value = next;

    await loadTeachers();
    alert(`已刪除老師：${name}`);
  } catch (error) {
    alert(`刪除失敗：${error?.message || '未知錯誤'}`);
  }
}

function openBulkModal() {
  showBulkModal.value = true;
  bulkError.value = '';
  bulkResult.value = null;
  bulkDefaultBranchId.value = resolveDefaultFormBranchId();
  bulkDefaultStatus.value = 'active';
}

function closeBulkModal() {
  showBulkModal.value = false;
  bulkSubmitting.value = false;
  bulkError.value = '';
  bulkResult.value = null;
  bulkLastSubmittedRows.value = [];
  bulkInputText.value = '';
}

function buildBulkCredentialText(createdRows = []) {
  const lines = ['account,name,initial_password,branch_id'];
  createdRows.forEach((row) => {
    lines.push([
      row.account || '',
      row.name || '',
      row.initial_password || '',
      row.branch_id ?? '',
    ].map((v) => `"${String(v).replaceAll('"', '""')}"`).join(','));
  });
  return lines.join('\n');
}

async function copyBulkCredentials() {
  const createdRows = bulkResult.value?.created || [];
  if (!createdRows.length) return;
  const text = buildBulkCredentialText(createdRows);
  try {
    await navigator.clipboard.writeText(text);
    alert('帳密清單已複製到剪貼簿。');
  } catch (_) {
    alert('複製失敗，請改用下載 CSV。');
  }
}

function downloadBulkCredentialsCsv() {
  const createdRows = bulkResult.value?.created || [];
  if (!createdRows.length) return;
  const text = `\uFEFF${buildBulkCredentialText(createdRows)}`;
  const blob = new Blob([text], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `teacher-credentials-${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

function rowsToBulkInput(rows = []) {
  const lines = ['account,name,phone,branch_id,subject_ids,multi_branches,line_id,status'];
  rows.forEach((row) => {
    lines.push([
      row.account || '',
      row.name || '',
      row.phone || '',
      row.branch_id || '',
      (row.subject_ids || []).join('|'),
      (row.multi_branches || []).join('|'),
      row.line_id || '',
      row.status || 'active',
    ].map((v) => `"${String(v).replaceAll('"', '""')}"`).join(','));
  });
  return lines.join('\n');
}

function refillBulkWithFailedRows() {
  const failedRows = (bulkResult.value?.failed || [])
    .map((item) => Number(item.row))
    .filter((row) => Number.isFinite(row));
  if (!failedRows.length) return;

  const failedSet = new Set(failedRows);
  const rows = (bulkLastSubmittedRows.value || []).filter((item) => failedSet.has(Number(item.row)));
  bulkInputText.value = rowsToBulkInput(rows);
  bulkResult.value = null;
  bulkError.value = '';
}

async function submitBulkTeachers() {
  bulkError.value = '';
  const parsed = bulkParseSummary.value;
  if (!parsed.rows.length) {
    bulkError.value = '請先貼上批次資料。';
    return;
  }

  if (parsed.errors.length > 0) {
    const shouldContinue = confirm(`偵測到 ${parsed.errors.length} 個格式問題，仍要送出嗎？`);
    if (!shouldContinue) return;
  }

  const headers = await getAuthHeaders();
  bulkSubmitting.value = true;
  try {
    const payload = {
      default_branch_id: bulkDefaultBranchId.value || null,
      teachers: parsed.rows.map((row) => ({
        name: row.name,
        account: row.account,
        phone: row.phone,
        branch_id: row.branch_id,
        multi_branches: row.multi_branches,
        line_id: row.line_id,
        subject_ids: row.subject_ids,
        status: row.status || bulkDefaultStatus.value,
      })),
    };

    const res = await fetch(`${API_BASE}/profiles/bulk-teachers`, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    });
    const json = await res.json().catch(() => ({}));

    bulkLastSubmittedRows.value = parsed.rows;
    bulkResult.value = {
      created: Array.isArray(json?.created) ? json.created : [],
      failed: Array.isArray(json?.failed) ? json.failed : [],
      summary: json?.summary || {
        total: parsed.rows.length,
        created: Array.isArray(json?.created) ? json.created.length : 0,
        failed: Array.isArray(json?.failed) ? json.failed.length : 0,
      },
    };

    if (bulkResult.value.created.length > 0) {
      const nextPasswords = { ...temporaryPasswords.value };
      bulkResult.value.created.forEach((item) => {
        const userId = Number(item?.user_id);
        const pwd = String(item?.initial_password || '');
        if (Number.isFinite(userId) && userId > 0 && pwd) {
          nextPasswords[userId] = pwd;
        }
      });
      temporaryPasswords.value = nextPasswords;
    }

    if (!res.ok && (bulkResult.value.created?.length || 0) === 0) {
      bulkError.value = json?.message || '批次建立失敗';
      return;
    }

    if ((bulkResult.value.created?.length || 0) > 0) {
      await loadTeachers();
      alert('批次建立完成。請立即下載或複製初始密碼。');
    }
  } catch (error) {
    bulkError.value = error?.message || '批次建立失敗，請稍後再試。';
  } finally {
    bulkSubmitting.value = false;
  }
}

const closeModal = () => {
  showAddModal.value = false;
  editingId.value = null;
  formError.value = '';
  const branchId = resolveDefaultFormBranchId();
  form.value = {
    username: '', account: '', password: 'teacher123', phone: '', line_id: '',
    branch_id: branchId, multi_branches: [], subject_ids: [], subject_level_scopes: [], status: 'active', employment_type: 'full_time', rfid_by_branch: {}
  };
};

const bindRfidFromTempForCampus = async (campusId) => {
  const cid = Number(campusId);
  if (!Number.isFinite(cid) || cid <= 0) {
    formError.value = '分校無效';
    return;
  }
  try {
    const headers = await getAuthHeaders();
    const res = await fetch(`${API_BASE}/temp-rfid?campus_id=${cid}`, { headers });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      formError.value = `取得暫存 RFID 失敗（HTTP ${res.status}）${json?.message ? '：' + json.message : ''}`;
      return;
    }
    if (json?.data?.rfid) {
      const next = { ...form.value.rfid_by_branch, [String(cid)]: json.data.rfid };
      form.value.rfid_by_branch = next;
      formError.value = '';
    } else {
      formError.value = '暫無刷卡資料，請先於該分校刷卡後 5 分鐘內點擊讀取';
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
    const subjectIds = (form.value.subject_ids || []).map(Number).filter((sid) => Number.isFinite(sid));
    if (isEditing.value) {
      if (!form.value.account) { formError.value = '請輸入登入帳號'; return; }
      const rfidByBranch = {};
      formTeacherCampusIds.value.forEach((bid) => {
        const k = String(bid);
        const raw = form.value.rfid_by_branch[k];
        rfidByBranch[k] = raw == null || String(raw).trim() === '' ? '' : String(raw).trim();
      });
      const body = {
        username: form.value.username,
        account: form.value.account,
        branch_id: form.value.branch_id,
        multi_branches: form.value.multi_branches || [],
        status: form.value.status,
        employment_type: form.value.employment_type || 'full_time',
        phone: form.value.phone || null,
        line_id: form.value.line_id || null,
        rfid_by_branch: rfidByBranch,
        subject_ids: subjectIds,
        subject_level_scopes: normalizedSubjectLevelScopesForSubmit(),
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
      if (!form.value.account) { formError.value = '請輸入登入帳號'; return; }
      if (!form.value.password) { formError.value = '請輸入密碼'; return; }
      if (String(form.value.password).length < 8) { formError.value = '密碼至少 8 個字元'; return; }
      const body = {
        name: form.value.username,
        account: form.value.account,
        password: form.value.password,
        role: 'teacher',
        campus_id: form.value.branch_id,
        branch_id: form.value.branch_id,
        multi_branches: form.value.multi_branches || [],
        status: form.value.status,
        employment_type: form.value.employment_type || 'full_time',
        phone: form.value.phone || null,
        line_id: form.value.line_id || null,
        subject_ids: subjectIds,
        subject_level_scopes: normalizedSubjectLevelScopesForSubmit(),
      };
      const res = await fetch(`${API_BASE}/profiles`, {
        method: 'POST',
        headers,
        body: JSON.stringify(body)
      });
      const j = await res.json().catch(() => ({}));
      if (!res.ok) {
        const details = j?.errors ? Object.values(j.errors).flat().join(' ') : '';
        formError.value = details || j?.message || '新增失敗';
        return;
      }
    }
    closeModal();
    await loadTeachers();
  } catch (err) {
    formError.value = err?.message || '儲存失敗，請稍後再試';
  }
};

watch(() => props.branchId, () => {
  const fallbackBranch = resolveDefaultFormBranchId();
  if (!isEditing.value) {
    form.value.branch_id = fallbackBranch;
  }
  bulkDefaultBranchId.value = fallbackBranch;
  loadTeachers();
});
onMounted(() => {
  loadAllBranchOptions();
  loadTeachers();
  loadSubjects();
});

watch(showAddModal, (opened) => {
  if (opened && subjects.value.length === 0) {
    loadSubjects();
  }
  if (opened && formBranchOptions.value.length === 0) {
    loadAllBranchOptions();
  }
});

watch(showBulkModal, (opened) => {
  if (opened && subjects.value.length === 0) {
    loadSubjects();
  }
  if (opened && formBranchOptions.value.length === 0) {
    loadAllBranchOptions();
  }
});
</script>

<style scoped>
.teachers-page {
  font-family: 'Noto Sans TC', 'Inter', 'Microsoft JhengHei', sans-serif;
  letter-spacing: 0.01em;
}

.teachers-card {
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
  border: 1px solid var(--ds-hairline);
}

.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 18px;
  gap: 16px;
}

.title-group h2 {
  margin: 0;
  font-size: 24px;
  line-height: 1.2;
  font-weight: 700;
  color: var(--ds-ink);
}

.title-sub {
  margin-top: 4px;
  font-size: 13px;
  color: var(--ds-ink-mute);
}

.header-btns {
  display: flex;
  gap: 10px;
  align-items: center;
}

.teacher-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.summary-card {
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  background: linear-gradient(180deg, var(--ds-canvas) 0%, var(--ds-canvas-soft) 100%);
  padding: 12px 14px;
}

.summary-label {
  font-size: 12px;
  color: var(--ds-ink-mute);
}

.summary-value {
  font-size: 24px;
  line-height: 1.25;
  font-weight: 700;
  color: var(--ds-ink);
}

.summary-value.active { color: var(--ds-success); }
.summary-value.pending { color: var(--ds-primary); }

.filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
  margin-bottom: 12px;
  padding: 12px;
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  background: var(--ds-canvas-soft);
}

.teacher-chips-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
  padding: 8px 12px;
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  background: var(--ds-canvas-soft);
  min-height: 40px;
}
.teacher-chips-label {
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 600;
  color: var(--ds-ink-mute);
}
.teacher-chips-scroll {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  min-width: 0;
  max-height: 5.5rem;
  overflow-y: auto;
  padding: 2px 0;
}
.teacher-chip {
  padding: 5px 12px;
  border: 1px solid var(--ds-hairline);
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.3;
  color: var(--ds-ink);
  background: var(--ds-canvas);
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s, color 0.15s;
}
.teacher-chip:hover {
  background: var(--ds-canvas-soft);
  border-color: var(--ds-hairline);
}
.teacher-chip.active {
  color: var(--ds-canvas);
}
.teacher-chip-clear {
  flex-shrink: 0;
  padding: 4px 10px;
  border: 1px dashed var(--ds-hairline);
  border-radius: 999px;
  font-size: 12px;
  color: var(--ds-ink-mute);
  background: transparent;
  cursor: pointer;
  transition: color 0.15s, border-color 0.15s;
}
.teacher-chip-clear:hover {
  color: var(--ds-danger);
  border-color: var(--ds-danger);
}

.filter-item {
  min-width: 150px;
}

.filter-item-search {
  min-width: 260px;
  flex: 1;
}

.filter-item-view {
  min-width: auto;
}


.teacher-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 16px;
  align-items: stretch;
}

.teacher-profile-card {
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  background: var(--ds-canvas);
  box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: box-shadow 0.2s;
}

.teacher-profile-card:hover {
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.12);
}

.tpc-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 16px 12px;
  border-bottom: 1px solid var(--ds-canvas-soft);
}

.tpc-avatar {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  color: var(--ds-canvas);
  font-size: 20px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}

.tpc-head-text {
  flex: 1;
  min-width: 0;
}

.tpc-name {
  margin: 0;
  font-size: 17px;
  font-weight: 700;
  color: var(--ds-ink);
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tpc-account {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--ds-ink-mute);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tpc-status.status-tag {
  flex-shrink: 0;
  align-self: flex-start;
}

.tpc-body {
  padding: 12px 16px 14px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.tpc-row {
  display: flex;
  gap: 10px;
  font-size: 13px;
  line-height: 1.45;
}

.tpc-row-tags {
  align-items: flex-start;
}

.tpc-label {
  flex: 0 0 52px;
  color: var(--ds-ink-mute);
  font-weight: 600;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding-top: 2px;
}

.tpc-value {
  color: var(--ds-ink-mute);
  word-break: break-all;
}

.tpc-tags {
  flex: 1;
  min-width: 0;
}

.tpc-rfid-block .tpc-label {
  padding-top: 4px;
}

.tpc-rfid-inner {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.tpc-rfid-btn {
  align-self: flex-start;
}

.tpc-temp-pw {
  font-size: 12px;
  color: var(--ds-ink-mute);
}

.tpc-scope-summary {
  align-items: flex-start;
}

.tpc-scope-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.scope-chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  line-height: 1.6;
}


.scope-summary-cell {
  font-size: 12px;
  line-height: 1.45;
  color: var(--ds-ink-mute);
  max-width: 220px;
}

.scope-table-wrap {
  overflow-x: auto;
  border: 1px solid var(--ds-hairline);
  border-radius: 10px;
  background: var(--ds-canvas);
}

.scope-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.92rem;
}

.scope-table th,
.scope-table td {
  border: 1px solid var(--ds-hairline);
  padding: 8px 10px;
  text-align: center;
}

.scope-table th:first-child,
.scope-table td:first-child {
  text-align: left;
}

.tpc-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 12px 16px 16px;
  border-top: 1px solid var(--ds-canvas-soft);
  background: rgba(248, 250, 252, 0.85);
}

.tpc-actions .small {
  min-height: 30px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
}
.filter-row label {
  display: block;
  font-size: 12px;
  color: var(--ds-ink-mute);
  margin-bottom: 6px;
  font-weight: 600;
}
.filter-row input, .filter-row select {
  padding: 9px 12px;
  border: 1px solid var(--ds-hairline);
  border-radius: 10px;
  min-width: 140px;
  font-size: 14px;
  background: var(--ds-canvas);
}

.tabs {
    display: flex;
    gap: 8px;
}
.tabs button {
    background: var(--ds-canvas-soft);
    border: 1px solid var(--ds-hairline);
    border-radius: 10px;
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
    color: var(--ds-ink-mute);
}
.tabs button.active {
    border-color: var(--ds-warning);
    color: var(--ds-warning);
    background: var(--ds-warning-wash);
    font-weight: 700;
}


.action-group {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  min-width: 280px;
}

.action-group .small {
  min-height: 30px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
}

.status-tag {
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.8em;
}
.status-tag.active { background: var(--ds-success-wash); color: var(--ds-success); }
.status-tag.pending { background: var(--ds-warning-wash); color: var(--ds-primary); }
.status-tag.suspended { background: var(--ds-danger-wash); color: var(--ds-danger); }
.status-tag.tpc-parttime { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); margin-left: 4px; }

button.small.warning {
  background: var(--ds-warning-wash);
  border: 1px solid var(--ds-hairline);
  color: var(--ds-warning);
}

button.small.danger {
  background: var(--ds-danger-wash);
  border: 1px solid var(--ds-hairline);
  color: var(--ds-danger);
}

.badge {
    background: var(--ds-danger);
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
  background: var(--ds-canvas);
  padding: 24px;
  border-radius: 8px;
  width: 450px;
  max-height: 90vh;
  overflow-y: auto;
}
.modal-wide {
  width: min(920px, 94vw);
}
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: bold; }
.form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid var(--ds-hairline); border-radius: 4px; }
.form-group textarea { width: 100%; padding: 8px; border: 1px solid var(--ds-hairline); border-radius: 4px; resize: vertical; font-family: monospace; }
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
  padding: 4px 10px;
  border-radius: 8px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas-soft);
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: var(--ds-ink-mute);
  transition: border-color 0.15s, background 0.15s;
  user-select: none;
}

.branch-chip:hover {
  border-color: var(--primary);
  background: var(--primary-bg);
}

.branch-chip.selected {
  border-color: var(--primary);
  background: var(--primary-bg);
  color: var(--primary);
  font-weight: 700;
}
.branch-chip.disabled {
  opacity: 0.5;
  cursor: default;
  border-color: var(--ds-hairline);
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
}
.branch-chip.disabled:hover { border-color: var(--ds-hairline); background: var(--ds-canvas-soft); }
.form-group .hint { font-size: 12px; color: var(--ds-ink-mute); margin-bottom: 8px; font-weight: normal; }

.rfid-bind-row {
  display: flex;
  gap: 8px;
  align-items: center;
}
.rfid-bind-row input {
  flex: 1;
  padding: 8px 12px;
  border: 1px solid var(--ds-hairline);
  border-radius: 4px;
}
.rfid-bind-row input[readonly] {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
  cursor: default;
}

.rfid-branch-block {
  margin-bottom: 12px;
  padding: 10px 12px;
  border: 1px solid var(--ds-hairline);
  border-radius: 8px;
  background: var(--ds-canvas-soft);
}
.rfid-branch-title {
  font-size: 12px;
  font-weight: 700;
  color: var(--ds-ink-mute);
  margin-bottom: 8px;
}
.rfid-multi-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 6px;
}
.rfid-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}
.rfid-branch-mini {
  color: var(--ds-ink-mute);
  font-weight: 600;
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
  color: var(--ds-primary);
}
.actions { display: flex; justify-content: flex-end; gap: 8px; }

.form-error {
  background: var(--ds-danger-wash);
  color: var(--ds-danger);
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 13px;
  margin-bottom: 12px;
}

.branch-tag {
  display: inline-block;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
  padding: 3px 9px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  margin: 2px 4px 2px 0;
  white-space: nowrap;
}

.no-support {
  color: var(--ds-ink-mute);
  font-size: 0.85em;
}

.empty-state {
  border: 1px dashed var(--ds-hairline);
  border-radius: 10px;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
  padding: 18px;
  text-align: center;
}

.bulk-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.bulk-preview,
.bulk-result {
  margin: 12px 0;
  border: 1px solid var(--ds-hairline);
  border-radius: 8px;
  padding: 12px;
}

.bulk-preview-title {
  font-size: 13px;
  color: var(--ds-ink-mute);
  margin-bottom: 8px;
}

.bulk-result-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}

.teacher-table.compact th,
.teacher-table.compact td {
  padding: 8px;
  font-size: 12px;
}

.mono {
  font-family: monospace;
  font-size: 13px;
  letter-spacing: 0.02em;
}

@media (max-width: 960px) {
  .teacher-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .header-actions {
    flex-direction: column;
    align-items: flex-start;
  }

  .header-btns {
    width: 100%;
  }

  .action-group {
    min-width: 220px;
  }
}
</style>
