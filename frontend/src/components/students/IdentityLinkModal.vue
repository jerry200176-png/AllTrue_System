<template>
<div v-if="show" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="identity-modal-title" @click.self="emit('close')">
  <div class="modal" style="width: 680px; max-width: calc(100vw - 32px);">
    <div class="modal-header-row">
      <h3 id="identity-modal-title">跨分校學生身份關聯</h3>
      <button type="button" class="ghost small" @click="emit('close')">關閉</button>
    </div>
    <p class="hint">只把主任已確認為同一位學生的兩筆分校資料關聯；姓名或手機相同不會自動合併。</p>
    <div class="identity-search-row">
      <input v-model="query" placeholder="搜尋學生姓名" @keyup.enter="emit('search')" />
      <button type="button" class="small ghost" @click="emit('search')" :disabled="loading">搜尋</button>
    </div>
    <div v-if="error" class="error-text">{{ error }}</div>
    <div v-if="loading" class="empty-text">載入中…</div>
    <div v-else class="identity-candidate-list">
      <button
        v-for="candidate in students"
        :key="candidate.student_id"
        type="button"
        class="identity-candidate"
        :class="{ selected: selectedIds.includes(candidate.student_id) }"
        @click="emit('toggle', candidate.student_id)"
      >
        <span><strong>{{ candidate.name }}</strong> · {{ candidate.campus_name || `分校 ${candidate.campus_id}` }}</span>
        <small>{{ candidate.identity_group_id ? `群組 #${candidate.identity_group_id}` : '尚未關聯' }}</small>
      </button>
      <div v-if="!students.length" class="empty-text">請搜尋可管理的學生資料</div>
    </div>
    <div class="identity-selected-summary">已選 {{ selectedIds.length }} / 2 筆</div>
    <div class="actions">
      <button type="button" class="ghost" @click="emit('close')">取消</button>
      <button type="button" class="primary" :disabled="selectedIds.length !== 2 || saving" @click="emit('link')">
        {{ saving ? '建立中…' : '建立身份關聯' }}
      </button>
    </div>

    <div class="identity-group-list" v-if="groups.length">
      <h4>已建立的身份群組</h4>
      <div v-for="group in groups" :key="group.id" class="identity-group-card">
        <div class="identity-group-head">
          <strong>{{ group.display_name || `身份群組 #${group.id}` }}</strong>
          <select :value="group.mode" @change="emit('update-mode', group, $event.target.value)">
            <option value="off">關閉</option>
            <option value="readonly">唯讀試點</option>
            <option value="actions">開放操作</option>
          </select>
        </div>
        <div class="identity-group-members">
          <span v-for="member in group.members" :key="member.student_id" class="pp-campus-label">
            {{ member.name }} · {{ member.campus_name || `分校 ${member.campus_id}` }}
          </span>
        </div>
        <button type="button" class="small ghost" @click="emit('load-audit', group.id)">查看稽核紀錄</button>
      </div>
    </div>
    <pre v-if="audit.length" class="identity-audit">{{ JSON.stringify(audit, null, 2) }}</pre>
  </div>
</div>
</template>

<script setup>
defineProps({
  show: { type: Boolean, default: false },
  students: { type: Array, default: () => [] },
  groups: { type: Array, default: () => [] },
  selectedIds: { type: Array, default: () => [] },
  audit: { type: Array, default: () => [] },
  loading: Boolean,
  saving: Boolean,
  error: { type: String, default: '' },
});
const query = defineModel('query', { type: String, default: '' });
const emit = defineEmits(['close', 'search', 'toggle', 'link', 'update-mode', 'load-audit']);
</script>

<style scoped>
.modal-header-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.identity-search-row { display: flex; gap: 8px; margin: 14px 0 8px; }
.identity-search-row input { flex: 1; min-width: 0; }
.identity-candidate-list { max-height: 190px; overflow: auto; border: 1px solid var(--ds-border); border-radius: var(--ds-radius-lg); }
.identity-candidate { width: 100%; display: flex; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 0; border-bottom: 1px solid var(--ds-border); background: var(--ds-surface); color: var(--ds-ink); text-align: left; cursor: pointer; }
.identity-candidate:last-child { border-bottom: 0; }
.identity-candidate.selected { background: var(--ds-success-wash); }
.identity-candidate small { color: var(--ds-ink-mute); white-space: nowrap; }
.identity-selected-summary { margin-top: 8px; color: var(--ds-ink-mute); font-size: var(--ds-font-size-sm); }
.identity-group-list { margin-top: 18px; border-top: 1px solid var(--ds-border); padding-top: 14px; }
.identity-group-card { border: 1px solid var(--ds-border); border-radius: var(--ds-radius-lg); padding: 10px; margin-top: 8px; }
.identity-group-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.identity-group-head select { min-height: 32px; }
.identity-group-members { display: flex; gap: 6px; flex-wrap: wrap; margin: 8px 0; }
.identity-audit { max-height: 180px; overflow: auto; margin-top: 10px; padding: 10px; background: var(--ds-surface-2); border-radius: var(--ds-radius-md); font-size: var(--ds-font-size-xs); }
</style>
