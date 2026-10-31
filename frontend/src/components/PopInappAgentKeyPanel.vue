<template>
  <div class="pop-key-panel" data-testid="pop-key-panel">
    <div class="pop-key-header">
      <h4>AI 回報處理申請金鑰</h4>
      <AtButton shape="rect" size="sm" variant="ghost" :disabled="loading" @click="refresh">重新整理</AtButton>
    </div>
    <p class="muted">
      這把金鑰讓雲端的 AI 助理替「問題回報」送出處理申請（分類並回覆、修好上線後回覆、逾期結案），並先試跑檢查。
      它不能自己核准或執行：只有完全符合預先核准規則的申請會由系統執行，其他申請仍要超級管理員核准。
      只能處理你勾選的分校，建立後 {{ ttlDays }} 天自動失效；不用時請馬上撤銷。
    </p>

    <AtInlineAlert v-if="errorMsg" tone="danger">{{ errorMsg }}</AtInlineAlert>
    <AtInlineAlert v-if="noticeMsg" tone="success"><span data-testid="pop-key-notice">{{ noticeMsg }}</span></AtInlineAlert>

    <form class="issue-form" @submit.prevent="issue">
      <label for="pop-key-name">金鑰名稱</label>
      <input id="pop-key-name" v-model="name" type="text" maxlength="64" placeholder="例：Claude 雲端回報處理" data-testid="pop-key-name" />
      <fieldset class="campus-fieldset" :disabled="campuses.length === 0">
        <legend>可處理的分校</legend>
        <AtButton v-if="campuses.length > 0" shape="rect" size="sm" variant="ghost" data-testid="pop-key-select-all" @click="toggleAll">{{ allSelected ? '取消全選' : '全選' }}</AtButton>
        <div class="campus-grid">
          <label v-for="c in campuses" :key="c.id" class="campus-option">
            <input v-model="selectedIds" type="checkbox" :value="c.id" data-testid="pop-key-campus" />
            <span>{{ c.name }}</span>
          </label>
        </div>
        <p v-if="campusError" class="field-error" data-testid="pop-key-campus-error">{{ campusError }}</p>
        <p v-else-if="campuses.length > 0 && selectedIds.length === 0" class="muted" data-testid="pop-key-campus-required">至少勾選一間分校才能建立。</p>
      </fieldset>
      <AtButton shape="rect" variant="primary" type="submit" :disabled="!canIssue" :loading="issuing" data-testid="pop-key-issue">建立金鑰</AtButton>
    </form>

    <div v-if="loading && !listLoaded" class="muted" role="status" aria-live="polite">載入中...</div>
    <ul v-else-if="keys.length > 0" class="key-list" data-testid="pop-key-list">
      <li v-for="item in keys" :key="item.id" class="key-item" data-testid="pop-key-row">
        <div class="key-item-head">
          <strong>{{ item.name }}</strong>
          <AtBadge :tone="statusOf(item).tone" :label="statusOf(item).label" />
        </div>
        <dl class="key-meta">
          <div><dt>可處理分校</dt><dd>{{ campusNames(item.campus_ids) }}</dd></div>
          <div><dt>建立時間</dt><dd>{{ formatDateTime(item.created_at) }}</dd></div>
          <div><dt>到期日</dt><dd>{{ formatDateTime(item.expires_at) }}</dd></div>
        </dl>
        <AtButton v-if="item.active" class="key-revoke" shape="rect" size="sm" variant="ghost" data-testid="pop-key-revoke" @click="askRevoke(item)">撤銷</AtButton>
      </li>
    </ul>
    <AtEmpty v-else-if="listLoaded && !loading" icon="key" title="目前沒有任何金鑰" description="需要讓 AI 送出回報處理申請時，在上面填名稱、勾選分校後建立。" data-testid="pop-key-empty" />

    <AtDialog :open="!!issued" title="金鑰只會顯示這一次" title-id="pop-key-issued-title" :close-on-backdrop="false" @close="closeIssued">
      <template v-if="issued">
        <AtInlineAlert tone="warning">
          <p>只會顯示一次，請立即存到雲端環境的 secret。關掉這個視窗後就再也看不到了。</p>
          <p>不要貼到 GitHub、LINE 群組或任何公開的地方：拿到金鑰的人可以替下列分校送出回報處理申請。</p>
        </AtInlineAlert>
        <div class="secret-row">
          <input class="secret-input" :value="issued.key" readonly aria-label="新的金鑰" data-testid="pop-key-secret" @focus="$event.target.select()" />
          <AtCopyButton :text="issued?.key || ''" data-testid="pop-key-copy" @error="errorMsg = '瀏覽器不允許自動複製，請手動選取金鑰後複製。'" />
        </div>
        <dl class="key-meta">
          <div><dt>可處理分校</dt><dd>{{ campusNames(issued.record.campus_ids) }}</dd></div>
          <div><dt>到期日</dt><dd>{{ formatDateTime(issued.record.expires_at) }}</dd></div>
        </dl>
      </template>
      <template #actions>
        <AtButton shape="rect" variant="primary" data-testid="pop-key-dismiss" @click="closeIssued">我存好了，關閉</AtButton>
      </template>
    </AtDialog>

    <AtDialog :open="!!revokeTarget" title="撤銷這把金鑰？" title-id="pop-key-revoke-title" size="sm" initial-focus="[data-initial-focus]" :close-on-backdrop="!revoking" @close="cancelRevoke">
      <p v-if="revokeTarget" data-testid="pop-key-revoke-text">
        撤銷「{{ revokeTarget.name }}」後，AI 立刻不能再用它送出申請。它送出、已經由預先核准規則通過但還沒執行的申請會一起取消；超級管理員親自核准的申請不受影響。
      </p>
      <template #actions>
        <AtButton shape="rect" size="sm" variant="secondary" data-initial-focus :disabled="revoking" @click="cancelRevoke">取消</AtButton>
        <AtButton shape="rect" size="sm" variant="danger" :loading="revoking" data-testid="pop-key-confirm" @click="confirmRevoke">確定撤銷</AtButton>
      </template>
    </AtDialog>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AtBadge from './design-system/AtBadge.vue';
import AtButton from './design-system/AtButton.vue';
import AtCopyButton from './design-system/AtCopyButton.vue';
import AtDialog from './design-system/AtDialog.vue';
import AtEmpty from './design-system/AtEmpty.vue';
import AtInlineAlert from './design-system/AtInlineAlert.vue';
import { loadBranchesForDirector } from '../lib/useBranches';
import { issuePopInappAgentKey, listPopInappAgentKeys, revokePopInappAgentKey } from '../lib/popInappAgentKeyService';

// Sibling of TriageTokenPanel for the ADR-POP-015 §3 key. The raw key lives only in `issued` while its modal
// dialog is open (the modal covers the profile tabs) and is dropped on close or unmount: never emitted to the
// parent, stored or logged. The parent blocks tab switches while `pending` so the POST response is not lost.
const props = defineProps({
  token: { type: String, default: '' },
});
const emit = defineEmits(['update:pending']);

const keys = ref([]);
const ttlDays = ref(90);
const campuses = ref([]);
const campusError = ref('');
const name = ref('');
const selectedIds = ref([]);
const issued = ref(null);
const listLoaded = ref(false);
const loading = ref(false);
const issuing = ref(false);
const revokeTarget = ref(null);
const revoking = ref(false);
const errorMsg = ref('');
const noticeMsg = ref('');
// Only the newest list request may write `keys` (a stale GET must not undo a post-mutation refresh).
let loadSeq = 0;

const allSelected = computed(() => campuses.value.length > 0 && selectedIds.value.length === campuses.value.length);
const canIssue = computed(() => !issuing.value && !issued.value && name.value.trim() !== '' && selectedIds.value.length > 0);
const campusNameById = computed(() => new Map(campuses.value.map((c) => [c.id, c.name])));

function formatDateTime(value) {
  if (!value) return '-';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? '-' : d.toLocaleString('zh-TW', { hour12: false });
}

function campusNames(ids) {
  // The backend lists an invalid or empty scope as [], and such a key is denied everything.
  if (!Array.isArray(ids) || ids.length === 0) return '沒有分校（無法使用）';
  return ids.map((id) => campusNameById.value.get(Number(id)) || `分校編號 ${id}`).join('、');
}

function statusOf(item) {
  if (!item.active) return { label: '已撤銷', tone: 'neutral' };
  if (item.expired) return { label: '已過期', tone: 'warning' };
  return { label: '有效', tone: 'success' };
}

async function load() {
  const seq = ++loadSeq;
  loading.value = true;
  errorMsg.value = '';
  try {
    const res = await listPopInappAgentKeys(props.token);
    if (seq !== loadSeq) return;
    keys.value = res.data;
    listLoaded.value = true;
    if (res.ttl_days != null) ttlDays.value = res.ttl_days;
  } catch (e) {
    if (seq !== loadSeq) return;
    listLoaded.value = false;
    keys.value = [];
    errorMsg.value = e.message || '讀取金鑰失敗';
  } finally {
    if (seq === loadSeq) loading.value = false;
  }
}

async function loadCampuses() {
  campusError.value = '';
  // Authenticated list (super_admin: every active campus); returns [] on any failure.
  const list = await loadBranchesForDirector(props.token);
  const valid = list
    .map((c) => ({ id: Number(c?.id), name: String(c?.name || '').trim() }))
    .filter((c) => Number.isInteger(c.id) && c.id > 0 && c.name);
  campuses.value = valid;
  selectedIds.value = selectedIds.value.filter((id) => valid.some((c) => c.id === id));
  if (valid.length === 0) campusError.value = '讀不到分校清單，暫時無法建立金鑰。請按「重新整理」再試。';
}

function refresh() {
  noticeMsg.value = '';
  return Promise.all([load(), loadCampuses()]);
}

function toggleAll() {
  selectedIds.value = allSelected.value ? [] : campuses.value.map((c) => c.id);
}

async function issue() {
  if (!canIssue.value) return;
  issuing.value = true;
  emit('update:pending', true);
  errorMsg.value = '';
  noticeMsg.value = '';
  try {
    const ids = [...selectedIds.value].sort((a, b) => a - b);
    const res = await issuePopInappAgentKey(props.token, name.value.trim(), ids);
    issued.value = { key: res.key, record: res.record };
    name.value = '';
    selectedIds.value = [];
    await load();
  } catch (e) {
    errorMsg.value = e.message || '建立金鑰失敗';
  } finally {
    issuing.value = false;
    emit('update:pending', false);
  }
}

function closeIssued() {
  issued.value = null;
}

function askRevoke(item) {
  errorMsg.value = '';
  noticeMsg.value = '';
  revokeTarget.value = item;
}

function cancelRevoke() {
  if (!revoking.value) revokeTarget.value = null;
}

async function confirmRevoke() {
  const target = revokeTarget.value;
  if (!target || revoking.value) return;
  revoking.value = true;
  errorMsg.value = '';
  try {
    const res = await revokePopInappAgentKey(props.token, target.id);
    const cancelled = Array.isArray(res.cancelled_request_ids) ? res.cancelled_request_ids.length : 0;
    noticeMsg.value = cancelled > 0
      ? `已撤銷「${target.name}」，並取消 ${cancelled} 筆還沒執行的申請。`
      : `已撤銷「${target.name}」，沒有需要取消的申請。`;
    await load();
  } catch (e) {
    errorMsg.value = e.message || '撤銷失敗';
  } finally {
    revoking.value = false;
    revokeTarget.value = null;
  }
}

onMounted(refresh);
onBeforeUnmount(closeIssued);
</script>

<style scoped>
.pop-key-panel { margin-top: var(--ds-space-5); padding-top: var(--ds-space-4); border-top: 1px solid var(--border); }
.pop-key-header,
.key-item-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: var(--ds-space-2); }
.pop-key-header h4 { margin: 0; }
.issue-form { display: grid; gap: var(--ds-space-2); margin: var(--ds-space-3) 0; }
.issue-form > input,
.issue-form > .at-btn { width: 100%; }
.campus-fieldset { min-width: 0; margin: 0; padding: var(--ds-space-3); border: 1px solid var(--border); border-radius: var(--ds-radius-md); }
.campus-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(128px, 1fr)); gap: var(--ds-space-1); margin-top: var(--ds-space-2); }
.campus-option { display: flex; align-items: center; gap: var(--ds-space-2); min-height: 44px; }
.field-error { margin: var(--ds-space-2) 0 0; color: var(--ds-danger); }
.secret-row { display: flex; flex-wrap: wrap; gap: var(--ds-space-2); align-items: center; margin: var(--ds-space-3) 0; }
.secret-input { flex: 1 1 240px; min-width: 0; font-family: ui-monospace, monospace; }
.key-list { display: grid; gap: var(--ds-space-2); margin: var(--ds-space-3) 0 0; padding: 0; list-style: none; }
.key-item { display: grid; gap: var(--ds-space-2); padding: var(--ds-space-3); border: 1px solid var(--border); border-radius: var(--ds-radius-md); }
.key-meta { display: grid; gap: var(--ds-space-1); margin: 0; }
.key-meta div { display: grid; grid-template-columns: 6em minmax(0, 1fr); gap: var(--ds-space-2); }
.key-meta dt { color: var(--ds-text-tertiary); }
.key-meta dd { margin: 0; overflow-wrap: anywhere; }
.key-revoke { justify-self: start; }
@media (min-width: 768px) {
  .issue-form > input { width: min(100%, 360px); }
  .issue-form > .at-btn { width: auto; justify-self: start; }
}
</style>
