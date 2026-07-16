<template>
  <div class="dsr-page">
    <!-- Header -->
    <header class="page-header dsr-header">
      <div>
        <h2>重複課程審核</h2>
        <p class="page-desc">跨合約重複時段需逐筆確認保留哪一側，送出後系統自動取消非保留側堂次。</p>
      </div>
      <button class="primary" type="button" @click="refresh">重新整理</button>
    </header>

    <!-- Trust focus banner (from Decision Center deep-link, in-app #200) -->
    <div v-if="trustFocusLabel" class="dsr-focus-banner" role="status">
      <span class="material-symbols-outlined" aria-hidden="true">person_search</span>
      <span>已為您篩選：<strong>{{ trustFocusLabel }}</strong>（從可信度決策卡帶入）</span>
      <button type="button" class="ghost xs" @click="clearTrustFocus">顯示全部</button>
    </div>

    <!-- FilterBar -->
    <div class="dsr-filter-bar">
      <!-- Campus selector (super_admin only) -->
      <div v-if="isSuperAdmin" class="dsr-campus-select">
        <label class="dsr-filter-label" for="dsr-campus">分校</label>
        <select
          id="dsr-campus"
          class="dsr-select"
          :value="filterCampusId"
          @change="onCampusChange($event.target.value)"
        >
          <option value="">全部分校</option>
          <option v-for="b in allBranches" :key="b.id" :value="b.id">
            {{ b.name }}
          </option>
        </select>
      </div>
      <div v-else class="dsr-filter-label-wrap">
        <span class="dsr-filter-label">分校</span>
        <span class="dsr-filter-value">{{ branchName }}</span>
      </div>

      <!-- Status tabs -->
      <nav class="dsr-tabs" role="tablist" aria-label="審核狀態">
        <button
          v-for="tab in statusTabs"
          :key="tab.value"
          role="tab"
          :aria-selected="activeTab === tab.value"
          :class="['dsr-tab', { active: activeTab === tab.value }]"
          type="button"
          @click="setTab(tab.value)"
        >
          {{ tab.label }}
          <span v-if="tabCount(tab.value) > 0" class="dsr-tab-badge" :class="`dsr-tab-badge-${tab.value}`">
            {{ tabCount(tab.value) }}
          </span>
        </button>
      </nav>
    </div>

    <!-- StatsBar -->
    <div class="dsr-stats-bar">
      <div class="dsr-stat">
        <span class="dsr-stat-num">{{ total }}</span>
        <span class="dsr-stat-label">總組數</span>
      </div>
      <div class="dsr-stat">
        <span class="dsr-stat-num dsr-stat-num--executed">{{ executedCount }}</span>
        <span class="dsr-stat-label">已處理</span>
      </div>
      <div class="dsr-stat">
        <span class="dsr-stat-num dsr-stat-num--pending">{{ pendingCount }}</span>
        <span class="dsr-stat-label">待審核</span>
      </div>
    </div>

    <!-- Loading / Error / Empty states -->
    <div v-if="loading" class="dsr-state dsr-state-loading">
      <div class="dsr-spinner" aria-hidden="true"></div>
      <span>載入中…</span>
    </div>
    <div v-else-if="error" class="dsr-state dsr-state-error">
      <span class="material-symbols-outlined" aria-hidden="true">error</span>
      <span>{{ error }}</span>
      <button class="ghost xs" type="button" @click="refresh">重試</button>
    </div>
    <div v-else-if="filteredGroups.length === 0" class="dsr-state dsr-state-empty">
      <span class="material-symbols-outlined dsr-empty-icon" aria-hidden="true">task_alt</span>
      <div class="dsr-empty-title">沒有待審核的重複課程時段</div>
      <div class="dsr-empty-sub">目前沒有需要在此狀態下審核的課程重疊案件。</div>
    </div>

    <!-- ReviewTable (desktop) -->
    <div v-else class="dsr-table-wrap">
      <table class="dsr-table dsr-desktop">
        <thead>
          <tr>
            <th style="width:100px">學生</th>
            <th style="width:100px">日期／時段</th>
            <th>課程對比</th>
            <th style="width:100px">狀態</th>
            <th style="width:200px;text-align:right">操作</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="g in filteredGroups" :key="g._key">
            <!-- Main row -->
            <tr :class="{ 'dsr-row-expanded': expandedKey === g._key }">
              <td>
                <span class="dsr-student-name">{{ g.student_name || `學生 #${g.student_id}` }}</span>
              </td>
              <td>
                <div class="dsr-date">{{ formatDate(g.session_date) }}</div>
                <div class="dsr-time">{{ g.start_time }}</div>
              </td>
              <td>
                <div class="dsr-sides-grid" :class="{ 'dsr-sides-decided': g._localKeeper }">
                  <div
                    v-for="(side, si) in g.sides"
                    :key="side.student_class_id"
                    :class="[
                      'dsr-side-card',
                      {
                        'dsr-side--keeper': g._localKeeper === side.student_class_id,
                        'dsr-side--cancelled': g._localKeeper && g._localKeeper !== side.student_class_id,
                      },
                    ]"
                  >
                    <div class="dsr-side-head">
                      <label class="dsr-radio-label" v-if="!g._submitted">
                        <input
                          type="radio"
                          :name="`keeper-${g._key}`"
                          :value="side.student_class_id"
                          :checked="g._localKeeper === side.student_class_id"
                          @change="setDecision(g, side.student_class_id)"
                          class="dsr-radio"
                        />
                        <span class="dsr-side-sc">SC #{{ side.student_class_id }}</span>
                      </label>
                      <span v-else class="dsr-side-sc">SC #{{ side.student_class_id }}</span>
                      <span
                        v-if="side.has_live_lr"
                        class="dsr-lr-badge"
                        title="此側掛有評量記錄"
                        aria-label="此側掛有評量記錄"
                      >📝</span>
                    </div>
                    <div class="dsr-side-body">
                      <div class="dsr-side-row">
                        <span class="dsr-side-label">老師</span>
                        <span>{{ side.teacher_name || '—' }}</span>
                      </div>
                      <div class="dsr-side-row">
                        <span class="dsr-side-label">科目</span>
                        <span>{{ side.subject_name || '—' }}</span>
                      </div>
                      <div class="dsr-side-row">
                        <span class="dsr-side-label">堂數</span>
                        <span>{{ side.session_count ?? '—' }} 堂</span>
                        <span v-if="side.remaining_sessions != null" class="dsr-side-remaining">
                          （剩 {{ side.remaining_sessions }}）
                        </span>
                      </div>
                      <div class="dsr-side-row" v-if="side.schedule_mode">
                        <span class="dsr-side-label">模式</span>
                        <span>{{ scheduleModeLabel(side.schedule_mode) }}</span>
                      </div>
                      <div class="dsr-side-row" v-if="side.start_date">
                        <span class="dsr-side-label">開課</span>
                        <span>{{ formatDate(side.start_date) }}</span>
                      </div>
                      <div class="dsr-side-row" v-if="side.statuses && side.statuses.length">
                        <span class="dsr-side-label">狀態</span>
                        <div class="dsr-statuses">
                          <span
                            v-for="st in side.statuses"
                            :key="st"
                            :class="['dsr-session-st', `dsr-session-st--${st}`]"
                          >{{ sessionStatusLabel(st) }}</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <span :class="['dsr-status', `dsr-status-${groupStatus(g)}`]">
                  {{ statusLabel(groupStatus(g)) }}
                </span>
              </td>
              <td style="text-align:right">
                <div class="dsr-row-ops">
                  <button
                    v-if="g.sides.length > 2 || (g.sides[1] && g.sides[1].session_ids && g.sides[1].session_ids.length > 0)"
                    class="ghost xs"
                    type="button"
                    @click="toggleExpand(g)"
                  >
                    {{ expandedKey === g._key ? '收合' : '展開' }}
                  </button>
                  <button
                    v-if="!g._submitted && g._localKeeper"
                    class="primary xs"
                    type="button"
                    :disabled="submitting"
                    @click="onSubmit(g)"
                  >
                    {{ submitting ? '送出中…' : '確認送出' }}
                  </button>
                </div>
                <!-- Reason input (shown after selecting a side) -->
                <div v-if="!g._submitted && g._localKeeper" class="dsr-reason-wrap" style="margin-top:6px">
                  <input
                    type="text"
                    class="dsr-reason-input"
                    :value="g._localReason"
                    @input="setReason(g, $event.target.value)"
                    placeholder="決策原因（選填）"
                    maxlength="200"
                    aria-label="決策原因"
                  />
                </div>
              </td>
            </tr>

            <!-- Expanded detail row -->
            <tr v-if="expandedKey === g._key" class="dsr-detail-row">
              <td colspan="5">
                <div class="dsr-detail">
                  <div class="dsr-detail-sections">
                    <div
                      v-for="side in g.sides"
                      :key="'det-' + side.student_class_id"
                      class="dsr-detail-section"
                    >
                      <div class="dsr-detail-section-title">
                        SC #{{ side.student_class_id }}
                        <span v-if="g._localKeeper === side.student_class_id" class="dsr-detail-keeper-tag">保留</span>
                        <span v-else-if="g._localKeeper" class="dsr-detail-cancel-tag">取消</span>
                      </div>
                      <div class="dsr-detail-grid">
                        <div>
                          <div class="dsr-detail-label">老師</div>
                          <div class="dsr-detail-value">{{ side.teacher_name || '—' }}</div>
                        </div>
                        <div>
                          <div class="dsr-detail-label">科目</div>
                          <div class="dsr-detail-value">{{ side.subject_name || '—' }}</div>
                        </div>
                        <div>
                          <div class="dsr-detail-label">總堂數</div>
                          <div class="dsr-detail-value">{{ side.session_count ?? '—' }}</div>
                        </div>
                        <div>
                          <div class="dsr-detail-label">剩餘</div>
                          <div class="dsr-detail-value">{{ side.remaining_sessions ?? '—' }}</div>
                        </div>
                        <div>
                          <div class="dsr-detail-label">排課模式</div>
                          <div class="dsr-detail-value">{{ scheduleModeLabel(side.schedule_mode) || '—' }}</div>
                        </div>
                        <div>
                          <div class="dsr-detail-label">開課日期</div>
                          <div class="dsr-detail-value">{{ formatDate(side.start_date) || '—' }}</div>
                        </div>
                        <div>
                          <div class="dsr-detail-label">評量記錄</div>
                          <div class="dsr-detail-value">{{ side.has_live_lr ? '有' : '無' }}</div>
                        </div>
                      </div>
                      <div v-if="side.session_ids && side.session_ids.length" class="dsr-detail-sessions">
                        <div class="dsr-detail-label">堂次列表</div>
                        <div class="dsr-session-list">
                          <div
                            v-for="(sid, sIdx) in side.session_ids"
                            :key="sid"
                            class="dsr-session-item"
                          >
                            <span class="dsr-session-id">#{{ sid }}</span>
                            <span
                              v-if="side.statuses && side.statuses[sIdx]"
                              :class="['dsr-session-st', `dsr-session-st--${side.statuses[sIdx]}`]"
                            >{{ sessionStatusLabel(side.statuses[sIdx]) }}</span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>

      <!-- Mobile card list -->
      <div class="dsr-mobile">
        <article
          v-for="g in filteredGroups"
          :key="'m-' + g._key"
          class="dsr-mcard"
        >
          <header class="dsr-mcard-head">
            <div class="dsr-mcard-head-left">
              <span class="dsr-student-name">{{ g.student_name || `學生 #${g.student_id}` }}</span>
            </div>
            <span :class="['dsr-status', `dsr-status-${groupStatus(g)}`]">
              {{ statusLabel(groupStatus(g)) }}
            </span>
          </header>
          <div class="dsr-mcard-meta">
            <div><span class="dsr-mcard-label">日期</span> {{ formatDate(g.session_date) }}</div>
            <div><span class="dsr-mcard-label">時段</span> {{ g.start_time }}</div>
          </div>
          <div class="dsr-mcard-sides">
            <div
              v-for="side in g.sides"
              :key="side.student_class_id"
              :class="[
                'dsr-mcard-side',
                {
                  'dsr-mcard-side--keeper': g._localKeeper === side.student_class_id,
                  'dsr-mcard-side--cancelled': g._localKeeper && g._localKeeper !== side.student_class_id,
                },
              ]"
            >
              <div class="dsr-mcard-side-head">
                <label class="dsr-radio-label" v-if="!g._submitted">
                  <input
                    type="radio"
                    :name="`m-keeper-${g._key}`"
                    :value="side.student_class_id"
                    :checked="g._localKeeper === side.student_class_id"
                    @change="setDecision(g, side.student_class_id)"
                    class="dsr-radio"
                  />
                  SC #{{ side.student_class_id }}
                </label>
                <span v-else>SC #{{ side.student_class_id }}</span>
                <span
                  v-if="side.has_live_lr"
                  class="dsr-lr-badge"
                  title="此側掛有評量記錄"
                >📝</span>
              </div>
              <div class="dsr-mcard-side-body">
                <span>{{ side.teacher_name || '—' }}</span>
                <span>· {{ side.subject_name || '—' }}</span>
                <span>· {{ side.session_count ?? '—' }} 堂</span>
                <span v-if="side.remaining_sessions != null" class="dsr-side-remaining">
                  （剩 {{ side.remaining_sessions }}）
                </span>
              </div>
            </div>
          </div>
          <div v-if="!g._submitted && g._localKeeper" class="dsr-mcard-reason">
            <input
              type="text"
              class="dsr-reason-input"
              :value="g._localReason"
              @input="setReason(g, $event.target.value)"
              placeholder="決策原因（選填）"
              maxlength="200"
              aria-label="決策原因"
            />
          </div>
          <div class="dsr-mcard-ops">
            <button
              v-if="g.sides.length > 2 || (g.sides[0] && g.sides[0].session_ids && g.sides[0].session_ids.length > 0)"
              class="ghost xs"
              type="button"
              @click="toggleExpand(g)"
            >
              {{ expandedKey === g._key ? '收合' : '展開' }}
            </button>
            <button
              v-if="!g._submitted && g._localKeeper"
              class="primary xs"
              type="button"
              :disabled="submitting"
              @click="onSubmit(g)"
            >
              {{ submitting ? '…' : '確認送出' }}
            </button>
          </div>
          <!-- Mobile expanded detail -->
          <div v-if="expandedKey === g._key" class="dsr-mcard-detail">
            <div
              v-for="side in g.sides"
              :key="'md-' + side.student_class_id"
              class="dsr-mcard-detail-section"
            >
              <div class="dsr-detail-section-title">
                SC #{{ side.student_class_id }}
                <span v-if="g._localKeeper === side.student_class_id" class="dsr-detail-keeper-tag">保留</span>
                <span v-else-if="g._localKeeper" class="dsr-detail-cancel-tag">取消</span>
              </div>
              <div class="dsr-mcard-detail-grid">
                <div><span class="dsr-mcard-label">老師</span> {{ side.teacher_name || '—' }}</div>
                <div><span class="dsr-mcard-label">科目</span> {{ side.subject_name || '—' }}</div>
                <div><span class="dsr-mcard-label">總堂數</span> {{ side.session_count ?? '—' }}</div>
                <div><span class="dsr-mcard-label">剩餘</span> {{ side.remaining_sessions ?? '—' }}</div>
                <div><span class="dsr-mcard-label">開課</span> {{ formatDate(side.start_date) || '—' }}</div>
                <div><span class="dsr-mcard-label">評量</span> {{ side.has_live_lr ? '有' : '無' }}</div>
              </div>
              <div v-if="side.session_ids && side.session_ids.length">
                <div class="dsr-mcard-label" style="margin-bottom:4px">堂次</div>
                <div class="dsr-session-list">
                  <div
                    v-for="(sid, sIdx) in side.session_ids"
                    :key="sid"
                    class="dsr-session-item"
                  >
                    <span class="dsr-session-id">#{{ sid }}</span>
                    <span
                      v-if="side.statuses && side.statuses[sIdx]"
                      :class="['dsr-session-st', `dsr-session-st--${side.statuses[sIdx]}`]"
                    >{{ sessionStatusLabel(side.statuses[sIdx]) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </article>
      </div>
    </div>

    <!-- Submit error -->
    <div v-if="submitError" class="dsr-error" role="alert">{{ submitError }}</div>

    <!-- Toast -->
    <Teleport to="body">
      <Transition name="dsr-toast">
        <div v-if="toast.visible" class="dsr-toast" :class="`dsr-toast-${toast.tone}`" role="status">
          <span class="material-symbols-outlined" aria-hidden="true">
            {{ toast.tone === 'success' ? 'check_circle' : 'error' }}
          </span>
          <span>{{ toast.text }}</span>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, reactive } from 'vue';
import { branches } from '../lib/useBranches';
import { useDuplicateReview, groupKey } from '../composables/useDuplicateReview';
import { STATUS_LABELS, SESSION_STATUS_LABELS } from '../lib/duplicateReviewApi';
import { parseTrustFocus, filterGroupsByTrustFocus } from '../lib/trustDecisionDisplay.js';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: 'director' },
});

const isSuperAdmin = computed(() => props.userRole === 'super_admin');
const allBranches = computed(() => branches.value);

const branchName = computed(() => {
  const id = Number(props.branchId);
  if (!id) return '未知分校';
  const b = allBranches.value.find((bb) => bb.id === id);
  return b?.name || `分校 #${id}`;
});

const filterCampusId = ref('');
const trustFocusName = ref('');
const trustFocusStudentId = ref(0);

const trustFocusLabel = computed(() => {
  const name = String(trustFocusName.value || '').trim();
  if (name) return name;
  const sid = Number(trustFocusStudentId.value) || 0;
  return sid > 0 ? `學生 #${sid}` : '';
});

function applyTrustFocusFromStorage() {
  try {
    const raw = sessionStorage.getItem('alltrue_ops_trust_focus');
    if (!raw) return;
    const focus = parseTrustFocus(raw);
    if (!focus) {
      sessionStorage.removeItem('alltrue_ops_trust_focus');
      return;
    }
    trustFocusName.value = focus.studentName;
    trustFocusStudentId.value = focus.studentId;
    sessionStorage.removeItem('alltrue_ops_trust_focus');
    activeTab.value = 'pending';
  } catch (_) { /* ignore */ }
}

function clearTrustFocus() {
  trustFocusName.value = '';
  trustFocusStudentId.value = 0;
}

const {
  groups,
  total,
  loading,
  error,
  activeTab,
  decisions,
  submitting,
  submitError,
  expandedKey,
  pendingCount,
  executedCount,
  groupsWithLocalDecisions,
  load,
  setDecision,
  setReason,
  submitDecision,
  clearDecision,
} = useDuplicateReview();

const toast = reactive({ visible: false, text: '', tone: 'success' });
let toastTimer = null;

const statusTabs = [
  { value: 'pending', label: '待審核' },
  { value: 'all', label: '全部' },
];

function statusLabel(s) { return STATUS_LABELS[s] || s; }
function sessionStatusLabel(s) { return SESSION_STATUS_LABELS[s] || s; }

function scheduleModeLabel(mode) {
  const map = {
    count: '固定堂數',
    weekly: '每週固定',
    by_period: '依期間',
    irregular: '不定期',
  };
  return map[mode] || mode || '';
}

function groupStatus(g) {
  return g._submitted ? 'executed' : 'pending';
}

/** Only show groups matching active tab + optional trust focus */
const filteredGroups = computed(() => {
  let list = groupsWithLocalDecisions.value;
  if (activeTab.value === 'pending') list = list.filter((g) => !g._submitted);
  const focus = trustFocusName.value || trustFocusStudentId.value
    ? { studentName: trustFocusName.value, studentId: trustFocusStudentId.value }
    : null;
  return filterGroupsByTrustFocus(list, focus);
});

function formatDate(s) {
  if (!s) return '';
  try {
    const d = new Date(s);
    return `${d.getMonth() + 1}/${d.getDate()}`;
  } catch { return s; }
}

function tabCount(tabValue) {
  if (tabValue === 'pending') return pendingCount.value;
  return total.value;
}

function showToast(text, tone = 'success') {
  if (toastTimer) clearTimeout(toastTimer);
  toast.text = text;
  toast.tone = tone;
  toast.visible = true;
  toastTimer = setTimeout(() => { toast.visible = false; }, 4000);
}

function setTab(v) {
  activeTab.value = v;
  expandedKey.value = null;
  submitError.value = '';
  doLoad();
}

function onCampusChange(val) {
  filterCampusId.value = val;
  doLoad();
}

function toggleExpand(g) {
  expandedKey.value = expandedKey.value === g._key ? null : g._key;
}

async function doLoad() {
  const campusId = isSuperAdmin.value
    ? (filterCampusId.value || undefined)
    : (props.branchId || undefined);
  const status = activeTab.value;
  await load({ campusId, status });
}

async function onSubmit(g) {
  submitError.value = '';
  try {
    const result = await submitDecision(g);
    expandedKey.value = null;
    const cancelled = result?.cancelled_count ?? 0;
    const kept = result?.kept_session_ids?.length ?? 0;
    showToast(`已取消 ${cancelled} 筆堂次，保留 ${kept} 筆。`);
  } catch {
    showToast(submitError.value || '提交失敗', 'error');
  }
}

function refresh() {
  expandedKey.value = null;
  submitError.value = '';
  doLoad();
}

watch(() => props.branchId, () => {
  if (!isSuperAdmin.value) doLoad();
});

onMounted(() => {
  applyTrustFocusFromStorage();
  doLoad();
});
</script>

<style scoped>
.dsr-page { max-width: 1200px; margin: 0 auto; }

.dsr-focus-banner {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
  padding: 10px 14px;
  border-radius: 12px;
  border: 1px solid var(--ds-info);
  background: var(--ds-info-wash);
  font-size: 13px;
  color: var(--ds-ink);
}
.dsr-focus-banner .material-symbols-outlined { font-size: 20px; color: var(--ds-info); }

.dsr-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}

/* ── FilterBar ── */
.dsr-filter-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 16px;
  margin-bottom: 12px;
}
.dsr-filter-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light);
  display: block;
  margin-bottom: 4px;
}
.dsr-filter-label-wrap {
  display: flex;
  flex-direction: column;
}
.dsr-filter-value {
  font-size: 14px;
  font-weight: 600;
  color: var(--text);
}
.dsr-select {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 7px 12px;
  font-size: 14px;
  background: var(--card-bg);
  color: var(--text);
  min-width: 160px;
  cursor: pointer;
}
.dsr-select:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(239, 108, 0, 0.15);
}

/* Tabs */
.dsr-tabs {
  display: flex;
  gap: 4px;
  border-bottom: 1px solid var(--border);
  flex: 1;
  min-width: 200px;
  overflow-x: auto;
}
.dsr-tab {
  background: transparent;
  border: 0;
  padding: 10px 16px;
  min-height: 44px;
  font-size: 14px;
  font-weight: 600;
  color: var(--text-light);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}
.dsr-tab.active {
  color: var(--primary);
  border-bottom-color: var(--primary);
}
.dsr-tab-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  background: var(--border);
  color: var(--text);
}
.dsr-tab-badge-pending { background: #fff7ed; color: #b54708; }
.dsr-tab-badge-all { background: var(--border); color: var(--text); }

/* ── StatsBar ── */
.dsr-stats-bar {
  display: flex;
  gap: 16px;
  margin-bottom: 16px;
}
.dsr-stat {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px 20px;
  text-align: center;
  min-width: 100px;
}
.dsr-stat-num {
  display: block;
  font-size: 24px;
  font-weight: 700;
  color: var(--text);
}
.dsr-stat-num--executed { color: #1a8245; }
.dsr-stat-num--pending { color: #b54708; }
.dsr-stat-label {
  font-size: 12px;
  color: var(--text-light);
}

/* ── States ── */
.dsr-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 48px 16px;
  color: var(--text-light);
  text-align: center;
}
.dsr-state-error { color: var(--danger); }
.dsr-empty-icon {
  font-size: 48px;
  color: var(--success);
}
.dsr-empty-title { font-size: 16px; font-weight: 700; color: var(--text); }
.dsr-empty-sub { font-size: 13px; color: var(--text-light); max-width: 360px; }
.dsr-spinner {
  width: 24px;
  height: 24px;
  border: 3px solid rgba(0,0,0,0.08);
  border-top-color: var(--primary);
  border-radius: 50%;
  animation: dsr-spin 0.9s linear infinite;
}
@keyframes dsr-spin { to { transform: rotate(360deg); } }

/* ── Table ── */
.dsr-table-wrap {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 12px;
  margin-bottom: 12px;
}
.dsr-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.dsr-table th, .dsr-table td {
  padding: 10px 8px;
  border-bottom: 1px solid var(--border);
  text-align: left;
  vertical-align: top;
}
.dsr-table th {
  background: var(--bg);
  font-weight: 600;
  color: var(--text-light);
  font-size: 12px;
}
.dsr-row-expanded { background: var(--bg); }
.dsr-detail-row td { background: var(--bg); padding: 0; }

.dsr-student-name { font-weight: 600; color: var(--text); }
.dsr-date { font-size: 12px; color: var(--text); }
.dsr-time { font-size: 12px; color: var(--text-light); font-family: 'SFMono-Regular', ui-monospace, monospace; }

/* ── Side-by-side SC cards ── */
.dsr-sides-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.dsr-side-card {
  border: 2px solid var(--border);
  border-radius: 8px;
  padding: 8px 10px;
  transition: border-color 0.2s, background 0.2s;
}
.dsr-side--keeper {
  border-color: var(--success);
  background: #ecfdf5;
}
.dsr-side--cancelled {
  border-color: var(--danger);
  border-style: dashed;
  background: #fef2f2;
  opacity: 0.85;
}
.dsr-side-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}
.dsr-radio-label {
  display: flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
}
.dsr-radio {
  accent-color: var(--primary);
  width: 16px;
  height: 16px;
  cursor: pointer;
}
.dsr-side-sc { font-weight: 600; color: var(--text); }
.dsr-lr-badge {
  font-size: 14px;
  cursor: help;
  margin-left: 4px;
}
.dsr-side-body { display: flex; flex-direction: column; gap: 3px; font-size: 12px; }
.dsr-side-row { display: flex; align-items: center; gap: 6px; }
.dsr-side-label { color: var(--text-light); font-weight: 600; width: 36px; flex-shrink: 0; }
.dsr-side-remaining { color: var(--text-light); font-size: 11px; }
.dsr-statuses { display: flex; flex-wrap: wrap; gap: 3px; }
.dsr-session-st {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 600;
}
.dsr-session-st--attended { background: #ecfdf5; color: #047857; }
.dsr-session-st--completed { background: #dbeafe; color: #1d4ed8; }
.dsr-session-st--late { background: #fff7ed; color: #b54708; }
.dsr-session-st--leave { background: var(--border); color: var(--text-light); }
.dsr-session-st--cancelled { background: #fef2f2; color: #dc2626; }
.dsr-session-st--absent { background: #fef2f2; color: #dc2626; }
.dsr-session-st--scheduled { background: var(--border); color: var(--text-light); }

.dsr-sides-decided .dsr-side-card:not(.dsr-side--keeper):not(.dsr-side--cancelled) {
  opacity: 0.5;
}

/* ── Status badges ── */
.dsr-status {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  border: 1px solid transparent;
  white-space: nowrap;
}
.dsr-status-pending { background: var(--border); color: var(--text-light); border-color: var(--border); }
.dsr-status-executed { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }

.dsr-row-ops { display: inline-flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }

/* ── Reason input ── */
.dsr-reason-input {
  width: 100%;
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 5px 8px;
  font-size: 12px;
  color: var(--text);
  background: var(--card-bg);
  box-sizing: border-box;
}
.dsr-reason-input:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(239, 108, 0, 0.15);
}
.dsr-reason-input::placeholder { color: var(--text-light); }

/* ── Expanded detail ── */
.dsr-detail { padding: 12px 16px; display: flex; flex-direction: column; gap: 12px; }
.dsr-detail-sections { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.dsr-detail-section {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 10px;
}
.dsr-detail-section-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.dsr-detail-keeper-tag {
  font-size: 11px;
  font-weight: 600;
  background: #ecfdf5;
  color: #047857;
  padding: 1px 8px;
  border-radius: 999px;
}
.dsr-detail-cancel-tag {
  font-size: 11px;
  font-weight: 600;
  background: #fef2f2;
  color: #dc2626;
  padding: 1px 8px;
  border-radius: 999px;
}
.dsr-detail-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(60px, 1fr));
  gap: 8px 12px;
  margin-bottom: 8px;
}
.dsr-detail-label { font-size: 12px; color: var(--text-light); font-weight: 600; margin-bottom: 2px; }
.dsr-detail-value { font-size: 13px; color: var(--text); word-break: break-word; }
.dsr-detail-sessions { margin-top: 8px; }
.dsr-session-list { display: flex; flex-wrap: wrap; gap: 6px; }
.dsr-session-item { display: flex; align-items: center; gap: 6px; }
.dsr-session-id { font-family: 'SFMono-Regular', ui-monospace, monospace; font-size: 12px; color: var(--text-light); }

/* ── Error ── */
.dsr-error {
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fecdd3;
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 13px;
  margin-top: 8px;
}

/* ── Buttons ── */
.primary {
  background: var(--primary);
  color: #fff;
  border: none;
  padding: 9px 18px;
  border-radius: 999px;
  font-weight: 600;
  cursor: pointer;
  font-size: 14px;
  white-space: nowrap;
}
.primary:hover:not(:disabled) { background: #e65100; }
.primary:disabled { opacity: 0.55; cursor: default; }
.ghost {
  background: transparent;
  border: 1px solid var(--border);
  color: var(--text);
  padding: 5px 12px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.ghost:hover:not(:disabled) { background: var(--bg); }
.xs { padding: 4px 10px; font-size: 11px; }

/* ── Mobile ── */
.dsr-mobile { display: none; }
.dsr-mcard {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px;
  margin-bottom: 10px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.dsr-mcard-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.dsr-mcard-head-left { display: flex; align-items: center; gap: 8px; }
.dsr-mcard-meta { display: flex; flex-wrap: wrap; gap: 8px; font-size: 13px; color: var(--text); }
.dsr-mcard-label { color: var(--text-light); font-weight: 600; margin-right: 4px; font-size: 12px; }
.dsr-mcard-sides { display: flex; flex-direction: column; gap: 6px; }
.dsr-mcard-side {
  border: 2px solid var(--border);
  border-radius: 8px;
  padding: 8px 10px;
  transition: border-color 0.2s, background 0.2s;
}
.dsr-mcard-side--keeper { border-color: var(--success); background: #ecfdf5; }
.dsr-mcard-side--cancelled { border-color: var(--danger); border-style: dashed; background: #fef2f2; }
.dsr-mcard-side-head { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; font-weight: 600; }
.dsr-mcard-side-body { display: flex; flex-wrap: wrap; gap: 4px 8px; font-size: 12px; color: var(--text); }
.dsr-mcard-reason { margin-top: 4px; }
.dsr-mcard-ops { display: flex; gap: 6px; justify-content: flex-end; }
.dsr-mcard-detail {
  border-top: 1px dashed var(--border);
  padding-top: 8px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.dsr-mcard-detail-section {
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 8px;
}
.dsr-mcard-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 8px; font-size: 12px; }

/* ── Toast ── */
.dsr-toast {
  position: fixed;
  top: 24px;
  right: 24px;
  z-index: 10060;
  background: var(--success);
  color: #fff;
  padding: 12px 18px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.2);
}
.dsr-toast-error { background: var(--danger); }
.dsr-toast-enter-active, .dsr-toast-leave-active { transition: all 200ms ease; }
.dsr-toast-enter-from, .dsr-toast-leave-to { opacity: 0; transform: translateY(-8px); }

@media (max-width: 768px) {
  .dsr-desktop { display: none; }
  .dsr-mobile { display: block; }
  .dsr-stats-bar { gap: 8px; }
  .dsr-stat { padding: 8px 14px; min-width: 80px; }
  .dsr-stat-num { font-size: 20px; }
  .dsr-filter-bar { flex-direction: column; align-items: stretch; }
  .dsr-select { width: 100%; }
  .dsr-sides-grid { grid-template-columns: 1fr; }
}

@media (max-width: 480px) {
  .dsr-toast { top: 12px; right: 12px; left: 12px; }
}
</style>
