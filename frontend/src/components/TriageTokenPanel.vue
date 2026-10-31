<template>
  <div class="triage-panel" data-testid="triage-token-panel">
    <div class="triage-header">
      <h4>AI 分診唯讀金鑰</h4>
      <AtButton shape="rect" size="sm" variant="ghost" :disabled="loading" @click="load">重新整理</AtButton>
    </div>
    <p class="muted">
      這把金鑰讓雲端的 AI 助理讀取系統裡的「問題回報」（列表、內容、留言和截圖），用來整理和分類 bug。
      它只能看，不能改任何資料，也打不開其他頁面。建立後 {{ ttlDays }} 天自動失效；不用時請馬上撤銷。
    </p>

    <AtInlineAlert v-if="errorMsg" tone="danger">{{ errorMsg }}</AtInlineAlert>

    <AtInlineAlert v-if="plaintext" tone="warning" title="金鑰只會顯示這一次">
      <p>請現在複製，存到雲端工作階段的 <code>ALLTRUE_TRIAGE_TOKEN</code> 設定。關掉這個提示後就再也看不到了。</p>
      <p>不要貼到 GitHub、LINE 群組或任何公開的地方：拿到金鑰的人可以看到回報內容，包含老師姓名和截圖。</p>
      <div class="secret-row">
        <input class="secret-input" :value="plaintext" readonly aria-label="新的金鑰" data-testid="triage-token-secret" @focus="$event.target.select()" />
        <AtCopyButton :text="plaintext" data-testid="triage-token-copy" @error="errorMsg = '瀏覽器不允許自動複製，請手動選取金鑰後複製。'" />
      </div>
      <template #action>
        <AtButton shape="rect" size="sm" variant="ghost" data-testid="triage-token-dismiss" @click="dismiss">我存好了，關閉</AtButton>
      </template>
    </AtInlineAlert>

    <form class="create-row" @submit.prevent="create">
      <label for="triage-token-name">金鑰名稱</label>
      <input id="triage-token-name" v-model="name" type="text" maxlength="100" placeholder="例：Claude 雲端分診" :disabled="!!plaintext" data-testid="triage-token-name" />
      <AtButton shape="rect" variant="primary" type="submit" :disabled="creating || !!plaintext || !name.trim()" :loading="creating" data-testid="triage-token-create">建立金鑰</AtButton>
    </form>
    <p v-if="plaintext" class="muted" data-testid="triage-token-create-blocked">請先存好並關閉上面的金鑰，才能再建立新的。</p>

    <div v-if="tokens.length > 0" class="token-table-wrap" data-testid="triage-token-table-wrap">
      <table class="token-table">
        <thead>
          <tr><th>名稱</th><th>建立時間</th><th>最後使用</th><th>到期日</th><th>狀態</th><th><span class="sr-only">操作</span></th></tr>
        </thead>
        <tbody>
          <tr v-for="item in tokens" :key="item.id" data-testid="triage-token-row">
            <td>{{ item.name }}</td>
            <td>{{ formatDateTime(item.created_at) }}</td>
            <td>{{ item.last_used_at ? formatDateTime(item.last_used_at) : '尚未使用' }}</td>
            <td>{{ formatDateTime(item.expires_at) }}</td>
            <td>{{ item.expired ? '已過期' : '有效' }}</td>
            <td class="actions-cell">
              <template v-if="confirmingId === item.id">
                <span class="confirm-text">撤銷後 AI 立刻無法讀取，確定？</span>
                <AtButton shape="rect" size="sm" variant="danger" :disabled="revokingId === item.id" data-testid="triage-token-confirm" @click="revoke(item.id)">確定撤銷</AtButton>
                <AtButton shape="rect" size="sm" variant="ghost" @click="confirmingId = null">取消</AtButton>
              </template>
              <AtButton v-else shape="rect" size="sm" variant="ghost" data-testid="triage-token-revoke" @click="confirmingId = item.id">撤銷</AtButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-else-if="listLoaded && !loading" class="empty-text" data-testid="triage-token-empty">目前沒有任何金鑰。</div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import AtButton from './design-system/AtButton.vue';
import AtCopyButton from './design-system/AtCopyButton.vue';
import AtInlineAlert from './design-system/AtInlineAlert.vue';
import { createTriageToken, listTriageTokens, revokeTriageToken } from '../lib/triageTokenService';

// The one-time secret and the pending flag live in the parent (v-model:secret / v-model:pending)
// so a tab switch cannot drop the secret; the parent also blocks tab switches while pending.
const props = defineProps({
  token: { type: String, default: '' },
  secret: { type: String, default: '' },
});
const emit = defineEmits(['update:secret', 'update:pending']);

const tokens = ref([]);
const ttlDays = ref(90);
const name = ref('');
const plaintext = computed(() => props.secret);
const listLoaded = ref(false);
const loading = ref(false);
const creating = ref(false);
const confirmingId = ref(null);
const revokingId = ref(null);
const errorMsg = ref('');
// Id of the token whose plaintext is on screen, so revoking it also clears the secret. Derived from the
// parent-owned secret ("<id>|<secret>", TriageTokenService::create) so it survives a tab-switch remount.
const createdId = computed(() => {
  const m = /^(\d+)\|/.exec(props.secret || '');
  return m ? Number(m[1]) : null;
});
// Only the newest list request may write `tokens` (a stale GET must not undo a post-mutation refresh).
let loadSeq = 0;

function formatDateTime(value) {
  if (!value) return '-';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? '-' : d.toLocaleString('zh-TW', { hour12: false });
}

async function load() {
  const seq = ++loadSeq;
  loading.value = true;
  errorMsg.value = '';
  try {
    const res = await listTriageTokens(props.token);
    if (seq !== loadSeq) return;
    tokens.value = res.data;
    listLoaded.value = true;
    if (res.ttl_days != null) ttlDays.value = res.ttl_days;
  } catch (e) {
    if (seq !== loadSeq) return;
    listLoaded.value = false;
    tokens.value = [];
    errorMsg.value = e.message || '讀取金鑰失敗';
  } finally {
    if (seq === loadSeq) loading.value = false;
  }
}

async function create() {
  if (!name.value.trim() || plaintext.value) return;
  creating.value = true;
  emit('update:pending', true);
  errorMsg.value = '';
  try {
    const res = await createTriageToken(props.token, name.value.trim());
    emit('update:secret', res.token);
    name.value = '';
    await load();
  } catch (e) {
    errorMsg.value = e.message || '建立金鑰失敗';
  } finally {
    creating.value = false;
    emit('update:pending', false);
  }
}

function dismiss() {
  emit('update:secret', '');
}

async function revoke(id) {
  revokingId.value = id;
  errorMsg.value = '';
  try {
    await revokeTriageToken(props.token, id);
    confirmingId.value = null;
    if (createdId.value != null && createdId.value === Number(id)) dismiss();
    await load();
  } catch (e) {
    errorMsg.value = e.message || '撤銷失敗';
  } finally {
    revokingId.value = null;
  }
}

onMounted(load);
</script>

<style scoped>
.triage-panel {
  margin-top: var(--ds-space-5);
  padding-top: var(--ds-space-4);
  border-top: 1px solid var(--border);
}
.triage-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.triage-header h4 {
  margin: 0;
}
.secret-row,
.create-row {
  display: flex;
  flex-wrap: wrap;
  gap: var(--ds-space-2);
  align-items: center;
  margin: var(--ds-space-3) 0;
}
.secret-input {
  flex: 1 1 280px;
  font-family: ui-monospace, monospace;
}
.create-row input {
  flex: 1 1 200px;
}
.token-table-wrap {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.token-table {
  width: 100%;
  min-width: 640px;
  border-collapse: collapse;
}
.token-table th,
.token-table td {
  text-align: left;
  padding: var(--ds-space-2);
  border-bottom: 1px solid var(--border);
}
.actions-cell {
  display: flex;
  flex-wrap: wrap;
  gap: var(--ds-space-2);
  align-items: center;
}
.confirm-text {
  color: var(--ds-danger);
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}
</style>
