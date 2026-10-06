<template>
  <div v-if="show" class="modal-overlay" @click.self="!submitting && $emit('close')">
    <div class="modal course-modal batch-renew-modal" role="dialog" aria-labelledby="batch-renew-title">
      <h3 id="batch-renew-title" class="modal-title">月結續報</h3>
      <p class="modal-desc">{{ studentName }}：勾選要續的科目，一次建立新一期。</p>

      <div class="form-group">
        <label for="batch-renew-month">續報月份</label>
        <input id="batch-renew-month" v-model="targetMonth" type="month" :disabled="submitting || finished" @change="loadPreviews" />
      </div>

      <ul class="batch-renew-list">
        <li v-for="row in rows" :key="row.course.id" :class="['batch-renew-row', `batch-renew-row--${row.state}`]">
          <label class="batch-renew-row__main">
            <input v-model="row.selected" type="checkbox" :disabled="!selectable(row) || submitting || finished" />
            <span class="batch-renew-row__subject">{{ subjectOf(row.course) }}</span>
            <span class="batch-renew-row__teacher">{{ row.course.teacher_name }}</span>
          </label>
          <div class="batch-renew-row__detail">
            <template v-if="row.state === 'covered'">已續到 {{ row.course.end_date }}，不用再續</template>
            <template v-else-if="row.state === 'loading'">正在預覽…</template>
            <template v-else-if="row.state === 'done'">✅ 已建立 {{ row.start }} ～ {{ row.end }}</template>
            <template v-else>
              <span v-if="row.start">{{ row.start }} ～ {{ row.end }}</span>
              <span v-if="row.amount != null"> · 預估 NT$ {{ row.amount.toLocaleString() }}</span>
            </template>
          </div>
          <p v-for="(m, i) in row.messages" :key="i" :class="['batch-renew-row__msg', { 'batch-renew-row__msg--error': row.state === 'blocked' || row.state === 'error' }]">{{ m }}</p>
        </li>
      </ul>
      <p v-if="!rows.length" class="hint">這位學生沒有進行中的月結課程。</p>
      <p class="hint">金額為預排估算；實際收費依確認已上堂次計算。要打折請改用單科「結算 / 續約下月」。</p>

      <div class="actions">
        <button class="ghost" :disabled="submitting" @click="$emit('close')">{{ finished ? '關閉' : '取消' }}</button>
        <button v-if="!finished" class="primary" :disabled="submitting || !selectedRows.length" @click="submit">
          {{ submitting ? '建立中…' : `續報 ${selectedRows.length} 科` }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { authedFetch, getAccessToken } from '../../lib/authedFetch';
import { getSubjectLabel } from '../../lib/constants';
import { getRenewalPreviewAmount } from '../../lib/coursePricing';
import { batchRenewalEnd, nextRenewalMonth, renewalErrorMessage } from '../../lib/monthlyRenewalPreview';

const props = defineProps({
  show: Boolean,
  studentName: { type: String, default: '' },
  courses: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'done']);

const targetMonth = ref('');
const rows = ref([]);
const submitting = ref(false);
const finished = ref(false);
let loadId = 0;

const todayMinus = (days) => {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const subjectOf = (c) => c.subject_name || getSubjectLabel(c.subject);
const selectable = (row) => row.state === 'ready';
const selectedRows = computed(() => rows.value.filter((r) => r.selected && selectable(r)));

const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };

async function requireToken() {
  const token = await getAccessToken();
  if (!token) throw new Error('請重新登入後再試');
  return token;
}

async function previewRow(row, token, id) {
  try {
    const res = await authedFetch(`/api/v1/student-classes/${row.course.id}/renewal-preview`, {
      method: 'POST', credentials: 'include', headers: JSON_HEADERS,
      body: JSON.stringify({ mode: 'renew_monthly', end_date: row.end }),
    }, token);
    const json = await res.json().catch(() => ({}));
    if (id !== loadId) return;
    const blocked = json.severity === 'blocked';
    row.messages = [...(json.warnings || []), ...(json.blockers || [])].map((w) => w.message).filter(Boolean);
    if (!res.ok && !blocked) {
      Object.assign(row, { state: 'error', messages: [renewalErrorMessage(json, '無法預覽，請重試。')] });
      return;
    }
    row.start = json.proposed_course?.start_date || '';
    row.amount = getRenewalPreviewAmount(json);
    row.state = blocked || !row.start ? 'blocked' : 'ready';
    // A period that began weeks ago is usually stale data, not this month's renewal: ask, don't default.
    const late = row.state === 'ready' && row.start < todayMinus(7);
    if (late) row.messages.unshift(`新一期從 ${row.start} 開始（已過），確認真的要補這一期再勾選。`);
    row.selected = row.state === 'ready' && !late;
  } catch {
    if (id === loadId) Object.assign(row, { state: 'error', messages: ['無法預覽，請檢查連線後重試。'] });
  }
}

async function loadPreviews() {
  const id = ++loadId;
  finished.value = false;
  rows.value = props.courses.map((course) => {
    const end = batchRenewalEnd(course.end_date, course.settlement_day, targetMonth.value);
    return { course, end, start: '', amount: null, messages: [], selected: false, state: end ? 'loading' : 'covered' };
  });
  let token;
  try { token = await requireToken(); } catch (e) {
    rows.value.forEach((r) => { if (r.state === 'loading') Object.assign(r, { state: 'error', messages: [e.message] }); });
    return;
  }
  await Promise.all(rows.value.filter((r) => r.state === 'loading').map((r) => previewRow(r, token, id)));
}

async function submit() {
  if (submitting.value) return;
  submitting.value = true;
  try {
    const token = await requireToken();
    // Sequential: each renewal settles its source course; keep failures per row.
    for (const row of selectedRows.value) {
      const res = await authedFetch(`/api/v1/student-classes/${row.course.id}/renew-monthly`, {
        method: 'POST', credentials: 'include', headers: JSON_HEADERS, body: JSON.stringify({ end_date: row.end }),
      }, token);
      const json = await res.json().catch(() => ({}));
      if (res.ok) Object.assign(row, { state: 'done', selected: false, messages: [] });
      else {
        Object.assign(row, { state: 'error', selected: false, messages: [renewalErrorMessage(json, '續報失敗')] });
      }
    }
  } catch (e) {
    alert(e?.message || '續報失敗，請稍後再試');
  } finally {
    submitting.value = false;
    finished.value = true;
    emit('done');
  }
}

watch(() => props.show, (open) => {
  if (!open) return;
  targetMonth.value = nextRenewalMonth(props.courses.map((c) => c.end_date));
  loadPreviews();
});
</script>

<style scoped>
.batch-renew-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.batch-renew-list { list-style: none; margin: 12px 0; padding: 0; display: grid; gap: 8px; }
.batch-renew-row { border: 1px solid var(--ds-hairline); border-radius: 10px; padding: 10px 12px; }
.batch-renew-row--blocked, .batch-renew-row--error { border-color: var(--ds-danger); background: var(--ds-danger-wash); }
.batch-renew-row--done { border-color: var(--ds-success); background: var(--ds-success-wash); }
.batch-renew-row--covered { opacity: 0.65; }
.batch-renew-row__main { display: flex; flex-direction: row; align-items: center; justify-content: flex-start; gap: 10px; font-weight: 600; cursor: pointer; margin: 0; }
.batch-renew-row__main input { width: auto; flex: 0 0 auto; margin: 0; }
.batch-renew-row__main span { white-space: nowrap; }
.batch-renew-row__teacher { color: var(--ds-ink-mute); font-weight: 400; }
.batch-renew-row__detail { margin: 4px 0 0 26px; font-size: 14px; }
.batch-renew-row__msg { margin: 4px 0 0 26px; font-size: 13px; color: var(--ds-warning-ink); }
.batch-renew-row__msg--error { color: var(--ds-danger); }
.actions { display: flex; justify-content: flex-end; gap: 8px; position: sticky; bottom: 0; background: inherit; padding-top: 8px; }
</style>
