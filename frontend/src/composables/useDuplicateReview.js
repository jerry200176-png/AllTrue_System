/**
 * useDuplicateReview — P2 審核狀態管理 composable
 *
 * 職責：
 *   1. 載入 P2 審核清單（fetchP2ReviewGroups）
 *   2. 管理本機決策暫存（decisions: { groupKey → keeperScId }）
 *   3. 儲存決策到後端（saveDecisions）
 *   4. 執行已審核決策（executeDecisions，僅 super_admin）
 */
import { ref, reactive, computed } from 'vue';
import {
  fetchP2ReviewGroups,
  saveDecisions,
  executeDecisions,
} from '../lib/duplicateReviewApi';

/**
 * 產生 group 唯一金鑰：student_id + session_date + start_time
 */
export function groupKey(g) {
  return `${g.student_id}::${g.session_date}::${g.start_time}`;
}

export function useDuplicateReview() {
  // ── state ──
  const groups = ref([]);
  const total = ref(0);
  const loading = ref(false);
  const error = ref('');
  const activeTab = ref('pending');

  /** 本機暫存決策：{ groupKey → keeperScId } */
  const decisions = reactive({});

  const saving = ref(false);
  const saveError = ref('');

  const executing = ref(false);
  const executeResult = ref(null);
  const executeError = ref('');

  const selectedKeys = ref(new Set());

  // ── computed ──
  const pendingCount = computed(() =>
    groups.value.filter((g) => !g.review).length
  );
  const decidedCount = computed(() =>
    groups.value.filter((g) => g.review && g.review.status === 'decided').length
  );
  const executedCount = computed(() =>
    groups.value.filter((g) => g.review && g.review.status === 'executed').length
  );

  const groupsWithLocalDecisions = computed(() =>
    groups.value.map((g) => {
      const key = groupKey(g);
      return {
        ...g,
        _key: key,
        _localKeeper:
          decisions[key] !== undefined
            ? decisions[key]
            : g.review?.keeper_sc_id ?? null,
      };
    })
  );

  /** 有本機決策但尚未儲存的 group */
  const unsavedDecisions = computed(() => {
    const list = [];
    for (const [key, keeperScId] of Object.entries(decisions)) {
      const g = groups.value.find((gg) => groupKey(gg) === key);
      if (!g) continue;
      const backendKeeper = g.review?.keeper_sc_id ?? null;
      if (backendKeeper !== keeperScId) {
        list.push({ group: g, key, keeperScId });
      }
    }
    return list;
  });

  // ── actions ──

  /**
   * 載入審核清單。
   */
  async function load({ campusId, status } = {}) {
    loading.value = true;
    error.value = '';
    try {
      const payload = await fetchP2ReviewGroups({ campusId, status });
      groups.value = Array.isArray(payload?.data?.groups)
        ? payload.data.groups
        : [];
      total.value = payload?.data?.total ?? groups.value.length;
    } catch (e) {
      error.value = e?.message || '載入審核清單失敗';
      groups.value = [];
    } finally {
      loading.value = false;
    }
  }

  /**
   * 設定本機決策。
   */
  function setDecision(g, scId) {
    const key = groupKey(g);
    decisions[key] = scId;
  }

  /**
   * 清除本機決策。
   */
  function clearDecision(g) {
    const key = groupKey(g);
    delete decisions[key];
  }

  /**
   * 儲存所有未儲存的本機決策到後端。
   */
  async function save() {
    const list = unsavedDecisions.value;
    if (list.length === 0) return { saved: 0 };
    saving.value = true;
    saveError.value = '';
    try {
      const payload = list.map((d) => ({
        student_id: d.group.student_id,
        session_date: d.group.session_date,
        start_time: d.group.start_time,
        keeper_sc_id: d.keeperScId,
      }));
      const result = await saveDecisions(payload);
      // 清除已儲存的決策
      for (const d of list) {
        delete decisions[d.key];
      }
      return result?.data ?? { saved: list.length };
    } catch (e) {
      saveError.value = e?.payload?.message || e?.message || '儲存決策失敗';
      throw e;
    } finally {
      saving.value = false;
    }
  }

  /**
   * 執行已審核決策（僅 super_admin）。
   */
  async function execute({ reviewIds, campusId } = {}) {
    executing.value = true;
    executeError.value = '';
    executeResult.value = null;
    try {
      const result = await executeDecisions({ reviewIds, campusId });
      executeResult.value = result?.data ?? null;
      return executeResult.value;
    } catch (e) {
      executeError.value = e?.payload?.message || e?.message || '執行失敗';
      throw e;
    } finally {
      executing.value = false;
    }
  }

  /**
   * 切換全選。
   */
  function toggleSelectAll() {
    const all = groupsWithLocalDecisions.value.filter(
      (g) => !g.review || g.review.status !== 'executed'
    );
    if (selectedKeys.value.size === all.length) {
      selectedKeys.value = new Set();
    } else {
      selectedKeys.value = new Set(all.map((g) => g._key));
    }
  }

  function toggleSelect(g) {
    const key = groupKey(g);
    const next = new Set(selectedKeys.value);
    if (next.has(key)) {
      next.delete(key);
    } else {
      next.add(key);
    }
    selectedKeys.value = next;
  }

  function isSelected(g) {
    return selectedKeys.value.has(groupKey(g));
  }

  return {
    // state
    groups,
    total,
    loading,
    error,
    activeTab,
    decisions,
    saving,
    saveError,
    executing,
    executeResult,
    executeError,
    selectedKeys,
    // computed
    pendingCount,
    decidedCount,
    executedCount,
    groupsWithLocalDecisions,
    unsavedDecisions,
    // actions
    load,
    setDecision,
    clearDecision,
    save,
    execute,
    toggleSelectAll,
    toggleSelect,
    isSelected,
  };
}
