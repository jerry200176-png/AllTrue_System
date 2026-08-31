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
            <tr><th>老師</th><th>整體狀態</th><th>底薪</th><th>教師倍率</th><th v-for="item in visibleComponents" :key="item.key">{{ item.label }}</th><th>每週課程明細</th><th>總發放金額</th><th>缺少資料／原因</th></tr>
          </thead>
          <tbody>
            <tr v-for="teacher in filteredTeachers" :key="teacher.teacher_id">
              <td><strong>{{ teacher.teacher_name }}</strong><small>ID {{ teacher.teacher_id }}</small></td>
              <td><span :class="['status', statusClass(teacher.overall_status)]">{{ statusLabel(teacher.overall_status) }}</span></td>
              <td class="salary-cell">
                <template v-if="editingSalaryId === teacher.teacher_id">
                  <input v-model.number="salaryDraft" type="number" min="0" step="1" class="salary-input" />
                  <button class="btn-outline small" :disabled="savingSalary" @click="saveSalary(teacher)">存</button>
                  <button class="btn-outline small" @click="editingSalaryId = null">取消</button>
                </template>
                <template v-else>
                  {{ formatMoney(teacher.settlement?.base_salary) }}
                  <button class="btn-outline small" @click="startEditSalary(teacher)">改</button>
                </template>
              </td>
              <td>{{ teacher.settlement?.multiplier_pct ?? 100 }}%</td>
              <td v-for="item in visibleComponents" :key="item.key">
                <span :class="['status', statusClass(teacher.components?.[item.key]?.status)]">{{ statusLabel(teacher.components?.[item.key]?.status) }}</span>
                <small>{{ detail(item.key, teacher.components?.[item.key]) }}</small>
              </td>
              <td class="weekly-breakdown">
                <template v-for="week in weeklyWeeks(teacher)" :key="week.week_start">
                  <div><strong>{{ week.week_start }}～{{ week.week_end }}</strong>：{{ weeklySummary(week) }}</div>
                  <details v-if="week.course_sessions?.length">
                    <summary>查看 {{ week.course_sessions.length }} 堂實際課程</summary>
                    <ul><li v-for="session in week.course_sessions" :key="session.class_session_id">{{ session.session_date }} {{ session.start_time }}–{{ session.end_time }}｜{{ classTypeLabel(session.class_type) }}｜{{ session.segments }}段</li></ul>
                  </details>
                </template>
              </td>
              <td class="total-cell"><strong>{{ formatMoney(teacher.settlement?.calculated_payout ?? teacher.settlement?.total_payout) }}</strong><small>{{ calculationStatusLabel(teacher.settlement) }}</small></td>
              <td class="reason-cell">{{ reasonText(teacher) }}</td>
            </tr>
            <tr v-if="filteredTeachers.length === 0"><td :colspan="visibleComponents.length + 7" class="empty">查詢期間沒有符合條件的正職老師資料。</td></tr>
          </tbody>
        </table>
      </div>
      <div class="mobile-list">
        <article v-for="teacher in filteredTeachers" :key="teacher.teacher_id" class="eligibility-card teacher-card">
          <div class="teacher-card-header">
            <div><strong>{{ teacher.teacher_name }}</strong><small>ID {{ teacher.teacher_id }}</small></div>
            <span :class="['status', statusClass(teacher.overall_status)]">{{ statusLabel(teacher.overall_status) }}</span>
          </div>
          <div class="component-row"><span>底薪</span><span class="component-value">{{ formatMoney(teacher.settlement?.base_salary) }}</span></div>
          <div class="component-row"><span>教師倍率</span><span class="component-value">{{ teacher.settlement?.multiplier_pct ?? 100 }}%</span></div>
          <div class="teacher-components">
            <div v-for="item in visibleComponents" :key="item.key" class="component-row">
              <span>{{ item.label }}</span>
              <span class="component-value">
                <span :class="['status', statusClass(teacher.components?.[item.key]?.status)]">{{ statusLabel(teacher.components?.[item.key]?.status) }}</span>
                <small>{{ detail(item.key, teacher.components?.[item.key]) }}</small>
              </span>
            </div>
          </div>
          <div class="component-row weekly-breakdown"><span>每週課程明細</span><span class="component-value">
            <template v-for="week in weeklyWeeks(teacher)" :key="week.week_start">
              <span>{{ week.week_start }}～{{ week.week_end }}：{{ weeklySummary(week) }}</span>
              <details v-if="week.course_sessions?.length"><summary>查看實際課程</summary><ul><li v-for="session in week.course_sessions" :key="session.class_session_id">{{ session.session_date }} {{ session.start_time }}–{{ session.end_time }}｜{{ classTypeLabel(session.class_type) }}｜{{ session.segments }}段</li></ul></details>
            </template>
          </span></div>
          <div class="component-row total-cell"><span>計算後發放金額</span><span class="component-value"><strong>{{ formatMoney(teacher.settlement?.calculated_payout ?? teacher.settlement?.total_payout) }}</strong><small>{{ calculationStatusLabel(teacher.settlement) }}</small></span></div>
          <p class="mobile-reason">{{ reasonText(teacher) }}</p>
        </article>
        <div v-if="filteredTeachers.length === 0" class="eligibility-card empty">查詢期間沒有符合條件的正職老師資料。</div>
      </div>
      <TeacherEligibilityInputPanel
        :branch-id="props.branchId"
        :teachers="filteredTeachers"
        :start="periodWindow.start"
        :end="periodWindow.end"
        :user-role="props.userRole"
        @changed="loadData"
      />
      <p class="footnote">資料不足時顯示「待人工確認」，不會直接判定不符合；缺少欄位會在各項目下方列出，可直接從上方補登並送審。</p>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { fetchTeacherEligibility, saveTeacherSalaryProfile } from '../lib/teacherEligibilityApi.js';
import TeacherEligibilityInputPanel from '../components/TeacherEligibilityInputPanel.vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: '' },
});
const period = ref('month');
const componentKey = ref('all');
const search = ref('');
const loading = ref(false);
const error = ref('');
const teachers = ref([]);
const periodWindow = ref({ start: '', end: '' });
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

const editingSalaryId = ref(null);
const salaryDraft = ref(0);
const savingSalary = ref(false);

function formatMoney(value) {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value ?? 0);
  return `$${n.toLocaleString('zh-TW', { maximumFractionDigits: 0 })}`;
}

function startEditSalary(teacher) {
  editingSalaryId.value = teacher.teacher_id;
  salaryDraft.value = Math.round(Number(teacher.settlement?.base_salary ?? 0));
}

async function saveSalary(teacher) {
  savingSalary.value = true;
  try {
    await saveTeacherSalaryProfile({
      teacher_id: teacher.teacher_id,
      branch_id: props.branchId || null,
      base_salary: salaryDraft.value,
      effective_from: new Date().toISOString().slice(0, 10),
    });
    editingSalaryId.value = null;
    await loadData();
  } catch (e) {
    error.value = e?.message || '底薪儲存失敗';
  } finally {
    savingSalary.value = false;
  }
}

async function loadData() {
  loading.value = true; error.value = '';
  try {
    const data = await fetchTeacherEligibility({ period: period.value, branchId: props.branchId });
    teachers.value = data.teachers || [];
    periodWindow.value = { start: data.period?.start || '', end: data.period?.end || '' };
    policyVersion.value = data.policy_version || policyVersion.value;
    effectiveFrom.value = data.effective_from || effectiveFrom.value;
  } catch (e) { error.value = e?.message || '載入失敗'; }
  finally { loading.value = false; }
}

function statusLabel(status) { return { qualifies: '符合', not_qualifies: '不符合', review: '待人工確認' }[status] || '未判定'; }
function statusClass(status) { return { qualifies: 'pass', not_qualifies: 'fail', review: 'review' }[status] || 'unknown'; }
function detail(key, component) {
  if (!component) return '—';
  if (component.status === 'review') {
    const missing = (component.missing_fields || []).map(missingLabel).filter(Boolean);
    return missing.length ? `待補：${missing.join('、')}` : '待人工確認';
  }
  if (key === 'weekly_16_segments') {
    const metrics = component.metrics || {};
    const attainment = metrics.week_count === 1
      ? (metrics.meets_16_segments ? '達16段' : '未達16段')
      : `${metrics.qualifying_weeks ?? 0}/${metrics.week_count ?? 0}週達16段`;
    return `${metrics.total_segments ?? 0}段（正課${metrics.regular_segments ?? 0}／試聽${metrics.trial_segments ?? 0}）｜${attainment}`;
  }
  if (key === 'holiday_16_hours') {
    const metrics = component.metrics || {};
    const baseline = Object.values(metrics.regular_scheduled_hours || {}).reduce((total, hours) => total + Number(hours || 0), 0);
    const leave = Object.values(metrics.holiday_leave_hours || {}).reduce((total, hours) => total + Number(hours || 0), 0);
    return `${component.rate ?? 0}%｜常態${baseline}h｜假日假${leave}h不加算`;
  }
  if (key === 'special_performance') return `${component.rate ?? 0}%`;
  if (key === 'weekday_afternoon') return `${component.rate ?? 0}%｜有效${component.metrics?.extra_segments ?? 0}段`;
  if (key === 'deductions') return `${component.rate ?? 0}%`;
  return component.metrics?.subject_count == null ? '—' : `${component.metrics.subject_count}科`;
}
function weeklyWeeks(teacher) { return teacher.components?.weekly_16_segments?.metrics?.weeks || []; }
function weeklySummary(week) {
  const metrics = week.metrics || {};
  return `正課${metrics.regular_segments ?? 0}段／試聽${metrics.trial_segments ?? 0}段／合計${metrics.total_segments ?? 0}段｜${metrics.meets_16_segments ? '達標' : '未達標'}`;
}
function classTypeLabel(type) { return { one_on_one: '1對1', one_on_two: '1對2', one_on_three: '1對3', trial: '試聽', tutoring: '輔導' }[type] || type || '正課'; }
function calculationStatusLabel(settlement) {
  const status = settlement?.calculation_status;
  if (status === 'partial') return `已計算｜待確認${settlement?.pending_items?.length || 0}項`;
  if (status === 'blocked') return '核心資料不足，尚未計算';
  return status === 'calculated' ? '已計算' : '';
}
function missingLabel(field) {
  const labels = {
    weekly_segments: '每週正課段數',
    work_hours: '實際工時',
    weekly_exception_context: '官方活動／公休／請假例外',
    holiday_calendar: '假日曆',
    regular_scheduled_hours: '假日常態排課時數',
    achievement_evidence_or_approval: '成果證明／審核',
    deduction_approval: '主任確認／總部核准',
    approved_learning_records: '已核准評量資料',
    subject_count_table: '科目數附件表',
  };
  if (labels[field]) return labels[field];
  const match = /^holiday_days\.\d+\.(.+)$/.exec(field || '');
  if (match) return `假日${({ date: '日期', regular_scheduled_hours: '常態排課時數', worked_hours: '出勤時數', holiday_leave_hours: '假日假時數' }[match[1]] || match[1])}`;
  const weekday = /^weekday_hours\.(.+)$/.exec(field || '');
  if (weekday) return `平日課程時數 ${weekday[1]}`;
  return field;
}
function reasonText(teacher) {
  const reasons = [];
  if (teacher.work_hours_source === 'student_attendance') {
    reasons.push('工時來源：學生到班／點名（不採教師刷卡）');
  }
  for (const item of componentOptions) {
    const component = teacher.components?.[item.key];
    if (component?.reason) reasons.push(`${item.label}：${component.reason}`);
  }
  if (teacher.missing_fields?.length) reasons.push(`缺少：${teacher.missing_fields.join('、')}`);
  if (teacher.settlement?.pending_items?.length) {
    reasons.push(`待確認：${teacher.settlement.pending_items.map(item => item.label).join('、')}`);
  }
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
.salary-cell { white-space:nowrap; }
.salary-input { width:90px; padding:6px 8px; border:1px solid var(--border); border-radius:6px; background:var(--ds-canvas); color:inherit; margin-right:4px; }
.total-cell strong { font-variant-numeric:tabular-nums; color:var(--ds-primary); }
.teacher-card-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }.teacher-card-header small { display:block; margin-top:4px; color:var(--ds-ink-mute); font-size:12px; }.teacher-components { margin-top:14px; border-top:1px solid var(--border); }.component-row { display:flex; justify-content:space-between; gap:12px; padding:10px 0; border-bottom:1px solid var(--border); }.component-value { display:flex; flex-direction:column; align-items:flex-end; gap:4px; }.component-value small { color:var(--ds-ink-mute); font-size:12px; }.mobile-reason { margin:12px 0 0; color:var(--ds-ink-mute); font-size:13px; line-height:1.6; }
@media (max-width: 900px) { .eligibility-page { padding:16px; }.summary-grid { grid-template-columns:repeat(2, minmax(0,1fr)); }.policy-chip { margin-left:0; }.desktop-table { display:none; }.mobile-list { display:grid; gap:12px; } }
</style>
