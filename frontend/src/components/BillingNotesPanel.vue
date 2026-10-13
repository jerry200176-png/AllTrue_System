<template>
  <div class="billing-notes">
    <button type="button" class="billing-notes__toggle" :aria-expanded="open" @click="toggle">
      {{ open ? '收合備註紀錄' : '備註紀錄' }}
    </button>
    <div v-if="open" class="billing-notes__body">
      <p v-if="loading" class="billing-notes__muted">載入中…</p>
      <p v-else-if="error" class="billing-notes__error" role="alert">{{ error }}</p>
      <ol v-else class="billing-notes__list">
        <li v-if="!notes.length" class="billing-notes__muted">還沒有備註。</li>
        <li v-for="(n, i) in notes" :key="n.id ?? `legacy-${i}`" :class="{ 'is-legacy': n.legacy }">
          <small>{{ n.legacy ? '原備註（舊資料，不能修改）' : `${n.author_name || '未知'}・${formatTime(n.created_at)}` }}</small>
          <p>{{ n.body }}</p>
        </li>
      </ol>
      <form class="billing-notes__form" @submit.prevent="add">
        <label :for="inputId">新增備註</label>
        <textarea :id="inputId" v-model="draft" rows="2" :maxlength="MAX_BODY" />
        <button type="submit" :disabled="saving || loading || !draft.trim()">{{ saving ? '儲存中…' : '新增' }}</button>
      </form>
      <p class="billing-notes__muted">備註送出後不能修改或刪除；寫錯了請再新增一則更正。</p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { getToken } from '../lib/bugReportsApi.js';
import { humanizeApiErrorMessage } from '../lib/humanizeApiErrorMessage.js';

// Mirrors BillingNote::MAX_BODY on the server, which also enforces it.
const MAX_BODY = 1000;
const props = defineProps({
  subjectType: { type: String, required: true },
  subjectId: { type: [Number, String], required: true },
});

const open = ref(false);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notes = ref([]);
const draft = ref('');
const inputId = `billing-note-${props.subjectType}-${props.subjectId}`;

function headers() {
  return { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${getToken()}` };
}

function formatTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleString('zh-TW', { timeZone: 'Asia/Taipei', hour12: false });
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const qs = new URLSearchParams({ subject_type: props.subjectType, subject_id: String(props.subjectId) });
    const resp = await fetch(`/api/v1/accounting/notes?${qs}`, { headers: headers() });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(json.message || `載入失敗（${resp.status}）`);
    notes.value = json.notes || [];
  } catch (e) {
    error.value = humanizeApiErrorMessage(e.message || '載入備註失敗');
  } finally {
    loading.value = false;
  }
}

function toggle() {
  open.value = !open.value;
  if (open.value) load();
}

async function add() {
  const body = draft.value.trim();
  // While a load is in flight its older result would overwrite the saved note, so wait for it.
  if (!body || saving.value || loading.value) return;
  saving.value = true;
  error.value = '';
  try {
    const resp = await fetch('/api/v1/accounting/notes', {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify({ subject_type: props.subjectType, subject_id: Number(props.subjectId), body }),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(json.message || `儲存失敗（${resp.status}）`);
    notes.value = [...notes.value, json];
    draft.value = '';
  } catch (e) {
    error.value = humanizeApiErrorMessage(e.message || '儲存備註失敗');
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.billing-notes { margin-top: 8px; }
.billing-notes__toggle { border: 0; background: transparent; padding: 0; color: var(--ds-primary-text); font-size: var(--ds-font-size-md); font-weight: 600; cursor: pointer; min-height: 32px; }
.billing-notes__body { margin-top: 6px; display: grid; gap: 8px; }
.billing-notes__list { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.billing-notes__list li { padding: 6px 8px; border-left: 3px solid var(--ds-border); }
.billing-notes__list li.is-legacy { border-left-style: dashed; }
.billing-notes__list p { margin: 2px 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.billing-notes__list small, .billing-notes__muted { color: var(--ds-ink-mute); font-size: var(--ds-font-size-sm); }
.billing-notes__error { color: var(--ds-danger); font-size: var(--ds-font-size-sm); }
.billing-notes__form { display: grid; gap: 4px; }
.billing-notes__form textarea { width: 100%; font: inherit; }
.billing-notes__form button { justify-self: start; min-height: 32px; }
</style>
