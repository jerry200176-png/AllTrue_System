/**
 * useDuplicateReview.test.js — P2 審核 composable 單元測試
 *
 * 測試：
 *   1. groupKey 函數
 *   2. setDecision / clearDecision
 *   3. groupsWithLocalDecisions 合併邏輯
 *   4. 初始狀態
 *   5. selectedKeys 全選 / 切換
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { useDuplicateReview, groupKey } from '../useDuplicateReview.js';

describe('groupKey', () => {
  it('應產生唯一金鑰', () => {
    const key = groupKey({
      student_id: 100,
      session_date: '2026-06-10',
      start_time: '16:00',
    });
    expect(key).toBe('100::2026-06-10::16:00');
  });

  it('不同 group 應產生不同 key', () => {
    const a = groupKey({ student_id: 1, session_date: '2026-01-01', start_time: '10:00' });
    const b = groupKey({ student_id: 1, session_date: '2026-01-01', start_time: '11:00' });
    const c = groupKey({ student_id: 2, session_date: '2026-01-01', start_time: '10:00' });
    expect(a).not.toBe(b);
    expect(a).not.toBe(c);
    expect(b).not.toBe(c);
  });
});

describe('useDuplicateReview', () => {
  let store;

  beforeEach(() => {
    store = useDuplicateReview();
  });

  it('初始狀態應為空', () => {
    expect(store.groups.value).toEqual([]);
    expect(store.total.value).toBe(0);
    expect(store.loading.value).toBe(false);
    expect(store.error.value).toBe('');
    expect(store.saving.value).toBe(false);
    expect(store.executing.value).toBe(false);
    expect(store.pendingCount.value).toBe(0);
    expect(store.decidedCount.value).toBe(0);
    expect(store.executedCount.value).toBe(0);
  });

  it('setDecision 應設定本機決策', () => {
    const g = {
      student_id: 100,
      session_date: '2026-06-10',
      start_time: '16:00',
      sides: [{ sc_id: 1001 }, { sc_id: 1002 }],
    };
    store.setDecision(g, 1001);
    const key = groupKey(g);
    expect(store.decisions[key]).toBe(1001);
  });

  it('clearDecision 應清除本機決策', () => {
    const g = { student_id: 100, session_date: '2026-06-10', start_time: '16:00', sides: [] };
    const key = groupKey(g);
    store.decisions[key] = 1001;
    store.clearDecision(g);
    expect(store.decisions[key]).toBeUndefined();
  });

  it('groupsWithLocalDecisions 應合併後端 review 與本機決策', () => {
    store.groups.value = [
      {
        student_id: 100,
        student_name: '王小明',
        session_date: '2026-06-10',
        start_time: '16:00',
        sides: [{ sc_id: 1001 }, { sc_id: 1002 }],
        review: null,
      },
      {
        student_id: 101,
        student_name: '李小華',
        session_date: '2026-06-10',
        start_time: '17:00',
        sides: [{ sc_id: 2001 }, { sc_id: 2002 }],
        review: { keeper_sc_id: 2001, status: 'decided' },
      },
    ];

    // 對第一個 group 設定本機決策
    store.setDecision(store.groups.value[0], 1002);

    const result = store.groupsWithLocalDecisions.value;
    expect(result).toHaveLength(2);

    // Group 0: 本機決策覆蓋 null review
    expect(result[0]._localKeeper).toBe(1002);
    expect(result[0]._key).toBe('100::2026-06-10::16:00');

    // Group 1: review 已有 keeper_sc_id
    expect(result[1]._localKeeper).toBe(2001);
  });

  it('pendingCount / decidedCount / executedCount 計數正確', () => {
    store.groups.value = [
      { student_id: 1, session_date: '2026-01-01', start_time: '10:00', sides: [], review: null },
      { student_id: 2, session_date: '2026-01-01', start_time: '11:00', sides: [], review: { status: 'decided' } },
      { student_id: 3, session_date: '2026-01-01', start_time: '12:00', sides: [], review: { status: 'decided' } },
      { student_id: 4, session_date: '2026-01-01', start_time: '13:00', sides: [], review: { status: 'executed' } },
    ];
    expect(store.pendingCount.value).toBe(1);
    expect(store.decidedCount.value).toBe(2);
    expect(store.executedCount.value).toBe(1);
    expect(store.total.value).toBe(0); // total 只在 load() 時設定，不會自動從 groups 計算
  });

  it('unsavedDecisions 應包含有本機決策但未儲存的 group', () => {
    store.groups.value = [
      {
        student_id: 100,
        session_date: '2026-06-10',
        start_time: '16:00',
        sides: [{ sc_id: 1001 }, { sc_id: 1002 }],
        review: null,
      },
    ];
    store.setDecision(store.groups.value[0], 1001);
    expect(store.unsavedDecisions.value).toHaveLength(1);
    expect(store.unsavedDecisions.value[0].keeperScId).toBe(1001);
  });

  it('unsavedDecisions 不應包含已儲存（與後端一致）的 group', () => {
    store.groups.value = [
      {
        student_id: 100,
        session_date: '2026-06-10',
        start_time: '16:00',
        sides: [{ sc_id: 1001 }, { sc_id: 1002 }],
        review: { keeper_sc_id: 1001, status: 'decided' },
      },
    ];
    store.setDecision(store.groups.value[0], 1001); // same as backend
    expect(store.unsavedDecisions.value).toHaveLength(0);
  });

  it('toggleSelectAll 應全選所有可編輯的 group', () => {
    store.groups.value = [
      { student_id: 1, session_date: '2026-01-01', start_time: '10:00', sides: [], review: null },
      { student_id: 2, session_date: '2026-01-01', start_time: '11:00', sides: [], review: { status: 'executed', keeper_sc_id: 999 } },
    ];
    store.selectedKeys.value = new Set();
    store.toggleSelectAll();
    // executed 的 group 不可選
    expect(store.selectedKeys.value.size).toBe(1);
    const key1 = groupKey(store.groups.value[0]);
    expect(store.selectedKeys.value.has(key1)).toBe(true);

    // 再次 toggle = 全取消
    store.toggleSelectAll();
    expect(store.selectedKeys.value.size).toBe(0);
  });

  it('toggleSelect 應切換單一 group', () => {
    const g = { student_id: 1, session_date: '2026-01-01', start_time: '10:00', sides: [], review: null };
    store.toggleSelect(g);
    expect(store.isSelected(g)).toBe(true);
    store.toggleSelect(g);
    expect(store.isSelected(g)).toBe(false);
  });
});
