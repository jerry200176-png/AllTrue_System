<template>
  <div class="nightly-reconcile" role="region" aria-label="夜間堂數對帳異常面板">
    <AtPageHeader
      title="夜間堂數對帳"
      description="每天 02:00 核對每門課的已用堂數。本頁只顯示結果，不改數字。"
      icon="receipt_long"
      data-guide="nightly-reconcile-header"
    >
      <template #actions>
        <AtButton shape="rect" variant="ghost" icon="refresh" :loading="loading" @click="loadReport">重新載入</AtButton>
      </template>
    </AtPageHeader>

    <!-- Loading -->
    <div v-if="loading" class="nr-loading" role="status" aria-live="polite">
      <div class="nr-spinner" aria-hidden="true"></div>
      <span>載入對帳報告中…</span>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="nr-error" role="alert">
      <span class="material-symbols-outlined" aria-hidden="true">error</span>
      <span>{{ error }}</span>
      <AtButton class="nr-retry-btn" shape="rect" size="sm" variant="ghost" @click="loadReport">重試</AtButton>
    </div>

    <!-- 無報告（404） -->
    <div v-else-if="!report" class="nr-empty" role="status">
      <span class="material-symbols-outlined nr-empty-icon" aria-hidden="true">receipt_long</span>
      <h2 class="nr-empty-title">尚無堂數對帳報告</h2>
      <p class="nr-empty-desc">02:00 排程尚未跑完，或今天還沒寫出報告。這與帳務中心／銀行勾稽無關。</p>
      <AtButton shape="rect" variant="secondary" @click="loadReport">重新檢查</AtButton>
    </div>

    <!-- 有報告 -->
    <template v-else>
      <!-- 摘要：昨晚核對了什麼、幾筆對不上 -->
      <div
        :class="['nr-verdict', summary.mismatch_count > 0 ? 'nr-verdict--danger' : 'nr-verdict--ok']"
        role="status"
        data-testid="nr-verdict"
      >
        <span class="material-symbols-outlined" aria-hidden="true">{{ summary.mismatch_count > 0 ? 'error' : 'check_circle' }}</span>
        <div>
          <strong class="nr-verdict-title">{{ verdictTitle }}</strong>
          <p class="nr-verdict-desc">{{ formatDateTime(summary.checked_at) }} 核對 {{ summary.total_checked }} 門上課中的課程：已用堂數、扣堂紀錄、點名與評量紀錄。</p>
        </div>
      </div>

      <AtInlineAlert v-if="isStale" tone="warning" title="最近一次 02:00 核對沒有新報告" data-testid="nr-stale">
        <p>每天 02:00 的核對可能沒跑，請通知工程團隊。</p>
      </AtInlineAlert>

      <details class="nr-explainer">
        <summary>怎麼核對</summary>
        <ul>
          <li><strong>課程上的已用堂數</strong>：課程目前記錄用掉幾堂。</li>
          <li><strong>系統應為</strong>：依點名、已上課的課堂、評量與扣堂紀錄算出應用掉幾堂。堂數制的課不超過買的堂數；月結（依上課日收費）的課沒有這個上限。</li>
          <li><strong>課堂狀態已上</strong>：課堂狀態為已到、遲到或完成的堂數，只供參考。</li>
        </ul>
        <p>這不是學費或銀行對帳。</p>
      </details>

      <!-- 異常列表區 -->
      <template v-if="summary.mismatch_count > 0">
        <section class="nr-guide" aria-label="各類差異的處理方式" data-testid="nr-guide">
          <h2 class="nr-guide-title">怎麼處理</h2>
          <article v-for="item in guideItems" :key="item.category" class="nr-guide-item" :data-category="item.category">
            <h3>{{ categoryLabel(item.category) }}<span class="nr-guide-count">{{ item.count }} 門</span></h3>
            <p><span class="nr-guide-key">意思</span>{{ categoryGuide(item.category).meaning }}</p>
            <p><span class="nr-guide-key">下一步</span>{{ categoryGuide(item.category).next }}</p>
          </article>
          <div v-if="detailTruncated" class="nr-guide-foot nr-truncated" data-testid="nr-truncated">
            <p>
              明細目前列出前 {{ storedRowCount }} 筆，共 {{ report.mismatch_count }} 筆差異；上面的分類數字包含全部。
              <template v-if="allRowsUnavailable">這份報告沒有保存其餘明細，下一次夜間核對後就能看到全部。</template>
            </p>
            <AtButton
              v-if="!allRowsUnavailable"
              shape="rect"
              size="sm"
              variant="secondary"
              icon="expand_more"
              :loading="loadingAllRows"
              data-testid="nr-load-all"
              @click="loadAllRows"
            >載入全部 {{ report.mismatch_count }} 筆明細</AtButton>
            <p v-if="allRowsError" class="nr-truncated-error" role="alert" data-testid="nr-load-all-error">{{ allRowsError }}</p>
          </div>
          <p class="nr-guide-foot">本頁不會改數字。修正堂數由工程團隊處理，需先備份並經核准。</p>
        </section>

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
        </div>

        <!-- 異常表格 -->
        <div class="nr-table-wrap" role="table" aria-label="對帳異常明細">
          <div class="nr-table-inner">
            <table class="nr-table">
              <thead>
                <tr>
                  <th
                    class="nr-th--sortable"
                    :aria-sort="sortKey === 'student_name' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    <button type="button" class="nr-sort-button" @click="toggleSort('student_name')" :aria-label="`依學生${sortKey === 'student_name' && sortDir === 'desc' ? '升冪' : '降冪'}排序`">
                      學生
                      <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'student_name' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                    </button>
                  </th>
                  <th
                    class="nr-th--sortable"
                    :aria-sort="sortKey === 'subject_name' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    <button type="button" class="nr-sort-button" @click="toggleSort('subject_name')" :aria-label="`依科目${sortKey === 'subject_name' && sortDir === 'desc' ? '升冪' : '降冪'}排序`">
                      科目
                      <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'subject_name' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                    </button>
                  </th>
                  <th
                    class="nr-th--sortable"
                    :aria-sort="sortKey === 'campus_name' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    <button type="button" class="nr-sort-button" @click="toggleSort('campus_name')" :aria-label="`依分校${sortKey === 'campus_name' && sortDir === 'desc' ? '升冪' : '降冪'}排序`">
                      分校
                      <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'campus_name' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                    </button>
                  </th>
                  <th scope="col">課程編號</th>
                  <th scope="col">總堂數</th>
                  <th
                    class="nr-th--sortable"
                    :aria-sort="sortKey === 'recorded_used' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    <button type="button" class="nr-sort-button" @click="toggleSort('recorded_used')" :aria-label="`依課程已用堂數${sortKey === 'recorded_used' && sortDir === 'desc' ? '升冪' : '降冪'}排序`">
                      課程已用
                      <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'recorded_used' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                    </button>
                  </th>
                  <th
                    class="nr-th--sortable"
                    :aria-sort="sortKey === 'expected_used' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    <button type="button" class="nr-sort-button" @click="toggleSort('expected_used')" :aria-label="`依系統應為堂數${sortKey === 'expected_used' && sortDir === 'desc' ? '升冪' : '降冪'}排序`">
                      系統應為
                      <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'expected_used' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                    </button>
                  </th>
                  <th scope="col">課堂狀態已上</th>
                  <th
                    class="nr-th--sortable"
                    :aria-sort="sortKey === 'diff' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                    scope="col"
                  >
                    <button type="button" class="nr-sort-button" @click="toggleSort('diff')" :aria-label="`依差異${sortKey === 'diff' && sortDir === 'desc' ? '升冪' : '降冪'}排序`">
                      差異
                      <span class="nr-sort-icon" aria-hidden="true">{{ sortKey === 'diff' ? (sortDir === 'asc' ? '▲' : '▼') : '' }}</span>
                    </button>
                  </th>
                  <th scope="col">類別</th>
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
                  <td class="nr-cell-num">{{ row.student_class_id }}</td>
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
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 篩選後空狀態 -->
        <div v-if="filteredMismatches.length === 0 && mismatches.length > 0" class="nr-filter-empty" role="status">
          <span class="material-symbols-outlined" aria-hidden="true">filter_alt_off</span>
          <p>依目前篩選條件無匹配的異常項目</p>
          <AtButton shape="rect" size="sm" variant="ghost" @click="clearFilters">清除篩選</AtButton>
        </div>

        <!-- 顯示筆數資訊 -->
        <div class="nr-info" v-if="filteredMismatches.length > 0">
          顯示 {{ filteredMismatches.length }} / {{ mismatches.length }} 筆異常
          <template v-if="filters.campus || filters.subject || filters.category">（已篩選）</template>
          <template v-if="detailTruncated">（只列出前 {{ storedRowCount }} 筆）</template>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { onMounted, onBeforeUnmount, computed, ref } from 'vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
import { useNightlyReconcile, isReportStale } from '../composables/useNightlyReconcile';

const props = defineProps({
  token: { type: String, required: true },
});

const tokenRef = computed(() => props.token);

const {
  report,
  loading,
  error,
  filters,
  mismatches,
  filteredMismatches,
  campusOptions,
  subjectOptions,
  categoryOptions,
  summary,
  causeCounts,
  storedRowCount,
  detailTruncated,
  loadReport,
  loadAllRows,
  loadingAllRows,
  allRowsError,
  allRowsUnavailable,
  setFilter,
  toggleSort,
  sortKey,
  sortDir,
  diffColorClass,
  categoryLabel,
  categoryGuide,
} = useNightlyReconcile(tokenRef);

// Date.now() is not reactive: tick a clock so a tab left open still turns stale.
const now = ref(Date.now());
let clockTimer = null;
onMounted(() => {
  clockTimer = setInterval(() => { now.value = Date.now(); }, 60 * 1000);
});
onBeforeUnmount(() => clearInterval(clockTimer));

// Stale = no live report since the latest expected 02:00 run (plus grace);
// a dry-run preview never counts as the scheduled run (#3945).
const isStale = computed(() => isReportStale(report.value, now.value));

const verdictTitle = computed(() => {
  const count = summary.value?.mismatch_count || 0;
  if (count > 0) return `${count} 門課程需要確認`;
  return isStale.value ? '最近一次核對無異常' : '昨晚核對無異常';
});

// Older reports may lack cause_counts; fall back to the detail rows.
const guideItems = computed(() => {
  if (causeCounts.value.length) return causeCounts.value;
  const counts = {};
  for (const row of mismatches.value) {
    const category = row.category || 'unknown';
    counts[category] = (counts[category] || 0) + 1;
  }
  return Object.entries(counts).map(([category, count]) => ({ category, count }));
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
  color: var(--ds-primary-text);
  font-size: 13px;
  cursor: pointer;
}

.nr-cta-btn--sm {
  padding: 4px 12px;
  font-size: 12px;
}

/* === Summary Cards === */
.nr-verdict {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-bottom: 14px;
  padding: 14px 16px;
  border: 1px solid var(--ds-border);
  border-radius: var(--ds-radius-md);
  background: var(--ds-surface);
}
.nr-verdict--ok { background: var(--ds-success-wash); }
.nr-verdict--ok > .material-symbols-outlined { color: var(--ds-success); }
.nr-verdict--danger { border-color: var(--ds-danger-border); background: var(--ds-danger-wash); }
.nr-verdict--danger > .material-symbols-outlined { color: var(--ds-danger); }
.nr-verdict-title { display: block; font-size: var(--ds-font-size-lg); color: var(--ds-text-primary); }
.nr-verdict-desc { margin: 4px 0 0; font-size: var(--ds-font-size-sm); color: var(--ds-text-secondary); line-height: 1.5; }

.nightly-reconcile :deep(.at-inline-alert) { margin-bottom: 14px; }

.nr-explainer {
  margin-bottom: 18px;
  font-size: var(--ds-font-size-sm);
  color: var(--ds-text-secondary);
}
.nr-explainer summary { cursor: pointer; font-weight: 600; color: var(--ds-text-primary); }
.nr-explainer ul { margin: 8px 0 0; padding-left: 20px; line-height: 1.6; }
.nr-explainer p { margin: 6px 0 0; }

.nr-guide {
  margin-bottom: 18px;
  border-top: 1px solid var(--ds-border);
}
.nr-guide-title { margin: 14px 0 4px; font-size: var(--ds-font-size-md); color: var(--ds-text-primary); }
.nr-guide-item { padding: 10px 0; border-bottom: 1px solid var(--ds-border); }
.nr-guide-item h3 {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  margin: 0 0 4px;
  font-size: var(--ds-font-size-base);
  color: var(--ds-text-primary);
}
.nr-guide-count { flex: 0 0 auto; font-weight: 600; color: var(--ds-danger); }
.nr-guide-item p { margin: 2px 0 0; font-size: var(--ds-font-size-sm); line-height: 1.5; color: var(--ds-text-secondary); }
.nr-guide-key { display: inline-block; min-width: 3.5em; margin-right: 6px; font-weight: 600; color: var(--ds-text-primary); }
.nr-guide-foot { margin: 10px 0 0; font-size: var(--ds-font-size-sm); color: var(--ds-text-secondary); }
.nr-truncated p { margin: 0 0 8px; }
.nr-truncated-error { color: var(--ds-danger); }

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
  min-height: var(--ds-control-height-touch, 44px);
  padding: 8px 10px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--card-bg);
  color: var(--text);
  font-size: 13px;
  min-width: 120px;
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
  padding: 0 !important;
}

.nr-sort-button {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  width: 100%;
  min-height: var(--ds-control-height-touch, 44px);
  padding: 8px 12px;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  font-weight: 600;
  text-align: left;
  cursor: pointer;
  user-select: none;
}

.nr-sort-button:hover {
  color: var(--ds-primary-text);
}

.nr-sort-button:focus-visible {
  outline: none;
  box-shadow: inset 0 0 0 3px var(--ds-focus-ring);
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

.nightly-reconcile button,
.nightly-reconcile :deep(.at-btn),
.nightly-reconcile :deep(.at-btn--sm) {
  min-height: var(--ds-control-height-touch, 44px);
}

/* === RWD === */
@media (max-width: 768px) {
  .nr-header {
    flex-direction: column;
  }

  .nr-filters {
    flex-direction: column;
    align-items: stretch;
  }

  .nr-select {
    width: 100%;
  }

  .nr-error {
    align-items: flex-start;
    flex-wrap: wrap;
  }

  .nr-retry-btn {
    margin-left: 0;
    flex: 1 0 100%;
  }

}
</style>
