<template>
  <div class="bm-page">
    <!-- Header -->
    <header class="page-header bm-header">
      <div>
        <h2>LINE 綁定管理</h2>
        <p class="page-desc">查看與管理家長 LINE 綁定紀錄。解除綁定後，該 LINE 帳號將無法查看對應學生的資料。</p>
      </div>
      <div class="bm-header-btns">
        <button class="primary" type="button" @click="refresh" :disabled="loading">↻ 重新整理</button>
      </div>
    </header>

    <!-- Stats Cards -->
    <section class="bm-stats" aria-label="綁定統計">
      <div class="bm-stat-card bm-stat-card--total">
        <span class="bm-stat-label">總學生數</span>
        <span class="bm-stat-value">{{ stats.total_students ?? '—' }}</span>
      </div>
      <div class="bm-stat-card bm-stat-card--bound">
        <span class="bm-stat-label">已綁定學生</span>
        <span class="bm-stat-value">{{ stats.bound_students ?? '—' }}</span>
      </div>
      <div class="bm-stat-card bm-stat-card--rate">
        <span class="bm-stat-label">綁定率</span>
        <span class="bm-stat-value" :class="{ 'bm-stat-value--low': bindingRateNum < 60 }">
          {{ bindingRateDisplay }}
        </span>
      </div>
      <div class="bm-stat-card bm-stat-card--total-binds">
        <span class="bm-stat-label">LINE 綁定總數</span>
        <span class="bm-stat-value">{{ stats.total_bindings ?? '—' }}</span>
      </div>
    </section>

    <!-- Filter Bar -->
    <div class="bm-filter-bar">
      <div class="bm-filter-row">
        <!-- Campus selector -->
        <div class="bm-filter-group">
          <label class="bm-filter-label" for="bm-campus">分校</label>
          <select
            id="bm-campus"
            class="bm-select"
            :value="filterCampusId"
            @change="onCampusChange(($event.target).value)"
          >
            <option value="">全部分校</option>
            <option v-for="b in campusList" :key="b.id" :value="b.id">
              {{ b.name }}
            </option>
          </select>
        </div>
        <!-- Search by student name -->
        <div class="bm-filter-group bm-search-group">
          <label class="bm-filter-label" for="bm-search">搜尋學生</label>
          <input
            id="bm-search"
            v-model="searchQuery"
            type="text"
            class="bm-input"
            placeholder="輸入學生姓名…"
            @keyup.enter="doSearch"
          />
          <button class="ghost xs" type="button" @click="doSearch" :disabled="loading">搜尋</button>
          <button v-if="searchQuery || filterCampusId" class="ghost xs" type="button" @click="clearFilters">清除篩選</button>
        </div>
      </div>
    </div>

    <!-- Loading / Error / Empty states -->
    <div v-if="loading" class="bm-state bm-state-loading">
      <div class="bm-spinner" aria-hidden="true"></div>
      <span>載入中…</span>
    </div>
    <div v-else-if="errorMsg" class="bm-state bm-state-error">
      <span class="material-symbols-outlined" aria-hidden="true">error</span>
      <span>{{ errorMsg }}</span>
      <button class="ghost xs" type="button" @click="refresh">重試</button>
    </div>

    <template v-else>
      <!-- Desktop Table -->
      <div v-if="rows.length === 0" class="bm-state bm-state-empty">
        <span class="material-symbols-outlined bm-empty-icon" aria-hidden="true">link_off</span>
        <div class="bm-empty-title">暫無 LINE 綁定紀錄</div>
        <div class="bm-empty-sub">{{ filterActive ? '目前篩選條件下沒有綁定紀錄，請調整篩選條件。' : '尚無家長完成 LINE 綁定。請提醒家長加入 LINE 官方帳號完成綁定。' }}</div>
      </div>

      <div v-else class="bm-table-wrap">
        <table class="bm-table">
          <thead>
            <tr>
              <th>學生姓名</th>
              <th>LINE 名稱</th>
              <th>綁定時間</th>
              <th>分校</th>
              <th>狀態</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td>
                <span class="bm-student-name">{{ row.student_name }}</span>
              </td>
              <td>
                <span class="bm-line-name">{{ row.line_display_name || '—' }}</span>
              </td>
              <td>
                <span class="bm-date">{{ formatDate(row.bound_at) }}</span>
              </td>
              <td>
                <span class="bm-campus">{{ row.campus_name || `#${row.campus_id}` }}</span>
              </td>
              <td>
                <span :class="['bm-badge', row.bound_count > 0 ? 'bm-badge--bound' : 'bm-badge--unbound']">
                  <span v-if="row.bound_count > 0" class="material-symbols-outlined bm-badge-icon" aria-hidden="true">check_circle</span>
                  <span v-else class="material-symbols-outlined bm-badge-icon" aria-hidden="true">radio_button_unchecked</span>
                  {{ row.bound_count > 0 ? `已綁定 ${row.bound_count} 個 LINE` : '未綁定' }}
                </span>
              </td>
              <td style="text-align:right">
                <button
                  class="danger xs"
                  type="button"
                  :disabled="unbindingId === row.id"
                  @click="confirmUnbind(row)"
                >
                  {{ unbindingId === row.id ? '解除中…' : '解除綁定' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.last_page > 1" class="bm-pagination">
        <button
          class="ghost xs"
          type="button"
          :disabled="pagination.current_page <= 1 || loading"
          @click="goToPage(pagination.current_page - 1)"
        >
          ‹ 上一頁
        </button>
        <span class="bm-page-info">
          第 {{ pagination.current_page }} / {{ pagination.last_page }} 頁
          <span class="bm-page-total">（共 {{ pagination.total }} 筆）</span>
        </span>
        <button
          class="ghost xs"
          type="button"
          :disabled="pagination.current_page >= pagination.last_page || loading"
          @click="goToPage(pagination.current_page + 1)"
        >
          下一頁 ›
        </button>
      </div>
    </template>

    <!-- Unbind Confirmation Dialog -->
    <div v-if="showUnbindDialog" class="bm-dialog-overlay" @click.self="cancelUnbind">
      <div class="bm-dialog" role="dialog" aria-modal="true" aria-labelledby="unbind-dialog-title">
        <h3 id="unbind-dialog-title" class="bm-dialog-title">確認解除 LINE 綁定</h3>
        <div class="bm-dialog-body">
          <p>確定要解除以下 LINE 綁定嗎？</p>
          <div class="bm-dialog-detail">
            <div class="bm-detail-row">
              <span class="bm-detail-label">學生：</span>
              <span class="bm-detail-value">{{ unbindTarget?.student_name }}</span>
            </div>
            <div class="bm-detail-row">
              <span class="bm-detail-label">LINE：</span>
              <span class="bm-detail-value">{{ unbindTarget?.line_display_name || unbindTarget?.line_user_id || '—' }}</span>
            </div>
            <div class="bm-detail-row">
              <span class="bm-detail-label">綁定時間：</span>
              <span class="bm-detail-value">{{ formatDate(unbindTarget?.bound_at) }}</span>
            </div>
            <div class="bm-detail-row">
              <span class="bm-detail-label">分校：</span>
              <span class="bm-detail-value">{{ unbindTarget?.campus_name || `#${unbindTarget?.campus_id}` }}</span>
            </div>
          </div>
          <div v-if="unbindError" class="bm-dialog-error">{{ unbindError }}</div>
        </div>
        <div class="bm-dialog-actions">
          <button class="ghost" type="button" :disabled="unbindingId !== null" @click="cancelUnbind">取消</button>
          <button class="danger" type="button" :disabled="unbindingId !== null" @click="executeUnbind">
            {{ unbindingId !== null ? '解除中…' : '確認解除' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { fetchBindings, fetchBindingStats, unbindBinding } from '../lib/bindingApi.js';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  branches: { type: Array, default: () => [] },
});

// ── State ──
const loading = ref(false);
const errorMsg = ref('');
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 });
const stats = ref({});
const searchQuery = ref('');
const filterCampusId = ref('');
const currentPage = ref(1);

// Unbind dialog
const showUnbindDialog = ref(false);
const unbindTarget = ref(null);
const unbindingId = ref(null);
const unbindError = ref('');

// ── Computed ──
const campusList = computed(() => props.branches || []);

const bindingRateNum = computed(() => {
  const v = stats.value.binding_rate;
  if (v == null) return 0;
  return Math.round(Number(v) * 100) / 100;
});

const bindingRateDisplay = computed(() => {
  const v = stats.value.binding_rate;
  if (v == null) return '—';
  return `${(Number(v) * 100).toFixed(1)}%`;
});

const filterActive = computed(() => Boolean(searchQuery.value || filterCampusId.value));

// ── Methods ──
async function loadStats() {
  try {
    stats.value = await fetchBindingStats(filterCampusId.value || undefined);
  } catch {
    // Stats are non-critical; keep previous or empty
    stats.value = {};
  }
}

async function loadBindings() {
  loading.value = true;
  errorMsg.value = '';
  try {
    const result = await fetchBindings({
      branchId: filterCampusId.value || undefined,
      studentName: searchQuery.value || undefined,
      page: currentPage.value,
    });
    rows.value = result.data || [];
    pagination.value = {
      current_page: result.meta?.current_page || result.current_page || 1,
      last_page: result.meta?.last_page || result.last_page || 1,
      total: result.meta?.total || result.total || 0,
      per_page: result.meta?.per_page || result.per_page || 20,
    };
  } catch (err) {
    if (err.status === 404) {
      // API not yet deployed — handle gracefully
      rows.value = [];
      pagination.value = { current_page: 1, last_page: 1, total: 0, per_page: 20 };
      errorMsg.value = '綁定管理 API 尚未部署。請確認後端已更新至最新版本。';
    } else {
      errorMsg.value = err.message || '載入失敗，請稍後再試。';
    }
  } finally {
    loading.value = false;
  }
}

async function refresh() {
  await Promise.all([loadBindings(), loadStats()]);
}

function onCampusChange(val) {
  filterCampusId.value = val;
  currentPage.value = 1;
  loadBindings();
}

function doSearch() {
  currentPage.value = 1;
  loadBindings();
}

function clearFilters() {
  searchQuery.value = '';
  filterCampusId.value = '';
  currentPage.value = 1;
  loadBindings();
}

function goToPage(page) {
  if (page < 1 || page > pagination.value.last_page) return;
  currentPage.value = page;
  loadBindings();
}

function confirmUnbind(row) {
  unbindTarget.value = row;
  unbindError.value = '';
  showUnbindDialog.value = true;
}

function cancelUnbind() {
  showUnbindDialog.value = false;
  unbindTarget.value = null;
  unbindingId.value = null;
  unbindError.value = '';
}

async function executeUnbind() {
  if (!unbindTarget.value) return;
  const targetId = unbindTarget.value.id;
  unbindingId.value = targetId;
  unbindError.value = '';
  try {
    await unbindBinding(targetId);
    showUnbindDialog.value = false;
    unbindTarget.value = null;
    unbindingId.value = null;
    // Refresh data
    await refresh();
  } catch (err) {
    unbindError.value = err.message || '解除綁定失敗，請稍後再試。';
    unbindingId.value = null;
  }
}

function formatDate(raw) {
  if (!raw) return '—';
  try {
    const d = new Date(raw);
    if (isNaN(d.getTime())) return raw;
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `${y}-${m}-${day} ${hh}:${mm}`;
  } catch {
    return raw;
  }
}

// ── Lifecycle ──
onMounted(() => {
  refresh();
});

// Watch branchId changes from parent
watch(() => props.branchId, () => {
  filterCampusId.value = '';
  currentPage.value = 1;
  refresh();
});
</script>

<style scoped>
.bm-page {
  max-width: 1200px;
}

/* ── Header ── */
.bm-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.bm-header h2 {
  font-size: 22px;
  font-weight: 700;
  color: var(--ds-ink);
  margin-bottom: 4px;
}

.bm-header-btns {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}

/* ── Stats Cards ── */
.bm-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 16px;
}

.bm-stat-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  padding: 16px 18px;
  border-bottom: 3px solid transparent;
}

.bm-stat-card--total {
  border-bottom-color: var(--ds-primary);
}

.bm-stat-card--bound {
  border-bottom-color: var(--ds-success);
}

.bm-stat-card--rate {
  border-bottom-color: var(--ds-info);
}

.bm-stat-card--total-binds {
  border-bottom-color: var(--ds-brand-amber);
}

.bm-stat-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--ds-ink-mute);
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.bm-stat-value {
  font-size: 28px;
  font-weight: 700;
  color: var(--ds-ink);
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.01em;
  line-height: 1.1;
}

.bm-stat-value--low {
  color: var(--ds-danger);
}

/* ── Filter Bar ── */
.bm-filter-bar {
  margin-bottom: 12px;
}

.bm-filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
}

.bm-filter-group {
  display: flex;
  align-items: center;
  gap: 6px;
}

.bm-search-group {
  flex: 1;
  min-width: 240px;
}

.bm-filter-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--ds-ink-secondary);
  white-space: nowrap;
}

.bm-select,
.bm-input {
  font-family: inherit;
  font-size: 13px;
  padding: 6px 10px;
  border: 1px solid var(--ds-hairline-input);
  border-radius: 8px;
  background: var(--ds-canvas);
  color: var(--ds-ink);
  outline: none;
  transition: border-color 0.15s;
}

.bm-select:focus,
.bm-input:focus {
  border-color: var(--ds-primary);
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

.bm-input {
  min-width: 180px;
}

.bm-select {
  min-width: 120px;
}

/* ── States (Loading / Error / Empty) ── */
.bm-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 48px 24px;
  text-align: center;
  color: var(--ds-ink-mute);
}

.bm-state-loading {
  gap: 12px;
}

.bm-spinner {
  width: 28px;
  height: 28px;
  border: 3px solid var(--ds-hairline);
  border-top-color: var(--ds-primary);
  border-radius: 50%;
  animation: bm-spin 0.7s linear infinite;
}

@keyframes bm-spin {
  to { transform: rotate(360deg); }
}

.bm-state-error {
  color: var(--ds-danger);
}

.bm-empty-icon {
  font-size: 48px;
  color: var(--ds-hairline);
  margin-bottom: 4px;
}

.bm-empty-title {
  font-size: 18px;
  font-weight: 700;
  color: var(--ds-ink);
}

.bm-empty-sub {
  font-size: 13px;
  color: var(--ds-ink-mute);
  max-width: 400px;
  line-height: 1.5;
}

/* ── Table ── */
.bm-table-wrap {
  overflow-x: auto;
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  background: var(--ds-canvas);
}

.bm-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.bm-table thead {
  background: var(--ds-canvas-soft);
}

.bm-table th {
  text-align: left;
  padding: 12px 14px;
  font-weight: 600;
  font-size: 11px;
  color: var(--ds-ink-mute);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  border-bottom: 1px solid var(--ds-hairline);
  white-space: nowrap;
}

.bm-table td {
  padding: 12px 14px;
  border-bottom: 1px solid var(--ds-hairline);
  color: var(--ds-ink);
  vertical-align: middle;
}

.bm-table tbody tr:last-child td {
  border-bottom: none;
}

.bm-table tbody tr:hover {
  background: var(--ds-canvas-soft);
}

.bm-student-name {
  font-weight: 600;
}

.bm-line-name {
  color: var(--ds-ink-secondary);
}

.bm-date {
  color: var(--ds-ink-secondary);
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  white-space: nowrap;
}

.bm-campus {
  color: var(--ds-ink-secondary);
  font-size: 12px;
}

/* Badge */
.bm-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}

.bm-badge--bound {
  background: var(--ds-success-wash);
  color: var(--ds-success);
}

.bm-badge--unbound {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
}

.bm-badge-icon {
  font-size: 14px;
  line-height: 1;
}

/* ── Pagination ── */
.bm-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin-top: 12px;
  padding: 8px 0;
}

.bm-page-info {
  font-size: 13px;
  color: var(--ds-ink-secondary);
}

.bm-page-total {
  color: var(--ds-ink-mute);
}

/* ── Dialog ── */
.bm-dialog-overlay {
  position: fixed;
  inset: 0;
  z-index: 10000;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  padding: 16px;
}

.bm-dialog {
  width: min(420px, 100%);
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: 14px;
  box-shadow: 0 22px 48px rgba(15, 23, 42, 0.28);
  overflow: hidden;
}

.bm-dialog-title {
  font-size: 18px;
  font-weight: 700;
  color: var(--ds-ink);
  padding: 20px 20px 0;
  margin: 0;
}

.bm-dialog-body {
  padding: 16px 20px;
}

.bm-dialog-body p {
  margin: 0 0 12px;
  font-size: 14px;
  color: var(--ds-ink-secondary);
  line-height: 1.5;
}

.bm-dialog-detail {
  background: var(--ds-canvas-soft);
  border-radius: 8px;
  padding: 12px;
}

.bm-detail-row {
  display: flex;
  gap: 6px;
  padding: 4px 0;
  font-size: 13px;
}

.bm-detail-label {
  color: var(--ds-ink-mute);
  flex-shrink: 0;
  min-width: 60px;
}

.bm-detail-value {
  color: var(--ds-ink);
  font-weight: 500;
}

.bm-dialog-error {
  margin-top: 12px;
  padding: 8px 12px;
  border-radius: 8px;
  background: var(--ds-danger-wash);
  color: var(--ds-danger);
  font-size: 13px;
}

.bm-dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 0 20px 20px;
}

/* ── Responsive ── */
@media (max-width: 720px) {
  .bm-stats {
    grid-template-columns: repeat(2, 1fr);
  }

  .bm-stat-value {
    font-size: 22px;
  }

  .bm-filter-row {
    flex-direction: column;
    align-items: stretch;
  }

  .bm-search-group {
    min-width: unset;
  }
}

@media (max-width: 480px) {
  .bm-stats {
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }

  .bm-stat-card {
    padding: 12px 14px;
  }
}
</style>
