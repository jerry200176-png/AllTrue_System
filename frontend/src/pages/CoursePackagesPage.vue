<template>
  <div class="packages-page">
    <div class="card packages-header-card">
      <div class="card-header-row">
        <h2 class="page-title">
          <span class="material-symbols-outlined title-icon">inventory_2</span>
          多科共用方案
        </h2>
        <div class="header-actions">
          <button class="btn btn-primary btn-sm" @click="showCreateModal = true">
            <span class="material-symbols-outlined">add</span>
            建立方案
          </button>
        </div>
      </div>
      <p class="page-subtitle">管理學生的多科共用堂數方案。一個方案下可包含多科、多師，堂數共用扣減。</p>
    </div>

    <div class="filter-bar">
      <div class="filter-group">
        <label>搜尋學生</label>
        <input
          v-model="studentSearch"
          type="text"
          class="form-control form-control-sm"
          placeholder="輸入學生姓名..."
          @input="debouncedLoad"
        />
      </div>
      <div class="filter-group">
        <label>狀態</label>
        <select v-model="statusFilter" class="form-control form-control-sm" @change="loadPackages">
          <option value="active">使用中</option>
          <option value="all">全部</option>
          <option value="stopped">已暫停/結算</option>
        </select>
      </div>
    </div>

    <transition name="slide-down">
      <div
        v-if="!loading && packages.length && incompletePackageCount > 0"
        class="health-stats-bar incomplete"
        role="button"
        tabindex="0"
        @click="toggleHealthFilter"
        @keydown.enter="toggleHealthFilter"
        @keydown.space.prevent="toggleHealthFilter"
        :aria-pressed="scheduleHealthFilter === 'incomplete'"
      >
        <span class="material-symbols-outlined">warning</span>
        <span>{{ incompletePackageCount }} 個方案排課不完整</span>
        <span class="stats-bar-hint">
          {{ scheduleHealthFilter === 'incomplete' ? '（已篩選，點擊清除）' : '（點擊僅顯示）' }}
        </span>
      </div>
      <div
        v-else-if="!loading && packages.length"
        class="health-stats-bar complete"
      >
        <span class="material-symbols-outlined">check_circle</span>
        <span>所有方案排課已完整設定</span>
      </div>
    </transition>

    <div v-if="loading" class="loading-state">
      <div class="spinner"></div>
      <span>載入中...</span>
    </div>

    <div v-else-if="!packages.length" class="empty-state">
      <span class="material-symbols-outlined empty-icon">folder_open</span>
      <p>尚無方案，請點「建立方案」開始。</p>
    </div>

    <div v-else class="packages-list">
      <div
        v-for="pkg in filteredPackages"
        :key="pkg.id"
        class="card package-card"
        :class="{ 'package-stopped': pkg.stop }"
      >
        <div class="package-header" @click="toggleExpand(pkg.id)">
          <div class="package-info">
            <span class="package-name">{{ pkg.name }}</span>
            <span
              v-if="pkg.billing_mode !== 'date'"
              class="health-badge"
              :class="`health-${healthLevel(pkg)}`"
              :title="healthTooltip(pkg)"
            >
              <span class="material-symbols-outlined">{{ healthIcon(healthLevel(pkg)) }}</span>
            </span>
            <span class="student-tag">{{ pkg.student_name }}</span>
            <span v-if="pkg.stop" class="badge badge-warning">已暫停</span>
            <span v-else-if="pkg.remaining_sessions <= 2 && pkg.remaining_sessions > 0" class="badge badge-alert">剩餘不足</span>
            <span v-else-if="pkg.remaining_sessions === 0" class="badge badge-danger">堂數用完</span>
            <span v-if="pkg.paid" class="badge badge-paid">已繳</span>
            <span v-else class="badge badge-unpaid">未繳</span>
          </div>
          <div class="package-counters">
            <div class="counter">
              <span class="counter-value">{{ pkg.remaining_sessions }}</span>
              <span class="counter-label">剩餘</span>
            </div>
            <div class="counter-divider">/</div>
            <div class="counter">
              <span class="counter-value">{{ pkg.total_sessions }}</span>
              <span class="counter-label">總堂</span>
            </div>
            <div class="counter" style="margin-left:12px">
              <span class="counter-value">{{ pkg.used_sessions }}</span>
              <span class="counter-label">已用</span>
            </div>
          </div>
          <span class="material-symbols-outlined expand-icon" :class="{ rotated: expandedId === pkg.id }">expand_more</span>
        </div>

        <transition name="slide">
          <div v-if="expandedId === pkg.id" class="package-detail">
            <div class="members-section">
              <h4>科目與老師</h4>
              <div class="members-grid">
                <div v-for="m in pkg.members" :key="m.student_class_id" class="member-chip">
                  <div class="member-chip-row">
                    <span class="member-subject">{{ m.subject || '未設定科目' }}</span>
                    <span class="member-teacher">{{ m.teacher_name }}</span>
                    <span v-if="m.stop" class="badge badge-warning badge-sm">停</span>
                  </div>
                  <div v-if="pkg.billing_mode !== 'date'" class="member-chip-row member-chip-status-row">
                    <span
                      class="member-status-tag"
                      :class="`status-${memberStatusLevel(m, pkg)}`"
                      :title="memberStatusTooltip(m, pkg)"
                      :style="memberStatusLevel(m, pkg) === 'unscheduled' ? 'cursor:pointer;' : ''"
                      @click.stop="memberStatusLevel(m, pkg) === 'unscheduled' ? goSetSchedule(m) : null"
                    >
                      {{ memberStatusLabel(m, pkg) }}
                    </span>
                    <span v-if="(m.next_sessions ?? []).length" class="member-next-sessions">
                      {{ (m.next_sessions ?? []).join(' · ') }}
                    </span>
                    <span v-else class="member-next-sessions member-next-empty">-</span>
                  </div>
                </div>
              </div>
            </div>
            <div class="progress-bar-wrap">
              <div class="progress-bar">
                <div
                  class="progress-fill"
                  :style="{ width: progressPct(pkg) + '%' }"
                  :class="progressClass(pkg)"
                ></div>
              </div>
              <span class="progress-label">{{ progressPct(pkg) }}% 已使用</span>
            </div>
            <div class="detail-actions">
              <button class="btn btn-outline btn-xs" @click="viewDetail(pkg.id)">
                <span class="material-symbols-outlined">visibility</span>
                詳細
              </button>
              <button v-if="pkg.billing_mode !== 'date'" class="btn btn-primary btn-xs" @click="openEditModal(pkg)">
                <span class="material-symbols-outlined">edit</span>
                編輯 / 加購堂數
              </button>
              <button class="btn btn-outline btn-xs" @click="triggerRecompute(pkg.id)">
                <span class="material-symbols-outlined">refresh</span>
                重算餘額
              </button>
              <button v-if="!pkg.stop" class="btn btn-outline btn-xs" @click="togglePause(pkg)">
                <span class="material-symbols-outlined">pause</span>
                暫停
              </button>
              <button v-else class="btn btn-outline btn-xs" @click="togglePause(pkg)">
                <span class="material-symbols-outlined">play_arrow</span>
                恢復
              </button>
            </div>

            <div v-if="detailData && detailData.id === pkg.id" class="detail-breakdown">
              <h4>各科消耗明細</h4>
              <table class="mini-table">
                <thead>
                  <tr><th>科目</th><th>老師</th><th>已用堂</th></tr>
                </thead>
                <tbody>
                  <tr v-for="dm in detailData.members" :key="dm.student_class_id">
                    <td>{{ dm.subject }}</td>
                    <td>{{ dm.teacher_name }}</td>
                    <td class="text-center">{{ dm.subject_used }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </transition>
      </div>
    </div>

    <!-- Create Package Modal -->
    <teleport to="body">
      <div v-if="showCreateModal" class="modal-overlay" @click.self="showCreateModal = false">
        <div class="modal-box modal-lg">
          <div class="modal-header">
            <h3>建立多科共用方案</h3>
            <button class="modal-close" @click="showCreateModal = false">&times;</button>
          </div>
          <div class="modal-body">
            <div class="form-row">
              <div class="form-group flex-1">
                <label>學生 <span class="required">*</span></label>
                <select v-model="form.student_id" class="form-control">
                  <option value="">請選擇</option>
                  <option v-for="s in studentOptions" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
              </div>
              <div class="form-group flex-1">
                <label>方案名稱 <span class="required">*</span></label>
                <input v-model="form.name" class="form-control" placeholder="例：國英數24堂方案" />
              </div>
            </div>
            <div class="form-row">
              <div class="form-group flex-1">
                <label>共用總堂數 <span class="required">*</span></label>
                <input v-model.number="form.total_sessions" type="number" class="form-control" min="1" />
              </div>
              <div class="form-group flex-1">
                <label>每堂費率</label>
                <input v-model.number="form.rate" type="number" class="form-control" min="0" step="10" />
              </div>
              <div class="form-group">
                <label>上課類型</label>
                <select v-model="form.class_type" class="form-control">
                  <option value="one_on_one">一對一</option>
                  <option value="one_on_two">一對二</option>
                  <option value="one_on_three">一對三</option>
                  <option value="tutoring">輔導</option>
                </select>
              </div>
            </div>

            <h4 class="section-label">科目與老師設定</h4>
            <div v-for="(subj, idx) in form.subjects" :key="idx" class="subject-block">
              <div class="subject-row">
                <div class="form-group flex-1">
                  <label>科目</label>
                  <select v-model="subj.subject_id" class="form-control">
                    <option value="">請選擇</option>
                    <option v-for="s in subjectOptions" :key="s.id" :value="s.id">{{ s.name }}</option>
                  </select>
                </div>
                <div class="form-group flex-1">
                  <label>老師</label>
                  <select v-model="subj.teacher_id" class="form-control">
                    <option value="">請選擇</option>
                    <option v-for="t in teacherOptions" :key="t.id" :value="t.id">{{ t.name }}</option>
                  </select>
                </div>
                <div class="form-group" style="width:100px">
                  <label>時長(小時)</label>
                  <input v-model.number="subj.duration_hours" type="number" class="form-control" min="0.5" step="0.5" />
                </div>
                <button v-if="form.subjects.length > 1" class="btn btn-icon btn-danger-text" @click="removeSubject(idx)">
                  <span class="material-symbols-outlined">remove_circle</span>
                </button>
              </div>
              <div class="schedule-row">
                <div class="form-group">
                  <label>上課星期（可選填，可複選，最多 6 個）</label>
                  <div class="weekday-chips">
                    <button
                      v-for="d in dayOptions"
                      :key="d.value"
                      type="button"
                      class="weekday-chip"
                      :class="{ selected: (subj.days_of_week ?? []).includes(d.value) }"
                      :disabled="!(subj.days_of_week ?? []).includes(d.value) && (subj.days_of_week ?? []).length >= 6"
                      @click="toggleSubjectDay(subj, d.value)"
                    >
                      {{ d.label }}
                    </button>
                  </div>
                </div>
                <div class="form-group" style="width:140px">
                  <label>上課時間</label>
                  <select v-model="subj.start_time" class="form-control">
                    <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
                  </select>
                </div>
              </div>
              <p class="schedule-hint">
                {{ (subj.days_of_week ?? []).length
                  ? `此科將於每週${formatDaysLabel(subj.days_of_week)} ${subj.start_time || '16:00'} 自動產生排課`
                  : '未填上課星期時，建立後不產生排課（可稍後至課程管理頁補填）。' }}
              </p>
            </div>
            <button class="btn btn-text btn-sm" @click="addSubject">
              <span class="material-symbols-outlined">add_circle</span>
              新增科目
            </button>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" @click="showCreateModal = false">取消</button>
            <button class="btn btn-primary" :disabled="creating" @click="submitCreate">
              {{ creating ? '建立中...' : '確認建立' }}
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <!-- Edit Package Modal -->
    <teleport to="body">
      <div v-if="showEditModal" class="modal-overlay" @click.self="closeEditModal">
        <div class="modal-box modal-lg">
          <div class="modal-header">
            <h3>編輯方案 — {{ editPkgSnapshot?.name }}</h3>
            <button class="modal-close" @click="closeEditModal">&times;</button>
          </div>
          <div class="modal-body">
            <div class="form-row">
              <div class="form-group flex-1">
                <label>方案名稱</label>
                <input v-model="editForm.name" class="form-control" />
              </div>
              <div class="form-group flex-1">
                <label>總堂數 <span class="required">*</span></label>
                <input v-model.number="editForm.total_sessions" type="number" class="form-control" min="1" />
                <p class="inline-hint">
                  目前已用 {{ editPkgSnapshot?.used_sessions ?? 0 }} 堂。修改後剩餘將重新計算。
                </p>
                <p v-if="editTotalBelowUsed" class="inline-warn">
                  新總堂數不可小於已使用堂數（{{ editPkgSnapshot?.used_sessions ?? 0 }} 堂）
                </p>
                <p v-else-if="editTotalWillDecrease" class="inline-note">
                  減少堂數將取消最後 {{ editDecreaseDelta }} 堂未來排課，儲存前會再次確認。
                </p>
                <p v-else-if="editTotalWillIncrease" class="inline-ok">
                  加購 {{ editIncreaseDelta }} 堂：儲存後所有科目的排課會自動補排。
                </p>
              </div>
              <div class="form-group flex-1">
                <label>每堂費率</label>
                <input v-model.number="editForm.rate" type="number" class="form-control" min="0" step="10" />
                <p class="inline-hint">費率僅影響未來計費，不會覆寫已手動調整的金額。</p>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" @click="closeEditModal" :disabled="saving">取消</button>
            <button
              class="btn btn-primary"
              :disabled="saving || editTotalBelowUsed || !editForm.total_sessions"
              @click="submitEdit"
            >
              {{ saving ? '儲存中...' : '儲存' }}
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <!-- Schedule Summary Dialog (after createMultiSubject) -->
    <teleport to="body">
      <div v-if="showSummaryModal" class="modal-overlay" @click.self="closeSummary">
        <div class="modal-box modal-lg">
          <div class="modal-header">
            <h3>方案建立完成</h3>
            <button class="modal-close" @click="closeSummary">&times;</button>
          </div>
          <div class="modal-body">
            <div v-if="allSummariesEmpty" class="summary-empty">
              <span class="material-symbols-outlined summary-empty-icon">event_busy</span>
              <p>所有科目未設定上課時段，因此未自動產生排課。</p>
              <p class="summary-empty-sub">您可以至「課程管理」為每科設定排課時段；設定後系統會自動補排。</p>
              <button class="btn btn-primary btn-sm" @click="goCourseManagement">
                <span class="material-symbols-outlined">arrow_forward</span>
                前往課程管理設定
              </button>
            </div>
            <div v-else class="summary-list">
              <p class="summary-intro">以下是各科排課結果：</p>
              <ul>
                <li v-for="(item, i) in createSummaryData" :key="i" class="summary-item">
                  <div class="summary-subject">{{ item.subject || '未設定科目' }}</div>
                  <div v-if="item.scheduled_count > 0" class="summary-detail ok">
                    <span class="material-symbols-outlined">check_circle</span>
                    已排 {{ item.scheduled_count }} 堂
                    <span v-if="item.first_session_date" class="summary-first-date">
                      （首堂 {{ item.first_session_date }}）
                    </span>
                  </div>
                  <div v-else class="summary-detail warn">
                    <span class="material-symbols-outlined">info</span>
                    尚未設定排課時段
                    <a class="summary-link" href="javascript:void(0)" @click="goSetScheduleById(item.student_class_id)">
                      前往設定
                    </a>
                  </div>
                </li>
              </ul>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-primary" @click="closeSummary">完成</button>
          </div>
        </div>
      </div>
    </teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import {
  listPackages,
  getPackage,
  createMultiSubjectPackage,
  updatePackage,
  recomputePackage,
} from '../lib/coursePackagesApi.js';

const props = defineProps({
  branchId: [String, Number],
});

const loading = ref(false);
const packages = ref([]);
const expandedId = ref(null);
const detailData = ref(null);
const showCreateModal = ref(false);
const creating = ref(false);
const studentSearch = ref('');
const statusFilter = ref('active');
const scheduleHealthFilter = ref(null); // null | 'incomplete'
const studentOptions = ref([]);
const teacherOptions = ref([]);
const subjectOptions = ref([]);

const showSummaryModal = ref(false);
const createSummaryData = ref([]);

// ISO 1=Mon … 7=Sun，沿用 StudentsList.vue 的規格
const dayOptions = [
  { value: 1, label: '一' }, { value: 2, label: '二' }, { value: 3, label: '三' },
  { value: 4, label: '四' }, { value: 5, label: '五' }, { value: 6, label: '六' }, { value: 7, label: '日' },
];

function buildTimeOptions() {
  const out = [];
  for (let h = 6; h <= 22; h++) {
    for (const m of [0, 30]) {
      out.push(`${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`);
    }
  }
  return out;
}
const timeOptions = buildTimeOptions();

const form = ref(emptyForm());

const showEditModal = ref(false);
const saving = ref(false);
const editPkgSnapshot = ref(null);
const editForm = ref({ name: '', total_sessions: 0, rate: 0 });

const editTotalBelowUsed = computed(() => {
  const used = Number(editPkgSnapshot.value?.used_sessions ?? 0);
  const nv = Number(editForm.value.total_sessions);
  return Number.isFinite(nv) && nv > 0 && nv < used;
});
const editTotalWillDecrease = computed(() => {
  const oldV = Number(editPkgSnapshot.value?.total_sessions ?? 0);
  const nv = Number(editForm.value.total_sessions);
  return Number.isFinite(nv) && nv > 0 && nv < oldV;
});
const editTotalWillIncrease = computed(() => {
  const oldV = Number(editPkgSnapshot.value?.total_sessions ?? 0);
  const nv = Number(editForm.value.total_sessions);
  return Number.isFinite(nv) && nv > oldV;
});
const editDecreaseDelta = computed(() => {
  const oldV = Number(editPkgSnapshot.value?.total_sessions ?? 0);
  const nv = Number(editForm.value.total_sessions);
  return Math.max(0, oldV - (Number.isFinite(nv) ? nv : oldV));
});
const editIncreaseDelta = computed(() => {
  const oldV = Number(editPkgSnapshot.value?.total_sessions ?? 0);
  const nv = Number(editForm.value.total_sessions);
  return Math.max(0, (Number.isFinite(nv) ? nv : oldV) - oldV);
});

function openEditModal(pkg) {
  editPkgSnapshot.value = { ...pkg };
  editForm.value = {
    name: pkg.name || '',
    total_sessions: Number(pkg.total_sessions ?? 0),
    rate: Number(pkg.rate ?? 0),
  };
  showEditModal.value = true;
}

function closeEditModal() {
  if (saving.value) return;
  showEditModal.value = false;
  editPkgSnapshot.value = null;
}

async function submitEdit() {
  if (!editPkgSnapshot.value) return;
  if (editTotalBelowUsed.value) return;

  if (editTotalWillDecrease.value) {
    const msg = `減少後將取消 ${editDecreaseDelta.value} 堂未來排課，確認繼續？`;
    if (!confirm(msg)) return;
  }

  saving.value = true;
  try {
    const payload = {
      name: editForm.value.name,
      total_sessions: Number(editForm.value.total_sessions),
      rate: Number(editForm.value.rate || 0),
    };
    const res = await updatePackage(editPkgSnapshot.value.id, payload);
    const extended = Number(res?.extended_count ?? 0);
    const cancelled = Array.isArray(res?.cancelled_sessions) ? res.cancelled_sessions.length : 0;
    let summary = '方案已更新，排課同步完成';
    if (extended > 0) summary += `（補排 ${extended} 堂）`;
    if (cancelled > 0) summary += `（取消 ${cancelled} 堂未來排課，請手動通知家長）`;
    alert(summary);
    showEditModal.value = false;
    editPkgSnapshot.value = null;
    await loadPackages();
  } catch (e) {
    alert('儲存失敗：' + (e?.message || e));
  } finally {
    saving.value = false;
  }
}

function emptySubject() {
  return {
    subject_id: '',
    teacher_id: '',
    duration_hours: 2,
    days_of_week: [],
    start_time: '16:00',
  };
}

function emptyForm() {
  return {
    student_id: '',
    name: '',
    total_sessions: 24,
    rate: 0,
    class_type: 'one_on_one',
    subjects: [emptySubject()],
  };
}

function addSubject() {
  form.value.subjects.push(emptySubject());
}

function removeSubject(idx) {
  form.value.subjects.splice(idx, 1);
}

function toggleSubjectDay(subject, value) {
  if (!Array.isArray(subject.days_of_week)) subject.days_of_week = [];
  const i = subject.days_of_week.indexOf(value);
  if (i >= 0) {
    subject.days_of_week.splice(i, 1);
  } else if (subject.days_of_week.length < 6) {
    subject.days_of_week.push(value);
    subject.days_of_week.sort((a, b) => a - b);
  }
}

function formatDaysLabel(days) {
  if (!Array.isArray(days) || !days.length) return '';
  const map = { 1: '一', 2: '二', 3: '三', 4: '四', 5: '五', 6: '六', 7: '日' };
  return days.slice().sort((a, b) => a - b).map(d => map[d] ?? '').join('、');
}

const filteredPackages = computed(() => {
  let list = packages.value;
  if (studentSearch.value.trim()) {
    const q = studentSearch.value.trim().toLowerCase();
    list = list.filter(p => (p.student_name || '').toLowerCase().includes(q) || (p.name || '').toLowerCase().includes(q));
  }
  if (statusFilter.value === 'active') {
    list = list.filter(p => !p.stop && p.enabled);
  } else if (statusFilter.value === 'stopped') {
    list = list.filter(p => p.stop);
  }
  if (scheduleHealthFilter.value === 'incomplete') {
    list = list.filter(p => p.billing_mode !== 'date' && healthLevel(p) !== 'green');
  }
  return list;
});

// ── 排課健康度 / 成員狀態 helpers ──
function memberStatusLevel(m, pkg) {
  const total = Number(pkg.total_sessions ?? 0);
  const scheduled = Number(m.scheduled_count ?? 0);
  if (total <= 0) return 'ok';
  if (scheduled >= total) return 'ok';
  if (scheduled > 0) return 'partial';
  return 'unscheduled';
}

function memberStatusLabel(m, pkg) {
  const level = memberStatusLevel(m, pkg);
  const scheduled = Number(m.scheduled_count ?? 0);
  const total = Number(pkg.total_sessions ?? 0);
  if (level === 'ok') return `已排 ${scheduled}/${total}`;
  if (level === 'partial') return `已排 ${scheduled}/${total}`;
  return '未排定';
}

function memberStatusTooltip(m, pkg) {
  const level = memberStatusLevel(m, pkg);
  if (level === 'unscheduled') return '點擊前往設定排課時段';
  if (level === 'partial') return '排課未達總堂數，可至課程管理頁補排';
  return '排課已完整';
}

function healthLevel(pkg) {
  if (!pkg || pkg.billing_mode === 'date') return 'green';
  const members = Array.isArray(pkg.members) ? pkg.members : [];
  if (!members.length) return 'green';
  const total = Number(pkg.total_sessions ?? 0);
  if (total <= 0) return 'green';
  let allReady = true;
  let allZero = true;
  for (const m of members) {
    const s = Number(m.scheduled_count ?? 0);
    if (s < total) allReady = false;
    if (s > 0) allZero = false;
  }
  if (allReady) return 'green';
  if (allZero) return 'red';
  return 'orange';
}

function healthIcon(level) {
  if (level === 'green') return 'check';
  if (level === 'red') return 'close';
  return 'priority_high';
}

function healthTooltip(pkg) {
  const members = Array.isArray(pkg.members) ? pkg.members : [];
  const total = Number(pkg.total_sessions ?? 0);
  const ready = members.filter(m => Number(m.scheduled_count ?? 0) >= total).length;
  const unready = members.length - ready;
  return `${ready} 科已排齊 / ${unready} 科未排齊`;
}

const incompletePackageCount = computed(() => {
  return (packages.value || []).filter(p => {
    if (p.billing_mode === 'date') return false;
    if (p.stop || !p.enabled) return false;
    return healthLevel(p) !== 'green';
  }).length;
});

function toggleHealthFilter() {
  scheduleHealthFilter.value = scheduleHealthFilter.value === 'incomplete' ? null : 'incomplete';
}

function goSetSchedule(m) {
  if (!m?.student_class_id) return;
  if (!confirm('即將前往課程管理頁，是否繼續？')) return;
  window.location.href = `/course-management?student_class_id=${m.student_class_id}`;
}

function goSetScheduleById(studentClassId) {
  if (!studentClassId) return;
  window.location.href = `/course-management?student_class_id=${studentClassId}`;
}

function goCourseManagement() {
  window.location.href = '/course-management';
}

function progressPct(pkg) {
  if (!pkg.total_sessions) return 0;
  return Math.min(100, Math.round((pkg.used_sessions / pkg.total_sessions) * 100));
}

function progressClass(pkg) {
  const pct = progressPct(pkg);
  if (pct >= 90) return 'progress-danger';
  if (pct >= 70) return 'progress-warning';
  return 'progress-ok';
}

function toggleExpand(id) {
  expandedId.value = expandedId.value === id ? null : id;
  detailData.value = null;
}

async function loadPackages() {
  loading.value = true;
  try {
    const bid = props.branchId ? Number(props.branchId) : undefined;
    packages.value = await listPackages({
      branchId: bid,
      activeOnly: statusFilter.value === 'active',
    });
  } catch (e) {
    console.error('Load packages failed', e);
    packages.value = [];
  } finally {
    loading.value = false;
  }
}

let debounceTimer;
function debouncedLoad() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(loadPackages, 300);
}

async function viewDetail(id) {
  try {
    detailData.value = await getPackage(id);
  } catch (e) {
    alert('無法載入方案詳細：' + e.message);
  }
}

async function triggerRecompute(id) {
  if (!confirm('確定要重算此方案的餘額？')) return;
  try {
    const res = await recomputePackage(id);
    alert(`重算完成：剩餘 ${res.remaining_sessions} 堂`);
    await loadPackages();
  } catch (e) {
    alert('重算失敗：' + e.message);
  }
}

async function togglePause(pkg) {
  const newStop = !pkg.stop;
  const label = newStop ? '暫停' : '恢復';
  if (!confirm(`確定${label}「${pkg.name}」？`)) return;
  try {
    await updatePackage(pkg.id, { stop: newStop });
    await loadPackages();
  } catch (e) {
    alert(`${label}失敗：` + e.message);
  }
}

async function loadFormOptions() {
  const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';
  const raw = localStorage.getItem('alltrue_session');
  let token = '';
  try {
    const parsed = JSON.parse(raw);
    token = parsed?.access_token || parsed?.token || raw;
  } catch { token = raw || ''; }
  const headers = { Authorization: `Bearer ${token}` };

  const bid = props.branchId ? Number(props.branchId) : '';
  try {
    const [studentsRes, teachersRes, subjectsRes] = await Promise.all([
      fetch(`${API_BASE}/students?branch_id=${bid}&per_page=500`, { headers }),
      fetch(`${API_BASE}/teachers?branch_id=${bid}`, { headers }),
      fetch(`${API_BASE}/subjects`, { headers }),
    ]);
    const sData = await studentsRes.json().catch(() => ({}));
    const tData = await teachersRes.json().catch(() => ({}));
    const sjData = await subjectsRes.json().catch(() => []);

    studentOptions.value = (sData.data || sData || []).map(s => ({
      id: Number(s.id),
      name: s.name || s.Name || '',
    }));
    teacherOptions.value = (Array.isArray(tData) ? tData : tData.data || []).map(t => ({
      id: Number(t.id),
      name: t.Name || t.name || '',
    }));
    subjectOptions.value = (Array.isArray(sjData) ? sjData : sjData.data || []).map(s => ({
      id: Number(s.id),
      name: s.Subject_Name || s.name || '',
    }));
  } catch (e) {
    console.error('loadFormOptions', e);
  }
}

async function submitCreate() {
  if (!form.value.student_id) { alert('請選擇學生'); return; }
  if (!form.value.name.trim()) { alert('請輸入方案名稱'); return; }
  if (!form.value.total_sessions || form.value.total_sessions < 1) { alert('請輸入總堂數'); return; }
  const validSubjects = form.value.subjects.filter(s => s.subject_id && s.teacher_id);
  if (!validSubjects.length) { alert('請至少設定一個科目與老師'); return; }

  creating.value = true;
  try {
    const payload = {
      student_id: Number(form.value.student_id),
      branch_id: Number(props.branchId),
      name: form.value.name.trim(),
      total_sessions: form.value.total_sessions,
      rate: form.value.rate || 0,
      class_type: form.value.class_type,
      subjects: validSubjects.map(s => {
        const item = {
          subject_id: Number(s.subject_id),
          teacher_id: Number(s.teacher_id),
          duration_hours: Number(s.duration_hours) || 2,
        };
        const days = Array.isArray(s.days_of_week) ? s.days_of_week.filter(d => d >= 1 && d <= 7) : [];
        if (days.length) {
          item.days_of_week = days;
          item.start_time = s.start_time || '16:00';
        }
        return item;
      }),
    };
    const res = await createMultiSubjectPackage(payload);
    createSummaryData.value = Array.isArray(res?.members_scheduled) ? res.members_scheduled : [];
    showCreateModal.value = false;
    form.value = emptyForm();
    showSummaryModal.value = true;
    await loadPackages();
  } catch (e) {
    alert('建立失敗：' + e.message);
  } finally {
    creating.value = false;
  }
}

const allSummariesEmpty = computed(() => {
  if (!createSummaryData.value.length) return true;
  return createSummaryData.value.every(m => Number(m.scheduled_count ?? 0) === 0);
});

function closeSummary() {
  showSummaryModal.value = false;
  createSummaryData.value = [];
}

watch(() => props.branchId, () => {
  loadPackages();
  loadFormOptions();
});

onMounted(() => {
  loadPackages();
  loadFormOptions();
});
</script>

<style scoped>
.packages-page { max-width: 960px; margin: 0 auto; padding: 16px; }

.packages-header-card { padding: 20px; margin-bottom: 16px; }
.card-header-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.page-title { display: flex; align-items: center; gap: 8px; font-size: 1.25rem; font-weight: 700; margin: 0; }
.title-icon { font-size: 1.4rem; color: var(--primary, #4f46e5); }
.page-subtitle { margin: 8px 0 0; color: #64748b; font-size: 0.85rem; }

.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 160px; }
.filter-group label { font-size: 0.75rem; font-weight: 600; color: #64748b; }
.form-control { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.875rem; }
.form-control-sm { padding: 4px 8px; font-size: 0.8rem; }

.loading-state { display: flex; align-items: center; gap: 10px; justify-content: center; padding: 40px; color: #64748b; }
.spinner { width: 20px; height: 20px; border: 2px solid #e2e8f0; border-top-color: var(--primary, #4f46e5); border-radius: 50%; animation: spin 0.6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.empty-state { text-align: center; padding: 48px 16px; color: #94a3b8; }
.empty-icon { font-size: 3rem; opacity: 0.4; }

.packages-list { display: flex; flex-direction: column; gap: 10px; }

.package-card { border-radius: 10px; overflow: hidden; transition: box-shadow 0.2s; }
.package-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
.package-stopped { opacity: 0.7; }

.package-header { display: flex; align-items: center; gap: 12px; padding: 14px 16px; cursor: pointer; user-select: none; }
.package-info { flex: 1; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.package-name { font-weight: 600; font-size: 0.95rem; }
.student-tag { background: #f1f5f9; padding: 2px 8px; border-radius: 10px; font-size: 0.78rem; color: #475569; }

.badge { padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 600; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-alert { background: #fed7aa; color: #9a3412; }
.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-paid { background: #d1fae5; color: #065f46; }
.badge-unpaid { background: #fee2e2; color: #991b1b; }
.badge-sm { font-size: 0.65rem; padding: 1px 5px; }

.package-counters { display: flex; align-items: center; gap: 4px; }
.counter { text-align: center; }
.counter-value { display: block; font-size: 1.1rem; font-weight: 700; color: #1e293b; }
.counter-label { font-size: 0.65rem; color: #94a3b8; }
.counter-divider { font-size: 1rem; color: #cbd5e1; }

.expand-icon { font-size: 1.3rem; transition: transform 0.2s; color: #94a3b8; }
.expand-icon.rotated { transform: rotate(180deg); }

.package-detail { padding: 0 16px 16px; }

.members-section h4 { font-size: 0.8rem; font-weight: 600; color: #64748b; margin: 0 0 8px; }
.members-grid { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.member-chip { display: flex; flex-direction: column; gap: 3px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 12px; min-width: 180px; }
.member-chip-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.member-chip-status-row { margin-top: 2px; }
.member-subject { font-weight: 600; font-size: 0.85rem; }
.member-teacher { color: #64748b; font-size: 0.8rem; }

.member-status-tag {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 0.72rem; font-weight: 600;
  padding: 2px 6px; border-radius: 4px;
  transition: opacity 0.15s;
}
.member-status-tag.status-ok { background: #d1fae5; color: #065f46; }
.member-status-tag.status-partial { background: #fef3c7; color: #92400e; }
.member-status-tag.status-unscheduled { background: #fed7aa; color: #9a3412; }
.member-status-tag.status-unscheduled:hover { opacity: 0.8; }

.member-next-sessions { font-size: 0.72rem; color: #64748b; }
.member-next-empty { color: #cbd5e1; }

@media (max-width: 480px) {
  .member-next-sessions { display: none; }
}

/* ── Health badge on package header ── */
.health-badge {
  display: inline-flex; align-items: center; justify-content: center;
  width: 18px; height: 18px; border-radius: 50%;
  flex-shrink: 0;
  transition: background 0.15s, color 0.15s;
}
.health-badge .material-symbols-outlined { font-size: 14px; }
.health-badge.health-green { background: #22c55e; color: #fff; }
.health-badge.health-orange { background: #f59e0b; color: #fff; }
.health-badge.health-red { background: #ef4444; color: #fff; }

/* ── Health stats bar ── */
.health-stats-bar {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 0.82rem; font-weight: 600;
  margin-bottom: 12px;
  cursor: pointer; user-select: none;
  transition: opacity 0.15s;
}
.health-stats-bar:not([role]) { cursor: default; }
.health-stats-bar:hover { opacity: 0.9; }
.health-stats-bar.incomplete { background: #f59e0b; color: #fff; }
.health-stats-bar.complete { background: #d1fae5; color: #065f46; cursor: default; }
.health-stats-bar .material-symbols-outlined { font-size: 1.1rem; }
.stats-bar-hint { font-weight: 400; font-size: 0.78rem; opacity: 0.92; margin-left: auto; }

.slide-down-enter-active, .slide-down-leave-active { transition: max-height 0.2s ease, opacity 0.2s ease; overflow: hidden; }
.slide-down-enter-from, .slide-down-leave-to { max-height: 0; opacity: 0; }
.slide-down-enter-to, .slide-down-leave-from { max-height: 80px; opacity: 1; }

.progress-bar-wrap { margin-bottom: 12px; }
.progress-bar { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
.progress-ok { background: #22c55e; }
.progress-warning { background: #f59e0b; }
.progress-danger { background: #ef4444; }
.progress-label { font-size: 0.7rem; color: #94a3b8; margin-top: 2px; display: inline-block; }

.detail-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }

.detail-breakdown { margin-top: 8px; }
.detail-breakdown h4 { font-size: 0.8rem; font-weight: 600; color: #64748b; margin: 0 0 6px; }
.mini-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
.mini-table th { text-align: left; padding: 4px 8px; border-bottom: 1px solid #e2e8f0; color: #64748b; font-weight: 600; }
.mini-table td { padding: 4px 8px; border-bottom: 1px solid #f1f5f9; }
.text-center { text-align: center; }

/* Buttons */
.btn { display: inline-flex; align-items: center; gap: 4px; padding: 6px 14px; border: none; border-radius: 6px; font-size: 0.85rem; cursor: pointer; font-weight: 500; transition: background 0.15s, opacity 0.15s; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn .material-symbols-outlined { font-size: 1rem; }
.btn-primary { background: var(--primary, #4f46e5); color: #fff; }
.btn-primary:hover:not(:disabled) { background: #4338ca; }
.btn-secondary { background: #e2e8f0; color: #334155; }
.btn-outline { background: transparent; border: 1px solid #d1d5db; color: #475569; }
.btn-outline:hover { background: #f8fafc; }
.btn-text { background: none; border: none; color: var(--primary, #4f46e5); }
.btn-sm { padding: 4px 10px; font-size: 0.8rem; }
.btn-xs { padding: 3px 8px; font-size: 0.75rem; }
.btn-icon { background: none; border: none; padding: 4px; cursor: pointer; }
.btn-danger-text { color: #ef4444; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 9999; padding: 16px; }
.modal-box { background: #fff; border-radius: 12px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,0.15); }
.modal-lg { max-width: 640px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #f1f5f9; }
.modal-header h3 { margin: 0; font-size: 1.1rem; }
.modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #94a3b8; padding: 0; line-height: 1; }
.modal-body { padding: 20px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid #f1f5f9; }

.form-row { display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 0.78rem; font-weight: 600; color: #475569; }
.flex-1 { flex: 1; min-width: 140px; }
.required { color: #ef4444; }

.section-label { font-size: 0.85rem; font-weight: 700; color: #334155; margin: 16px 0 8px; }
.subject-block { padding: 10px; margin-bottom: 12px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fafbfc; }
.subject-row { display: flex; align-items: flex-end; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.schedule-row { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.schedule-hint { font-size: 0.72rem; color: #64748b; margin: 6px 0 0; }

.weekday-chips { display: flex; gap: 4px; flex-wrap: wrap; }
.weekday-chip {
  width: 28px; height: 28px;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1px solid #d1d5db; background: #f1f5f9; color: #475569;
  border-radius: 6px; font-size: 0.8rem; font-weight: 600;
  cursor: pointer; padding: 0;
  transition: transform 0.12s, background 0.12s, color 0.12s, border-color 0.12s;
}
.weekday-chip:hover:not(:disabled) { transform: scale(1.05); border-color: var(--primary, #4f46e5); }
.weekday-chip.selected { background: var(--primary, #4f46e5); color: #fff; border-color: var(--primary, #4f46e5); }
.weekday-chip:disabled { opacity: 0.4; cursor: not-allowed; }

/* ── Summary dialog ── */
.summary-empty { text-align: center; padding: 18px 8px; }
.summary-empty-icon { font-size: 3rem; color: #f59e0b; opacity: 0.8; }
.summary-empty p { margin: 8px 0; color: #334155; }
.summary-empty-sub { font-size: 0.85rem; color: #64748b; }

.summary-intro { font-size: 0.85rem; color: #475569; margin: 0 0 10px; }
.summary-list ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
.summary-item { padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
.summary-subject { font-weight: 600; color: #1e293b; font-size: 0.9rem; }
.summary-detail { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; margin-top: 4px; }
.summary-detail .material-symbols-outlined { font-size: 1rem; }
.summary-detail.ok { color: #065f46; }
.summary-detail.warn { color: #9a3412; }
.summary-first-date { color: #64748b; font-size: 0.78rem; }
.summary-link { color: var(--primary, #4f46e5); margin-left: 6px; text-decoration: underline; font-size: 0.8rem; cursor: pointer; }

.inline-hint { font-size: 0.72rem; color: #94a3b8; margin: 4px 0 0; }
.inline-warn { font-size: 0.76rem; color: #ea580c; margin: 4px 0 0; font-weight: 600; }
.inline-note { font-size: 0.76rem; color: #d97706; margin: 4px 0 0; }
.inline-ok { font-size: 0.76rem; color: #16a34a; margin: 4px 0 0; }

.slide-enter-active, .slide-leave-active { transition: all 0.2s ease; }
.slide-enter-from, .slide-leave-to { opacity: 0; max-height: 0; overflow: hidden; }
.slide-enter-to, .slide-leave-from { opacity: 1; max-height: 600px; }

.card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
</style>
