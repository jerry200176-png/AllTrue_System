<template>
  <div class="rn-page">
    <AtPageHeader title="版本更新" description="用白話整理近期會影響工作的改變；完整工程紀錄仍保留在 CHANGELOG。" icon="new_releases">
      <template #meta>
        <span v-if="latestNote">最新版本 <strong>{{ latestNote.version }}</strong></span>
        <span>{{ notes.length }} 則公告</span>
      </template>
      <template #actions>
        <a class="rn-changelog-link" :href="changelogUrl" target="_blank" rel="noopener noreferrer">查看完整 CHANGELOG</a>
      </template>
    </AtPageHeader>

    <div v-if="latestNote" class="rn-featured">
      <span class="rn-version rn-version--large">{{ latestNote.version }}</span>
      <div>
        <div class="rn-title-row">
          <span v-if="latestNote.importance" class="rn-importance" :data-importance="latestNote.importance">
            {{ importanceLabel(latestNote.importance) }}
          </span>
          <h3>{{ latestNote.title }}</h3>
        </div>
        <p>{{ latestNote.summary }}</p>
        <small
          v-if="latestNote.effectiveAt && latestNote.effectiveAt !== latestNote.publishedAt"
          class="rn-effective"
        >
          已於 {{ latestNote.effectiveAt }} 生效
        </small>
      </div>
      <span class="rn-featured-label">最近一次更新</span>
    </div>

    <div v-if="notes.length === 0" class="rn-empty">目前尚無可顯示的更新內容。</div>

    <p v-if="olderNotes.length" class="rn-compact-hint">
      顯示最近 {{ recentNotes.length + (latestNote ? 1 : 0) }} 則；其餘公告可點擊展開。
    </p>

    <section v-for="note in recentNotes" :key="note.id || `${note.version}-${note.title}`" class="rn-item">
      <div class="rn-item-head">
        <span class="rn-version">{{ note.version }}</span>
        <div>
          <div class="rn-title-row">
            <span v-if="note.importance" class="rn-importance" :data-importance="note.importance">
              {{ importanceLabel(note.importance) }}
            </span>
            <strong>{{ note.title }}</strong>
          </div>
          <small v-if="note.date">{{ note.date }}</small>
        </div>
      </div>
      <p v-if="note.summary" class="rn-summary">{{ note.summary }}</p>
      <details class="rn-sections-details">
        <summary>查看操作細節</summary>
        <div class="rn-sections">
          <section
            v-for="section in normalizedSections(note)"
            :key="`${note.id || note.version}-${section.title}`"
            class="rn-section"
          >
            <h4>{{ section.title }}</h4>
            <ul class="rn-list">
              <li v-for="row in section.items" :key="row">{{ row }}</li>
            </ul>
          </section>
        </div>
      </details>
    </section>

    <details v-if="olderNotes.length" class="rn-older-details">
      <summary>查看更早公告（{{ olderNotes.length }} 則）</summary>
      <section v-for="note in olderNotes" :key="note.id || `${note.version}-${note.title}`" class="rn-item">
        <div class="rn-item-head">
          <span class="rn-version">{{ note.version }}</span>
          <div>
            <div class="rn-title-row">
              <span v-if="note.importance" class="rn-importance" :data-importance="note.importance">
                {{ importanceLabel(note.importance) }}
              </span>
              <strong>{{ note.title }}</strong>
            </div>
            <small v-if="note.date">{{ note.date }}</small>
          </div>
        </div>
        <p v-if="note.summary" class="rn-summary">{{ note.summary }}</p>
        <details class="rn-sections-details">
          <summary>查看操作細節</summary>
          <div class="rn-sections">
            <section
              v-for="section in normalizedSections(note)"
              :key="`${note.id || note.version}-${section.title}`"
              class="rn-section"
            >
              <h4>{{ section.title }}</h4>
              <ul class="rn-list">
                <li v-for="row in section.items" :key="row">{{ row }}</li>
              </ul>
            </section>
          </div>
        </details>
      </section>
    </details>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { notesForRole } from '../lib/releaseNotes';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';

const props = defineProps({
  userRole: { type: String, default: '' },
});

const changelogUrl =
  'https://github.com/jerry200176-png/AllTrue_System/blob/main/docs/CHANGELOG.md';

const notes = computed(() => notesForRole(props.userRole));
const latestNote = computed(() => notes.value[0] || null);
const recentNotes = computed(() => notes.value.slice(1, 4));
const olderNotes = computed(() => notes.value.slice(4));

function normalizedSections(note) {
  if (Array.isArray(note.sections) && note.sections.length > 0) {
    return note.sections;
  }
  return [{ title: '你現在可以', items: note.items || [] }];
}

function importanceLabel(importance) {
  if (importance === 'action_required') return '需要注意';
  if (importance === 'major') return '重要更新';
  return '本週更新';
}
</script>

<style scoped>
.rn-page {
  max-width: 980px;
  margin: 0 auto;
}

.rn-featured {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 14px;
  align-items: center;
  margin-top: 0;
  padding: 16px;
  border-radius: var(--ds-radius-lg);
  border: 1px solid color-mix(in srgb, var(--ds-warning) 26%, transparent);
  background: var(--ds-warning-wash);
}

.rn-title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.rn-featured h3 {
  margin: 0 0 4px;
  color: var(--text);
  font-size: 17px;
}

.rn-featured p {
  margin: 0;
  color: var(--ds-warning);
  line-height: 1.55;
}

.rn-featured-label {
  grid-column: 1 / -1;
  color: var(--ds-warning);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: .04em;
}

.rn-changelog-link { color: var(--ds-primary-deep); font-size: 13px; font-weight: 700; }

.rn-effective {
  display: block;
  margin-top: 6px;
  color: var(--warning);
  font-size: 12px;
}

.rn-importance {
  display: inline-flex;
  align-items: center;
  font-size: 11px;
  font-weight: 800;
  border-radius: 999px;
  padding: 2px 8px;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-secondary);
}

.rn-importance[data-importance='major'] {
  background: var(--warning-bg);
  color: var(--warning);
}

.rn-importance[data-importance='action_required'] {
  background: var(--danger-bg);
  color: var(--danger);
}

.rn-item {
  border: 1px solid var(--border);
  border-radius: var(--ds-radius-lg);
  padding: 16px;
  margin-top: 14px;
  background: var(--card-bg);
  box-shadow: var(--ds-shadow-1);
}

.rn-item-head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.rn-item-head > div {
  display: grid;
  gap: 2px;
}

.rn-item-head strong {
  color: var(--text);
}

.rn-item-head small {
  color: var(--text-light);
  font-size: 12px;
}

.rn-version {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: #fff;
  background: var(--ds-cta);
  border-radius: 999px;
  padding: 4px 10px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.rn-version--large {
  font-size: 13px;
  padding: 6px 12px;
}

.rn-summary {
  margin: 10px 0 0;
  color: var(--text-light);
  line-height: 1.65;
}

.rn-compact-hint {
  margin: 14px 0 0;
  color: var(--text-light);
  font-size: 12px;
}

.rn-sections-details,
.rn-older-details {
  margin-top: 12px;
}

.rn-sections-details > summary,
.rn-older-details > summary {
  color: var(--ds-primary-deep);
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
}

.rn-sections {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 12px;
  margin-top: 14px;
}

.rn-section {
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 12px 14px;
  background: var(--ds-surface-0);
}

.rn-section h4 {
  margin: 0 0 8px;
  font-size: 13px;
  color: var(--text);
}

.rn-list {
  margin: 0;
  padding-left: 18px;
}

.rn-list li {
  margin: 6px 0;
  color: var(--text);
}

.rn-empty {
  color: var(--text-light);
}

@media (max-width: 720px) {
  .rn-page {
    width: 100%;
  }
  .rn-featured {
    grid-template-columns: 1fr;
  }
  .rn-featured-label {
    grid-column: auto;
  }
}
</style>
