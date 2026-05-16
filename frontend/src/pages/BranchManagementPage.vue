<template>
  <div class="branch-mgmt">
    <div class="branch-mgmt__header">
      <div>
        <h1 class="branch-mgmt__title">分校管理</h1>
        <p class="branch-mgmt__sub">管理所有分校設定，僅超級管理員可操作</p>
      </div>
      <button class="btn-primary" @click="openCreate">＋ 新增分校</button>
    </div>

    <div v-if="loading" class="branch-mgmt__loading">載入中…</div>
    <div v-else-if="error" class="branch-mgmt__error">{{ error }}</div>

    <div v-else class="branch-table-wrap">
      <table class="branch-table">
        <thead>
          <tr>
            <th>分校名稱</th>
            <th>代碼</th>
            <th>刷卡窗口</th>
            <th>狀態</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="campuses.length === 0">
            <td colspan="5" class="branch-table__empty">尚無分校資料</td>
          </tr>
          <tr v-for="c in campuses" :key="c.id" :class="{ 'branch-table__row--inactive': !c.active }">
            <td class="branch-table__name">{{ c.name }}</td>
            <td><code class="branch-code">{{ c.code }}</code></td>
            <td>{{ c.SwipeWindowMinutes }} 分鐘</td>
            <td>
              <span :class="['branch-status', c.active ? 'branch-status--active' : 'branch-status--inactive']">
                {{ c.active ? '啟用' : '停用' }}
              </span>
            </td>
            <td class="branch-table__actions">
              <button class="btn-sm btn-edit" @click="openEdit(c)">編輯</button>
              <button class="btn-sm btn-danger" @click="confirmDelete(c)">刪除</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Add / Edit Modal -->
    <div v-if="showModal" class="modal-backdrop" @click.self="closeModal">
      <div class="modal">
        <div class="modal__header">
          <h2 class="modal__title">{{ editTarget ? '編輯分校' : '新增分校' }}</h2>
          <button class="modal__close" @click="closeModal">✕</button>
        </div>
        <div class="modal__body">
          <form @submit.prevent="submitForm" novalidate>
            <div class="form-section">
              <div class="form-section__title">基本設定</div>
              <label class="form-field">
                <span class="form-label">分校名稱 <span class="required">*</span></span>
                <input v-model="form.name" type="text" class="form-input" placeholder="例：板橋分校" required />
              </label>
              <label class="form-field">
                <span class="form-label">代碼 <span class="required">*</span></span>
                <input v-model="form.code" type="text" class="form-input" placeholder="例：banqiao（英文小寫）" required />
                <span class="form-hint">用於 URL 和刷卡機識別，建立後避免更改</span>
              </label>
              <label class="form-field">
                <span class="form-label">啟用狀態</span>
                <div class="toggle-wrap">
                  <input type="checkbox" v-model="form.active" id="active-toggle" class="toggle-input" />
                  <label for="active-toggle" class="toggle-label">
                    <span>{{ form.active ? '啟用（主任申請與分校選單可見）' : '停用（不顯示於選單）' }}</span>
                  </label>
                </div>
              </label>
              <label class="form-field">
                <span class="form-label">刷卡窗口（分鐘）</span>
                <input v-model.number="form.SwipeWindowMinutes" type="number" min="1" max="120" class="form-input form-input--sm" />
              </label>
            </div>

            <div class="form-section">
              <div class="form-section__title">刷卡機授權</div>
              <label class="form-field">
                <span class="form-label">Token</span>
                <div class="token-field">
                  <input
                    v-model="form.Token"
                    :type="showToken ? 'text' : 'password'"
                    class="form-input"
                    placeholder="留空則自動產生"
                    autocomplete="off"
                  />
                  <button type="button" class="btn-token-toggle" @click="showToken = !showToken">
                    {{ showToken ? '隱藏' : '顯示' }}
                  </button>
                </div>
                <span class="form-hint">刷卡機連線時使用，請妥善保管</span>
              </label>
            </div>

            <details class="form-advanced">
              <summary class="form-advanced__toggle">▸ 通知設定（選填）</summary>
              <div class="form-advanced__body">
                <label class="form-field">
                  <span class="form-label">LINE Notify ID</span>
                  <input v-model="form.LineNotifyID" type="text" class="form-input" placeholder="LINE Notify Token" />
                </label>
                <label class="form-field">
                  <span class="form-label">Telegram Token</span>
                  <input v-model="form.TelegramToken" type="text" class="form-input" />
                </label>
                <label class="form-field">
                  <span class="form-label">Telegram Chat ID</span>
                  <input v-model="form.TelegramChatID" type="text" class="form-input" />
                </label>
              </div>
            </details>

            <div v-if="formError" class="form-error">{{ formError }}</div>

            <div class="modal__footer">
              <button type="button" class="btn-ghost" @click="closeModal" :disabled="submitting">取消</button>
              <button type="submit" class="btn-primary" :disabled="submitting">
                {{ submitting ? '儲存中…' : (editTarget ? '儲存變更' : '建立分校') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Delete Confirm -->
    <div v-if="deleteTarget" class="modal-backdrop" @click.self="deleteTarget = null">
      <div class="modal modal--sm">
        <div class="modal__header">
          <h2 class="modal__title">確認刪除</h2>
        </div>
        <div class="modal__body">
          <p>確定要刪除「<strong>{{ deleteTarget.name }}</strong>」嗎？</p>
          <p class="modal__warning">此操作無法復原。若分校仍有使用者，系統將拒絕刪除。</p>
          <div v-if="deleteError" class="form-error">{{ deleteError }}</div>
        </div>
        <div class="modal__footer">
          <button class="btn-ghost" @click="deleteTarget = null" :disabled="deleting">取消</button>
          <button class="btn-danger" @click="executeDelete" :disabled="deleting">
            {{ deleting ? '刪除中…' : '確認刪除' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const props = defineProps({ token: String });

const campuses = ref([]);
const loading  = ref(false);
const error    = ref('');

const showModal  = ref(false);
const editTarget = ref(null);
const showToken  = ref(false);
const submitting = ref(false);
const formError  = ref('');

const deleteTarget = ref(null);
const deleting     = ref(false);
const deleteError  = ref('');

const emptyForm = () => ({
  name: '', code: '', active: true,
  SwipeWindowMinutes: 30, Token: '',
  LineNotifyID: '', TelegramToken: '', TelegramChatID: '',
});
const form = ref(emptyForm());

const api = (path, opts = {}) => fetch(`/api/v1${path}`, {
  ...opts,
  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${props.token}`, ...(opts.headers || {}) },
  body: opts.body ? JSON.stringify(opts.body) : undefined,
});

const load = async () => {
  loading.value = true;
  error.value   = '';
  try {
    const res = await api('/admin/campuses');
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    campuses.value = await res.json();
  } catch (e) {
    error.value = '載入分校清單失敗，請重新整理頁面';
  } finally {
    loading.value = false;
  }
};

const openCreate = () => {
  editTarget.value = null;
  form.value       = emptyForm();
  showToken.value  = false;
  formError.value  = '';
  showModal.value  = true;
};

const openEdit = (c) => {
  editTarget.value = c;
  form.value = {
    name: c.name, code: c.code, active: c.active,
    SwipeWindowMinutes: c.SwipeWindowMinutes, Token: c.Token,
    LineNotifyID: c.LineNotifyID, TelegramToken: c.TelegramToken, TelegramChatID: c.TelegramChatID,
  };
  showToken.value = false;
  formError.value = '';
  showModal.value = true;
};

const closeModal = () => {
  if (submitting.value) return;
  showModal.value = false;
};

const submitForm = async () => {
  formError.value = '';
  if (!form.value.name.trim()) { formError.value = '請填寫分校名稱'; return; }
  if (!form.value.code.trim()) { formError.value = '請填寫分校代碼'; return; }
  submitting.value = true;
  try {
    const isEdit = !!editTarget.value;
    const res = await api(
      isEdit ? `/admin/campuses/${editTarget.value.id}` : '/admin/campuses',
      { method: isEdit ? 'PUT' : 'POST', body: form.value }
    );
    const json = await res.json();
    if (!res.ok) { formError.value = json.message || '操作失敗，請再試'; return; }
    showModal.value = false;
    await load();
  } catch {
    formError.value = '網路錯誤，請重試';
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = (c) => {
  deleteTarget.value = c;
  deleteError.value  = '';
};

const executeDelete = async () => {
  deleting.value = true;
  deleteError.value = '';
  try {
    const res = await api(`/admin/campuses/${deleteTarget.value.id}`, { method: 'DELETE', headers: {} });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) { deleteError.value = json.message || '刪除失敗'; return; }
    deleteTarget.value = null;
    await load();
  } catch {
    deleteError.value = '網路錯誤';
  } finally {
    deleting.value = false;
  }
};

onMounted(load);
</script>

<style scoped>
.branch-mgmt {
  max-width: 900px;
  margin: 0 auto;
  padding: 24px 16px;
}
.branch-mgmt__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 24px;
}
.branch-mgmt__title {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--text, #0f172a);
  margin: 0 0 4px;
}
.branch-mgmt__sub {
  font-size: 13px;
  color: #64748b;
  margin: 0;
}
.branch-mgmt__loading,
.branch-mgmt__error {
  text-align: center;
  padding: 40px;
  color: #64748b;
}
.branch-mgmt__error { color: #dc2626; }

/* Table */
.branch-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid #e2e8f0; }
.branch-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}
.branch-table thead th {
  background: #f8fafc;
  padding: 12px 16px;
  text-align: left;
  font-weight: 600;
  color: #64748b;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  border-bottom: 1px solid #e2e8f0;
}
.branch-table tbody tr {
  border-bottom: 1px solid #f1f5f9;
  transition: background 0.12s;
}
.branch-table tbody tr:hover { background: #f8fafc; }
.branch-table tbody tr:last-child { border-bottom: none; }
.branch-table__row--inactive { opacity: 0.55; }
.branch-table__empty {
  text-align: center;
  color: #94a3b8;
  padding: 32px;
}
.branch-table td { padding: 12px 16px; }
.branch-table__name { font-weight: 600; color: #0f172a; }
.branch-table__actions { display: flex; gap: 8px; }

.branch-code {
  background: #f1f5f9;
  padding: 2px 8px;
  border-radius: 4px;
  font-family: monospace;
  font-size: 12px;
  color: #475569;
}
.branch-status {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}
.branch-status--active { background: #dcfce7; color: #15803d; }
.branch-status--inactive { background: #f1f5f9; color: #64748b; }

/* Buttons */
.btn-primary {
  background: #2563eb;
  color: #fff;
  border: none;
  padding: 9px 18px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  font-size: 14px;
}
.btn-primary:hover:not(:disabled) { background: #1d4ed8; }
.btn-primary:disabled { opacity: 0.55; cursor: default; }
.btn-ghost {
  background: transparent;
  border: 1px solid #e2e8f0;
  color: #475569;
  padding: 8px 16px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 14px;
}
.btn-ghost:hover:not(:disabled) { background: #f8fafc; }
.btn-ghost:disabled { opacity: 0.55; cursor: default; }
.btn-danger {
  background: #dc2626;
  color: #fff;
  border: none;
  padding: 8px 18px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  font-size: 14px;
}
.btn-danger:hover:not(:disabled) { background: #b91c1c; }
.btn-danger:disabled { opacity: 0.55; cursor: default; }
.btn-sm { padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; }
.btn-edit { background: #eff6ff; color: #2563eb; }
.btn-edit:hover { background: #dbeafe; }

/* Modal */
.modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.45);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
  padding: 16px;
}
.modal {
  background: #fff;
  border-radius: 16px;
  width: 100%;
  max-width: 520px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0,0,0,.18);
}
.modal--sm { max-width: 380px; }
.modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px 0;
}
.modal__title { font-size: 1.1rem; font-weight: 700; margin: 0; }
.modal__close {
  background: none; border: none; font-size: 18px;
  cursor: pointer; color: #64748b; line-height: 1;
}
.modal__body { padding: 16px 24px; }
.modal__footer {
  display: flex; gap: 10px; justify-content: flex-end;
  padding: 16px 24px;
  border-top: 1px solid #f1f5f9;
}
.modal__warning { font-size: 13px; color: #b45309; margin-top: 6px; }

/* Form */
.form-section { margin-bottom: 20px; }
.form-section__title {
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .06em; color: #64748b; margin-bottom: 10px;
  padding-bottom: 6px; border-bottom: 1px solid #f1f5f9;
}
.form-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
.form-label { font-size: 13px; font-weight: 600; color: #374151; }
.required { color: #dc2626; }
.form-input {
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 14px;
  outline: none;
  transition: border-color 0.15s;
}
.form-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
.form-input--sm { max-width: 120px; }
.form-hint { font-size: 11px; color: #94a3b8; }
.form-error {
  background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;
  border-radius: 8px; padding: 8px 12px; font-size: 13px; margin-top: 8px;
}

.toggle-wrap { display: flex; align-items: center; gap: 10px; }
.toggle-input { width: 18px; height: 18px; accent-color: #2563eb; cursor: pointer; }
.toggle-label { font-size: 13px; color: #374151; cursor: pointer; }

.token-field { display: flex; gap: 8px; }
.token-field .form-input { flex: 1; }
.btn-token-toggle {
  background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px;
  padding: 0 12px; font-size: 12px; cursor: pointer; white-space: nowrap;
  color: #475569;
}
.btn-token-toggle:hover { background: #e2e8f0; }

.form-advanced {
  border: 1px solid #e2e8f0; border-radius: 10px;
  margin-bottom: 16px; overflow: hidden;
}
.form-advanced__toggle {
  padding: 10px 14px; font-size: 13px; font-weight: 600;
  color: #475569; cursor: pointer; list-style: none;
  background: #f8fafc;
}
.form-advanced__toggle:hover { background: #f1f5f9; }
.form-advanced__body { padding: 12px 14px; }
</style>
