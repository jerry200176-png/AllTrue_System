<template>
  <div class="tc-page">
    <div class="tc-header">
      <div>
        <h2>催繳名單</h2>
        <p class="tc-subtitle">催繳與核帳管理</p>
      </div>
      <button class="tc-refresh-btn" @click="loadAlerts()" :disabled="loading">
        <span class="material-symbols-outlined" style="font-size:17px">refresh</span>
        重新整理
      </button>
    </div>

    <div v-if="loading" class="tc-loading">
      <span class="material-symbols-outlined spin">progress_activity</span>
      載入中…
    </div>

    <div v-else-if="error" class="tc-error">
      <span class="material-symbols-outlined" style="font-size:20px">error</span>
      {{ error }}
    </div>

    <template v-else>
      <!-- Summary cards -->
      <div class="tc-summary" v-if="rows.length">
        <div class="tc-card tc-card--total">
          <span class="tc-card-num">{{ rows.length }}</span>
          <span class="tc-card-label">筆提醒</span>
        </div>
        <div class="tc-card tc-card--danger">
          <span class="tc-card-num">{{ unpaidCount }}</span>
          <span class="tc-card-label">未繳費</span>
        </div>
        <div class="tc-card tc-card--warn">
          <span class="tc-card-num">{{ paidLowCount }}</span>
          <span class="tc-card-label">已繳／低堂數</span>
        </div>
      </div>

      <div v-if="!rows.length" class="tc-empty">
        <span class="material-symbols-outlined" style="font-size:52px;color:var(--success)">check_circle</span>
        <p>本分校目前無待催繳課程</p>
      </div>

      <div v-else>
        <!-- Search -->
        <div class="tc-toolbar">
          <div class="tc-search-wrap">
            <span class="material-symbols-outlined tc-search-icon">search</span>
            <input
              v-model="searchQuery"
              class="tc-search-input"
              type="text"
              placeholder="搜尋學生姓名…"
              autocomplete="off"
            />
            <button v-if="searchQuery" class="tc-search-clear" @click="searchQuery = ''" title="清除">
              <span class="material-symbols-outlined" style="font-size:16px">close</span>
            </button>
          </div>
          <span v-if="searchQuery" class="tc-search-hint">
            {{ filteredRows.length }} 筆符合「{{ searchQuery }}」
          </span>
        </div>

        <div v-if="searchQuery && !filteredRows.length" class="tc-empty" style="padding:32px 0">
          <span class="material-symbols-outlined" style="font-size:40px;color:var(--text-light)">person_search</span>
          <p>找不到包含「{{ searchQuery }}」的學生</p>
        </div>

        <!-- Table -->
        <div v-else class="tc-table-wrap">
          <table class="tc-table">
            <thead>
              <tr>
                <th>學生</th>
                <th>科目</th>
                <th class="tc-col-mode">模式</th>
                <th>狀態</th>
                <th class="tc-col-num">剩餘</th>
                <th class="tc-col-date">繳費日期</th>
                <th class="tc-col-date">繳費期限</th>
                <th class="tc-col-actions">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredRows" :key="r.id" :class="{ 'row-paid': r.paid }">
                <td class="tc-cell-name">{{ r.student_name }}</td>
                <td>{{ r.subject }}</td>
                <td class="tc-col-mode">
                  <span class="mode-tag" :class="r.schedule_mode">
                    {{ r.schedule_mode === 'date' ? '月結' : '堂數' }}
                  </span>
                </td>
                <td>
                  <span class="status-tag" :class="r.paid ? 'paid' : 'unpaid'">
                    {{ r.paid ? '已繳費' : '未繳費' }}
                  </span>
                </td>
                <td class="tc-col-num">{{ r.remaining_sessions }}</td>
                <td class="tc-col-date">
                  <span v-if="r.last_paid_at" class="paid-date">{{ r.last_paid_at }}</span>
                  <span v-else class="text-light">—</span>
                </td>
                <td class="tc-col-date">
                  <template v-if="r.due_date">
                    {{ r.due_date }}
                    <span v-if="r.days_until_settlement != null && r.days_until_settlement < 0" class="overdue-tag">
                      逾期{{ Math.abs(r.days_until_settlement) }}天
                    </span>
                    <span v-else-if="r.days_until_settlement != null && r.days_until_settlement <= 2" class="soon-tag">
                      {{ r.days_until_settlement }}天後
                    </span>
                  </template>
                  <span v-else class="text-light">—</span>
                </td>
                <td class="tc-col-actions">
                  <div class="tc-actions">
                    <button
                      v-if="!r.paid"
                      class="tc-btn tc-btn--slip"
                      @click="openSlip(r)"
                      title="產生繳費通知單"
                    >
                      <span class="material-symbols-outlined">receipt_long</span>
                      繳費單
                    </button>
                    <button
                      v-if="!r.paid"
                      class="tc-btn tc-btn--confirm"
                      @click="openPaymentEntry(r)"
                      title="核帳登記"
                    >
                      <span class="material-symbols-outlined">check_circle</span>
                      核帳登記
                    </button>
                    <button
                      v-if="r.paid"
                      class="tc-btn tc-btn--receipt"
                      @click="viewReceiptForClass(r)"
                      title="查看收據"
                    >
                      <span class="material-symbols-outlined">receipt</span>
                      收據
                    </button>
                    <span v-if="r.paid" class="tc-renew-hint">續課聯繫</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- Modals -->
    <PaymentSlipModal
      :show="slipOpen"
      :invoice-id="slipInvoiceId"
      :student-class-id="slipStudentClassId"
      @close="slipOpen = false"
    />

    <PaymentEntryModal
      :show="entryOpen"
      :row="entryRow"
      @close="entryOpen = false"
      @confirmed="onEntryConfirmed"
    />

    <ReceiptModal
      :show="receiptOpen"
      :report-id="receiptReportId"
      @close="receiptOpen = false"
    />

    <!-- Success toast -->
    <Transition name="toast">
      <div v-if="toastMessage" class="tc-toast">
        <span class="material-symbols-outlined" style="font-size:18px">check_circle</span>
        {{ toastMessage }}
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import PaymentSlipModal from '../components/PaymentSlipModal.vue';
import PaymentEntryModal from '../components/PaymentEntryModal.vue';
import ReceiptModal from '../components/ReceiptModal.vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
});

const loading = ref(false);
const error = ref('');

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

// ═══ Alerts ═══
const rows = ref([]);
const searchQuery = ref('');
const slipOpen = ref(false);
const slipInvoiceId = ref(null);
const slipStudentClassId = ref(null);
const toastMessage = ref('');

const unpaidCount = computed(() => rows.value.filter(r => !r.paid).length);
const paidLowCount = computed(() => rows.value.filter(r => r.paid).length);
const filteredRows = computed(() => {
  const q = searchQuery.value.trim();
  if (!q) return rows.value;
  return rows.value.filter(r => r.student_name && r.student_name.includes(q));
});

function openSlip(row) {
  slipInvoiceId.value = null;
  slipStudentClassId.value = row.id;
  slipOpen.value = true;
}

async function loadAlerts() {
  loading.value = true;
  error.value = '';
  try {
    const token = getToken();
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

// ═══ Payment Entry (核帳登記) ═══
const entryOpen = ref(false);
const entryRow = ref(null);

function openPaymentEntry(row) {
  entryRow.value = row;
  entryOpen.value = true;
}

function onEntryConfirmed(result) {
  entryOpen.value = false;
  toastMessage.value = '核帳登記完成';
  setTimeout(() => { toastMessage.value = ''; }, 3000);

  if (result?.report_id) {
    receiptReportId.value = result.report_id;
    receiptOpen.value = true;
  }

  loadAlerts();
}

// ═══ Receipt ═══
const receiptOpen = ref(false);
const receiptReportId = ref(null);

async function viewReceiptForClass(row) {
  try {
    const token = getToken();
    const params = new URLSearchParams();
    if (props.branchId != null && props.branchId !== '') {
      params.set('branch_id', String(Number(props.branchId)));
    }
    const resp = await fetch(`/api/v1/payment-reports?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) return;
    const json = await resp.json();
    const reports = json.data || [];
    const match = reports.find(r => r.student_class_id === row.id && r.status === 'confirmed');
    if (match) {
      receiptReportId.value = match.id;
      receiptOpen.value = true;
    } else {
      alert('找不到此課程的核帳收據');
    }
  } catch {
    alert('查詢收據失敗');
  }
}

// ═══ Watchers ═══
watch(() => props.branchId, () => {
  loadAlerts();
}, { flush: 'post' });

loadAlerts();
</script>

<style scoped>
/* ─── Page ─── */
.tc-page {
  background: var(--card-bg);
  border-radius: 14px;
  padding: 24px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}

/* ─── Header ─── */
.tc-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
.tc-header h2 {
  margin: 0;
  font-size: 20px;
  letter-spacing: -0.01em;
}
.tc-subtitle {
  color: var(--text-light);
  font-size: 13px;
  margin-top: 2px;
}
.tc-refresh-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 7px 14px;
  border: 1px solid var(--border);
  background: var(--card-bg);
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: var(--text);
  transition: all 0.15s;
  font-family: inherit;
}
.tc-refresh-btn:hover:not(:disabled) {
  background: var(--bg);
  border-color: var(--primary-light);
  color: var(--primary);
}
.tc-refresh-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* ─── Summary cards ─── */
.tc-summary {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.tc-card {
  display: flex;
  align-items: baseline;
  gap: 6px;
  padding: 10px 16px;
  border-radius: 10px;
  border: 1px solid var(--border);
}
.tc-card-num {
  font-size: 22px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}
.tc-card-label {
  font-size: 13px;
  color: var(--text-light);
}
.tc-card--total { background: var(--bg); }
.tc-card--total .tc-card-num { color: var(--text); }
.tc-card--danger { background: #FFF5F5; border-color: #FECACA; }
.tc-card--danger .tc-card-num { color: var(--danger); }
.tc-card--warn { background: #FFFBEB; border-color: #FDE68A; }
.tc-card--warn .tc-card-num { color: #D97706; }

/* ─── States ─── */
.tc-loading {
  text-align: center;
  padding: 56px 0;
  color: var(--text-light);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 14px;
}
.spin { animation: rotate 1s linear infinite; }
@keyframes rotate { to { transform: rotate(360deg); } }

.tc-error {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--danger);
  padding: 14px 16px;
  background: #FFF5F5;
  border-radius: 10px;
  font-size: 14px;
}
.tc-empty {
  text-align: center;
  padding: 56px 0;
  color: var(--text-light);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}
.tc-empty p { margin: 0; font-size: 14px; }

/* ─── Search toolbar ─── */
.tc-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.tc-search-wrap {
  position: relative;
  display: flex;
  align-items: center;
  width: 260px;
}
.tc-search-icon {
  position: absolute;
  left: 10px;
  font-size: 18px;
  color: var(--text-light);
  pointer-events: none;
}
.tc-search-input {
  width: 100%;
  padding: 8px 32px 8px 36px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  background: var(--bg);
  color: var(--text);
  transition: border-color 0.15s, box-shadow 0.15s;
  outline: none;
}
.tc-search-input:focus {
  border-color: var(--primary-light);
  box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
}
.tc-search-clear {
  position: absolute;
  right: 6px;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--text-light);
  display: flex;
  align-items: center;
  padding: 2px;
}
.tc-search-clear:hover { color: var(--text); }
.tc-search-hint {
  font-size: 13px;
  color: var(--text-light);
}

/* ─── Table ─── */
.tc-table-wrap {
  overflow-x: auto;
  margin: 0 -4px;
}
.tc-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
}
.tc-table thead tr {
  background: var(--bg);
}
.tc-table th {
  text-align: left;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light);
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.tc-table th:first-child { border-radius: 8px 0 0 0; }
.tc-table th:last-child { border-radius: 0 8px 0 0; }
.tc-table td {
  padding: 12px 12px;
  border-bottom: 1px solid var(--border);
  font-size: 14px;
  vertical-align: middle;
  white-space: nowrap;
}
.tc-table tbody tr {
  transition: background 0.1s;
}
.tc-table tbody tr:hover {
  background: rgba(0,0,0,0.015);
}
.tc-table tbody tr:last-child td {
  border-bottom: none;
}

.tc-cell-name { font-weight: 500; }

/* Column sizing — use auto layout; only constrain specific columns */
.tc-col-mode { width: 60px; text-align: center; }
.tc-col-num { width: 56px; text-align: center; font-variant-numeric: tabular-nums; }
.tc-col-date { white-space: nowrap; }
.tc-col-actions { width: 1%; white-space: nowrap; }

.row-paid { opacity: 0.55; }
.row-paid:hover { opacity: 0.75; }

/* ─── Tags ─── */
.mode-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}
.mode-tag.count { background: #DBEAFE; color: #1D4ED8; }
.mode-tag.date { background: #EDE9FE; color: #6D28D9; }

.status-tag {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}
.status-tag.unpaid { background: #FEE2E2; color: #DC2626; }
.status-tag.paid { background: #DCFCE7; color: #16A34A; }

.overdue-tag {
  display: inline-block;
  margin-left: 4px;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  background: #FEE2E2;
  color: #DC2626;
}
.soon-tag {
  display: inline-block;
  margin-left: 4px;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  background: #FEF3C7;
  color: #D97706;
}

.text-light { color: var(--text-light); }
.paid-date { color: #16A34A; font-size: 13px; }

/* ─── Action buttons ─── */
.tc-actions {
  display: flex;
  gap: 6px;
  align-items: center;
}
.tc-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 10px;
  border: 1px solid var(--border);
  background: var(--card-bg);
  border-radius: 7px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 500;
  transition: all 0.15s;
  font-family: inherit;
  white-space: nowrap;
}
.tc-btn .material-symbols-outlined { font-size: 15px; }

.tc-btn--slip { color: var(--primary); }
.tc-btn--slip:hover { background: #EFF6FF; border-color: #93C5FD; }

.tc-btn--confirm { color: #16A34A; }
.tc-btn--confirm:hover { background: #F0FDF4; border-color: #86EFAC; }

.tc-btn--receipt { color: #15803D; }
.tc-btn--receipt:hover { background: #F0FDF4; border-color: #86EFAC; }

.tc-renew-hint {
  font-size: 12px;
  color: var(--text-light);
  margin-left: 2px;
}

/* ─── Toast ─── */
.tc-toast {
  position: fixed;
  bottom: 80px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 24px;
  background: #1E293B;
  color: #fff;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 500;
  box-shadow: 0 8px 30px rgba(0,0,0,0.18);
  z-index: 9999;
}
.toast-enter-active { animation: toastIn 0.25s ease; }
.toast-leave-active { animation: toastOut 0.2s ease forwards; }
@keyframes toastIn { from { opacity: 0; transform: translateX(-50%) translateY(10px); } }
@keyframes toastOut { to { opacity: 0; transform: translateX(-50%) translateY(10px); } }

/* ─── Responsive ─── */
@media (max-width: 768px) {
  .tc-page { padding: 16px; }
  .tc-summary { gap: 8px; }
  .tc-card { padding: 8px 12px; }
  .tc-card-num { font-size: 18px; }
  .tc-search-wrap { width: 100%; }

  .tc-col-mode,
  .tc-col-date { display: none; }
  .tc-col-num { width: 48px; }
}
</style>
