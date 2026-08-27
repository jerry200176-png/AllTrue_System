<template>
  <section class="tafr-card" aria-labelledby="teacher-assessment-fill-rate-title">
    <header class="tafr-card__header">
      <div>
        <div class="tafr-card__eyebrow">教學品質追蹤</div>
        <h3 id="teacher-assessment-fill-rate-title">老師評量完成率</h3>
        <p>用來安排協助與提醒，不是公開排名。代課堂次會歸給實際授課老師。</p>
      </div>
      <label class="tafr-period">
        <span>統計區間</span>
        <select v-model="selectedDays" :disabled="loading" aria-label="評量完成率統計區間" @change="load">
          <option :value="7">近 7 天</option>
          <option :value="14">近 14 天</option>
          <option :value="30">近 30 天</option>
        </select>
      </label>
    </header>

    <div v-if="loading" class="tafr-state" role="status">評量完成率載入中…</div>
    <div v-else-if="error" class="tafr-state tafr-state--error" role="alert">
      <span>{{ error }}</span>
      <button type="button" class="text-action" @click="load">再試一次</button>
    </div>
    <div v-else-if="!rows.length" class="tafr-state">
      <span class="material-symbols-outlined" aria-hidden="true">insights</span>
      <span>這段期間沒有可追蹤的已完成課程。</span>
    </div>
    <template v-else>
      <div class="tafr-summary" aria-label="評量完成率摘要">
        <div><strong>{{ followUpCount }}</strong><span>位需要跟進</span></div>
        <div><strong>{{ watchCount }}</strong><span>位提醒關注</span></div>
        <div><strong>{{ rows.length }}</strong><span>位有上課紀錄</span></div>
      </div>
      <div class="tafr-table-wrap">
        <table class="tafr-table">
          <caption class="sr-only">{{ periodLabel }}老師評量完成率</caption>
          <thead><tr><th scope="col">老師</th><th scope="col">完成率</th><th scope="col">已填／應填</th><th scope="col">狀態</th></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.teacherId || row.teacherName">
              <th scope="row"><span class="tafr-teacher">{{ row.teacherName }}</span><small v-if="row.status === 'building'">樣本 {{ row.sessions }} 堂</small></th>
              <td><strong class="tafr-rate">{{ row.fillRate }}%</strong><div class="tafr-progress" aria-hidden="true"><span :style="{ width: `${row.fillRate}%` }" /></div></td>
              <td>{{ row.filled }}／{{ row.sessions }}<small v-if="row.pending">待填 {{ row.pending }} 堂</small></td>
              <td><span class="tafr-status" :class="`tafr-status--${statusFor(row).tone}`">{{ statusFor(row).label }}</span><small>{{ statusFor(row).description }}</small></td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="tafr-note">口徑：已到班、遲到、已完成堂次；取消與請假不列入。填寫率只代表是否有有效進度文字，主任仍應查看內容品質。</p>
      <footer class="tafr-card__footer"><button type="button" class="text-action" @click="$emit('view-learning')">前往評量審核</button></footer>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import {
  getTeacherAssessmentFillRateStatus,
  normalizeTeacherAssessmentFillRate,
  sortTeacherAssessmentFillRates,
} from '../lib/teacherAssessmentFillRate.js';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  fetchReport: { type: Function, required: true },
});

defineEmits(['view-learning']);

const selectedDays = ref(14);
const loading = ref(false);
const error = ref('');
const rows = ref([]);

const followUpCount = computed(() => rows.value.filter((row) => row.status === 'follow_up').length);
const watchCount = computed(() => rows.value.filter((row) => row.status === 'watch').length);
const periodLabel = computed(() => `近 ${selectedDays.value} 天`);

function statusFor(row) {
  return getTeacherAssessmentFillRateStatus(row.status);
}

async function load() {
  if (!props.branchId) {
    rows.value = [];
    return;
  }
  loading.value = true;
  error.value = '';
  try {
    const report = await props.fetchReport({ branch_id: props.branchId, days: selectedDays.value });
    const normalized = Array.isArray(report?.teachers)
      ? report.teachers.map((row) => normalizeTeacherAssessmentFillRate(row))
      : [];
    rows.value = sortTeacherAssessmentFillRates(normalized);
  } catch (e) {
    rows.value = [];
    error.value = e?.message || '評量完成率暫時無法載入。';
  } finally {
    loading.value = false;
  }
}

watch(() => props.branchId, load, { immediate: true });

defineExpose({ reload: load });
</script>

<style scoped>
.tafr-card { display: flex; flex-direction: column; gap: 14px; }
.tafr-card__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.tafr-card__eyebrow { color: var(--ds-primary); font-size: 11px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
.tafr-card h3 { margin: 3px 0 4px; color: var(--ds-ink); font-size: 16px; }
.tafr-card p { margin: 0; color: var(--ds-ink-secondary); font-size: 12px; line-height: 1.5; }
.tafr-period { display: flex; flex-direction: column; gap: 5px; flex: 0 0 auto; color: var(--ds-ink-mute); font-size: 11px; font-weight: 700; }
.tafr-period select { min-width: 100px; border: 1px solid var(--ds-hairline-input); border-radius: 8px; padding: 7px 8px; background: var(--ds-canvas); color: var(--ds-ink); font: inherit; }
.tafr-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.tafr-summary div { padding: 10px 12px; border: 1px solid var(--ds-hairline); border-radius: 9px; background: var(--ds-canvas-soft); }
.tafr-summary strong, .tafr-summary span { display: block; }
.tafr-summary strong { color: var(--ds-ink); font-size: 20px; font-variant-numeric: tabular-nums; }
.tafr-summary span { margin-top: 2px; color: var(--ds-ink-mute); font-size: 11px; }
.tafr-table-wrap { overflow-x: auto; }
.tafr-table { width: 100%; border-collapse: collapse; color: var(--ds-ink); font-size: 13px; }
.tafr-table th, .tafr-table td { padding: 10px 8px; border-bottom: 1px solid var(--ds-hairline); text-align: left; vertical-align: middle; }
.tafr-table thead th { color: var(--ds-ink-mute); font-size: 11px; font-weight: 800; white-space: nowrap; }
.tafr-table tbody th { min-width: 92px; font-weight: 700; }
.tafr-table small { display: block; margin-top: 3px; color: var(--ds-ink-mute); font-size: 11px; font-weight: 400; line-height: 1.35; }
.tafr-teacher { display: block; }
.tafr-rate { display: inline-block; min-width: 42px; font-variant-numeric: tabular-nums; }
.tafr-progress { display: inline-block; width: 62px; height: 5px; margin-left: 6px; overflow: hidden; border-radius: 99px; background: var(--ds-hairline); vertical-align: middle; }
.tafr-progress span { display: block; height: 100%; border-radius: inherit; background: var(--ds-primary); }
.tafr-status { display: inline-flex; border-radius: 999px; padding: 3px 7px; font-size: 11px; font-weight: 800; white-space: nowrap; }
.tafr-status--success { background: var(--ds-success-wash); color: var(--ds-success); }
.tafr-status--warning { background: var(--ds-warning-wash); color: var(--ds-warning); }
.tafr-status--danger { background: var(--ds-danger-wash); color: var(--ds-danger); }
.tafr-status--neutral { background: var(--ds-canvas-soft); color: var(--ds-ink-secondary); }
.tafr-note { padding-top: 2px; font-size: 11px !important; }
.tafr-card__footer { display: flex; justify-content: flex-end; }
.tafr-state { display: flex; align-items: center; gap: 8px; min-height: 80px; color: var(--ds-ink-secondary); font-size: 13px; }
.tafr-state--error { justify-content: space-between; color: var(--ds-danger); }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
@media (max-width: 720px) { .tafr-card__header { flex-direction: column; } .tafr-period { flex-direction: row; align-items: center; } .tafr-summary { grid-template-columns: 1fr; } .tafr-progress { display: none; } }
</style>
