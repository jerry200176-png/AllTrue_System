<template>
  <div class="card">
    <div class="tr-header">
      <div>
        <h2>當月學收</h2>
        <p class="tr-subtitle">依已上課堂數 × 費率試算各學生當月學收</p>
      </div>
      <div class="tr-controls">
        <div class="tr-month-picker">
          <button class="tr-arrow" @click="prevMonth" title="上一月">
            <span class="material-symbols-outlined">chevron_left</span>
          </button>
          <span class="tr-month-label">{{ year }} 年 {{ month }} 月</span>
          <button class="tr-arrow" @click="nextMonth" title="下一月">
            <span class="material-symbols-outlined">chevron_right</span>
          </button>
        </div>
        <button class="primary" @click="loadData" :disabled="loading">
          <span class="material-symbols-outlined" style="font-size:18px">refresh</span>
          重新整理
        </button>
        <button class="ghost" @click="exportCsv" :disabled="loading || !rows.length" title="匯出為 CSV">
          <span class="material-symbols-outlined" style="font-size:18px">download</span>
          匯出 CSV
        </button>
      </div>
    </div>

    <div v-if="loading" class="tr-loading">
      <span class="material-symbols-outlined spin">progress_activity</span>
      載入中…
    </div>

    <div v-else-if="error" class="tr-error">{{ error }}</div>

    <template v-else>
      <div class="tr-stats" v-if="rows.length">
        <span class="tr-stat">
          學生 <strong>{{ summary.total_students }}</strong> 人
        </span>
        <span class="tr-stat">
          堂數 <strong>{{ summary.total_sessions }}</strong> 堂
        </span>
        <span class="tr-stat tr-stat--primary">
          學收合計 <strong>{{ formatAmount(summary.total_tuition) }}</strong>
        </span>
      </div>

      <div v-if="!rows.length" class="tr-empty">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--text-light)">calendar_month</span>
        <p>本分校 {{ year }}/{{ month }} 無有效課程紀錄</p>
      </div>

      <div v-else class="tr-table-wrap">
        <table class="tr-table">
          <thead>
            <tr>
              <th>學生</th>
              <th>科目</th>
              <th class="hide-sm">老師</th>
              <th class="hide-sm">班型</th>
              <th class="num">月堂數</th>
              <th class="num">費率</th>
              <th class="num">月學收</th>
              <th class="hide-sm">繳費日期</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.student_class_id">
              <td>{{ r.student_name }}</td>
              <td>{{ r.subject }}</td>
              <td class="hide-sm">{{ r.teacher_name }}</td>
              <td class="hide-sm">
                <span class="type-tag" :class="r.class_type">{{ classTypeLabel(r.class_type) }}</span>
              </td>
              <td class="num">{{ r.monthly_sessions }}</td>
              <td class="num">{{ formatAmount(r.rate) }}</td>
              <td class="num"><strong>{{ formatAmount(r.monthly_tuition) }}</strong></td>
              <td class="hide-sm">
                <span v-if="r.last_paid_at" class="paid-date">{{ r.last_paid_at }}</span>
                <span v-else class="text-light">—</span>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td :colspan="4" class="hide-sm"><strong>合計</strong></td>
              <td class="show-sm-only"><strong>合計</strong></td>
              <td class="num"><strong>{{ summary.total_sessions }}</strong></td>
              <td class="num">—</td>
              <td class="num"><strong>{{ formatAmount(summary.total_tuition) }}</strong></td>
              <td class="hide-sm"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div v-if="meta.last_page > 1" class="tr-pagination">
        <button :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">上一頁</button>
        <span class="tr-page-info">第 {{ meta.current_page }} / {{ meta.last_page }} 頁（共 {{ meta.total }} 筆）</span>
        <button :disabled="meta.current_page >= meta.last_page" @click="goPage(meta.current_page + 1)">下一頁</button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
});

const now = new Date();
const year = ref(now.getFullYear());
const month = ref(now.getMonth() + 1);

const rows = ref([]);
const summary = ref({ total_students: 0, total_sessions: 0, total_tuition: 0 });
const meta = ref({ current_page: 1, last_page: 1, per_page: 50, total: 0 });
const loading = ref(false);
const error = ref('');
const currentPage = ref(1);

const CLASS_TYPE_LABELS = {
  one_on_one: '一對一',
  one_on_two: '一對二',
  one_on_three: '一對三',
  tutoring: '輔導',
  trial: '試聽',
};
const classTypeLabel = (t) => CLASS_TYPE_LABELS[t] || t || '—';
const formatAmount = (n) => Number(n || 0).toLocaleString('zh-TW');

function prevMonth() {
  if (month.value <= 1) { year.value--; month.value = 12; }
  else { month.value--; }
  currentPage.value = 1;
  loadData();
}
function nextMonth() {
  if (month.value >= 12) { year.value++; month.value = 1; }
  else { month.value++; }
  currentPage.value = 1;
  loadData();
}
function goPage(p) {
  currentPage.value = p;
  loadData();
}

async function loadData() {
  loading.value = true;
  error.value = '';
  try {
    const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
    const token = session?.access_token;
    if (!token) { error.value = '請先登入'; return; }

    const params = new URLSearchParams();
    if (props.branchId != null && props.branchId !== '') {
      params.set('branch_id', String(Number(props.branchId)));
    }
    params.set('year', String(year.value));
    params.set('month', String(month.value));
    params.set('page', String(currentPage.value));

    const resp = await fetch(`/api/v1/finance/branch-monthly-tuition?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) throw new Error(`載入失敗（${resp.status}）`);
    const json = await resp.json();
    rows.value = json.data || [];
    summary.value = json.summary || { total_students: 0, total_sessions: 0, total_tuition: 0 };
    meta.value = json.meta || { current_page: 1, last_page: 1, per_page: 50, total: 0 };
  } catch (e) {
    error.value = e.message || '載入失敗';
  } finally {
    loading.value = false;
  }
}

async function exportCsv() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  const token = session?.access_token;
  if (!token) { alert('請先登入'); return; }
  const params = new URLSearchParams();
  if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));
  params.set('year', String(year.value));
  params.set('month', String(month.value));
  const url = `/api/v1/finance/branch-monthly-tuition/export?${params}`;
  try {
    const resp = await fetch(url, { headers: { Authorization: `Bearer ${token}` } });
    if (!resp.ok) { alert('匯出失敗'); return; }
    const blob = await resp.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    const cd = resp.headers.get('Content-Disposition') || '';
    const match = cd.match(/filename\*?=['"]?([^'";\s]+)/);
    a.download = match ? decodeURIComponent(match[1]) : `當月學收_${year.value}${String(month.value).padStart(2,'0')}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  } catch (e) {
    alert('匯出失敗：' + e.message);
  }
}

watch(() => props.branchId, () => { currentPage.value = 1; loadData(); }, { flush: 'post' });
loadData();
</script>

<style scoped>
.tr-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.tr-header h2 { margin: 0; }
.tr-subtitle { color: var(--text-light); font-size: 13px; margin-top: 4px; }
.tr-controls {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.tr-month-picker {
  display: flex;
  align-items: center;
  gap: 4px;
  background: var(--bg);
  border-radius: 8px;
  padding: 2px;
}
.tr-arrow {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  background: transparent;
  border-radius: 6px;
  cursor: pointer;
  color: var(--text);
  transition: var(--transition);
}
.tr-arrow:hover { background: var(--border); }
.tr-arrow .material-symbols-outlined { font-size: 20px; }
.tr-month-label {
  font-size: 14px;
  font-weight: 600;
  min-width: 100px;
  text-align: center;
  white-space: nowrap;
}

.tr-loading {
  text-align: center;
  padding: 48px 0;
  color: var(--text-light);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.spin { animation: rotate 1s linear infinite; }
@keyframes rotate { to { transform: rotate(360deg); } }

.tr-error { color: var(--danger); padding: 16px 0; }
.tr-empty {
  text-align: center;
  padding: 48px 0;
  color: var(--text-light);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.tr-stats {
  display: flex;
  gap: 16px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.tr-stat {
  font-size: 14px;
  color: var(--text);
  padding: 6px 14px;
  background: var(--bg);
  border-radius: 8px;
}
.tr-stat--primary { color: var(--primary); font-weight: 600; }

.tr-table-wrap { overflow-x: auto; }
.tr-table {
  width: 100%;
  border-collapse: collapse;
}
.tr-table th {
  text-align: left;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light);
  padding: 10px 8px;
  border-bottom: 2px solid var(--border);
  white-space: nowrap;
}
.tr-table th.num,
.tr-table td.num { text-align: right; }
.tr-table td {
  padding: 10px 8px;
  border-bottom: 1px solid var(--border);
  font-size: 14px;
  vertical-align: middle;
}
.tr-table td.num { font-variant-numeric: tabular-nums; }
.tr-table tfoot td {
  border-top: 2px solid var(--border);
  border-bottom: none;
  padding-top: 12px;
  font-size: 14px;
}

.type-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}
.type-tag.one_on_one { background: #E3F2FD; color: #1565C0; }
.type-tag.one_on_two { background: #E8F5E9; color: #2E7D32; }
.type-tag.one_on_three { background: #FFF3E0; color: #E65100; }
.type-tag.tutoring { background: #F3E5F5; color: #7B1FA2; }

.tr-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  margin-top: 16px;
}
.tr-pagination button {
  padding: 6px 14px;
  border: 1px solid var(--border);
  background: var(--card-bg);
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  font-family: inherit;
  color: var(--text);
  transition: var(--transition);
}
.tr-pagination button:hover:not(:disabled) { background: var(--bg); }
.tr-pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
.tr-page-info { font-size: 13px; color: var(--text-light); }

.paid-date { color: var(--success); font-size: 13px; white-space: nowrap; }
.text-light { color: var(--text-light); }
.show-sm-only { display: none; }

@media (max-width: 768px) {
  .hide-sm { display: none; }
  .show-sm-only { display: table-cell; }
  .tr-table tfoot td[colspan="4"] { display: none; }
}
</style>
