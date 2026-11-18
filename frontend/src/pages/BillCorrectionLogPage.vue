<script setup>
// 更正紀錄 (step 2 PR 3): HQ (super_admin) reads the history of 更正帳單, newest first. Read only.
import { onMounted, ref } from 'vue';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { authedFetch } from '../lib/authedFetch.js';

const rows = ref([]);
const page = ref(1);
const lastPage = ref(1);
const month = ref('');
const loading = ref(true);
const error = ref('');

const money = (n) => `NT$ ${Number(n || 0).toLocaleString('zh-TW')}`;
const day = (s) => String(s || '').slice(0, 10);

async function load(to = page.value) {
  loading.value = true;
  error.value = '';
  try {
    const q = new URLSearchParams({ page: String(to), per_page: '20', ...(/^\d{4}-\d{2}$/.test(month.value) ? { month: month.value } : {}) });
    const res = await authedFetch(`/api/v1/accounting/bill-corrections?${q}`, { headers: { Accept: 'application/json' } });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error();
    rows.value = json.data ?? [];
    page.value = json.current_page ?? to;
    lastPage.value = json.last_page ?? 1;
  } catch {
    error.value = '更正紀錄載入失敗，請按「重新載入」再試一次。';
  } finally {
    loading.value = false;
  }
}
const search = () => load(1);

onMounted(() => load(1));
</script>

<template>
  <main class="tw:mx-auto tw:flex tw:max-w-6xl tw:flex-col tw:gap-4 tw:text-foreground" data-testid="bill-correction-log">
    <header class="tw:flex tw:flex-col tw:gap-2">
      <h2 class="tw:text-xl tw:font-bold">更正紀錄</h2>
      <p class="tw:text-sm tw:text-muted-foreground">各分校主任按「更正帳單」留下的紀錄，新的在最上面。這裡只能查看。</p>
      <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-3">
        <label class="tw:flex tw:flex-col tw:gap-1 tw:text-sm">月份
          <Input v-model="month" type="month" class="tw:w-44" data-testid="bcl-month" @change="search" />
        </label>
        <Button variant="outline" class="tw:min-h-11" :disabled="loading" data-testid="bcl-reload" @click="load(page)">重新載入</Button>
      </div>
    </header>

    <Alert v-if="error" tone="danger" data-testid="bcl-error">{{ error }}</Alert>
    <p v-else-if="loading && !rows.length" role="status">正在載入…</p>
    <Alert v-else-if="!rows.length" tone="info" data-testid="bcl-empty">{{ month ? '這個月還沒有更正紀錄。' : '目前還沒有任何更正紀錄。' }}</Alert>

    <Table v-else data-testid="bcl-table">
      <TableHeader>
        <TableRow>
          <TableHead>日期</TableHead>
          <TableHead>分校</TableHead>
          <TableHead>原帳單</TableHead>
          <TableHead>新帳單</TableHead>
          <TableHead>金額</TableHead>
          <TableHead>原因</TableHead>
          <TableHead>操作人</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="r in rows" :key="r.id">
          <TableCell>{{ day(r.created_at) }}</TableCell>
          <TableCell>{{ r.campus_name || '—' }}</TableCell>
          <TableCell>#{{ r.original_invoice_id }}</TableCell>
          <TableCell>#{{ r.replacement_invoice_id }}</TableCell>
          <TableCell class="tw:whitespace-nowrap">{{ money(r.old_total) }} → {{ money(r.new_total) }}</TableCell>
          <TableCell class="tw:whitespace-normal">{{ r.reason }}</TableCell>
          <TableCell>{{ r.operator_name || '—' }}</TableCell>
        </TableRow>
      </TableBody>
    </Table>

    <nav v-if="lastPage > 1" class="tw:flex tw:items-center tw:gap-3" aria-label="更正紀錄分頁">
      <Button variant="outline" class="tw:min-h-11" :disabled="loading || page <= 1" data-testid="bcl-prev" @click="load(page - 1)">上一頁</Button>
      <span class="tw:text-sm">第 {{ page }} / {{ lastPage }} 頁</span>
      <Button variant="outline" class="tw:min-h-11" :disabled="loading || page >= lastPage" data-testid="bcl-next" @click="load(page + 1)">下一頁</Button>
    </nav>
  </main>
</template>
