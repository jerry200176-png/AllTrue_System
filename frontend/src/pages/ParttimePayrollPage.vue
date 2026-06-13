<template>
  <div class="payroll-page">
    <!-- Page Header -->
    <div class="page-header">
      <div class="page-header-left">
        <div class="page-icon">
          <span class="material-symbols-outlined">payments</span>
        </div>
        <div class="title-group">
          <h2>兼職老師薪資</h2>
          <p class="title-sub">查詢兼職老師月薪資總額與堂次明細</p>
        </div>
      </div>
      <div class="header-btns">
        <button class="btn-outline" :disabled="loading" @click="openRulesModal">
          <span class="material-symbols-outlined">tune</span>
          薪資計算設定
        </button>
        <button class="btn-outline" :disabled="exporting || loading" @click="doExport">
          <span v-if="exporting" class="material-symbols-outlined spin">progress_activity</span>
          <span v-else class="material-symbols-outlined">download</span>
          {{ exporting ? '匯出中...' : '匯出 CSV' }}
        </button>
      </div>
    </div>

    <div class="payroll-card">
      <!-- Filters -->
      <div class="filter-row">
        <div class="filter-item">
          <label>
            <span class="material-symbols-outlined">calendar_month</span>
            月份
          </label>
          <input type="month" v-model="selectedMonth" @change="loadData" />
        </div>
        <div class="filter-item filter-item-search">
          <label>
            <span class="material-symbols-outlined">search</span>
            搜尋老師
          </label>
          <div class="search-input-wrap">
            <input v-model="searchQ" placeholder="輸入姓名..." @input="debouncedLoad" />
          </div>
        </div>
      </div>

      <!-- Summary Cards -->
      <div v-if="loading" class="skeleton-cards">
        <div class="skeleton-card" v-for="i in 4" :key="i"></div>
      </div>
      <div v-else-if="error" class="error-state">
        <div class="error-icon-wrap">
          <span class="material-symbols-outlined">error_outline</span>
        </div>
        <p>載入薪資資料失敗，請稍後再試</p>
        <button class="btn-outline small" @click="loadData">
          <span class="material-symbols-outlined">refresh</span>
          重試
        </button>
      </div>
      <div v-else class="summary-row">
        <div class="summary-card primary">
          <div class="summary-card-icon">
            <span class="material-symbols-outlined">account_balance_wallet</span>
          </div>
          <div class="summary-body">
            <div class="summary-label">應付薪資總額</div>
            <div class="summary-value big">NT$ {{ formatNum(summary.total_salary) }}</div>
          </div>
        </div>
        <div class="summary-card teal">
          <div class="summary-card-icon">
            <span class="material-symbols-outlined">schedule</span>
          </div>
          <div class="summary-body">
            <div class="summary-label">總教學時數</div>
            <div class="summary-value">{{ summary.total_hours }} <span class="unit-text">h</span></div>
          </div>
        </div>
        <div class="summary-card purple">
          <div class="summary-card-icon">
            <span class="material-symbols-outlined">group</span>
          </div>
          <div class="summary-body">
            <div class="summary-label">兼職老師數</div>
            <div class="summary-value">{{ summary.teacher_count }} <span class="unit-text">人</span></div>
          </div>
        </div>
        <div class="summary-card orange">
          <div class="summary-card-icon">
            <span class="material-symbols-outlined">trending_up</span>
          </div>
          <div class="summary-body">
            <div class="summary-label">平均時薪<span class="summary-hint">（含併堂加給）</span></div>
            <div class="summary-value">NT$ {{ formatNum(summary.avg_hourly_rate) }}</div>
          </div>
        </div>
      </div>

      <!-- Lock Status Bar -->
      <div v-if="!loading && !error" class="lock-bar">
        <div class="lock-bar-left">
          <span :class="['lock-badge', summary.lock_status || 'draft']">
            <span class="material-symbols-outlined lock-badge-icon">
              {{ summary.lock_status === 'locked' ? 'lock' : summary.lock_status === 'reviewed' ? 'verified' : 'edit_note' }}
            </span>
            {{ summary.lock_status === 'locked' ? '已鎖帳' : summary.lock_status === 'reviewed' ? '已確認' : '草稿' }}
          </span>
          <span v-if="summary.locked_at" class="lock-info">
            <span class="material-symbols-outlined" style="font-size:0.9rem;vertical-align:-2px">history</span>
            {{ summary.locked_at?.substring(0, 16) }} 鎖定
          </span>
        </div>
        <div class="lock-bar-right">
          <button
            v-if="summary.lock_status !== 'locked'"
            class="btn-primary small"
            :disabled="locking"
            @click="doLock"
          >
            <span class="material-symbols-outlined">lock</span>
            {{ locking ? '處理中...' : '鎖帳' }}
          </button>
          <button
            v-if="summary.lock_status === 'locked' && userRole === 'super_admin'"
            class="btn-warning small"
            @click="showReopenModal = true"
          >
            <span class="material-symbols-outlined">lock_open</span>
            重開
          </button>
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="!loading && !error && teachers.length === 0" class="empty-state">
        <div class="empty-icon-wrap">
          <span class="material-symbols-outlined">person_search</span>
        </div>
        <p class="empty-title">本月無授課紀錄</p>
        <p class="empty-sub">該分校本月尚無兼職老師授課資料</p>
      </div>

      <!-- Teacher Table -->
      <div v-if="!loading && !error && teachers.length > 0" class="teacher-table">
        <div class="table-header">
          <span class="col-name sortable" @click="toggleSort('name_asc')">
            老師姓名
            <span class="material-symbols-outlined sort-icon" :class="{ active: sortKey === 'name_asc' }">unfold_more</span>
          </span>
          <span class="col-hours sortable" @click="toggleSort('hours_desc')">
            總時數
            <span class="material-symbols-outlined sort-icon" :class="{ active: sortKey === 'hours_desc' }">unfold_more</span>
          </span>
          <span class="col-high hide-mobile">高中</span>
          <span class="col-junior hide-mobile">國中</span>
          <span class="col-elem hide-mobile">國小</span>
          <span class="col-tutor hide-mobile">輔導</span>
          <span class="col-salary sortable" @click="toggleSort('salary_desc')">
            應付薪資
            <span class="material-symbols-outlined sort-icon" :class="{ active: sortKey === 'salary_desc' }">unfold_more</span>
          </span>
          <span class="col-count hide-mobile">堂次</span>
        </div>

        <div
          v-for="t in teachers"
          :key="t.teacher_id"
          class="teacher-row-wrap"
          :class="{ 'is-expanded': expandedId === t.teacher_id }"
        >
          <div class="teacher-row" @click="toggleExpand(t.teacher_id)">
            <span class="col-name">
              <span class="expand-btn" :class="{ expanded: expandedId === t.teacher_id }">
                <span class="material-symbols-outlined">expand_more</span>
              </span>
              <span class="teacher-avatar">{{ t.teacher_name?.charAt(0) }}</span>
              {{ t.teacher_name }}
              <span v-if="t.rule_source === 'teacher_override'" class="override-tag">自訂費率</span>
            </span>
            <span class="col-hours">{{ t.total_hours }}<span class="col-unit">h</span></span>
            <span class="col-high hide-mobile level-pill high">{{ t.high_hours }}</span>
            <span class="col-junior hide-mobile level-pill junior">{{ t.junior_hours }}</span>
            <span class="col-elem hide-mobile level-pill elem">{{ t.elementary_hours }}</span>
            <span class="col-tutor hide-mobile level-pill tutor">{{ t.tutoring_hours }}</span>
            <span class="col-salary">
              <span class="salary-chip">NT$ {{ formatNum(t.total_salary) }}</span>
            </span>
            <span class="col-count hide-mobile">
              <span class="count-badge">{{ t.session_count }}</span>
            </span>
          </div>

          <!-- Expanded session detail -->
          <transition name="expand-slide">
            <div v-if="expandedId === t.teacher_id" class="session-detail">
              <div v-if="sessionsLoading" class="session-loading">
                <span class="material-symbols-outlined spin">progress_activity</span>
                載入明細中...
              </div>
              <div v-else-if="sessions.length === 0" class="session-empty">
                <span class="material-symbols-outlined">inbox</span>
                本月無堂次紀錄
              </div>
              <template v-else>
                <div class="session-table">
                  <div class="session-header">
                    <span class="s-date">日期</span>
                    <span class="s-student">學生</span>
                    <span class="s-subject hide-mobile">科目</span>
                    <span class="s-level">學段</span>
                    <span class="s-type hide-mobile">課型</span>
                    <span class="s-hours">時數</span>
                    <span class="s-rate hide-mobile">時薪計算</span>
                    <span class="s-cb hide-mobile">併堂</span>
                    <span class="s-salary">薪資</span>
                  </div>
                  <template v-for="s in sessions" :key="s.learning_record_id">
                    <div class="session-row">
                      <span class="s-date">{{ s.session_date?.substring(5) }}</span>
                      <span class="s-student">{{ s.student_name }}</span>
                      <span class="s-subject hide-mobile">{{ s.subject }}</span>
                      <span class="s-level">
                        <span class="level-tag" :class="s.level_key">{{ s.level_label }}</span>
                      </span>
                      <span class="s-type hide-mobile">
                        <span class="type-tag">{{ classTypeLabel(s.class_type) }}</span>
                      </span>
                      <span class="s-hours">{{ s.hours }}h</span>
                      <span class="s-rate hide-mobile rate-formula">
                        <span class="rate-base">{{ s.base_rate }}</span>
                        <template v-if="s.headcount_bonus > 0">
                          <span class="rate-op">+</span>
                          <span class="rate-bonus">{{ s.headcount_bonus }}</span>
                          <span class="rate-op">=</span>
                          <span class="rate-eff">{{ s.effective_rate }}</span>
                        </template>
                      </span>
                      <span class="s-cb hide-mobile" :class="{ 'has-cb': s.concurrency_bonus_amount > 0 }">
                        {{ s.concurrency_bonus_amount > 0 ? '+' + s.concurrency_bonus_amount : '—' }}
                      </span>
                      <span class="s-salary">{{ formatNum(s.session_salary) }}</span>
                    </div>
                    <!-- #614：併堂時段採「最高時薪」說明（僅歸屬此堂的併堂段才顯示）-->
                    <div v-if="s.concurrency_segments?.length" class="session-concurrency-note">
                      <span class="material-symbols-outlined">groups</span>
                      <span
                        v-for="(seg, i) in s.concurrency_segments"
                        :key="i"
                        class="cc-seg"
                      >本段採用 ${{ seg.max_base }} 最高時薪（同時段 {{ seg.headcount }} 位學生 · {{ seg.minutes }} 分）</span>
                    </div>
                  </template>
                </div>
                <div class="session-footer">
                  <div class="session-subtotal">
                    <span class="material-symbols-outlined">receipt</span>
                    小計：<strong>NT$ {{ formatNum(sessionTeacher.total_salary) }}</strong>
                    <span class="session-count-info">（{{ sessionTeacher.session_count }} 堂）</span>
                    <button class="btn-outline tiny teacher-rule-btn" @click.stop="openTeacherRuleModal(expandedId)">
                      <span class="material-symbols-outlined" style="font-size:1rem">tune</span>
                      個別費率
                    </button>
                  </div>
                  <div v-if="sessionMeta.last_page > 1" class="session-paging">
                    <button class="btn-outline tiny" :disabled="sessionMeta.current_page <= 1" @click="loadSessions(expandedId, sessionMeta.current_page - 1)">
                      <span class="material-symbols-outlined">chevron_left</span>
                    </button>
                    <span class="paging-info">{{ sessionMeta.current_page }} / {{ sessionMeta.last_page }}</span>
                    <button class="btn-outline tiny" :disabled="sessionMeta.current_page >= sessionMeta.last_page" @click="loadSessions(expandedId, sessionMeta.current_page + 1)">
                      <span class="material-symbols-outlined">chevron_right</span>
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </transition>
        </div>
      </div>
    </div>

    <!-- Reopen Modal -->
    <div v-if="showReopenModal" class="modal-overlay" @click.self="showReopenModal = false">
      <div class="modal" style="max-width:420px">
        <div class="modal-header">
          <span class="material-symbols-outlined modal-header-icon warning">lock_open</span>
          <h3>重開月結</h3>
        </div>
        <p class="modal-desc">鎖帳後重新開啟需填寫原因，此操作將被記錄。</p>
        <div class="form-group">
          <label>原因</label>
          <textarea v-model="reopenReason" rows="3" placeholder="說明重開原因..."></textarea>
        </div>
        <div class="modal-actions">
          <button class="btn-ghost" @click="showReopenModal = false">取消</button>
          <button class="btn-warning" :disabled="!reopenReason.trim() || reopening" @click="doReopen">
            {{ reopening ? '處理中...' : '確認重開' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Rules Settings Modal -->
    <div v-if="showRulesModal" class="modal-overlay" @click.self="confirmCloseRules">
      <div class="modal rules-modal">
        <div class="modal-header">
          <span class="material-symbols-outlined modal-header-icon primary">tune</span>
          <div>
            <h3>薪資計算設定</h3>
            <span v-if="rulesData.rule_version_id" class="rules-version">
              版本 #{{ rulesData.rule_version_id }}
              <template v-if="rulesData.created_by_name"> · {{ rulesData.created_by_name }}</template>
              <template v-if="rulesData.created_at"> · {{ rulesData.created_at?.substring(0, 16) }}</template>
            </span>
          </div>
        </div>

        <div v-if="summary.lock_status === 'locked'" class="rules-banner info">
          <span class="material-symbols-outlined">info</span>
          本月已鎖帳，數字依鎖定當下規則計算。如需修改參數請 super_admin 重開後再調整。
        </div>

        <div v-if="rulesLoading" class="rules-loading">
          <span class="material-symbols-outlined spin">progress_activity</span>
          載入設定中...
        </div>
        <div v-else-if="rulesError" class="error-state" style="padding:16px">
          <p>{{ rulesError }}</p>
          <button class="btn-outline small" @click="loadRules">重試</button>
        </div>
        <template v-else>
          <fieldset class="rules-fieldset" :disabled="isRulesReadonly">
            <legend>基礎時薪（元／小時）</legend>
            <div class="rules-grid">
              <div class="rules-field" v-for="item in rateFields" :key="item.key">
                <label :for="'rate-' + item.key">
                  <span :class="['level-dot', item.key]"></span>
                  {{ item.label }}
                </label>
                <div class="input-with-unit">
                  <input
                    :id="'rate-' + item.key"
                    type="number"
                    min="100" max="2000" step="1"
                    v-model.number="ruleForm.base_rates[item.key]"
                    :class="{ 'has-error': ruleErrors[item.key] }"
                    :aria-describedby="ruleErrors[item.key] ? 'err-' + item.key : undefined"
                  />
                  <span class="unit">元/h</span>
                </div>
                <span v-if="ruleErrors[item.key]" :id="'err-' + item.key" class="field-error">{{ ruleErrors[item.key] }}</span>
              </div>
            </div>
          </fieldset>

          <fieldset class="rules-fieldset" disabled>
            <legend>人數加成（已停用）</legend>
            <p class="rules-hint">
              <span class="material-symbols-outlined" style="font-size:0.85rem;vertical-align:-1px">info</span>
              人數加成已改為依實際同時段學生數自動計算（併堂加給），此欄位不影響薪資
            </p>
          </fieldset>

          <div class="modal-actions">
            <button v-if="!isRulesReadonly" class="btn-ghost small" @click="resetToDefaults">
              <span class="material-symbols-outlined">restart_alt</span>
              還原預設
            </button>
            <div style="flex:1"></div>
            <button class="btn-ghost" @click="confirmCloseRules">{{ isRulesReadonly ? '關閉' : '取消' }}</button>
            <button v-if="!isRulesReadonly" class="btn-primary" :disabled="rulesSaving || !isRulesDirty" @click="saveRules">
              <span class="material-symbols-outlined">save</span>
              {{ rulesSaving ? '儲存中...' : '儲存' }}
            </button>
          </div>
        </template>
      </div>
    </div>

    <!-- Reset Defaults Confirmation -->
    <div v-if="showResetConfirm" class="modal-overlay" @click.self="showResetConfirm = false" style="z-index:10001">
      <div class="modal" style="max-width:380px">
        <div class="modal-header">
          <span class="material-symbols-outlined modal-header-icon warning">restart_alt</span>
          <h3>還原系統預設</h3>
        </div>
        <p class="modal-desc">將覆寫本校區目前參數為系統預設值，確定嗎？</p>
        <div class="modal-actions">
          <button class="btn-ghost" @click="showResetConfirm = false">取消</button>
          <button class="btn-warning" @click="doResetDefaults">確認還原</button>
        </div>
      </div>
    </div>

    <!-- Teacher Override Rules Modal -->
    <div v-if="showTeacherRuleModal" class="modal-overlay" @click.self="showTeacherRuleModal = false">
      <div class="modal rules-modal">
        <div class="modal-header">
          <span class="material-symbols-outlined modal-header-icon primary">person_edit</span>
          <div>
            <h3>個別費率設定</h3>
            <span class="rules-version">{{ trTeacherName }}</span>
          </div>
        </div>

        <div v-if="trLoading" class="rules-loading">
          <span class="material-symbols-outlined spin">progress_activity</span>
          載入中...
        </div>
        <template v-else>
          <div v-if="trHasOverride" class="rules-banner info">
            <span class="material-symbols-outlined">info</span>
            此老師使用個別費率，計算結果不受分校預設影響
          </div>
          <div v-else class="rules-banner">
            <span class="material-symbols-outlined">info</span>
            目前使用分校預設費率，可在下方設定個別費率
          </div>

          <fieldset class="rules-fieldset">
            <legend>基礎時薪（元／小時）</legend>
            <div class="rules-field" style="margin-bottom:12px">
              <label for="tr-effective-from">生效日</label>
              <div class="input-with-unit">
                <input id="tr-effective-from" type="date" v-model="trEffectiveFrom" />
              </div>
            </div>
            <div class="rules-grid">
              <div class="rules-field" v-for="item in rateFields" :key="'tr-' + item.key">
                <label :for="'tr-rate-' + item.key">
                  <span :class="['level-dot', item.key]"></span>
                  {{ item.label }}
                </label>
                <div class="input-with-unit">
                  <input
                    :id="'tr-rate-' + item.key"
                    type="number"
                    min="100" max="2000" step="1"
                    v-model.number="trForm.base_rates[item.key]"
                  />
                  <span class="unit">元/h</span>
                </div>
              </div>
            </div>
          </fieldset>

          <fieldset class="rules-fieldset" v-if="trHistory.length">
            <legend>費率卡歷史</legend>
            <div class="rule-history-list">
              <div v-for="item in trHistory" :key="item.id" class="rule-history-row">
                <span class="rh-date">{{ item.effective_from }}</span>
                <span class="rh-type">{{ item.use_branch_default ? '恢復分校預設' : '個別費率卡' }}</span>
              </div>
            </div>
          </fieldset>

          <fieldset class="rules-fieldset" disabled>
            <legend>人數加成（已停用）</legend>
            <p class="rules-hint">
              <span class="material-symbols-outlined" style="font-size:0.85rem;vertical-align:-1px">info</span>
              人數加成已改為依實際同時段學生數自動計算（併堂加給），此欄位不影響薪資
            </p>
          </fieldset>

          <div class="modal-actions">
            <button v-if="trHasOverride" class="btn-ghost small" @click="revertTeacherRule" :disabled="trSaving">
              <span class="material-symbols-outlined">restart_alt</span>
              恢復分校預設
            </button>
            <div style="flex:1"></div>
            <button class="btn-ghost" @click="showTeacherRuleModal = false">取消</button>
            <button class="btn-primary" :disabled="trSaving" @click="saveTeacherRule">
              <span class="material-symbols-outlined">save</span>
              {{ trSaving ? '儲存中...' : '儲存個別費率' }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import {
  fetchPayrollSummary,
  fetchTeacherSessions,
  exportPayrollCsv,
  lockPayroll,
  reopenPayroll,
  fetchPayrollRules,
  updatePayrollRules,
  fetchTeacherPayrollRules,
  updateTeacherPayrollRules,
  deleteTeacherPayrollRules,
} from '../lib/parttimePayrollApi.js';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: 'director' },
});

const now = new Date();
const selectedMonth = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);

const loading = ref(false);
const error = ref(false);
const summary = ref({});
const teachers = ref([]);
const sortKey = ref('salary_desc');
const searchQ = ref('');

const expandedId = ref(null);
const sessionsLoading = ref(false);
const sessions = ref([]);
const sessionTeacher = ref({});
const sessionMeta = ref({});

const exporting = ref(false);
const locking = ref(false);
const showReopenModal = ref(false);
const reopenReason = ref('');
const reopening = ref(false);

let debounceTimer = null;
function debouncedLoad() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(loadData, 400);
}

async function loadData() {
  loading.value = true;
  error.value = false;
  expandedId.value = null;
  try {
    const data = await fetchPayrollSummary({
      month: selectedMonth.value,
      branchId: props.branchId,
      search: searchQ.value || undefined,
      sort: sortKey.value,
    });
    summary.value = data.summary || {};
    teachers.value = data.teachers || [];
  } catch (e) {
    console.error('Payroll load error:', e);
    error.value = true;
  } finally {
    loading.value = false;
  }
}

async function toggleExpand(teacherId) {
  if (expandedId.value === teacherId) {
    expandedId.value = null;
    return;
  }
  expandedId.value = teacherId;
  await loadSessions(teacherId, 1);
}

async function loadSessions(teacherId, page = 1) {
  sessionsLoading.value = true;
  try {
    const data = await fetchTeacherSessions({
      teacherId,
      month: selectedMonth.value,
      branchId: props.branchId,
      page,
    });
    sessions.value = data.sessions || [];
    sessionTeacher.value = data.teacher || {};
    sessionMeta.value = data.meta || {};
  } catch (e) {
    console.error('Sessions load error:', e);
    sessions.value = [];
  } finally {
    sessionsLoading.value = false;
  }
}

function toggleSort(key) {
  sortKey.value = key;
  loadData();
}

async function doExport() {
  exporting.value = true;
  try {
    await exportPayrollCsv({ month: selectedMonth.value, branchId: props.branchId });
  } catch (e) {
    alert('匯出失敗：' + (e.message || ''));
  } finally {
    exporting.value = false;
  }
}

async function doLock() {
  if (!confirm(`確定要鎖帳 ${selectedMonth.value} 嗎？鎖帳後不可修改。`)) return;
  locking.value = true;
  try {
    await lockPayroll({ month: selectedMonth.value, branchId: props.branchId });
    await loadData();
  } catch (e) {
    alert('鎖帳失敗：' + (e.message || ''));
  } finally {
    locking.value = false;
  }
}

async function doReopen() {
  reopening.value = true;
  try {
    await reopenPayroll({ month: selectedMonth.value, branchId: props.branchId, reason: reopenReason.value });
    showReopenModal.value = false;
    reopenReason.value = '';
    await loadData();
  } catch (e) {
    alert('重開失敗：' + (e.message || ''));
  } finally {
    reopening.value = false;
  }
}

function formatNum(n) {
  if (n == null) return '0';
  return Number(n).toLocaleString('zh-TW');
}

function classTypeLabel(ct) {
  const map = { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導' };
  return map[ct] || ct;
}

// ── Rules Settings ──────────────────────────
const showRulesModal = ref(false);
const rulesLoading = ref(false);
const rulesError = ref('');
const rulesData = ref({});
const ruleForm = ref({ base_rates: {}, headcount_bonus: 50 });
const ruleFormSnapshot = ref('');
const rulesSaving = ref(false);
const ruleErrors = ref({});
const showResetConfirm = ref(false);

const rateFields = [
  { key: 'high', label: '高中' },
  { key: 'junior', label: '國中' },
  { key: 'elementary', label: '國小' },
  { key: 'tutoring', label: '輔導' },
];

const isRulesReadonly = computed(() => summary.value.lock_status === 'locked');
const isRulesDirty = computed(() => JSON.stringify(ruleForm.value) !== ruleFormSnapshot.value);

async function openRulesModal() {
  showRulesModal.value = true;
  await loadRules();
}

async function loadRules() {
  rulesLoading.value = true;
  rulesError.value = '';
  try {
    const data = await fetchPayrollRules({ branchId: props.branchId });
    rulesData.value = data;
    ruleForm.value = {
      base_rates: { ...data.base_rates },
      headcount_bonus: data.headcount_bonus,
    };
    ruleFormSnapshot.value = JSON.stringify(ruleForm.value);
  } catch (e) {
    rulesError.value = e.message || '載入失敗';
  } finally {
    rulesLoading.value = false;
  }
}

function validateRules() {
  const errs = {};
  for (const f of rateFields) {
    const v = ruleForm.value.base_rates[f.key];
    if (v == null || v === '') errs[f.key] = '必填';
    else if (v < 100 || v > 2000) errs[f.key] = '100–2000';
  }
  ruleErrors.value = errs;
  return Object.keys(errs).length === 0;
}

async function saveRules() {
  if (!validateRules()) return;
  rulesSaving.value = true;
  try {
    await updatePayrollRules({
      branchId: props.branchId,
      baseRates: ruleForm.value.base_rates,
      headcountBonus: ruleForm.value.headcount_bonus,
    });
    showRulesModal.value = false;
    await loadData();
  } catch (e) {
    alert('儲存失敗：' + (e.message || ''));
  } finally {
    rulesSaving.value = false;
  }
}

function confirmCloseRules() {
  if (isRulesDirty.value && !isRulesReadonly.value) {
    if (!confirm('尚有未儲存的變更，確定要離開嗎？')) return;
  }
  showRulesModal.value = false;
}

function resetToDefaults() {
  showResetConfirm.value = true;
}

function doResetDefaults() {
  if (rulesData.value.defaults) {
    ruleForm.value = {
      base_rates: { ...rulesData.value.defaults.base_rates },
      headcount_bonus: rulesData.value.defaults.headcount_bonus,
    };
  }
  showResetConfirm.value = false;
}

// ── Teacher Override Rules ──
const showTeacherRuleModal = ref(false);
const trLoading = ref(false);
const trSaving = ref(false);
const trHasOverride = ref(false);
const trTeacherName = ref('');
const trTeacherId = ref(null);
const trForm = ref({ base_rates: { high: 400, junior: 350, elementary: 300, tutoring: 200 }, headcount_bonus: 50 });
const trEffectiveFrom = ref(new Date().toISOString().slice(0, 10));
const trHistory = ref([]);

async function openTeacherRuleModal(teacherId) {
  trTeacherId.value = teacherId;
  const t = teachers.value.find(x => x.teacher_id === teacherId);
  trTeacherName.value = t?.teacher_name || '';
  trLoading.value = true;
  showTeacherRuleModal.value = true;
  try {
    const data = await fetchTeacherPayrollRules({ branchId: props.branchId, teacherId });
    trHasOverride.value = data.has_override;
    trForm.value = {
      base_rates: { ...data.base_rates },
      headcount_bonus: data.headcount_bonus,
    };
    trEffectiveFrom.value = data.effective_from || new Date().toISOString().slice(0, 10);
    trHistory.value = Array.isArray(data.history) ? data.history : [];
  } catch (e) {
    alert('載入失敗：' + (e.message || ''));
    showTeacherRuleModal.value = false;
  } finally {
    trLoading.value = false;
  }
}

async function saveTeacherRule() {
  trSaving.value = true;
  try {
    await updateTeacherPayrollRules({
      branchId: props.branchId,
      teacherId: trTeacherId.value,
      baseRates: trForm.value.base_rates,
      headcountBonus: trForm.value.headcount_bonus,
      effectiveFrom: trEffectiveFrom.value,
    });
    showTeacherRuleModal.value = false;
    await loadData();
  } catch (e) {
    alert('儲存失敗：' + (e.message || ''));
  } finally {
    trSaving.value = false;
  }
}

async function revertTeacherRule() {
  if (!confirm('確定要恢復此老師為分校預設費率嗎？')) return;
  trSaving.value = true;
  try {
    await deleteTeacherPayrollRules({
      branchId: props.branchId,
      teacherId: trTeacherId.value,
      effectiveFrom: trEffectiveFrom.value,
    });
    showTeacherRuleModal.value = false;
    await loadData();
  } catch (e) {
    alert('恢復失敗：' + (e.message || ''));
  } finally {
    trSaving.value = false;
  }
}

watch(() => props.branchId, () => loadData());
onMounted(loadData);
</script>

<style scoped>
/* ─── Layout ─────────────────────────────── */
.payroll-page {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 0 32px;
}

/* ─── Page Header ────────────────────────── */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 20px;
}
.page-header-left {
  display: flex;
  align-items: center;
  gap: 14px;
}
.page-icon {
  width: 48px;
  height: 48px;
  background: linear-gradient(135deg, var(--ds-ink-mute) 0%, var(--ds-ink-mute) 100%);
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
  flex-shrink: 0;
}
.page-icon .material-symbols-outlined {
  color: var(--ds-canvas);
  font-size: 1.5rem;
}
.title-group h2 {
  margin: 0;
  font-size: 1.4rem;
  font-weight: 700;
  color: var(--ds-ink);
  line-height: 1.2;
}
.title-sub {
  color: var(--ds-ink-mute);
  font-size: 0.82rem;
  margin: 3px 0 0;
}
.header-btns {
  display: flex;
  gap: 8px;
}

/* ─── Card ───────────────────────────────── */
.payroll-card {
  background: var(--ds-canvas);
  border-radius: 16px;
  border: 1px solid var(--ds-canvas-soft);
  padding: 24px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.05);
}

/* ─── Buttons ────────────────────────────── */
.btn-outline {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: 1.5px solid var(--ds-canvas-soft);
  border-radius: 8px;
  background: var(--ds-canvas);
  color: var(--ds-ink);
  font-size: 0.85rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s;
}
.btn-outline:hover:not(:disabled) {
  border-color: var(--ds-hairline);
  background: var(--ds-canvas-soft);
}
.btn-outline:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-outline .material-symbols-outlined { font-size: 1rem; }

.btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border: none;
  border-radius: 8px;
  background: var(--ds-ink-mute);
  color: var(--ds-canvas);
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s, box-shadow 0.15s;
}
.btn-primary:hover:not(:disabled) { background: var(--ds-ink-mute); box-shadow: 0 2px 8px rgba(37,99,235,0.3); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary.small { padding: 6px 12px; font-size: 0.8rem; }
.btn-primary .material-symbols-outlined { font-size: 0.95rem; }

.btn-warning {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border: none;
  border-radius: 8px;
  background: var(--ds-warning);
  color: var(--ds-canvas);
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
}
.btn-warning:hover:not(:disabled) { background: var(--ds-warning); }
.btn-warning:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-warning.small { padding: 6px 12px; font-size: 0.8rem; }

.btn-ghost {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: 1.5px solid var(--ds-canvas-soft);
  border-radius: 8px;
  background: transparent;
  color: var(--ds-ink-mute);
  font-size: 0.85rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s;
}
.btn-ghost:hover:not(:disabled) { background: var(--ds-canvas-soft); border-color: var(--ds-canvas-soft); color: var(--ds-ink); }
.btn-ghost.small { padding: 6px 10px; font-size: 0.8rem; }
.btn-ghost .material-symbols-outlined { font-size: 0.95rem; }

.btn-outline.tiny, .btn-ghost.tiny {
  padding: 4px 8px;
  font-size: 0.75rem;
}
.btn-outline.tiny .material-symbols-outlined { font-size: 0.9rem; }

/* ─── Filters ────────────────────────────── */
.filter-row {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
.filter-item {
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.filter-item label {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 0.72rem;
  color: var(--ds-ink-mute);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.filter-item label .material-symbols-outlined { font-size: 0.85rem; }
.filter-item input {
  padding: 8px 12px;
  border: 1.5px solid var(--ds-canvas-soft);
  border-radius: 8px;
  font-size: 0.875rem;
  color: var(--ds-ink);
  background: var(--ds-canvas);
  transition: border-color 0.15s, background 0.15s;
}
.filter-item input:focus {
  outline: none;
  border-color: var(--ds-ink-mute);
  background: var(--ds-canvas);
}
.filter-item-search { flex: 1; min-width: 180px; }
.filter-item-search input { width: 100%; }

/* ─── Skeleton ───────────────────────────── */
.skeleton-cards {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 20px;
}
.skeleton-card {
  height: 96px;
  background: linear-gradient(90deg, var(--ds-canvas-soft) 25%, var(--ds-canvas-soft) 50%, var(--ds-canvas-soft) 75%);
  background-size: 200% 100%;
  border-radius: 12px;
  animation: shimmer 1.5s ease-in-out infinite;
}
@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* ─── Summary Cards ──────────────────────── */
.summary-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 18px;
}
.summary-card {
  border-radius: 14px;
  padding: 18px 20px;
  display: flex;
  align-items: center;
  gap: 14px;
  border: 1px solid transparent;
  transition: transform 0.15s, box-shadow 0.15s;
}
.summary-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0,0,0,0.08);
}
.summary-card.primary {
  background: linear-gradient(135deg, var(--ds-canvas-soft) 0%, var(--ds-canvas-soft) 100%);
  border-color: var(--ds-canvas-soft);
}
.summary-card.teal {
  background: linear-gradient(135deg, var(--ds-success-wash) 0%, var(--ds-success-wash) 100%);
  border-color: var(--ds-success);
}
.summary-card.purple {
  background: linear-gradient(135deg, var(--ds-canvas-soft) 0%, var(--ds-canvas-soft) 100%);
  border-color: var(--ds-canvas-soft);
}
.summary-card.orange {
  background: linear-gradient(135deg, var(--ds-warning-wash) 0%, var(--ds-warning-wash) 100%);
  border-color: var(--ds-warning);
}
.summary-card-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.summary-card.primary .summary-card-icon { background: var(--ds-ink-mute); }
.summary-card.teal .summary-card-icon { background: var(--ds-success); }
.summary-card.purple .summary-card-icon { background: var(--ds-ink-mute); }
.summary-card.orange .summary-card-icon { background: var(--ds-warning); }
.summary-card-icon .material-symbols-outlined { color: var(--ds-canvas); font-size: 1.3rem; }
.summary-body { flex: 1; min-width: 0; }
.summary-label { font-size: 0.72rem; font-weight: 600; color: var(--ds-ink-mute); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
.summary-hint { font-size: 0.65rem; font-weight: 400; color: var(--ds-hairline); text-transform: none; letter-spacing: 0; margin-left: 2px; }
.summary-value { font-size: 1.15rem; font-weight: 700; color: var(--ds-ink); }
.summary-value.big { font-size: 1.6rem; color: var(--ds-ink-mute); }
/* #696：薪資金額一律等寬數字（tabular），避免列間跳動（Xero payroll 取向）。 */
.summary-value, .salary-chip, .s-salary { font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
.unit-text { font-size: 0.75rem; font-weight: 500; color: var(--ds-ink-mute); margin-left: 2px; }

/* ─── Lock Bar ───────────────────────────── */
.lock-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 18px;
  padding: 10px 16px;
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-canvas-soft);
  border-radius: 10px;
}
.lock-bar-left { display: flex; align-items: center; gap: 10px; }
.lock-bar-right { display: flex; align-items: center; gap: 8px; }
.lock-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 0.75rem;
  font-weight: 700;
  padding: 4px 12px;
  border-radius: 999px;
}
.lock-badge-icon { font-size: 0.9rem; }
.lock-badge.draft { background: var(--ds-canvas-soft); color: var(--ds-ink); border: 1px solid var(--ds-canvas-soft); }
.lock-badge.reviewed { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); border: 1px solid var(--ds-canvas-soft); }
.lock-badge.locked { background: var(--ds-success-wash); color: var(--ds-success); border: 1px solid var(--ds-success); }
.lock-info { font-size: 0.78rem; color: var(--ds-hairline); display: flex; align-items: center; gap: 3px; }

/* ─── Error / Empty States ───────────────── */
.error-state { text-align: center; padding: 40px 16px; }
.error-icon-wrap {
  width: 56px;
  height: 56px;
  background: var(--ds-danger-wash);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 12px;
}
.error-icon-wrap .material-symbols-outlined { color: var(--ds-danger); font-size: 1.8rem; }
.error-state p { color: var(--ds-ink-mute); margin-bottom: 12px; }

.empty-state { text-align: center; padding: 56px 16px; }
.empty-icon-wrap {
  width: 64px;
  height: 64px;
  background: var(--ds-canvas-soft);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 14px;
}
.empty-icon-wrap .material-symbols-outlined { color: var(--ds-hairline); font-size: 2rem; }
.empty-title { font-size: 1rem; font-weight: 600; color: var(--ds-ink); margin: 0 0 6px; }
.empty-sub { font-size: 0.85rem; color: var(--ds-hairline); margin: 0; }

/* ─── Teacher Table ──────────────────────── */
.teacher-table {
  border: 1px solid var(--ds-canvas-soft);
  border-radius: 12px;
  overflow: hidden;
}
.table-header,
.teacher-row {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 1.6fr 0.8fr;
  padding: 10px 16px;
  align-items: center;
  font-size: 0.83rem;
}
.table-header {
  background: var(--ds-canvas-soft);
  font-weight: 700;
  color: var(--ds-ink);
  border-bottom: 1px solid var(--ds-canvas-soft);
  position: sticky;
  top: 0;
}
.sortable {
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 3px;
  user-select: none;
  transition: color 0.15s;
}
.sortable:hover { color: var(--ds-ink-mute); }
.sort-icon { font-size: 0.9rem; color: var(--ds-canvas-soft); transition: color 0.15s; }
.sort-icon.active { color: var(--ds-ink-mute); }

.teacher-row-wrap { border-top: 1px solid var(--ds-canvas-soft); }
.teacher-row-wrap.is-expanded { background: var(--ds-canvas-soft); }
.teacher-row {
  cursor: pointer;
  transition: background 0.12s;
}
.teacher-row:hover { background: var(--ds-canvas-soft); }

.expand-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 6px;
  background: var(--ds-canvas-soft);
  margin-right: 6px;
  flex-shrink: 0;
  transition: background 0.15s;
}
.expand-btn .material-symbols-outlined { font-size: 1rem; transition: transform 0.2s; color: var(--ds-ink-mute); }
.expand-btn.expanded { background: var(--ds-canvas-soft); }
.expand-btn.expanded .material-symbols-outlined { transform: rotate(180deg); color: var(--ds-ink-mute); }

.teacher-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--ds-ink-mute), var(--ds-ink-mute));
  color: var(--ds-canvas);
  font-size: 0.75rem;
  font-weight: 700;
  margin-right: 8px;
  flex-shrink: 0;
}

.col-name { display: flex; align-items: center; font-weight: 500; color: var(--ds-ink); gap: 4px; flex-wrap: wrap; }
.col-hours { color: var(--ds-ink); font-weight: 500; }

.override-tag {
  display: inline-flex;
  align-items: center;
  padding: 1px 6px;
  font-size: 0.65rem;
  font-weight: 600;
  color: var(--ds-ink-mute);
  background: var(--ds-canvas-soft);
  border-radius: 4px;
  white-space: nowrap;
}
.teacher-rule-btn {
  margin-left: 8px;
  gap: 2px;
}
.col-unit { font-size: 0.7rem; color: var(--ds-hairline); margin-left: 1px; }

.level-pill { font-size: 0.75rem; font-weight: 600; }
.level-pill.high { color: var(--ds-ink-mute); }
.level-pill.junior { color: var(--ds-ink-mute); }
.level-pill.elem { color: var(--ds-success); }
.level-pill.tutor { color: var(--ds-warning); }

.salary-chip {
  display: inline-block;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
  font-weight: 700;
  font-size: 0.82rem;
  padding: 3px 10px;
  border-radius: 8px;
  border: 1px solid var(--ds-canvas-soft);
}

.count-badge {
  display: inline-block;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
  font-weight: 600;
  font-size: 0.78rem;
  padding: 2px 8px;
  border-radius: 6px;
}

/* ─── Session Detail ─────────────────────── */
.expand-slide-enter-active { animation: slideDown 0.18s ease-out; }
.expand-slide-leave-active { animation: slideUp 0.15s ease-in; }
@keyframes slideDown {
  from { opacity: 0; transform: translateY(-6px); }
  to { opacity: 1; transform: translateY(0); }
}
@keyframes slideUp {
  from { opacity: 1; transform: translateY(0); }
  to { opacity: 0; transform: translateY(-4px); }
}

.session-detail {
  background: var(--ds-canvas-soft);
  padding: 14px 20px 14px 32px;
  border-top: 1px solid var(--ds-canvas-soft);
}
.session-loading, .session-empty {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--ds-hairline);
  font-size: 0.85rem;
  padding: 8px 0;
}
.session-table { font-size: 0.79rem; }
.session-header,
.session-row {
  display: grid;
  grid-template-columns: 0.9fr 1.2fr 1fr 0.7fr 0.8fr 0.6fr 1.6fr 0.6fr 0.8fr;
  padding: 6px 2px;
  align-items: center;
}
.session-header {
  font-weight: 700;
  color: var(--ds-hairline);
  border-bottom: 1px solid var(--ds-canvas-soft);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.session-row {
  border-bottom: 1px solid var(--ds-canvas-soft);
  color: var(--ds-ink);
}
.session-row:last-child { border-bottom: none; }

.level-tag {
  display: inline-block;
  font-size: 0.72rem;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 4px;
}
.level-tag.high { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.level-tag.junior { background: var(--ds-canvas-soft); color: var(--ds-ink); }
.level-tag.elem { background: var(--ds-success-wash); color: var(--ds-success); }
.level-tag.tutoring { background: var(--ds-warning-wash); color: var(--ds-warning); }

.type-tag {
  display: inline-block;
  font-size: 0.72rem;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
  padding: 1px 6px;
  border-radius: 4px;
  font-weight: 500;
}

.rate-formula { display: flex; align-items: center; gap: 3px; font-size: 0.75rem; }
.rate-base { color: var(--ds-ink); font-weight: 500; }
.rate-op { color: var(--ds-hairline); }
.rate-bonus { color: var(--ds-ink-mute); }
.rate-eff { color: var(--ds-ink-mute); font-weight: 700; }

.s-cb { font-size: 0.75rem; color: var(--ds-hairline); text-align: center; }
.s-cb.has-cb { color: var(--ds-success); font-weight: 600; }

/* var(--ds-ink)：併堂「最高時薪」說明列（橫跨整列，視覺上附屬於上一堂）*/
.session-concurrency-note {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px 10px;
  padding: 4px 10px 8px 28px;
  margin-top: -4px;
  font-size: 0.72rem;
  color: var(--ds-success);
  background: var(--ds-success-wash);
  border-bottom: 1px solid var(--ds-canvas-soft);
}
.session-concurrency-note .material-symbols-outlined { font-size: 1rem; color: var(--ds-success); }
.session-concurrency-note .cc-seg { white-space: nowrap; }

.session-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 2px 2px;
  font-size: 0.83rem;
  color: var(--ds-ink);
  border-top: 1px solid var(--ds-canvas-soft);
  margin-top: 4px;
}
.session-subtotal {
  display: flex;
  align-items: center;
  gap: 5px;
}
.session-subtotal .material-symbols-outlined { font-size: 0.95rem; color: var(--ds-hairline); }
.session-count-info { color: var(--ds-hairline); font-weight: 400; }
.session-paging { display: flex; align-items: center; gap: 6px; }
.paging-info { font-size: 0.78rem; color: var(--ds-ink-mute); min-width: 36px; text-align: center; }

/* ─── Modals ─────────────────────────────── */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  backdrop-filter: blur(2px);
}
.modal {
  background: var(--ds-canvas);
  border-radius: 16px;
  padding: 28px;
  width: 90%;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
}
.modal-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 10px;
}
.modal-header h3 { margin: 0; font-size: 1.05rem; color: var(--ds-ink); }
.modal-header-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
}
/* trick: use the span as icon container */
.modal-header-icon.primary { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.modal-header-icon.warning { background: var(--ds-warning-wash); color: var(--ds-warning); }
.modal-desc { color: var(--ds-ink-mute); font-size: 0.88rem; margin: 0 0 16px; }
.modal-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 20px;
  align-items: center;
}
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 0.8rem; font-weight: 600; color: var(--ds-ink); }
.form-group textarea {
  padding: 10px 12px;
  border: 1.5px solid var(--ds-canvas-soft);
  border-radius: 8px;
  font-size: 0.875rem;
  resize: vertical;
  transition: border-color 0.15s;
}
.form-group textarea:focus { outline: none; border-color: var(--ds-ink-mute); }

/* ─── Rules Modal ────────────────────────── */
.rules-modal { max-width: 540px; }
.rules-version { display: block; font-size: 0.72rem; color: var(--ds-hairline); margin-top: 2px; }
.rules-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 0.83rem;
  margin-bottom: 18px;
}
.rules-banner.info { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); border: 1px solid var(--ds-canvas-soft); }
.rules-banner .material-symbols-outlined { font-size: 1rem; flex-shrink: 0; }
.rules-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 28px;
  justify-content: center;
  color: var(--ds-hairline);
  font-size: 0.88rem;
}
.rules-fieldset {
  border: 1.5px solid var(--ds-canvas-soft);
  border-radius: 12px;
  padding: 16px 18px;
  margin-bottom: 16px;
}
.rules-fieldset legend {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--ds-ink);
  padding: 0 8px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.rules-fieldset:disabled { opacity: 0.55; pointer-events: none; }
.rules-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.rules-field { display: flex; flex-direction: column; gap: 5px; }
.rules-field label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.78rem;
  color: var(--ds-ink);
  font-weight: 600;
}
.level-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.level-dot.high { background: var(--ds-ink-mute); }
.level-dot.junior { background: var(--ds-ink-mute); }
.level-dot.elementary { background: var(--ds-success); }
.level-dot.tutoring { background: var(--ds-warning); }

.input-with-unit { display: flex; align-items: center; gap: 8px; }
.input-with-unit input {
  flex: 1;
  padding: 8px 10px;
  border: 1.5px solid var(--ds-canvas-soft);
  border-radius: 8px;
  font-size: 0.9rem;
  transition: border-color 0.15s;
}
.input-with-unit input:focus { outline: none; border-color: var(--ds-ink-mute); }
.input-with-unit input.has-error { border-color: var(--ds-danger); }
.input-with-unit .unit { font-size: 0.75rem; color: var(--ds-hairline); white-space: nowrap; }
.field-error { font-size: 0.72rem; color: var(--ds-danger); }
.rules-hint { font-size: 0.78rem; color: var(--ds-hairline); margin: 0 0 12px; display: flex; align-items: center; gap: 4px; }
.rule-history-list { display: flex; flex-direction: column; gap: 6px; }
.rule-history-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.8rem;
  padding: 6px 8px;
  border: 1px solid var(--ds-canvas-soft);
  border-radius: 8px;
  background: var(--ds-canvas);
}
.rh-date { color: var(--ds-ink); font-weight: 600; }
.rh-type { color: var(--ds-ink-mute); }

/* ─── Spinner ────────────────────────────── */
.spin { animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

/* ─── Responsive ─────────────────────────── */
@media (max-width: 768px) {
  .summary-row { grid-template-columns: repeat(2, 1fr); }
  .skeleton-cards { grid-template-columns: repeat(2, 1fr); }
  .summary-value.big { font-size: 1.25rem; }
  .table-header, .teacher-row { grid-template-columns: 2fr 1fr 1.6fr; }
  .session-header, .session-row { grid-template-columns: 0.9fr 1.2fr 0.7fr 0.6fr 0.8fr; }
  .hide-mobile { display: none !important; }
  .rules-grid { grid-template-columns: 1fr; }
  .rules-modal { max-width: 95vw; }
  .payroll-card { padding: 16px; }
  .page-header { gap: 10px; }
  .page-icon { width: 40px; height: 40px; border-radius: 11px; }
}

@media (max-width: 480px) {
  .summary-card { flex-direction: column; align-items: flex-start; gap: 8px; padding: 14px; }
  .summary-row { gap: 10px; }
  .page-header { flex-direction: column; align-items: flex-start; }
  .header-btns { width: 100%; }
  .btn-outline { flex: 1; justify-content: center; }
}
</style>
