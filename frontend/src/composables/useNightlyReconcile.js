import { ref, computed } from 'vue';
import { getReconcileLatest } from '../api';

/**
 * 夜間堂數對帳異常面板：讀最新報告、分類篩選。不改寫堂數。
 * 回傳 reactive 物件供 NightlyReconcilePanel 消費。
 *
 * @param {import('vue').Ref<string>} tokenRef - supabase access_token
 */
export function useNightlyReconcile(tokenRef) {
  // ---- 原始資料 ----
  const report = ref(null);            // GET /latest 回傳的整包 data（null = 無報告）
  const loading = ref(false);
  const error = ref('');

  // ---- 篩選與排序 ----
  const filters = ref({
    campus: '',       // campus_name（空字串 = 全部）
    subject: '',      // subject_name
    category: '',     // attendance_ahead | ledger_ahead | counter_overstated | partial_minutes | contract_cap | source_conflict
  });
  const sortKey = ref('diff');        // 預設依 diff 降冪
  const sortDir = ref('desc');        // 'asc' | 'desc'

  /** 所有 mismatches 或空陣列 */
  const mismatches = computed(() => {
    if (!report.value || !Array.isArray(report.value.mismatches)) return [];
    return report.value.mismatches;
  });

  /** 套用當前篩選後 */
  const filteredMismatches = computed(() => {
    let rows = [...mismatches.value];

    if (filters.value.campus) {
      rows = rows.filter((r) => r.campus_name === filters.value.campus);
    }
    if (filters.value.subject) {
      rows = rows.filter((r) => r.subject_name === filters.value.subject);
    }
    if (filters.value.category) {
      rows = rows.filter((r) => r.category === filters.value.category);
    }

    // 排序
    const dir = sortDir.value === 'asc' ? 1 : -1;
    if (sortKey.value === 'diff') {
      rows.sort((a, b) => (a.diff - b.diff) * dir);
    } else if (sortKey.value === 'student_name') {
      rows.sort((a, b) => a.student_name.localeCompare(b.student_name, 'zh-TW') * dir);
    } else if (sortKey.value === 'subject_name') {
      rows.sort((a, b) => a.subject_name.localeCompare(b.subject_name, 'zh-TW') * dir);
    } else if (sortKey.value === 'campus_name') {
      rows.sort((a, b) => a.campus_name.localeCompare(b.campus_name, 'zh-TW') * dir);
    } else if (sortKey.value === 'recorded_used') {
      rows.sort((a, b) => (a.recorded_used - b.recorded_used) * dir);
    } else if (sortKey.value === 'expected_used') {
      rows.sort((a, b) => (a.expected_used - b.expected_used) * dir);
    }
    return rows;
  });

  /** unique 選項供篩選下拉 */
  const campusOptions = computed(() => {
    const set = new Set(mismatches.value.map((r) => r.campus_name).filter(Boolean));
    return [...set].sort((a, b) => a.localeCompare(b, 'zh-TW'));
  });
  const subjectOptions = computed(() => {
    const set = new Set(mismatches.value.map((r) => r.subject_name).filter(Boolean));
    return [...set].sort((a, b) => a.localeCompare(b, 'zh-TW'));
  });
  const categoryOptions = computed(() => {
    const set = new Set(mismatches.value.map((r) => r.category).filter(Boolean));
    return [...set].sort();
  });

  /** 頂部摘要 */
  const summary = computed(() => {
    if (!report.value) return null;
    return {
      checked_at: report.value.checked_at,
      total_checked: report.value.total_checked,
      mismatch_count: report.value.mismatch_count,
      cause_counts: report.value.cause_counts || {},
    };
  });

  const causeCounts = computed(() => {
    const counts = summary.value?.cause_counts || {};
    return Object.entries(counts)
      .map(([category, count]) => ({ category, count: Number(count) || 0 }))
      .filter((item) => item.count > 0)
      .sort((a, b) => b.count - a.count || a.category.localeCompare(b.category));
  });

  // ---- 載入 ----
  async function loadReport() {
    const token = tokenRef?.value;
    if (!token) return;
    loading.value = true;
    error.value = '';
    try {
      const data = await getReconcileLatest(token);
      report.value = data;  // null if 404
    } catch (e) {
      error.value = e.message || '載入失敗';
      report.value = null;
    } finally {
      loading.value = false;
    }
  }

  // ---- 篩選 / 排序 helpers ----
  function setFilter(key, value) {
    filters.value = { ...filters.value, [key]: value };
  }

  function toggleSort(key) {
    if (sortKey.value === key) {
      sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
      sortKey.value = key;
      sortDir.value = 'desc';
    }
  }

  // ---- helpers ----
  /**
   * 依 diff 大小回傳顏色 class
   * 0       → 正常
   * 1–2     → 注意（黃）
   * 3–5     → 警告（橘）
   * >5      → 嚴重（紅）
   */
  function diffColorClass(diff) {
    const d = Math.abs(diff);
    if (d === 0) return 'reconcile-diff--ok';
    if (d <= 2) return 'reconcile-diff--warn';
    if (d <= 5) return 'reconcile-diff--caution';
    return 'reconcile-diff--danger';
  }

  function categoryLabel(cat) {
    const map = {
      attendance_ahead: '出勤／評量證據領先',
      ledger_ahead: '扣堂帳本領先',
      counter_overstated: '已用堂數偏高',
      partial_minutes: '部分時數換算',
      contract_cap: '出勤超出合約堂數',
      source_conflict: '課堂狀態與扣堂證據衝突',
      unknown: '待分類',
    };
    return map[cat] || cat;
  }

  return {
    report,
    loading,
    error,
    filters,
    filteredMismatches,
    campusOptions,
    subjectOptions,
    categoryOptions,
    summary,
    causeCounts,
    loadReport,
    setFilter,
    toggleSort,
    sortKey,
    sortDir,
    diffColorClass,
    categoryLabel,
  };
}
