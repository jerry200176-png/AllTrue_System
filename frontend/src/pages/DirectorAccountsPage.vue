<template>
  <div class="director-accounts-page">
    <AtPageHeader
      title="主任管理"
      description="管理主任帳號，包含審核申請、重設密碼與刪除帳號。"
      icon="admin_panel_settings"
      data-guide="director-accounts-header"
    />

    <div v-if="msg" :class="['msg', msg.type]" :role="msg.type === 'error' ? 'alert' : 'status'" aria-live="polite">{{ msg.text }}</div>

    <!-- Active directors -->
    <section class="section">
      <h3 class="section-title">已審核主任</h3>
      <div v-if="loadingActive" class="loading-hint" role="status" aria-live="polite">載入中...</div>
      <div v-else-if="activeError" class="state-card error-state" role="alert" aria-live="assertive">
        <span>{{ activeError }}</span>
        <AtButton shape="rect" variant="secondary" @click="loadActive">重新載入</AtButton>
      </div>
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
              <td data-label="姓名">{{ item.name }}</td>
              <td data-label="帳號">{{ item.account }}</td>
              <td data-label="分校">{{ item.campus_names.join('、') || '—' }}</td>
              <td class="actions" data-label="操作">
                <AtButton shape="rect" size="sm" variant="secondary" @click="openCampusModal(item, $event)" :disabled="actionId === item.id">
                  編輯分校
                </AtButton>
                <AtButton shape="rect" size="sm" variant="secondary" @click="resetPassword(item)" :disabled="actionId === item.id">
                  重設密碼
                </AtButton>
                <AtButton shape="rect" size="sm" variant="danger" @click="destroyDirector(item)" :disabled="actionId === item.id">
                  刪除
                </AtButton>
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
      <div v-if="loading" class="loading-hint" role="status" aria-live="polite">載入中...</div>
      <div v-else-if="pendingError" class="state-card error-state" role="alert" aria-live="assertive">
        <span>{{ pendingError }}</span>
        <AtButton shape="rect" variant="secondary" @click="loadPending">重新載入</AtButton>
      </div>
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
              <td data-label="姓名">{{ item.name }}</td>
              <td data-label="帳號">{{ item.email }}</td>
              <td data-label="分校">{{ item.campus_name }}</td>
              <td class="actions" data-label="操作">
                <AtButton shape="rect" size="sm" variant="primary" @click="approve(item.id)" :disabled="actionId === item.id">通過</AtButton>
                <AtButton shape="rect" size="sm" variant="danger" @click="reject(item.id)" :disabled="actionId === item.id">拒絕</AtButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Edit campus modal -->
    <div v-if="campusModal.visible" class="modal-overlay" @click.self="closeCampusModal" @keydown.esc="closeCampusModal">
      <div ref="campusModalCard" class="modal-card" role="dialog" aria-modal="true" aria-labelledby="campus-modal-title" aria-describedby="campus-modal-hint" tabindex="-1">
        <h3 id="campus-modal-title" class="modal-title">編輯分校 — {{ campusModal.directorName }}</h3>
        <p id="campus-modal-hint" class="modal-hint">勾選此主任可管理的分校（至少一間）</p>
        <div class="campus-checkbox-list">
          <label v-for="c in allCampuses" :key="c.id" class="campus-checkbox-item">
            <input type="checkbox" :value="c.id" v-model="campusModal.selectedIds">
            <span>{{ c.name }}</span>
          </label>
        </div>
        <div v-if="campusError" class="section-msg error" role="alert">{{ campusError }}</div>
        <div class="modal-footer">
          <AtButton shape="rect" variant="ghost" @click="closeCampusModal">取消</AtButton>
          <AtButton shape="rect" variant="primary" :loading="campusModal.saving" @click="saveCampuses" :disabled="campusModal.selectedIds.length === 0">
            {{ campusModal.saving ? '儲存中...' : '儲存' }}
          </AtButton>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, nextTick, onMounted } from 'vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';

const props = defineProps({
  token: { type: String, default: '' },
});

const authHeaders = () => ({ Authorization: `Bearer ${props.token}` });

const list = ref([]);
const loading = ref(true);
const activeList = ref([]);
const loadingActive = ref(true);
const pendingError = ref('');
const activeError = ref('');
const campusError = ref('');
const msg = ref(null);
const actionId = ref(null);
const tempPasswords = ref({});
const allCampuses = ref([]);
const campusModalCard = ref(null);
const campusModalTrigger = ref(null);

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
  pendingError.value = '';
  try {
    const res = await fetch('/api/v1/directors/pending', { headers: authHeaders() });
    const data = await res.json().catch(() => []);
    if (!res.ok) throw new Error(data?.message || '主任申請資料暫時無法載入');
    list.value = Array.isArray(data) ? data : [];
  } catch (error) {
    list.value = [];
    pendingError.value = error?.message || '主任申請資料暫時無法載入';
  } finally {
    loading.value = false;
  }
}

async function loadActive() {
  loadingActive.value = true;
  activeError.value = '';
  try {
    const res = await fetch('/api/v1/directors', { headers: authHeaders() });
    const data = await res.json().catch(() => []);
    if (!res.ok) throw new Error(data?.message || '已審核主任資料暫時無法載入');
    activeList.value = Array.isArray(data) ? data : [];
  } catch (error) {
    activeList.value = [];
    activeError.value = error?.message || '已審核主任資料暫時無法載入';
  } finally {
    loadingActive.value = false;
  }
}

async function loadCampuses() {
  campusError.value = '';
  try {
    const res = await fetch('/api/v1/campuses', { headers: authHeaders() });
    const data = await res.json().catch(() => []);
    if (!res.ok) throw new Error(data?.message || '分校資料暫時無法載入');
    allCampuses.value = Array.isArray(data) ? data : [];
  } catch (error) {
    allCampuses.value = [];
    campusError.value = error?.message || '分校資料暫時無法載入';
  }
}

function openCampusModal(item, event) {
  campusModal.directorId = item.id;
  campusModal.directorName = item.name;
  campusModal.selectedIds = [...(item.campus_ids || [])];
  campusModal.saving = false;
  campusModalTrigger.value = event?.currentTarget || null;
  campusModal.visible = true;
  nextTick(() => campusModalCard.value?.focus());
}

function closeCampusModal() {
  campusModal.visible = false;
  nextTick(() => campusModalTrigger.value?.focus());
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
  box-sizing: border-box;
  max-width: 1200px;
  padding: 1.5rem;
}
.section {
  margin-bottom: 2rem;
}
.section-title {
  font-size: 1rem;
  font-weight: 600;
  color: var(--ds-ink-secondary, #273951);
  margin: 0 0 0.75rem;
}
.msg {
  padding: 0.75rem 1rem;
  border: 1px solid var(--ds-hairline, #e3e8ee);
  border-radius: var(--ds-radius-md, 8px);
  margin-bottom: 1rem;
}
.msg.success { background: color-mix(in srgb, var(--ds-success, #1a8245) 10%, white); color: var(--ds-success, #1a8245); }
.msg.error { background: color-mix(in srgb, var(--ds-danger, #e11d48) 10%, white); color: var(--ds-danger, #e11d48); }
.loading-hint, .empty-hint {
  color: var(--ds-ink-mute, #64748d);
  border: 1px dashed var(--ds-hairline, #e3e8ee);
  border-radius: var(--ds-radius-md, 8px);
  padding: 1.5rem;
}
.state-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--ds-hairline, #e3e8ee);
  border-radius: var(--ds-radius-md, 8px);
}
.error-state {
  color: var(--ds-danger, #e11d48);
  background: color-mix(in srgb, var(--ds-danger, #e11d48) 6%, white);
}
.pending-table-wrap { overflow-x: auto; }
.pending-table {
  width: 100%;
  border-collapse: collapse;
  background: var(--ds-canvas, #fff);
  border: 1px solid var(--ds-hairline, #e3e8ee);
  border-radius: var(--ds-radius-lg, 12px);
  box-shadow: var(--ds-shadow-level-1, 0 2px 8px rgba(0,0,0,.06));
}
.pending-table th, .pending-table td {
  padding: 12px 14px;
  text-align: left;
  border-bottom: 1px solid var(--ds-hairline, #e3e8ee);
  overflow-wrap: anywhere;
}
.pending-table th {
  background: var(--ds-canvas-soft, #f6f9fc);
  font-size: 12px;
  font-weight: 600;
  color: var(--ds-ink-mute, #64748d);
  text-transform: uppercase;
}
.pending-table .actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 300px;
}
.pending-table .actions .at-btn { min-height: var(--ds-control-height-touch, 44px); }
.temp-password {
  font-size: 13px;
  color: var(--ds-ink-secondary, #273951);
  background: var(--ds-canvas-soft, #f6f9fc);
  border: 1px solid var(--ds-hairline, #e3e8ee);
  padding: 4px 8px;
  border-radius: var(--ds-radius-sm, 6px);
  white-space: nowrap;
}
.modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 9000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(15, 23, 42, .48);
}
.modal-card {
  box-sizing: border-box;
  width: min(420px, 100%);
  max-height: min(640px, calc(100dvh - 32px));
  overflow-y: auto;
  padding: 1.5rem;
  background: var(--ds-canvas, #fff);
  border: 1px solid var(--ds-hairline, #e3e8ee);
  border-radius: var(--ds-radius-lg, 12px);
  box-shadow: var(--ds-shadow-level-2, 0 8px 32px rgba(0,0,0,.18));
}
.modal-title { margin: 0 0 0.25rem; font-size: 1.05rem; }
.modal-hint { color: var(--ds-ink-mute, #64748d); font-size: 0.85rem; margin: 0 0 1rem; }
.campus-checkbox-list {
  display: flex; flex-direction: column; gap: 6px;
  max-height: 300px; overflow-y: auto; margin-bottom: 1.25rem;
}
.campus-checkbox-item {
  display: flex; align-items: center; gap: 8px;
  min-height: var(--ds-control-height-touch, 44px);
  padding: 6px 8px; border-radius: var(--ds-radius-sm, 6px); cursor: pointer;
  font-size: 14px;
}
.campus-checkbox-item:hover { background: var(--ds-canvas-soft, #f6f9fc); }
.campus-checkbox-item input[type="checkbox"] {
  width: 20px; height: 20px; accent-color: var(--ds-primary, #533afd);
}
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; }
.modal-footer .at-btn { min-height: var(--ds-control-height-touch, 44px); min-width: 96px; }

@media (max-width: 900px) {
  .director-accounts-page { padding: 1rem; }
  .state-card { align-items: stretch; flex-direction: column; }
  .state-card .at-btn { width: 100%; }
  .pending-table,
  .pending-table tbody,
  .pending-table tr,
  .pending-table td { display: block; width: auto; }
  .pending-table { border: 0; box-shadow: none; background: transparent; }
  .pending-table thead {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
  }
  .pending-table tbody tr {
    margin-bottom: 12px;
    border: 1px solid var(--ds-hairline, #e3e8ee);
    border-radius: var(--ds-radius-lg, 12px);
    background: var(--ds-canvas, #fff);
    box-shadow: var(--ds-shadow-level-1, 0 2px 8px rgba(0,0,0,.06));
  }
  .pending-table td {
    display: grid;
    grid-template-columns: 4.5rem minmax(0, 1fr);
    gap: 12px;
    align-items: start;
    padding: 12px 14px;
    border-bottom: 1px solid var(--ds-hairline, #e3e8ee);
  }
  .pending-table td::before {
    content: attr(data-label);
    color: var(--ds-ink-mute, #64748d);
    font-size: 12px;
    font-weight: 700;
  }
  .pending-table td:last-child { border-bottom: 0; }
  .pending-table .actions {
    display: grid;
    grid-template-columns: 4.5rem minmax(0, 1fr);
    min-width: 0;
    align-items: center;
  }
  .pending-table .actions .at-btn,
  .pending-table .actions .temp-password { grid-column: 2; width: 100%; }
  .modal-footer { flex-direction: column-reverse; }
  .modal-footer .at-btn { width: 100%; }
}
</style>
