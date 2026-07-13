<template>
  <div class="nightly-reconcile" role="region" aria-label="夜間對帳異常面板">
    <!-- 頁首 -->
    <div class="nr-header">
      <div>
        <h1 class="nr-title">夜間對帳異常</h1>
        <p class="nr-subtitle">監控每日自動對帳結果，檢視異常明細並一鍵重新計算</p>
      </div>
      <button
        class="nr-refresh-btn"
        :disabled="loading || recomputing"
        @click="loadReport"
        :aria-label="'重新載入對帳報告'"
      >
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:18px">refresh</span>
        重新載入
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="nr-loading" role="status" aria-live="polite">
      <div class="nr-spinner" aria-hidden="true"></div>
      <span>載入對帳報告中…</span>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="nr-error" role="alert">
      <span class="material-symbols-outlined" aria-hidden="true">error</span>
      <span>{{ error }}</span>
      <button class="nr-retry-btn" @click="loadReport">重試</button>
    </div>

    <!-- 無報告（404） -->
    <div v-else-if="!report" class="nr-empty" role="status">
      <span class="material-symbols-outlined nr-empty-icon" aria-hidden="true">receipt_long</span>
      <h2 class="nr-empty-title">尚無對帳報告</h2>
      <p class="nr-empty-desc">夜間對帳排程尚未執行，或尚未產出第一份報告。請稍後再試。</p>
      <button class="nr-cta-btn" @click="loadReport">重新檢查</button>
    </div>

    <!-- 有報告 -->
    <template v-else>
      <!-- 頂部摘要卡片 -->
      <div class="nr-summary" v-if="summary">
        <div class="nr-card">
          <span class="nr-card-num">{{ formatDateTime(summary.checked_at) }}</span>
          <span class="nr-card-label">對帳時間</span>
        </div>
        <div class="nr-card">
          <span class="nr-card-num">{{ summary.total_checked }}</span>
          <span class="nr-card-label">已檢查課程</span>
        </div>
        <div :class="['nr-card', summary.mismatch_count > 0 ? 'nr-card--danger' : 'nr-card--ok']">
          <span class="nr-card-num">{{ summary.mismatch_count }}</span>
          <span class="nr-card-label">異常筆數</span>
        </div>
      </div>

      <!-- 零異常狀態 -->
      <div v-if="summary.mismatch_count === 0" class="nr-all-clear" role="status">
        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:48px;color:var(--success)">check_circle</span>
        <p>所有課程對帳一致，無異常資料</p>
      </div>

      <!-- 異常列表區 -->
      <template v-else>
        <!-- 篩選列 -->
        <div class="nr-filters" role="toolbar" aria-label="篩選與排序工具列">
          <label class="nr-filter">
            <span class="nr-filter-label">分校</span>
            <select
              class="nr-select"
              :value="filters.campus"
              @change="setFilter('campus', ($event.target).value)"
              aria-label="依分校篩選"
            >
              <option value="">全部</option>
              <option v-for="opt in campusOptions" :key="opt" :value="opt">{{ opt }}</option>
            </select>
          </label>
          <label class="nr-filter">
            <span class="nr-filter-label">科目</span>
            <select
              class="nr-select"
              :value="filters.subject"
              @change="setFilter('subject', ($event.target).value)"
              aria-label="依科目篩選"
            >
              <option value="">全部</option>
              <option v-for="opt in subjectOptions" :key="opt" :value="opt">{{ opt }}</option>
            </select>
          </label>
          <label class="nr-filter">
            <span class="nr-filter-label">類別</span>
            <select
              class="nr-select"
              :value="filters.category"
              @change="setFilter('category', ($event.target).value)"
              aria-label="依異常類別篩選"
            >
              <option value="">全部</option>
              <option v-for="opt in categoryOptions" :key="opt" :value="opt">{{ categoryLabel(opt) }}</option>
            </select>
          </label>
          <div class="nr-filter-spacer"></div>
          <button
            class="nr-recompute-all-btn"
            :disabled="recomputing || filteredMismatches.length === 0"
            @click="recomputeAll"
            :aria-label="'重新計算全部 ' + filteredMismatches.length + ' 筆異常'"
          >
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:16px">play_arrow</span>
            全部重新計算（{{ filteredMismatches.length }} 筆）
          </button>
        </div>

        <!-- 重新計算結果提示 -->
        <div v-if="recomputeResults.length" class="nr-recompute-toast" role="status" aria-live="polite">
          <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
          已重新計算 {{ recomputeResults.length }} 筆課程計數器
        </div>

        <!-- 異常表格 -->
        <div class="nr-table-wrap" role="table" aria-label="對帳異常明細">
          <div class="nr-table-inner">
            <table class="nr-table">
              <thead>
                <tr>
                  <th
                    class="nr-th--sortable"
                    @click="toggleSort('student_name')"
                    :aria-sort="sortKey === 'student_name' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    學生
                    <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'student_name' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                  </th>
                  <th
                    class="nr-th--sortable"
                    @click="toggleSort('subject_name')"
                    :aria-sort="sortKey === 'subject_name' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    科目
                    <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'subject_name' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                  </th>
                  <th
                    class="nr-th--sortable"
                    @click="toggleSort('campus_name')"
                    :aria-sort="sortKey === 'campus_name' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    分校
                    <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'campus_name' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                  </th>
                  <th scope="col">總堂數</th>
                  <th
                    class="nr-th--sortable"
                    @click="toggleSort('recorded_used')"
                    :aria-sort="sortKey === 'recorded_used' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    已用（DB）
                    <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'recorded_used' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                  </th>
                  <th
                    class="nr-th--sortable"
                    @click="toggleSort('expected_used')"
                    :aria-sort="sortKey === 'expected_used' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    預期
                    <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'expected_used' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                  </th>
                  <th scope="col">實際出席</th>
                  <th
                    class="nr-th--sortable"
                    @click="toggleSort('diff')"
                    :aria-sort="sortKey === 'diff' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    差異
                    <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'diff' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                  </th>
                  <th scope="col">類別</th>
                  <th scope="col">操作</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in filteredMismatches"
                  :key="row.student_class_id"
                  :class="diffColorClass(row.diff)"
                >
                  <td class="nr-cell-name">{{ row.student_name }}</td>
                  <td>{{ row.subject_name }}</td>
                  <td>{{ row.campus_name }}</td>
                  <td class="nr-cell-num">{{ row.session_count }}</td>
                  <td class="nr-cell-num">{{ row.recorded_used }}</td>
                  <td class="nr-cell-num">{{ row.expected_used }}</td>
                  <td class="nr-cell-num">{{ row.actual_attended }}</td>
                  <td class="nr-cell-diff">
                    <span :class="['nr-diff-badge', 'nr-diff-badge--' + diffSeverity(row.diff)]">
                      {{ row.diff > 0 ? '+' : '' }}{{ row.diff }}
                    </span>
                  </td>
                  <td>
                    <span class="nr-category-tag">{{ categoryLabel(row.category) }}</span>
                  </td>
                  <td>
                    <button
                      class="nr-recompute-btn"
                      :disabled="recomputing"
                      @click="recomputeOne(row.student_class_id)"
                      :aria-label="'重新計算 ' + row.student_name + ' 的計數器'"
                    >
                      <span class="material-symbols-outlined" aria-hidden="true" style="font-size:14px">refresh</span>
                      重算
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 篩選後空狀態 -->
        <div v-if="filteredMismatches.length === 0 && mismatches.length > 0" class="nr-filter-empty" role="status">
          <span class="material-symbols-outlined" aria-hidden="true">filter_alt_off</span>
          <p>依目前篩選條件無匹配的異常項目</p>
          <button class="nr-cta-btn nr-cta-btn--sm" @click="clearFilters">清除篩選</button>
        </div>

        <!-- 顯示筆數資訊 -->
        <div class="nr-info" v-if="filteredMismatches.length > 0">
          顯示 {{ filteredMismatches.length }} / {{ mismatches.length }} 筆異常
          <template v-if="filters.campus || filters.subject || filters.category">（已套用篩選）</template>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { onMounted, computed } from 'vue';
import { useNightlyReconcile } from '../composables/useNightlyReconcile';

const props = defineProps({
  token: { type: String, required: true },
});

const tokenRef = computed(() => props.token);

const {
  report,
  loading,
  error,
  filters,
  filteredMismatches,
  campusOptions,
  subjectOptions,
  categoryOptions,
  summary,
  loadReport,
  recomputeOne,
  recomputeAll,
  recomputing,
  recomputeResults,
  setFilter,
  toggleSort,
  sortKey,
  sortDir,
  diffColorClass,
  categoryLabel,
} = useNightlyReconcile(tokenRef);

// 取得 mismatches 原始陣列（供篩選後空狀態判斷）
const mismatches = computed(() => {
  if (!report.value || !Array.isArray(report.value.mismatches)) return [];
  return report.value.mismatches;
});

function clearFilters() {
  setFilter('campus', '');
  setFilter('subject', '');
  setFilter('category', '');
}

function formatDateTime(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  const y = d.getFullYear();
  const mo = String(d.getMonth() + 1).padStart(2, '0');
  const da = String(d.getDate()).padStart(2, '0');
  const h = String(d.getHours()).padStart(2, '0');
  const mi = String(d.getMinutes()).padStart(2, '0');
  return `${y}-${mo}-${da} ${h}:${mi}`;
}

function diffSeverity(diff) {
  const d = Math.abs(diff);
  if (d === 0) return 'ok';
  if (d <= 2) return 'warn';
  if (d <= 5) return 'caution';
  return 'danger';
}

onMounted(() => {
  loadReport();
});
</script>

<style scoped>
/* === Layout === */
.nightly-reconcile {
  max-width: 1280px;
  margin: 0 auto;
  padding: 20px 16px 40px;
  color: var(--text);
}

/* === Header === */
.nr-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.nr-title {
  font-size: 22px;
  font-weight: 700;
  margin: 0 0 4px;
}

.nr-subtitle {
  margin: 0;
  font-size: 13px;
  color: var(--text-light);
}

.nr-refresh-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 8px 14px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--card-bg);
  color: var(--text);
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
}

.nr-refresh-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* === Loading / Error / Empty === */
.nr-loading {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 40px;
  justify-content: center;
  color: var(--text-light);
}

.nr-spinner {
  width: 20px;
  height: 20px;
  border: 2px solid var(--border);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: nr-spin 0.7s linear infinite;
}

@keyframes nr-spin {
  to { transform: rotate(360deg); }
}

.nr-error {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
  color: #b91c1c;
  font-size: 14px;
}

.nr-retry-btn {
  margin-left: auto;
  padding: 4px 12px;
  border: 1px solid #fca5a5;
  border-radius: 6px;
  background: #fff;
  color: #b91c1c;
  font-size: 12px;
  cursor: pointer;
}

.nr-empty {
  text-align: center;
  padding: 60px 20px;
}

.nr-empty-icon {
  font-size: 56px;
  color: var(--text-light);
  display: block;
  margin-bottom: 12px;
}

.nr-empty-title {
  font-size: 18px;
  margin: 0 0 6px;
  color: var(--text);
}

.nr-empty-desc {
  margin: 0 0 16px;
  font-size: 14px;
  color: var(--text-light);
}

.nr-cta-btn {
  padding: 8px 18px;
  border: 1px solid var(--accent);
  border-radius: 8px;
  background: transparent;
  color: var(--accent);
  font-size: 13px;
  cursor: pointer;
}

.nr-cta-btn--sm {
  padding: 4px 12px;
  font-size: 12px;
}

/* === Summary Cards === */
.nr-summary {
  display: flex;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.nr-card {
  flex: 1;
  min-width: 140px;
  padding: 14px 16px;
  border-radius: 10px;
  background: var(--card-bg);
  border: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.nr-card--danger {
  border-color: #fecaca;
  background: #fef2f2;
}

.nr-card--ok {
  border-color: #bbf7d0;
  background: #f0fdf4;
}

.nr-card-num {
  font-size: 22px;
  font-weight: 700;
  color: var(--text);
}

.nr-card--danger .nr-card-num {
  color: #dc2626;
}

.nr-card--ok .nr-card-num {
  color: #16a34a;
}

.nr-card-label {
  font-size: 12px;
  color: var(--text-light);
}

/* === All Clear === */
.nr-all-clear {
  text-align: center;
  padding: 56px 20px;
  color: var(--text-light);
  font-size: 15px;
}

/* === Filters === */
.nr-filters {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 14px;
  padding: 12px;
  border-radius: 8px;
  background: var(--card-bg);
  border: 1px solid var(--border);
}

.nr-filter {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.nr-filter-label {
  font-size: 11px;
  color: var(--text-light);
  font-weight: 600;
}

.nr-select {
  padding: 6px 10px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--card-bg);
  color: var(--text);
  font-size: 13px;
  min-width: 120px;
}

.nr-filter-spacer {
  flex: 1;
}

.nr-recompute-all-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 8px 14px;
  border: 1px solid var(--accent);
  border-radius: 8px;
  background: var(--accent);
  color: #fff;
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
}

.nr-recompute-all-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* === Recompute Toast === */
.nr-recompute-toast {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  margin-bottom: 12px;
  border-radius: 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #16a34a;
  font-size: 13px;
}

/* === Table === */
.nr-table-wrap {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--card-bg);
}

.nr-table-inner {
  min-width: 860px;
}

.nr-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.nr-table th {
  padding: 10px 12px;
  text-align: left;
  font-weight: 600;
  font-size: 12px;
  color: var(--text-light);
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
  background: var(--card-bg);
}

.nr-table td {
  padding: 10px 12px;
  border-bottom: 1px solid var(--border-light, rgba(148,163,184,0.18));
  white-space: nowrap;
}

.nr-th--sortable {
  cursor: pointer;
  user-select: none;
}

.nr-th--sortable:hover {
  color: var(--accent);
}

.nr-sort-icon {
  display: inline-block;
  width: 12px;
  font-size: 10px;
  margin-left: 2px;
}

.nr-cell-name {
  font-weight: 600;
}

.nr-cell-num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.nr-cell-diff {
  text-align: center;
}

.nr-diff-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.nr-diff-badge--ok {
  background: #f0fdf4;
  color: #16a34a;
}

.nr-diff-badge--warn {
  background: #fefce8;
  color: #ca8a04;
}

.nr-diff-badge--caution {
  background: #fff7ed;
  color: #ea580c;
}

.nr-diff-badge--danger {
  background: #fef2f2;
  color: #dc2626;
}

.nr-category-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  background: var(--bg-light, #f1f5f9);
  color: var(--text-light);
}

.nr-recompute-btn {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 3px 10px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--card-bg);
  color: var(--text);
  font-size: 12px;
  cursor: pointer;
}

.nr-recompute-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* === Row color-code by diff severity === */
.reconcile-diff--ok td { }
.reconcile-diff--warn td { background: rgba(250, 204, 21, 0.06); }
.reconcile-diff--caution td { background: rgba(249, 115, 22, 0.07); }
.reconcile-diff--danger td { background: rgba(239, 68, 68, 0.08); }

/* === Filter Empty === */
.nr-filter-empty {
  text-align: center;
  padding: 40px 20px;
  color: var(--text-light);
}

/* === Info === */
.nr-info {
  margin-top: 8px;
  font-size: 12px;
  color: var(--text-light);
}

/* === RWD === */
@media (max-width: 768px) {
  .nr-header {
    flex-direction: column;
  }

  .nr-summary {
    flex-direction: column;
  }

  .nr-card {
    min-width: unset;
  }

  .nr-filters {
    flex-direction: column;
    align-items: stretch;
  }

  .nr-select {
    width: 100%;
  }

  .nr-recompute-all-btn {
    justify-content: center;
  }
}
</style>
