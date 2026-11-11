<template>
  <div class="branch-health tw:mx-auto tw:flex tw:max-w-6xl tw:flex-col tw:gap-4 tw:px-[calc(var(--ds-space-4)-10px)] tw:text-foreground tw:md:px-0" data-testid="branch-health-board">
    <header class="tw:pb-2" data-testid="branch-health-header">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-2">
        <h2 class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-page tw:font-bold tw:tracking-tight">
          <Activity class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
          分校健康
        </h2>
        <Button variant="outline" class="tw:h-12 tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none" :disabled="loading" @click="load">
          <RefreshCw class="tw:size-4" :stroke-width="2" aria-hidden="true" />
          重新整理
        </Button>
      </div>
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">總部查看各分校目前可驗證的營運訊號；這不是總分或排名。</p>
      <p class="tw:mt-1 tw:text-tpl-caption tw:text-tpl-secondary">資料更新於 <strong class="tw:text-foreground">{{ updatedLabel }}</strong></p>
    </header>

    <div v-if="showSkeleton" class="tw:flex tw:flex-col tw:gap-3" data-testid="branch-health-skeleton">
      <Skeleton v-for="n in 6" :key="n" class="tw:h-14 tw:w-full" />
    </div>
    <Alert v-else-if="error" tone="danger" title="無法載入分校健康" class="tw:text-tpl-body">
      <p>{{ error }}</p>
      <Button variant="outline" class="tw:mt-3 tw:h-12 tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:text-foreground" @click="load">重試</Button>
    </Alert>

    <div v-else class="at-refetch tw:flex tw:flex-col tw:gap-4" :class="{ 'is-busy': busy }" :aria-busy="busy ? 'true' : 'false'">
      <div class="tw:flex tw:items-start tw:gap-2 tw:rounded-xl tw:border tw:bg-secondary tw:px-4 tw:py-3 tw:text-tpl-body tw:text-pretty" role="note">
        <Info class="tw:mt-0.5 tw:size-5 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
        <span>顏色只根據目前系統有的資料判斷；老師流失、老師可排課量、續班率、家長客訴還沒納入。</span>
      </div>

      <section class="branch-health__summary tw:grid tw:grid-cols-2 tw:gap-3 tw:min-[901px]:grid-cols-4" aria-label="分校健康摘要">
        <Card v-for="metric in metrics" :key="metric.label" class="tw:gap-0 tw:py-4 tw:shadow-none">
          <CardContent class="tw:flex tw:flex-col tw:gap-1 tw:px-4">
            <span class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-caption tw:text-tpl-secondary">
              <span class="tw:size-2.5 tw:shrink-0 tw:rounded-full" :style="{ backgroundColor: metric.accent }" aria-hidden="true" />{{ metric.label }}
            </span>
            <span class="tw:text-tpl-num-sm tw:leading-none tw:font-bold tw:tabular-nums">{{ metric.value }}</span>
            <span :class="['tw:text-tpl-caption', metric.negative ? 'tw:text-[color:var(--ds-danger-text)]' : 'tw:text-tpl-secondary']">{{ metric.delta }}</span>
          </CardContent>
        </Card>
      </section>

      <section class="tw:flex tw:flex-col tw:gap-3" aria-labelledby="bh-board-title">
        <h3 id="bh-board-title" class="tw:text-tpl-list tw:font-bold">分校健康看板</h3>
        <Card v-if="!rows.length" class="branch-health__empty tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none">
          <Activity class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
          <h4 class="tw:text-tpl-list tw:font-bold">目前沒有啟用中的分校資料</h4>
          <p class="tw:max-w-prose tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">啟用分校並接入營運訊號後，總部才能在這裡查看詳情。</p>
        </Card>
        <template v-else>
          <Card class="branch-health__table-wrap tw:gap-0 tw:py-0 tw:shadow-none tw:max-[900px]:hidden">
            <Table class="branch-health__table tw:min-w-[920px] tw:text-[length:var(--tpl-text-body)] tw:[&_td]:text-[length:var(--tpl-text-body)] tw:[&_th]:text-[length:var(--tpl-text-caption)]">
              <TableCaption class="tw:sr-only">各分校五個營運健康維度</TableCaption>
              <TableHeader>
                <TableRow class="tw:hover:bg-transparent">
                  <TableHead scope="col" :class="headCell">分校</TableHead>
                  <TableHead v-for="dimension in dimensionOrder" :key="dimension.key" scope="col" :class="headCell">{{ dimension.label }}</TableHead>
                  <TableHead scope="col" :class="headCell">主要狀況</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="row in rows" :key="row.branch_id" :class="['tw:cursor-pointer', selected?.branch_id === row.branch_id && 'tw:bg-muted/50']" @click="select(row)">
                  <TableHead scope="row" class="tw:bg-transparent tw:px-2 tw:py-2 tw:normal-case tw:text-[length:var(--tpl-text-body)]">
                    <Button variant="ghost" class="tw:h-12 tw:px-3 tw:text-[length:var(--tpl-text-body)] tw:font-bold" @click.stop="select(row)">{{ row.branch_name }}</Button>
                  </TableHead>
                  <TableCell v-for="dimension in dimensionOrder" :key="dimension.key" class="tw:px-4 tw:py-3">
                    <Badge variant="outline" :class="[pillBase, pillClass(row.dimensions?.[dimension.key])]" :title="row.dimensions?.[dimension.key]?.next_step || ''">{{ row.dimensions?.[dimension.key]?.label || '待接資料' }}</Badge>
                  </TableCell>
                  <TableCell class="branch-health__headline tw:max-w-[300px] tw:px-4 tw:py-3 tw:whitespace-normal tw:text-tpl-secondary">{{ row.headline }}</TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </Card>
          <div class="branch-health__mobile-list tw:flex tw:flex-col tw:gap-3 tw:min-[901px]:hidden" aria-label="分校健康清單">
            <Card v-for="row in rows" :key="row.branch_id" class="branch-health__mobile-card tw:gap-0 tw:py-4 tw:shadow-none" :class="selected?.branch_id === row.branch_id && 'tw:border-primary tw:ring-2 tw:ring-primary/30'">
              <CardContent class="tw:flex tw:flex-col tw:gap-4 tw:px-4">
                <div class="tw:flex tw:items-start tw:justify-between tw:gap-3">
                  <Button variant="ghost" class="tw:h-auto tw:min-h-12 tw:min-w-0 tw:justify-start tw:px-3 tw:text-left tw:text-[length:var(--tpl-text-body)] tw:font-bold tw:whitespace-normal" @click="select(row)">{{ row.branch_name }}</Button>
                  <span class="tw:shrink-0 tw:pt-3 tw:text-tpl-caption tw:text-tpl-secondary">查看詳情</span>
                </div>
                <dl class="tw:grid tw:grid-cols-2 tw:gap-2.5">
                  <div v-for="dimension in dimensionOrder" :key="dimension.key" class="tw:flex tw:min-w-0 tw:flex-col tw:gap-1 tw:rounded-lg tw:bg-secondary tw:p-2.5">
                    <dt class="tw:text-tpl-caption tw:text-tpl-secondary">{{ dimension.label }}</dt>
                    <dd><Badge variant="outline" :class="[pillBase, pillClass(row.dimensions?.[dimension.key])]">{{ row.dimensions?.[dimension.key]?.label || '待接資料' }}</Badge></dd>
                  </div>
                </dl>
                <p class="branch-health__mobile-headline tw:grid tw:gap-1 tw:text-tpl-body tw:text-tpl-secondary tw:[overflow-wrap:anywhere]"><strong class="tw:text-foreground">主要狀況</strong>{{ row.headline || '—' }}</p>
              </CardContent>
            </Card>
          </div>
        </template>
      </section>

      <section v-if="selected" class="tw:flex tw:flex-col tw:gap-3" aria-labelledby="bh-detail-title">
        <h3 id="bh-detail-title" class="tw:text-tpl-list tw:font-bold">{{ `${selected.branch_name} 詳情` }}</h3>
        <div class="tw:grid tw:grid-cols-1 tw:gap-3 tw:min-[901px]:grid-cols-2">
          <Card v-for="dimension in dimensionOrder" :key="dimension.key" class="tw:gap-0 tw:py-4 tw:shadow-none">
            <CardContent class="tw:flex tw:flex-col tw:gap-3 tw:px-4">
              <header class="tw:flex tw:items-start tw:justify-between tw:gap-3">
                <div class="tw:min-w-0">
                  <h4 class="tw:text-tpl-list tw:font-bold">{{ dimension.label }}</h4>
                  <p class="tw:mt-1 tw:text-tpl-caption tw:text-tpl-secondary">{{ selected.dimensions?.[dimension.key]?.source || '—' }}</p>
                </div>
                <Badge variant="outline" :class="[pillBase, pillClass(selected.dimensions?.[dimension.key])]">{{ selected.dimensions?.[dimension.key]?.label || '待接資料' }}</Badge>
              </header>
              <dl class="tw:grid tw:grid-cols-[1fr_auto] tw:gap-x-4 tw:gap-y-2 tw:text-tpl-body">
                <div v-for="signal in (selected.dimensions?.[dimension.key]?.signals || [])" :key="signal.key" class="tw:contents">
                  <dt class="tw:text-tpl-secondary">{{ signal.label }}</dt>
                  <dd class="tw:font-bold tw:tabular-nums">{{ signal.value }}</dd>
                </div>
              </dl>
              <p class="tw:text-tpl-caption tw:text-tpl-secondary">期間：{{ periodLabel(selected.dimensions?.[dimension.key]?.period) }}</p>
              <p class="tw:text-tpl-body"><strong>下一步：</strong>{{ selected.dimensions?.[dimension.key]?.next_step || '—' }}</p>
            </CardContent>
          </Card>
        </div>
        <p class="tw:border-t tw:pt-3 tw:text-tpl-caption tw:text-tpl-secondary">這份資料只協助總部找出要先看的分校，不代表分校品質保證，也不會自動修改任何資料。</p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Activity, Info, RefreshCw } from 'lucide-vue-next';
import { useRefetchView } from '../composables/useDelayedLoading';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

const props = defineProps({ token: { type: String, default: '' } });
const rows = ref([]); const selected = ref(null); const loading = ref(true); const error = ref(''); const updatedAt = ref('');
const { showSkeleton, busy } = useRefetchView(loading, computed(() => rows.value.length > 0));
// Presentation only: status pills use status text on its own wash; table heads override the global `th` rule.
const PILL_TONE = {
  green: 'tw:bg-[var(--ds-success-wash)] tw:text-[color:var(--ds-success-text)]',
  yellow: 'tw:bg-[var(--ds-warning-wash)] tw:text-[color:var(--ds-warning-text)]',
  red: 'tw:bg-[var(--ds-danger-wash)] tw:text-[color:var(--ds-danger-text)]',
  unavailable: 'tw:bg-secondary tw:text-tpl-secondary',
};
const pillBase = 'tw:border-transparent tw:px-3 tw:py-1 tw:text-[length:var(--tpl-text-caption)] tw:font-bold';
const headCell = 'tw:h-12 tw:px-4 tw:text-tpl-caption tw:font-semibold tw:normal-case tw:text-tpl-secondary';
const dimensionOrder = [{ key: 'students', label: '學生' }, { key: 'teaching', label: '教學' }, { key: 'parents', label: '家長' }, { key: 'teachers', label: '教師' }, { key: 'operations', label: '營運' }];
const statusCounts = computed(() => rows.value.reduce((counts, row) => { const status = row.status || 'green'; counts[status] = (counts[status] || 0) + 1; return counts; }, { red: 0, yellow: 0, green: 0 }));
const unavailableCount = computed(() => rows.value.reduce((count, row) => count + dimensionOrder.filter(({ key }) => row.dimensions?.[key]?.status === 'unavailable').length, 0));
const metrics = computed(() => [
  { label: '目前分校', value: rows.value.length, delta: '啟用中的分校', accent: 'var(--ds-primary)' },
  { label: '優先處理', value: statusCounts.value.red, delta: '有紅色訊號', accent: 'var(--ds-danger)', negative: true },
  { label: '需要注意', value: statusCounts.value.yellow, delta: '有黃色訊號', accent: 'var(--ds-warning)' },
  { label: '資料不足', value: unavailableCount.value, delta: '不代表正常', accent: 'var(--ds-info)' },
]);
const updatedLabel = computed(() => formatDateTime(updatedAt.value));

function api(path) { return fetch(`/api/v1${path}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${props.token}` } }); }
async function load() {
  loading.value = true; error.value = '';
  try {
    const response = await api('/admin/branch-health'); const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload?.message || `HTTP ${response.status}`);
    rows.value = Array.isArray(payload?.data) ? payload.data : []; updatedAt.value = payload?.meta?.generated_at || new Date().toISOString();
    selected.value = selected.value ? (rows.value.find((row) => row.branch_id === selected.value.branch_id) || rows.value[0] || null) : (rows.value[0] || null);
  } catch (e) { rows.value = []; selected.value = null; error.value = e?.message || '請稍後再試'; } finally { loading.value = false; }
}
function select(row) { selected.value = row; }
function pillClass(dimension) { return PILL_TONE[dimension?.status] || PILL_TONE.unavailable; }
function periodLabel(period) { return { current: '目前資料', next_7_days: '今天起 7 天', rolling_28_days: '近 28 天' }[period] || '未提供'; }
function formatDateTime(value) { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('zh-TW', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' }); }
onMounted(load);
</script>
