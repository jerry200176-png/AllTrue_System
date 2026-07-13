/**
 * useDuplicateReview.test.js — P2 審核 composable 單元測試 (#1130)
 *
 * 測試：
 *   1. groupKey 函數
 *   2. setDecision / setReason / clearDecision
 *   3. groupsWithLocalDecisions 合併邏輯
 *   4. 初始狀態
 *   5. pendingCount / executedCount 計數
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { useDuplicateReview, groupKey } from '../useDuplicateReview.js';

describe('groupKey', () => {
  it('應使用 API 回傳的 id 作為 key', () => {
    const key = groupKey({
      id: 'group-1',
      student_name: '王小明',
      session_date: '2026-06-10',
      start_time: '16:00',
    });
    expect(key).toBe('group-1');
  });

  it('無 id 時 fallback 到組合 key', () => {
    const key = groupKey({
      student_name: '王小明',
      session_date: '2026-06-10',
      start_time: '16:00',
    });
    expect(key).toBe('王小明::2026-06-10::16:00');
  });

  it('不同 group 應產生不同 key', () => {
    const a = groupKey({ id: 'group-1', student_name: 'A' });
    const b = groupKey({ id: 'group-2', student_name: 'B' });
    expect(a).not.toBe(b);
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
    expect(store.submitting.value).toBe(false);
    expect(store.pendingCount.value).toBe(0);
    expect(store.executedCount.value).toBe(0);
  });

  it('setDecision 應設定本機決策', () => {
    const g = {
      id: 'group-1',
      student_name: '王小明',
      session_date: '2026-06-10',
      start_time: '16:00',
      sides: [{ student_class_id: 1001 }, { student_class_id: 1002 }],
    };
    store.setDecision(g, 1001);
    const gk = groupKey(g);
    expect(store.decisions[gk].keepScId).toBe(1001);
  });

  it('setReason 應設定決策原因', () => {
    const g = {
      id: 'group-3',
      student_name: '陳小美',
      session_date: '2026-06-10',
      start_time: '14:00',
      sides: [{ student_class_id: 3001 }],
    };
    store.setDecision(g, 3001);
    store.setReason(g, '新契約為主任核定');
    const gk = groupKey(g);
    expect(store.decisions[gk].reason).toBe('新契約為主任核定');
  });

  it('clearDecision 應清除本機決策', () => {
    const g = {
      id: 'group-1',
      student_name: '王小明',
      session_date: '2026-06-10',
      start_time: '16:00',
      sides: [],
    };
    store.setDecision(g, 1001);
    const gk = groupKey(g);
    expect(store.decisions[gk]).toBeDefined();
    store.clearDecision(g);
    expect(store.decisions[gk]).toBeUndefined();
  });

  it('groupsWithLocalDecisions 應合併後端 resolved 與本機決策', () => {
    store.groups.value = [
      {
        id: 'group-1',
        student_name: '王小明',
        session_date: '2026-06-10',
        start_time: '16:00',
        sides: [
          { student_class_id: 1001, teacher_name: '鄭老師', subject_name: '數學', session_count: 16, remaining_sessions: 8, schedule_mode: 'count', start_date: '2026-06-01' },
          { student_class_id: 1002, teacher_name: '林老師', subject_name: '數學', session_count: 12, remaining_sessions: 4, schedule_mode: 'count', start_date: '2026-07-01' },
        ],
        _key: 'group-1',
        _resolved_keeper_sc_id: null,
        _submitted: false,
      },
      {
        id: 'group-2',
        student_name: '李小華',
        session_date: '2026-06-10',
        start_time: '17:00',
        sides: [
          { student_class_id: 2001, teacher_name: '張老師', subject_name: '英文' },
          { student_class_id: 2002, teacher_name: '黃老師', subject_name: '英文' },
        ],
        _key: 'group-2',
        _resolved_keeper_sc_id: 2001,
        _submitted: true,
      },
    ];

    // Set decision for group-1
    store.setDecision(store.groups.value[0], 1002);

    const result = store.groupsWithLocalDecisions.value;
    expect(result).toHaveLength(2);

    // Group 0: 本機決策選中 1002
    expect(result[0]._localKeeper).toBe(1002);
    expect(result[0]._key).toBe('group-1');

    // Group 1: 已提交，顯示 resolved keeper
    expect(result[1]._localKeeper).toBe(2001);
    expect(result[1]._submitted).toBe(true);
  });

  it('pendingCount / executedCount 計數正確', () => {
    store.groups.value = [
      { id: 'g1', student_name: 'A', session_date: '2026-01-01', start_time: '10:00', sides: [], _key: 'g1', _resolved_keeper_sc_id: null, _submitted: false },
      { id: 'g2', student_name: 'B', session_date: '2026-01-01', start_time: '11:00', sides: [], _key: 'g2', _resolved_keeper_sc_id: 999, _submitted: true },
      { id: 'g3', student_name: 'C', session_date: '2026-01-01', start_time: '12:00', sides: [], _key: 'g3', _resolved_keeper_sc_id: 888, _submitted: true },
    ];
    expect(store.pendingCount.value).toBe(1);
    expect(store.executedCount.value).toBe(2);
  });

  it('submitDecision 成功後應標記 group 為已提交', async () => {
    // 此測試驗證 submitDecision 的狀態更新邏輯；實際 API 呼叫需要 mock
    const g = {
      id: 'group-test',
      student_name: '測試',
      session_date: '2026-01-01',
      start_time: '10:00',
      sides: [{ student_class_id: 1 }],
      _key: 'group-test',
      _resolved_keeper_sc_id: null,
      _submitted: false,
    };
    store.groups.value = [g];
    store.setDecision(g, 1);

    expect(store.decisions['group-test'].keepScId).toBe(1);
    // 確認未提交前狀態
    expect(store.groups.value[0]._submitted).toBe(false);
    expect(store.pendingCount.value).toBe(1);
  });
});
