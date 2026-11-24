<template>
  <section class="tw:mt-6 tw:rounded-2xl tw:border tw:border-border tw:bg-card tw:p-5" aria-labelledby="aia-title" data-testid="ai-activity">
    <div class="tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-3 tw:gap-y-1">
      <h2 id="aia-title" class="tw:m-0 tw:text-tpl-section tw:font-semibold">AI 做了什麼</h2>
      <p class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary">過去 {{ report?.days || 30 }} 天。只記讀了哪張表，不記內容；改資料一律要先試跑、再由你核准。</p>
    </div>
    <p v-if="loading" class="tw:mt-3 tw:mb-0 tw:text-tpl-body" role="status">載入中…</p>
    <p v-else-if="error" class="tw:mt-3 tw:mb-0 tw:text-tpl-body" role="alert" data-testid="aia-error">{{ error }}</p>
    <template v-else-if="report">
      <p class="tw:mt-3 tw:mb-0 tw:text-tpl-body" data-testid="aia-reads">共記錄 {{ report.reads.total }} 次讀取，其中 {{ report.reads.refused }} 次未成功。</p>
      <details v-if="failureReasons.length" class="tw:mt-2 tw:text-tpl-body">
        <summary class="tw:cursor-pointer">查看原因</summary>
        <ul class="tw:mt-2 tw:mb-0 tw:ps-5" aria-label="未成功的原因">
          <li v-for="reason in failureReasons" :key="reason.key">{{ reason.label }}（{{ reason.count }} 次）。{{ reason.action }}</li>
        </ul>
      </details>
      <ul v-if="topSources.length" class="tw:mt-2 tw:mb-0 tw:ps-5 tw:text-tpl-body" data-testid="aia-sources">
        <li v-for="s in topSources" :key="s.name"><span class="tw:font-mono">{{ s.name }}</span>：{{ s.count }} 次</li>
      </ul>
      <h3 class="tw:mt-4 tw:mb-0 tw:text-tpl-body tw:font-semibold">提出的資料修正（受控操作）</h3>
      <p v-if="!report.operations.length" class="tw:mt-1 tw:mb-0 tw:text-tpl-body" data-testid="aia-no-ops">沒有。</p>
      <div v-else class="tw:mt-2 tw:overflow-x-auto">
        <Table>
          <TableCaption class="tw:sr-only">過去 30 天的受控資料操作</TableCaption>
          <TableHeader>
            <TableRow>
              <TableHead scope="col">時間</TableHead>
              <TableHead scope="col">操作</TableHead>
              <TableHead scope="col">狀態</TableHead>
              <TableHead scope="col" class="tw:text-right">核准紀錄</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="(op, i) in report.operations" :key="i" data-testid="aia-op">
              <TableCell class="tw:tabular-nums">{{ op.created_at.slice(0, 16) }}</TableCell>
              <TableCell class="tw:font-mono">{{ op.operation }}</TableCell>
              <TableCell>{{ op.status }}</TableCell>
              <TableCell class="tw:text-right tw:tabular-nums">{{ op.approval_events }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    </template>
  </section>
</template>

<script setup>
// 帳務中心重整 G3 (Founder 2026-11-16): one place to see what AI read and which data changes it asked for.
import { trackedFetch as fetch } from '../lib/loadingBar';
import { computed, onMounted, ref } from 'vue';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

const props = defineProps({ token: { type: String, required: true } });
const report = ref(null);
const loading = ref(true);
const error = ref('');
const topSources = computed(() => (report.value?.reads?.by_source || []).slice(0, 8));

const reasons = [
  ['access_denied', '權限攔截', '請使用已授權的查詢範圍。'],
  ['credential', '讀取憑證無效或過期', '請管理員檢查讀取憑證。'],
  ['not_found', '查無可讀資料', '請確認查詢對象及範圍。'],
  ['invalid_request', '查詢條件有誤', '請核對輸入的查詢條件。'],
  ['rate_limited', '查詢過於頻繁', '請稍後再查。'],
  ['service_error', '服務讀取失敗', '若持續發生，請回報發生時間。'],
  ['unknown', '其他或原因未記錄', '現有紀錄無法判定原因。'],
];
const failureReasons = computed(() => reasons
  .map(([key, label, action]) => ({ key, label, action, count: report.value?.reads?.failure_counts?.[key] || 0 }))
  .filter(({ count }) => count > 0));

onMounted(async () => {
  try {
    const res = await fetch('/api/v1/admin/ai-activity', { headers: { Authorization: `Bearer ${props.token}`, Accept: 'application/json' } });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(body?.message || '無法取得 AI 活動紀錄');
    report.value = body?.data?.reads ? body.data : null;
  } catch (e) {
    error.value = e?.message || '無法取得 AI 活動紀錄';
  } finally {
    loading.value = false;
  }
});
</script>
