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
                  <span class="member-subject">{{ m.subject || '未設定科目' }}</span>
                  <span class="member-teacher">{{ m.teacher_name }}</span>
                  <span v-if="m.stop" class="badge badge-warning badge-sm">停</span>
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
            <div v-for="(subj, idx) in form.subjects" :key="idx" class="subject-row">
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
const studentOptions = ref([]);
const teacherOptions = ref([]);
const subjectOptions = ref([]);

const form = ref(emptyForm());

function emptyForm() {
  return {
    student_id: '',
    name: '',
    total_sessions: 24,
    rate: 0,
    class_type: 'one_on_one',
    subjects: [
      { subject_id: '', teacher_id: '', duration_hours: 2 },
    ],
  };
}

function addSubject() {
  form.value.subjects.push({ subject_id: '', teacher_id: '', duration_hours: 2 });
}

function removeSubject(idx) {
  form.value.subjects.splice(idx, 1);
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
  return list;
});

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
      subjects: validSubjects.map(s => ({
        subject_id: Number(s.subject_id),
        teacher_id: Number(s.teacher_id),
        duration_hours: Number(s.duration_hours) || 2,
      })),
    };
    const res = await createMultiSubjectPackage(payload);
    alert(`方案已建立（ID: ${res.package_id}）`);
    showCreateModal.value = false;
    form.value = emptyForm();
    await loadPackages();
  } catch (e) {
    alert('建立失敗：' + e.message);
  } finally {
    creating.value = false;
  }
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
.member-chip { display: flex; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 12px; }
.member-subject { font-weight: 600; font-size: 0.85rem; }
.member-teacher { color: #64748b; font-size: 0.8rem; }

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
.subject-row { display: flex; align-items: flex-end; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }

.slide-enter-active, .slide-leave-active { transition: all 0.2s ease; }
.slide-enter-from, .slide-leave-to { opacity: 0; max-height: 0; overflow: hidden; }
.slide-enter-to, .slide-leave-from { opacity: 1; max-height: 600px; }

.card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
</style>
