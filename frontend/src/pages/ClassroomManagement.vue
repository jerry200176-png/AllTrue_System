<template>
  <div class="classroom-page tw:mx-auto tw:flex tw:max-w-5xl tw:flex-col tw:gap-4 tw:px-[calc(var(--ds-space-4)-10px)] tw:pb-24 tw:text-foreground tw:md:px-0 tw:md:pb-0" data-testid="classroom-page">
    <header class="tw:pb-2" data-guide="classroom-header">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-2">
        <h2 class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-page tw:font-bold tw:tracking-tight">
          <DoorOpen class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
          教室管理
        </h2>
        <Button class="tw:h-12 tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none" @click="openAdd">
          <Plus class="tw:size-4" :stroke-width="2" aria-hidden="true" />
          新增教室
        </Button>
      </div>
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">管理目前分校的教室，供排課時選擇地點。</p>
    </header>

    <div class="tw:flex tw:flex-col tw:gap-4" :aria-busy="loading ? 'true' : 'false'" data-testid="classroom-content">
      <div v-if="loading" class="tw:flex tw:flex-col tw:gap-3" role="status" aria-live="polite" data-testid="classroom-loading">
        <span class="tw:sr-only">載入中</span>
        <Skeleton v-for="n in 4" :key="n" class="tw:h-14 tw:w-full" />
      </div>
      <Alert v-else-if="loadError" tone="danger" title="教室清單暫時無法載入，請重試。" class="classroom-error tw:text-tpl-body">
        <p>{{ loadError }}</p>
        <Button variant="outline" :class="[rowButton, 'tw:mt-3 tw:text-foreground']" @click="loadRooms">重試</Button>
      </Alert>
      <Card v-else-if="!rooms.length" class="tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none" data-testid="classroom-empty">
        <DoorOpen class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
        <h3 class="tw:text-tpl-list tw:font-bold">目前此分校尚無教室</h3>
        <p class="tw:max-w-prose tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">新增教室後，排課時就能直接選擇上課地點。</p>
        <Button variant="outline" :class="[rowButton, 'tw:mt-2']" @click="openAdd">新增教室</Button>
      </Card>
      <template v-else>
        <Card class="room-table-wrap tw:gap-0 tw:py-0 tw:shadow-none tw:max-[720px]:hidden">
          <Table class="room-table tw:text-[length:var(--tpl-text-body)] tw:[&_td]:text-[length:var(--tpl-text-body)] tw:[&_th]:text-[length:var(--tpl-text-caption)]" data-guide="classroom-table">
            <TableCaption class="tw:sr-only">目前分校教室清單</TableCaption>
            <TableHeader>
              <TableRow class="tw:hover:bg-transparent">
                <TableHead scope="col" :class="headCell">教室名稱</TableHead>
                <TableHead scope="col" :class="headCell">容量</TableHead>
                <TableHead scope="col" :class="headCell">備註</TableHead>
                <TableHead scope="col" :class="headCell">狀態</TableHead>
                <TableHead scope="col" :class="headCell">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="r in rooms" :key="r.id">
                <TableCell class="tw:px-4 tw:py-3 tw:whitespace-normal tw:[overflow-wrap:anywhere]"><strong>{{ r.name }}</strong></TableCell>
                <TableCell class="tw:px-4 tw:py-3 tw:tabular-nums">{{ r.capacity }}</TableCell>
                <TableCell class="tw:px-4 tw:py-3 tw:whitespace-normal tw:text-tpl-secondary tw:[overflow-wrap:anywhere]">{{ r.memo || '—' }}</TableCell>
                <TableCell class="tw:px-4 tw:py-3"><Badge variant="outline" :class="[pillBase, statusTone(r)]">{{ statusLabel(r) }}</Badge></TableCell>
                <TableCell class="tw:px-4 tw:py-2">
                  <div class="tw:flex tw:flex-nowrap tw:items-center tw:gap-2">
                    <Button variant="outline" :class="rowButton" :aria-label="`編輯教室：${r.name}`" @click="openEdit(r)">編輯</Button>
                    <Button variant="outline" :class="rowButton" :aria-label="`${toggleLabel(r)}教室：${r.name}`" @click="toggleActive(r)">{{ toggleLabel(r) }}</Button>
                    <Button variant="outline" :class="[rowButton, dangerButton]" :aria-label="`刪除教室：${r.name}`" @click="confirmDelete(r)">刪除</Button>
                  </div>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </Card>
        <ul class="room-mobile-list tw:flex tw:list-none tw:flex-col tw:gap-3 tw:min-[721px]:hidden" aria-label="教室清單">
          <li v-for="r in rooms" :key="r.id">
            <Card class="room-mobile-card tw:gap-0 tw:py-4 tw:shadow-none">
              <CardContent class="tw:flex tw:flex-col tw:gap-3 tw:px-4">
                <div class="tw:flex tw:items-start tw:justify-between tw:gap-3">
                  <strong class="tw:min-w-0 tw:text-tpl-list tw:[overflow-wrap:anywhere]">{{ r.name }}</strong>
                  <Badge variant="outline" :class="[pillBase, statusTone(r), 'tw:shrink-0']">{{ statusLabel(r) }}</Badge>
                </div>
                <dl class="tw:grid tw:gap-2 tw:text-tpl-body">
                  <div class="tw:grid tw:grid-cols-[3.5rem_minmax(0,1fr)] tw:gap-2">
                    <dt class="tw:font-semibold tw:text-tpl-secondary">容量</dt>
                    <dd class="tw:tabular-nums">{{ r.capacity }} 人</dd>
                  </div>
                  <div class="tw:grid tw:grid-cols-[3.5rem_minmax(0,1fr)] tw:gap-2">
                    <dt class="tw:font-semibold tw:text-tpl-secondary">備註</dt>
                    <dd class="tw:min-w-0 tw:[overflow-wrap:anywhere]">{{ r.memo || '—' }}</dd>
                  </div>
                </dl>
                <div class="tw:grid tw:grid-cols-3 tw:gap-2">
                  <Button variant="outline" :class="[rowButton, 'tw:px-2']" :aria-label="`編輯教室：${r.name}`" @click="openEdit(r)">編輯</Button>
                  <Button variant="outline" :class="[rowButton, 'tw:px-2']" :aria-label="`${toggleLabel(r)}教室：${r.name}`" @click="toggleActive(r)">{{ toggleLabel(r) }}</Button>
                  <Button variant="outline" :class="[rowButton, dangerButton, 'tw:px-2']" :aria-label="`刪除教室：${r.name}`" @click="confirmDelete(r)">刪除</Button>
                </div>
              </CardContent>
            </Card>
          </li>
        </ul>
      </template>
    </div>

    <!-- Add / edit. The save button stays enabled, so each required field says why nothing was saved. -->
    <FormDialog
      :open="showModal"
      :title="editingId ? '編輯教室' : '新增教室'"
      description="標示 * 的欄位必填。"
      :loading="saving"
      @update:open="showModal = $event"
      @submit="onSubmit"
    >
      <div class="tw:flex tw:flex-col tw:gap-4">
        <div class="tw:flex tw:flex-col tw:gap-2">
          <Label for="classroom-name" :class="fieldLabel">教室名稱 <span class="tw:text-destructive">*</span></Label>
          <Input id="classroom-name" v-model="form.name" :class="fieldControl" placeholder="例如：教室1、201" maxlength="64" required :aria-invalid="nameError ? 'true' : undefined" :aria-describedby="nameError ? 'classroom-name-error' : undefined" />
          <p v-if="nameError" id="classroom-name-error" class="tw:text-tpl-caption tw:text-destructive" role="alert">{{ nameError }}</p>
        </div>
        <div class="tw:flex tw:flex-col tw:gap-2">
          <Label for="classroom-capacity" :class="fieldLabel">容量（人） <span class="tw:text-destructive">*</span></Label>
          <Input id="classroom-capacity" :model-value="form.capacity" :class="fieldControl" type="number" inputmode="numeric" min="1" step="any" placeholder="1" required :aria-invalid="capacityError ? 'true' : undefined" :aria-describedby="capacityError ? 'classroom-capacity-error' : undefined" @update:model-value="setCapacity" />
          <p v-if="capacityError" id="classroom-capacity-error" class="tw:text-tpl-caption tw:text-destructive" role="alert">{{ capacityError }}</p>
        </div>
        <div class="tw:flex tw:flex-col tw:gap-2">
          <Label for="classroom-memo" :class="fieldLabel">備註</Label>
          <Textarea id="classroom-memo" v-model="form.memo" class="tw:min-h-24 tw:resize-y tw:text-[length:var(--tpl-text-body)] tw:md:text-[length:var(--tpl-text-body)]" rows="2" placeholder="選填" maxlength="512" />
        </div>
        <label :for="activeId" class="tw:flex tw:min-h-12 tw:cursor-pointer tw:items-center tw:gap-3 tw:text-tpl-body tw:font-semibold">
          <Checkbox :id="activeId" v-model="form.is_active" class="tw:size-6" />
          {{ editingId ? '啟用' : '啟用（預設勾選）' }}
        </label>
      </div>
    </FormDialog>

    <ConfirmDialog
      :open="deleteOpen"
      title="確認刪除"
      :description="deletingRoom ? `確定要刪除教室「${deletingRoom.name}」嗎？此操作無法復原。` : ''"
      confirm-label="刪除"
      destructive
      :loading="deleting"
      @update:open="onDeleteOpenChange"
      @confirm="onDeleteConfirm"
    />
  </div>
</template>

<script setup>
import { computed, nextTick, ref, watch, onMounted } from 'vue';
import { DoorOpen, Plus } from 'lucide-vue-next';
import { ConfirmDialog, FormDialog } from '@/components/app-overlay';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
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
const deleteOpen = ref(false);
const saving = ref(false);
const deleting = ref(false);
const nameError = ref('');
const capacityError = ref('');

const form = ref({
  name: '',
  capacity: 1,
  memo: '',
  is_active: true,
});

// Presentation only. Table heads override the global `th` rule; row buttons are 48px tall.
const headCell = 'tw:h-12 tw:px-4 tw:font-semibold tw:normal-case tw:text-tpl-secondary';
const rowButton = 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none';
const dangerButton = 'tw:border-[var(--ds-danger-text)] tw:text-[color:var(--ds-danger-text)] tw:hover:bg-[var(--ds-danger-wash)] tw:hover:text-[color:var(--ds-danger-text)]';
const pillBase = 'tw:border-transparent tw:px-3 tw:py-1 tw:text-[length:var(--tpl-text-caption)] tw:font-bold';
const fieldLabel = 'tw:text-tpl-body tw:font-semibold';
const fieldControl = 'tw:h-12 tw:text-[length:var(--tpl-text-body)] tw:md:text-[length:var(--tpl-text-body)]';
const statusLabel = (r) => (r.is_active ? '啟用' : '停用');
const toggleLabel = (r) => (r.is_active ? '停用' : '啟用');
const statusTone = (r) => (r.is_active ? 'tw:bg-[var(--ds-success-wash)] tw:text-[color:var(--ds-success-text)]' : 'tw:bg-secondary tw:text-tpl-secondary');
const activeId = computed(() => (editingId.value ? 'classroom-active-edit' : 'classroom-active-create'));

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

// Same value the old `v-model.number` produced: a number, or the raw text when it is not one ('' when empty).
function setCapacity(value) {
  const n = parseFloat(value);
  form.value.capacity = Number.isNaN(n) ? value : n;
  capacityError.value = '';
}
// A field's error goes away as soon as that field is edited.
watch(() => form.value.name, () => { nameError.value = ''; });

function clearFieldErrors() {
  nameError.value = '';
  capacityError.value = '';
}

function openAdd() {
  editingId.value = null;
  form.value = { name: '', capacity: 1, memo: '', is_active: true };
  clearFieldErrors();
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
  clearFieldErrors();
  showModal.value = true;
}

async function submit() {
  // The save button is always enabled: say which field stopped the save, next to that field, and call no API.
  const nameMissing = !String(form.value.name ?? '').trim();
  const capacityMissing = !form.value.capacity || form.value.capacity < 1;
  nameError.value = nameMissing ? '請輸入教室名稱' : '';
  capacityError.value = capacityMissing ? '請輸入至少 1 人的容量' : '';
  if (nameMissing || capacityMissing) {
    document.getElementById(nameMissing ? 'classroom-name' : 'classroom-capacity')?.focus();
    return;
  }
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

// One request at a time: the dialog's buttons are disabled while `saving`, and Enter is ignored too.
async function onSubmit() {
  if (saving.value) return;
  saving.value = true;
  try { await submit(); } finally { saving.value = false; }
}

function confirmDelete(r) {
  deletingRoom.value = r;
  deleteOpen.value = true;
}

// The 刪除 button emits both a close request and `confirm` in the same click (order is the library's),
// so the close is decided one tick later: while a delete is running the dialog stays open.
async function onDeleteOpenChange(open) {
  await nextTick();
  if (deleting.value) return;
  deleteOpen.value = open;
  if (!open) deletingRoom.value = null;
}

// A failed delete keeps the dialog open (as before) so the director can retry or cancel.
async function onDeleteConfirm() {
  if (deleting.value || !deletingRoom.value) return;
  deleting.value = true;
  try { await doDelete(); } finally { deleting.value = false; }
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
    deleteOpen.value = false;
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
