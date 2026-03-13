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

    <table v-if="invoices.length">
      <thead>
        <tr>
          <th>代號</th>
          <th>學生</th>
          <th>總金額</th>
          <th>已繳金額</th>
          <th>狀態</th>
          <th>開立日期</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="invoice in invoices" :key="invoice.id">
          <td>{{ invoice.id }}</td>
          <td>{{ invoice.StudentID }}</td>
          <td>{{ invoice.TotalAmount }}</td>
          <td>{{ invoice.PaidAmount }}</td>
          <td>{{ invoice.Status }}</td>
          <td>{{ invoice.IssueDate }}</td>
        </tr>
      </tbody>
    </table>
    <p class="hint" v-else>無資料</p>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { downloadExport, getInvoices } from '../api';

const invoices = ref([]);
const filters = ref({
  student_id: null,
  status: ''
});

const loadInvoices = async () => {
  const result = await getInvoices(filters.value);
  invoices.value = result.data || result;
};

loadInvoices();
</script>
