<template>
  <section class="tma" aria-labelledby="tma-title">
    <div class="tma-toolbar">
      <h3 id="tma-title" class="tma-title">{{ current ? `${current.teacher_name} · ${month} ${title}` : title }}</h3>
      <input v-model="month" type="month" class="att-date-input" aria-label="選擇月份" @change="load" />
      <select v-if="teachers.length > 1" v-model="teacherId" class="att-date-input" aria-label="選擇老師">
        <option v-for="t in teachers" :key="t.teacher_id" :value="t.teacher_id">
          {{ t.teacher_name }}{{ issueSummary(t.totals) }}
        </option>
      </select>
      <label class="tma-check"><input v-model="onlyAnomaly" type="checkbox" /> 只看異常</label>
      <button type="button" class="ghost small" @click="print">列印</button>
    </div>
    <div v-if="canClose && campusId && !loading" class="tma-close" role="status">
      <template v-if="monthClose">
        <AtBadge :label="`本月已確認（${monthClose.closed_by} ${monthClose.closed_at.slice(0, 16)}）`" tone="success" />
        <span class="tma-close-hint">已確認的月份不能補卡。</span>
        <button type="button" class="ghost small" :disabled="closing" @click="reopenMonth">重新開啟</button>
      </template>
      <template v-else>
        <span class="tma-close-hint">核對完這個月的出勤後按確認，之後要修改需先重新開啟並寫原因。</span>
        <button type="button" class="primary small" :disabled="closing" @click="closeMonth">確認本月出勤</button>
      </template>
      <span v-if="closeError" class="tma-close-error" role="alert">{{ closeError }}</span>
    </div>

    <div v-if="loading" class="att-empty enterprise-empty enterprise-loading" role="status" aria-live="polite">載入中…</div>
    <div v-else-if="error" class="att-empty enterprise-empty" role="alert">{{ error }}</div>
    <div v-else-if="!current" class="att-empty enterprise-empty" role="status">這個月沒有刷卡或排課紀錄</div>
    <template v-else>
      <div class="tma-metrics" :aria-label="`${current.teacher_name} 本月合計`">
        <AtMetric label="出勤天數" :value="current.totals.days_present" />
        <AtMetric label="總工時（時）" :value="hours(current.totals.minutes).toFixed(2)" />
        <AtMetric label="遲到" :value="current.totals.late_days" :accent="current.totals.late_days ? 'var(--ds-warning)' : ''" />
        <AtMetric label="有課未刷卡" :value="current.totals.missed_days" :accent="current.totals.missed_days ? 'var(--ds-danger)' : ''" />
        <AtMetric
          label="只刷一次（待補登）"
          :value="current.totals.anomaly_days"
          :accent="current.totals.anomaly_days ? 'var(--ds-warning)' : ''"
        />
        <AtMetric label="已修正" :value="current.totals.corrected_days" />
      </div>
      <div class="att-table-scroll">
        <table class="tma-table">
          <thead>
            <tr><th>日期</th><th>第一堂</th><th>狀態</th><th>跑校</th><th>上班</th><th>下班</th><th class="tma-num">工時(時)</th><th>註記</th></tr>
          </thead>
          <tbody>
            <tr
              v-for="d in rows"
              :key="d.date"
              :class="{ 'tma-anomaly': isIssue(d), 'tma-weekend': isWeekend(d.date) && !isIssue(d), 'tma-empty': !d.sign_in && !isIssue(d) && !d.status }"
            >
              <td>{{ d.label }}</td>
              <td>{{ d.first_class ?? '' }}</td>
              <td>
                <div class="tma-notes">
                  <AtBadge v-if="STATUS[d.status]" :label="statusLabel(d)" :tone="STATUS[d.status].tone" />
                  <AtBadge v-if="d.original_late_minutes" :label="`原本遲到 ${d.original_late_minutes} 分（已修正）`" tone="neutral" />
                </div>
              </td>
              <td>{{ d.run_school ? '是' : '' }}</td>
              <td>{{ d.sign_in ?? '' }}</td>
              <td>{{ d.sign_out ?? '' }}</td>
              <td class="tma-num">{{ d.minutes != null ? hours(d.minutes).toFixed(2) : '' }}</td>
              <td>
                <div class="tma-notes">
                  <AtBadge v-for="(n, i) in notes(d.note)" :key="i" :label="n" :tone="noteTone(n)" />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import { supabase } from '../supabase';
import AtBadge from './design-system/AtBadge.vue';
import AtMetric from './design-system/AtMetric.vue';

const props = defineProps({
  campusId: { type: [Number, String], default: null },
  title: { type: String, default: '月出勤表' },
  canClose: { type: Boolean, default: false },
});

const now = new Date();
const month = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);
const teachers = ref([]);
const teacherId = ref(null);
const onlyAnomaly = ref(false);
const loading = ref(false);
const monthClose = ref(null);
const closing = ref(false);
const closeError = ref('');
const error = ref('');

const current = computed(() => teachers.value.find(t => t.teacher_id === teacherId.value) ?? null);
const STATUS = {
  on_time: { label: '準時', tone: 'success' },
  late: { label: '遲到', tone: 'warning' },
  missed: { label: '有課未刷卡', tone: 'danger' },
  admin: { label: '行政出勤', tone: 'info' },
};
const statusLabel = d => (d.status === 'late' ? `遲到 ${d.late_minutes} 分` : STATUS[d.status].label);
const isIssue = d => d.anomaly || d.status === 'late' || d.status === 'missed';
const issueSummary = t => {
  const parts = [
    t.late_days && `遲到 ${t.late_days}`,
    t.missed_days && `缺卡 ${t.missed_days}`,
    t.anomaly_days && `只刷一次 ${t.anomaly_days}`,
  ].filter(Boolean);
  return parts.length ? `（${parts.join('、')}）` : '';
};
const rows = computed(() => (current.value?.days ?? []).filter(d => !onlyAnomaly.value || isIssue(d)));
const hours = m => Math.round(m / 60 * 100) / 100;
const notes = note => (note ? note.split('、') : []);
const noteTone = n => (n.startsWith('只刷一次') || n.startsWith('跨校未簽退') ? 'warning' : n === '上班中' ? 'success' : 'info');
const isWeekend = date => [0, 6].includes(new Date(`${date}T00:00:00`).getDay());

let seq = 0;
async function load() {
  const my = ++seq;
  loading.value = true;
  error.value = '';
  try {
    const { data: { session } } = await supabase.auth.getSession();
    const campus = props.campusId ? `&campus_id=${props.campusId}` : '';
    const res = await fetch(`/api/v1/teacher-attendance/monthly?year_month=${month.value}${campus}`, {
      headers: { Authorization: `Bearer ${session?.access_token}`, Accept: 'application/json' },
    });
    if (!res.ok) throw new Error(String(res.status));
    const data = await res.json();
    if (my !== seq) return;
    teachers.value = data.teachers ?? [];
    monthClose.value = data.month_close ?? null;
    if (!current.value) teacherId.value = teachers.value[0]?.teacher_id ?? null;
  } catch {
    if (my !== seq) return;
    teachers.value = [];
    error.value = '讀取失敗，請稍後再試';
  } finally {
    if (my === seq) loading.value = false;
  }
}

async function postMonth(path, extra = {}) {
  closing.value = true;
  closeError.value = '';
  try {
    const { data: { session } } = await supabase.auth.getSession();
    const res = await fetch(`/api/v1/teacher-attendance/${path}`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${session?.access_token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ year_month: month.value, campus_id: Number(props.campusId), ...extra }),
    });
    if (!res.ok) {
      const json = await res.json().catch(() => null);
      closeError.value = json?.message ?? '操作失敗，請稍後再試';
      return;
    }
    await load();
  } catch {
    closeError.value = '操作失敗，請稍後再試';
  } finally {
    closing.value = false;
  }
}

function closeMonth() {
  if (window.confirm(`確認 ${month.value} 的老師出勤？確認後不能補卡。`)) postMonth('month-close');
}

function reopenMonth() {
  const reason = window.prompt('重新開啟的原因（會留紀錄）');
  if (reason && reason.trim().length >= 2) postMonth('month-reopen', { reason: reason.trim() });
  else if (reason !== null) closeError.value = '請寫至少 2 個字的原因';
}

async function print() {
  onlyAnomaly.value = false; // 列印一定是整月完整紀錄（§30 副本）
  await nextTick();
  window.print();
}

watch(() => props.campusId, load);
onMounted(load);
</script>

<style scoped>
.tma-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 8px; }
.tma-toolbar .att-date-input { width: auto; flex: 0 0 auto; }
.tma-check { white-space: nowrap; }
.tma-title { margin: 0 auto 0 0; font-size: 1rem; }
.tma-close { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
.tma-close-hint { color: var(--ds-text-tertiary); font-size: 0.8125rem; }
.tma-close-error { color: var(--ds-danger); font-size: 0.8125rem; }
.tma-check { display: inline-flex; flex: 0 0 auto; gap: 4px; align-items: center; font-size: 0.875rem; }
.tma-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; margin-bottom: 12px; }
.tma-table td, .tma-table th { white-space: nowrap; }
.tma-num { text-align: right; font-variant-numeric: tabular-nums; }
.tma-notes { display: flex; gap: 4px; flex-wrap: wrap; }
.tma-anomaly td { background: var(--ds-warning-wash); }
.tma-anomaly td:first-child { box-shadow: inset 3px 0 0 var(--ds-warning); font-weight: 600; }
.tma-weekend td { background: var(--ds-surface-subtle); }
.tma-empty td { color: var(--ds-text-tertiary); }
@media print {
  .tma-toolbar select, .tma-toolbar input, .tma-toolbar button, .tma-check { display: none; }
}
</style>
