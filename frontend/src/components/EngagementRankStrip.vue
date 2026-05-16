<template>
  <span class="ers" role="status">
    <span class="ers-badge" :title="engagement.rank_label" aria-hidden="true">
      <RocRankBadge :rank-key="engagement.rank_key" />
    </span>
    <span class="ers-rank">{{ engagement.rank_label }}</span>
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
</template>

<script setup>
import { computed } from 'vue';
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
</style>
