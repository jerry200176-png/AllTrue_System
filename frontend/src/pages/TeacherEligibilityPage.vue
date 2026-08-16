<template>
  <div class="eligibility-page">
    <div class="page-header">
      <div class="page-header-left">
        <div class="page-icon"><span class="material-symbols-outlined">payments</span></div>
        <div class="title-group">
          <h2>正職薪資要件</h2>
          <p class="title-sub">115.07 正職結算：本薪＋16段課獎金＋科目數／一對三獎金×教師倍率</p>
        </div>
        <span class="privilege-chip">高權限存取區</span>
      </div>
      <div class="header-actions">
        <button class="btn-primary" :disabled="loading || isLocked" @click="loadData">
          <span class="material-symbols-outlined">refresh</span>{{ isLocked ? '已鎖定' : '重新結算' }}
        </button>
        <button class="btn-outline" :disabled="loading || !props.branchId" @click="exportCsv">匯出 Excel</button>
        <button v-if="!isLocked" class="btn-outline" :disabled="loading || !props.branchId || reviewCount > 0" @click="lockMonth">鎖定本月</button>
        <button v-else-if="isHq" class="btn-outline" :disabled="loading" @click="reopenMonth">重開結算</button>
      </div>
    </div>

    <div class="eligibility-card filters">
      <label>結算月份
        <input v-model="settlementMonth" type="month" @change="period = 'month'; loadData()" />
      </label>
      <label>查詢層級
        <select v-model="period" @change="loadData">
          <option value="week">本週</option>
          <option value="month">結算月</option>
          <option value="year">本年度</option>
        </select>
      </label>
      <label class="search-label">搜尋老師
        <input v-model.trim="search" placeholder="姓名" />
      </label>
      <span class="policy-chip">本月分校總科目數 {{ formatSubjects(branchSubjectTotal) }} 科｜制度 {{ policyVersion }}｜{{ lockLabel }}</span>
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
            <tr>
              <th>老師姓名</th>
              <th>固定底薪</th>
              <th>正課科目數</th>
              <th>輔導＋試聽科目數</th>
              <th>核薪總科目數</th>
              <th>一對三總計</th>
              <th>科目數獎金</th>
              <th>一對三獎金</th>
              <th>教師倍率</th>
              <th>倍率後獎金</th>
              <th>加扣款</th>
              <th>總發放金額</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="teacher in filteredTeachers" :key="teacher.teacher_id">
              <td>
                <strong>{{ teacher.teacher_name }}</strong>
                <small>老師</small>
                <span :class="['status', statusClass(teacher.overall_status)]">{{ statusLabel(teacher) }}</span>
              </td>
              <td class="salary-cell">
                <template v-if="editingSalaryId === teacher.teacher_id">
                  <input v-model.number="salaryDraft" type="number" min="0" step="1" class="salary-input" />
                  <button class="btn-outline small" :disabled="savingSalary" @click="saveSalary(teacher)">存</button>
                  <button class="btn-outline small" @click="editingSalaryId = null">取消</button>
                </template>
                <template v-else>
                  <span class="money-pos">{{ formatMoney(teacher.settlement?.base_salary) }}</span>
                  <small v-if="teacher.pending_salary" class="pending-salary">待核准 {{ formatMoney(teacher.pending_salary.base_salary) }}</small>
                  <button v-if="!isLocked" class="btn-outline small" @click="startEditSalary(teacher)">改</button>
                  <button v-if="isHq && teacher.pending_salary && !isLocked" class="btn-outline small" :disabled="savingSalary" @click="approveSalary(teacher)">核准底薪</button>
                </template>
              </td>
              <td>{{ formatSubjects(teacher.settlement?.regular_subject_count) }}</td>
              <td>{{ formatSubjects(teacher.settlement?.tutoring_trial_subject_count) }}</td>
              <td>{{ formatSubjects(teacher.settlement?.payroll_subject_count) }}</td>
              <td>{{ formatSubjects(teacher.settlement?.one_to_three_count) }}</td>
              <td>{{ formatMoney(teacher.settlement?.subject_count_bonus) }}</td>
              <td>{{ formatMoney(teacher.settlement?.one_to_three_bonus) }}</td>
              <td>
                <strong>{{ teacher.settlement?.multiplier_pct ?? 100 }}%</strong>
                <small v-for="part in visibleMultiplierParts(teacher)" :key="part.key">{{ part.label }} {{ formatPct(part.pct) }}</small>
              </td>
              <td>
                <strong>{{ formatMoney(teacher.settlement?.weighted_bonus_amount) }}</strong>
                <small>{{ weightedBonusFormula(teacher.settlement) }}</small>
              </td>
              <td>
                <span
                  v-for="item in teacher.settlement?.adjustments || []"
                  :key="item.label"
                  :class="['adj-chip', Number(item.amount) >= 0 ? 'pos' : 'neg']"
                >{{ item.label }} {{ Number(item.amount) >= 0 ? '+' : '' }}{{ Math.round(Number(item.amount)) }}</span>
                <small v-if="!(teacher.settlement?.adjustments || []).length">—</small>
              </td>
              <td class="total-cell">
                <strong>{{ formatMoney(teacher.settlement?.total_payout) }}</strong>
                <small v-if="isDraft(teacher)">試算，未可發放</small>
              </td>
            </tr>
            <tr v-if="filteredTeachers.length === 0"><td colspan="12" class="empty">查詢期間沒有符合條件的正職老師資料。</td></tr>
          </tbody>
        </table>
      </div>
      <div class="mobile-list">
        <article v-for="teacher in filteredTeachers" :key="teacher.teacher_id" class="eligibility-card teacher-card">
          <div class="teacher-card-header">
            <div><strong>{{ teacher.teacher_name }}</strong><small>老師</small></div>
            <span :class="['status', statusClass(teacher.overall_status)]">{{ statusLabel(teacher) }}</span>
          </div>
          <div class="component-row"><span>固定底薪</span><span class="component-value money-pos">{{ formatMoney(teacher.settlement?.base_salary) }}</span></div>
          <div class="component-row"><span>核薪總科目數</span><span class="component-value">{{ formatSubjects(teacher.settlement?.payroll_subject_count) }}</span></div>
          <div class="component-row"><span>科目數獎金</span><span class="component-value">{{ formatMoney(teacher.settlement?.subject_count_bonus) }}</span></div>
          <div class="component-row"><span>一對三獎金</span><span class="component-value">{{ formatMoney(teacher.settlement?.one_to_three_bonus) }}</span></div>
          <div class="component-row"><span>教師倍率</span><span class="component-value">{{ teacher.settlement?.multiplier_pct ?? 100 }}%</span></div>
          <div class="component-row"><span>倍率後獎金</span><span class="component-value">{{ formatMoney(teacher.settlement?.weighted_bonus_amount) }}</span></div>
          <div class="component-row total-cell"><span>總發放金額</span><strong>{{ formatMoney(teacher.settlement?.total_payout) }}</strong></div>
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
      <p class="footnote">資料不足時仍會列出試算金額並標「待人工確認／試算」，不能鎖定發放。全勤／勞健保尚未列入自動計算。</p>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { fetchTeacherEligibility, saveTeacherSalaryProfile, approveTeacherSalaryProfile, lockFulltimePayroll, reopenFulltimePayroll, exportFulltimePayrollCsv } from '../lib/teacherEligibilityApi.js';
import TeacherEligibilityInputPanel from '../components/TeacherEligibilityInputPanel.vue';
import {
  formatMoney,
  formatPct,
  formatSubjects,
  monthWindow,
  weightedBonusFormula,
} from '../lib/teacherEligibilityDisplay.js';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: '' },
});
const period = ref('month');
const now = new Date();
const settlementMonth = ref(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);
const search = ref('');
const loading = ref(false);
const error = ref('');
const teachers = ref([]);
const periodWindow = ref({ start: '', end: '' });
const policyVersion = ref('115.07');
const branchSubjectTotal = ref(0);
const lockState = ref({ status: 'draft' });
const editingSalaryId = ref(null);
const salaryDraft = ref(0);
const savingSalary = ref(false);
const componentOptions = [
  { key: 'weekly_16_segments', label: '每週16段' },
  { key: 'holiday_16_hours', label: '假日16小時' },
  { key: 'weekday_afternoon', label: '平日下午課' },
  { key: 'special_performance', label: '特殊表現' },
  { key: 'deductions', label: '扣除案件' },
  { key: 'admin_allowance', label: '行政加給' },
  { key: 'subject_count_bonus', label: '科目數獎金' },
];

const isHq = computed(() => props.userRole === 'super_admin');
const isLocked = computed(() => lockState.value?.status === 'locked');
const lockLabel = computed(() => (isLocked.value ? '已鎖定結算單' : '草稿結算單'));

const filteredTeachers = computed(() => teachers.value.filter(teacher => !search.value || teacher.teacher_name.includes(search.value)));
const reviewCount = computed(() => filteredTeachers.value.filter(t => t.review_required).length);
const qualifyingCount = computed(() => filteredTeachers.value.filter((teacher) => componentOptions.some((item) => {
  const component = teacher.components?.[item.key];
  return component?.status === 'qualifies' && (Number(component.rate) > 0 || Number(component.amount) > 0);
})).length);
const deductionCount = computed(() => filteredTeachers.value.filter(t => Number(t.components?.deductions?.rate || 0) < 0).length);

function visibleMultiplierParts(teacher) {
  return (teacher.settlement?.multiplier_parts || []).filter((part) => Number(part.pct) !== 0);
}

function isDraft(teacher) {
  return Boolean(teacher.review_required || teacher.settlement?.payout_is_draft);
}

async function exportCsv() {
  if (!props.branchId) return;
  try {
    await exportFulltimePayrollCsv({ month: settlementMonth.value, branchId: props.branchId });
  } catch (e) {
    error.value = e?.message || '匯出失敗';
  }
}

async function lockMonth() {
  if (!props.branchId) return;
  if (!window.confirm('鎖定後本月金額凍結。仍有試算列時不能鎖定。')) return;
  try {
    await lockFulltimePayroll({ month: settlementMonth.value, branchId: props.branchId });
    await loadData();
  } catch (e) {
    error.value = e?.message || '鎖定失敗';
  }
}

async function reopenMonth() {
  const reason = window.prompt('請填重開原因（總部）');
  if (!reason) return;
  try {
    await reopenFulltimePayroll({ month: settlementMonth.value, branchId: props.branchId, reason });
    await loadData();
  } catch (e) {
    error.value = e?.message || '重開失敗';
  }
}

function startEditSalary(teacher) {
  editingSalaryId.value = teacher.teacher_id;
  salaryDraft.value = Math.round(Number(teacher.settlement?.base_salary ?? 0));
}

async function saveSalary(teacher) {
  savingSalary.value = true;
  try {
    const saved = await saveTeacherSalaryProfile({
      teacher_id: teacher.teacher_id,
      branch_id: props.branchId || null,
      base_salary: salaryDraft.value,
      effective_from: periodWindow.value.start || `${settlementMonth.value}-01`,
    });
    editingSalaryId.value = null;
    await loadData();
    if (saved?.status === 'pending') {
      error.value = '底薪已送出，待總部核准後才會改結算金額。';
    }
  } catch (e) {
    error.value = e?.message || '底薪儲存失敗';
  } finally {
    savingSalary.value = false;
  }
}

async function approveSalary(teacher) {
  if (!teacher.pending_salary?.id) return;
  savingSalary.value = true;
  try {
    await approveTeacherSalaryProfile(teacher.pending_salary.id);
    await loadData();
  } catch (e) {
    error.value = e?.message || '底薪核准失敗';
  } finally {
    savingSalary.value = false;
  }
}

async function loadData() {
  loading.value = true; error.value = '';
  try {
    const range = period.value === 'month' ? monthWindow(settlementMonth.value) : {};
    const data = await fetchTeacherEligibility({
      period: period.value,
      branchId: props.branchId,
      start: range.start,
      end: range.end,
    });
    teachers.value = data.teachers || [];
    periodWindow.value = { start: data.period?.start || range.start || '', end: data.period?.end || range.end || '' };
    policyVersion.value = data.policy_version || policyVersion.value;
    branchSubjectTotal.value = Number(data.branch_subject_total ?? 0);
    lockState.value = data.lock || { status: 'draft' };
  } catch (e) { error.value = e?.message || '載入失敗'; }
  finally { loading.value = false; }
}

function statusLabel(teacher) {
  if (isDraft(teacher)) return '試算';
  return { qualifies: '符合', not_qualifies: '不符合', review: '待人工確認' }[teacher.overall_status] || '未判定';
}
function statusClass(status) { return { qualifies: 'pass', not_qualifies: 'fail', review: 'review' }[status] || 'unknown'; }
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
  return reasons.join('；');
}

watch(() => props.branchId, loadData);
onMounted(loadData);
</script>

<style scoped>
.eligibility-page { padding: 24px; max-width: 1800px; margin: 0 auto; }
.page-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:18px; }
.page-header-left { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.page-icon { width:44px; height:44px; border-radius:12px; display:grid; place-items:center; background:var(--ds-primary-wash); color:var(--ds-primary); }
.title-group h2 { margin:0; }.title-sub { margin:4px 0 0; color:var(--ds-ink-mute); }
.privilege-chip { padding:4px 10px; border-radius:999px; background:var(--ds-warning-wash); color:var(--ds-warning); font-size:12px; }
.header-actions { display:flex; gap:8px; flex-wrap:wrap; }
.btn-primary { display:inline-flex; align-items:center; gap:6px; border:0; background:var(--ds-primary); color:var(--ds-on-primary); border-radius:8px; padding:9px 14px; cursor:pointer; }
.btn-outline { display:inline-flex; align-items:center; gap:6px; border:1px solid var(--border); background:var(--ds-canvas); color:inherit; border-radius:8px; padding:9px 14px; cursor:pointer; }
.btn-outline.small { padding:4px 8px; font-size:12px; }
.eligibility-card { background:var(--ds-canvas); border:1px solid var(--border); border-radius:14px; padding:16px; box-shadow:var(--ds-shadow-1); }
.filters { display:flex; align-items:end; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
.filters label { display:flex; flex-direction:column; gap:6px; font-size:13px; color:var(--ds-ink-mute); }
.filters select,.filters input { min-width:150px; padding:9px 10px; border:1px solid var(--border); border-radius:8px; background:var(--ds-canvas); color:inherit; }
.search-label input { min-width:220px; }.policy-chip { margin-left:auto; padding:8px 12px; color:var(--ds-ink-mute); background:var(--ds-canvas-soft); border-radius:8px; font-size:13px; }
.summary-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; margin-bottom:18px; }
.summary-card { padding:16px; background:var(--ds-canvas); border:1px solid var(--border); border-radius:12px; }.summary-card span { color:var(--ds-ink-mute); font-size:13px; }.summary-card strong { display:block; font-size:28px; margin-top:6px; }.summary-card.success strong { color:var(--ds-success); }.summary-card.warning strong { color:var(--ds-warning); }.summary-card.danger strong { color:var(--ds-danger); }
.table-wrap { overflow:auto; }.table-wrap table { width:100%; border-collapse:collapse; min-width:1280px; }.table-wrap th,.table-wrap td { padding:12px 10px; border-bottom:1px solid var(--border); text-align:left; vertical-align:top; }.table-wrap th { color:var(--ds-ink-mute); font-size:13px; background:var(--ds-canvas-soft); white-space:nowrap; }.table-wrap td small { display:block; margin-top:4px; color:var(--ds-ink-mute); font-size:12px; }.status { display:inline-flex; margin-top:6px; padding:4px 8px; border-radius:999px; font-size:12px; white-space:nowrap; }.status.pass { background:var(--ds-success-wash); color:var(--ds-success); }.status.fail { background:var(--ds-danger-wash); color:var(--ds-danger); }.status.review { background:var(--ds-warning-wash); color:var(--ds-warning); }.status.unknown { background:var(--ds-canvas-soft); color:var(--ds-ink-mute); }.empty,.loading { text-align:center; color:var(--ds-ink-mute); padding:36px; }.error { color:var(--ds-danger); display:flex; align-items:center; gap:12px; }.footnote { color:var(--ds-ink-mute); font-size:13px; margin:12px 4px; }.mobile-list { display:none; }
.salary-cell { white-space:nowrap; }
.pending-salary { color: var(--ds-warning); }
.salary-input { width:90px; padding:6px 8px; border:1px solid var(--border); border-radius:6px; background:var(--ds-canvas); color:inherit; margin-right:4px; }
.money-pos { color:var(--ds-success); font-variant-numeric:tabular-nums; }
.total-cell strong { font-variant-numeric:tabular-nums; color:var(--ds-primary); }
.adj-chip { display:inline-flex; margin:0 6px 6px 0; padding:3px 8px; border-radius:999px; font-size:12px; }
.adj-chip.pos { background:var(--ds-success-wash); color:var(--ds-success); }
.adj-chip.neg { background:var(--ds-danger-wash); color:var(--ds-danger); }
.teacher-card-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }.teacher-card-header small { display:block; margin-top:4px; color:var(--ds-ink-mute); font-size:12px; }.component-row { display:flex; justify-content:space-between; gap:12px; padding:10px 0; border-bottom:1px solid var(--border); }.component-value { display:flex; flex-direction:column; align-items:flex-end; gap:4px; }.component-value small { color:var(--ds-ink-mute); font-size:12px; }.mobile-reason { margin:12px 0 0; color:var(--ds-ink-mute); font-size:13px; line-height:1.6; }
@media (max-width: 900px) { .eligibility-page { padding:16px; }.summary-grid { grid-template-columns:repeat(2, minmax(0,1fr)); }.policy-chip { margin-left:0; }.desktop-table { display:none; }.mobile-list { display:grid; gap:12px; } }
</style>
