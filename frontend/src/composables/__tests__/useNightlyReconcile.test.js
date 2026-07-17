import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ref, nextTick } from 'vue';
import { useNightlyReconcile } from '../useNightlyReconcile';

// Mock api module
vi.mock('../../api', () => ({
  getReconcileLatest: vi.fn(),
  recomputeReconcile: vi.fn(),
}));

import * as api from '../../api';

// Helper: build a minimal report
function makeReport(overrides = {}) {
  return {
    checked_at: '2026-07-12 03:00:12',
    mode: 'live',
    threshold: 0,
    total_checked: 1428,
    mismatch_count: 3,
    mismatches: [
      {
        student_class_id: 101, student_id: 1, student_name: '王小明',
        subject_id: 7, subject_name: '數學', campus_id: 2, campus_name: '東湖',
        session_count: 24, recorded_used: 10, expected_used: 12,
        actual_attended: 12, diff: 2, category: 'counter_drift',
      },
      {
        student_class_id: 102, student_id: 2, student_name: '李小華',
        subject_id: 3, subject_name: '英文', campus_id: 1, campus_name: '板橋',
        session_count: 20, recorded_used: 5, expected_used: 8,
        actual_attended: 8, diff: 3, category: 'ledger_mismatch',
      },
      {
        student_class_id: 103, student_id: 3, student_name: '張大偉',
        subject_id: 7, subject_name: '數學', campus_id: 2, campus_name: '東湖',
        session_count: 16, recorded_used: 7, expected_used: 0,
        actual_attended: 0, diff: 7, category: 'contract',
      },
    ],
    ...overrides,
  };
}

function setup(tokenVal = 'test-token') {
  const tokenRef = ref(tokenVal);
  const result = useNightlyReconcile(tokenRef);
  return { tokenRef, ...result };
}

describe('useNightlyReconcile', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  // ---- loadReport ----
  it('loadReport fills report on success', async () => {
    const rep = makeReport();
    api.getReconcileLatest.mockResolvedValue(rep);
    const { report, loading, error, loadReport } = setup();
    await loadReport();
    expect(loading.value).toBe(false);
    expect(error.value).toBe('');
    expect(report.value).toEqual(rep);
  });

  it('loadReport sets report=null on 404', async () => {
    api.getReconcileLatest.mockResolvedValue(null);
    const { report, loading, error, loadReport } = setup();
    await loadReport();
    expect(report.value).toBeNull();
    expect(error.value).toBe('');
    expect(loading.value).toBe(false);
  });

  it('loadReport sets error on failure', async () => {
    api.getReconcileLatest.mockRejectedValue(new Error('Network error'));
    const { report, error, loadReport } = setup();
    await loadReport();
    expect(report.value).toBeNull();
    expect(error.value).toContain('Network error');
  });

  it('loadReport does nothing without token', async () => {
    const { loading, loadReport } = setup('');
    await loadReport();
    expect(api.getReconcileLatest).not.toHaveBeenCalled();
    expect(loading.value).toBe(false);
  });

  // ---- summary ----
  it('summary returns null when no report', () => {
    const { summary } = setup();
    expect(summary.value).toBeNull();
  });

  it('summary returns correct fields', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport({ checked_at: '2026-07-11 03:00:00', total_checked: 100, mismatch_count: 5 }));
    const { summary, loadReport } = setup();
    await loadReport();
    expect(summary.value).toEqual({
      checked_at: '2026-07-11 03:00:00',
      total_checked: 100,
      mismatch_count: 5,
    });
  });

  // ---- filteredMismatches (sorting) ----
  it('filteredMismatches default sort by diff desc', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    const { filteredMismatches, loadReport } = setup();
    await loadReport();
    const diffs = filteredMismatches.value.map(r => r.diff);
    expect(diffs).toEqual([7, 3, 2]); // desc
  });

  it('filteredMismatches sort by diff asc after toggle', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    const { filteredMismatches, loadReport, toggleSort } = setup();
    await loadReport();
    toggleSort('diff'); // was desc → now asc
    const diffs = filteredMismatches.value.map(r => r.diff);
    expect(diffs).toEqual([2, 3, 7]);
  });

  it('filteredMismatches sort by student_name asc', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    const { filteredMismatches, loadReport, toggleSort } = setup();
    await loadReport();
    toggleSort('student_name'); // default desc on new key
    const names = filteredMismatches.value.map(r => r.student_name);
    // Locale collation differs by ICU; assert order matches product comparator + same members.
    const expected = ['王小明', '李小華', '張大偉'].sort((a, b) => b.localeCompare(a, 'zh-TW'));
    expect(names).toEqual(expected);
    expect(new Set(names)).toEqual(new Set(['王小明', '李小華', '張大偉']));
  });

  // ---- filteredMismatches (filtering) ----
  it('filteredMismatches filters by campus', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    const { filteredMismatches, setFilter, loadReport } = setup();
    await loadReport();
    setFilter('campus', '東湖');
    expect(filteredMismatches.value).toHaveLength(2);
    expect(filteredMismatches.value.every(r => r.campus_name === '東湖')).toBe(true);
  });

  it('filteredMismatches filters by subject', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    const { filteredMismatches, setFilter, loadReport } = setup();
    await loadReport();
    setFilter('subject', '英文');
    expect(filteredMismatches.value).toHaveLength(1);
    expect(filteredMismatches.value[0].subject_name).toBe('英文');
  });

  it('filteredMismatches filters by category', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    const { filteredMismatches, setFilter, loadReport } = setup();
    await loadReport();
    setFilter('category', 'counter_drift');
    expect(filteredMismatches.value).toHaveLength(1);
    expect(filteredMismatches.value[0].category).toBe('counter_drift');
  });

  it('filteredMismatches combines filters', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    const { filteredMismatches, setFilter, loadReport } = setup();
    await loadReport();
    setFilter('campus', '東湖');
    setFilter('category', 'counter_drift');
    expect(filteredMismatches.value).toHaveLength(1);
    expect(filteredMismatches.value[0].student_name).toBe('王小明');
  });

  // ---- campusOptions / subjectOptions / categoryOptions ----
  it('campusOptions returns unique sorted campuses', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    const { campusOptions, loadReport } = setup();
    await loadReport();
    expect(campusOptions.value).toEqual(['板橋', '東湖'].sort((a, b) => a.localeCompare(b, 'zh-TW')));
  });

  it('subjectOptions returns unique sorted subjects', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    const { subjectOptions, loadReport } = setup();
    await loadReport();
    expect(subjectOptions.value).toEqual(['數學', '英文'].sort((a, b) => a.localeCompare(b, 'zh-TW')));
  });

  it('categoryOptions returns unique sorted categories', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    const { categoryOptions, loadReport } = setup();
    await loadReport();
    expect(categoryOptions.value).toEqual(['contract', 'counter_drift', 'ledger_mismatch']);
  });

  // ---- recompute ----
  it('recomputeOne calls API and reloads', async () => {
    const rep = makeReport();
    api.getReconcileLatest.mockResolvedValue(rep);
    api.recomputeReconcile.mockResolvedValue({ recomputed: 1, results: [{ student_class_id: 101, before: 10, after: 12 }] });
    const { recomputeOne, recomputeResults, loadReport } = setup();
    await loadReport();
    await recomputeOne(101);
    expect(api.recomputeReconcile).toHaveBeenCalledWith('test-token', [101]);
    expect(recomputeResults.value).toHaveLength(1);
    expect(recomputeResults.value[0]).toEqual({ student_class_id: 101, before: 10, after: 12 });
  });

  it('recomputeOne sets error on failure', async () => {
    api.getReconcileLatest.mockResolvedValue(makeReport());
    api.recomputeReconcile.mockRejectedValue(new Error('Server error'));
    const { recomputeOne, error, loadReport } = setup();
    await loadReport();
    await recomputeOne(101);
    expect(error.value).toContain('Server error');
  });

  it('recomputeAll calls API with all IDs', async () => {
    const rep = makeReport();
    api.getReconcileLatest.mockResolvedValue(rep);
    api.recomputeReconcile.mockResolvedValue({ recomputed: 3, results: [] });
    const { recomputeAll, loadReport } = setup();
    await loadReport();
    await recomputeAll();
    expect(api.recomputeReconcile).toHaveBeenCalledWith('test-token', [101, 102, 103]);
  });

  it('recomputeAll does nothing when no mismatches', async () => {
    const { recomputeAll } = setup();
    await recomputeAll();
    expect(api.recomputeReconcile).not.toHaveBeenCalled();
  });

  // ---- diffColorClass ----
  it('diffColorClass returns correct classes', () => {
    const { diffColorClass } = setup();
    expect(diffColorClass(0)).toBe('reconcile-diff--ok');
    expect(diffColorClass(1)).toBe('reconcile-diff--warn');
    expect(diffColorClass(2)).toBe('reconcile-diff--warn');
    expect(diffColorClass(3)).toBe('reconcile-diff--caution');
    expect(diffColorClass(5)).toBe('reconcile-diff--caution');
    expect(diffColorClass(6)).toBe('reconcile-diff--danger');
    expect(diffColorClass(-3)).toBe('reconcile-diff--caution');
  });

  // ---- categoryLabel ----
  it('categoryLabel returns Chinese labels', () => {
    const { categoryLabel } = setup();
    expect(categoryLabel('counter_drift')).toBe('計數器漂移');
    expect(categoryLabel('ledger_mismatch')).toBe('帳本不一致');
    expect(categoryLabel('partial_minutes')).toBe('部分時數');
    expect(categoryLabel('contract')).toBe('合約');
    expect(categoryLabel('unknown')).toBe('未知');
    expect(categoryLabel('')).toBe('');
  });

  // ---- sortKey / sortDir ----
  it('toggleSort toggles direction on same key', () => {
    const { sortKey, sortDir, toggleSort } = setup();
    expect(sortKey.value).toBe('diff');
    expect(sortDir.value).toBe('desc');
    toggleSort('diff');
    expect(sortDir.value).toBe('asc');
    toggleSort('diff');
    expect(sortDir.value).toBe('desc');
  });

  it('toggleSort changes key and resets to desc', () => {
    const { sortKey, sortDir, toggleSort } = setup();
    toggleSort('diff'); // asc
    toggleSort('student_name'); // new key → desc
    expect(sortKey.value).toBe('student_name');
    expect(sortDir.value).toBe('desc');
  });

  // ---- filters default ----
  it('filters default to empty strings', () => {
    const { filters } = setup();
    expect(filters.value).toEqual({ campus: '', subject: '', category: '' });
  });
});
