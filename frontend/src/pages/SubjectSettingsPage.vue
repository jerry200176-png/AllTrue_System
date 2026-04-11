<template>
  <div>
    <div class="card">
      <div class="header-actions">
        <div>
          <h2>科目管理</h2>
          <p class="ref-hint">管理此分校可選用的科目，共用科目由系統預設、各分校均可使用</p>
        </div>
        <button class="primary" @click="openAdd">+ 新增科目</button>
      </div>

      <div v-if="loading" class="hint">載入中...</div>
      <table v-else-if="subjects.length" class="subject-table">
        <thead>
          <tr>
            <th>科目名稱</th>
            <th>類型</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in subjects" :key="s.id">
            <td><strong>{{ s.label }}</strong></td>
            <td>
              <span :class="['status-tag', s.campus_id ? 'custom' : 'shared']">
                {{ s.campus_id ? '分校專屬' : '共用' }}
              </span>
            </td>
            <td>
              <button class="small" @click="openEdit(s)">更名</button>
              <button
                class="small ghost danger-text"
                :disabled="!s.campus_id && !isSuperAdmin"
                :title="!s.campus_id && !isSuperAdmin ? '共用科目僅超級管理員可刪除' : ''"
                @click="confirmDelete(s)"
              >刪除</button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-else class="empty-text">目前無科目資料</div>
    </div>

    <!-- Add / Edit modal -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal">
        <h3>{{ editingId ? '科目更名' : '新增科目' }}</h3>
        <div class="form-group">
          <label>科目名稱 <span class="required">*</span></label>
          <input
            v-model="formName"
            placeholder="例如：程式設計"
            maxlength="16"
            @keydown.enter="submit"
          />
        </div>
        <p v-if="formError" class="error-text">{{ formError }}</p>
        <div class="actions">
          <button class="ghost" @click="showModal = false">取消</button>
          <button class="primary" :disabled="saving || !formName.trim()" @click="submit">
            {{ saving ? '處理中...' : '儲存' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Delete confirm -->
    <div v-if="deletingSubject" class="modal-overlay" @click.self="deletingSubject = null">
      <div class="modal">
        <h3>確認刪除</h3>
        <p>確定要刪除科目「{{ deletingSubject.label }}」嗎？若有課程使用此科目則無法刪除。</p>
        <div class="actions">
          <button class="ghost" @click="deletingSubject = null">取消</button>
          <button class="primary" style="background:#c62828;" :disabled="saving" @click="doDelete">
            {{ saving ? '刪除中...' : '刪除' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import { fetchSubjectOptions, createSubject, updateSubject, deleteSubject } from '../lib/subjectsApi';

const props = defineProps({
  branchId: [String, Number],
  userRole: String,
});

const isSuperAdmin = ref(false);
watch(() => props.userRole, (r) => { isSuperAdmin.value = r === 'super_admin'; }, { immediate: true });

const subjects = ref([]);
const loading = ref(false);
const showModal = ref(false);
const editingId = ref(null);
const formName = ref('');
const formError = ref('');
const saving = ref(false);
const deletingSubject = ref(null);

async function load() {
  loading.value = true;
  try {
    subjects.value = await fetchSubjectOptions({ branchId: props.branchId });
  } catch {
    subjects.value = [];
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

.subject-table { width: 100%; border-collapse: collapse; }
.subject-table th,
.subject-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border-color, #eee); }
.subject-table th { font-size: 12px; text-transform: uppercase; color: var(--text-light); }

.status-tag {
  display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;
}
.status-tag.shared { background: #E3F2FD; color: #1565C0; }
.status-tag.custom { background: #FFF3E0; color: #E65100; }

.error-text { color: #c62828; font-size: 13px; margin-top: 4px; }
.empty-text { color: var(--text-light); text-align: center; padding: 40px 0; }
.hint { color: var(--text-light); text-align: center; padding: 20px 0; }
.danger-text { color: #c62828; }

button.small { font-size: 13px; padding: 4px 12px; border-radius: 6px; cursor: pointer; border: 1px solid #ddd; background: #fff; }
button.small:hover { background: #f5f5f5; }
button.small.ghost { background: transparent; border-color: transparent; }
button.small:disabled { opacity: 0.4; cursor: not-allowed; }
button.primary { background: var(--primary, #1976D2); color: #fff; border: none; padding: 8px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
button.primary:disabled { opacity: 0.5; cursor: not-allowed; }
button.ghost { background: transparent; border: 1px solid #ddd; padding: 8px 16px; border-radius: 8px; cursor: pointer; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal { background: #fff; border-radius: 12px; padding: 24px; width: 90%; max-width: 420px; box-shadow: 0 8px 32px rgba(0,0,0,.15); }
.modal h3 { margin: 0 0 16px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.required { color: #c62828; }
.actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
</style>
