<template>
  <div class="subject-settings-page">
    <AtPageHeader
      title="科目管理"
      description="管理目前分校可選用的科目；共用科目由系統預設。"
      icon="library_books"
      data-guide="subject-settings-header"
    >
      <template #actions>
        <AtButton shape="rect" variant="primary" icon="add" @click="openAdd">新增科目</AtButton>
      </template>
    </AtPageHeader>

    <div class="card">

      <div v-if="loading" class="hint" role="status" aria-live="polite">載入中...</div>
      <AtInlineAlert v-else-if="loadError" tone="danger" title="無法載入科目列表">
        <p>{{ loadError }}</p>
        <template #action>
          <AtButton shape="rect" size="sm" variant="ghost" @click="load">重試</AtButton>
        </template>
      </AtInlineAlert>
      <div v-else-if="subjects.length" class="subject-table-wrap">
        <table class="subject-table">
          <thead>
            <tr>
              <th>科目名稱</th>
              <th>類型</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in subjects" :key="s.id">
              <td data-label="科目名稱"><strong>{{ s.label }}</strong></td>
              <td data-label="類型">
                <span :class="['status-tag', s.campus_id ? 'custom' : 'shared']">
                  {{ s.campus_id ? '分校專屬' : '共用' }}
                </span>
              </td>
              <td data-label="操作" class="subject-row-actions">
                <AtButton shape="rect" size="sm" variant="secondary" @click="openEdit(s)">更名</AtButton>
                <AtButton
                  shape="rect"
                  size="sm"
                  variant="danger"
                  :disabled="!s.campus_id && !isSuperAdmin"
                  :title="!s.campus_id && !isSuperAdmin ? '共用科目僅超級管理員可刪除' : ''"
                  @click="confirmDelete(s)"
                >刪除</AtButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <AtEmpty
        v-else
        icon="menu_book"
        title="目前沒有科目資料"
        description="新增第一個分校科目後，課程建立時就能選用。"
      />
    </div>

    <!-- Add / Edit dialog -->
    <AtDialog
      :open="showModal"
      :title="editingId ? '科目更名' : '新增科目'"
      panel-class="subject-dialog"
      @close="showModal = false"
    >
        <div class="form-group">
          <label for="subject-name">科目名稱 <span class="required">*</span></label>
          <input
            id="subject-name"
            v-model="formName"
            placeholder="例如：程式設計"
            maxlength="16"
            aria-describedby="subject-name-hint"
            @keydown.enter="submit"
          />
          <span id="subject-name-hint" class="form-hint">最多 16 個字。</span>
        </div>
        <p v-if="formError" class="error-text">{{ formError }}</p>
        <template #actions>
          <AtButton shape="rect" variant="ghost" @click="showModal = false">取消</AtButton>
          <AtButton shape="rect" variant="primary" :loading="saving" :disabled="!formName.trim()" @click="submit">
            {{ saving ? '處理中...' : '儲存' }}
          </AtButton>
        </template>
    </AtDialog>

    <!-- Delete confirmation dialog -->
    <AtDialog
      :open="Boolean(deletingSubject)"
      title="確認刪除"
      size="sm"
      panel-class="subject-dialog"
      @close="deletingSubject = null"
    >
        <p>確定要刪除科目「{{ deletingSubject.label }}」嗎？若有課程使用此科目則無法刪除。</p>
        <template #actions>
          <AtButton shape="rect" variant="ghost" @click="deletingSubject = null">取消</AtButton>
          <AtButton shape="rect" variant="danger" :loading="saving" @click="doDelete">
            {{ saving ? '刪除中...' : '刪除' }}
          </AtButton>
        </template>
    </AtDialog>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtDialog from '../components/design-system/AtDialog.vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
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
const saving = ref(false);
const deletingSubject = ref(null);

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
  if (!name) return;
  saving.value = true;
  formError.value = '';
  try {
    if (editingId.value) {
      await updateSubject(editingId.value, name);
    } else {
      await createSubject(name, props.branchId);
    }
    showModal.value = false;
    await load();
  } catch (e) {
    formError.value = e.message || '操作失敗';
  } finally {
    saving.value = false;
  }
}

function confirmDelete(s) {
  if (!s.campus_id && !isSuperAdmin.value) return;
  deletingSubject.value = s;
}

async function doDelete() {
  if (!deletingSubject.value) return;
  saving.value = true;
  try {
    await deleteSubject(deletingSubject.value.id);
    deletingSubject.value = null;
    await load();
  } catch (e) {
    alert(e.message || '刪除失敗');
  } finally {
    saving.value = false;
  }
}

watch(() => props.branchId, () => { if (props.branchId) load(); });
onMounted(() => { if (props.branchId) load(); });
</script>

<style scoped>
.header-actions {
  display: flex; justify-content: space-between; align-items: flex-start;
  margin-bottom: 20px; gap: 16px; flex-wrap: wrap;
}
.ref-hint { color: var(--text-light); font-size: 13px; margin-top: 4px; }

.subject-table-wrap { min-width: 0; overflow-x: auto; }
.subject-table { width: 100%; border-collapse: collapse; }
.subject-table th,
.subject-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border-color, #eee); }
.subject-table th { font-size: 12px; text-transform: uppercase; color: var(--text-light); }
.subject-row-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.subject-settings-page :deep(.at-btn) { min-height: 44px; }
.subject-settings-page :deep(.at-dialog__close) { width: 44px; height: 44px; }

.status-tag {
  display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;
}
.status-tag.shared { background: #E3F2FD; color: #1565C0; }
.status-tag.custom { background: #FFF3E0; color: #E65100; }

.error-text { color: #c62828; font-size: 13px; margin-top: 4px; }
.empty-text { color: var(--text-light); text-align: center; padding: 40px 0; }
.hint { color: var(--text-light); text-align: center; padding: 20px 0; }
.danger-text { color: #c62828; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal { background: #fff; border-radius: 12px; padding: 24px; width: 90%; max-width: 420px; box-shadow: 0 8px 32px rgba(0,0,0,.15); }
.modal h3 { margin: 0 0 16px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.form-group input { width: 100%; min-height: 44px; padding: 8px 12px; border: 1px solid var(--ds-hairline-input, #ddd); border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.form-group input:focus-visible { outline: 3px solid var(--ds-focus-ring); outline-offset: 2px; }
.form-hint { display: block; margin-top: 4px; color: var(--ds-ink-mute); font-size: 12px; }
.subject-settings-page :deep(.at-inline-alert p) { margin: 0; }
.required { color: #c62828; }
.actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }

@media (max-width: 600px) {
  .subject-table-wrap { overflow-x: visible; }
  .subject-table,
  .subject-table tbody,
  .subject-table tr,
  .subject-table td { display: block; }
  .subject-table thead { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
  .subject-table tr { padding: 12px 0; border-bottom: 1px solid var(--border-color, #eee); }
  .subject-table td { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 0; }
  .subject-table td::before { content: attr(data-label); flex: 0 0 auto; color: var(--ds-ink-mute); font-size: 12px; }
  .subject-table td:first-child { padding-top: 0; }
  .subject-table td:last-child { justify-content: flex-end; padding-bottom: 0; }
  .subject-table td:last-child::before { margin-right: auto; }
}
</style>
