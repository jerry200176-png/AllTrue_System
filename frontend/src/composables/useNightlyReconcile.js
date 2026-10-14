import { ref, computed } from 'vue';
import { getReconcileLatest, getReconcileRows } from '../api';

/**
 * 夜間堂數對帳異常面板：讀最新報告、分類篩選。不改寫堂數。
 * 回傳 reactive 物件供 NightlyReconcilePanel 消費。
 *
 * @param {import('vue').Ref<string>} tokenRef - supabase access_token
 */
// What each mismatch type means and what to do next. Derived from
// NightlyReconcile::classifyMismatch() and docs/GUIDE_NIGHTLY_SESSION_RECONCILE.md.
// Repairs never happen on this page: the report is read-only.
export const CATEGORY_GUIDE = Object.freeze({
  attendance_ahead: {
    meaning: '點名、已上課的課堂或評量紀錄，比課程上的已用堂數多。',
    next: '逐一比對這門課的點名、課堂狀態、評量與扣堂紀錄（「出缺勤」看點名與課堂狀態，「學習評量」看評量），確認這幾堂是否真的有上，再把課程編號交給工程團隊修正。',
  },
  ledger_ahead: {
    meaning: '扣堂紀錄比課程上的已用堂數多，也比點名、已上課的課堂和評量紀錄多。',
    next: '逐筆比對扣堂與退回紀錄，和這門課的點名、課堂狀態、評量；找出哪一項都對不上的扣堂，再把課程編號交給工程團隊。',
  },
  counter_overstated: {
    meaning: '課程上的已用堂數，比依點名、已上課的課堂、評量和扣堂紀錄算出的還多。',
    next: '比對這門課的點名、課堂狀態、評量與扣堂紀錄，四項都找不到的已用堂數就是多記的；確認後再把課程編號交給工程團隊。',
  },
  partial_minutes: {
    meaning: '有按分鐘扣的課，換算成堂數後對不上。',
    next: '核對扣堂紀錄裡每次扣的分鐘數，與「課程查找」裡這門課一堂幾分鐘。',
  },
  contract_cap: {
    meaning: '依點名、已上課的課堂、評量或扣堂紀錄算出的已用堂數，超過這門課買的堂數（只檢查堂數制的課）。',
    next: '先比對這門課的點名、課堂狀態、評量與扣堂紀錄，找出重複或多算的一項（例如同一堂扣兩次、課堂狀態誤標已上）；每一項都對、確實上滿，再請分校主任確認續課。',
  },
  source_conflict: {
    meaning: '已取消的課堂仍留有點名、評量或扣堂紀錄。',
    next: '把這堂已取消的課堂和它的點名、評量、扣堂與退回紀錄逐一比對，確認這堂到底有沒有上，再把課程編號交給工程團隊。',
  },
  unknown: {
    meaning: '系統無法歸類這筆差異。',
    next: '把課程編號交給工程團隊。',
  },
});

// The scheduler runs reconcile:nightly daily at 02:00 Asia/Taipei (UTC+8, no
// DST). A run gets this long to finish before its report counts as missing.
export const RUN_HOUR_TAIPEI = 2;
export const RUN_GRACE_MS = 60 * 60 * 1000;
const TAIPEI_OFFSET_MS = 8 * 60 * 60 * 1000;
const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * The backend writes checked_at as "YYYY-MM-DD HH:mm:ss" in app time
 * (Asia/Taipei) with no offset; parse it as such so the result does not depend
 * on the viewer's timezone.
 */
export function parseServerTime(value) {
  const text = String(value || '');
  const m = text.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/);
  return new Date(m ? `${m[1]}T${m[2]}+08:00` : text);
}

/** Epoch ms of the most recent 02:00 Taipei run whose grace period has passed. */
export function latestExpectedRunMs(nowMs) {
  const taipei = new Date(nowMs + TAIPEI_OFFSET_MS);
  let run = Date.UTC(taipei.getUTCFullYear(), taipei.getUTCMonth(), taipei.getUTCDate(), RUN_HOUR_TAIPEI) - TAIPEI_OFFSET_MS;
  if (nowMs < run + RUN_GRACE_MS) run -= DAY_MS;
  return run;
}

/**
 * A report is stale when it is not a live scheduled run (a dry-run preview
 * never counts, #3945) or was written before the latest expected 02:00 run.
 */
export function isReportStale(report, nowMs) {
  if (!report) return false;
  if ((report.mode ?? 'live') !== 'live') return true;
  const checkedAt = parseServerTime(report.checked_at);
  if (Number.isNaN(checkedAt.getTime())) return false;
  return checkedAt.getTime() < latestExpectedRunMs(nowMs);
}

export function useNightlyReconcile(tokenRef) {
  // ---- 原始資料 ----
  const report = ref(null);            // GET /latest 回傳的整包 data（null = 無報告）
  const loading = ref(false);
  const error = ref('');
  const loadingAllRows = ref(false);
  const allRowsError = ref('');
  // Set when the server has no full row list for this report (a report from
  // before the rows file existed), so the panel stops offering the button.
  const allRowsUnavailable = ref(false);

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
    const threshold = Math.max(0, Number(report.value.threshold) || 0);
    return report.value.mismatches.filter((row) => Math.abs(Number(row.diff) || 0) > threshold);
  });

  const hiddenMismatchCounts = computed(() => {
    if (!report.value || !Array.isArray(report.value.mismatches)) return {};
    const threshold = Math.max(0, Number(report.value.threshold) || 0);
    return report.value.mismatches.reduce((counts, row) => {
      if (Math.abs(Number(row.diff) || 0) <= threshold) {
        const category = row.category || 'unknown';
        counts[category] = (counts[category] || 0) + 1;
      }
      return counts;
    }, {});
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
      // Keep report-level totals (which include rows beyond the 200-row detail
      // cap), while removing stale zero-difference rows that are still present
      // in an older snapshot.
      mismatch_count: Math.max(
        0,
        (Number(report.value.mismatch_count) || 0) - Object.values(hiddenMismatchCounts.value).reduce((sum, count) => sum + count, 0)
      ),
      cause_counts: Object.entries(report.value.cause_counts || {}).reduce((counts, [category, count]) => {
        const visibleCount = Math.max(0, Number(count) - Number(hiddenMismatchCounts.value[category] || 0));
        if (visibleCount > 0) counts[category] = visibleCount;
        return counts;
      }, {}),
    };
  });

  // The summary report holds the full mismatch_count but only the first 200
  // rows; loadAllRows() pages the rest in from the rows endpoint (#4044).
  const storedRowCount = computed(() => (Array.isArray(report.value?.mismatches) ? report.value.mismatches.length : 0));
  const detailTruncated = computed(() => (Number(report.value?.mismatch_count) || 0) > storedRowCount.value);

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
      allRowsError.value = '';
      allRowsUnavailable.value = false;
    } catch (e) {
      error.value = e.message || '載入失敗';
      report.value = null;
    } finally {
      loading.value = false;
    }
  }

  // ---- 載入全部明細（#4044）----
  async function loadAllRows() {
    const token = tokenRef?.value;
    if (!token || !report.value || loadingAllRows.value) return;
    const checkedAt = report.value.checked_at;
    loadingAllRows.value = true;
    allRowsError.value = '';
    try {
      const rows = [];
      let page = 1;
      let lastPage = 1;
      let complete = true;
      do {
        const { rows: pageRows, meta } = await getReconcileRows(token, page);
        if (meta.checked_at !== checkedAt) {
          throw new Error('對帳報告已更新，請按「重新載入」後再試。');
        }
        rows.push(...pageRows);
        lastPage = Math.max(1, Number(meta.last_page) || 1);
        complete = meta.complete !== false;
        page += 1;
      } while (page <= lastPage);
      if (rows.length > storedRowCount.value) {
        report.value = { ...report.value, mismatches: rows };
      }
      allRowsUnavailable.value = !complete;
    } catch (e) {
      allRowsError.value = e.message || '明細載入失敗';
    } finally {
      loadingAllRows.value = false;
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

  function categoryGuide(cat) {
    return CATEGORY_GUIDE[cat] || CATEGORY_GUIDE.unknown;
  }

  function categoryLabel(cat) {
    const map = {
      attendance_ahead: '出勤／評量證據領先',
      ledger_ahead: '扣堂帳本領先',
      counter_overstated: '已用堂數偏高',
      partial_minutes: '部分時數換算',
      contract_cap: '使用紀錄超出合約堂數',
      source_conflict: '課堂狀態與扣堂證據衝突',
      unknown: '待分類',
    };
    return map[cat] || (cat ? map.unknown : '');
  }

  return {
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
  };
}
