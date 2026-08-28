<script setup>
import { computed, ref } from 'vue';
import AtBadge from '../components/design-system/AtBadge.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtFilterBar from '../components/design-system/AtFilterBar.vue';
import AtMetric from '../components/design-system/AtMetric.vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import { getUiImprovementSummary, UI_IMPROVEMENTS } from '../lib/uiImprovementCatalog.js';

const emit = defineEmits(['navigate']);
const severity = ref('');
const category = ref('');
const query = ref('');
const categories = [...new Set(UI_IMPROVEMENTS.map(item => item.category))];
const summary = getUiImprovementSummary();

const visibleItems = computed(() => UI_IMPROVEMENTS.filter((item) => {
  const text = `${item.title} ${item.impact} ${item.action} ${item.pageLabel}`.toLowerCase();
  return (!severity.value || item.severity === severity.value)
    && (!category.value || item.category === category.value)
    && (!query.value.trim() || text.includes(query.value.trim().toLowerCase()));
}));

function toneFor(level) {
  return level === 'P0' ? 'danger' : level === 'P1' ? 'warning' : 'neutral';
}

function clearFilters() {
  severity.value = '';
  category.value = '';
  query.value = '';
}
</script>

<template>
  <main class="ui-improvement-center">
    <AtPageHeader title="UI／營運改善" description="把主任與老師遇到的操作摩擦，整理成可追蹤、可驗收的改善清單。" icon="tune">
      <template #meta><span>掃描範圍：非總覽頁面</span><span>最後更新：2026-08-28</span></template>
      <template #actions><AtButton variant="ghost" shape="rect" icon="filter_alt_off" @click="clearFilters">清除篩選</AtButton></template>
    </AtPageHeader>

    <section class="ui-improvement-metrics" aria-label="改善摘要">
      <AtMetric label="已整理問題" :value="summary.total" accent="var(--ds-primary)" />
      <AtMetric label="優先處理 P0" :value="summary.P0" accent="var(--ds-danger)" />
      <AtMetric label="體驗改善 P1／P2" :value="summary.P1 + summary.P2" accent="var(--ds-warning)" />
    </section>

    <AtFilterBar label="篩選改善項目">
      <label>優先級<select v-model="severity"><option value="">全部優先級</option><option value="P0">P0 先處理</option><option value="P1">P1 重要</option><option value="P2">P2 優化</option></select></label>
      <label>問題類型<select v-model="category"><option value="">全部類型</option><option v-for="item in categories" :key="item" :value="item">{{ item }}</option></select></label>
      <label>搜尋<input v-model="query" type="search" placeholder="搜尋頁面或問題" /></label>
    </AtFilterBar>

    <section class="ui-improvement-list" aria-labelledby="ui-improvement-list-title">
      <div class="ui-improvement-list__head"><div><h3 id="ui-improvement-list-title">改善清單</h3><p>先處理 P0，再依主任實際使用情況逐批收斂。</p></div><span class="ui-improvement-count">顯示 {{ visibleItems.length }} 筆</span></div>
      <div v-if="visibleItems.length" class="ui-improvement-grid">
        <article v-for="item in visibleItems" :key="item.id" class="ui-improvement-item">
          <div class="ui-improvement-item__top"><AtBadge :tone="toneFor(item.severity)" :label="item.severity" /><span>{{ item.category }}</span></div>
          <h4>{{ item.title }}</h4>
          <p class="ui-improvement-item__impact">{{ item.impact }}</p>
          <p class="ui-improvement-item__action"><strong>建議：</strong>{{ item.action }}</p>
          <div class="ui-improvement-item__foot"><span>{{ item.pageLabel }}</span><AtButton variant="ghost" size="sm" shape="rect" icon="arrow_forward" @click="emit('navigate', item.page)">前往頁面</AtButton></div>
        </article>
      </div>
      <AtEmpty v-else icon="search_off" title="找不到符合條件的改善項目" description="請調整優先級、類型或搜尋文字。" />
    </section>

    <aside class="ui-improvement-principles" aria-label="本次 UI 原則"><span class="material-symbols-outlined" aria-hidden="true">rule</span><div><strong>本次改版原則</strong><p>一個區塊一個主要行動；先顯示待處理事項；細節收進篩選、抽屜或明細；狀態一定用文字說明。</p></div></aside>
  </main>
</template>

<style scoped>
.ui-improvement-center { max-width: 1320px; margin: 0 auto; }
.ui-improvement-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--ds-space-3); margin-bottom: var(--ds-space-4); }
.ui-improvement-list { background: var(--ds-canvas); border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-lg); padding: var(--ds-space-5); }
.ui-improvement-list__head, .ui-improvement-item__top, .ui-improvement-item__foot { display: flex; align-items: center; justify-content: space-between; gap: var(--ds-space-3); }
.ui-improvement-list__head { margin-bottom: var(--ds-space-4); }
.ui-improvement-list h3 { color: var(--ds-ink); font-size: var(--ds-font-size-lg); }
.ui-improvement-list__head p, .ui-improvement-principles p { color: var(--ds-ink-mute); font-size: var(--ds-font-size-md); margin-top: 4px; }
.ui-improvement-count, .ui-improvement-item__top > span, .ui-improvement-item__foot > span { color: var(--ds-ink-mute); font-size: var(--ds-font-size-sm); }
.ui-improvement-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--ds-space-3); }
.ui-improvement-item { border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); padding: var(--ds-space-4); display: flex; flex-direction: column; gap: var(--ds-space-3); min-width: 0; }
.ui-improvement-item h4 { color: var(--ds-ink); font-size: var(--ds-font-size-base); line-height: var(--ds-line-base); }
.ui-improvement-item p { color: var(--ds-ink-secondary); font-size: var(--ds-font-size-md); line-height: var(--ds-line-loose); }
.ui-improvement-item__action { padding: var(--ds-space-3); background: var(--ds-surface-0); border-radius: var(--ds-radius-sm); }
.ui-improvement-item__foot { margin-top: auto; padding-top: var(--ds-space-2); border-top: 1px solid var(--ds-hairline); }
.ui-improvement-principles { display: flex; gap: var(--ds-space-3); margin-top: var(--ds-space-4); padding: var(--ds-space-4); border-left: 3px solid var(--ds-primary); background: var(--ds-primary-wash); border-radius: var(--ds-radius-md); }
.ui-improvement-principles > .material-symbols-outlined { color: var(--ds-primary-deep); }
.ui-improvement-principles strong { color: var(--ds-ink); font-size: var(--ds-font-size-base); }
@media (max-width: 768px) { .ui-improvement-metrics, .ui-improvement-grid { grid-template-columns: 1fr; } .ui-improvement-list { padding: var(--ds-space-4); } }
</style>
