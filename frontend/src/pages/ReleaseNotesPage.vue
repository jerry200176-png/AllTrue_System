<template>
  <div class="rn-page card">
    <div class="rn-hero">
      <div>
        <span class="rn-kicker">AllTrue 更新公告</span>
        <h2>版本更新</h2>
        <p>這裡用白話整理每一版多了什麼、修了什麼。技術細節仍保留在完整 CHANGELOG。</p>
      </div>
      <div v-if="latestNote" class="rn-latest">
        <span>最新版本</span>
        <strong>{{ latestNote.version }}</strong>
        <small>{{ latestNote.date }}</small>
      </div>
    </div>

    <div v-if="latestNote" class="rn-featured">
      <span class="rn-version rn-version--large">{{ latestNote.version }}</span>
      <div>
        <h3>{{ latestNote.title }}</h3>
        <p>{{ latestNote.summary }}</p>
      </div>
      <a :href="changelogUrl" target="_blank" rel="noopener noreferrer">完整 CHANGELOG</a>
    </div>

    <div v-if="notes.length === 0" class="rn-empty">目前尚無可顯示的更新內容。</div>

    <section v-for="note in notes" :key="`${note.version}-${note.title}`" class="rn-item">
      <div class="rn-item-head">
        <span class="rn-version">{{ note.version }}</span>
        <div>
          <strong>{{ note.title }}</strong>
          <small v-if="note.date">{{ note.date }}</small>
        </div>
      </div>
      <p v-if="note.summary" class="rn-summary">{{ note.summary }}</p>
      <div class="rn-sections">
        <section v-for="section in normalizedSections(note)" :key="`${note.version}-${section.title}`" class="rn-section">
          <h4>{{ section.title }}</h4>
          <ul class="rn-list">
            <li v-for="row in section.items" :key="row">{{ row }}</li>
          </ul>
        </section>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { notesForRole } from '../lib/releaseNotes';

const props = defineProps({
  userRole: { type: String, default: '' },
});

const changelogUrl =
  'https://github.com/jerry200176-png/AllTrue_System/blob/main/docs/CHANGELOG.md';

const notes = computed(() => notesForRole(props.userRole));
const latestNote = computed(() => notes.value[0] || null);

function normalizedSections(note) {
  if (Array.isArray(note.sections) && note.sections.length > 0) {
    return note.sections;
  }
  return [{ title: '更新內容', items: note.items || [] }];
}
</script>

<style scoped>
.rn-page {
  max-width: 980px;
  margin: 0 auto;
  padding: 22px;
}

.rn-hero {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 190px;
  gap: 18px;
  align-items: stretch;
  padding: 22px;
  border-radius: 18px;
  background:
    radial-gradient(circle at top right, rgba(245, 158, 11, 0.18), transparent 34%),
    linear-gradient(135deg, #ffffff, #f8fafc);
  border: 1px solid var(--border);
}

.rn-kicker {
  display: inline-block;
  margin-bottom: 8px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.12em;
  color: var(--accent);
  text-transform: uppercase;
}

.rn-hero h2 {
  margin: 0;
  font-size: 30px;
  color: var(--text);
}

.rn-hero p {
  max-width: 62ch;
  margin: 8px 0 0;
  color: var(--text-light);
  line-height: 1.7;
}

.rn-latest {
  display: grid;
  align-content: center;
  justify-items: start;
  gap: 4px;
  border-radius: 16px;
  padding: 16px;
  color: #fff;
  background: linear-gradient(135deg, #111827, #334155);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.12);
}

.rn-latest span,
.rn-latest small {
  color: rgba(255,255,255,0.72);
  font-size: 12px;
  font-weight: 700;
}

.rn-latest strong {
  font-size: 28px;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.rn-featured {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 14px;
  align-items: center;
  margin-top: 14px;
  padding: 16px;
  border-radius: 16px;
  border: 1px solid rgba(245, 158, 11, 0.26);
  background: #fffbeb;
}

.rn-featured h3 {
  margin: 0 0 4px;
  color: var(--text);
  font-size: 17px;
}

.rn-featured p {
  margin: 0;
  color: #92400e;
  line-height: 1.55;
}

.rn-featured a {
  color: #b45309;
  font-weight: 800;
  white-space: nowrap;
}

.rn-item {
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 16px;
  margin-top: 14px;
  background: var(--card-bg);
  box-shadow: 0 14px 34px rgba(15, 23, 42, 0.05);
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
  background: var(--accent);
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
  background: rgba(248, 250, 252, 0.72);
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
    padding: 16px;
  }
  .rn-hero,
  .rn-featured {
    grid-template-columns: 1fr;
  }
  .rn-latest {
    justify-items: start;
  }
  .rn-featured a {
    justify-self: start;
  }
}
</style>

