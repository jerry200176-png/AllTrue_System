<template>
  <div class="tw:space-y-4">
    <p class="tw:text-sm tw:text-muted-foreground">下面是可能寫法不同的同一所學校。選一個要保留的名稱，確認人數後再合併；合併後 180 天內可復原。</p>
    <p v-if="error" role="alert" class="tw:text-sm tw:text-destructive">{{ error }}</p>
    <p v-if="notice" role="status" class="tw:text-sm">{{ notice }}</p>
    <p v-if="!loading && !groups.length" class="tw:text-sm tw:text-muted-foreground">目前沒有看起來重複的學校名稱。</p>

    <Card v-for="(g, i) in groups" :key="i" class="tw:gap-3 tw:py-4">
      <CardHeader class="tw:px-4">
        <CardTitle class="tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:text-base">
          共 {{ g.student_count }} 位學生
          <Badge v-if="g.reason === 'similar'" variant="outline">拼寫相近，請確認是同一所</Badge>
        </CardTitle>
      </CardHeader>
      <CardContent class="tw:space-y-2 tw:px-4">
        <label v-for="n in g.names" :key="n.name" class="tw:flex tw:items-center tw:gap-2 tw:text-sm">
          <input v-model="pick[i]" type="radio" :name="`canon-${i}`" :value="n.name" />
          <span>{{ n.name }}</span>
          <Badge variant="secondary">{{ n.count }} 人</Badge>
        </label>
        <div class="tw:flex tw:items-center tw:justify-between tw:gap-2 tw:pt-2">
          <span class="tw:text-sm tw:text-muted-foreground">保留「{{ chosen(i) }}」，會改 {{ affected(g, i) }} 位學生</span>
          <Button v-if="confirming !== i" size="sm" @click="confirming = i">合併</Button>
          <span v-else class="tw:flex tw:gap-2">
            <Button size="sm" :disabled="busy" @click="doMerge(g, i)">確定合併</Button>
            <Button size="sm" variant="ghost" @click="confirming = null">取消</Button>
          </span>
        </div>
      </CardContent>
    </Card>

    <div v-if="history.length" class="tw:space-y-2">
      <h3 class="tw:text-sm tw:font-medium">最近的合併（180 天內可復原）</h3>
      <div v-for="h in history" :key="h.request_id" class="tw:flex tw:items-center tw:justify-between tw:gap-2 tw:text-sm">
        <span>{{ h.occurred_at.slice(0, 10) }} 合併為「{{ h.canonical_name }}」，{{ h.student_count }} 位學生</span>
        <Button size="sm" variant="outline" :disabled="busy" @click="doUndo(h)">復原</Button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { Card, CardContent, CardHeader, CardTitle } from './ui/card';
import { supabase } from '../supabase';

const props = defineProps({ branchId: { type: [Number, String], required: true } });
const emit = defineEmits(['changed']);

const groups = ref([]);
const history = ref([]);
const pick = ref({});
const confirming = ref(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');

async function api(path, options = {}) {
  const { data: { session } } = await supabase.auth.getSession();
  const token = session?.access_token;
  const res = await fetch(`/api/v1${path}`, {
    ...options,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body.message || `HTTP ${res.status}`);
  return body;
}

const chosen = (i) => pick.value[i] || '';
const affected = (g, i) => g.names.filter((n) => n.name !== chosen(i)).reduce((s, n) => s + n.count, 0);

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const q = `campus_id=${encodeURIComponent(props.branchId)}`;
    const [s, h] = await Promise.all([api(`/school-merge/suggestions?${q}`), api(`/school-merge/history?${q}`)]);
    groups.value = s.data || [];
    history.value = h.data || [];
    pick.value = Object.fromEntries(groups.value.map((g, i) => [i, g.names[0].name]));
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function doMerge(g, i) {
  busy.value = true;
  error.value = '';
  try {
    const r = await api('/school-merge', {
      method: 'POST',
      body: JSON.stringify({ campus_id: Number(props.branchId), canonical: chosen(i), variants: g.names.map((n) => n.name).filter((n) => n !== chosen(i)) }),
    });
    notice.value = `已合併，改了 ${r.data.students_updated} 位學生。`;
    confirming.value = null;
    emit('changed');
    await load();
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

async function doUndo(h) {
  busy.value = true;
  error.value = '';
  try {
    const r = await api(`/school-merge/${h.request_id}/undo`, { method: 'POST', body: JSON.stringify({ campus_id: Number(props.branchId) }) });
    notice.value = `已復原 ${r.data.students_restored} 位學生。`;
    emit('changed');
    await load();
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

onMounted(load);
</script>
