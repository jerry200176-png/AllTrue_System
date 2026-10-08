<template>
  <article class="contract" :data-testid="`contract-card-${course.id}`">
    <header class="contract__head">
      <h5>{{ title }}</h5>
      <span class="contract__mode">{{ modeLine }}</span>
    </header>

    <div class="contract__money" data-testid="contract-money">
      <span v-if="noObligation" class="contract__muted">輔導課不用繳費</span>
      <span v-else-if="outstanding > 0">未繳 <strong class="due">{{ money(outstanding) }}</strong></span>
      <span v-else-if="!course.paid" class="contract__muted">還沒開帳單，金額待確認</span>
      <span v-else>已收清</span>
      <template v-if="pendingReport">
        <span class="contract__chip pay-partial">家長說繳了 {{ money(pendingReport.amount) }}，等你確認</span>
        <button type="button" class="contract__btn" :disabled="busy" data-testid="contract-confirm" @click="decide('confirm')">確認入帳</button>
        <button type="button" class="contract__btn contract__btn--ghost" :disabled="busy" data-testid="contract-reject" @click="openReject">退回</button>
      </template>
      <button v-else-if="!noObligation && (outstanding > 0 || !course.paid)" type="button" class="contract__btn" data-testid="contract-record" @click="$emit('record', course)">登記收款</button>
      <span v-if="actionError" class="contract__error">{{ actionError }}</span>
    </div>

    <div class="contract__memo">
      <template v-if="!editing">
        <p v-if="memo" class="contract__memo-text" data-testid="contract-memo">{{ memo }}</p>
        <p v-else class="contract__muted">沒有合約備註</p>
        <button class="contract__link" type="button" data-testid="contract-memo-edit" @click="startEdit">✎ 改備註</button>
      </template>
      <template v-else>
        <textarea v-model="draft" rows="3" aria-label="合約備註" data-testid="contract-memo-input" />
        <div class="contract__memo-actions">
          <button type="button" :disabled="saving" data-testid="contract-memo-save" @click="saveMemo">{{ saving ? '儲存中…' : '儲存' }}</button>
          <button type="button" :disabled="saving" @click="editing = false">取消</button>
        </div>
      </template>
      <p v-if="memoError" class="contract__error">{{ memoError }}</p>
    </div>

    <p v-if="state === 'loading'" class="contract__muted">上課日載入中…</p>
    <p v-else-if="state === 'error'" class="contract__muted">
      上課日暫時無法載入，金額不受影響。
      <button class="contract__link" type="button" @click="load">重試</button>
    </p>
    <template v-else>
      <p class="contract__summary" data-testid="contract-summary">
        共 {{ sessions.length }} 堂・已上 {{ heldCount }}・還沒上 {{ upcomingCount }}
      </p>
      <section v-for="m in months" :key="m.key" class="contract__month">
        <h6>{{ m.label }}</h6>
        <ol>
          <li v-for="s in m.sessions" :key="s.class_session_id" data-testid="contract-session">
            <span class="contract__date">{{ formatSessionDate(s.date) }}</span>
            <span class="contract__time">{{ s.start_time || '' }}</span>
            <span :class="['contract__chip', `tone-${statusTone(s.status)}`]">{{ STATUS_ZH[s.status] || '' }}</span>
            <span :class="['contract__chip', `pay-${s.payment}`]">{{ PAYMENT_LABELS[s.payment] }}</span>
          </li>
        </ol>
      </section>
      <p v-if="unscheduled > 0" class="contract__muted" data-testid="contract-unscheduled">還有 {{ unscheduled }} 堂還沒排日期</p>
    </template>
    <AtDialog :open="rejectOpen" title="退回這筆收款" size="sm" title-id="contract-reject-title" @close="rejectOpen = false">
      <label class="contract__reject-label" :for="`contract-reject-reason-${course.id}`">退回原因</label>
      <AtTextarea :id="`contract-reject-reason-${course.id}`" v-model="rejectReason" :rows="3" data-testid="contract-reject-reason" />
      <template #actions>
        <AtButton variant="secondary" size="sm" shape="rect" data-testid="contract-reject-cancel" @click="rejectOpen = false">取消</AtButton>
        <AtButton size="sm" shape="rect" :disabled="!rejectReason.trim()" data-testid="contract-reject-submit" @click="submitReject">退回</AtButton>
      </template>
    </AtDialog>
  </article>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { authedFetch } from '../../lib/authedFetch.js';
import { humanizeApiErrorMessage } from '../../lib/humanizeApiErrorMessage.js';
import AtButton from '../design-system/AtButton.vue';
import AtDialog from '../design-system/AtDialog.vue';
import AtTextarea from '../design-system/AtTextarea.vue';
import { STATUS_ZH, statusTone, formatSessionDate } from '../../lib/billingDocumentView.js';

const props = defineProps({
  course: { type: Object, required: true },
  outstanding: { type: Number, default: 0 },
  pendingReport: { type: Object, default: null },
});
const emit = defineEmits(['changed', 'record']);

// Billing redesign PRD v2 D16: whether the money for each lesson is in.
const PAYMENT_LABELS = { paid: '已付', partial: '付了一部分', unpaid: '未付', no_invoice: '還沒開帳單' };
const HELD = ['attended', 'completed', 'late', 'absent'];
const UPCOMING = ['scheduled', 'rescheduled', 'expected'];

const state = ref('loading');
const data = ref(null);
const memo = ref('');
const editing = ref(false);
const draft = ref('');
const saving = ref(false);
const memoError = ref('');

const sessions = computed(() => data.value?.sessions || []);
const unscheduled = computed(() => Number(data.value?.unscheduled_count || 0));
const heldCount = computed(() => sessions.value.filter((s) => HELD.includes(s.status)).length);
const upcomingCount = computed(() => sessions.value.filter((s) => UPCOMING.includes(s.status)).length + unscheduled.value);

const dateOnly = (d) => (d ? String(d).slice(0, 10).replaceAll('-', '/') : '');
const title = computed(() => {
  const c = data.value || props.course;
  const range = [dateOnly(c.start_date), dateOnly(c.end_date)].filter(Boolean).join('–');
  return range ? `${c.subject || '課程'}・${range}` : (c.subject || '課程');
});
const modeLine = computed(() => ((data.value?.schedule_mode || props.course.schedule_mode) === 'date'
  ? '月結：每個月依當月上課日收費'
  : '堂數制：先買堂數，上一堂扣一堂'));

const months = computed(() => {
  const groups = [];
  for (const s of sessions.value) {
    const key = String(s.date).slice(0, 7);
    if (groups.at(-1)?.key !== key) groups.push({ key, label: `${key.slice(0, 4)} 年 ${Number(key.slice(5, 7))} 月`, sessions: [] });
    groups.at(-1).sessions.push(s);
  }
  return groups;
});

async function load() {
  state.value = 'loading';
  try {
    const resp = await authedFetch(`/api/v1/accounting/contracts/${Number(props.course.id)}/sessions`, { headers: { Accept: 'application/json' } });
    if (!resp.ok) throw new Error(String(resp.status));
    data.value = await resp.json();
    memo.value = data.value.memo || '';
    state.value = 'ready';
  } catch {
    state.value = 'error';
  }
}

function startEdit() {
  draft.value = memo.value;
  memoError.value = '';
  editing.value = true;
}

async function saveMemo() {
  saving.value = true;
  memoError.value = '';
  try {
    // Memo-only PUT leaves payment status untouched (StudentClassPaidStatusTest::test_update_memo_only_does_not_touch_paid).
    const resp = await authedFetch(`/api/v1/student-classes/${Number(props.course.id)}`, {
      method: 'PUT',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ Memo: draft.value }),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(json.message || `儲存失敗（${resp.status}）`);
    memo.value = draft.value;
    editing.value = false;
    emit('changed');
  } catch (e) {
    memoError.value = humanizeApiErrorMessage(e.message || '儲存失敗');
  } finally {
    saving.value = false;
  }
}

const money = (v) => 'NT$ ' + Number(v || 0).toLocaleString('zh-TW');
const noObligation = computed(() => data.value?.class_type === 'tutoring');
const busy = ref(false);
const actionError = ref('');

// PRD v2 D9/D20: step 2 (確認入帳 / 退回) happens right here, no tab switch.
const rejectOpen = ref(false);
const rejectReason = ref('');
function openReject() {
  rejectReason.value = '';
  rejectOpen.value = true;
}
function submitReject() {
  const reason = rejectReason.value.trim();
  if (!reason) return;
  rejectOpen.value = false;
  decide('reject', reason);
}
async function decide(action, reason = '') {
  const body = action === 'reject' ? { rejection_note: reason } : {};
  busy.value = true;
  actionError.value = '';
  try {
    const resp = await authedFetch(`/api/v1/payment-reports/${Number(props.pendingReport.report_id)}/${action}`, {
      method: 'PUT',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(json.message || `操作失敗（${resp.status}）`);
    emit('changed');
  } catch (e) {
    actionError.value = humanizeApiErrorMessage(e.message || '操作失敗');
  } finally {
    busy.value = false;
  }
}

watch(() => props.course.id, load, { immediate: true });
</script>

<style scoped>
.contract{border:1px solid var(--ds-canvas-soft);border-radius:12px;padding:12px 14px;display:grid;gap:10px;background:var(--surface,var(--ds-canvas))}
.contract__head{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 10px}
.contract__head h5{margin:0;font-size:15px}
.contract__mode{font-size:12px;color:var(--ds-ink-mute)}
.contract__money{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;font-size:14px}
.contract__money .due{color:var(--ds-danger)}
.contract__reject-label{display:block;margin-bottom:6px;font-size:14px;font-weight:700}
.contract__btn{border:1px solid var(--ds-primary,var(--ds-canvas-soft));background:var(--ds-primary,var(--ds-canvas));color:var(--ds-on-primary,var(--ds-canvas));border-radius:8px;padding:6px 12px;font-size:13px;font-weight:700;cursor:pointer;min-height:36px}
.contract__btn--ghost{background:transparent;color:var(--ds-ink)}
.contract__memo{background:var(--ds-canvas-soft);border-radius:8px;padding:8px 10px;display:grid;gap:6px}
.contract__memo-text{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:14px}
.contract__memo textarea{width:100%;box-sizing:border-box;font:inherit;font-size:14px;padding:6px 8px}
.contract__memo-actions{display:flex;gap:8px}
.contract__summary{margin:0;font-size:13px;font-weight:700}
.contract__month h6{margin:0 0 4px;font-size:12px;color:var(--ds-ink-mute)}
.contract__month ol{list-style:none;margin:0;padding:0;display:grid;gap:4px}
.contract__month li{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;font-size:13px;padding:4px 0;border-bottom:1px solid var(--ds-canvas-soft)}
.contract__date{min-width:110px;font-variant-numeric:tabular-nums}
.contract__time{min-width:44px;color:var(--ds-ink-mute);font-variant-numeric:tabular-nums}
.contract__chip{display:inline-flex;border-radius:6px;padding:1px 7px;font-size:11px;font-weight:700;background:var(--ds-canvas-soft);color:var(--ds-ink-mute)}
.contract__chip.tone-done,.contract__chip.pay-paid{background:var(--ds-success-wash);color:var(--ds-success)}
.contract__chip.tone-warn,.contract__chip.pay-partial{background:var(--ds-warning-wash);color:var(--ds-warning)}
.contract__chip.pay-unpaid{background:var(--ds-danger-wash);color:var(--ds-danger)}
.contract__muted{margin:0;font-size:13px;color:var(--ds-ink-mute)}
.contract__error{margin:0;font-size:13px;color:var(--ds-danger)}
.contract__link{border:0;background:transparent;color:var(--ds-primary-text);font-size:13px;font-weight:600;cursor:pointer;padding:0;justify-self:start}
</style>
