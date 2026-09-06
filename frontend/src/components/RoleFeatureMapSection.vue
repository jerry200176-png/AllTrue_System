<script setup>
import { computed, ref } from 'vue';
import { getRoleFeatureMap } from '../lib/roleFeatureMap.js';

const props = defineProps({
  role: { type: String, default: 'director' },
  admissionsEnabled: { type: Boolean, default: true },
});
const emit = defineEmits(['select-page']);

const activeFilter = ref('all');
const searchQuery = ref('');

const featureMap = computed(() => getRoleFeatureMap(props.role, { admissionsEnabled: props.admissionsEnabled }));

const filteredGroups = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  const f = activeFilter.value;
  return featureMap.value.groups.map(group => ({
    ...group,
    items: group.items.filter(item => {
      if (f === 'high' && item.frequency !== 'high') return false;
      if (f === 'advanced' && item.frequency !== 'advanced') return false;
      if (!q) return true;
      return item.label.toLowerCase().includes(q) || item.usage.toLowerCase().includes(q) || group.title.toLowerCase().includes(q);
    }),
  })).filter(g => g.items.length > 0);
});
</script>

<template>
  <div class="role-feature-map" role="region" aria-label="角色功能地圖">
    <header class="rfm-header">
      <div class="rfm-badge"><span class="material-symbols-outlined" aria-hidden="true">map</span>完整功能地圖 · 接下來你還可以做什麼</div>
      <h3 class="rfm-title">{{ featureMap.roleLabel }}功能指南</h3>
      <p class="rfm-sub">共收錄 <strong>{{ featureMap.totalCount }}</strong> 項現行可用功能。建議新手優先熟悉 <span class="tag-high">常用高頻 ({{ featureMap.highFrequencyCount }})</span> 日常工作流；其他 <span class="tag-adv">進階工具 ({{ featureMap.advancedCount }})</span> 可依時機點擊直接體驗：</p>
    </header>

    <div class="rfm-controls">
      <div class="rfm-filters" role="tablist">
        <button type="button" class="rfm-btn" :class="{ active: activeFilter === 'all' }" @click="activeFilter = 'all'">全部 ({{ featureMap.totalCount }})</button>
        <button type="button" class="rfm-btn" :class="{ active: activeFilter === 'high' }" data-testid="filter-high" @click="activeFilter = 'high'">⭐ 常用高頻 ({{ featureMap.highFrequencyCount }})</button>
        <button type="button" class="rfm-btn" :class="{ active: activeFilter === 'advanced' }" data-testid="filter-advanced" @click="activeFilter = 'advanced'">🛠️ 進階工具 ({{ featureMap.advancedCount }})</button>
      </div>
      <div class="rfm-search">
        <span class="material-symbols-outlined search-icon" aria-hidden="true">search</span>
        <input v-model="searchQuery" type="search" placeholder="搜尋功能或時機…" class="search-input" aria-label="搜尋功能" />
      </div>
    </div>

    <div v-if="filteredGroups.length === 0" class="rfm-empty" role="status">
      <p>沒有符合「{{ searchQuery }}」的功能。<button type="button" class="rfm-reset-btn" @click="searchQuery = ''; activeFilter = 'all'">重設篩選</button></p>
    </div>

    <div v-else class="rfm-groups">
      <section v-for="g in filteredGroups" :key="g.key" class="rfm-group">
        <div class="rfm-group-hdr">
          <h4>{{ g.title }}</h4>
          <span v-if="g.primary" class="primary-pill">每日核心</span>
        </div>
        <div class="rfm-grid">
          <button v-for="item in g.items" :key="item.page" type="button" class="rfm-card" :class="[`is-${item.frequency}`]" :data-page="item.page" @click="emit('select-page', item.page)">
            <div class="card-head">
              <span class="material-symbols-outlined card-ico" aria-hidden="true">{{ item.icon }}</span>
              <span class="card-name">{{ item.label }}</span>
              <span class="card-tag" :class="item.frequency">{{ item.frequencyLabel }}</span>
            </div>
            <p class="card-desc">{{ item.usage }}</p>
            <div class="card-foot"><span>點擊前往</span><span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></div>
          </button>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.role-feature-map { width: 100%; margin-top: 20px; padding-top: 18px; border-top: 1px solid var(--ds-primary-wash); text-align: left; }
.rfm-header { margin-bottom: 14px; }
.rfm-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; color: var(--ds-primary); background: var(--ds-primary-wash); padding: 3px 8px; border-radius: 99px; margin-bottom: 6px; }
.rfm-badge .material-symbols-outlined { font-size: 15px; }
.rfm-title { margin: 0 0 4px; font-size: 17px; font-weight: 700; color: var(--ds-ink); }
.rfm-sub { margin: 0; font-size: 12px; line-height: 1.5; color: var(--ds-ink-mute); }
.tag-high { background: var(--ds-primary-wash); color: var(--ds-primary-deep); padding: 1px 5px; border-radius: 4px; font-weight: 600; font-size: 11px; }
.tag-adv { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); padding: 1px 5px; border-radius: 4px; font-weight: 600; font-size: 11px; }
.rfm-controls { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin: 12px 0 14px; }
.rfm-filters { display: flex; gap: 4px; background: var(--ds-canvas-soft); padding: 3px; border-radius: 8px; }
.rfm-btn { padding: 5px 10px; border: none; background: transparent; color: var(--ds-ink-mute); font-size: 11px; font-weight: 600; border-radius: 6px; cursor: pointer; transition: all .15s; }
.rfm-btn.active { background: var(--ds-canvas); color: var(--ds-primary); box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08); }
.rfm-search { position: relative; display: flex; align-items: center; min-width: 180px; flex: 1; max-width: 260px; }
.search-icon { position: absolute; left: 8px; font-size: 16px; color: var(--ds-ink-mute); pointer-events: none; }
.search-input { width: 100%; padding: 6px 12px 6px 28px; border: 1px solid var(--ds-hairline-input); border-radius: 6px; font-size: 12px; outline: none; }
.search-input:focus { border-color: var(--ds-primary); }
.rfm-empty { padding: 20px; text-align: center; color: var(--ds-ink-mute); font-size: 12px; }
.rfm-reset-btn { margin-left: 8px; padding: 3px 8px; background: var(--ds-primary); color: var(--ds-on-primary); border: none; border-radius: 4px; font-size: 11px; cursor: pointer; }
.rfm-groups { display: flex; flex-direction: column; gap: 16px; }
.rfm-group { display: flex; flex-direction: column; gap: 8px; }
.rfm-group-hdr { display: flex; align-items: center; gap: 6px; }
.rfm-group-hdr h4 { margin: 0; font-size: 13px; font-weight: 700; color: var(--ds-ink); }
.primary-pill { font-size: 10px; font-weight: 600; color: var(--ds-primary-deep); background: var(--ds-primary-wash); padding: 1px 6px; border-radius: 99px; }
.rfm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px; }
.rfm-card { display: flex; flex-direction: column; justify-content: space-between; padding: 10px 12px; background: var(--ds-canvas); border: 1px solid var(--ds-hairline); border-radius: 10px; cursor: pointer; text-align: left; transition: all .15s ease; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03); }
.rfm-card:hover { border-color: var(--ds-primary); transform: translateY(-1px); box-shadow: 0 3px 8px color-mix(in srgb, var(--ds-primary) 12%, transparent); }
.rfm-card.is-high { border-left: 3px solid var(--ds-primary); }
.rfm-card.is-advanced { border-left: 3px solid var(--ds-hairline-input); }
.card-head { display: flex; align-items: center; gap: 6px; margin-bottom: 5px; }
.card-ico { font-size: 17px; color: var(--ds-primary); }
.card-name { font-size: 12px; font-weight: 700; color: var(--ds-ink); flex: 1; }
.card-tag { font-size: 10px; font-weight: 600; padding: 1px 5px; border-radius: 4px; }
.card-tag.high { background: var(--ds-primary-wash); color: var(--ds-primary-deep); }
.card-tag.advanced { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.card-desc { margin: 0 0 8px; font-size: 11px; line-height: 1.4; color: var(--ds-ink-secondary); flex: 1; }
.card-foot { display: flex; align-items: center; justify-content: space-between; font-size: 10px; font-weight: 600; color: var(--ds-primary); border-top: 1px dashed var(--ds-hairline); padding-top: 5px; }
.card-foot .material-symbols-outlined { font-size: 13px; }
@media (max-width: 640px) { .rfm-controls { flex-direction: column; align-items: stretch; } .rfm-search { max-width: none; } .rfm-grid { grid-template-columns: 1fr; } }
</style>
