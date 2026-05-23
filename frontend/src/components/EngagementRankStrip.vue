<template>
  <span class="ers" role="status">
    <button
      type="button"
      class="ers-rank-trigger"
      :title="`查看軍階一覽（目前：${engagement.rank_label}）`"
      @click="openRankOverview"
    >
      <span class="ers-badge" :title="engagement.rank_label" aria-hidden="true">
        <RocRankBadge :rank-key="engagement.rank_key" />
      </span>
      <span class="ers-rank">{{ engagement.rank_label }}</span>
    </button>
    <template v-if="showXp">
      <span class="ers-xp">XP {{ engagement.xp_total }}</span>
      <span v-if="!progress.isMax && remainingXp > 0" class="ers-remain">尚餘 {{ remainingXp }} XP 升級</span>
      <span v-else-if="progress.isMax" class="ers-remain ers-remain--max">已達最高階</span>
      <div v-if="!progress.isMax" class="ers-bar-wrap" aria-hidden="true">
        <div
          class="ers-bar-fill"
          :class="{ 'ers-bar-fill--still': reducedMotion }"
          :style="{ width: barWidth + '%' }"
        />
      </div>
    </template>
  </span>

  <teleport to="body">
    <div v-if="showRankOverview" class="rank-modal-overlay" @click.self="showRankOverview = false">
      <div class="rank-modal-card">
        <div class="rank-modal-header">
          <h3>軍階一覽</h3>
          <button type="button" class="rank-modal-close" @click="showRankOverview = false">×</button>
        </div>
        <div class="rank-modal-body">
          <p class="rank-modal-subtitle">
            目前軍階：<strong>{{ engagement.rank_label }}</strong>（XP {{ engagement.xp_total }}）
          </p>
          <p class="rank-modal-hint">
            升級方式：填寫/審核評量、完成點名與排課等教學流程可獲得 XP。
          </p>
          <p v-if="loadingRankOverview" class="rank-modal-state">載入軍階資料中...</p>
          <p v-else-if="rankOverviewError" class="rank-modal-state rank-modal-state--error">{{ rankOverviewError }}</p>
          <ul v-else class="rank-modal-list">
            <li
              v-for="row in rankOverviewRows"
              :key="row.key"
              :class="['rank-modal-item', { 'is-current': row.key === engagement.rank_key }]"
            >
              <RocRankBadge :rank-key="row.key" :size="22" />
              <span class="rank-modal-item-label">{{ row.label }}</span>
              <span class="rank-modal-item-xp">{{ row.min_xp }} XP</span>
              <span v-if="row.key === engagement.rank_key" class="rank-modal-item-current">目前</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { computed, ref } from 'vue';
import { rankTierProgress, xpRemainingToNext } from '../lib/engagementRankProgress';
import RocRankBadge from './RocRankBadge.vue';

const props = defineProps({
  engagement: {
    type: Object,
    required: true,
  },
  reducedMotion: {
    type: Boolean,
    default: false,
  },
});

const showXp = computed(() => typeof props.engagement?.xp_total === 'number');

const track = computed(() => (props.engagement?.role_track === 'staff' ? 'staff' : 'teacher'));

const progress = computed(() => {
  if (!showXp.value) {
    return { pct: 0, isMax: true, xpAtNext: null };
  }
  return rankTierProgress(props.engagement.xp_total, track.value);
});

const barWidth = computed(() => (showXp.value ? progress.value.pct : 0));

const remainingXp = computed(() => {
  if (!showXp.value) return 0;
  return xpRemainingToNext(props.engagement.xp_total, track.value);
});

const showRankOverview = ref(false);
const loadingRankOverview = ref(false);
const rankOverviewError = ref('');
const rankOverviewRows = ref([]);

function extractToken() {
  try {
    const raw = localStorage.getItem('alltrue_session');
    if (!raw) return '';
    const parsed = JSON.parse(raw);
    return parsed?.access_token || parsed?.token || raw;
  } catch {
    return '';
  }
}

async function loadRankOverview() {
  if (rankOverviewRows.value.length > 0 || loadingRankOverview.value) return;
  loadingRankOverview.value = true;
  rankOverviewError.value = '';
  try {
    const token = extractToken();
    const res = await fetch('/api/v1/engagement/rank-thresholds', {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    });
    if (!res.ok) {
      throw new Error('無法讀取軍階資料');
    }
    const data = await res.json();
    const rows = Array.isArray(data?.[track.value]) ? data[track.value] : [];
    rankOverviewRows.value = rows.map((row) => ({
      key: row.key,
      label: row.label || row.key,
      min_xp: Number(row.min_xp ?? 0),
    }));
    if (!rankOverviewRows.value.length) {
      rankOverviewError.value = '目前沒有可顯示的軍階資料';
    }
  } catch (e) {
    rankOverviewError.value = e?.message || '軍階資料載入失敗';
  } finally {
    loadingRankOverview.value = false;
  }
}

async function openRankOverview() {
  showRankOverview.value = true;
  await loadRankOverview();
}
</script>

<style scoped>
.ers {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px 10px;
  font-size: 13px;
  color: var(--text, #0f172a);
  line-height: 1.35;
}
.ers-badge {
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
}
.ers-rank-trigger {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  border: none;
  background: transparent;
  color: inherit;
  padding: 0;
  cursor: pointer;
}
.ers-rank {
  font-weight: 700;
}
.ers-xp {
  opacity: 0.9;
  font-variant-numeric: tabular-nums;
}
.ers-remain {
  font-size: 12px;
  color: var(--text-light, #64748b);
  width: 100%;
  flex-basis: 100%;
}
.ers-remain--max {
  font-size: 12px;
  color: var(--success-strong, #047857);
}
.ers-bar-wrap {
  width: 100%;
  flex-basis: 100%;
  height: 5px;
  border-radius: 999px;
  background: color-mix(in srgb, var(--text-light, #64748b) 18%, transparent);
  overflow: hidden;
}
.ers-bar-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, color-mix(in srgb, var(--primary, #1976d2) 55%, #0ea5e9), var(--primary, #1976d2));
  transition: width 0.45s ease;
}
.ers-bar-fill--still {
  transition: none;
}
@media (prefers-reduced-motion: reduce) {
  .ers-bar-fill {
    transition: none;
  }
}

.rank-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(2, 6, 23, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  padding: 12px;
}
.rank-modal-card {
  width: min(520px, 100%);
  max-height: 86vh;
  overflow: auto;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 14px 32px rgba(15, 23, 42, 0.2);
}
.rank-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid #e2e8f0;
}
.rank-modal-header h3 {
  margin: 0;
  font-size: 16px;
}
.rank-modal-close {
  border: none;
  background: transparent;
  font-size: 20px;
  cursor: pointer;
  color: #64748b;
}
.rank-modal-body {
  padding: 14px 16px 18px;
}
.rank-modal-subtitle,
.rank-modal-hint {
  margin: 0 0 8px;
  font-size: 13px;
  color: #334155;
}
.rank-modal-hint {
  color: #64748b;
}
.rank-modal-state {
  margin: 8px 0;
  font-size: 13px;
  color: #475569;
}
.rank-modal-state--error {
  color: #b91c1c;
}
.rank-modal-list {
  list-style: none;
  padding: 0;
  margin: 8px 0 0;
  display: grid;
  gap: 8px;
}
.rank-modal-item {
  display: grid;
  grid-template-columns: 24px 1fr auto auto;
  gap: 8px;
  align-items: center;
  padding: 8px 10px;
  border-radius: 8px;
  background: #f8fafc;
}
.rank-modal-item.is-current {
  background: #e0f2fe;
  border: 1px solid #7dd3fc;
}
.rank-modal-item-label {
  font-size: 13px;
  color: #0f172a;
}
.rank-modal-item-xp {
  font-size: 12px;
  color: #64748b;
}
.rank-modal-item-current {
  font-size: 12px;
  font-weight: 600;
  color: #0369a1;
}
</style>
