<template>
  <div class="rn-page card">
    <div class="rn-head">
      <h2>版本更新</h2>
      <p>超級管理員、主任與老師可在這裡查看近期變更摘要；內容由 <code>docs/CHANGELOG.md</code> 自動整理（略過純維運／文件類條目）。</p>
      <p class="rn-changelog-link">
        <a :href="changelogUrl" target="_blank" rel="noopener noreferrer">在 GitHub 開啟完整 CHANGELOG</a>
      </p>
    </div>

    <div v-if="notes.length === 0" class="rn-empty">目前尚無可顯示的更新內容。</div>

    <section v-for="note in notes" :key="`${note.version}-${note.title}`" class="rn-item">
      <div class="rn-item-head">
        <span class="rn-version">{{ note.version }}</span>
        <strong>{{ note.title }}</strong>
      </div>
      <ul class="rn-list">
        <li v-for="row in note.items" :key="row">{{ row }}</li>
      </ul>
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
</script>

<style scoped>
.rn-page {
  max-width: 880px;
  margin: 0 auto;
  padding: 20px;
}

.rn-head h2 {
  margin: 0;
}

.rn-head p {
  margin: 6px 0 14px;
  color: var(--text-light);
}
.rn-head code {
  font-size: 0.9em;
}
.rn-changelog-link {
  margin: -4px 0 10px;
  font-size: 13px;
}
.rn-changelog-link a {
  color: var(--accent, #2563eb);
  font-weight: 600;
}

.rn-item {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 14px;
  margin-top: 12px;
  background: var(--card-bg);
}

.rn-item-head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.rn-version {
  font-size: 12px;
  color: #fff;
  background: var(--accent);
  border-radius: 999px;
  padding: 2px 10px;
}

.rn-list {
  margin: 10px 0 0;
  padding-left: 18px;
}

.rn-list li {
  margin: 6px 0;
  color: var(--text);
}

.rn-empty {
  color: var(--text-light);
}
</style>

