<template>
  <div class="director-accounts-page">
    <h2>主任審核</h2>
    <p class="page-desc">主任自行申請後在此審核，通過即可登入系統。</p>

    <div v-if="msg" :class="['msg', msg.type]">{{ msg.text }}</div>

    <div v-if="loading" class="loading-hint">載入中...</div>
    <div v-else-if="list.length === 0" class="empty-hint">目前沒有待審申請</div>
    <div v-else class="pending-table-wrap">
      <table class="pending-table">
        <thead>
          <tr>
            <th>姓名</th>
            <th>Email</th>
            <th>分校</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in list" :key="item.id">
            <td>{{ item.name }}</td>
            <td>{{ item.email }}</td>
            <td>{{ item.campus_name }}</td>
            <td class="actions">
              <button type="button" class="btn-approve" @click="approve(item.id)" :disabled="actionId === item.id">通過</button>
              <button type="button" class="btn-reject" @click="reject(item.id)" :disabled="actionId === item.id">拒絕</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const props = defineProps({
  token: { type: String, default: '' },
});

const list = ref([]);
const loading = ref(true);
const msg = ref(null);
const actionId = ref(null);

async function loadPending() {
  if (!props.token) return;
  loading.value = true;
  try {
    const res = await fetch('/api/v1/directors/pending', {
      headers: { Authorization: `Bearer ${props.token}` },
    });
    const data = await res.json().catch(() => []);
    list.value = Array.isArray(data) ? data : [];
  } finally {
    loading.value = false;
  }
}

async function approve(id) {
  if (!props.token) return;
  actionId.value = id;
  msg.value = null;
  try {
    const res = await fetch(`/api/v1/directors/${id}/approve`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${props.token}` },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      msg.value = { type: 'error', text: data?.message || '操作失敗' };
      return;
    }
    msg.value = { type: 'success', text: '已通過審核' };
    await loadPending();
  } finally {
    actionId.value = null;
  }
}

async function reject(id) {
  if (!props.token) return;
  if (!confirm('確定要拒絕此申請？')) return;
  actionId.value = id;
  msg.value = null;
  try {
    const res = await fetch(`/api/v1/directors/${id}/reject`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${props.token}` },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      msg.value = { type: 'error', text: data?.message || '操作失敗' };
      return;
    }
    msg.value = { type: 'success', text: '已拒絕' };
    await loadPending();
  } finally {
    actionId.value = null;
  }
}

onMounted(() => loadPending());
</script>

<style scoped>
.director-accounts-page {
  padding: 1.5rem;
  max-width: 720px;
}
.director-accounts-page h2 {
  margin: 0 0 0.5rem;
  font-size: 1.25rem;
}
.page-desc {
  color: #666;
  font-size: 0.9rem;
  margin-bottom: 1.25rem;
}
.msg {
  padding: 0.5rem 0.75rem;
  border-radius: 6px;
  margin-bottom: 1rem;
}
.msg.success { background: #e8f5e9; color: #2e7d32; }
.msg.error { background: #ffebee; color: #c62828; }
.loading-hint, .empty-hint {
  color: #78909c;
  padding: 1.5rem;
}
.pending-table-wrap { overflow-x: auto; }
.pending-table {
  width: 100%;
  border-collapse: collapse;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.pending-table th, .pending-table td {
  padding: 12px 14px;
  text-align: left;
  border-bottom: 1px solid #eee;
}
.pending-table th {
  background: #fafafa;
  font-size: 12px;
  font-weight: 600;
  color: #78909c;
  text-transform: uppercase;
}
.pending-table .actions { display: flex; gap: 8px; }
.btn-approve, .btn-reject {
  padding: 6px 12px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  border: none;
  font-family: inherit;
}
.btn-approve {
  background: #e8f5e9;
  color: #2e7d32;
}
.btn-approve:hover:not(:disabled) { background: #c8e6c9; }
.btn-reject {
  background: #ffebee;
  color: #c62828;
}
.btn-reject:hover:not(:disabled) { background: #ffcdd2; }
.btn-approve:disabled, .btn-reject:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
