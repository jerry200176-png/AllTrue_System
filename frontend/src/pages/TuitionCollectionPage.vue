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

    <!-- Skeleton loading -->
    <div v-if="loading && !rows.length" class="tc-skeleton-area">
      <div class="tc-summary">
        <div class="tc-card tc-card--skeleton" v-for="i in 4" :key="i">
          <span class="skel skel-num"></span>
          <span class="skel skel-label"></span>
        </div>
      </div>
      <div class="tc-table-wrap">
        <table class="tc-table">
          <thead>
            <tr>
              <th v-for="i in 8" :key="i"><span class="skel skel-th"></span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in 5" :key="i">
              <td v-for="j in 8" :key="j"><span class="skel skel-cell" :style="{ width: j === 1 ? '120px' : j <= 4 ? '72px' : '80px' }"></span></td>
            </tr>
          </tbody>
        </table>
      </div>
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
          <span class="tc-card-num">{{ statusCounts.unpaid }}</span>
          <span class="tc-card-label">未繳費</span>
        </div>
        <div class="tc-card tc-card--warn">
          <span class="tc-card-num">{{ statusCounts.partial + statusCounts.pending_report }}</span>
          <span class="tc-card-label">部分付款／待核帳</span>
        </div>
        <div class="tc-card tc-card--outstanding">
          <span class="tc-card-num">{{ formatCurrency(totalOutstanding) }}</span>
          <span class="tc-card-label">未結清總額</span>
        </div>
      </div>

      <div v-if="!rows.length" class="tc-empty">
        <span class="material-symbols-outlined" style="font-size:52px;color:var(--success)">check_circle</span>
        <p>本分校目前無待催繳課程</p>
        <button class="tc-cta-btn" @click="$emit('navigate', 'tuition-report')">查看當月學收</button>
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
          <div v-if="loading" class="tc-inline-loading">
            <span class="material-symbols-outlined spin" style="font-size:16px">progress_activity</span>
            更新中…
          </div>
        </div>

        <div v-if="searchQuery && !filteredRows.length" class="tc-empty" style="padding:32px 0">
          <span class="material-symbols-outlined" style="font-size:40px;color:var(--text-light)">person_search</span>
          <p>找不到包含「{{ searchQuery }}」的學生</p>
          <button class="tc-cta-btn tc-cta-btn--ghost" @click="searchQuery = ''">清除搜尋</button>
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
                <th class="tc-col-currency">應繳</th>
                <th class="tc-col-currency">已繳</th>
                <th class="tc-col-currency">未結清</th>
                <th class="tc-col-date">最近付款</th>
                <th class="tc-col-date">到期／逾期</th>
                <th class="tc-col-actions">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredRows" :key="r.id" :class="rowClass(r)">
                <td class="tc-cell-name">{{ r.student_name }}</td>
                <td>{{ r.subject }}</td>
                <td class="tc-col-mode">
                  <span class="mode-tag" :class="r.schedule_mode">
                    {{ r.schedule_mode === 'date' ? '月結' : '堂數' }}
                  </span>
                </td>
                <td>
                  <span class="status-tag" :class="statusClass(r)">
                    {{ statusLabel(r) }}
                  </span>
                </td>
                <td class="tc-col-currency">{{ r.charge != null ? formatCurrency(r.charge) : '—' }}</td>
                <td class="tc-col-currency">{{ r.paid_amount != null ? formatCurrency(r.paid_amount) : '—' }}</td>
                <td class="tc-col-currency" :class="{ 'tc-outstanding-warn': r.outstanding > 0 }">
                  {{ r.outstanding != null ? formatCurrency(r.outstanding) : '—' }}
                </td>
                <td class="tc-col-date">
                  <span v-if="r.last_paid_at" class="paid-date" :title="'最後一筆付款日，非本期是否結清的依據'">{{ r.last_paid_at }}</span>
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
                  <template v-else-if="r.schedule_mode === 'count'">
                    <span class="text-light">剩 {{ r.remaining_sessions }} 堂</span>
                  </template>
                  <span v-else class="text-light">—</span>
                </td>
                <td class="tc-col-actions">
                  <div class="tc-actions">
                    <!-- unpaid / partial: slip + 核帳登記 -->
                    <template v-if="r.payment_status === 'unpaid' || r.payment_status === 'partial'">
                      <button class="tc-btn tc-btn--slip" @click="openSlip(r)" title="產生繳費通知單">
                        <span class="material-symbols-outlined">receipt_long</span>
                        繳費單
                      </button>
                      <button class="tc-btn tc-btn--confirm" @click="openPaymentEntry(r)" title="核帳登記" :disabled="actionLoading === r.id">
                        <span class="material-symbols-outlined">check_circle</span>
                        核帳登記
                      </button>
                    </template>

                    <!-- pending_report: confirm / reject -->
                    <template v-if="r.payment_status === 'pending_report'">
                      <button class="tc-btn tc-btn--confirm" @click="confirmReport(r)" :disabled="actionLoading === r.id" title="確認入帳">
                        <span v-if="actionLoading === r.id" class="material-symbols-outlined spin" style="font-size:15px">progress_activity</span>
                        <span v-else class="material-symbols-outlined">check_circle</span>
                        確認入帳
                      </button>
                      <button class="tc-btn tc-btn--reject" @click="rejectReport(r)" :disabled="actionLoading === r.id" title="退回">
                        <span class="material-symbols-outlined">cancel</span>
                        退回
                      </button>
                    </template>

                    <!-- paid / renew_needed / monthly_due_soon: void + receipt -->
                    <template v-if="r.payment_status === 'paid' || r.payment_status === 'renew_needed' || r.payment_status === 'monthly_due_soon'">
                      <button class="tc-btn tc-btn--receipt" @click="viewReceiptForClass(r)" title="查看收據">
                        <span class="material-symbols-outlined">receipt</span>
                        收據
                      </button>
                      <button v-if="canVoid" class="tc-btn tc-btn--void" @click="openVoidDialog(r)" :disabled="actionLoading === r.id" title="撤銷收款">
                        <span class="material-symbols-outlined">undo</span>
                        撤銷
                      </button>
                      <template v-if="r.payment_status === 'renew_needed'">
                        <span v-if="r.has_newer_course" class="tc-newer-badge" :title="'已有新課程 #' + r.newer_course_id + '（剩餘 ' + (r.newer_course_remaining ?? 0) + ' 堂）'">已有新課程</span>
                        <span v-else class="tc-renew-hint">需續課</span>
                        <button class="tc-btn tc-btn--settle" @click="openSettleDialog(r)" :disabled="settleLoading === r.id" title="確認舊課程已被新課程取代，點此關閉">
                          <span v-if="settleLoading === r.id" class="material-symbols-outlined spin" style="font-size:15px">progress_activity</span>
                          <span v-else class="material-symbols-outlined">task_alt</span>
                          結案
                        </button>
                      </template>
                    </template>
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

    <!-- Void Confirmation Dialog -->
    <Transition name="fade">
      <div v-if="voidDialogOpen" class="tc-overlay" @click.self="voidDialogOpen = false">
        <div class="tc-dialog">
          <h3 class="tc-dialog-title">
            <span class="material-symbols-outlined" style="font-size:22px;color:var(--danger)">warning</span>
            撤銷收款確認
          </h3>
          <p class="tc-dialog-desc">此操作將作廢原付款紀錄並重置繳費狀態，無法自動還原，請確認。</p>
          <div class="tc-dialog-info" v-if="voidTarget">
            <span>{{ voidTarget.student_name }} — {{ voidTarget.subject }}</span>
          </div>
          <label class="tc-dialog-label">撤銷原因（必填）</label>
          <textarea v-model="voidReason" class="tc-dialog-textarea" placeholder="請輸入撤銷原因…" maxlength="500" rows="3"></textarea>
          <div class="tc-dialog-charcount">{{ voidReason.length }} / 500</div>
          <div class="tc-dialog-btns">
            <button class="tc-btn tc-btn--ghost" @click="voidDialogOpen = false" :disabled="voidLoading">取消</button>
            <button class="tc-btn tc-btn--danger" @click="confirmVoid" :disabled="!voidReason.trim() || voidLoading">
              <span v-if="voidLoading" class="material-symbols-outlined spin" style="font-size:15px">progress_activity</span>
              確認撤銷
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Settle (Close) Confirmation Dialog -->
    <Transition name="fade">
      <div v-if="settleDialogOpen" class="tc-overlay" @click.self="settleDialogOpen = false">
        <div class="tc-dialog">
          <h3 class="tc-dialog-title">
            <span class="material-symbols-outlined" style="font-size:22px;color:var(--primary)">task_alt</span>
            確認結案此課程
          </h3>
          <p class="tc-dialog-desc">結案後此課程將從催繳名單移除，不再追蹤。</p>
          <div class="tc-dialog-info" v-if="settleTarget">
            <div style="margin-bottom:4px"><strong>{{ settleTarget.student_name }}</strong> — {{ settleTarget.subject }}</div>
            <div style="font-size:12px;color:var(--text-light)">課程 #{{ settleTarget.id }}，剩餘 {{ settleTarget.remaining_sessions }} 堂</div>
          </div>
          <div v-if="settleTarget?.has_newer_course" class="tc-settle-newer-info">
            <span class="material-symbols-outlined" style="font-size:16px;color:#16A34A">check_circle</span>
            <span>已偵測到新課程 #{{ settleTarget.newer_course_id }}（開課日 {{ settleTarget.newer_course_start_date || '—' }}，剩餘 {{ settleTarget.newer_course_remaining ?? 0 }} 堂）</span>
          </div>
          <div v-else class="tc-settle-warn-info">
            <span class="material-symbols-outlined" style="font-size:16px;color:#D97706">info</span>
            <span>未偵測到同科目新課程，關閉後此課程不再追蹤</span>
          </div>
          <div class="tc-dialog-btns">
            <button class="tc-btn tc-btn--ghost" @click="settleDialogOpen = false" :disabled="settleLoading">取消</button>
            <button class="tc-btn tc-btn--primary" @click="confirmSettle" :disabled="settleLoading">
              <span v-if="settleLoading" class="material-symbols-outlined spin" style="font-size:15px">progress_activity</span>
              確認結案
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Toast -->
    <Transition name="toast">
      <div v-if="toastMessage" class="tc-toast" :class="toastType">
        <span class="material-symbols-outlined" style="font-size:18px">{{ toastIcon }}</span>
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

const emit = defineEmits(['navigate']);

const loading = ref(false);
const error = ref('');
const actionLoading = ref(null);

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

function getAuthRole() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.user?.role || session?.role || '';
}

const canVoid = computed(() => {
  const role = getAuthRole();
  return ['director', 'admin', 'super_admin'].includes(role);
});

// ═══ Payment Status Helpers ═══
const STATUS_CONFIG = {
  unpaid:           { label: '未繳費',        cls: 'st-unpaid' },
  partial:          { label: '部分付款',      cls: 'st-partial' },
  pending_report:   { label: '待核帳',        cls: 'st-pending' },
  paid:             { label: '已繳費',        cls: 'st-paid' },
  renew_needed:     { label: '已繳需續課',    cls: 'st-renew' },
  monthly_due_soon: { label: '月結將到期',    cls: 'st-monthly' },
};

function statusLabel(r) {
  const ps = r.payment_status;
  if (ps && STATUS_CONFIG[ps]) return STATUS_CONFIG[ps].label;
  return r.paid ? '已繳費' : '未繳費';
}

function statusClass(r) {
  const ps = r.payment_status;
  if (ps && STATUS_CONFIG[ps]) return STATUS_CONFIG[ps].cls;
  return r.paid ? 'st-paid' : 'st-unpaid';
}

function rowClass(r) {
  const ps = r.payment_status;
  if (ps === 'paid' || ps === 'renew_needed') return 'row-paid';
  return '';
}

function formatCurrency(v) {
  if (v == null || isNaN(v)) return '—';
  return 'NT$ ' + Number(v).toLocaleString('zh-TW');
}

// ═══ Alerts ═══
const rows = ref([]);
const searchQuery = ref('');
const slipOpen = ref(false);
const slipInvoiceId = ref(null);
const slipStudentClassId = ref(null);

const statusCounts = computed(() => {
  const c = { unpaid: 0, partial: 0, pending_report: 0, paid: 0, renew_needed: 0, monthly_due_soon: 0 };
  rows.value.forEach(r => {
    const ps = r.payment_status || (r.paid ? 'paid' : 'unpaid');
    if (c[ps] !== undefined) c[ps]++;
  });
  return c;
});

const OUTSTANDING_STATUSES = ['unpaid', 'partial', 'pending_report'];
const totalOutstanding = computed(() => {
  return rows.value
    .filter(r => OUTSTANDING_STATUSES.includes(r.payment_status))
    .reduce((sum, r) => sum + (r.outstanding || 0), 0);
});

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

    const statusPriority = { unpaid: 0, partial: 1, pending_report: 2, monthly_due_soon: 3, renew_needed: 4, paid: 5 };
    rows.value = list.sort((a, b) => {
      const pa = statusPriority[a.payment_status] ?? 3;
      const pb = statusPriority[b.payment_status] ?? 3;
      if (pa !== pb) return pa - pb;
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

// ═══ Toast ═══
const toastMessage = ref('');
const toastType = ref('');
const toastIcon = ref('check_circle');

function showToast(msg, type = 'success') {
  toastMessage.value = msg;
  toastType.value = type === 'error' ? 'tc-toast--error' : type === 'warning' ? 'tc-toast--warning' : '';
  toastIcon.value = type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'check_circle';
  setTimeout(() => { toastMessage.value = ''; }, 3000);
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
  showToast('已完成核帳登記');

  if (result?.report_id) {
    receiptReportId.value = result.report_id;
    receiptOpen.value = true;
  }

  loadAlerts();
}

// ═══ Confirm / Reject pending reports ═══
async function confirmReport(row) {
  if (!row.latest_payment_report_id) {
    showToast('找不到待確認的回報', 'error');
    return;
  }
  actionLoading.value = row.id;
  try {
    const token = getToken();
    const resp = await fetch(`/api/v1/payment-reports/${row.latest_payment_report_id}/confirm`, {
      method: 'PUT',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `操作失敗（${resp.status}）`);
    }
    showToast('已確認入帳');
    loadAlerts();
  } catch (e) {
    showToast(e.message || '確認入帳失敗', 'error');
  } finally {
    actionLoading.value = null;
  }
}

async function rejectReport(row) {
  if (!row.latest_payment_report_id) {
    showToast('找不到待確認的回報', 'error');
    return;
  }
  const reason = prompt('請輸入退回原因：');
  if (!reason || !reason.trim()) return;

  actionLoading.value = row.id;
  try {
    const token = getToken();
    const resp = await fetch(`/api/v1/payment-reports/${row.latest_payment_report_id}/reject`, {
      method: 'PUT',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ rejection_note: reason.trim() }),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `操作失敗（${resp.status}）`);
    }
    showToast('已退回此回報', 'warning');
    loadAlerts();
  } catch (e) {
    showToast(e.message || '退回失敗', 'error');
  } finally {
    actionLoading.value = null;
  }
}

// ═══ Void Dialog ═══
const voidDialogOpen = ref(false);
const voidTarget = ref(null);
const voidReason = ref('');
const voidLoading = ref(false);

function openVoidDialog(row) {
  voidTarget.value = row;
  voidReason.value = '';
  voidDialogOpen.value = true;
}

async function confirmVoid() {
  if (!voidTarget.value || !voidReason.value.trim()) return;

  voidLoading.value = true;
  try {
    const token = getToken();
    const reportId = await findConfirmedReportForClass(voidTarget.value);
    if (!reportId) {
      showToast('找不到此課程的已確認核帳紀錄', 'error');
      return;
    }

    const resp = await fetch(`/api/v1/payment-reports/${reportId}/void`, {
      method: 'PUT',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ void_reason: voidReason.value.trim() }),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `撤銷失敗（${resp.status}）`);
    }
    voidDialogOpen.value = false;
    showToast('已撤銷收款，狀態已重置', 'warning');
    loadAlerts();
  } catch (e) {
    showToast(e.message || '撤銷失敗', 'error');
  } finally {
    voidLoading.value = false;
  }
}

async function findConfirmedReportForClass(row) {
  try {
    const token = getToken();
    const params = new URLSearchParams();
    if (props.branchId != null && props.branchId !== '') {
      params.set('branch_id', String(Number(props.branchId)));
    }
    params.set('status', 'confirmed');
    const resp = await fetch(`/api/v1/payment-reports?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!resp.ok) return null;
    const json = await resp.json();
    const reports = json.data || [];
    const match = reports.find(r => r.student_class_id === row.id && r.status === 'confirmed');
    return match?.id || null;
  } catch {
    return null;
  }
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
      showToast('找不到此課程的核帳收據', 'error');
    }
  } catch {
    showToast('查詢收據失敗', 'error');
  }
}

// ═══ Settle (Close) ═══
const settleDialogOpen = ref(false);
const settleTarget = ref(null);
const settleLoading = ref(null);

function openSettleDialog(row) {
  settleTarget.value = row;
  settleDialogOpen.value = true;
}

async function confirmSettle() {
  if (!settleTarget.value) return;
  const row = settleTarget.value;
  settleLoading.value = row.id;
  try {
    const token = getToken();
    const resp = await fetch(`/api/v1/student-classes/${row.id}/pause`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'pause', reason: 'settled' }),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `結案失敗（${resp.status}）`);
    }
    settleDialogOpen.value = false;
    rows.value = rows.value.filter(r => r.id !== row.id);
    showToast(`已結案：${row.student_name} ${row.subject}`);
  } catch (e) {
    showToast(e.message || '結案失敗，請重試', 'error');
  } finally {
    settleLoading.value = null;
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
.tc-card--outstanding { background: #FFF5F5; border-color: #FECACA; }
.tc-card--outstanding .tc-card-num { color: #DC2626; font-size: 18px; }

/* ─── Skeleton ─── */
.tc-card--skeleton {
  background: var(--bg);
  border-color: var(--border);
}
.skel {
  display: inline-block;
  background: linear-gradient(90deg, #e5e7eb 25%, #f3f4f6 50%, #e5e7eb 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s ease infinite;
  border-radius: 4px;
}
.skel-num { width: 36px; height: 24px; }
.skel-label { width: 48px; height: 14px; }
.skel-th { width: 56px; height: 12px; }
.skel-cell { height: 16px; display: block; }
@keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

/* ─── States ─── */
.tc-inline-loading {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  color: var(--text-light);
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

.tc-cta-btn {
  padding: 8px 20px;
  border-radius: 8px;
  border: 1px solid var(--primary);
  background: var(--primary);
  color: #fff;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.15s;
}
.tc-cta-btn:hover { opacity: 0.9; }
.tc-cta-btn--ghost {
  background: transparent;
  color: var(--primary);
}
.tc-cta-btn--ghost:hover {
  background: rgba(37,99,235,0.05);
}

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

.tc-col-mode { width: 60px; text-align: center; }
.tc-col-currency { width: 90px; text-align: right; font-variant-numeric: tabular-nums; font-size: 13px; }
.tc-col-date { white-space: nowrap; }
.tc-col-actions { width: 1%; white-space: nowrap; }

.row-paid { opacity: 0.55; }
.row-paid:hover { opacity: 0.75; }

.tc-outstanding-warn { color: #DC2626; font-weight: 600; }

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
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
.st-unpaid { background: #FEE2E2; color: #DC2626; }
.st-partial { background: #FED7AA; color: #C2410C; }
.st-pending { background: #FED7AA; color: #C2410C; }
.st-pending::before {
  content: '';
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #C2410C;
  animation: blink 1.2s ease infinite;
}
@keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
.st-paid { background: #DCFCE7; color: #16A34A; }
.st-renew { background: #DBEAFE; color: #1D4ED8; }
.st-monthly { background: #FEF3C7; color: #D97706; }

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
.paid-date { color: #16A34A; font-size: 13px; cursor: help; border-bottom: 1px dotted #16A34A; }

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
.tc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.tc-btn .material-symbols-outlined { font-size: 15px; }

.tc-btn--slip { color: var(--primary); }
.tc-btn--slip:hover:not(:disabled) { background: #EFF6FF; border-color: #93C5FD; }

.tc-btn--confirm { color: #16A34A; }
.tc-btn--confirm:hover:not(:disabled) { background: #F0FDF4; border-color: #86EFAC; }

.tc-btn--receipt { color: #15803D; }
.tc-btn--receipt:hover:not(:disabled) { background: #F0FDF4; border-color: #86EFAC; }

.tc-btn--reject { color: #DC2626; }
.tc-btn--reject:hover:not(:disabled) { background: #FFF5F5; border-color: #FECACA; }

.tc-btn--void { color: #D97706; }
.tc-btn--void:hover:not(:disabled) { background: #FFFBEB; border-color: #FDE68A; }

.tc-btn--ghost {
  background: transparent;
  border-color: var(--border);
  color: var(--text);
}
.tc-btn--ghost:hover:not(:disabled) { background: var(--bg); }

.tc-btn--danger {
  background: #DC2626;
  border-color: #DC2626;
  color: #fff;
}
.tc-btn--danger:hover:not(:disabled) { background: #B91C1C; }
.tc-btn--danger:disabled { background: #FCA5A5; border-color: #FCA5A5; }

.tc-renew-hint {
  font-size: 12px;
  color: var(--text-light);
  margin-left: 2px;
}

.tc-newer-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  background: #DCFCE7;
  color: #16A34A;
  cursor: help;
  white-space: nowrap;
}

.tc-btn--settle { color: #6D28D9; }
.tc-btn--settle:hover:not(:disabled) { background: #F5F3FF; border-color: #C4B5FD; }

.tc-btn--primary {
  background: var(--primary);
  border-color: var(--primary);
  color: #fff;
}
.tc-btn--primary:hover:not(:disabled) { opacity: 0.9; }
.tc-btn--primary:disabled { opacity: 0.5; }

.tc-settle-newer-info,
.tc-settle-warn-info {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
  margin-bottom: 16px;
  line-height: 1.5;
}
.tc-settle-newer-info {
  background: #F0FDF4;
  border: 1px solid #BBF7D0;
  color: #15803D;
}
.tc-settle-warn-info {
  background: #FFFBEB;
  border: 1px solid #FDE68A;
  color: #92400E;
}

/* ─── Void Dialog ─── */
.tc-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  padding: 16px;
}
.tc-dialog {
  background: var(--card-bg);
  border-radius: 14px;
  padding: 24px;
  width: 100%;
  max-width: 440px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.tc-dialog-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 8px;
  font-size: 16px;
}
.tc-dialog-desc {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--text-light);
  line-height: 1.5;
}
.tc-dialog-info {
  padding: 8px 12px;
  background: var(--bg);
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 12px;
}
.tc-dialog-label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 6px;
  color: var(--text);
}
.tc-dialog-textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  resize: vertical;
  min-height: 72px;
  outline: none;
  background: var(--bg);
  color: var(--text);
  box-sizing: border-box;
}
.tc-dialog-textarea:focus {
  border-color: var(--primary-light);
  box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
}
.tc-dialog-charcount {
  text-align: right;
  font-size: 11px;
  color: var(--text-light);
  margin-top: 4px;
  margin-bottom: 16px;
}
.tc-dialog-btns {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
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
.tc-toast--error { background: #DC2626; }
.tc-toast--warning { background: #D97706; }
.toast-enter-active { animation: toastIn 0.25s ease; }
.toast-leave-active { animation: toastOut 0.2s ease forwards; }
@keyframes toastIn { from { opacity: 0; transform: translateX(-50%) translateY(10px); } }
@keyframes toastOut { to { opacity: 0; transform: translateX(-50%) translateY(10px); } }

/* fade transition for dialog */
.fade-enter-active { transition: opacity 0.2s ease; }
.fade-leave-active { transition: opacity 0.15s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

/* ─── Responsive ─── */
@media (max-width: 768px) {
  .tc-page { padding: 16px; }
  .tc-summary { gap: 8px; }
  .tc-card { padding: 8px 12px; }
  .tc-card-num { font-size: 18px; }
  .tc-card--outstanding .tc-card-num { font-size: 15px; }
  .tc-search-wrap { width: 100%; }
}
</style>
