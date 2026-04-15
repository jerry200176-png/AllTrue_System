<template>
  <div class="card">
    <div class="tc-header">
      <div>
        <h2>催繳名單</h2>
        <p class="tc-subtitle">依堂數不足（≤2 堂）與月結將屆自動彙整，僅未繳費可產生繳費單</p>
      </div>
      <button class="primary" @click="loadAlerts" :disabled="loading">
        <span class="material-symbols-outlined" style="font-size:18px">refresh</span>
        重新整理
      </button>
    </div>

    <div v-if="loading" class="tc-loading">
      <span class="material-symbols-outlined spin">progress_activity</span>
      載入中…
    </div>

    <div v-else-if="error" class="tc-error">{{ error }}</div>

    <template v-else>
      <!-- Stats bar -->
      <div class="tc-stats" v-if="rows.length">
        <span class="tc-stat">
          <strong>{{ rows.length }}</strong> 筆提醒
        </span>
        <span class="tc-stat tc-stat--danger">
          <strong>{{ unpaidCount }}</strong> 筆未繳費
        </span>
        <span class="tc-stat tc-stat--warn">
          <strong>{{ paidLowCount }}</strong> 筆已繳／低堂數
        </span>
      </div>

      <div v-if="!rows.length" class="tc-empty">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--success)">check_circle</span>
        <p>本分校目前無待催繳課程</p>
      </div>

      <div v-else class="tc-table-wrap">
      <table class="tc-table">
        <thead>
          <tr>
            <th>學生</th>
            <th>科目</th>
            <th>模式</th>
            <th>狀態</th>
            <th>剩餘堂數</th>
            <th>繳費日期</th>
            <th>繳費期限</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id" :class="{ 'row-paid': r.paid }">
            <td>{{ r.student_name }}</td>
            <td>{{ r.subject }}</td>
            <td>
              <span class="mode-tag" :class="r.schedule_mode">
                {{ r.schedule_mode === 'date' ? '月結' : '堂數' }}
              </span>
            </td>
            <td>
              <span class="status-tag" :class="r.paid ? 'paid' : 'unpaid'">
                {{ r.paid ? '已繳費' : '未繳費' }}
              </span>
            </td>
            <td class="num">{{ r.remaining_sessions }}</td>
            <td>
              <span v-if="r.last_paid_at" class="paid-date">{{ r.last_paid_at }}</span>
              <span v-else class="text-light">—</span>
            </td>
            <td>
              <template v-if="r.due_date">
                {{ r.due_date }}
                <span v-if="r.days_until_settlement != null && r.days_until_settlement < 0" class="overdue-tag">
                  逾期 {{ Math.abs(r.days_until_settlement) }} 天
                </span>
                <span v-else-if="r.days_until_settlement != null && r.days_until_settlement <= 2" class="soon-tag">
                  {{ r.days_until_settlement }} 天後
                </span>
              </template>
              <span v-else class="text-light">—</span>
            </td>
            <td>
              <button
                v-if="!r.paid"
                class="slip-btn"
                @click="openSlip(r)"
                title="產生繳費通知單"
              >
                <span class="material-symbols-outlined">receipt_long</span>
                繳費單
              </button>
              <span v-else class="text-light text-sm">續課聯繫</span>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </template>

    <PaymentSlipModal
      :show="slipOpen"
      :invoice-id="slipInvoiceId"
      :student-class-id="slipStudentClassId"
      @close="slipOpen = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import PaymentSlipModal from '../components/PaymentSlipModal.vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
});

const rows = ref([]);
const loading = ref(false);
const error = ref('');

const slipOpen = ref(false);
const slipInvoiceId = ref(null);
const slipStudentClassId = ref(null);

const unpaidCount = computed(() => rows.value.filter(r => !r.paid).length);
const paidLowCount = computed(() => rows.value.filter(r => r.paid).length);

function openSlip(row) {
  slipInvoiceId.value = null;
  slipStudentClassId.value = row.id;
  slipOpen.value = true;
}

async function loadAlerts() {
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

    const resp = await fetch(`/api/v1/alerts/tuition?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) throw new Error(`載入失敗（${resp.status}）`);
    const json = await resp.json();
    const list = Array.isArray(json) ? json : [];

    // Sort: unpaid first, then by days_until_settlement ascending (most urgent first)
    rows.value = list.sort((a, b) => {
      if (a.paid !== b.paid) return a.paid ? 1 : -1;
      const da = a.days_until_settlement ?? 999;
      const db = b.days_until_settlement ?? 999;
      return da - db;
    });
  } catch (e) {
    error.value = e.message || '載入失敗';
  } finally {
    loading.value = false;
  }
}

watch(() => props.branchId, () => loadAlerts(), { flush: 'post' });
loadAlerts();
</script>

<style scoped>
.tc-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.tc-header h2 { margin: 0; }
.tc-subtitle { color: var(--text-light); font-size: 13px; margin-top: 4px; }

.tc-loading {
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

.tc-error {
  color: var(--danger);
  padding: 16px 0;
}
.tc-empty {
  text-align: center;
  padding: 48px 0;
  color: var(--text-light);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}
.tc-stats {
  display: flex;
  gap: 16px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.tc-stat {
  font-size: 14px;
  color: var(--text);
  padding: 6px 14px;
  background: var(--bg);
  border-radius: 8px;
}
.tc-stat--danger { color: var(--danger); }
.tc-stat--warn { color: var(--warning); }

.tc-table-wrap { overflow-x: auto; }
.tc-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
}
.tc-table th {
  text-align: left;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light);
  padding: 10px 8px;
  border-bottom: 2px solid var(--border);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.tc-table td {
  padding: 10px 8px;
  border-bottom: 1px solid var(--border);
  font-size: 14px;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
}
/* Column widths: 學生 | 科目 | 模式 | 狀態 | 剩餘堂數 | 繳費日期 | 繳費期限 | 操作 */
.tc-table th:nth-child(1),
.tc-table td:nth-child(1) { width: 15%; }
.tc-table th:nth-child(2),
.tc-table td:nth-child(2) { width: 10%; }
.tc-table th:nth-child(3),
.tc-table td:nth-child(3) { width: 8%; }
.tc-table th:nth-child(4),
.tc-table td:nth-child(4) { width: 10%; }
.tc-table th:nth-child(5),
.tc-table td:nth-child(5) { width: 10%; text-align: center; }
.tc-table th:nth-child(6),
.tc-table td:nth-child(6) { width: 13%; }
.tc-table th:nth-child(7),
.tc-table td:nth-child(7) { width: 18%; }
.tc-table th:nth-child(8),
.tc-table td:nth-child(8) { width: 16%; }
.tc-table .num { text-align: center; font-variant-numeric: tabular-nums; }
.row-paid { opacity: 0.65; }

.mode-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}
.mode-tag.count { background: #E3F2FD; color: #1565C0; }
.mode-tag.date { background: #F3E5F5; color: #7B1FA2; }

.status-tag {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}
.status-tag.unpaid { background: var(--danger-bg); color: var(--danger); }
.status-tag.paid { background: var(--success-bg); color: var(--success); }

.overdue-tag {
  display: inline-block;
  margin-left: 4px;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  background: var(--danger-bg);
  color: var(--danger);
}
.soon-tag {
  display: inline-block;
  margin-left: 4px;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  background: var(--warning-bg);
  color: var(--warning);
}
.text-light { color: var(--text-light); }
.text-sm { font-size: 12px; }
.paid-date { color: var(--success); font-size: 13px; white-space: nowrap; }

.slip-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border: 1px solid var(--border);
  background: var(--card-bg);
  border-radius: 8px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 500;
  color: var(--primary);
  transition: var(--transition);
  font-family: inherit;
}
.slip-btn:hover {
  background: var(--primary-bg);
  border-color: var(--primary-light);
}
.slip-btn .material-symbols-outlined { font-size: 16px; }

@media (max-width: 768px) {
  .tc-table { table-layout: auto; }
  /* Hide: 模式(3), 剩餘堂數(5), 繳費日期(6), 繳費期限(7) */
  .tc-table th:nth-child(3),
  .tc-table td:nth-child(3),
  .tc-table th:nth-child(5),
  .tc-table td:nth-child(5),
  .tc-table th:nth-child(6),
  .tc-table td:nth-child(6),
  .tc-table th:nth-child(7),
  .tc-table td:nth-child(7) { display: none; }
}
</style>
