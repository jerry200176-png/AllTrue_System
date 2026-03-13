<template>
  <div>
    <HelpGuide
      title="教室管理 — 使用說明"
      :items="[
        '左側切換分校後，此頁只顯示<strong>該分校</strong>下的教室。',
        '可新增教室（名稱、容量、備註、啟用狀態），編輯或停用/啟用、刪除。',
        '新增課程時可選擇「分校＋教室」作為上課地點。'
      ]"
      tip="教室名稱建議簡短易識別，例如「教室1」「201」。"
    />
    <div class="card">
      <div class="header-actions">
        <h2>教室管理</h2>
        <p class="ref-hint">管理當前分校的教室，供排課時選擇地點</p>
        <button class="primary" @click="openAdd">+ 新增教室</button>
      </div>

      <div v-if="loading" class="hint">載入中...</div>
      <table v-else-if="rooms.length" class="room-table">
        <thead>
          <tr>
            <th>教室名稱</th>
            <th>容量</th>
            <th>備註</th>
            <th>狀態</th>
            <th>操作</th>
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
              <button class="small" @click="openEdit(r)">編輯</button>
              <button class="small" @click="toggleActive(r)">
                {{ r.is_active ? '停用' : '啟用' }}
              </button>
              <button class="small ghost" @click="confirmDelete(r)">刪除</button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-else class="empty-text">目前此分校尚無教室，請點「新增教室」。</div>
    </div>

    <!-- Add/Edit Modal -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal">
        <h3>{{ editingId ? '編輯教室' : '新增教室' }}</h3>
        <div class="form-group">
          <label>教室名稱 <span class="required">*</span></label>
          <input v-model="form.name" placeholder="例如：教室1、201" maxlength="64" />
        </div>
        <div class="form-group">
          <label>容量（人） <span class="required">*</span></label>
          <input v-model.number="form.capacity" type="number" min="1" placeholder="1" />
        </div>
        <div class="form-group">
          <label>備註</label>
          <textarea v-model="form.memo" rows="2" placeholder="選填" maxlength="512" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; resize:vertical;"></textarea>
        </div>
        <div class="form-group" v-if="editingId">
          <label>
            <input type="checkbox" v-model="form.is_active" /> 啟用
          </label>
        </div>
        <div v-else class="form-group">
          <label>
            <input type="checkbox" v-model="form.is_active" /> 啟用（預設勾選）
          </label>
        </div>
        <div class="actions">
          <button class="ghost" @click="showModal = false">取消</button>
          <button class="primary" @click="submit" :disabled="!form.name || !form.capacity || form.capacity < 1">儲存</button>
        </div>
      </div>
    </div>

    <!-- Delete confirm -->
    <div v-if="deletingRoom" class="modal-overlay" @click.self="deletingRoom = null">
      <div class="modal">
        <h3>確認刪除</h3>
        <p>確定要刪除教室「{{ deletingRoom.name }}」嗎？此操作無法復原。</p>
        <div class="actions">
          <button class="ghost" @click="deletingRoom = null">取消</button>
          <button class="primary" style="background:#c62828;" @click="doDelete">刪除</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import { supabase } from '../supabase';

const props = defineProps({
  branchId: { type: Number, default: null },
});

const API_BASE = '/api/v1';
const rooms = ref([]);
const loading = ref(false);
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
  try {
    const h = await getAuthHeaders();
    const res = await fetch(`${API_BASE}/rooms?branch_id=${props.branchId}`, {
      credentials: 'include',
      headers: h,
    });
    if (!res.ok) {
      rooms.value = [];
      return;
    }
    const data = await res.json();
    rooms.value = Array.isArray(data) ? data : [];
  } catch {
    rooms.value = [];
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
.memo-text { color: var(--text-light); font-size: 0.9rem; }
.status-tag.active { background: #e8f5e9; color: #2e7d32; }
.status-tag.inactive { background: #ffebee; color: #c62828; }
.empty-text { padding: 24px; color: var(--text-light); text-align: center; }
.required { color: #c62828; }
.actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
</style>
