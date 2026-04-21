<template>
  <div class="sdp-page">
    <header class="page-header sdp-header">
      <div>
        <h2>課表回報管理</h2>
        <p class="page-desc">老師回報的課表與實際不符情形。處理後系統會留下稽核紀錄。</p>
      </div>
      <div class="sdp-header-btns">
        <button class="primary" type="button" @click="refresh">重新整理</button>
      </div>
    </header>

    <!-- Tabs -->
    <nav class="sdp-tabs" role="tablist" aria-label="回報狀態">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        role="tab"
        :aria-selected="activeTab === tab.value"
        :class="['sdp-tab', { active: activeTab === tab.value }]"
        type="button"
        @click="setTab(tab.value)"
      >
        {{ tab.label }}
        <span v-if="counts[tab.value] > 0" class="sdp-tab-badge" :class="`sdp-tab-badge-${tab.value}`">{{ counts[tab.value] }}</span>
      </button>
    </nav>

    <section class="sdp-list-wrap">
      <div v-if="!hasBranch" class="sdp-state sdp-state-empty sdp-state-no-branch">
        <span class="material-symbols-outlined sdp-empty-icon sdp-empty-icon-info" aria-hidden="true">location_city</span>
        <div class="sdp-empty-title">請先選擇要管理的分校</div>
        <div class="sdp-empty-sub">課表回報屬於各分校的內部資料；請於上方切換分校後再查看。</div>
      </div>
      <div v-else-if="loading" class="sdp-state sdp-state-loading">
        <div class="sdp-spinner" aria-hidden="true"></div>
        <span>載入中…</span>
      </div>
      <div v-else-if="errorMsg" class="sdp-state sdp-state-error">
        <span class="material-symbols-outlined" aria-hidden="true">error</span>
        <span>{{ errorMsg }}</span>
        <button class="ghost xs" type="button" @click="refresh">重試</button>
      </div>
      <div v-else-if="rows.length === 0" class="sdp-state sdp-state-empty">
        <span class="material-symbols-outlined sdp-empty-icon" aria-hidden="true">{{ emptyIcon }}</span>
        <div class="sdp-empty-title">{{ emptyTitle }}</div>
        <div class="sdp-empty-sub">{{ emptySub }}</div>
      </div>

      <!-- Desktop table -->
      <table v-else-if="hasBranch" class="sdp-table sdp-desktop">
        <thead>
          <tr>
            <th style="width:130px">回報時間</th>
            <th style="width:88px">老師</th>
            <th style="width:72px">分校</th>
            <th>堂次 / 出入類型</th>
            <th style="width:110px">狀態</th>
            <th style="width:180px; text-align:right">操作</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in rows" :key="row.id">
            <tr :class="{ 'sdp-row-expanded': expandedId === row.id }">
              <td>
                <div class="sdp-ts">{{ formatDateTime(row.created_at) }}</div>
              </td>
              <td>{{ row.reporter_name || `#${row.reporter_id}` }}</td>
              <td>{{ row.branch_name || `#${row.branch_id}` }}</td>
              <td>
                <div class="sdp-cell-main">
                  <span class="sdp-type-pill">{{ row.discrepancy_type_label }}</span>
                  <span v-if="row.student_name" class="sdp-student">{{ row.student_name }}</span>
                </div>
                <div class="sdp-cell-sub">
                  <span v-if="row.session_date">{{ row.session_date }}</span>
                  <template v-if="row.time_range">
                    <span class="sdp-time-original" :class="{ 'sdp-time-has-correction': row.corrected_time_range }">
                      · {{ row.time_range }}
                    </span>
                    <template v-if="row.corrected_time_range">
                      <span class="sdp-time-arrow material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                      <span class="sdp-time-corrected" :title="'老師建議修正為 ' + row.corrected_time_range">{{ row.corrected_time_range }}</span>
                    </template>
                  </template>
                  <span v-if="row.subject">· {{ row.subject }}</span>
                </div>
              </td>
              <td>
                <span class="sdp-status" :class="`sdp-status-${row.status}`">{{ statusLabel(row.status) }}</span>
              </td>
              <td style="text-align:right">
                <div class="sdp-row-ops">
                  <button class="ghost xs" type="button" @click="toggleExpand(row)">
                    {{ expandedId === row.id ? '收合' : '展開' }}
                  </button>
                  <button
                    v-if="row.status === 'pending'"
                    class="primary xs"
                    type="button"
                    :disabled="busyId === row.id"
                    @click="acknowledge(row)"
                  >
                    {{ busyId === row.id && busyAction === 'ack' ? '處理中…' : '標記已確認' }}
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="expandedId === row.id" class="sdp-detail-row">
              <td colspan="6">
                <div class="sdp-detail">
                  <div class="sdp-detail-grid">
                    <div>
                      <div class="sdp-detail-label">備註</div>
                      <div class="sdp-detail-value">{{ row.notes || '（無）' }}</div>
                    </div>
                    <div>
                      <div class="sdp-detail-label">堂次 ID</div>
                      <div class="sdp-detail-value">{{ row.class_session_id ? `#${row.class_session_id}` : '— 無對應堂次 —' }}</div>
                    </div>
                    <div>
                      <div class="sdp-detail-label">確認時間</div>
                      <div class="sdp-detail-value">{{ formatDateTime(row.acknowledged_at) || '—' }}</div>
                    </div>
                    <div>
                      <div class="sdp-detail-label">解決時間</div>
                      <div class="sdp-detail-value">{{ formatDateTime(row.resolved_at) || '—' }}</div>
                    </div>
                  </div>

                  <div v-if="row.corrected_time_range" class="sdp-detail-corrected">
                    <div class="sdp-detail-label">老師建議修正為</div>
                    <div class="sdp-detail-corrected-body">
                      <span class="sdp-detail-time-original">{{ row.time_range || '—' }}</span>
                      <span class="material-symbols-outlined sdp-detail-arrow" aria-hidden="true">arrow_forward</span>
                      <span class="sdp-detail-time-corrected">{{ row.corrected_time_range }}</span>
                      <button
                        type="button"
                        class="sdp-copy-btn"
                        :title="'複製「' + row.corrected_time_range + '」'"
                        @click="copyCorrectedTime(row)"
                      >
                        <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>
                        複製
                      </button>
                    </div>
                  </div>

                  <div v-if="row.status === 'resolved' && row.resolution_note" class="sdp-detail-note">
                    <div class="sdp-detail-label">處理說明</div>
                    <div class="sdp-detail-value sdp-detail-note-text">{{ row.resolution_note }}</div>
                  </div>

                  <div v-if="row.status !== 'resolved' && row.status !== 'withdrawn'" class="sdp-resolve-form">
                    <label class="sdp-detail-label" :for="`sdp-note-${row.id}`">
                      處理說明 <span class="sdp-required" aria-hidden="true">*</span>
                      <span class="sdp-note-hint">（至少 10 字）</span>
                    </label>
                    <textarea
                      :id="`sdp-note-${row.id}`"
                      class="sdp-textarea"
                      rows="3"
                      maxlength="500"
                      v-model="resolutionDrafts[row.id]"
                      placeholder="請簡述系統已補登 / 已修正哪些資料…"
                    ></textarea>
                    <div class="sdp-resolve-actions">
                      <span class="sdp-counter" :class="{ 'sdp-counter-ok': (resolutionDrafts[row.id] || '').trim().length >= 10 }">
                        {{ (resolutionDrafts[row.id] || '').length }} / 500
                      </span>
                      <button
                        class="primary xs"
                        type="button"
                        :disabled="!canResolve(row) || busyId === row.id"
                        @click="resolve(row)"
                      >
                        {{ busyId === row.id && busyAction === 'resolve' ? '送出中…' : '標記已修正' }}
                      </button>
                    </div>
                  </div>

                  <div v-if="resolveError[row.id]" class="sdp-error" role="alert">{{ resolveError[row.id] }}</div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>

      <!-- Mobile card list -->
      <div v-if="hasBranch && rows.length > 0" class="sdp-mobile">
        <article v-for="row in rows" :key="'m-' + row.id" class="sdp-mcard">
          <header class="sdp-mcard-head">
            <span class="sdp-type-pill">{{ row.discrepancy_type_label }}</span>
            <span class="sdp-status" :class="`sdp-status-${row.status}`">{{ statusLabel(row.status) }}</span>
          </header>
          <div class="sdp-mcard-meta">
            <div><span class="sdp-mcard-label">老師</span> {{ row.reporter_name || `#${row.reporter_id}` }}</div>
            <div><span class="sdp-mcard-label">分校</span> {{ row.branch_name || `#${row.branch_id}` }}</div>
            <div v-if="row.student_name"><span class="sdp-mcard-label">學生</span> {{ row.student_name }}</div>
            <div v-if="row.session_date || row.time_range">
              <span class="sdp-mcard-label">時段</span>
              <span>{{ row.session_date || '' }}</span>
              <template v-if="row.time_range">
                <span class="sdp-time-original" :class="{ 'sdp-time-has-correction': row.corrected_time_range }">
                  {{ ' ' + row.time_range }}
                </span>
                <template v-if="row.corrected_time_range">
                  <span class="sdp-time-arrow material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                  <span class="sdp-time-corrected">{{ row.corrected_time_range }}</span>
                </template>
              </template>
            </div>
            <div v-if="row.notes"><span class="sdp-mcard-label">備註</span> {{ row.notes }}</div>
            <div v-if="row.resolution_note"><span class="sdp-mcard-label">處理說明</span> {{ row.resolution_note }}</div>
          </div>
          <div class="sdp-mcard-ops">
            <button v-if="row.status === 'pending'" class="primary xs" type="button" :disabled="busyId === row.id" @click="acknowledge(row)">
              {{ busyId === row.id && busyAction === 'ack' ? '…' : '已確認' }}
            </button>
            <button class="ghost xs" type="button" @click="toggleExpand(row)">
              {{ expandedId === row.id ? '收合' : '處理' }}
            </button>
          </div>
          <div v-if="expandedId === row.id && row.status !== 'resolved' && row.status !== 'withdrawn'" class="sdp-resolve-form">
            <label class="sdp-detail-label">處理說明 <span class="sdp-required">*</span></label>
            <textarea class="sdp-textarea" rows="3" maxlength="500" v-model="resolutionDrafts[row.id]" placeholder="至少 10 字"></textarea>
            <div class="sdp-resolve-actions">
              <span class="sdp-counter" :class="{ 'sdp-counter-ok': (resolutionDrafts[row.id] || '').trim().length >= 10 }">
                {{ (resolutionDrafts[row.id] || '').length }} / 500
              </span>
              <button class="primary xs" type="button" :disabled="!canResolve(row) || busyId === row.id" @click="resolve(row)">
                {{ busyId === row.id && busyAction === 'resolve' ? '…' : '標記已修正' }}
              </button>
            </div>
            <div v-if="resolveError[row.id]" class="sdp-error">{{ resolveError[row.id] }}</div>
          </div>
        </article>
      </div>
    </section>

    <!-- Toast -->
    <Teleport to="body">
      <Transition name="sdp-toast">
        <div v-if="toast.visible" class="sdp-toast" :class="`sdp-toast-${toast.tone}`" role="status">
          <span class="material-symbols-outlined" aria-hidden="true">
            {{ toast.tone === 'success' ? 'check_circle' : (toast.tone === 'error' ? 'error' : 'info') }}
          </span>
          <span>{{ toast.text }}</span>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { fetchDiscrepancies, fetchDiscrepancySummary, updateDiscrepancyStatus, STATUS_LABELS } from '../lib/scheduleDiscrepanciesApi';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
});

const tabs = [
  { value: 'pending', label: '待處理' },
  { value: 'acknowledged', label: '處理中' },
  { value: 'resolved', label: '已解決' },
];

const activeTab = ref('pending');
const rows = ref([]);
const counts = ref({ pending: 0, acknowledged: 0, resolved: 0, withdrawn: 0 });
const loading = ref(false);
const errorMsg = ref('');

const expandedId = ref(null);
const resolutionDrafts = reactive({});
const resolveError = reactive({});
const busyId = ref(null);
const busyAction = ref('');

const toast = reactive({ visible: false, text: '', tone: 'success' });
let toastTimer = null;

const emptyIcon = computed(() => {
  if (activeTab.value === 'resolved') return 'flag_circle';
  if (activeTab.value === 'acknowledged') return 'hourglass_empty';
  return 'task_alt';
});
const emptyTitle = computed(() => {
  if (activeTab.value === 'resolved') return '尚無已解決的回報';
  if (activeTab.value === 'acknowledged') return '目前沒有處理中的回報';
  return '目前沒有待處理的回報';
});
const emptySub = computed(() => {
  if (activeTab.value === 'resolved') return '處理完畢的回報會集中顯示在這裡，便於日後追溯。';
  if (activeTab.value === 'acknowledged') return '老師送出新回報並由主任點擊「已確認」後，會進入此列表。';
  return '太棒了，老師沒有回報任何課表出入情形。';
});

// FR-008: never hit the API when no branch is selected; show an explicit empty-state instead.
const hasBranch = computed(() => {
  const v = props.branchId;
  return v !== null && v !== undefined && v !== '' && Number(v) > 0;
});

function statusLabel(s) { return STATUS_LABELS[s] || s; }

function formatDateTime(s) {
  if (!s) return '';
  try {
    const d = new Date(s);
    return `${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  } catch { return ''; }
}

function showToast(text, tone = 'success') {
  if (toastTimer) clearTimeout(toastTimer);
  toast.text = text;
  toast.tone = tone;
  toast.visible = true;
  toastTimer = setTimeout(() => { toast.visible = false; }, 3000);
}

// ── Page-level polling (FR-001 ~ FR-004, FR-006) ─────────────────────────────
// pending tab → 30s; other tabs → 60s. Pauses when page is hidden.
let _pagePollingTimer = null;

const POLL_INTERVAL_PENDING = 30_000;
const POLL_INTERVAL_OTHER   = 60_000;

function _pollInterval() {
  return activeTab.value === 'pending' ? POLL_INTERVAL_PENDING : POLL_INTERVAL_OTHER;
}

function startPagePolling() {
  stopPagePolling();
  _pagePollingTimer = setInterval(() => {
    if (!hasBranch.value || document.visibilityState !== 'visible') return;
    load();
  }, _pollInterval());
}

function stopPagePolling() {
  if (_pagePollingTimer) { clearInterval(_pagePollingTimer); _pagePollingTimer = null; }
}

function _onVisibilityChange() {
  if (document.visibilityState === 'visible' && hasBranch.value) load();
}

function setTab(v) {
  activeTab.value = v;
  expandedId.value = null;
  stopPagePolling();
  load();
  startPagePolling();
}

function toggleExpand(row) {
  if (expandedId.value === row.id) {
    expandedId.value = null;
  } else {
    expandedId.value = row.id;
    if (resolutionDrafts[row.id] === undefined) {
      resolutionDrafts[row.id] = '';
    }
  }
}

function canResolve(row) {
  const note = (resolutionDrafts[row.id] || '').trim();
  return note.length >= 10 && row.status !== 'resolved' && row.status !== 'withdrawn';
}

async function load() {
  // FR-008: guard — never call the API without a branch; surfaces as empty-state instead.
  if (!hasBranch.value) {
    rows.value = [];
    counts.value = { pending: 0, acknowledged: 0, resolved: 0, withdrawn: 0 };
    loading.value = false;
    errorMsg.value = '';
    return;
  }
  loading.value = true;
  errorMsg.value = '';
  try {
    const [list, summary] = await Promise.all([
      fetchDiscrepancies({
        branchId: props.branchId,
        status: activeTab.value,
        perPage: 50,
      }),
      fetchDiscrepancySummary(props.branchId),
    ]);
    rows.value = Array.isArray(list?.data) ? list.data : [];
    counts.value = summary || counts.value;
  } catch (e) {
    errorMsg.value = e?.message || '載入失敗，請重試';
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

async function copyCorrectedTime(row) {
  const v = row?.corrected_time_range;
  if (!v) return;
  try {
    if (navigator?.clipboard?.writeText) {
      await navigator.clipboard.writeText(v);
    } else {
      const ta = document.createElement('textarea');
      ta.value = v;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    }
    showToast(`已複製「${v}」`, 'info');
  } catch {
    showToast('複製失敗，請手動選取', 'error');
  }
}

function refresh() { load(); }

async function acknowledge(row) {
  busyId.value = row.id;
  busyAction.value = 'ack';
  try {
    await updateDiscrepancyStatus(row.id, 'acknowledged');
    showToast('已標記為處理中');
    // Optimistically remove from pending list; refresh counts.
    if (activeTab.value === 'pending') {
      rows.value = rows.value.filter((r) => r.id !== row.id);
    }
    await refreshSummary();
  } catch (e) {
    showToast(e?.payload?.message || e?.message || '操作失敗', 'error');
  } finally {
    busyId.value = null;
    busyAction.value = '';
  }
}

async function resolve(row) {
  const note = (resolutionDrafts[row.id] || '').trim();
  if (note.length < 10) return;
  busyId.value = row.id;
  busyAction.value = 'resolve';
  resolveError[row.id] = '';
  try {
    await updateDiscrepancyStatus(row.id, 'resolved', note);
    showToast('已標記為已修正');
    resolutionDrafts[row.id] = '';
    expandedId.value = null;
    if (activeTab.value !== 'resolved') {
      rows.value = rows.value.filter((r) => r.id !== row.id);
    } else {
      await load();
    }
    await refreshSummary();
  } catch (e) {
    resolveError[row.id] = e?.payload?.message || e?.message || '送出失敗，請稍後再試';
  } finally {
    busyId.value = null;
    busyAction.value = '';
  }
}

async function refreshSummary() {
  try {
    counts.value = await fetchDiscrepancySummary(props.branchId);
  } catch { /* non-fatal */ }
}

watch(() => props.branchId, () => {
  stopPagePolling();
  load();
  startPagePolling();
});
onMounted(() => {
  load();
  startPagePolling();
  document.addEventListener('visibilitychange', _onVisibilityChange);
});
onBeforeUnmount(() => {
  stopPagePolling();
  document.removeEventListener('visibilitychange', _onVisibilityChange);
});
</script>

<style scoped>
.sdp-page { max-width: 1200px; margin: 0 auto; }

.sdp-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}
.sdp-header-btns { display: flex; gap: 8px; }

.sdp-tabs {
  display: flex;
  gap: 4px;
  border-bottom: 1px solid var(--border-soft, #e5e7eb);
  margin-bottom: 12px;
  overflow-x: auto;
}
.sdp-tab {
  background: transparent;
  border: 0;
  padding: 10px 16px;
  min-height: 44px;
  font-size: 14px;
  font-weight: 600;
  color: var(--text-light, #64748b);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}
.sdp-tab.active {
  color: var(--primary, #2563eb);
  border-bottom-color: var(--primary, #2563eb);
}
.sdp-tab-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  background: var(--border-soft, #e5e7eb);
  color: var(--text, #0f172a);
}
.sdp-tab-badge-pending { background: var(--warning-soft, #fffbeb); color: var(--warning-strong, #b45309); }
.sdp-tab-badge-acknowledged { background: var(--info-soft, #eff6ff); color: var(--info-strong, #1d4ed8); }
.sdp-tab-badge-resolved { background: var(--success-soft, #ecfdf5); color: var(--success-strong, #047857); }

.sdp-list-wrap { background: var(--card-bg, #fff); border: 1px solid var(--border-soft, #e5e7eb); border-radius: 12px; padding: 12px; }

.sdp-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 48px 16px;
  color: var(--text-light, #64748b);
  text-align: center;
}
.sdp-state-error { color: var(--danger, #ef4444); }
.sdp-empty-icon {
  font-size: 48px;
  color: var(--success-strong, #047857);
}
.sdp-state-empty.sdp-state:has(.sdp-empty-icon) { /* no-op helper */ }
.sdp-empty-title { font-size: 16px; font-weight: 700; color: var(--text, #0f172a); }
.sdp-empty-sub { font-size: 13px; color: var(--text-light, #64748b); }
.sdp-spinner {
  width: 24px;
  height: 24px;
  border: 3px solid rgba(0,0,0,0.08);
  border-top-color: var(--primary, #2563eb);
  border-radius: 50%;
  animation: sdp-spin 0.9s linear infinite;
}
@keyframes sdp-spin { to { transform: rotate(360deg); } }

.sdp-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.sdp-table th, .sdp-table td {
  padding: 10px 8px;
  border-bottom: 1px solid var(--border-soft, #e5e7eb);
  text-align: left;
  vertical-align: top;
}
.sdp-table th { background: var(--surface-muted, #f8fafc); font-weight: 600; color: var(--text-light, #64748b); font-size: 12px; }
.sdp-row-expanded { background: var(--surface-muted, #f8fafc); }
.sdp-detail-row td { background: var(--surface-muted, #f8fafc); }

.sdp-ts { font-family: 'SFMono-Regular', ui-monospace, monospace; font-size: 12px; }
.sdp-cell-main { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.sdp-cell-sub { font-size: 12px; color: var(--text-light, #64748b); margin-top: 2px; display: inline-flex; flex-wrap: wrap; align-items: center; gap: 4px; row-gap: 2px; }
.sdp-student { font-weight: 600; }

/* PRD 2026-04-18 FR-007: corrected-time-range inline comparison */
.sdp-time-original { color: var(--text-light, #64748b); }
.sdp-time-has-correction { text-decoration: line-through; text-decoration-color: #cbd5e1; }
.sdp-time-arrow { font-size: 14px; color: var(--text-light, #9ca3af); margin: 0 2px; line-height: 1; }
.sdp-time-corrected {
  color: var(--primary, #2563eb);
  font-weight: 600;
  background: rgba(37, 99, 235, 0.08);
  padding: 1px 6px;
  border-radius: 4px;
}

.sdp-detail-corrected {
  background: rgba(37, 99, 235, 0.06);
  border: 1px solid rgba(37, 99, 235, 0.2);
  border-radius: 8px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.sdp-detail-corrected-body {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 14px;
}
.sdp-detail-time-original { color: var(--text-light, #64748b); text-decoration: line-through; text-decoration-color: #cbd5e1; }
.sdp-detail-arrow { color: var(--primary, #2563eb); font-size: 18px; }
.sdp-detail-time-corrected { color: var(--primary, #2563eb); font-weight: 600; font-size: 15px; }
.sdp-copy-btn {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid var(--primary, #2563eb);
  background: transparent;
  color: var(--primary, #2563eb);
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  min-height: 32px;
}
.sdp-copy-btn:hover { background: rgba(37, 99, 235, 0.08); }
.sdp-copy-btn .material-symbols-outlined { font-size: 14px; }

.sdp-empty-icon-info { color: var(--info-strong, #1d4ed8) !important; }
.sdp-state-no-branch { padding: 56px 16px; }

.sdp-type-pill {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  background: var(--warning-soft, #fffbeb);
  color: var(--warning-strong, #b45309);
  border: 1px solid var(--warning-border, #fde68a);
}

.sdp-status {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  border: 1px solid transparent;
}
.sdp-status-pending { background: var(--warning-soft, #fffbeb); color: var(--warning-strong, #b45309); border-color: var(--warning-border, #fde68a); }
.sdp-status-acknowledged { background: var(--info-soft, #eff6ff); color: var(--info-strong, #1d4ed8); border-color: var(--info-border, #bfdbfe); }
.sdp-status-resolved { background: var(--success-soft, #ecfdf5); color: var(--success-strong, #047857); border-color: var(--success-border, #a7f3d0); }
.sdp-status-withdrawn { background: var(--border-soft, #f1f5f9); color: var(--text-light, #64748b); }

.sdp-row-ops { display: inline-flex; gap: 6px; justify-content: flex-end; }

.sdp-detail { display: flex; flex-direction: column; gap: 12px; padding: 8px 4px; }
.sdp-detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px 16px; }
.sdp-detail-label { font-size: 12px; color: var(--text-light, #64748b); font-weight: 600; margin-bottom: 2px; }
.sdp-detail-value { font-size: 13px; color: var(--text, #0f172a); word-break: break-word; }
.sdp-detail-note { background: var(--success-soft, #ecfdf5); border: 1px solid var(--success-border, #a7f3d0); border-radius: 8px; padding: 10px 12px; }
.sdp-detail-note-text { white-space: pre-wrap; }

.sdp-resolve-form { display: flex; flex-direction: column; gap: 8px; padding-top: 6px; border-top: 1px dashed var(--border-soft, #e5e7eb); }
.sdp-note-hint { font-weight: 400; color: var(--text-light, #64748b); }
.sdp-required { color: var(--danger, #ef4444); }
.sdp-textarea {
  width: 100%;
  border: 1px solid var(--border, #cbd5e1);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 13px;
  min-height: 80px;
  resize: vertical;
  box-sizing: border-box;
  background: var(--input-bg, #fff);
  color: inherit;
}
.sdp-textarea:focus { outline: none; border-color: var(--primary, #2563eb); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
.sdp-resolve-actions { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.sdp-counter { font-size: 12px; color: var(--text-light, #64748b); }
.sdp-counter-ok { color: var(--success-strong, #047857); font-weight: 600; }

.sdp-error {
  color: var(--danger, #ef4444);
  background: rgba(239, 68, 68, 0.08);
  border: 1px solid rgba(239, 68, 68, 0.2);
  padding: 6px 10px;
  border-radius: 6px;
  font-size: 12px;
}

.sdp-mobile { display: none; }
.sdp-mcard {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border-soft, #e5e7eb);
  border-radius: 10px;
  padding: 12px;
  margin-bottom: 10px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sdp-mcard-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.sdp-mcard-meta { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
.sdp-mcard-label { color: var(--text-light, #64748b); font-weight: 600; margin-right: 6px; font-size: 12px; }
.sdp-mcard-ops { display: flex; gap: 6px; justify-content: flex-end; }

@media (max-width: 768px) {
  .sdp-desktop { display: none; }
  .sdp-mobile { display: block; }
}

.sdp-toast {
  position: fixed;
  top: 24px;
  right: 24px;
  z-index: 10060;
  background: var(--success-strong, #047857);
  color: #fff;
  padding: 12px 18px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.2);
}
.sdp-toast-error { background: var(--danger, #ef4444); }
.sdp-toast-info { background: var(--info-strong, #1d4ed8); }
.sdp-toast-enter-active, .sdp-toast-leave-active { transition: all 200ms ease; }
.sdp-toast-enter-from, .sdp-toast-leave-to { opacity: 0; transform: translateY(-8px); }
@media (max-width: 480px) {
  .sdp-toast { top: 12px; right: 12px; left: 12px; }
}
</style>
