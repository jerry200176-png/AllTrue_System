<template>
  <div class="subject-settings-page tw:mx-auto tw:flex tw:max-w-3xl tw:flex-col tw:gap-4 tw:px-[calc(var(--ds-space-4)-10px)] tw:text-foreground tw:md:px-0" data-testid="subject-settings-page">
    <header class="tw:pb-2" data-guide="subject-settings-header">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-2">
        <h2 class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-page tw:font-bold tw:tracking-tight">
          <LibraryBig class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
          科目管理
        </h2>
        <Button class="tw:h-12 tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none" @click="openAdd">
          <Plus class="tw:size-4" :stroke-width="2" aria-hidden="true" />
          新增科目
        </Button>
      </div>
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">管理目前分校可選用的科目；共用科目由系統預設。</p>
    </header>

    <p v-if="loading" class="tw:py-5 tw:text-center tw:text-tpl-body tw:text-tpl-secondary" role="status" aria-live="polite" data-testid="subject-settings-loading">載入中...</p>
    <Alert v-else-if="loadError" tone="danger" title="無法載入科目列表" class="tw:text-tpl-body">
      <p>{{ loadError }}</p>
      <Button variant="outline" :class="[rowButton, 'tw:mt-3 tw:text-foreground']" @click="load">重試</Button>
    </Alert>
    <template v-else-if="subjects.length">
      <Card class="subject-table-wrap tw:gap-0 tw:py-0 tw:shadow-none tw:max-[600px]:hidden">
        <Table class="subject-table tw:text-[length:var(--tpl-text-body)] tw:[&_td]:text-[length:var(--tpl-text-body)] tw:[&_th]:text-[length:var(--tpl-text-caption)]">
          <TableHeader>
            <TableRow class="tw:hover:bg-transparent">
              <TableHead :class="headCell">科目名稱</TableHead>
              <TableHead :class="headCell">類型</TableHead>
              <TableHead :class="headCell">操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="s in subjects" :key="s.id">
              <TableCell class="tw:px-4 tw:py-3 tw:whitespace-normal tw:[overflow-wrap:anywhere]"><strong>{{ s.label }}</strong></TableCell>
              <TableCell class="tw:px-4 tw:py-3"><Badge variant="outline" :class="[pillBase, typeTone(s)]">{{ typeLabel(s) }}</Badge></TableCell>
              <TableCell class="tw:px-4 tw:py-2">
                <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                  <Button variant="outline" :class="rowButton" :disabled="locked(s)" :title="locked(s) ? '共用科目僅超級管理員可更名' : ''" @click="openEdit(s)">更名</Button>
                  <Button variant="outline" :class="[rowButton, dangerButton]" :disabled="locked(s)" :title="locked(s) ? '共用科目僅超級管理員可刪除' : ''" @click="confirmDelete(s)">刪除</Button>
                </div>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </Card>
      <ul class="subject-mobile-list tw:flex tw:list-none tw:flex-col tw:gap-3 tw:min-[601px]:hidden" aria-label="科目清單">
        <li v-for="s in subjects" :key="s.id">
          <Card class="subject-mobile-card tw:gap-0 tw:py-4 tw:shadow-none">
            <CardContent class="tw:flex tw:flex-col tw:gap-3 tw:px-4">
              <div class="tw:flex tw:items-start tw:justify-between tw:gap-3">
                <strong class="tw:min-w-0 tw:text-tpl-list tw:[overflow-wrap:anywhere]">{{ s.label }}</strong>
                <Badge variant="outline" :class="[pillBase, typeTone(s), 'tw:shrink-0']">{{ typeLabel(s) }}</Badge>
              </div>
              <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-end tw:gap-2">
                <Button variant="outline" :class="rowButton" :disabled="locked(s)" :title="locked(s) ? '共用科目僅超級管理員可更名' : ''" @click="openEdit(s)">更名</Button>
                <Button variant="outline" :class="[rowButton, dangerButton]" :disabled="locked(s)" :title="locked(s) ? '共用科目僅超級管理員可刪除' : ''" @click="confirmDelete(s)">刪除</Button>
              </div>
            </CardContent>
          </Card>
        </li>
      </ul>
    </template>
    <Card v-else class="tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none" data-testid="subject-settings-empty">
      <BookOpen class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
      <h3 class="tw:text-tpl-list tw:font-bold">目前沒有科目資料</h3>
      <p class="tw:max-w-prose tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">新增第一個分校科目後，課程建立時就能選用。</p>
    </Card>

    <!-- Add / rename. Enter and the save button both submit the form, so they share one pending guard (#4488). -->
    <FormDialog
      :open="showModal"
      :title="editingId ? '科目更名' : '新增科目'"
      description="最多 16 個字。"
      size="sm"
      :loading="saving"
      @update:open="showModal = $event"
      @submit="onSubmit"
    >
      <div class="tw:flex tw:flex-col tw:gap-2">
        <Label for="subject-name" class="tw:text-tpl-body tw:font-semibold">科目名稱 <span class="tw:text-destructive">*</span></Label>
        <Input id="subject-name" v-model="formName" class="tw:h-12 tw:text-[length:var(--tpl-text-body)] tw:md:text-[length:var(--tpl-text-body)]" placeholder="例如：程式設計" maxlength="16" required />
        <p v-if="formError" class="tw:text-tpl-caption tw:text-destructive" role="alert">{{ formError }}</p>
      </div>
    </FormDialog>

    <ConfirmDialog
      :open="deleteOpen"
      title="確認刪除"
      :description="deletingSubject ? `確定要刪除科目「${deletingSubject.label}」嗎？若有課程使用此科目則無法刪除。` : ''"
      confirm-label="刪除"
      destructive
      :loading="deleting"
      @update:open="onDeleteOpenChange"
      @confirm="onDeleteConfirm"
    />
  </div>
</template>

<script setup>
import { nextTick, ref, watch, onMounted } from 'vue';
import { BookOpen, LibraryBig, Plus } from 'lucide-vue-next';
import { ConfirmDialog, FormDialog } from '@/components/app-overlay';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { fetchSubjectOptions, createSubject, updateSubject, deleteSubject } from '../lib/subjectsApi';

const props = defineProps({
  branchId: [String, Number],
  userRole: String,
});

const isSuperAdmin = ref(false);
watch(() => props.userRole, (r) => { isSuperAdmin.value = r === 'super_admin'; }, { immediate: true });

const subjects = ref([]);
const loading = ref(false);
const loadError = ref('');
const showModal = ref(false);
const editingId = ref(null);
const formName = ref('');
const formError = ref('');
const deletingSubject = ref(null);
const deleteOpen = ref(false);
const saving = ref(false);
const deleting = ref(false);

// Presentation only. Table heads override the global `th` rule; row buttons are 48px tall.
const headCell = 'tw:h-12 tw:px-4 tw:font-semibold tw:normal-case tw:text-tpl-secondary';
const rowButton = 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none';
const dangerButton = 'tw:border-[var(--ds-danger-text)] tw:text-[color:var(--ds-danger-text)] tw:hover:bg-[var(--ds-danger-wash)] tw:hover:text-[color:var(--ds-danger-text)]';
const pillBase = 'tw:border-transparent tw:px-3 tw:py-1 tw:text-[length:var(--tpl-text-caption)] tw:font-bold';
const locked = (s) => !s.campus_id && !isSuperAdmin.value;
const typeLabel = (s) => (s.campus_id ? '分校專屬' : '共用');
const typeTone = (s) => (s.campus_id ? 'tw:bg-[var(--ds-warning-wash)] tw:text-[color:var(--ds-warning-text)]' : 'tw:bg-secondary tw:text-tpl-secondary');

async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    subjects.value = await fetchSubjectOptions({ branchId: props.branchId });
  } catch (e) {
    subjects.value = [];
    loadError.value = e?.message || '科目列表暫時無法載入，請稍後再試。';
  } finally {
    loading.value = false;
  }
}

function openAdd() {
  editingId.value = null;
  formName.value = '';
  formError.value = '';
  showModal.value = true;
}

function openEdit(s) {
  editingId.value = s.id;
  formName.value = s.label;
  formError.value = '';
  showModal.value = true;
}

async function submit() {
  const name = formName.value.trim();
  if (!name) return false;
  formError.value = '';
  try {
    if (editingId.value) {
      await updateSubject(editingId.value, name);
    } else {
      await createSubject(name, props.branchId);
    }
    showModal.value = false;
    await load();
    return true;
  } catch (e) {
    formError.value = e.message || '操作失敗';
    return false;
  }
}

// One request at a time: the dialog's buttons are disabled while `saving`, and Enter is ignored too.
async function onSubmit() {
  if (saving.value) return;
  saving.value = true;
  try { await submit(); } finally { saving.value = false; }
}

function confirmDelete(s) {
  if (!s.campus_id && !isSuperAdmin.value) return;
  deletingSubject.value = s;
  deleteOpen.value = true;
}

// The 刪除 button emits both a close request and `confirm` in the same click (order is the library's),
// so the close is decided one tick later: while a delete is running the dialog stays open.
async function onDeleteOpenChange(open) {
  await nextTick();
  if (deleting.value) return;
  deleteOpen.value = open;
  if (!open) deletingSubject.value = null;
}

// A failed delete keeps the dialog open (as before) so the director can retry or cancel.
async function onDeleteConfirm() {
  if (deleting.value || !deletingSubject.value) return;
  deleting.value = true;
  try { await doDelete(); } finally { deleting.value = false; }
}

async function doDelete() {
  if (!deletingSubject.value) return false;
  try {
    await deleteSubject(deletingSubject.value.id);
    deleteOpen.value = false;
    deletingSubject.value = null;
    await load();
    return true;
  } catch (e) {
    alert(e.message || '刪除失敗');
    return false;
  }
}

watch(() => props.branchId, () => { if (props.branchId) load(); });
onMounted(() => { if (props.branchId) load(); });
</script>
