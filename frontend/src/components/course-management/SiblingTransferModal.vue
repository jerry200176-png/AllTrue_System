<template>
  <div v-if="show" class="modal-overlay" @click.self="!busy && $emit('close')">
    <div ref="panelRef" class="modal course-modal" role="dialog" aria-modal="true" aria-labelledby="sibling-transfer-title" tabindex="-1" @keydown="onKeydown">
      <h3 id="sibling-transfer-title" class="modal-title">把剩餘堂數轉給手足</h3>
      <p class="modal-desc">轉出的堂數會在手足名下開一份新合約，金額照這份合約實際繳的錢等比例搬過去。原收款與收據不變，帳上以轉出／轉入紀錄連動，沒有退費。</p>

      <label class="form-label" for="sib-target">轉給誰（同分校的學生）</label>
      <select id="sib-target" v-model="form.target_student_id" class="form-input" :disabled="busy || loadingStudents">
        <option value="">{{ loadingStudents ? '載入學生中…' : '請選擇' }}</option>
        <option v-for="s in candidates" :key="s.id" :value="s.id">{{ s.name }}</option>
      </select>

      <label class="form-label" for="sib-sessions">轉幾堂（留空＝全部剩餘堂數）</label>
      <input id="sib-sessions" v-model.number="form.sessions" type="number" min="1" step="1" class="form-input" :disabled="busy" :aria-invalid="!sessionsValid" aria-describedby="sib-sessions-err" />
      <p v-if="!sessionsValid" id="sib-sessions-err" class="error" role="alert">堂數要是 1 以上的整數；要全部轉就留空。</p>

      <label class="form-label" for="sib-reason">原因（必填）</label>
      <textarea id="sib-reason" v-model.trim="form.reason" class="form-input" rows="2" maxlength="100" :disabled="busy" />

      <div v-if="plan" class="preview" role="status" data-testid="sibling-preview">
        <div>剩餘 {{ plan.remaining_sessions }} 堂，這次轉 <strong>{{ plan.moved_sessions }}</strong> 堂</div>
        <div>這份合約已繳 ${{ plan.paid_amount }}，轉給手足 <strong>${{ plan.amount }}</strong>，這份合約保留 ${{ plan.source_keeps_amount }}</div>
        <div class="preview-note">手足的新合約沒有固定上課時段，轉完後請在課程管理幫他排課。</div>
      </div>
      <label v-if="plan && !plan.family_link" class="ack" data-testid="sibling-ack">
        <input v-model="form.acknowledge_no_family_link" type="checkbox" :disabled="busy" />
        <span>系統找不到這兩位學生共同的家長紀錄。我確認他們是手足。</span>
      </label>
      <p v-if="error" class="error" role="alert">{{ error }}</p>

      <div class="actions">
        <button class="ghost" :disabled="busy" @click="$emit('close')">取消</button>
        <button class="ghost" :disabled="busy || !canSubmit" @click="run(true)">試算</button>
        <button class="primary" :disabled="busy || !canSubmit || !plan || (!plan.family_link && !form.acknowledge_no_family_link)" @click="run(false)">確認轉給手足</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { authedFetch } from '../../lib/authedFetch';
import { trapTab } from '../../lib/focusTrap.js';

const props = defineProps({
  show: Boolean,
  courseId: { type: [Number, String], default: null },
  ownStudentId: { type: [Number, String], default: null },
  branchId: { type: [Number, String], default: null },
});
const emit = defineEmits(['close', 'done']);
const form = reactive({ target_student_id: '', sessions: null, reason: '', acknowledge_no_family_link: false });
const plan = ref(null);
const error = ref('');
const busy = ref(false);
const students = ref([]);
const loadingStudents = ref(false);
const panelRef = ref(null);
let opener = null;
// One key per opening of the dialog: a double click or a retry after a lost response replays the same transfer.
let idempotencyKey = '';
const newKey = () => (globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`);

const candidates = computed(() => students.value.filter((s) => Number(s.id) !== Number(props.ownStudentId)));
// Blank means "all remaining"; anything typed must be a whole number of at least 1 (0 must never fall back to "all").
const hasSessions = computed(() => form.sessions !== null && form.sessions !== '' && form.sessions !== undefined);
const sessionsValid = computed(() => !hasSessions.value || (Number.isInteger(form.sessions) && form.sessions >= 1));
const canSubmit = computed(() => !!form.target_student_id && !!form.reason && sessionsValid.value);

// Every student of the campus (per_page=all), not just the first page the course list loaded.
async function loadStudents() {
  students.value = [];
  if (!props.branchId) return;
  loadingStudents.value = true;
  try {
    const res = await authedFetch(`/api/v1/students?${new URLSearchParams({ branch_id: String(props.branchId), per_page: 'all' })}`, {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    });
    const json = await res.json().catch(() => null);
    const list = Array.isArray(json) ? json : (json?.data ?? []);
    if (!res.ok || !Array.isArray(list)) throw new Error('students');
    students.value = list.map((s) => ({ id: Number(s?.id ?? 0), name: String(s?.name ?? '').trim() })).filter((s) => s.id > 0 && s.name);
  } catch {
    error.value = '載入學生名單失敗，請關掉重開再試。';
  } finally {
    loadingStudents.value = false;
  }
}

watch(() => props.show, async (v) => {
  if (!v) {
    // Give focus back to whatever opened the dialog (the ⋯ menu or the drawer), unless it is gone.
    if (opener?.isConnected) opener.focus();
    opener = null;
    return;
  }
  opener = document.activeElement;
  Object.assign(form, { target_student_id: '', sessions: null, reason: '', acknowledge_no_family_link: false });
  plan.value = null;
  error.value = '';
  idempotencyKey = newKey();
  await nextTick();
  panelRef.value?.querySelector('#sib-target')?.focus();
  loadStudents();
});
const onKeydown = (e) => {
  if (e.key === 'Tab') trapTab(e, panelRef.value);
  else if (e.key === 'Escape' && !busy.value) emit('close');
};
// Any input change invalidates a previous preview.
watch(() => [form.target_student_id, form.sessions], () => { plan.value = null; form.acknowledge_no_family_link = false; });

const body = (preview) => ({
  target_student_id: Number(form.target_student_id),
  reason: form.reason,
  idempotency_key: idempotencyKey,
  preview,
  ...(hasSessions.value ? { sessions: Number(form.sessions) } : {}),
  ...(form.acknowledge_no_family_link ? { acknowledge_no_family_link: true } : {}),
});

async function run(previewOnly) {
  busy.value = true;
  error.value = '';
  try {
    const res = await authedFetch(`/api/v1/student-classes/${props.courseId}/sibling-transfer`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body(previewOnly)),
    });
    const payload = await res.json().catch(() => ({}));
    if (!res.ok) {
      error.value = payload?.message || '轉給手足失敗，請檢查欄位。';
      return;
    }
    if (previewOnly) plan.value = payload.plan;
    else emit('done', payload);
  } catch {
    error.value = '連線失敗，請稍後再試。';
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.course-modal { width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: var(--ds-font-size-lg); font-weight: var(--ds-font-weight-bold); margin: 0 0 var(--ds-space-1); }
.modal-desc { color: var(--ds-ink-secondary); font-size: var(--ds-font-size-sm); margin: 0 0 var(--ds-space-3); }
.form-label { display: block; font-size: var(--ds-font-size-sm); font-weight: var(--ds-font-weight-bold); margin: var(--ds-space-3) 0 var(--ds-space-2); }
.form-input { width: 100%; padding: var(--ds-space-2) var(--ds-space-3); border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); font-size: var(--ds-font-size-base); }
.preview { margin-top: var(--ds-space-3); padding: var(--ds-space-3); border-radius: var(--ds-radius-md); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); font-size: var(--ds-font-size-sm); line-height: 1.6; }
.preview-note { color: var(--ds-ink-secondary); }
.ack { display: flex; gap: var(--ds-space-2); align-items: flex-start; margin-top: var(--ds-space-3); padding: var(--ds-space-3); border-radius: var(--ds-radius-md); background: var(--ds-warning-wash); color: var(--ds-warning-text); font-size: var(--ds-font-size-sm); }
.error { margin: var(--ds-space-3) 0; padding: var(--ds-space-2) var(--ds-space-3); border-radius: var(--ds-radius-md); font-size: var(--ds-font-size-sm); background: var(--ds-danger-wash); color: var(--ds-danger); }
.actions { display: flex; justify-content: flex-end; gap: var(--ds-space-2); margin-top: var(--ds-space-4); }
</style>
