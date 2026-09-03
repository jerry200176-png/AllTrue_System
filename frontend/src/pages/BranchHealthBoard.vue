<template>
  <main class="branch-health" data-testid="branch-health-board">
    <AtPageHeader title="分校健康" description="總部查看各分校目前可驗證的營運訊號；這不是總分或排名。" icon="monitor_heart">
      <template #meta><span>資料更新於 <strong>{{ updatedLabel }}</strong></span></template>
      <template #actions><AtButton shape="rect" variant="ghost" icon="refresh" :disabled="loading" @click="load">重新整理</AtButton></template>
    </AtPageHeader>

    <AtSkeleton v-if="loading" rows="6" />
    <AtInlineAlert v-else-if="error" tone="danger" title="無法載入分校健康">
      <p>{{ error }}</p>
      <template #action><AtButton shape="rect" size="sm" variant="ghost" @click="load">重試</AtButton></template>
    </AtInlineAlert>

    <template v-else>
      <div class="branch-health__note" role="note"><span class="material-symbols-outlined" aria-hidden="true">info</span><span>紅／黃／綠只代表已接入的證據訊號。教師流失、教師 capacity、完整續班率與家長客訴尚未接入，不會被當成正常。</span></div>
      <section class="branch-health__summary" aria-label="分校健康摘要">
        <AtMetric label="目前分校" :value="rows.length" delta="啟用中的分校" accent="var(--ds-primary)" />
        <AtMetric label="優先處理" :value="statusCounts.red" delta="有紅色訊號" delta-tone="negative" accent="var(--ds-danger)" />
        <AtMetric label="需要注意" :value="statusCounts.yellow" delta="有黃色訊號" delta-tone="neutral" accent="var(--ds-warning)" />
        <AtMetric label="待接資料" :value="unavailableCount" delta="不是正常狀態" delta-tone="neutral" accent="var(--ds-info)" />
      </section>

      <AtSection title="分校健康看板">
        <div v-if="!rows.length" class="branch-health__empty" role="status">目前沒有啟用中的分校資料。</div>
        <div v-else class="branch-health__table-wrap">
          <table class="branch-health__table">
            <caption class="sr-only">各分校五個營運健康維度</caption>
            <thead><tr><th scope="col">分校</th><th v-for="dimension in dimensionOrder" :key="dimension.key" scope="col">{{ dimension.label }}</th><th scope="col">主要訊號</th></tr></thead>
            <tbody>
              <tr v-for="row in rows" :key="row.branch_id" :class="{ 'is-selected': selected?.branch_id === row.branch_id }" @click="select(row)">
                <th scope="row"><button type="button" class="branch-health__branch" @click.stop="select(row)">{{ row.branch_name }}</button></th>
                <td v-for="dimension in dimensionOrder" :key="dimension.key"><span :class="pillClass(row.dimensions?.[dimension.key])" :title="row.dimensions?.[dimension.key]?.next_step || ''">{{ row.dimensions?.[dimension.key]?.label || '待接資料' }}</span></td>
                <td class="branch-health__headline">{{ row.headline }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AtSection>

      <AtSection v-if="selected" :title="`${selected.branch_name} 詳情`">
        <div class="branch-health__detail-grid">
          <article v-for="dimension in dimensionOrder" :key="dimension.key" class="branch-health__dimension">
            <header><div><h3>{{ dimension.label }}</h3><p>{{ selected.dimensions?.[dimension.key]?.source || '—' }}</p></div><span :class="pillClass(selected.dimensions?.[dimension.key])">{{ selected.dimensions?.[dimension.key]?.label || '待接資料' }}</span></header>
            <dl><div v-for="signal in (selected.dimensions?.[dimension.key]?.signals || [])" :key="signal.key"><dt>{{ signal.label }}</dt><dd>{{ signal.value }}</dd></div></dl>
            <p class="branch-health__period">期間：{{ periodLabel(selected.dimensions?.[dimension.key]?.period) }}</p>
            <p class="branch-health__next"><strong>下一步：</strong>{{ selected.dimensions?.[dimension.key]?.next_step || '—' }}</p>
          </article>
        </div>
        <p class="branch-health__disclaimer">這份資料只協助總部找出要先看的分校，不代表分校品質保證，也不會自動修改任何資料。</p>
      </AtSection>
    </template>
  </main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtSection from '../components/design-system/AtSection.vue';
import AtMetric from '../components/design-system/AtMetric.vue';
import AtSkeleton from '../components/design-system/AtSkeleton.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';

const props = defineProps({ token: { type: String, default: '' } });
const rows = ref([]); const selected = ref(null); const loading = ref(false); const error = ref(''); const updatedAt = ref('');
const dimensionOrder = [{ key: 'students', label: '學生' }, { key: 'teaching', label: '教學' }, { key: 'parents', label: '家長' }, { key: 'teachers', label: '教師' }, { key: 'operations', label: '營運' }];
const statusCounts = computed(() => rows.value.reduce((counts, row) => { const status = row.status || 'green'; counts[status] = (counts[status] || 0) + 1; return counts; }, { red: 0, yellow: 0, green: 0 }));
const unavailableCount = computed(() => rows.value.reduce((count, row) => count + dimensionOrder.filter(({ key }) => row.dimensions?.[key]?.status === 'unavailable').length, 0));
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
function pillClass(dimension) { return `branch-health__pill branch-health__pill--${dimension?.status || 'unavailable'}`; }
function periodLabel(period) { return { current: '目前資料', next_7_days: '今天起 7 天', rolling_28_days: '近 28 天' }[period] || '未提供'; }
function formatDateTime(value) { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('zh-TW', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' }); }
onMounted(load);
</script>

<style scoped>
.branch-health { max-width: 1280px; margin: 0 auto; }
.branch-health__note { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 18px; padding: 12px 14px; color: var(--ds-ink-secondary); background: var(--ds-info-wash); border: 1px solid color-mix(in srgb, var(--ds-info) 25%, transparent); border-radius: 10px; font-size: 13px; line-height: 1.6; }
.branch-health__note .material-symbols-outlined { color: var(--ds-info); font-size: 19px; }
.branch-health__summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 0 0 18px; }
.branch-health__table-wrap { overflow-x: auto; }
.branch-health__table { width: 100%; min-width: 920px; border-collapse: collapse; font-size: 13px; }
.branch-health__table th, .branch-health__table td { padding: 13px 12px; border-top: 1px solid var(--ds-hairline); text-align: left; vertical-align: middle; }
.branch-health__table thead th { color: var(--ds-ink-mute); font-size: 11px; font-weight: 800; white-space: nowrap; }
.branch-health__table tbody tr { cursor: pointer; transition: background .15s ease; }
.branch-health__table tbody tr:hover, .branch-health__table tbody tr.is-selected { background: var(--ds-surface-subtle); }
.branch-health__branch { padding: 0; border: 0; background: transparent; color: var(--ds-ink); font: inherit; font-weight: 800; cursor: pointer; }
.branch-health__headline { max-width: 300px; color: var(--ds-ink-secondary); line-height: 1.5; }
.branch-health__pill { display: inline-flex; align-items: center; min-height: 26px; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 800; white-space: nowrap; }
.branch-health__pill--green { color: var(--ds-success); background: var(--ds-success-wash); }
.branch-health__pill--yellow { color: var(--ds-warning-ink, var(--ds-warning)); background: var(--ds-warning-wash); }
.branch-health__pill--red { color: var(--ds-danger); background: var(--ds-danger-wash); }
.branch-health__pill--unavailable { color: var(--ds-ink-mute); background: var(--ds-surface-subtle); }
.branch-health__detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.branch-health__dimension { padding: 16px; border: 1px solid var(--ds-hairline); border-radius: 10px; }
.branch-health__dimension header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.branch-health__dimension h3 { margin: 0; color: var(--ds-ink); font-size: 15px; }
.branch-health__dimension header p { margin: 4px 0 0; color: var(--ds-ink-mute); font-size: 11px; line-height: 1.4; }
.branch-health__dimension dl { display: grid; grid-template-columns: 1fr auto; gap: 8px 14px; margin: 16px 0 10px; }
.branch-health__dimension dl div { display: contents; }
.branch-health__dimension dt { color: var(--ds-ink-secondary); }
.branch-health__dimension dd { margin: 0; color: var(--ds-ink); font-weight: 800; font-variant-numeric: tabular-nums; }
.branch-health__period, .branch-health__next, .branch-health__disclaimer { margin: 0; color: var(--ds-ink-mute); font-size: 12px; line-height: 1.6; }
.branch-health__next { margin-top: 8px; color: var(--ds-ink-secondary); }
.branch-health__disclaimer { margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--ds-hairline); }
.branch-health__empty { padding: 32px 12px; color: var(--ds-ink-mute); text-align: center; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
@media (max-width: 720px) { .branch-health__summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } .branch-health__detail-grid { grid-template-columns: 1fr; } }
</style>
