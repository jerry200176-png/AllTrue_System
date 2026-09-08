<script setup>
import { computed } from 'vue';

const props = defineProps({
  query: { type: String, default: '' },
  entityGroups: { type: Array, default: () => [] },
  featureGroups: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  activeIndex: { type: Number, default: -1 },
  mobile: { type: Boolean, default: false },
  getBadgeCount: { type: Function, default: () => 0 },
});

const emit = defineEmits(['select', 'retry']);
const normalizedQuery = computed(() => props.query.trim());
const isSearching = computed(() => normalizedQuery.value.length > 0);
const entityItems = computed(() => props.entityGroups.flatMap(group => group.items || []));
const featureItems = computed(() => props.featureGroups.flatMap(group => group.items || []));
const hasResults = computed(() => entityItems.value.length > 0 || featureItems.value.length > 0);

function itemId(index) {
  return `global-search-result-${props.mobile ? 'mobile' : 'desktop'}-${index}`;
}

function emitSelection(kind, item) {
  emit('select', { kind, item });
}
</script>

<template>
  <p v-if="!isSearching" class="global-search-description">
    搜尋學生、老師、課程與功能；結果只顯示你已獲授權的資料。
  </p>
  <div v-else-if="loading" class="global-search-status" role="status" aria-live="polite">
    搜尋中…
  </div>
  <div v-else-if="error" class="global-search-status global-search-status-error" role="alert">
    <span>搜尋暫時無法完成，請再試一次。</span>
    <button type="button" class="global-search-retry" @click="emit('retry')">重試</button>
  </div>
  <template v-else>
    <div v-for="(group, groupIndex) in entityGroups" :key="`entity-${group.key}`" class="global-search-group">
      <div class="global-search-group-title">{{ group.title }}</div>
      <div class="global-search-items">
        <button
          v-for="(item, itemIndex) in (group.items || [])"
          :id="itemId(entityGroups.slice(0, groupIndex).reduce((total, current) => total + (current.items || []).length, 0) + itemIndex)"
          :key="`${group.key}-${item.id}`"
          type="button"
          :class="['global-search-item', { active: activeIndex === entityGroups.slice(0, groupIndex).reduce((total, current) => total + (current.items || []).length, 0) + itemIndex }]"
          @click="emitSelection('entity', item)"
        >
          <span class="material-symbols-outlined global-search-item-icon" aria-hidden="true">
            {{ item.type === 'student' ? 'person' : item.type === 'teacher' ? 'badge' : 'menu_book' }}
          </span>
          <span class="global-search-item-copy">
            <span class="global-search-item-title">{{ item.title }}</span>
            <span class="global-search-item-subtitle">{{ item.subtitle }}</span>
            <span v-if="item.meta" class="global-search-item-meta">{{ item.meta }}</span>
          </span>
        </button>
      </div>
    </div>
    <div v-for="(group, groupIndex) in featureGroups" :key="`feature-${group.key}`" class="global-search-group">
      <div class="global-search-group-title">{{ group.title }}</div>
      <div class="global-search-items">
        <button
          v-for="(item, itemIndex) in (group.items || [])"
          :id="itemId(entityItems.length + featureGroups.slice(0, groupIndex).reduce((total, current) => total + (current.items || []).length, 0) + itemIndex)"
          :key="`feature-${item.page}`"
          type="button"
          class="global-search-item"
          :class="{ active: activeIndex === entityItems.length + featureGroups.slice(0, groupIndex).reduce((total, current) => total + (current.items || []).length, 0) + itemIndex }"
          :disabled="item.disabled"
          @click="emitSelection('feature', item)"
        >
          <span class="material-symbols-outlined global-search-item-icon" aria-hidden="true">{{ item.icon }}</span>
          <span class="global-search-item-copy">
            <span class="global-search-item-title">{{ item.label }}</span>
            <span class="global-search-item-subtitle">{{ group.title }}</span>
          </span>
          <span v-if="getBadgeCount(item) > 0" class="global-search-item-badge">
            {{ getBadgeCount(item) > 99 ? '99+' : getBadgeCount(item) }}
          </span>
        </button>
      </div>
    </div>
    <div v-if="!hasResults" class="global-search-empty" role="status">
      找不到符合「{{ normalizedQuery }}」的學生、老師、課程或功能
    </div>
  </template>
</template>

<style scoped>
.global-search-description,
.global-search-status,
.global-search-empty {
  margin: 14px 0 18px;
  color: var(--ds-ink-mute);
  font-size: 13px;
  line-height: 1.6;
}

.global-search-status,
.global-search-empty {
  padding: 18px 10px;
  text-align: center;
}

.global-search-status-error { color: var(--ds-danger); }

.global-search-retry {
  display: block;
  margin: 10px auto 0;
  padding: 5px 10px;
  color: var(--ds-primary);
  background: var(--ds-primary-wash);
  border: 0;
  border-radius: 6px;
  cursor: pointer;
}

.global-search-group + .global-search-group { margin-top: 18px; }
.global-search-group-title {
  margin-bottom: 7px;
  color: var(--ds-ink-mute);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.07em;
}
.global-search-items { display: grid; gap: 4px; }
.global-search-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  min-height: 48px;
  padding: 8px 10px;
  color: var(--ds-ink-secondary);
  background: transparent;
  border: 1px solid transparent;
  border-radius: 9px;
  font: inherit;
  text-align: left;
  cursor: pointer;
}
.global-search-item:hover,
.global-search-item.active {
  color: var(--ds-ink);
  background: var(--ds-primary-wash);
  border-color: rgba(239, 108, 0, 0.22);
}
.global-search-item:disabled { opacity: 0.55; cursor: not-allowed; }
.global-search-item-icon { flex: 0 0 22px; color: var(--ds-primary-deep); font-size: 20px; text-align: center; }
.global-search-item-copy { min-width: 0; display: grid; gap: 2px; }
.global-search-item-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 14px; }
.global-search-item-subtitle,
.global-search-item-meta { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--ds-ink-mute); font-size: 12px; }
.global-search-item-badge { margin-left: auto; min-width: 22px; padding: 1px 6px; border-radius: 999px; background: var(--ds-danger); color: var(--ds-on-primary); font-size: 10px; font-weight: 700; text-align: center; }

:global(.more-sheet) .global-search-description,
:global(.more-sheet) .global-search-status,
:global(.more-sheet) .global-search-empty { color: var(--ds-ink-mute); }
:global(.more-sheet) .global-search-group-title { color: var(--ds-ink-mute); }
:global(.more-sheet) .global-search-item { color: var(--ds-ink-secondary); background: rgba(148, 163, 184, 0.1); border-color: rgba(148, 163, 184, 0.15); }
:global(.more-sheet) .global-search-item:hover,
:global(.more-sheet) .global-search-item.active { color: var(--ds-ink); background: rgba(148, 163, 184, 0.2); }
:global(.more-sheet) .global-search-item-subtitle,
:global(.more-sheet) .global-search-item-meta { color: var(--ds-ink-mute); }

@media (max-width: 480px) {
  .global-search-item { min-height: 52px; }
  .global-search-item-title { font-size: 13px; }
}
</style>
