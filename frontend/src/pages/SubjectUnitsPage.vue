<template>
  <div>
    <!-- Month Filter -->
    <div class="card" style="margin-bottom: 20px;" data-guide="subject-units-header">
      <div class="header-actions">
        <h2>📐 科目數統計</h2>
        <div class="header-controls">
          <div class="branch-selector">
            <label>分校</label>
            <select v-model="selectedBranchId" @change="loadData">
              <option
                v-for="b in branchOptions"
                :key="b.id"
                :value="b.id"
              >
                {{ b.name }}
              </option>
            </select>
          </div>
          <div class="month-selector">
          <button class="ghost small" @click="changeMonth(-1)">◀</button>
          <span class="month-label">{{ monthLabel }}</span>
          <button class="ghost small" @click="changeMonth(1)">▶</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-if="!loading && teacherList.length === 0" class="card">
      <div class="empty-state-large">
        <div class="empty-icon">📐</div>
        <h3>尚無資料</h3>
        <p>請先在「學生管理」中為學生建立課程設定，系統才能計算科目數。</p>
        <p class="hint">計算規則：一對一 × 1.5、一對二 × 0.75、一對三 × 0.5、輔導 × 0.5，總科目數 ÷ 8</p>
      </div>
    </div>

    <!-- Summary Cards -->
    <div v-if="teacherList.length > 0" class="summary-cards" data-guide="subject-units-summary">
      <div class="summary-card">
        <div class="summary-label">總上課時數</div>
        <div class="summary-value">{{ totals.totalHours }}</div>
        <div class="summary-sub">
          一對一 {{ totals.oneOnOneHours }}h ｜ 一對二 {{ totals.oneOnTwoHours }}h ｜ 一對三 {{ totals.oneOnThreeHours }}h ｜ 輔導 {{ totals.tutoringHours }}h
        </div>
      </div>
      <div class="summary-card accent">
      <div class="summary-label">本校總科目數（含輔導）</div>
        <div class="summary-value">{{ totals.subjectCountWith }}</div>
        <div class="summary-sub">加權總分: {{ totals.totalUnitsWithTutoring }}</div>
      </div>
      <div class="summary-card primary">
        <div class="summary-label">本校總科目數（不含輔導）</div>
        <div class="summary-value">{{ totals.subjectCountWithout }}</div>
        <div class="summary-sub">加權總分: {{ totals.totalUnitsWithoutTutoring }}</div>
      </div>
    </div>

    <!-- Subject-count calculation (matches GET /api/v1/finance/subject-units) -->
    <div v-if="teacherList.length > 0" class="card calc-guide" data-guide="subject-units-formula">
      <div class="calc-guide-header" @click="showCalcGuide = !showCalcGuide">
        <h3>📎 科目數計算方式</h3>
        <button type="button" class="ghost small">{{ showCalcGuide ? '收合 ▲' : '展開 ▼' }}</button>
      </div>
      <div v-if="showCalcGuide" class="calc-guide-body">
        <p class="calc-guide-lead">
          本頁「科目數」與「加權總分」由後端依下列規則計算，與薪資報表口徑一致。
        </p>
        <ul class="calc-guide-list">
          <li>
            <strong>資料範圍</strong>：所選月份、分校內，學習評量狀態為「已審核通過（approved）」的紀錄。
          </li>
          <li>
            <strong>上課時數（各堂別欄位）</strong>：優先依評量上的開始／結束時間換算為小時；若無法推算，則使用該學生課程設定的單堂分鐘數（SessionDuration）換算；仍無資料時預設每堂 2 小時。
          </li>
          <li>
            <strong>加權總分（含輔導）</strong>：
            一對一×1.5 ＋ 一對二×0.75 ＋ 一對三×0.5 ＋ 輔導×0.5。
          </li>
          <li>
            <strong>加權總分（不含輔導）</strong>：同上前三項之和，<strong>不</strong>將輔導時數列入加權。
          </li>
          <li>
            <strong>科目數</strong>＝ 對應加權總分 ÷ <strong>8</strong>。
            摘要卡上的大數字即為此商；「加權總分」即除法前的分子。
          </li>
        </ul>
        <p class="calc-guide-example" v-if="totals.totalUnitsWithTutoring > 0 || totals.totalUnitsWithoutTutoring > 0">
          <strong>本月合計對照</strong>：
          含輔導科目數 {{ totals.subjectCountWith }}
          ＝ 加權總分 {{ totals.totalUnitsWithTutoring }} ÷ 8；
          不含輔導科目數 {{ totals.subjectCountWithout }}
          ＝ 加權總分 {{ totals.totalUnitsWithoutTutoring }} ÷ 8。
        </p>
      </div>
    </div>

    <!-- Teacher Breakdown Table -->
    <div v-if="teacherList.length > 0" class="card" data-guide="subject-units-table">
      <h3>👨‍🏫 老師科目數明細</h3>
      <table>
        <thead>
         <tr>
            <th>老師</th>
            <th>一對一 (h)</th>
            <th>一對二 (h)</th>
            <th>一對三 (h)</th>
            <th>輔導 (h)</th>
            <th>總時數</th>
            <th>科目數（含輔導）</th>
            <th>科目數（不含輔導）</th>
            <th>佔比</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in teacherList" :key="t.name">
            <td><strong>{{ t.name }}</strong></td>
            <td>{{ t.oneOnOneHours }}</td>
            <td>{{ t.oneOnTwoHours }}</td>
            <td>{{ t.oneOnThreeHours }}</td>
            <td>{{ t.tutoringHours }}</td>
            <td style="font-weight: 600;">{{ t.totalHours }}</td>
            <td style="font-weight: 700; color: var(--primary);">{{ t.unitsWith }}</td>
            <td style="font-weight: 700; color: var(--accent);">{{ t.unitsWithout }}</td>
            <td style="width: 200px;">
              <div class="progress-bar-wrap">
                <div class="progress-bar" :style="{ width: t.pct + '%' }"></div>
                <span class="progress-label">{{ t.pct }}%</span>
              </div>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td><strong>合計</strong></td>
            <td>{{ totals.oneOnOneHours }}</td>
            <td>{{ totals.oneOnTwoHours }}</td>
            <td>{{ totals.oneOnThreeHours }}</td>
            <td>{{ totals.tutoringHours }}</td>
            <td style="font-weight: 700;">{{ totals.totalHours }}</td>
            <td style="font-weight: 800; color: var(--primary);">{{ totals.subjectCountWith }}</td>
            <td style="font-weight: 800; color: var(--accent);">{{ totals.subjectCountWithout }}</td>
            <td>100%</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Level Breakdown -->
    <div v-if="levelBreakdownTotals.length > 0" class="card" style="margin-top: 20px;">
      <div class="level-breakdown-header" @click="showLevelBreakdown = !showLevelBreakdown">
        <h3>📊 學段分解（國小/國中/高中）</h3>
        <button class="ghost small">{{ showLevelBreakdown ? '收合 ▲' : '展開 ▼' }}</button>
      </div>

      <div v-if="showLevelBreakdown">
        <div class="level-summary-cards">
          <div v-for="lb in levelBreakdownTotals" :key="'lvl-total-'+lb.level" class="summary-card level-card">
            <div class="summary-label">{{ lb.levelLabel }}</div>
            <div class="summary-value" style="font-size: 24px;">{{ lb.totalHours }}h</div>
            <div class="summary-sub">
              科目數（含輔導）: {{ lb.unitsWith }} ｜ 科目數（不含）: {{ lb.unitsWithout }}
            </div>
          </div>
        </div>

        <table style="margin-top: 12px;">
          <thead>
            <tr>
              <th>老師</th>
              <th v-for="lb in levelBreakdownTotals" :key="'lvl-th-h-'+lb.level">{{ lb.levelLabel }} 時數</th>
              <th v-for="lb in levelBreakdownTotals" :key="'lvl-th-u-'+lb.level">{{ lb.levelLabel }} 科目數</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in teacherList" :key="'lvl-'+t.name">
              <td><strong>{{ t.name }}</strong></td>
              <td v-for="lb in levelBreakdownTotals" :key="'lvl-h-'+t.name+'-'+lb.level">
                {{ (t.levelBreakdown.find(x => x.level === lb.level) || {}).totalHours || 0 }}
              </td>
              <td v-for="lb in levelBreakdownTotals" :key="'lvl-u-'+t.name+'-'+lb.level" style="font-weight: 600; color: var(--primary);">
                {{ (t.levelBreakdown.find(x => x.level === lb.level) || {}).unitsWith || 0 }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { supabase } from '../supabase';
import { branches, loadBranches } from '../lib/useBranches';

const props = defineProps({
  branchId: [String, Number],
  userRole: {
    type: String,
    default: '',
  },
});

const loading = ref(true);
const teacherList = ref([]);
const levelBreakdownTotals = ref([]);
const showLevelBreakdown = ref(false);
const showCalcGuide = ref(true);
const totals = ref({
  oneOnOneHours: 0, oneOnTwoHours: 0, oneOnThreeHours: 0, tutoringHours: 0,
  totalHours: 0, totalUnitsWithTutoring: 0, totalUnitsWithoutTutoring: 0,
  subjectCountWith: '0.00', subjectCountWithout: '0.00'
});
const currentDate = ref(new Date());
const selectedBranchId = ref(props.branchId ? Number(props.branchId) : null);
const sessionCampusIds = computed(() => {
  try {
    const raw = localStorage.getItem('alltrue_session') || '{}';
    const sess = JSON.parse(raw);
    const ids = Array.isArray(sess?.user?.campuses) ? sess.user.campuses : [];
    return ids.map((id) => Number(id)).filter((id) => Number.isFinite(id));
  } catch (_) {
    return [];
  }
});
const branchOptions = computed(() =>
  (branches.value || [])
    .filter((b) => {
      const bid = Number(b?.id);
      if (!Number.isFinite(bid)) return false;
      if (props.userRole !== 'teacher') return true;
      // Teacher can only switch between assigned campuses (home + support campuses)
      if (sessionCampusIds.value.length === 0) return false;
      return sessionCampusIds.value.includes(bid);
    })
    .filter((b) => Number.isFinite(Number(b?.id)))
    .map((b) => ({ id: Number(b.id), name: b.name || `分校 #${b.id}` }))
);

const monthLabel = computed(() => {
  const d = currentDate.value;
  return `${d.getFullYear()} 年 ${d.getMonth() + 1} 月`;
});

const changeMonth = (delta) => {
  const d = new Date(currentDate.value);
  d.setMonth(d.getMonth() + delta);
  currentDate.value = d;
  loadData();
};

/**
 * Fetch subject-unit data from the unified backend endpoint.
 * This ensures the numbers here match the teacher-payroll report exactly.
 */
const loadData = async () => {
  loading.value = true;
  try {
    const d = currentDate.value;
    const year = d.getFullYear();
    const month = d.getMonth(); // 0-based
    const startDate = `${year}-${String(month + 1).padStart(2, '0')}-01`;
    const lastDay = new Date(year, month + 1, 0).getDate();
    const endDate = `${year}-${String(month + 1).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;

    const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const token = session?.access_token || '';
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';

    const params = new URLSearchParams({ start: startDate, end: endDate, include_level: '1' });
    if (selectedBranchId.value) {
      params.set('branch_id', String(selectedBranchId.value));
    }
    const res = await fetch(`${baseUrl}/v1/finance/subject-units?${params}`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
      }
    });

    if (!res.ok) {
      teacherList.value = [];
      return;
    }

    const json = await res.json();

    // Map backend response to template format
    teacherList.value = (json.teachers || []).map(t => ({
      name: t.teacher_name,
      oneOnOneHours: t.one_on_one_hours,
      oneOnTwoHours: t.one_on_two_hours,
      oneOnThreeHours: t.one_on_three_hours,
      tutoringHours: t.tutoring_hours,
      totalHours: t.total_hours,
      unitsWith: t.subject_count_with,
      unitsWithout: t.subject_count_without,
      pct: t.share_pct,
      levelBreakdown: (t.level_breakdown || []).map(lb => ({
        level: lb.level,
        levelLabel: lb.level_label,
        totalHours: lb.total_hours,
        unitsWith: lb.subject_count_with,
        unitsWithout: lb.subject_count_without,
      })),
    }));

    levelBreakdownTotals.value = (json.level_breakdown_totals || []).map(lb => ({
      level: lb.level,
      levelLabel: lb.level_label,
      oneOnOneHours: lb.one_on_one_hours,
      oneOnTwoHours: lb.one_on_two_hours,
      oneOnThreeHours: lb.one_on_three_hours,
      tutoringHours: lb.tutoring_hours,
      totalHours: lb.total_hours,
      unitsWith: lb.subject_count_with,
      unitsWithout: lb.subject_count_without,
    }));

    const t = json.totals || {};
    totals.value = {
      oneOnOneHours: t.one_on_one_hours || 0,
      oneOnTwoHours: t.one_on_two_hours || 0,
      oneOnThreeHours: t.one_on_three_hours || 0,
      tutoringHours: t.tutoring_hours || 0,
      totalHours: t.total_hours || 0,
      totalUnitsWithTutoring: t.weighted_with_tutoring || 0,
      totalUnitsWithoutTutoring: t.weighted_without_tutoring || 0,
      subjectCountWith: (t.subject_count_with || 0).toFixed(2),
      subjectCountWithout: (t.subject_count_without || 0).toFixed(2),
    };
  } catch (e) {
    console.error('Failed to load subject units:', e);
    teacherList.value = [];
  } finally {
    loading.value = false;
  }
};

watch(() => props.branchId, (val) => {
  const next = val ? Number(val) : null;
  if (next && next !== selectedBranchId.value) {
    selectedBranchId.value = next;
  }
  loadData();
});

onMounted(async () => {
  await loadBranches();
  if (!selectedBranchId.value && branchOptions.value.length > 0) {
    selectedBranchId.value = branchOptions.value[0].id;
  }
  if (
    selectedBranchId.value &&
    !branchOptions.value.some((b) => b.id === Number(selectedBranchId.value))
  ) {
    selectedBranchId.value = branchOptions.value.length ? branchOptions.value[0].id : null;
  }
  loadData();
});
</script>

<style scoped>
.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.header-controls {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.branch-selector {
  display: flex;
  align-items: center;
  gap: 6px;
}

.branch-selector label {
  font-size: 12px;
  color: var(--text-light);
}

.branch-selector select {
  min-width: 130px;
  padding: 6px 8px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--card-bg);
  color: var(--text);
}

.month-selector {
  display: flex;
  align-items: center;
  gap: 12px;
}

.month-label {
  font-size: 16px;
  font-weight: 700;
  min-width: 120px;
  text-align: center;
}

.summary-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
  margin-bottom: 20px;
}

.summary-card {
  background: var(--card-bg);
  border-radius: var(--radius);
  padding: 24px;
  box-shadow: var(--shadow);
  border-left: 4px solid var(--border);
}

.summary-card.accent { border-left-color: var(--accent); }
.summary-card.primary { border-left-color: var(--primary); }

.summary-label {
  font-size: 13px;
  color: var(--text-light);
  font-weight: 600;
  margin-bottom: 8px;
}

.summary-value {
  font-size: 32px;
  font-weight: 800;
  color: var(--text);
}

.summary-sub {
  font-size: 12px;
  color: var(--text-light);
  margin-top: 6px;
}

.empty-state-large {
  text-align: center;
  padding: 60px 20px;
}

.empty-icon {
  font-size: 48px;
  margin-bottom: 16px;
}

.empty-state-large h3 {
  color: var(--text-light);
  font-size: 18px;
  margin-bottom: 8px;
}

.empty-state-large p {
  color: var(--text-light);
  font-size: 14px;
}

/* Progress Bar */
.progress-bar-wrap {
  height: 22px;
  background: #ECEFF1;
  border-radius: 12px;
  position: relative;
  overflow: hidden;
}

.progress-bar {
  height: 100%;
  background: linear-gradient(90deg, var(--accent), var(--primary));
  border-radius: 12px;
  transition: width 0.6s ease;
}

.progress-label {
  position: absolute;
  right: 8px;
  top: 2px;
  font-size: 11px;
  font-weight: 700;
  color: #333;
}

table tfoot td {
  background: #FAFAFA;
  font-weight: 600;
  border-top: 2px solid var(--border);
}

.level-breakdown-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
}
.level-breakdown-header h3 {
  margin: 0;
}

.level-summary-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-top: 12px;
}
.level-card {
  border-left-color: #8b5cf6;
}

.calc-guide {
  border: 1px dashed var(--border);
  background: var(--card-bg);
  border-left: 4px solid var(--primary);
}

.calc-guide-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  user-select: none;
}

.calc-guide-header h3 {
  margin: 0;
  font-size: 16px;
}

.calc-guide-body {
  margin-top: 14px;
  padding-top: 4px;
  border-top: 1px solid var(--border);
}

.calc-guide-lead {
  margin: 0 0 10px;
  font-size: 13px;
  color: var(--text-light);
  line-height: 1.5;
}

.calc-guide-list {
  margin: 0;
  padding-left: 1.25rem;
  font-size: 13px;
  line-height: 1.65;
  color: var(--text);
}

.calc-guide-list li {
  margin-bottom: 8px;
}

.calc-guide-example {
  margin: 14px 0 0;
  padding: 10px 12px;
  font-size: 12px;
  line-height: 1.55;
  border-radius: 8px;
  background: var(--card-bg);
  border: 1px solid var(--border);
  color: var(--text-light);
}
</style>
