<template>
  <div class="teachers-page">
  <div class="card teachers-card">
    <div class="header-actions" data-guide="teachers-header">
      <div class="title-group">
        <h2>老師管理</h2>
        <p class="title-sub">管理老師資料、分校配置與登入帳號操作</p>
      </div>
      <div class="tabs">
          <button :class="{ active: tab === 'active' }" @click="tab = 'active'">正式老師 (Active)</button>
          <button :class="{ active: tab === 'pending' }" @click="tab = 'pending'">待審核 (Pending) <span v-if="pendingCount > 0" class="badge">{{ pendingCount }}</span></button>
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
          <option value="active">Active</option>
          <option value="pending">Pending</option>
          <option value="suspended">Suspended</option>
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

    <div v-else class="teacher-cards-grid" data-guide="teachers-table">
      <div v-if="filteredTeachers.length === 0" class="empty-state" style="grid-column: 1 / -1;">
        目前沒有符合條件的老師資料。
      </div>
      <div
        v-for="teacher in filteredTeachers"
        :key="teacher.id"
        class="teacher-card"
      >
        <!-- Card Header -->
        <div class="tc-header">
          <div class="tc-avatar" :style="avatarStyle(teacher.username)">
            {{ teacher.username?.[0] ?? '?' }}
          </div>
          <div class="tc-identity">
            <div class="tc-name">{{ teacher.username }}</div>
            <div class="tc-account mono">{{ teacher.account || teacher.email || '—' }}</div>
            <div class="tc-phone" v-if="teacher.phone">📞 {{ teacher.phone }}</div>
          </div>
          <span :class="['status-tag', teacher.status]">{{ teacher.status }}</span>
        </div>

        <!-- Card Body -->
        <div class="tc-body">
          <div class="tc-row">
            <span class="tc-icon">🏫</span>
            <div>
              <span class="branch-tag main-branch">{{ getBranchName(teacher.branch_id) }}</span>
              <span v-for="bName in getMultiBranchList(teacher)" :key="bName" class="branch-tag">{{ bName }}</span>
            </div>
          </div>
          <div class="tc-row" v-if="teacherSubjectLabels(teacher).length > 0">
            <span class="tc-icon">📚</span>
            <div>
              <span v-for="n in teacherScopeSummary(teacher)" :key="n" class="branch-tag subject-tag">{{ n }}</span>
            </div>
          </div>
          <div class="tc-row tc-rfid" @click="editTeacher(teacher)" title="點擊編輯以綁定 RFID">
            <span class="tc-icon">📡</span>
            <span v-if="teacher.rfid" class="rfid-tag">{{ teacher.rfid }}</span>
            <span v-else class="no-support">未綁定</span>
          </div>
        </div>

        <!-- Card Footer -->
        <div class="tc-footer">
          <button class="small" @click="editTeacher(teacher)">編輯</button>
          <button class="small ghost" @click="navigateToSchedule(teacher.id, 'course-mgmt')">課程</button>
          <button class="small ghost" @click="navigateToSchedule(teacher.id, 'calendar')">日曆</button>
          <div class="tc-dropdown" @click.stop="toggleDropdown(teacher.id)">
            <button class="small ghost tc-more-btn">⋮</button>
            <div v-if="activeDropdown === teacher.id" class="tc-dropdown-menu">
              <button @click.stop="resetTeacherPassword(teacher); closeDropdown()">重設密碼</button>
              <button v-if="teacher.status === 'pending'" @click.stop="approveTeacher(teacher); closeDropdown()" class="approve">核准</button>
              <button @click.stop="deleteTeacher(teacher); closeDropdown()" class="danger">刪除</button>
            </div>
          </div>
        </div>
      </div>
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
          <p v-if="subjects.length === 0" class="hint">目前沒有可選科目，請先確認科目資料已建立。</p>
        </div>

        <div v-if="selectedSubjectsForScopes.length > 0" class="form-group">
          <label>授課學段設定</label>
          <p class="hint">選擇每個科目可教授的學段（國小/國中/高中）。未勾選學段的科目在建課時會出現警示。</p>
          <table class="scope-matrix">
            <thead>
              <tr>
                <th>科目</th>
                <th v-for="lv in LEVEL_OPTIONS" :key="'hdr-'+lv.value">{{ lv.label }}</th>
                <th>全選</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="subj in selectedSubjectsForScopes" :key="'scope-'+subj.id">
                <td class="scope-subj-name">{{ subj.name }}</td>
                <td v-for="lv in LEVEL_OPTIONS" :key="'scope-'+subj.id+'-'+lv.value" class="scope-cell">
                  <label class="scope-check-label">
                    <input
                      type="checkbox"
                      :checked="hasScopeEntry(subj.id, lv.value)"
                      @change="toggleScopeEntry(subj.id, lv.value)"
                    />
                  </label>
                </td>
                <td class="scope-cell">
                  <button type="button" class="scope-toggle-all" @click="toggleAllLevelsForSubject(subj.id)">
                    {{ LEVEL_OPTIONS.every(lv => hasScopeEntry(subj.id, lv.value)) ? '取消全選' : '全選' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
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
              <option value="active">Active (正式)</option>
              <option value="pending">Pending (待審核)</option>
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
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
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
  rfid: ''
});

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
  { key: 'social', label: '社會', aliases: ['social', '社會'] },
  { key: 'science_mix', label: '理化', aliases: ['science', '理化'] },
  { key: 'physics', label: '物理', aliases: ['physics', '物理'] },
  { key: 'chemistry', label: '化學', aliases: ['chemistry', '化學'] },
  { key: 'biology', label: '生物', aliases: ['biology', '生物'] },
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
  const labels = [];
  const keySet = new Set(teacherSubjectKeys(teacher));
  SUBJECT_CATEGORY_CONFIG.forEach((item) => {
    if (keySet.has(item.key)) labels.push(item.label);
  });

  if (labels.length > 0) return labels;

  const fallback = (teacher?.subject_names || [])
    .map((name) => String(name || '').trim())
    .filter(Boolean);
  return Array.from(new Set(fallback));
}

const LEVEL_LABEL_MAP = { elementary: '國小', junior: '國中', high: '高中' };

function teacherScopeSummary(teacher) {
  const scopes = Array.isArray(teacher?.subject_level_scopes) ? teacher.subject_level_scopes : [];
  if (scopes.length === 0) return teacherSubjectLabels(teacher);

  const subjectLevels = {};
  scopes.forEach((s) => {
    const sid = Number(s.subject_id);
    const key = subjectIdToKey.value[sid];
    const cfg = key ? SUBJECT_CATEGORY_CONFIG.find((c) => c.key === key) : null;
    const name = cfg?.label || `#${sid}`;
    if (!subjectLevels[name]) subjectLevels[name] = [];
    const lbl = LEVEL_LABEL_MAP[s.level] || s.level;
    if (!subjectLevels[name].includes(lbl)) subjectLevels[name].push(lbl);
  });

  return Object.entries(subjectLevels).map(
    ([name, levels]) => `${name}（${levels.join('/')}）`
  );
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
    let list = tab.value === 'active'
      ? teachers.value.filter(t => t.status === 'active')
      : teachers.value.filter(t => t.status === 'pending');

    if (filterSubjectId.value) {
      const selectedKey = subjectIdToKey.value[Number(filterSubjectId.value)];
      if (!selectedKey) return [];
      list = list.filter((teacher) => teacherSubjectKeys(teacher).includes(selectedKey));
    }

    return list;
});

const pendingCount = computed(() => teachers.value.filter(t => t.status === 'pending').length);
const activeTeachersCount = computed(() => teachers.value.filter(t => t.status === 'active').length);

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
    const idToKey = {};
    const keyToId = {};

    list.forEach((item) => {
      const id = Number(item.id);
      const rawName = item.name || item.Subject_Name || '';
      if (!Number.isFinite(id) || !rawName) return;
      const key = resolveSubjectKey(rawName);
      if (!key) return;
      idToKey[id] = key;
      if (!keyToId[key]) keyToId[key] = id;
    });

    subjectIdToKey.value = idToKey;
    subjectKeyToId.value = keyToId;

    subjects.value = SUBJECT_CATEGORY_CONFIG
      .map((item) => ({ id: keyToId[item.key], name: item.label }))
      .filter((item) => Number.isFinite(item.id));
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

function hasScopeEntry(subjectId, level) {
  return form.value.subject_level_scopes.some(
    (s) => Number(s.subject_id) === Number(subjectId) && s.level === level
  );
}

function toggleScopeEntry(subjectId, level) {
  const scopes = [...form.value.subject_level_scopes];
  const idx = scopes.findIndex(
    (s) => Number(s.subject_id) === Number(subjectId) && s.level === level
  );
  if (idx >= 0) {
    scopes.splice(idx, 1);
  } else {
    scopes.push({ subject_id: Number(subjectId), level });
  }
  form.value.subject_level_scopes = scopes;
}

function toggleAllLevelsForSubject(subjectId) {
  const allLevels = LEVEL_OPTIONS.map((l) => l.value);
  const allPresent = allLevels.every((lv) => hasScopeEntry(subjectId, lv));
  const scopes = form.value.subject_level_scopes.filter(
    (s) => Number(s.subject_id) !== Number(subjectId)
  );
  if (!allPresent) {
    allLevels.forEach((lv) => scopes.push({ subject_id: Number(subjectId), level: lv }));
  }
  form.value.subject_level_scopes = scopes;
}

const selectedSubjectsForScopes = computed(() => {
  return subjects.value.filter((s) => form.value.subject_ids.includes(s.id));
});

const editTeacher = (teacher) => {
  editingId.value = teacher.id;
  const mainId = teacher.branch_id != null ? Number(teacher.branch_id) : null;
  const branchIds = teacher.branch_ids || [];
  const multiOnly = mainId != null ? branchIds.filter(b => Number(b) !== mainId) : branchIds;
  const existingScopes = Array.isArray(teacher.subject_level_scopes)
    ? teacher.subject_level_scopes.map((s) => ({ subject_id: Number(s.subject_id), level: s.level }))
    : [];
  form.value = {
    username: teacher.username,
    account: teacher.account || teacher.email || '',
    phone: teacher.phone || '',
    line_id: teacher.line_id || '',
    branch_id: teacher.branch_id,
    multi_branches: multiOnly,
    subject_ids: normalizeTeacherSubjectIds(teacher),
    subject_level_scopes: existingScopes,
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
      body: JSON.stringify({
        status: 'active',
        branch_id: props.branchId != null && props.branchId !== '' ? Number(props.branchId) : undefined,
      })
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
    branch_id: branchId, multi_branches: [], subject_ids: [], subject_level_scopes: [],
    status: 'active', rfid: ''
  };
};

const bindRfidFromTemp = async () => {
  if (!props.branchId) { formError.value = '請先選擇分校'; return; }
  try {
    const headers = await getAuthHeaders();
    const res = await fetch(`${API_BASE}/temp-rfid?campus_id=${props.branchId}`, { headers });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      formError.value = `取得暫存 RFID 失敗（HTTP ${res.status}）${json?.message ? '：' + json.message : ''}`;
      return;
    }
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
    const subjectIds = (form.value.subject_ids || []).map(Number).filter((sid) => Number.isFinite(sid));
    if (isEditing.value) {
      if (!form.value.account) { formError.value = '請輸入登入帳號'; return; }
      const body = {
        username: form.value.username,
        account: form.value.account,
        branch_id: form.value.branch_id,
        multi_branches: form.value.multi_branches || [],
        status: form.value.status,
        phone: form.value.phone || null,
        line_id: form.value.line_id || null,
        rfid: form.value.rfid || null,
        subject_ids: subjectIds,
        subject_level_scopes: form.value.subject_level_scopes || []
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
      const body = {
        name: form.value.username,
        account: form.value.account,
        password: form.value.password,
        role: 'teacher',
        campus_id: form.value.branch_id,
        branch_id: form.value.branch_id,
        multi_branches: form.value.multi_branches || [],
        status: form.value.status,
        phone: form.value.phone || null,
        line_id: form.value.line_id || null,
        subject_ids: subjectIds,
        subject_level_scopes: form.value.subject_level_scopes || []
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
const activeDropdown = ref(null);
function toggleDropdown(teacherId) {
  activeDropdown.value = activeDropdown.value === teacherId ? null : teacherId;
}
function closeDropdown() {
  activeDropdown.value = null;
}
function avatarStyle(name) {
  const hue = (name?.charCodeAt(0) ?? 0) * 37 % 360;
  return { background: `hsl(${hue}, 60%, 55%)` };
}

onMounted(() => {
  loadAllBranchOptions();
  loadTeachers();
  loadSubjects();
  window.addEventListener('click', closeDropdown);
});
onUnmounted(() => {
  window.removeEventListener('click', closeDropdown);
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
  border: 1px solid #edf2f7;
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
  color: #1f2937;
}

.title-sub {
  margin-top: 4px;
  font-size: 13px;
  color: #64748b;
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
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
  padding: 12px 14px;
}

.summary-label {
  font-size: 12px;
  color: #607d8b;
}

.summary-value {
  font-size: 24px;
  line-height: 1.25;
  font-weight: 700;
  color: #1e293b;
}

.summary-value.active { color: #2e7d32; }
.summary-value.pending { color: #ef6c00; }

.filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
  margin-bottom: 18px;
  padding: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: #f8fafc;
}

.filter-item {
  min-width: 150px;
}

.filter-item-search {
  min-width: 260px;
  flex: 1;
}
.filter-row label {
  display: block;
  font-size: 12px;
  color: #64748b;
  margin-bottom: 6px;
  font-weight: 600;
}
.filter-row input, .filter-row select {
  padding: 9px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  min-width: 140px;
  font-size: 14px;
  background: #fff;
}

.tabs {
    display: flex;
    gap: 8px;
}
.tabs button {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
    color: #475569;
}
.tabs button.active {
    border-color: #fb923c;
    color: #c2410c;
    background: #fff7ed;
    font-weight: 700;
}

.teacher-table {
  width: 100%;
  border-collapse: collapse;
}

.table-wrap {
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  overflow: auto;
  background: #fff;
}

.teacher-table th, .teacher-table td {
  padding: 12px;
  border-bottom: 1px solid #f1f5f9;
  text-align: left;
  vertical-align: top;
  font-size: 14px;
  line-height: 1.5;
}

.teacher-table thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  background: #f8fafc;
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.teacher-table tbody tr:nth-child(even) {
  background: #fcfdff;
}

.teacher-table tbody tr:hover {
  background: #f8fbff;
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
.status-tag.active { background: #e8f5e9; color: #2e7d32; }
.status-tag.pending { background: #fff3e0; color: #ef6c00; }
.status-tag.suspended { background: #ffebee; color: #c62828; }

button.small.warning {
  background: #fff7ed;
  border: 1px solid #fdba74;
  color: #c2410c;
}

button.small.danger {
  background: #fef2f2;
  border: 1px solid #fca5a5;
  color: #b91c1c;
}

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
.modal-wide {
  width: min(920px, 94vw);
}
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-weight: bold; }
.form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
.form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; resize: vertical; font-family: monospace; }
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
  background: #eff6ff;
  color: #1d4ed8;
  padding: 3px 9px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  margin: 2px 4px 2px 0;
  white-space: nowrap;
}

.no-support {
  color: #999;
  font-size: 0.85em;
}

.empty-state {
  border: 1px dashed #cfd8dc;
  border-radius: 10px;
  background: #fafbfc;
  color: #607d8b;
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
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 12px;
}

.bulk-preview-title {
  font-size: 13px;
  color: #555;
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

/* ── 卡片網格 ── */
.teacher-cards-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}
@media (max-width: 1200px) { .teacher-cards-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px)  { .teacher-cards-grid { grid-template-columns: 1fr; } }

/* ── 單張卡片 ── */
.teacher-card {
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(15,23,42,0.05);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: box-shadow 0.2s, transform 0.15s;
}
.teacher-card:hover {
  box-shadow: 0 6px 20px rgba(15,23,42,0.10);
  transform: translateY(-2px);
}

/* ── Card Header ── */
.tc-header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px 16px 12px;
  border-bottom: 1px solid #f1f5f9;
}
.tc-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  color: #fff;
  font-size: 20px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.tc-identity { flex: 1; min-width: 0; }
.tc-name { font-size: 16px; font-weight: 700; color: #1e293b; }
.tc-account { font-size: 12px; color: #64748b; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tc-phone { font-size: 12px; color: #64748b; margin-top: 2px; }

/* ── Card Body ── */
.tc-body {
  padding: 12px 16px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.tc-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 13px;
}
.tc-icon { flex-shrink: 0; font-size: 14px; line-height: 1.6; }
.tc-rfid { cursor: pointer; }
.tc-rfid:hover .rfid-tag { text-decoration: underline; }
.main-branch {
  background: #fff7ed;
  color: #c2410c;
  border: 1px solid #fdba74;
}
.subject-tag { background: #f0fdf4; color: #16a34a; }

/* ── Card Footer ── */
.tc-footer {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 16px;
  border-top: 1px solid #f1f5f9;
  background: #fafbfc;
}
.tc-dropdown { position: relative; margin-left: auto; }
.tc-more-btn { min-width: 32px; font-size: 18px; line-height: 1; padding: 4px 8px; }
.tc-dropdown-menu {
  position: absolute;
  right: 0;
  bottom: calc(100% + 4px);
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(15,23,42,0.12);
  min-width: 130px;
  z-index: 10;
  overflow: hidden;
}
.tc-dropdown-menu button {
  width: 100%;
  text-align: left;
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 500;
  background: none;
  border: none;
  border-radius: 0;
  cursor: pointer;
  color: #374151;
  display: block;
}
.tc-dropdown-menu button:hover { background: #f8fafc; }
.tc-dropdown-menu button.danger { color: #b91c1c; }
.tc-dropdown-menu button.danger:hover { background: #fef2f2; }
.tc-dropdown-menu button.approve { color: #15803d; }
.tc-dropdown-menu button.approve:hover { background: #f0fdf4; }

.scope-matrix {
  width: 100%;
  border-collapse: collapse;
  margin-top: 8px;
  font-size: 13px;
}
.scope-matrix th,
.scope-matrix td {
  padding: 8px 10px;
  border: 1px solid #e2e8f0;
  text-align: center;
}
.scope-matrix th {
  background: #f8fafc;
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
}
.scope-subj-name {
  text-align: left !important;
  font-weight: 600;
  color: #1e293b;
}
.scope-cell input[type="checkbox"] {
  width: 18px;
  height: 18px;
  cursor: pointer;
  accent-color: #1565C0;
}
.scope-check-label {
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.scope-toggle-all {
  font-size: 11px;
  padding: 2px 8px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #f8fafc;
  cursor: pointer;
  color: #475569;
  white-space: nowrap;
}
.scope-toggle-all:hover {
  background: #e2e8f0;
}
</style>
