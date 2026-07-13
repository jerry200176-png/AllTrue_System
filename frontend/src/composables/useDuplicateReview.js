/**
 * useDuplicateReview — P2 審核狀態管理 composable (#1130)
 *
 * 職責：
 *   1. 載入 P2 審核清單（fetchP2ReviewGroups）
 *   2. 管理本機決策暫存（decisions: { groupId → keepScId }）
 *   3. 逐組提交決策（patchP2ReviewGroup — 同時 decide + execute）
 */
import { ref, reactive, computed } from 'vue';
import {
  fetchP2ReviewGroups,
  patchP2ReviewGroup,
} from '../lib/duplicateReviewApi';

/**
 * 產生 group 唯一金鑰：id（來自 API response）。
 */
export function groupKey(g) {
  return g?.id ?? `${g?.student_name}::${g?.session_date}::${g?.start_time}`;
}

export function useDuplicateReview() {
  // ── state ──
  const groups = ref([]);
  const total = ref(0);
  const loading = ref(false);
  const error = ref('');
  const activeTab = ref('pending');

  /** 本機暫存決策：{ groupKey → { keepScId, reason } } */
  const decisions = reactive({});

  const submitting = ref(false);
  const submitError = ref('');

  const expandedKey = ref(null);

  // ── computed ──
  const pendingCount = computed(() =>
    groups.value.filter((g) => !g._submitted).length
  );
  const executedCount = computed(() =>
    groups.value.filter((g) => g._submitted).length
  );

  const groupsWithLocalDecisions = computed(() =>
    groups.value.map((g) => {
      const gk = groupKey(g);
      const d = decisions[gk];
      return {
        ...g,
        _key: gk,
        _localKeeper: d?.keepScId ?? g._resolved_keeper_sc_id ?? null,
        _localReason: d?.reason ?? '',
        _submitted: !!g._resolved_keeper_sc_id,
      };
    })
  );

  // ── actions ──

  /**
   * 載入審核清單。
   */
  async function load({ campusId, status } = {}) {
    loading.value = true;
    error.value = '';
    try {
      const payload = await fetchP2ReviewGroups({ campusId, status });
      const raw = Array.isArray(payload?.groups)
        ? payload.groups
        : Array.isArray(payload?.data?.groups)
          ? payload.data.groups
          : [];
      groups.value = raw.map((g) => ({
        ...g,
        _key: groupKey(g),
        _resolved_keeper_sc_id: g.resolved_keeper_sc_id ?? null,
        _submitted: !!g.resolved_keeper_sc_id,
      }));
      total.value = payload?.total ?? payload?.data?.total ?? groups.value.length;
    } catch (e) {
      error.value = e?.message || '載入審核清單失敗';
      groups.value = [];
    } finally {
      loading.value = false;
    }
  }

  /**
   * 設定本機決策（選擇保留哪個 SC）。
   */
  function setDecision(g, scId) {
    const gk = groupKey(g);
    if (!decisions[gk]) {
      decisions[gk] = { keepScId: scId, reason: '' };
    } else {
      decisions[gk].keepScId = scId;
    }
  }

  /**
   * 設定決策原因。
   */
  function setReason(g, reason) {
    const gk = groupKey(g);
    if (!decisions[gk]) {
      decisions[gk] = { keepScId: null, reason };
    } else {
      decisions[gk].reason = reason;
    }
  }

  /**
   * 提交單組決策到後端。
   */
  async function submitDecision(g) {
    const gk = groupKey(g);
    const d = decisions[gk];
    if (!d?.keepScId) return null;
    submitting.value = true;
    submitError.value = '';
    try {
      const result = await patchP2ReviewGroup(g.id, {
        keep_student_class_id: d.keepScId,
        reason: d.reason || undefined,
      });
      delete decisions[gk];
      // Mark as submitted to remove from pending list
      const idx = groups.value.findIndex((gg) => groupKey(gg) === gk);
      if (idx >= 0) {
        groups.value[idx]._submitted = true;
        groups.value[idx]._resolved_keeper_sc_id = d.keepScId;
      }
      return result;
    } catch (e) {
      if (e.status === 409) {
        submitError.value = '此組已被其他主任審核，請重新整理。';
      } else {
        submitError.value = e?.payload?.message || e?.message || '提交決策失敗';
      }
      throw e;
    } finally {
      submitting.value = false;
    }
  }

  /**
   * 清除本機決策。
   */
  function clearDecision(g) {
    const gk = groupKey(g);
    delete decisions[gk];
  }

  return {
    // state
    groups,
    total,
    loading,
    error,
    activeTab,
    decisions,
    submitting,
    submitError,
    expandedKey,
    // computed
    pendingCount,
    executedCount,
    groupsWithLocalDecisions,
    // actions
    load,
    setDecision,
    setReason,
    submitDecision,
    clearDecision,
  };
}
