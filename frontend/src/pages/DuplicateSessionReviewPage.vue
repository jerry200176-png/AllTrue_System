<template>
  <div class="dsr-page">
    <!-- Header -->
    <header class="page-header dsr-header">
      <div>
        <h2>重疊課程審核</h2>
        <p class="page-desc">新舊課程重疊產生重複課表，請逐筆確認保留哪一側。</p>
      </div>
      <button class="primary" type="button" @click="refresh">重新整理</button>
    </header>

    <!-- FilterBar -->
    <div class="dsr-filter-bar">
      <!-- Campus selector (super_admin only) -->
      <div v-if="isSuperAdmin" class="dsr-campus-select">
        <label class="dsr-filter-label" for="dsr-campus">分校</label>
        <select
          id="dsr-campus"
          class="dsr-select"
          :value="filterCampusId"
          @change="onCampusChange(($event.target).value)"
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
        <span class="dsr-stat-num dsr-stat-num--decided">{{ decidedCount }}</span>
        <span class="dsr-stat-label">已審核</span>
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
    <div v-else-if="groupsWithLocalDecisions.length === 0" class="dsr-state dsr-state-empty">
      <span class="material-symbols-outlined dsr-empty-icon" aria-hidden="true">task_alt</span>
      <div class="dsr-empty-title">暫無待審核的重疊課程</div>
      <div class="dsr-empty-sub">目前沒有需要在這個狀態下審核的課程重疊案件。</div>
    </div>

    <!-- ReviewTable (desktop) -->
    <div v-else class="dsr-table-wrap">
      <table class="dsr-table dsr-desktop">
        <thead>
          <tr>
            <th style="width:40px">
              <input
                type="checkbox"
                :checked="allSelected"
                :indeterminate="someSelected && !allSelected"
                @change="toggleSelectAll"
                title="全選"
              />
            </th>
            <th style="width:100px">學生</th>
            <th style="width:100px">日期／時段</th>
            <th>課程對比</th>
            <th style="width:100px">狀態</th>
            <th style="width:120px;text-align:right">操作</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="g in groupsWithLocalDecisions" :key="g._key">
            <!-- Main row -->
            <tr :class="{ 'dsr-row-expanded': expandedKey === g._key }">
              <td>
                <input
                  type="checkbox"
                  :checked="isSelected(g)"
                  :disabled="g.review && g.review.status === 'executed'"
                  @change="toggleSelect(g)"
                />
              </td>
              <td>
                <span class="dsr-student-name">{{ g.student_name || `#${g.student_id}` }}</span>
              </td>
              <td>
                <div class="dsr-date">{{ formatDate(g.session_date) }}</div>
                <div class="dsr-time">{{ g.start_time }}</div>
              </td>
              <td>
                <div class="dsr-sides-grid" :class="{ 'dsr-sides-decided': g._localKeeper }">
                  <div
                    v-for="(side, si) in g.sides"
                    :key="side.sc_id"
                    :class="[
                      'dsr-side-card',
                      {
                        'dsr-side--keeper': g._localKeeper === side.sc_id,
                        'dsr-side--cancelled': g._localKeeper && g._localKeeper !== side.sc_id,
                      },
                    ]"
                  >
                    <div class="dsr-side-head">
                      <label class="dsr-radio-label" v-if="canEdit(g)">
                        <input
                          type="radio"
                          :name="`keeper-${g._key}`"
                          :value="side.sc_id"
                          :checked="g._localKeeper === side.sc_id"
                          @change="setDecision(g, side.sc_id)"
                          class="dsr-radio"
                        />
                        <span class="dsr-side-sc">SC #{{ side.sc_id }}</span>
                      </label>
                      <span v-else class="dsr-side-sc">SC #{{ side.sc_id }}</span>
                      <span
                        v-if="side.has_live_lr"
                        class="dsr-lr-badge"
                        title="此側掛有評量記錄"
                        aria-label="此側掛有評量記錄"
                      >📝</span>
                    </div>
                    <div class="dsr-side-body">
                      <div class="dsr-side-row">
                        <span class="dsr-side-label">科目</span>
                        <span>{{ side.subject_name || '—' }}</span>
                      </div>
                      <div class="dsr-side-row">
                        <span class="dsr-side-label">堂數</span>
                        <span>{{ side.session_count ?? '—' }}</span>
                      </div>
                      <div class="dsr-side-row">
                        <span class="dsr-side-label">狀態</span>
                        <span v-if="side.stop" class="dsr-stop-badge">已中止</span>
                        <span v-else class="dsr-active-badge">進行中</span>
                      </div>
                      <div class="dsr-side-row" v-if="side.statuses && side.statuses.length">
                        <span class="dsr-side-label">出席</span>
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
                <span :class="['dsr-status', `dsr-status-${reviewStatus(g)}`]">
                  {{ statusLabel(reviewStatus(g)) }}
                </span>
              </td>
              <td style="text-align:right">
                <div class="dsr-row-ops">
                  <button class="ghost xs" type="button" @click="toggleExpand(g)">
                    {{ expandedKey === g._key ? '收合' : '展開' }}
                  </button>
                  <button
                    v-if="canSaveSingle(g)"
                    class="primary xs"
                    type="button"
                    :disabled="saving"
                    @click="saveSingle(g)"
                  >
                    {{ saving ? '儲存中…' : '儲存決策' }}
                  </button>
                </div>
              </td>
            </tr>

            <!-- Expanded detail row -->
            <tr v-if="expandedKey === g._key" class="dsr-detail-row">
              <td colspan="6">
                <div class="dsr-detail">
                  <div class="dsr-detail-sections">
                    <div
                      v-for="(side, si) in g.sides"
                      :key="'det-' + side.sc_id"
                      class="dsr-detail-section"
                    >
                      <div class="dsr-detail-section-title">
                        SC #{{ side.sc_id }}
                        <span v-if="g._localKeeper === side.sc_id" class="dsr-detail-keeper-tag">保留</span>
                        <span v-else-if="g._localKeeper" class="dsr-detail-cancel-tag">取消</span>
                      </div>
                      <div class="dsr-detail-grid">
                        <div>
                          <div class="dsr-detail-label">科目</div>
                          <div class="dsr-detail-value">{{ side.subject_name || '—' }}</div>
                        </div>
                        <div>
                          <div class="dsr-detail-label">總堂數</div>
                          <div class="dsr-detail-value">{{ side.session_count ?? '—' }}</div>
                        </div>
                        <div>
                          <div class="dsr-detail-label">SC 狀態</div>
                          <div class="dsr-detail-value">{{ side.stop ? '已中止' : '進行中' }}</div>
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

                  <!-- Review info if decided/executed -->
                  <div v-if="g.review" class="dsr-detail-review">
                    <div class="dsr-detail-label">審核資訊</div>
                    <div class="dsr-detail-grid">
                      <div>
                        <div class="dsr-detail-label">審核狀態</div>
                        <div class="dsr-detail-value">
                          <span :class="['dsr-status', `dsr-status-${g.review.status}`]">
                            {{ statusLabel(g.review.status) }}
                          </span>
                        </div>
                      </div>
                      <div v-if="g.review.decided_at">
                        <div class="dsr-detail-label">決策時間</div>
                        <div class="dsr-detail-value">{{ formatDateTime(g.review.decided_at) }}</div>
                      </div>
                      <div v-if="g.review.executed_at">
                        <div class="dsr-detail-label">執行時間</div>
                        <div class="dsr-detail-value">{{ formatDateTime(g.review.executed_at) }}</div>
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
          v-for="g in groupsWithLocalDecisions"
          :key="'m-' + g._key"
          class="dsr-mcard"
        >
          <header class="dsr-mcard-head">
            <div class="dsr-mcard-head-left">
              <input
                type="checkbox"
                :checked="isSelected(g)"
                :disabled="g.review && g.review.status === 'executed'"
                @change="toggleSelect(g)"
              />
              <span class="dsr-student-name">{{ g.student_name || `#${g.student_id}` }}</span>
            </div>
            <span :class="['dsr-status', `dsr-status-${reviewStatus(g)}`]">
              {{ statusLabel(reviewStatus(g)) }}
            </span>
          </header>
          <div class="dsr-mcard-meta">
            <div><span class="dsr-mcard-label">日期</span> {{ formatDate(g.session_date) }}</div>
            <div><span class="dsr-mcard-label">時段</span> {{ g.start_time }}</div>
          </div>
          <div class="dsr-mcard-sides">
            <div
              v-for="side in g.sides"
              :key="side.sc_id"
              :class="[
                'dsr-mcard-side',
                {
                  'dsr-mcard-side--keeper': g._localKeeper === side.sc_id,
                  'dsr-mcard-side--cancelled': g._localKeeper && g._localKeeper !== side.sc_id,
                },
              ]"
            >
              <div class="dsr-mcard-side-head">
                <label class="dsr-radio-label" v-if="canEdit(g)">
                  <input
                    type="radio"
                    :name="`m-keeper-${g._key}`"
                    :value="side.sc_id"
                    :checked="g._localKeeper === side.sc_id"
                    @change="setDecision(g, side.sc_id)"
                    class="dsr-radio"
                  />
                  SC #{{ side.sc_id }}
                </label>
                <span v-else>SC #{{ side.sc_id }}</span>
                <span
                  v-if="side.has_live_lr"
                  class="dsr-lr-badge"
                  title="此側掛有評量記錄"
                >📝</span>
              </div>
              <div class="dsr-mcard-side-body">
                <span>{{ side.subject_name || '—' }}</span>
                <span>· {{ side.session_count ?? '—' }} 堂</span>
                <span v-if="side.stop" class="dsr-stop-badge">已中止</span>
              </div>
            </div>
          </div>
          <div class="dsr-mcard-ops">
            <button class="ghost xs" type="button" @click="toggleExpand(g)">
              {{ expandedKey === g._key ? '收合' : '展開' }}
            </button>
            <button
              v-if="canSaveSingle(g)"
              class="primary xs"
              type="button"
              :disabled="saving"
              @click="saveSingle(g)"
            >
              {{ saving ? '…' : '儲存決策' }}
            </button>
          </div>
          <!-- Mobile expanded detail -->
          <div v-if="expandedKey === g._key" class="dsr-mcard-detail">
            <div
              v-for="side in g.sides"
              :key="'md-' + side.sc_id"
              class="dsr-mcard-detail-section"
            >
              <div class="dsr-detail-section-title">
                SC #{{ side.sc_id }}
                <span v-if="g._localKeeper === side.sc_id" class="dsr-detail-keeper-tag">保留</span>
                <span v-else-if="g._localKeeper" class="dsr-detail-cancel-tag">取消</span>
              </div>
              <div class="dsr-mcard-detail-grid">
                <div><span class="dsr-mcard-label">科目</span> {{ side.subject_name || '—' }}</div>
                <div><span class="dsr-mcard-label">總堂數</span> {{ side.session_count ?? '—' }}</div>
                <div><span class="dsr-mcard-label">SC 狀態</span> {{ side.stop ? '已中止' : '進行中' }}</div>
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

    <!-- BatchActionBar -->
    <div v-if="groupsWithLocalDecisions.length > 0" class="dsr-batch-bar">
      <div class="dsr-batch-left">
        <label class="dsr-batch-check">
          <input
            type="checkbox"
            :checked="allSelected"
            :indeterminate="someSelected && !allSelected"
            @change="toggleSelectAll"
          />
          全選
        </label>
        <span v-if="selectedKeys.size > 0" class="dsr-batch-count">
          已選取 {{ selectedKeys.size }} 組
        </span>
      </div>
      <div class="dsr-batch-actions">
        <button
          class="primary"
          type="button"
          :disabled="saving || unsavedDecisions.length === 0"
          @click="saveBatch"
        >
          {{ saving ? '儲存中…' : `儲存決策（${unsavedDecisions.length}）` }}
        </button>
        <button
          v-if="isSuperAdmin"
          class="dsr-btn-execute"
          type="button"
          :disabled="executing"
          @click="openExecuteModal"
        >
          {{ executing ? '執行中…' : '執行修復' }}
        </button>
      </div>
    </div>

    <!-- Save error -->
    <div v-if="saveError" class="dsr-error" role="alert">{{ saveError }}</div>

    <!-- ExecutionLogModal -->
    <div v-if="showExecuteModal" class="modal-backdrop" @click.self="showExecuteModal = false">
      <div class="modal dsr-exec-modal">
        <div class="modal__header">
          <h2 class="modal__title">確認執行修復</h2>
          <button class="modal__close" @click="showExecuteModal = false">✕</button>
        </div>
        <div class="modal__body">
          <p>執行後，系統將根據審核決策取消重複的課程堂次。此操作不可復原。</p>
          <div v-if="executeError" class="dsr-error" role="alert">{{ executeError }}</div>
          <div v-if="executeResult" class="dsr-exec-result">
            <div class="dsr-exec-result-title">執行結果</div>
            <div class="dsr-exec-result-row">
              <span>已執行</span>
              <strong>{{ executeResult.executed ?? 0 }}</strong>
            </div>
            <div class="dsr-exec-result-row">
              <span>已略過</span>
              <strong>{{ executeResult.skipped ?? 0 }}</strong>
            </div>
            <div v-if="executeResult.details && executeResult.details.length" class="dsr-exec-details">
              <div
                v-for="d in executeResult.details"
                :key="d.review_id"
                class="dsr-exec-detail-item"
              >
                <span>Review #{{ d.review_id }}</span>
                <span :class="d.result === 'executed' ? 'dsr-exec-ok' : 'dsr-exec-skip'">
                  {{ d.result === 'executed' ? '✓ 已執行' : '⊘ 已略過' }}
                </span>
                <span v-if="d.cancelled_session_ids" class="dsr-exec-sessions">
                  取消堂次：{{ d.cancelled_session_ids.join(', ') }}
                </span>
              </div>
            </div>
          </div>
        </div>
        <div class="modal__footer">
          <button class="btn-ghost" @click="showExecuteModal = false" :disabled="executing">關閉</button>
          <button
            class="dsr-btn-execute"
            :disabled="executing || !!executeResult"
            @click="doExecute"
          >
            {{ executing ? '執行中…' : '確認執行' }}
          </button>
        </div>
      </div>
    </div>

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
import { ref, computed, watch, onMounted, onBeforeUnmount, reactive } from 'vue';
import { branches } from '../lib/useBranches';
import { useDuplicateReview, groupKey } from '../composables/useDuplicateReview';
import { STATUS_LABELS, SESSION_STATUS_LABELS } from '../lib/duplicateReviewApi';

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

const {
  groups,
  total,
  loading,
  error,
  activeTab,
  decisions,
  saving,
  saveError,
  executing,
  executeResult,
  executeError,
  selectedKeys,
  pendingCount,
  decidedCount,
  executedCount,
  groupsWithLocalDecisions,
  unsavedDecisions,
  load,
  setDecision,
  save,
  execute,
  toggleSelectAll,
  toggleSelect,
  isSelected,
} = useDuplicateReview();

const expandedKey = ref(null);
const showExecuteModal = ref(false);

const toast = reactive({ visible: false, text: '', tone: 'success' });
let toastTimer = null;

const statusTabs = [
  { value: 'pending', label: '待審核' },
  { value: 'decided', label: '已決策' },
  { value: 'executed', label: '已執行' },
  { value: 'all', label: '全部' },
];

function statusLabel(s) { return STATUS_LABELS[s] || s; }
function sessionStatusLabel(s) { return SESSION_STATUS_LABELS[s] || s; }
function reviewStatus(g) {
  if (g.review) return g.review.status;
  if (decisions[groupKey(g)] !== undefined) return 'pending'; // has local decision only
  return 'pending';
}

function canEdit(g) {
  return !g.review || g.review.status === 'pending';
}

function canSaveSingle(g) {
  if (!canEdit(g)) return false;
  return g._localKeeper != null && g._localKeeper !== (g.review?.keeper_sc_id ?? null);
}

function formatDate(s) {
  if (!s) return '';
  try {
    const d = new Date(s);
    return `${d.getMonth() + 1}/${d.getDate()}`;
  } catch { return s; }
}

function formatDateTime(s) {
  if (!s) return '';
  try {
    const d = new Date(s);
    return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  } catch { return s; }
}

function tabCount(tabValue) {
  if (tabValue === 'pending') return pendingCount.value;
  if (tabValue === 'decided') return decidedCount.value;
  if (tabValue === 'executed') return executedCount.value;
  return total.value;
}

function showToast(text, tone = 'success') {
  if (toastTimer) clearTimeout(toastTimer);
  toast.text = text;
  toast.tone = tone;
  toast.visible = true;
  toastTimer = setTimeout(() => { toast.visible = false; }, 3000);
}

const allSelected = computed(() => {
  const editable = groupsWithLocalDecisions.value.filter((g) => !g.review || g.review.status !== 'executed');
  return editable.length > 0 && selectedKeys.value.size === editable.length;
});
const someSelected = computed(() => selectedKeys.value.size > 0 && !allSelected.value);

function setTab(v) {
  activeTab.value = v;
  expandedKey.value = null;
  saveError.value = '';
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

async function saveSingle(g) {
  saveError.value = '';
  try {
    const result = await save();
    expandedKey.value = null;
    showToast(`已儲存 ${result.saved ?? 1} 筆決策`);
    await doLoad();
  } catch {
    showToast(saveError.value || '儲存失敗', 'error');
  }
}

async function saveBatch() {
  saveError.value = '';
  try {
    const result = await save();
    showToast(`已儲存 ${result.saved ?? unsavedDecisions.value.length} 筆決策`);
    expandedKey.value = null;
    await doLoad();
  } catch {
    showToast(saveError.value || '儲存失敗', 'error');
  }
}

function openExecuteModal() {
  executeError.value = '';
  executeResult.value = null;
  showExecuteModal.value = true;
}

async function doExecute() {
  try {
    const campusId = isSuperAdmin.value
      ? (filterCampusId.value || undefined)
      : (props.branchId || undefined);
    await execute({ campusId });
    showToast('執行完成');
    await doLoad();
  } catch {
    // error is set in executeError
  }
}

function refresh() {
  expandedKey.value = null;
  saveError.value = '';
  doLoad();
}

// Watch branchId changes
watch(() => props.branchId, () => {
  if (!isSuperAdmin.value) doLoad();
});

onMounted(() => {
  doLoad();
});
</script>

<style scoped>
.dsr-page { max-width: 1200px; margin: 0 auto; }

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
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
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
.dsr-tab-badge-pending { background: var(--warning-bg); color: var(--warning); }
.dsr-tab-badge-decided { background: var(--info-wash, #eff6ff); color: var(--ds-info, #1d4ed8); }
.dsr-tab-badge-executed { background: var(--success-bg); color: var(--success); }

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
.dsr-stat-num--decided { color: var(--ds-info, #1d4ed8); }
.dsr-stat-num--pending { color: var(--warning); }
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
  background: var(--success-bg);
}
.dsr-side--cancelled {
  border-color: var(--danger);
  border-style: dashed;
  background: var(--danger-bg);
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
.dsr-stop-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  background: var(--danger-bg);
  color: var(--danger);
}
.dsr-active-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  background: var(--success-bg);
  color: var(--success);
}
.dsr-statuses { display: flex; flex-wrap: wrap; gap: 3px; }
.dsr-session-st {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 600;
}
.dsr-session-st--attended { background: var(--success-bg); color: var(--success); }
.dsr-session-st--completed { background: #dbeafe; color: #1d4ed8; }
.dsr-session-st--late { background: var(--warning-bg); color: var(--warning); }
.dsr-session-st--leave { background: var(--border); color: var(--text-light); }
.dsr-session-st--cancelled { background: var(--danger-bg); color: var(--danger); }
.dsr-session-st--absent { background: var(--danger-bg); color: var(--danger); }
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
.dsr-status-decided { background: var(--warning-bg); color: var(--warning); border-color: #fde68a; }
.dsr-status-executed { background: var(--success-bg); color: var(--success); border-color: #a7f3d0; }

.dsr-row-ops { display: inline-flex; gap: 6px; justify-content: flex-end; }

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
  background: var(--success-bg);
  color: var(--success);
  padding: 1px 8px;
  border-radius: 999px;
}
.dsr-detail-cancel-tag {
  font-size: 11px;
  font-weight: 600;
  background: var(--danger-bg);
  color: var(--danger);
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

.dsr-detail-review {
  border-top: 1px dashed var(--border);
  padding-top: 8px;
}

/* ── BatchActionBar ── */
.dsr-batch-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 10px 16px;
  flex-wrap: wrap;
}
.dsr-batch-left { display: flex; align-items: center; gap: 12px; }
.dsr-batch-check {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text);
  cursor: pointer;
}
.dsr-batch-check input { accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer; }
.dsr-batch-count { font-size: 13px; color: var(--text-light); }
.dsr-batch-actions { display: flex; gap: 8px; }
.dsr-btn-execute {
  background: var(--danger);
  color: #fff;
  border: none;
  padding: 9px 18px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  font-size: 14px;
  white-space: nowrap;
}
.dsr-btn-execute:hover:not(:disabled) { background: #be123c; }
.dsr-btn-execute:disabled { opacity: 0.55; cursor: default; }

/* ── Error ── */
.dsr-error {
  background: var(--danger-bg);
  color: var(--danger);
  border: 1px solid #fecdd3;
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 13px;
  margin-top: 8px;
}

/* ── Execution Modal ── */
.modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.45);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
  padding: 16px;
}
.modal {
  background: var(--card-bg);
  border-radius: 16px;
  width: 100%;
  max-width: 560px;
  max-height: 85vh;
  overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0,0,0,.18);
}
.modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px 0;
}
.modal__title { font-size: 1.1rem; font-weight: 700; margin: 0; }
.modal__close {
  background: none; border: none; font-size: 18px;
  cursor: pointer; color: var(--text-light); line-height: 1;
}
.modal__body { padding: 16px 24px; font-size: 14px; color: var(--text); }
.modal__footer {
  display: flex; gap: 10px; justify-content: flex-end;
  padding: 16px 24px;
  border-top: 1px solid var(--border);
}
.btn-ghost {
  background: transparent;
  border: 1px solid var(--border);
  color: var(--text);
  padding: 8px 16px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 14px;
}
.btn-ghost:hover:not(:disabled) { background: var(--bg); }
.btn-ghost:disabled { opacity: 0.55; cursor: default; }
.primary {
  background: var(--primary);
  color: var(--ds-on-primary);
  border: none;
  padding: 9px 18px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  font-size: 14px;
  white-space: nowrap;
}
.primary:hover:not(:disabled) { background: var(--ds-primary-deep); }
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

.dsr-exec-modal .modal__body { line-height: 1.6; }

.dsr-exec-result {
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px;
  margin-top: 12px;
}
.dsr-exec-result-title { font-weight: 700; font-size: 14px; margin-bottom: 8px; }
.dsr-exec-result-row {
  display: flex;
  justify-content: space-between;
  padding: 4px 0;
  font-size: 13px;
  border-bottom: 1px solid var(--border);
}
.dsr-exec-result-row:last-child { border-bottom: none; }
.dsr-exec-details { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
.dsr-exec-detail-item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  padding: 6px 8px;
  background: var(--card-bg);
  border-radius: 6px;
}
.dsr-exec-ok { color: var(--success); font-weight: 600; }
.dsr-exec-skip { color: var(--text-light); }
.dsr-exec-sessions { color: var(--text-light); font-family: 'SFMono-Regular', ui-monospace, monospace; font-size: 11px; }

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
.dsr-mcard-side--keeper { border-color: var(--success); background: var(--success-bg); }
.dsr-mcard-side--cancelled { border-color: var(--danger); border-style: dashed; background: var(--danger-bg); }
.dsr-mcard-side-head { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; font-weight: 600; }
.dsr-mcard-side-body { display: flex; flex-wrap: wrap; gap: 4px 8px; font-size: 12px; color: var(--text); }
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
}

@media (max-width: 480px) {
  .dsr-toast { top: 12px; right: 12px; left: 12px; }
  .dsr-batch-bar { padding: 8px 12px; gap: 8px; }
  .dsr-batch-actions { width: 100%; }
  .dsr-batch-actions button { flex: 1; }
}
</style>
