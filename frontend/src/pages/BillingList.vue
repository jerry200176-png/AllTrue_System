<template>
  <div class="card">
    <h2>帳單列表</h2>
    <div class="grid">
      <div>
        <label>學生代號</label>
        <input v-model.number="filters.student_id" type="number" />
      </div>
      <div>
        <label>狀態</label>
        <select v-model="filters.status">
          <option value="">全部 (All)</option>
          <option value="unpaid">未繳費 (Unpaid)</option>
          <option value="partial">部分繳費 (Partial)</option>
          <option value="paid">已繳費 (Paid)</option>
        </select>
      </div>
    </div>
    <div style="margin-top: 12px; display: flex; gap: 8px;">
      <button class="primary" @click="loadInvoices">重新整理</button>
      <button @click="downloadExport('invoices')">匯出 Excel</button>
    </div>

    <p v-if="loadError" class="hint" style="color: var(--danger); margin-top: 12px;">{{ loadError }}</p>

    <table v-if="invoices.length" class="billing-table">
      <thead>
        <tr>
          <th>代號</th>
          <th>學生</th>
          <th>總金額</th>
          <th>已繳金額</th>
          <th>狀態</th>
          <th>開立日期</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="invoice in invoices" :key="invoice.id">
          <td>{{ invoice.id }}</td>
          <td>{{ invoice.student?.name || invoice.StudentID }}</td>
          <td class="num">{{ formatAmount(invoice.TotalAmount) }}</td>
          <td class="num">{{ formatAmount(invoice.PaidAmount) }}</td>
          <td>
            <span class="status-tag" :class="invoice.Status">{{ statusLabel(invoice.Status) }}</span>
          </td>
          <td>{{ invoice.IssueDate }}</td>
          <td>
            <button
              v-if="invoice.Status !== 'paid'"
              class="slip-btn"
              title="產生繳費單圖片"
              @click="openSlip(invoice.id)"
            >
              <span class="material-symbols-outlined">receipt_long</span>
              繳費單
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    <div v-else class="billing-empty">
      <p class="hint">此分校目前沒有帳單資料。</p>
      <p class="hint billing-empty-note">
        若營運上尚未透過本系統「開立帳單」（<code>Invoice</code>），列表會是空的——繳費／堂數多半記在學生課程或家長入口，與此列表無關。
      </p>
    </div>

    <PaymentSlipModal
      :show="slipOpen"
      :invoice-id="slipInvoiceId"
      @close="slipOpen = false"
    />
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { downloadExport, getInvoices } from '../api';
import PaymentSlipModal from '../components/PaymentSlipModal.vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
});

const invoices = ref([]);
const loadError = ref('');
const filters = ref({
  student_id: null,
  status: ''
});

const slipOpen = ref(false);
const slipInvoiceId = ref(null);

const STATUS_LABELS = { unpaid: '未繳費', partial: '部分繳費', paid: '已繳費' };
const statusLabel = (s) => STATUS_LABELS[s] || s;
const formatAmount = (n) => Number(n || 0).toLocaleString('zh-TW');

function openSlip(id) {
  slipInvoiceId.value = id;
  slipOpen.value = true;
}

const loadInvoices = async () => {
  loadError.value = '';
  const result = await getInvoices({
    ...filters.value,
    branch_id: props.branchId != null && props.branchId !== '' ? Number(props.branchId) : undefined,
  });
  invoices.value = result.data || [];
  if (result.error) {
    loadError.value = result.error;
  }
};

watch(
  () => props.branchId,
  () => loadInvoices(),
  { flush: 'post' },
);

loadInvoices();
</script>

<style scoped>
.billing-table { width: 100%; }
.billing-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.status-tag {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}
.status-tag.unpaid { background: var(--danger-bg); color: var(--danger); }
.status-tag.partial { background: var(--warning-bg); color: var(--warning); }
.status-tag.paid { background: var(--success-bg); color: var(--success); }
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
.billing-empty { margin-top: 16px; }
.billing-empty-note { margin-top: 8px; font-size: 13px; line-height: 1.5; }
.billing-empty-note code { font-size: 12px; }
</style>
