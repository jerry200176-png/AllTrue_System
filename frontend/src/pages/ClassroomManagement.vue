<template>
  <div class="classroom-page">
    <AtPageHeader
      title="教室管理"
      description="管理目前分校的教室，供排課時選擇地點。"
      icon="meeting_room"
      data-guide="classroom-header"
    >
      <template #actions>
        <AtButton shape="rect" variant="primary" icon="add" @click="openAdd">新增教室</AtButton>
      </template>
    </AtPageHeader>

    <div class="card" :aria-busy="loading ? 'true' : 'false'">
      <div v-if="loading" class="hint" role="status" aria-live="polite">載入中…</div>
      <div v-else>
        <div v-if="loadError" class="classroom-error" role="alert">
          <p>{{ loadError }}</p>
          <button type="button" class="small" @click="loadRooms">重試</button>
        </div>
        <table v-if="rooms.length" class="room-table" data-guide="classroom-table">
          <caption class="sr-only">目前分校教室清單</caption>
          <thead>
            <tr>
              <th scope="col">教室名稱</th>
              <th scope="col">容量</th>
              <th scope="col">備註</th>
              <th scope="col">狀態</th>
              <th scope="col">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rooms" :key="r.id">
              <td><strong>{{ r.name }}</strong></td>
              <td>{{ r.capacity }}</td>
              <td><span class="memo-text">{{ r.memo || '—' }}</span></td>
              <td>
                <span :class="['status-tag', r.is_active ? 'active' : 'inactive']">
                  {{ r.is_active ? '啟用' : '停用' }}
                </span>
              </td>
              <td>
                <button type="button" class="small" :aria-label="`編輯教室：${r.name}`" @click="openEdit(r)">編輯</button>
                <button type="button" class="small" :aria-label="`${r.is_active ? '停用' : '啟用'}教室：${r.name}`" @click="toggleActive(r)">
                  {{ r.is_active ? '停用' : '啟用' }}
                </button>
                <button type="button" class="small ghost" :aria-label="`刪除教室：${r.name}`" @click="confirmDelete(r)">刪除</button>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-else-if="!loadError" class="empty-text" role="status">
          <p>目前此分校尚無教室。</p>
          <button type="button" class="small" @click="openAdd">新增教室</button>
        </div>
      </div>
    </div>

    <!-- Add/Edit dialog -->
    <AtDialog
      :open="showModal"
      :title="editingId ? '編輯教室' : '新增教室'"
      panel-class="classroom-dialog"
      @close="showModal = false"
    >
        <div class="form-group">
          <label for="classroom-name">教室名稱 <span class="required">*</span></label>
          <input id="classroom-name" v-model="form.name" placeholder="例如：教室1、201" maxlength="64" />
        </div>
        <div class="form-group">
          <label for="classroom-capacity">容量（人） <span class="required">*</span></label>
          <input id="classroom-capacity" v-model.number="form.capacity" type="number" min="1" placeholder="1" />
        </div>
        <div class="form-group">
          <label for="classroom-memo">備註</label>
          <textarea id="classroom-memo" v-model="form.memo" rows="2" placeholder="選填" maxlength="512" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; resize:vertical;"></textarea>
        </div>
        <div class="form-group" v-if="editingId">
          <label>
            <input id="classroom-active-edit" type="checkbox" v-model="form.is_active" /> 啟用
          </label>
        </div>
        <div v-else class="form-group">
          <label>
            <input id="classroom-active-create" type="checkbox" v-model="form.is_active" /> 啟用（預設勾選）
          </label>
        </div>
        <template #actions>
          <AtButton shape="rect" variant="ghost" @click="showModal = false">取消</AtButton>
          <AtButton shape="rect" variant="primary" @click="submit" :disabled="!form.name || !form.capacity || form.capacity < 1">儲存</AtButton>
        </template>
    </AtDialog>

    <!-- Delete confirmation dialog -->
    <AtDialog
      :open="Boolean(deletingRoom)"
      title="確認刪除"
      size="sm"
      panel-class="classroom-dialog"
      @close="deletingRoom = null"
    >
        <p>確定要刪除教室「{{ deletingRoom.name }}」嗎？此操作無法復原。</p>
        <template #actions>
          <AtButton shape="rect" variant="ghost" @click="deletingRoom = null">取消</AtButton>
          <AtButton shape="rect" variant="danger" @click="doDelete">刪除</AtButton>
        </template>
    </AtDialog>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtDialog from '../components/design-system/AtDialog.vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import { supabase } from '../supabase';

const props = defineProps({
  branchId: { type: Number, default: null },
});

const API_BASE = '/api/v1';
const rooms = ref([]);
const loading = ref(false);
const loadError = ref('');
const showModal = ref(false);
const editingId = ref(null);
const deletingRoom = ref(null);

const form = ref({
  name: '',
  capacity: 1,
  memo: '',
  is_active: true,
});

/** Same token source as rest of app: Laravel session in localStorage (alltrue_session). */
async function getAuthHeaders() {
  const { data: { session } } = await supabase.auth.getSession();
  const token = session?.access_token;
  const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;
  return headers;
}

async function loadRooms() {
  if (props.branchId == null || props.branchId === '') {
    rooms.value = [];
    return;
  }
  loading.value = true;
  loadError.value = '';
  try {
    const h = await getAuthHeaders();
    const res = await fetch(`${API_BASE}/rooms?branch_id=${props.branchId}`, {
      credentials: 'include',
      headers: h,
    });
    if (!res.ok) {
      loadError.value = '教室清單暫時無法載入，請重試。';
      return;
    }
    const data = await res.json();
    if (!Array.isArray(data)) {
      loadError.value = '教室清單暫時無法載入，請重試。';
      return;
    }
    rooms.value = data;
  } catch {
    loadError.value = '教室清單暫時無法載入，請重試。';
  } finally {
    loading.value = false;
  }
}

function openAdd() {
  editingId.value = null;
  form.value = { name: '', capacity: 1, memo: '', is_active: true };
  showModal.value = true;
}

function openEdit(r) {
  editingId.value = r.id;
  form.value = {
    name: r.name,
    capacity: r.capacity,
    memo: r.memo || '',
    is_active: r.is_active !== false,
  };
  showModal.value = true;
}

async function submit() {
  if (!form.value.name || !form.value.capacity || form.value.capacity < 1) return;
  const id = editingId.value;
  const url = id ? `${API_BASE}/rooms/${id}` : `${API_BASE}/rooms`;
  const method = id ? 'PUT' : 'POST';
  const body = id
    ? { name: form.value.name, capacity: form.value.capacity, memo: form.value.memo || null, is_active: form.value.is_active }
    : { name: form.value.name, capacity: form.value.capacity, campus_id: props.branchId, memo: form.value.memo || null, is_active: form.value.is_active };

  try {
    const res = await fetch(url, {
      method,
      credentials: 'include',
      headers: await getAuthHeaders(),
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      alert(err.message || '儲存失敗');
      return;
    }
    showModal.value = false;
    await loadRooms();
  } catch (e) {
    alert('儲存失敗：' + (e.message || '網路錯誤'));
  }
}

function confirmDelete(r) {
  deletingRoom.value = r;
}

async function doDelete() {
  if (!deletingRoom.value) return;
  const id = deletingRoom.value.id;
  try {
    const res = await fetch(`${API_BASE}/rooms/${id}`, {
      method: 'DELETE',
      credentials: 'include',
      headers: await getAuthHeaders(),
    });
    if (!res.ok) {
      alert('刪除失敗');
      return;
    }
    deletingRoom.value = null;
    await loadRooms();
  } catch (e) {
    alert('刪除失敗：' + (e.message || '網路錯誤'));
  }
}

async function toggleActive(r) {
  const next = !r.is_active;
  try {
    const res = await fetch(`${API_BASE}/rooms/${r.id}`, {
      method: 'PUT',
      credentials: 'include',
      headers: await getAuthHeaders(),
      body: JSON.stringify({ is_active: next }),
    });
    if (!res.ok) {
      alert('更新失敗');
      return;
    }
    r.is_active = next;
  } catch (e) {
    alert('更新失敗：' + (e.message || '網路錯誤'));
  }
}

watch(() => props.branchId, () => loadRooms(), { immediate: true });
onMounted(() => loadRooms());
</script>

<style scoped>
.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 12px;
}
.header-actions h2 { margin: 0 0 4px 0; }
.ref-hint { color: var(--text-light); font-size: 0.9rem; margin: 0; }
.room-table { width: 100%; border-collapse: collapse; }
.room-table th, .room-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; }
.room-table th { font-weight: 600; background: #f8f9fa; }
.classroom-error { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; margin-bottom: 16px; color: var(--danger, var(--ds-danger)); background: var(--danger-bg, var(--ds-canvas-soft)); border: 1px solid currentColor; border-radius: 8px; }
.classroom-error p { margin: 0; }
.memo-text { color: var(--text-light); font-size: 0.9rem; }
.status-tag.active { background: #e8f5e9; color: #2e7d32; }
.status-tag.inactive { background: #ffebee; color: #c62828; }
.empty-text { padding: 24px; color: var(--text-light); text-align: center; }
.empty-text p { margin: 0 0 12px; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
.required { color: #c62828; }
.actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
</style>
