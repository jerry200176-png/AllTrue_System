<template>
  <div class="tbr card" data-testid="tg-review">
    <h3>Telegram 綁定確認</h3>
    <p class="tbr-help">以前用姓名綁定的 Telegram，請確認是家長本人；不認得就解除。</p>
    <p v-if="error" class="tbr-error" role="alert">{{ error }}</p>
    <p v-else-if="loaded && !rows.length" class="tbr-help">目前沒有已綁定的 Telegram。</p>
    <table v-else-if="rows.length" class="tbr-table">
      <thead>
        <tr><th>學生</th><th>Telegram 名稱</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="r in rows" :key="`${r.student_id}-${r.slot}`">
          <td>{{ r.student_name }}</td>
          <td>{{ r.telegram_name || '無法取得' }}</td>
          <td><AtButton shape="rect" size="sm" variant="ghost" data-testid="tg-unbind" @click="pending = r">解除</AtButton></td>
        </tr>
      </tbody>
    </table>
    <AtDialog :open="!!pending" size="sm" title="解除 Telegram 綁定" initial-focus="[data-testid='tg-cancel']" @close="pending = null">
      <p v-if="pending">確定解除 {{ pending.student_name }} 的這個 Telegram？解除後該聊天室不再收到通知。</p>
      <template #actions>
        <AtButton shape="rect" variant="ghost" data-testid="tg-cancel" @click="pending = null">取消</AtButton>
        <AtButton shape="rect" variant="danger" data-testid="tg-confirm" @click="unbind">解除</AtButton>
      </template>
    </AtDialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import AtButton from './design-system/AtButton.vue';
import AtDialog from './design-system/AtDialog.vue';
import { supabase } from '../supabase';

const props = defineProps({ branchId: { type: Number, default: null } });
const data = ref([]);
const loaded = ref(false);
const error = ref('');
const pending = ref(null);

const rows = computed(() => data.value.flatMap((s) => s.bindings.map((b) => ({
  student_id: s.student_id, student_name: s.student_name, slot: b.slot, telegram_name: b.telegram_name,
}))));

async function call(path, opts = {}) {
  const { data: { session } } = await supabase.auth.getSession();
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
  if (session?.access_token) headers.Authorization = `Bearer ${session.access_token}`;
  return fetch(`/api/v1/telegram/bindings${path}`, { headers, credentials: 'include', ...opts });
}

async function load() {
  error.value = '';
  try {
    const res = await call(props.branchId ? `?branch_id=${props.branchId}` : '');
    if (!res.ok) throw new Error();
    data.value = (await res.json()).data;
    loaded.value = true;
  } catch (_) {
    error.value = '無法載入 Telegram 綁定，請稍後再試。';
  }
}

async function unbind() {
  const r = pending.value;
  pending.value = null;
  try {
    const body = { student_id: r.student_id, slot: r.slot };
    if (props.branchId) body.branch_id = props.branchId;
    const res = await call('/unbind', { method: 'POST', body: JSON.stringify(body) });
    if (!res.ok) throw new Error();
    await load();
  } catch (_) {
    error.value = '解除失敗，請稍後再試。';
  }
}

onMounted(load);
watch(() => props.branchId, load);
</script>

<style scoped>
.tbr-help { color: var(--ds-ink-mute); }
.tbr-error { color: var(--ds-danger-text); }
.tbr-table { width: 100%; border-collapse: collapse; }
.tbr-table th, .tbr-table td { text-align: left; padding: 8px; border-bottom: 1px solid var(--ds-hairline); }
</style>
