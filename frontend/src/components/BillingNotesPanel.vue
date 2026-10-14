<template>
  <div class="billing-notes">
    <button type="button" class="billing-notes__toggle" :aria-expanded="open" @click="toggle">
      {{ open ? '收合備註紀錄' : '備註紀錄' }}
    </button>
    <div v-if="open" class="billing-notes__body">
      <p v-if="loading" class="billing-notes__muted">載入中…</p>
      <p v-if="!loading && error" class="billing-notes__error" role="alert">{{ error }}</p>
      <button v-if="!loading && !loaded" type="button" class="billing-notes__reload" @click="load">重新載入紀錄</button>
      <ol v-if="!loading && loaded" class="billing-notes__list">
        <li v-if="!notes.length" class="billing-notes__muted">還沒有備註。</li>
        <li v-for="(n, i) in notes" :key="n.id ?? `legacy-${i}`" :class="{ 'is-legacy': n.legacy }">
          <small>{{ n.legacy ? '原備註（舊資料，不能修改）' : `${n.author_name || '未知'}・${formatTime(n.created_at)}` }}</small>
          <p>{{ n.body }}</p>
        </li>
      </ol>
      <p v-if="!loading && loaded && readOnly" class="billing-notes__muted billing-notes__readonly">
        {{ readOnlyLabel || '（唯讀）' }}這筆帳單已不能再新增備註，只能查看。
      </p>
      <form v-if="!readOnly" class="billing-notes__form" @submit.prevent="add">
        <label :for="inputId">新增備註</label>
        <textarea :id="inputId" v-model="draft" rows="2" :maxlength="MAX_BODY" />
        <button type="submit" :disabled="saving || loading || !loaded || !draft.trim()">{{ saving ? '儲存中…' : '新增' }}</button>
      </form>
      <p v-if="!readOnly" class="billing-notes__muted">備註送出後不能修改或刪除；寫錯了請再新增一則更正。</p>
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
// True only after the latest history load succeeded. The add form stays disabled until then,
// so a failed GET or a POST with an unknown outcome cannot lead to a blind (duplicate) submit.
const loaded = ref(false);
// The server marks some histories read-only (an invoice voided because its course was deleted, #4050)
// and refuses a POST there, so the add form is hidden instead of inviting an append that must fail.
const readOnly = ref(false);
const readOnlyLabel = ref('');
// Only the newest load() may apply its result; older responses are dropped (#3990).
let loadSeq = 0;
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
  const seq = ++loadSeq;
  loading.value = true;
  loaded.value = false;
  error.value = '';
  try {
    const qs = new URLSearchParams({ subject_type: props.subjectType, subject_id: String(props.subjectId) });
    const resp = await fetch(`/api/v1/accounting/notes?${qs}`, { headers: headers() });
    const json = await resp.json().catch(() => null);
    if (seq !== loadSeq) return;
    if (!resp.ok) throw new Error(json?.message || `載入失敗（${resp.status}）`);
    // An unreadable body must not count as a confirmed history: it could hide an uncertain save.
    if (!json || !Array.isArray(json.notes)) throw new Error('紀錄讀取失敗，請重新載入');
    notes.value = json.notes;
    readOnly.value = json.read_only === true;
    readOnlyLabel.value = readOnly.value && typeof json.read_only_label === 'string' ? json.read_only_label : '';
    loaded.value = true;
  } catch (e) {
    if (seq === loadSeq) error.value = humanizeApiErrorMessage(e.message || '載入備註失敗');
  } finally {
    if (seq === loadSeq) loading.value = false;
  }
}

function toggle() {
  open.value = !open.value;
  if (open.value) load();
}

async function add() {
  const body = draft.value.trim();
  // While a load is in flight its older result would overwrite the saved note, so wait for it.
  if (!body || saving.value || loading.value || !loaded.value || readOnly.value) return;
  saving.value = true;
  error.value = '';
  // A note may be saved even when its response never arrives (network error, 5xx, unreadable body).
  // Then we cannot tell, so the history must be reloaded before another submit (#3990).
  let uncertain = true;
  try {
    const resp = await fetch('/api/v1/accounting/notes', {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify({ subject_type: props.subjectType, subject_id: Number(props.subjectId), body }),
    });
    const json = await resp.json().catch(() => null);
    if (!resp.ok) {
      if (resp.status < 500) uncertain = false;
      throw new Error(json?.message || `儲存失敗（${resp.status}）`);
    }
    if (!json || json.id == null) throw new Error('');
    uncertain = false;
    draft.value = '';
    if (loading.value) {
      // The panel was reopened during the save: that GET may predate this note, so fetch again.
      load();
    } else if (!notes.value.some((n) => n.id === json.id)) {
      // A load that finished during the save may already include this note.
      notes.value = [...notes.value, json];
    }
  } catch (e) {
    if (uncertain) {
      // Any load started before this point may predate the save; drop it and require a fresh one.
      loadSeq++;
      loading.value = false;
      loaded.value = false;
      error.value = '不確定這則備註有沒有存到。請先重新載入紀錄，確認沒有再送出。';
    } else {
      error.value = humanizeApiErrorMessage(e.message || '儲存備註失敗');
    }
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
.billing-notes__reload { justify-self: start; min-height: 32px; }
.billing-notes__form { display: grid; gap: 4px; }
.billing-notes__form textarea { width: 100%; font: inherit; }
.billing-notes__form button { justify-self: start; min-height: 32px; }
</style>
