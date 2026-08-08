<template>
  <div class="eligibility-page">
    <div class="page-header">
      <div class="page-header-left">
        <div class="page-icon"><span class="material-symbols-outlined">rule</span></div>
        <div class="title-group">
          <h2>正職老師薪資要件</h2>
          <p class="title-sub">依115.07制度分項挑出符合條件、未符合與待人工確認的老師</p>
        </div>
      </div>
      <button class="btn-outline" :disabled="loading" @click="loadData">
        <span class="material-symbols-outlined">refresh</span>重新整理
      </button>
    </div>

    <div class="eligibility-card filters">
      <label>查詢層級
        <select v-model="period" @change="loadData">
          <option value="week">本週</option>
          <option value="month">本月</option>
          <option value="year">本年度</option>
        </select>
      </label>
      <label>顯示項目
        <select v-model="componentKey">
          <option value="all">全部項目</option>
          <option v-for="item in componentOptions" :key="item.key" :value="item.key">{{ item.label }}</option>
        </select>
      </label>
      <label class="search-label">搜尋老師
        <input v-model.trim="search" placeholder="姓名" />
      </label>
      <span class="policy-chip">制度 {{ policyVersion }}｜生效 {{ effectiveFrom }}</span>
    </div>

    <div v-if="loading" class="eligibility-card loading">載入中…</div>
    <div v-else-if="error" class="eligibility-card error">{{ error }} <button class="btn-outline small" @click="loadData">重試</button></div>
    <template v-else>
      <div class="summary-grid">
        <div class="summary-card"><span>老師總數</span><strong>{{ filteredTeachers.length }}</strong></div>
        <div class="summary-card success"><span>符合獎金項目</span><strong>{{ qualifyingCount }}</strong></div>
        <div class="summary-card warning"><span>待人工確認</span><strong>{{ reviewCount }}</strong></div>
        <div class="summary-card danger"><span>有扣除案件</span><strong>{{ deductionCount }}</strong></div>
      </div>

      <div class="eligibility-card table-wrap desktop-table">
        <table>
          <thead>
            <tr><th>老師</th><th>整體狀態</th><th v-for="item in visibleComponents" :key="item.key">{{ item.label }}</th><th>缺少資料／原因</th></tr>
          </thead>
          <tbody>
            <tr v-for="teacher in filteredTeachers" :key="teacher.teacher_id">
              <td><strong>{{ teacher.teacher_name }}</strong><small>ID {{ teacher.teacher_id }}</small></td>
              <td><span :class="['status', statusClass(teacher.overall_status)]">{{ statusLabel(teacher.overall_status) }}</span></td>
              <td v-for="item in visibleComponents" :key="item.key">
                <span :class="['status', statusClass(teacher.components?.[item.key]?.status)]">{{ statusLabel(teacher.components?.[item.key]?.status) }}</span>
                <small>{{ detail(item.key, teacher.components?.[item.key]) }}</small>
              </td>
              <td class="reason-cell">{{ reasonText(teacher) }}</td>
            </tr>
            <tr v-if="filteredTeachers.length === 0"><td :colspan="visibleComponents.length + 3" class="empty">查詢期間沒有符合條件的正職老師資料。</td></tr>
          </tbody>
        </table>
      </div>
      <div class="mobile-list">
        <article v-for="teacher in filteredTeachers" :key="teacher.teacher_id" class="eligibility-card teacher-card">
          <div class="teacher-card-header">
            <div><strong>{{ teacher.teacher_name }}</strong><small>ID {{ teacher.teacher_id }}</small></div>
            <span :class="['status', statusClass(teacher.overall_status)]">{{ statusLabel(teacher.overall_status) }}</span>
          </div>
          <div class="teacher-components">
            <div v-for="item in visibleComponents" :key="item.key" class="component-row">
              <span>{{ item.label }}</span>
              <span class="component-value">
                <span :class="['status', statusClass(teacher.components?.[item.key]?.status)]">{{ statusLabel(teacher.components?.[item.key]?.status) }}</span>
                <small>{{ detail(item.key, teacher.components?.[item.key]) }}</small>
              </span>
            </div>
          </div>
          <p class="mobile-reason">{{ reasonText(teacher) }}</p>
        </article>
        <div v-if="filteredTeachers.length === 0" class="eligibility-card empty">查詢期間沒有符合條件的正職老師資料。</div>
      </div>
      <p class="footnote">資料不足時顯示「待人工確認」，不會直接判定不符合；假日、請假、升學成果與扣除案件需先完成資料登錄／審核。</p>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { fetchTeacherEligibility } from '../lib/teacherEligibilityApi.js';

const props = defineProps({ branchId: { type: [Number, String], default: null } });
const period = ref('month');
const componentKey = ref('all');
const search = ref('');
const loading = ref(false);
const error = ref('');
const teachers = ref([]);
const policyVersion = ref('115.07');
const effectiveFrom = ref('2026-07-01');
const componentOptions = [
  { key: 'weekly_16_segments', label: '每週16段' },
  { key: 'holiday_16_hours', label: '假日16小時' },
  { key: 'weekday_afternoon', label: '平日下午課' },
  { key: 'special_performance', label: '特殊表現' },
  { key: 'deductions', label: '扣除案件' },
  { key: 'subject_count_bonus', label: '科目數獎金' },
];

const visibleComponents = computed(() => componentKey.value === 'all'
  ? componentOptions
  : componentOptions.filter(item => item.key === componentKey.value));
const filteredTeachers = computed(() => teachers.value.filter(teacher => !search.value || teacher.teacher_name.includes(search.value)));
const reviewCount = computed(() => filteredTeachers.value.filter(t => t.review_required).length);
const qualifyingCount = computed(() => filteredTeachers.value.filter((teacher) => componentOptions.some((item) => {
  const component = teacher.components?.[item.key];
  return component?.status === 'qualifies' && (Number(component.rate) > 0 || Number(component.amount) > 0);
})).length);
const deductionCount = computed(() => filteredTeachers.value.filter(t => Number(t.components?.deductions?.rate || 0) < 0).length);

async function loadData() {
  loading.value = true; error.value = '';
  try {
    const data = await fetchTeacherEligibility({ period: period.value, branchId: props.branchId });
    teachers.value = data.teachers || [];
    policyVersion.value = data.policy_version || policyVersion.value;
    effectiveFrom.value = data.effective_from || effectiveFrom.value;
  } catch (e) { error.value = e?.message || '載入失敗'; }
  finally { loading.value = false; }
}

function statusLabel(status) { return { qualifies: '符合', not_qualifies: '不符合', review: '待人工確認' }[status] || '未判定'; }
function statusClass(status) { return { qualifies: 'pass', not_qualifies: 'fail', review: 'review' }[status] || 'unknown'; }
function detail(key, component) {
  if (!component) return '—';
  if (component.status === 'review') return '待補資料／確認';
  if (key === 'weekly_16_segments') return `${component.amount ?? 0}元`;
  if (key === 'holiday_16_hours' || key === 'weekday_afternoon' || key === 'special_performance') return `${component.rate ?? 0}%`;
  if (key === 'deductions') return `${component.rate ?? 0}%`;
  return component.metrics?.subject_count == null ? '—' : `${component.metrics.subject_count}科`;
}
function reasonText(teacher) {
  const reasons = [];
  for (const item of componentOptions) {
    const component = teacher.components?.[item.key];
    if (component?.reason) reasons.push(`${item.label}：${component.reason}`);
  }
  if (teacher.missing_fields?.length) reasons.push(`缺少：${teacher.missing_fields.join('、')}`);
  return reasons.join('；');
}

watch(() => props.branchId, loadData);
onMounted(loadData);
</script>

<style scoped>
.eligibility-page { padding: 24px; max-width: 1800px; margin: 0 auto; }
.page-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:18px; }
.page-header-left { display:flex; align-items:center; gap:12px; }
.page-icon { width:44px; height:44px; border-radius:12px; display:grid; place-items:center; background:var(--ds-primary-wash); color:var(--ds-primary); }
.title-group h2 { margin:0; }.title-sub { margin:4px 0 0; color:var(--ds-ink-mute); }
.eligibility-card { background:var(--ds-canvas); border:1px solid var(--border); border-radius:14px; padding:16px; box-shadow:var(--ds-shadow-1); }
.filters { display:flex; align-items:end; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
.filters label { display:flex; flex-direction:column; gap:6px; font-size:13px; color:var(--ds-ink-mute); }
.filters select,.filters input { min-width:150px; padding:9px 10px; border:1px solid var(--border); border-radius:8px; background:var(--ds-canvas); color:inherit; }
.search-label input { min-width:220px; }.policy-chip { margin-left:auto; padding:8px 12px; color:var(--ds-ink-mute); background:var(--ds-canvas-soft); border-radius:8px; font-size:13px; }
.summary-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; margin-bottom:18px; }
.summary-card { padding:16px; background:var(--ds-canvas); border:1px solid var(--border); border-radius:12px; }.summary-card span { color:var(--ds-ink-mute); font-size:13px; }.summary-card strong { display:block; font-size:28px; margin-top:6px; }.summary-card.success strong { color:var(--ds-success); }.summary-card.warning strong { color:var(--ds-warning); }.summary-card.danger strong { color:var(--ds-danger); }
.table-wrap { overflow:auto; }.table-wrap table { width:100%; border-collapse:collapse; min-width:1050px; }.table-wrap th,.table-wrap td { padding:12px 10px; border-bottom:1px solid var(--border); text-align:left; vertical-align:top; }.table-wrap th { color:var(--ds-ink-mute); font-size:13px; background:var(--ds-canvas-soft); }.table-wrap td small { display:block; margin-top:4px; color:var(--ds-ink-mute); font-size:12px; }.status { display:inline-flex; padding:4px 8px; border-radius:999px; font-size:12px; white-space:nowrap; }.status.pass { background:var(--ds-success-wash); color:var(--ds-success); }.status.fail { background:var(--ds-danger-wash); color:var(--ds-danger); }.status.review { background:var(--ds-warning-wash); color:var(--ds-warning); }.status.unknown { background:var(--ds-canvas-soft); color:var(--ds-ink-mute); }.reason-cell { min-width:280px; line-height:1.6; }.empty,.loading { text-align:center; color:var(--ds-ink-mute); padding:36px; }.error { color:var(--ds-danger); display:flex; align-items:center; gap:12px; }.footnote { color:var(--ds-ink-mute); font-size:13px; margin:12px 4px; }.mobile-list { display:none; }
.teacher-card-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }.teacher-card-header small { display:block; margin-top:4px; color:var(--ds-ink-mute); font-size:12px; }.teacher-components { margin-top:14px; border-top:1px solid var(--border); }.component-row { display:flex; justify-content:space-between; gap:12px; padding:10px 0; border-bottom:1px solid var(--border); }.component-value { display:flex; flex-direction:column; align-items:flex-end; gap:4px; }.component-value small { color:var(--ds-ink-mute); font-size:12px; }.mobile-reason { margin:12px 0 0; color:var(--ds-ink-mute); font-size:13px; line-height:1.6; }
@media (max-width: 900px) { .eligibility-page { padding:16px; }.summary-grid { grid-template-columns:repeat(2, minmax(0,1fr)); }.policy-chip { margin-left:0; }.desktop-table { display:none; }.mobile-list { display:grid; gap:12px; } }
</style>
