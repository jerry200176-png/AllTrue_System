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
      <AtSkeleton v-if="loading" :rows="4" />
      <div v-else>
        <AtInlineAlert v-if="loadError" class="classroom-error" tone="danger" title="教室清單暫時無法載入，請重試。">
          <p>{{ loadError }}</p>
          <template #action><AtButton shape="rect" size="sm" variant="ghost" @click="loadRooms">重試</AtButton></template>
        </AtInlineAlert>
        <AtEmpty v-else-if="!rooms.length" class="empty-text" icon="meeting_room" title="目前此分校尚無教室" description="新增教室後，排課時就能直接選擇上課地點。">
          <template #action><AtButton shape="rect" variant="primary" @click="openAdd">新增教室</AtButton></template>
        </AtEmpty>
        <template v-else>
          <div class="room-table-wrap">
            <table class="room-table" data-guide="classroom-table">
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
                  <td class="room-actions">
                    <AtButton shape="rect" size="sm" variant="ghost" :aria-label="`編輯教室：${r.name}`" @click="openEdit(r)">編輯</AtButton>
                    <AtButton shape="rect" size="sm" variant="ghost" :aria-label="`${r.is_active ? '停用' : '啟用'}教室：${r.name}`" @click="toggleActive(r)">
                      {{ r.is_active ? '停用' : '啟用' }}
                    </AtButton>
                    <AtButton shape="rect" size="sm" variant="ghost" :aria-label="`刪除教室：${r.name}`" @click="confirmDelete(r)">刪除</AtButton>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="room-mobile-list" aria-label="教室清單">
            <article v-for="r in rooms" :key="r.id" class="room-mobile-card">
              <div class="room-mobile-card__head">
                <strong>{{ r.name }}</strong>
                <span :class="['status-tag', r.is_active ? 'active' : 'inactive']">{{ r.is_active ? '啟用' : '停用' }}</span>
              </div>
              <dl class="room-mobile-details">
                <div><dt>容量</dt><dd>{{ r.capacity }} 人</dd></div>
                <div><dt>備註</dt><dd>{{ r.memo || '—' }}</dd></div>
              </dl>
              <div class="room-mobile-actions">
                <AtButton block shape="rect" variant="ghost" :aria-label="`編輯教室：${r.name}`" @click="openEdit(r)">編輯</AtButton>
                <AtButton block shape="rect" variant="ghost" :aria-label="`${r.is_active ? '停用' : '啟用'}教室：${r.name}`" @click="toggleActive(r)">
                  {{ r.is_active ? '停用' : '啟用' }}
                </AtButton>
                <AtButton block shape="rect" variant="ghost" :aria-label="`刪除教室：${r.name}`" @click="confirmDelete(r)">刪除</AtButton>
              </div>
            </article>
          </div>
        </template>
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
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtSkeleton from '../components/design-system/AtSkeleton.vue';
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
.room-table-wrap { overflow-x: auto; }
.room-table { width: 100%; border-collapse: collapse; }
.room-table th, .room-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--ds-hairline); vertical-align: middle; }
.room-table th { font-weight: 600; color: var(--ds-text-secondary); background: var(--ds-surface-0); }
.room-actions { white-space: nowrap; }
.classroom-error { margin: 10px 0; }
.classroom-error p { margin: 0; }
.memo-text { color: var(--ds-text-tertiary); font-size: 13px; overflow-wrap: anywhere; }
.status-tag { display: inline-flex; border-radius: 999px; padding: 4px 9px; font-size: 12px; font-weight: 600; }
.status-tag.active { background: var(--ds-success-wash); color: var(--ds-success); }
.status-tag.inactive { background: var(--ds-danger-wash); color: var(--ds-danger); }
.empty-text { margin: 0; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
.required { color: #c62828; }
.actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
.room-mobile-list { display: none; }
.room-mobile-card { padding: 16px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-lg); background: var(--ds-canvas); }
.room-mobile-card + .room-mobile-card { margin-top: 12px; }
.room-mobile-card__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.room-mobile-details { display: grid; gap: 8px; margin: 16px 0; }
.room-mobile-details > div { display: grid; grid-template-columns: 48px minmax(0, 1fr); gap: 8px; align-items: start; }
.room-mobile-details dt { color: var(--ds-text-tertiary); font-size: 13px; font-weight: 600; }
.room-mobile-details dd { min-width: 0; margin: 0; overflow-wrap: anywhere; color: var(--ds-text-secondary); }
.room-mobile-actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.classroom-page :deep(.at-btn) { min-height: 44px; }
.classroom-page input, .classroom-page textarea { min-height: 44px; }
:global(.classroom-dialog input), :global(.classroom-dialog textarea) { width: 100%; margin-top: 6px; border: 1px solid var(--ds-hairline-input); border-radius: 7px; padding: 9px 10px; background: var(--ds-canvas); color: inherit; font: inherit; }
:global(.classroom-dialog input) { min-height: 44px; }
:global(.classroom-dialog textarea) { min-height: 96px; resize: vertical; }
:global(.classroom-dialog .at-dialog__close), :global(.classroom-dialog .at-btn) { min-width: 44px; min-height: 44px; }
@media (max-width: 720px) {
  .room-table-wrap { display: none; }
  .room-mobile-list { display: block; }
  .room-mobile-actions { grid-template-columns: 1fr; }
}
</style>
