<template>
  <div class="obp-panel">
    <!-- Loading -->
    <div v-if="loading" class="obp-loading">
      <span class="material-symbols-outlined spin">progress_activity</span>
      <span>載入逾期概覽…</span>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="obp-error">
      <span class="material-symbols-outlined">error</span>
      {{ error }}
    </div>

    <template v-else>
      <!-- Summary: 4 bucket cards -->
      <div class="obp-buckets" v-if="buckets.length">
        <button
          v-for="b in buckets"
          :key="b.key"
          class="obp-bucket-card"
          :class="[`obp-${b.key}`, { 'obp-active': activeBucket === b.key }]"
          @click="drillDown(b.key)"
          :aria-label="`${b.label}：${b.count} 人，${formatCurrency(b.total_outstanding)}`"
        >
          <span class="obp-bucket-icon">{{ bucketIcon(b.key) }}</span>
          <span class="obp-bucket-count">{{ b.count }}<small> 人</small></span>
          <span class="obp-bucket-amount">{{ formatCurrency(b.total_outstanding) }}</span>
          <span class="obp-bucket-label">{{ b.label }}</span>
        </button>
      </div>

      <!-- Drill-down table -->
      <div v-if="activeBucket && drillLoading" class="obp-loading obp-loading--small">
        <span class="material-symbols-outlined spin">progress_activity</span>
        <span>載入明細…</span>
      </div>

      <div v-else-if="activeBucket" class="obp-drilldown">
        <div class="obp-drilldown-header">
          <h4>{{ activeBucketLabel }} — {{ drillRows.length }} 筆</h4>
          <button class="ghost" @click="activeBucket = null">
            <span class="material-symbols-outlined" style="font-size:16px">arrow_back</span>
            回概覽
          </button>
        </div>

        <div v-if="!drillRows.length" class="obp-empty">
          <span class="material-symbols-outlined" style="font-size:40px;color:var(--text-light)">inbox</span>
          <p>此分類目前無資料</p>
        </div>

        <div v-else class="obp-table-wrap">
          <table class="obp-table">
            <thead>
              <tr>
                <th>學生</th>
                <th>科目</th>
                <th>班級</th>
                <th class="obp-col-date">到期日</th>
                <th class="obp-col-num">逾期天數</th>
                <th class="obp-col-amount">應繳</th>
                <th class="obp-col-amount">未結清</th>
                <th>狀態</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="r in drillRows"
                :key="r.id"
                :class="overdueRowClass(r.overdue_days)"
              >
                <td class="obp-cell-name">{{ r.student_name }}</td>
                <td>{{ r.subject }}</td>
                <td>{{ r.class_name || '—' }}</td>
                <td class="obp-col-date">{{ r.due_date || '—' }}</td>
                <td class="obp-col-num">
                  <span v-if="r.overdue_days != null" :class="overdueDaysClass(r.overdue_days)">
                    {{ r.overdue_days > 0 ? `逾期 ${r.overdue_days} 天` : r.overdue_days === 0 ? '今日到期' : `${Math.abs(r.overdue_days)} 天後到期` }}
                  </span>
                  <span v-else class="obp-na">—</span>
                </td>
                <td class="obp-col-amount">{{ formatCurrency(r.total_amount) }}</td>
                <td class="obp-col-amount" :class="{ 'obp-outstanding': (r.total_amount - (r.paid_amount || 0)) > 0 }">
                  {{ formatCurrency(r.total_amount - (r.paid_amount || 0)) }}
                </td>
                <td>
                  <span class="obp-status-tag" :class="statusClass(r.status)">
                    {{ statusLabel(r.status) }}
                  </span>
                </td>
                <td>
                  <button
                    class="obp-action-btn"
                    @click="$emit('openLedger', r)"
                    title="查看帳務對帳"
                  >
                    <span class="material-symbols-outlined">account_balance</span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="!buckets.length && !loading" class="obp-empty">
        <span class="material-symbols-outlined" style="font-size:52px;color:var(--success)">check_circle</span>
        <p>無逾期或即將到期帳單</p>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  refreshTrigger: { type: Number, default: 0 },
});
const emit = defineEmits(['openLedger']);

const loading = ref(false);
const error = ref('');
const buckets = ref([]);
const activeBucket = ref(null);
const drillRows = ref([]);
const drillLoading = ref(false);

const BUCKET_COLORS = {
  upcoming: { label: '即將到期', icon: '🟢' },
  mild: { label: '逾期 1-7 天', icon: '🟡' },
  moderate: { label: '逾期 8-30 天', icon: '🟠' },
  severe: { label: '逾期 >30 天', icon: '🔴' },
};

const activeBucketLabel = computed(() => {
  if (!activeBucket.value) return '';
  return BUCKET_COLORS[activeBucket.value]?.label || activeBucket.value;
});

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

function formatCurrency(v) {
  if (v == null || isNaN(v)) return '—';
  return 'NT$ ' + Number(v).toLocaleString('zh-TW');
}

function bucketIcon(key) {
  return BUCKET_COLORS[key]?.icon || '📋';
}

function statusLabel(s) {
  const labels = { unpaid: '未繳費', partial: '部分付款', paid: '已繳費' };
  return labels[s] || s || '—';
}

function statusClass(s) {
  if (s === 'unpaid') return 'st-unpaid';
  if (s === 'partial') return 'st-partial';
  if (s === 'paid') return 'st-paid';
  return '';
}

function overdueDaysClass(days) {
  if (days > 30) return 'od-severe';
  if (days > 7) return 'od-moderate';
  if (days > 0) return 'od-mild';
  if (days >= -3) return 'od-upcoming';
  return '';
}

function overdueRowClass(days) {
  if (days > 30) return 'odr-severe';
  if (days > 7) return 'odr-moderate';
  if (days > 0) return 'odr-mild';
  return '';
}

// ─── Load summary ───
async function loadSummary() {
  loading.value = true;
  error.value = '';
  activeBucket.value = null;
  drillRows.value = [];
  try {
    const token = getToken();
    if (!token) { error.value = '請先登入'; return; }

    const params = new URLSearchParams();
    if (props.branchId != null && props.branchId !== '') {
      params.set('campus_id', String(Number(props.branchId)));
    }

    const resp = await fetch(`/api/v1/invoices/overdue-summary?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `載入失敗（${resp.status}）`);
    }
    const json = await resp.json();
    buckets.value = json.buckets || [];
  } catch (e) {
    error.value = e.message || '載入失敗';
  } finally {
    loading.value = false;
  }
}

// ─── Drill down ───
async function drillDown(bucketKey) {
  activeBucket.value = bucketKey;
  drillRows.value = [];
  drillLoading.value = true;
  try {
    const token = getToken();
    const params = new URLSearchParams({ overdue_bucket: bucketKey, per_page: '200' });
    if (props.branchId != null && props.branchId !== '') {
      params.set('campus_id', String(Number(props.branchId)));
    }

    const resp = await fetch(`/api/v1/invoices?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `載入失敗（${resp.status}）`);
    }
    const json = await resp.json();
    drillRows.value = Array.isArray(json?.data) ? json.data : Array.isArray(json) ? json : [];
  } catch (e) {
    drillRows.value = [];
  } finally {
    drillLoading.value = false;
  }
}

// ─── Watchers ───
watch(() => props.branchId, () => { loadSummary(); });
watch(() => props.refreshTrigger, () => { loadSummary(); });

loadSummary();
</script>

<style scoped>
.obp-panel { display: flex; flex-direction: column; gap: 16px; }

.obp-loading {
  text-align: center; padding: 48px 0; color: var(--text-light);
  display: flex; flex-direction: column; align-items: center; gap: 10px; font-size: 14px;
}
.obp-loading--small { padding: 24px 0; flex-direction: row; }
.spin { animation: rotate 1s linear infinite; font-size: 28px; }
@keyframes rotate { to { transform: rotate(360deg); } }

.obp-error {
  display: flex; align-items: center; gap: 8px;
  color: var(--ds-danger); padding: 14px 16px;
  background: var(--ds-danger-wash); border-radius: 10px; font-size: 14px;
}

/* Bucket cards */
.obp-buckets {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
}
.obp-bucket-card {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  padding: 18px 12px; border-radius: 12px;
  border: 2px solid var(--border); background: var(--card-bg);
  cursor: pointer; transition: all 0.15s;
  text-align: center; font-family: inherit; font-size: inherit;
}
.obp-bucket-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
.obp-bucket-card.obp-active { border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary); }

.obp-bucket-icon { font-size: 28px; }
.obp-bucket-count { font-size: 28px; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--text); }
.obp-bucket-count small { font-size: 14px; font-weight: 500; color: var(--text-light); }
.obp-bucket-amount { font-size: 16px; font-weight: 600; font-variant-numeric: tabular-nums; }
.obp-bucket-label { font-size: 12px; color: var(--text-light); font-weight: 500; }

/* Color-coded cards */
.obp-upcoming { border-left: 4px solid #22c55e; }
.obp-upcoming .obp-bucket-amount { color: #16a34a; }
.obp-mild { border-left: 4px solid #eab308; }
.obp-mild .obp-bucket-amount { color: #ca8a04; }
.obp-moderate { border-left: 4px solid #f97316; }
.obp-moderate .obp-bucket-amount { color: #ea580c; }
.obp-severe { border-left: 4px solid #ef4444; }
.obp-severe .obp-bucket-amount { color: #dc2626; }

/* Drill-down */
.obp-drilldown-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px;
}
.obp-drilldown-header h4 { margin: 0; font-size: 15px; }

.obp-empty {
  text-align: center; padding: 48px 0; color: var(--text-light);
  display: flex; flex-direction: column; align-items: center; gap: 10px;
}
.obp-empty p { margin: 0; font-size: 14px; }

.obp-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; }
.obp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.obp-table thead th {
  text-align: left; padding: 9px 10px; background: var(--bg);
  font-size: 11px; font-weight: 600; color: var(--text-light);
  border-bottom: 1px solid var(--border); white-space: nowrap;
  text-transform: uppercase; letter-spacing: 0.03em;
}
.obp-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.obp-table tbody tr:last-child td { border-bottom: none; }
.obp-cell-name { font-weight: 500; }
.obp-col-date { white-space: nowrap; }
.obp-col-num { white-space: nowrap; font-weight: 500; }
.obp-col-amount { text-align: right; font-variant-numeric: tabular-nums; width: 90px; }
.obp-outstanding { color: var(--ds-danger); font-weight: 600; }
.obp-na { color: var(--text-light); }

/* Overdue row coloring */
.odr-mild td { background: var(--ds-warning-wash); }
.odr-moderate td { background: rgba(249,115,22,0.06); }
.odr-severe td { background: var(--ds-danger-wash); }

.od-upcoming { color: #16a34a; }
.od-mild { color: #ca8a04; }
.od-moderate { color: #ea580c; }
.od-severe { color: #dc2626; font-weight: 700; }

/* Status tags */
.obp-status-tag {
  display: inline-flex; align-items: center; padding: 3px 10px;
  border-radius: 20px; font-size: 12px; font-weight: 600; white-space: nowrap;
}
.st-unpaid { background: var(--ds-danger-wash); color: var(--ds-danger); }
.st-partial { background: var(--ds-warning-wash); color: var(--ds-warning); }
.st-paid { background: var(--ds-success-wash); color: var(--ds-success); }

.obp-action-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border: 1px solid var(--border); border-radius: 8px;
  background: var(--card-bg); color: var(--text); cursor: pointer; transition: all 0.15s;
}
.obp-action-btn:hover { background: var(--bg); border-color: var(--primary-light); color: var(--primary); }
.obp-action-btn .material-symbols-outlined { font-size: 16px; }

.ghost {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 5px 12px; border-radius: 8px; font-size: 13px; font-weight: 500;
  cursor: pointer; font-family: inherit; background: transparent;
  color: var(--text); border: 1px solid var(--border); transition: all 0.15s;
}
.ghost:hover { background: var(--bg); }

@media (max-width: 768px) {
  .obp-buckets { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
  .obp-buckets { grid-template-columns: 1fr; }
}
</style>
