<template>
  <div>
    <HelpGuide
      title="科目數統計 — 使用說明"
      :items="[
        '科目數依<strong>學生課程</strong>自動計算：一對一×1.5、一對二×0.75、一對三×0.5、輔導×0.5。',
        '各老師總上課時數 ÷ 8 = 科目數；可切換月份查看不同時期。',
        '資料來源為學生管理中的課程與排課，未建立課程前此頁會顯示尚無資料。'
      ]"
      tip="請先在學生管理中為學生建立課程並排課，統計資料才會產生。"
    />

    <!-- Month Filter -->
    <div class="card" style="margin-bottom: 20px;">
      <div class="header-actions">
        <h2>📐 科目數統計</h2>
        <div class="month-selector">
          <button class="ghost small" @click="changeMonth(-1)">◀</button>
          <span class="month-label">{{ monthLabel }}</span>
          <button class="ghost small" @click="changeMonth(1)">▶</button>
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
    <div v-if="teacherList.length > 0" class="summary-cards">
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

    <!-- Teacher Breakdown Table -->
    <div v-if="teacherList.length > 0" class="card">
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { supabase } from '../supabase';
import HelpGuide from '../components/HelpGuide.vue';

const props = defineProps({ branchId: [String, Number] });

const loading = ref(true);
const teacherList = ref([]);
const totals = ref({
  oneOnOneHours: 0, oneOnTwoHours: 0, oneOnThreeHours: 0, tutoringHours: 0,
  totalHours: 0, totalUnitsWithTutoring: 0, totalUnitsWithoutTutoring: 0,
  subjectCountWith: '0.00', subjectCountWithout: '0.00'
});
const currentDate = ref(new Date());

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

    const params = new URLSearchParams({ start: startDate, end: endDate });
    if (props.branchId) {
      params.set('branch_id', String(props.branchId));
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

watch(() => props.branchId, loadData);
onMounted(loadData);
</script>

<style scoped>
.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
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
</style>
