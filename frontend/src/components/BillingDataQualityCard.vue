<template>
  <section class="tw:mt-6 tw:rounded-2xl tw:border tw:border-border tw:bg-card tw:p-5" aria-labelledby="bdq-title" data-testid="billing-data-quality">
    <div class="tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-3 tw:gap-y-1">
      <h2 id="bdq-title" class="tw:m-0 tw:text-tpl-section tw:font-semibold">帳務資料品質</h2>
      <p class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary">每個分校現在各有幾筆。數字往下走，代表帳變乾淨了。只看結果，不會改資料。</p>
    </div>
    <p v-if="loading" class="tw:mt-3 tw:mb-0 tw:text-tpl-body" role="status">載入中…</p>
    <p v-else-if="error" class="tw:mt-3 tw:mb-0 tw:text-tpl-body" role="alert" data-testid="bdq-error">{{ error }}</p>
    <div v-else-if="report" class="tw:mt-3 tw:overflow-x-auto">
      <Table>
        <TableCaption class="tw:sr-only">各分校帳務資料品質筆數</TableCaption>
        <TableHeader>
          <TableRow>
            <TableHead scope="col">分校</TableHead>
            <TableHead v-for="rule in report.rules" :key="rule.key" scope="col" class="tw:text-right tw:whitespace-normal">{{ rule.label }}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="campus in report.campuses" :key="campus.campus_id" :data-testid="`bdq-campus-${campus.campus_id}`">
            <TableCell>{{ campus.campus_name || `分校 ${campus.campus_id}` }}</TableCell>
            <TableCell v-for="rule in report.rules" :key="rule.key" class="tw:text-right tw:tabular-nums">{{ campus.counts[rule.key] || '—' }}</TableCell>
          </TableRow>
          <TableRow data-testid="bdq-total">
            <TableCell class="tw:font-semibold">全部</TableCell>
            <TableCell v-for="rule in report.rules" :key="rule.key" class="tw:text-right tw:font-semibold tw:tabular-nums">{{ report.totals[rule.key] || 0 }}</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  </section>
</template>

<script setup>
// 帳務中心重整 G2 (Founder 2026-11-16): the scoreboard that says whether each step of the rework worked.
import { onMounted, ref } from 'vue';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

const props = defineProps({ token: { type: String, required: true } });
const report = ref(null);
const loading = ref(true);
const error = ref('');

onMounted(async () => {
  try {
    const res = await fetch('/api/v1/admin/billing-data-quality', { headers: { Authorization: `Bearer ${props.token}`, Accept: 'application/json' } });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(body?.message || '無法取得帳務資料品質');
    report.value = body?.data?.rules ? body.data : null;
  } catch (e) {
    error.value = e?.message || '無法取得帳務資料品質';
  } finally {
    loading.value = false;
  }
});
</script>
