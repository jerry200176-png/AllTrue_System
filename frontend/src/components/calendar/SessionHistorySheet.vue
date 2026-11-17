<script setup>
// 競品 #4529 候選 C：日曆上單堂課的異動紀錄，只讀。重用 GET schedule-audit?session_id=（主任只看得到自己的分校）。
import { ref, watch } from 'vue';
import DetailSheet from '@/components/app-overlay/DetailSheet.vue';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { authedFetch } from '@/lib/authedFetch';
import { SESSION_STATUS_LABELS } from '@/lib/sessionStatus';

const props = defineProps({
  open: { type: Boolean, default: false },
  sessionId: { type: Number, default: null },
});
const emit = defineEmits(['update:open']);

const LIMIT = 50;
const ACTION = { create: '建立這堂課', update: '修改', delete: '刪除' };
const FIELDS = { SessionDate: '日期', StartTime: '開始時間', EndTime: '結束時間', Status: '狀態', Note: '備註' };
const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const error = ref('');
let seq = 0; // a slow reply for a session the sheet already left is dropped

const value = (key, v) => {
  if (v == null || v === '') return '—';
  if (key === 'Status') return SESSION_STATUS_LABELS[String(v).toLowerCase()] || String(v);
  if (key === 'SessionDate') return String(v).slice(0, 10);
  if (key.endsWith('Time')) return String(v).slice(0, 5);
  return String(v);
};
// What changed, in words. Fields outside FIELDS (price, contract flags, evaluation rows) read as one line.
function changes(r) {
  const before = r.old_data || {};
  const after = r.new_data || {};
  if (r.action_type === 'create') return [`${value('SessionDate', after.SessionDate)} ${value('StartTime', after.StartTime)}–${value('EndTime', after.EndTime)}`];
  const lines = Object.keys(FIELDS)
    .filter((k) => k in before || k in after)
    .filter((k) => value(k, before[k]) !== value(k, after[k]))
    .map((k) => `${FIELDS[k]}：${value(k, before[k])} → ${value(k, after[k])}`);
  return lines.length ? lines : [r.action_type === 'delete' ? '刪除相關紀錄' : '其他資料更新'];
}
const when = (iso) => new Date(iso).toLocaleString('zh-TW', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });

async function load() {
  const mine = ++seq;
  loading.value = true;
  error.value = '';
  try {
    const res = await authedFetch(`/api/v1/schedule-audit?session_id=${props.sessionId}&per_page=${LIMIT}`, { headers: { Accept: 'application/json' } });
    const json = await res.json().catch(() => ({}));
    if (mine !== seq) return;
    if (!res.ok) throw new Error(res.status === 403 ? '只有主任可以看異動紀錄。' : json?.message || `載入失敗（${res.status}）`);
    rows.value = json.data || [];
    total.value = json.meta?.total ?? rows.value.length;
  } catch (e) {
    if (mine === seq) { error.value = e.message || '載入失敗'; rows.value = []; }
  } finally {
    if (mine === seq) loading.value = false;
  }
}
watch(() => [props.open, props.sessionId], ([open, id]) => { if (open && id) load(); }, { immediate: true });
</script>

<template>
  <DetailSheet
    :open="open"
    title="這堂課的異動紀錄"
    description="這堂課被建立、修改或刪除的紀錄。只能看，不能改。"
    @update:open="emit('update:open', $event)"
  >
    <div v-if="loading" class="tw:flex tw:flex-col tw:gap-3"><Skeleton v-for="n in 3" :key="n" class="tw:h-16" /></div>
    <Alert v-else-if="error" tone="danger" class="tw:text-tpl-body">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-3">
        <span class="tw:min-w-0 tw:flex-1">{{ error }}</span>
        <Button variant="outline" class="tw:min-h-11" @click="load">再試一次</Button>
      </div>
    </Alert>
    <p v-else-if="!rows.length" class="tw:py-10 tw:text-center tw:text-tpl-body tw:text-tpl-secondary" role="status">這堂課沒有異動紀錄。</p>
    <template v-else>
      <ol class="tw:m-0 tw:flex tw:list-none tw:flex-col tw:gap-3 tw:p-0" data-testid="session-history">
        <li v-for="r in rows" :key="r.id" class="tw:rounded-lg tw:border tw:border-[var(--tpl-line)] tw:p-3">
          <div class="tw:flex tw:justify-between tw:gap-2 tw:text-tpl-caption tw:text-tpl-secondary tw:tabular-nums">
            <span>{{ when(r.created_at) }}</span>
            <span>{{ r.operator?.Name || '系統自動' }}</span>
          </div>
          <p class="tw:m-0 tw:mt-1 tw:text-tpl-body tw:font-semibold">{{ ACTION[r.action_type] || '異動' }}</p>
          <ul class="tw:m-0 tw:mt-1 tw:list-none tw:p-0 tw:text-tpl-body">
            <li v-for="(c, i) in changes(r)" :key="i">{{ c }}</li>
          </ul>
        </li>
      </ol>
      <p v-if="total > rows.length" class="tw:mt-3 tw:text-tpl-caption tw:text-tpl-secondary" role="status">只列出最近 {{ rows.length }} 筆（共 {{ total }} 筆）。</p>
    </template>
  </DetailSheet>
</template>
