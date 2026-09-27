<template>
  <div class="branch-mgmt">
    <AtPageHeader
      title="分校管理"
      description="管理所有分校設定，僅超級管理員可操作。"
      icon="store"
      data-guide="branch-management-header"
    >
      <template #actions>
        <AtButton shape="rect" variant="primary" icon="add" @click="openCreate($event)">新增分校</AtButton>
      </template>
    </AtPageHeader>

    <div v-if="loading" class="branch-mgmt__loading" role="status" aria-live="polite">載入中…</div>
    <div v-else-if="error" class="branch-mgmt__error" role="alert" aria-live="assertive">
      <span>{{ error }}</span>
      <AtButton shape="rect" variant="secondary" @click="load">重新載入</AtButton>
    </div>

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
            <td colspan="5" class="branch-table__empty" role="status">尚無分校資料</td>
          </tr>
          <tr v-for="c in campuses" :key="c.id" :class="{ 'branch-table__row--inactive': !c.active }">
            <td class="branch-table__name" data-label="分校名稱">{{ c.name }}</td>
            <td data-label="代碼"><code class="branch-code">{{ c.code }}</code></td>
            <td data-label="刷卡窗口">{{ c.SwipeWindowMinutes }} 分鐘</td>
            <td data-label="狀態">
              <span :class="['branch-status', c.active ? 'branch-status--active' : 'branch-status--inactive']">
                {{ c.active ? '啟用' : '停用' }}
              </span>
            </td>
            <td class="branch-table__actions" data-label="操作">
              <AtButton shape="rect" size="sm" variant="secondary" @click="openEdit(c, $event)">編輯</AtButton>
              <AtButton shape="rect" size="sm" variant="danger" @click="confirmDelete(c, $event)">刪除</AtButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Add / Edit Modal -->
    <div v-if="showModal" class="modal-backdrop" @click.self="closeModal" @keydown.esc="closeModal">
      <div ref="modalCard" class="modal" role="dialog" aria-modal="true" aria-labelledby="branch-editor-dialog-title" tabindex="-1">
        <div class="modal__header">
          <h2 id="branch-editor-dialog-title" class="modal__title">{{ editTarget ? '編輯分校' : '新增分校' }}</h2>
          <AtIconButton icon="close" label="關閉" @click="closeModal" />
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
                <span class="form-label">授權碼</span>
                <div class="token-field">
                  <input
                    v-model="form.Token"
                    :type="showToken ? 'text' : 'password'"
                    class="form-input"
                    placeholder="留空則自動產生"
                    autocomplete="off"
                  />
                  <AtButton type="button" shape="rect" size="sm" variant="ghost" class="btn-token-toggle" @click="showToken = !showToken">
                    {{ showToken ? '隱藏' : '顯示' }}
                  </AtButton>
                </div>
                <span class="form-hint">刷卡機連線用的授權碼，請妥善保管</span>
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
              <AtButton type="button" shape="rect" variant="ghost" @click="closeModal" :disabled="submitting">取消</AtButton>
              <AtButton type="submit" shape="rect" variant="primary" :loading="submitting">
                {{ submitting ? '儲存中…' : (editTarget ? '儲存變更' : '建立分校') }}
              </AtButton>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Delete Confirm -->
    <div v-if="deleteTarget" class="modal-backdrop" @click.self="closeDeleteModal" @keydown.esc="closeDeleteModal">
      <div ref="deleteModalCard" class="modal modal--sm" role="dialog" aria-modal="true" aria-labelledby="branch-delete-dialog-title" aria-describedby="branch-delete-dialog-warning" tabindex="-1">
        <div class="modal__header">
          <h2 id="branch-delete-dialog-title" class="modal__title">確認刪除</h2>
        </div>
        <div class="modal__body">
          <p>確定要刪除「<strong>{{ deleteTarget.name }}</strong>」嗎？</p>
          <p id="branch-delete-dialog-warning" class="modal__warning">此操作無法復原。若分校仍有使用者，系統將拒絕刪除。</p>
          <div v-if="deleteError" class="form-error">{{ deleteError }}</div>
        </div>
        <div class="modal__footer">
          <AtButton shape="rect" variant="ghost" @click="closeDeleteModal" :disabled="deleting">取消</AtButton>
          <AtButton shape="rect" variant="danger" :loading="deleting" @click="executeDelete">
            {{ deleting ? '刪除中…' : '確認刪除' }}
          </AtButton>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import AtButton from '../components/design-system/AtButton.vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtIconButton from '../components/design-system/AtIconButton.vue';
import { ref, nextTick, onMounted } from 'vue';

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
const modalCard = ref(null);
const modalTrigger = ref(null);
const deleteModalCard = ref(null);
const deleteModalTrigger = ref(null);

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
    const body = await res.json().catch(() => []);
    if (!res.ok) throw new Error(body?.message || '分校資料暫時無法載入');
    campuses.value = Array.isArray(body) ? body : [];
  } catch (e) {
    campuses.value = [];
    error.value = e?.message || '分校資料暫時無法載入';
  } finally {
    loading.value = false;
  }
};

const openCreate = (event) => {
  editTarget.value = null;
  form.value       = emptyForm();
  showToken.value  = false;
  formError.value  = '';
  modalTrigger.value = event?.currentTarget || null;
  showModal.value  = true;
  nextTick(() => modalCard.value?.focus());
};

const openEdit = (c, event) => {
  editTarget.value = c;
  form.value = {
    name: c.name, code: c.code, active: c.active,
    SwipeWindowMinutes: c.SwipeWindowMinutes, Token: c.Token,
    LineNotifyID: c.LineNotifyID, TelegramToken: c.TelegramToken, TelegramChatID: c.TelegramChatID,
  };
  showToken.value = false;
  formError.value = '';
  modalTrigger.value = event?.currentTarget || null;
  showModal.value = true;
  nextTick(() => modalCard.value?.focus());
};

const closeModal = () => {
  if (submitting.value) return;
  showModal.value = false;
  nextTick(() => modalTrigger.value?.focus());
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
    nextTick(() => modalTrigger.value?.focus());
    await load();
  } catch {
    formError.value = '網路錯誤，請重試';
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = (c, event) => {
  deleteTarget.value = c;
  deleteError.value  = '';
  deleteModalTrigger.value = event?.currentTarget || null;
  nextTick(() => deleteModalCard.value?.focus());
};

const closeDeleteModal = () => {
  if (deleting.value) return;
  deleteTarget.value = null;
  nextTick(() => deleteModalTrigger.value?.focus());
};

const executeDelete = async () => {
  deleting.value = true;
  deleteError.value = '';
  try {
    const res = await api(`/admin/campuses/${deleteTarget.value.id}`, { method: 'DELETE', headers: {} });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) { deleteError.value = json.message || '刪除失敗'; return; }
    deleteTarget.value = null;
    nextTick(() => deleteModalTrigger.value?.focus());
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
  color: var(--text, var(--ds-ink));
  margin: 0 0 4px;
}
.branch-mgmt__sub {
  font-size: 13px;
  color: var(--ds-ink-mute);
  margin: 0;
}
.branch-mgmt__loading,
.branch-mgmt__error {
  text-align: center;
  padding: 40px;
  color: var(--ds-ink-mute);
}
.branch-mgmt__error { color: var(--ds-danger); }

/* Table */
.branch-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--ds-canvas-soft); }
.branch-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}
.branch-table thead th {
  background: var(--ds-canvas-soft);
  padding: 12px 16px;
  text-align: left;
  font-weight: 600;
  color: var(--ds-ink-mute);
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  border-bottom: 1px solid var(--ds-canvas-soft);
}
.branch-table tbody tr {
  border-bottom: 1px solid var(--ds-canvas-soft);
  transition: background 0.12s;
}
.branch-table tbody tr:hover { background: var(--ds-canvas-soft); }
.branch-table tbody tr:last-child { border-bottom: none; }
.branch-table__row--inactive { opacity: 0.55; }
.branch-table__empty {
  text-align: center;
  color: var(--ds-ink-mute);
  padding: 32px;
}
.branch-table td { padding: 12px 16px; }
.branch-table__name { font-weight: 600; color: var(--ds-ink); }
.branch-table__actions { display: flex; gap: 8px; }

.branch-code {
  background: var(--ds-canvas-soft);
  padding: 2px 8px;
  border-radius: 4px;
  font-family: monospace;
  font-size: 12px;
  color: var(--ds-ink);
}
.branch-status {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}
.branch-status--active { background: var(--ds-success-wash); color: var(--ds-success); }
.branch-status--inactive { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }

/* Buttons */
.btn-primary {
  background: var(--ds-ink-mute);
  color: var(--ds-canvas);
  border: none;
  padding: 9px 18px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  font-size: 14px;
}
.btn-primary:hover:not(:disabled) { background: var(--ds-ink-mute); }
.btn-primary:disabled { opacity: 0.55; cursor: default; }
.btn-ghost {
  background: transparent;
  border: 1px solid var(--ds-canvas-soft);
  color: var(--ds-ink);
  padding: 8px 16px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 14px;
}
.btn-ghost:hover:not(:disabled) { background: var(--ds-canvas-soft); }
.btn-ghost:disabled { opacity: 0.55; cursor: default; }
.btn-danger {
  background: var(--ds-danger);
  color: var(--ds-canvas);
  border: none;
  padding: 8px 18px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  font-size: 14px;
}
.btn-danger:hover:not(:disabled) { background: var(--ds-danger); }
.btn-danger:disabled { opacity: 0.55; cursor: default; }
.btn-sm { padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; }
.btn-edit { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.btn-edit:hover { background: var(--ds-canvas-soft); }

/* Modal */
.modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.45);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
  padding: 16px;
}
.modal {
  background: var(--ds-canvas);
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
  cursor: pointer; color: var(--ds-ink-mute); line-height: 1;
}
.modal__body { padding: 16px 24px; }
.modal__footer {
  display: flex; gap: 10px; justify-content: flex-end;
  padding: 16px 24px;
  border-top: 1px solid var(--ds-canvas-soft);
}
.modal__warning { font-size: 13px; color: var(--ds-warning); margin-top: 6px; }

/* Form */
.form-section { margin-bottom: 20px; }
.form-section__title {
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .06em; color: var(--ds-ink-mute); margin-bottom: 10px;
  padding-bottom: 6px; border-bottom: 1px solid var(--ds-canvas-soft);
}
.form-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
.form-label { font-size: 13px; font-weight: 600; color: var(--ds-ink); }
.required { color: var(--ds-danger); }
.form-input {
  border: 1px solid var(--ds-canvas-soft);
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 14px;
  outline: none;
  transition: border-color 0.15s;
}
.form-input:focus { border-color: var(--ds-ink-mute); box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
.form-input--sm { max-width: 120px; }
.form-hint { font-size: 11px; color: var(--ds-ink-mute); }
.form-error {
  background: var(--ds-danger-wash); color: var(--ds-danger); border: 1px solid var(--ds-danger-wash);
  border-radius: 8px; padding: 8px 12px; font-size: 13px; margin-top: 8px;
}

.toggle-wrap { display: flex; align-items: center; gap: 10px; }
.toggle-input { width: 18px; height: 18px; accent-color: var(--ds-ink-mute); cursor: pointer; }
.toggle-label { font-size: 13px; color: var(--ds-ink); cursor: pointer; }

.token-field { display: flex; gap: 8px; }
.token-field .form-input { flex: 1; }
.btn-token-toggle {
  background: var(--ds-canvas-soft); border: 1px solid var(--ds-canvas-soft); border-radius: 8px;
  padding: 0 12px; font-size: 12px; cursor: pointer; white-space: nowrap;
  color: var(--ds-ink);
}
.btn-token-toggle:hover { background: var(--ds-canvas-soft); }

.form-advanced {
  border: 1px solid var(--ds-canvas-soft); border-radius: 10px;
  margin-bottom: 16px; overflow: hidden;
}
.form-advanced__toggle {
  padding: 10px 14px; font-size: 13px; font-weight: 600;
  color: var(--ds-ink); cursor: pointer; list-style: none;
  background: var(--ds-canvas-soft);
}
.form-advanced__toggle:hover { background: var(--ds-canvas-soft); }
.form-advanced__body { padding: 12px 14px; }
.branch-mgmt .at-btn,
.branch-mgmt .at-icon-btn {
  min-height: var(--ds-control-height-touch, 44px);
}
.branch-mgmt__loading,
.branch-mgmt__error {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  flex-wrap: wrap;
  min-height: 88px;
}
.branch-table td { overflow-wrap: anywhere; }
.branch-table__actions { min-width: 160px; }
.branch-table__actions .at-btn { flex: 0 0 auto; }
.branch-mgmt .form-input,
.branch-mgmt .btn-token-toggle {
  min-height: var(--ds-control-height-touch, 44px);
  box-sizing: border-box;
}
.branch-mgmt .toggle-wrap,
.branch-mgmt .toggle-label { min-height: var(--ds-control-height-touch, 44px); }
.branch-mgmt .btn-token-toggle {
  border-color: var(--ds-hairline);
  background: transparent;
  color: var(--ds-ink);
}
.branch-mgmt .modal {
  max-height: min(90vh, calc(100dvh - 32px));
}
.branch-mgmt .modal__footer .at-btn { min-width: 104px; }

@media (max-width: 720px) {
  .branch-mgmt { padding: 16px; }
  .branch-mgmt__error { align-items: stretch; flex-direction: column; }
  .branch-mgmt__error .at-btn { width: 100%; }
  .branch-table-wrap { overflow: visible; border: 0; }
  .branch-table,
  .branch-table tbody,
  .branch-table tr,
  .branch-table td { display: block; width: auto; }
  .branch-table { border: 0; }
  .branch-table thead {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
  }
  .branch-table tbody tr {
    margin-bottom: 12px;
    border: 1px solid var(--ds-hairline);
    border-radius: var(--ds-radius-lg, 12px);
    background: var(--ds-canvas);
    box-shadow: var(--ds-shadow-level-1, 0 2px 8px rgba(0,0,0,.06));
  }
  .branch-table td {
    display: grid;
    grid-template-columns: 5.25rem minmax(0, 1fr);
    gap: 12px;
    align-items: start;
    padding: 12px 14px;
    border-bottom: 1px solid var(--ds-hairline);
  }
  .branch-table td::before {
    content: attr(data-label);
    color: var(--ds-ink-mute);
    font-size: 12px;
    font-weight: 700;
  }
  .branch-table td:last-child { border-bottom: 0; }
  .branch-table__empty {
    display: block !important;
    border-bottom: 0 !important;
  }
  .branch-table__empty::before { display: none; }
  .branch-table__actions {
    display: grid;
    grid-template-columns: 5.25rem minmax(0, 1fr);
    min-width: 0;
    align-items: center;
  }
  .branch-table__actions .at-btn { grid-column: 2; width: 100%; }
  .branch-mgmt .modal__footer { flex-direction: column-reverse; }
  .branch-mgmt .modal__footer .at-btn { width: 100%; }
}
</style>
