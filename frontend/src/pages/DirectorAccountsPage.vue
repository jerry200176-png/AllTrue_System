<template>
  <div class="director-accounts-page">
    <div data-guide="director-accounts-header">
      <h2>主任管理</h2>
      <p class="page-desc">管理主任帳號，包含審核申請、重設密碼與刪除帳號。</p>
    </div>

    <div v-if="msg" :class="['msg', msg.type]">{{ msg.text }}</div>

    <!-- Active directors -->
    <section class="section">
      <h3 class="section-title">已審核主任</h3>
      <div v-if="loadingActive" class="loading-hint">載入中...</div>
      <div v-else-if="activeList.length === 0" class="empty-hint">目前沒有已審核的主任</div>
      <div v-else class="pending-table-wrap">
        <table class="pending-table">
          <thead>
            <tr>
              <th>姓名</th>
              <th>帳號</th>
              <th>分校</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in activeList" :key="item.id">
              <td>{{ item.name }}</td>
              <td>{{ item.account }}</td>
              <td>{{ item.campus_names.join('、') || '—' }}</td>
              <td class="actions">
                <button type="button" class="btn-campus" @click="openCampusModal(item)" :disabled="actionId === item.id">
                  編輯分校
                </button>
                <button type="button" class="btn-reset" @click="resetPassword(item)" :disabled="actionId === item.id">
                  重設密碼
                </button>
                <button type="button" class="btn-delete" @click="destroyDirector(item)" :disabled="actionId === item.id">
                  刪除
                </button>
                <span v-if="tempPasswords[item.id]" class="temp-password">
                  新密碼：<strong>{{ tempPasswords[item.id] }}</strong>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Pending approvals -->
    <section class="section" data-guide="director-accounts-table">
      <h3 class="section-title">待審申請</h3>
      <div v-if="loading" class="loading-hint">載入中...</div>
      <div v-else-if="list.length === 0" class="empty-hint">目前沒有待審申請</div>
      <div v-else class="pending-table-wrap">
        <table class="pending-table">
          <thead>
            <tr>
              <th>姓名</th>
              <th>帳號</th>
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
    </section>

    <!-- Edit campus modal -->
    <div v-if="campusModal.visible" class="modal-overlay" @click.self="closeCampusModal">
      <div class="modal-card">
        <h3 class="modal-title">編輯分校 — {{ campusModal.directorName }}</h3>
        <p class="modal-hint">勾選此主任可管理的分校（至少一間）</p>
        <div class="campus-checkbox-list">
          <label v-for="c in allCampuses" :key="c.id" class="campus-checkbox-item">
            <input type="checkbox" :value="c.id" v-model="campusModal.selectedIds">
            <span>{{ c.name }}</span>
          </label>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-cancel" @click="closeCampusModal">取消</button>
          <button type="button" class="btn-save" @click="saveCampuses" :disabled="campusModal.saving || campusModal.selectedIds.length === 0">
            {{ campusModal.saving ? '儲存中...' : '儲存' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';

const props = defineProps({
  token: { type: String, default: '' },
});

const authHeaders = () => ({ Authorization: `Bearer ${props.token}` });

const list = ref([]);
const loading = ref(true);
const activeList = ref([]);
const loadingActive = ref(true);
const msg = ref(null);
const actionId = ref(null);
const tempPasswords = ref({});
const allCampuses = ref([]);

const campusModal = reactive({
  visible: false,
  directorId: null,
  directorName: '',
  selectedIds: [],
  saving: false,
});

async function loadAll() {
  if (!props.token) return;
  await Promise.all([loadPending(), loadActive(), loadCampuses()]);
}

async function loadPending() {
  loading.value = true;
  try {
    const res = await fetch('/api/v1/directors/pending', { headers: authHeaders() });
    const data = await res.json().catch(() => []);
    list.value = Array.isArray(data) ? data : [];
  } finally {
    loading.value = false;
  }
}

async function loadActive() {
  loadingActive.value = true;
  try {
    const res = await fetch('/api/v1/directors', { headers: authHeaders() });
    const data = await res.json().catch(() => []);
    activeList.value = Array.isArray(data) ? data : [];
  } finally {
    loadingActive.value = false;
  }
}

async function loadCampuses() {
  try {
    const res = await fetch('/api/v1/campuses', { headers: authHeaders() });
    const data = await res.json().catch(() => []);
    allCampuses.value = Array.isArray(data) ? data : [];
  } catch (_) { /* noop */ }
}

function openCampusModal(item) {
  campusModal.directorId = item.id;
  campusModal.directorName = item.name;
  campusModal.selectedIds = [...(item.campus_ids || [])];
  campusModal.saving = false;
  campusModal.visible = true;
}

function closeCampusModal() {
  campusModal.visible = false;
}

async function saveCampuses() {
  if (!props.token || campusModal.selectedIds.length === 0) return;
  campusModal.saving = true;
  try {
    const res = await fetch(`/api/v1/directors/${campusModal.directorId}/campuses`, {
      method: 'PUT',
      headers: { ...authHeaders(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ campus_ids: campusModal.selectedIds }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      msg.value = { type: 'error', text: data?.message || '更新失敗' };
      return;
    }
    msg.value = { type: 'success', text: `已更新「${campusModal.directorName}」的分校` };
    closeCampusModal();
    await loadActive();
  } finally {
    campusModal.saving = false;
  }
}

async function approve(id) {
  if (!props.token) return;
  actionId.value = id;
  msg.value = null;
  try {
    const res = await fetch(`/api/v1/directors/${id}/approve`, {
      method: 'POST',
      headers: authHeaders(),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      msg.value = { type: 'error', text: data?.message || '操作失敗' };
      return;
    }
    msg.value = { type: 'success', text: '已通過審核' };
    await loadAll();
    window.dispatchEvent(new CustomEvent('alltrue-refresh-badges'));
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
      headers: authHeaders(),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      msg.value = { type: 'error', text: data?.message || '操作失敗' };
      return;
    }
    msg.value = { type: 'success', text: '已拒絕' };
    await loadAll();
    window.dispatchEvent(new CustomEvent('alltrue-refresh-badges'));
  } finally {
    actionId.value = null;
  }
}

async function destroyDirector(item) {
  if (!props.token) return;
  if (
    !confirm(
      `確定要刪除主任「${item.name}」(${item.account})？\n此操作無法復原，該帳號將無法再登入。`
    )
  ) {
    return;
  }
  actionId.value = item.id;
  msg.value = null;
  try {
    const res = await fetch(`/api/v1/directors/${item.id}`, {
      method: 'DELETE',
      headers: authHeaders(),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      msg.value = { type: 'error', text: data?.message || '刪除失敗' };
      return;
    }
    const nextTemps = { ...tempPasswords.value };
    delete nextTemps[item.id];
    tempPasswords.value = nextTemps;
    msg.value = { type: 'success', text: data?.message || '主任帳號已刪除' };
    await loadAll();
  } finally {
    actionId.value = null;
  }
}

async function resetPassword(item) {
  if (!props.token) return;
  if (!confirm(`確認重設「${item.name}」的密碼？\n重設後他們下次登入時需要更改密碼。`)) return;
  actionId.value = item.id;
  msg.value = null;
  try {
    const res = await fetch(`/api/v1/directors/${item.id}/reset-password`, {
      method: 'POST',
      headers: authHeaders(),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      msg.value = { type: 'error', text: data?.message || '重設失敗' };
      return;
    }
    const newPassword = String(data?.temporary_password || '').trim();
    if (!newPassword) {
      msg.value = { type: 'error', text: '重設成功但未取得新密碼，請稍後重試' };
      return;
    }
    tempPasswords.value = { ...tempPasswords.value, [item.id]: newPassword };
    try {
      await navigator.clipboard.writeText(`${item.account},${newPassword}`);
      msg.value = { type: 'success', text: `已重設「${item.name}」的密碼，新密碼已複製到剪貼簿` };
    } catch (_) {
      msg.value = { type: 'success', text: `已重設「${item.name}」的密碼` };
    }
  } finally {
    actionId.value = null;
  }
}

onMounted(() => loadAll());
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
.section {
  margin-bottom: 2rem;
}
.section-title {
  font-size: 1rem;
  font-weight: 600;
  color: #455a64;
  margin: 0 0 0.75rem;
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
.btn-reset {
  padding: 6px 12px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  border: none;
  font-family: inherit;
  background: #fff3e0;
  color: #e65100;
}
.btn-reset:hover:not(:disabled) { background: #ffe0b2; }
.btn-reset:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-delete {
  padding: 6px 12px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  border: none;
  font-family: inherit;
  background: #fce4ec;
  color: #c2185b;
}
.btn-delete:hover:not(:disabled) { background: #f8bbd9; }
.btn-delete:disabled { opacity: 0.6; cursor: not-allowed; }
.temp-password {
  font-size: 13px;
  color: #455a64;
  background: #f5f5f5;
  padding: 4px 8px;
  border-radius: 4px;
  white-space: nowrap;
}
.btn-campus {
  padding: 6px 12px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  border: none;
  font-family: inherit;
  background: #e3f2fd;
  color: #1565c0;
}
.btn-campus:hover:not(:disabled) { background: #bbdefb; }
.btn-campus:disabled { opacity: 0.6; cursor: not-allowed; }
.pending-table .actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.modal-overlay {
  position: fixed; inset: 0; z-index: 9000;
  background: rgba(0,0,0,.4);
  display: flex; align-items: center; justify-content: center;
}
.modal-card {
  background: #fff; border-radius: 12px; padding: 1.5rem;
  width: 360px; max-width: 92vw; max-height: 80vh; overflow-y: auto;
  box-shadow: 0 8px 32px rgba(0,0,0,.18);
}
.modal-title { margin: 0 0 0.25rem; font-size: 1.05rem; }
.modal-hint { color: #78909c; font-size: 0.85rem; margin: 0 0 1rem; }
.campus-checkbox-list {
  display: flex; flex-direction: column; gap: 6px;
  max-height: 300px; overflow-y: auto; margin-bottom: 1.25rem;
}
.campus-checkbox-item {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 8px; border-radius: 6px; cursor: pointer;
  font-size: 14px;
}
.campus-checkbox-item:hover { background: #f5f5f5; }
.campus-checkbox-item input[type="checkbox"] {
  width: 16px; height: 16px; accent-color: #1565c0;
}
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; }
.btn-cancel {
  padding: 8px 16px; border-radius: 6px; font-size: 13px;
  cursor: pointer; border: 1px solid #ccc; background: #fff;
  font-family: inherit; color: #455a64;
}
.btn-cancel:hover { background: #f5f5f5; }
.btn-save {
  padding: 8px 16px; border-radius: 6px; font-size: 13px;
  cursor: pointer; border: none; background: #1565c0; color: #fff;
  font-family: inherit;
}
.btn-save:hover:not(:disabled) { background: #0d47a1; }
.btn-save:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
