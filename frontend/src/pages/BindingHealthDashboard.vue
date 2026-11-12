<template>
  <div class="bhd-page tw:mx-auto tw:flex tw:max-w-6xl tw:flex-col tw:gap-4 tw:px-[calc(var(--ds-space-4)-10px)] tw:pb-24 tw:text-foreground tw:md:px-0 tw:md:pb-0" data-testid="binding-health-page">
    <header class="tw:pb-2">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-2">
        <h2 class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-page tw:font-bold tw:tracking-tight">
          <HeartPulse class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
          綁定健康度
        </h2>
        <Button variant="outline" :class="actionButton" @click="refresh">
          <RefreshCw class="tw:size-4" :stroke-width="2" aria-hidden="true" />
          重新整理
        </Button>
      </div>
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">全校與各分校 LINE 綁定概況、趨勢與異常事件。</p>
      <p class="tw:mt-1 tw:text-tpl-caption tw:text-tpl-secondary" data-testid="binding-health-updated">更新於 <strong class="tw:text-foreground tw:tabular-nums">{{ updatedLabel }}</strong></p>
    </header>

    <div class="tw:flex tw:flex-col tw:gap-4" :aria-busy="loading ? 'true' : 'false'" data-testid="binding-health-content">
      <div v-if="loading" class="tw:flex tw:flex-col tw:gap-3" role="status" aria-live="polite" data-testid="binding-health-loading">
        <span class="tw:sr-only">載入中</span>
        <Skeleton v-for="n in 5" :key="n" class="tw:h-14 tw:w-full" />
      </div>
      <Alert v-else-if="loadError" tone="danger" title="無法載入綁定健康度" class="tw:text-tpl-body">
        <p class="tw:text-[length:var(--tpl-text-body)] tw:[overflow-wrap:anywhere]">{{ loadError }}</p>
        <Button variant="outline" :class="[actionButton, 'tw:mt-3 tw:text-foreground']" @click="refresh">重試</Button>
      </Alert>
      <Card v-else-if="!hasMetricsData" class="tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none" data-testid="binding-health-empty">
        <HeartPulse class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
        <h3 class="tw:text-tpl-list tw:font-bold">目前沒有可顯示的綁定健康度資料</h3>
        <p class="tw:max-w-prose tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">資料準備好後會顯示在這裡。請使用右上角重新整理。</p>
      </Card>

      <template v-else>
        <!-- 每週摘要卡 -->
        <section class="bhd-stats tw:grid tw:grid-cols-2 tw:gap-3 tw:min-[901px]:grid-cols-4" aria-label="本週綁定摘要">
          <Card v-for="metric in weeklyMetrics" :key="metric.label" class="bhd-metric tw:gap-0 tw:py-4 tw:shadow-none">
            <CardContent class="tw:flex tw:flex-col tw:gap-1 tw:px-4">
              <span class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-caption tw:text-tpl-secondary">
                <span :class="['tw:size-2.5 tw:shrink-0 tw:rounded-full', metric.dot]" aria-hidden="true" />{{ metric.label }}
              </span>
              <span class="tw:text-tpl-num-sm tw:leading-none tw:font-bold tw:tabular-nums">{{ metric.value }}</span>
              <span v-if="metric.delta" :class="['tw:text-tpl-caption', metric.deltaClass]">{{ metric.delta }}</span>
            </CardContent>
          </Card>
        </section>

        <div :class="twoColumns">
          <!-- 全校綁定率 -->
          <section :class="panel" aria-labelledby="bhd-overall-title">
            <Card :class="panelCard">
              <h3 id="bhd-overall-title" :class="panelTitle">全校綁定率</h3>
              <div class="bhd-ring-wrap tw:flex tw:flex-wrap tw:items-center tw:gap-5">
                <ActivityRing :done="overallBound" :total="overallTotal" :label="`全校綁定率 ${overallRateLabel}`" />
                <div class="tw:flex tw:min-w-0 tw:flex-col tw:gap-1">
                  <strong class="tw:text-tpl-num-sm tw:leading-none tw:font-bold tw:tabular-nums">{{ overallRateLabel }}</strong>
                  <span class="tw:text-tpl-body tw:text-tpl-secondary tw:tabular-nums">已綁定 {{ overall.bound_count ?? 0 }}</span>
                </div>
              </div>
            </Card>
          </section>

          <!-- 各分校綁定率 -->
          <section :class="panel" aria-labelledby="bhd-campus-title">
            <Card :class="panelCard">
              <h3 id="bhd-campus-title" :class="panelTitle">各分校綁定率</h3>
              <ul class="bhd-bars tw:flex tw:list-none tw:flex-col tw:gap-4">
                <li v-for="c in campusRates" :key="c.campus_id" class="tw:flex tw:flex-col tw:gap-2">
                  <div class="tw:flex tw:flex-wrap tw:items-baseline tw:justify-between tw:gap-x-3 tw:gap-y-1 tw:text-tpl-body">
                    <span class="tw:min-w-0 tw:[overflow-wrap:anywhere]">{{ campusLabel(c) }}</span>
                    <span class="tw:whitespace-nowrap tw:tabular-nums tw:text-tpl-secondary">{{ rateLabel(c.rate) }}（{{ c.bound_count }}/{{ c.student_total }}）</span>
                  </div>
                  <Progress :model-value="barValue(c.rate)" :get-value-label="() => `${campusLabel(c)}綁定率 ${rateLabel(c.rate)}`" />
                </li>
              </ul>
            </Card>
          </section>
        </div>

        <!-- 綁定趨勢 -->
        <section :class="panel" aria-labelledby="bhd-trend-title">
          <Card :class="panelCard">
            <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-2">
              <h3 id="bhd-trend-title" :class="panelTitle">綁定趨勢</h3>
              <div class="bhd-gran tw:flex tw:gap-1" role="group" aria-label="趨勢單位">
                <Button
                  v-for="g in granularities"
                  :key="g.value"
                  :variant="granularity === g.value ? 'secondary' : 'ghost'"
                  :class="[actionButton, 'tw:min-w-12']"
                  :aria-pressed="granularity === g.value ? 'true' : 'false'"
                  @click="setGranularity(g.value)"
                >{{ g.label }}</Button>
              </div>
            </div>
            <Sparkline v-if="trendPoints.length > 1" class="bhd-line tw:h-40!" :values="trendValues" tone="amber" label="綁定趨勢折線圖" />
            <p v-else-if="trendPoints.length === 1" class="tw:flex tw:items-baseline tw:gap-3 tw:text-tpl-body" data-testid="binding-health-trend-single">
              <span class="tw:text-tpl-secondary tw:tabular-nums">{{ trendPoints[0].key }}</span>
              <strong class="tw:text-tpl-list tw:font-bold tw:tabular-nums">{{ trendPoints[0].bound }}</strong>
            </p>
            <div v-else :class="panelEmpty">
              <ChartLine class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
              <div>
                <p class="tw:font-semibold">目前沒有趨勢資料</p>
                <p class="tw:text-tpl-secondary">選擇其他期間或稍後重新整理，查看最新綁定變化。</p>
              </div>
            </div>
          </Card>
        </section>

        <div :class="twoColumns">
          <!-- 未綁定活躍學生 -->
          <section :class="panel" aria-labelledby="bhd-unbound-title">
            <Card :class="panelCard">
              <h3 id="bhd-unbound-title" :class="panelTitle">未綁定活躍學生（近 30 天有課）</h3>
              <ul v-if="unboundActive.length" class="bhd-list tw:flex tw:list-none tw:flex-col tw:gap-2">
                <li v-for="s in unboundActive" :key="s.student_id" :class="[listRow, 'tw:items-center tw:justify-between']">
                  <div class="tw:flex tw:min-w-0 tw:flex-col">
                    <strong class="tw:text-tpl-body tw:[overflow-wrap:anywhere]">{{ s.student_name }}</strong>
                    <span class="tw:text-tpl-caption tw:text-tpl-secondary tw:[overflow-wrap:anywhere]">{{ campusLabel(s) }}</span>
                  </div>
                  <Button variant="outline" :class="actionButton" :aria-label="`查看：${s.student_name}`" @click="viewStudent(s)">
                    <Eye class="tw:size-4" :stroke-width="2" aria-hidden="true" />
                    查看
                  </Button>
                </li>
              </ul>
              <div v-else :class="panelEmpty">
                <CircleCheck class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
                <p class="tw:font-semibold">沒有未綁定的活躍學生</p>
              </div>
            </Card>
          </section>

          <!-- 異常事件 -->
          <section :class="panel" aria-labelledby="bhd-anomaly-title">
            <Card :class="panelCard">
              <h3 id="bhd-anomaly-title" :class="panelTitle">異常事件（近 7 天）</h3>
              <ul v-if="anomalies.length" class="bhd-timeline tw:flex tw:list-none tw:flex-col tw:gap-2">
                <li v-for="a in anomalies" :key="a.id" :class="[listRow, 'tw:items-start']">
                  <span :class="['bhd-tl-dot tw:mt-2 tw:size-2.5 tw:shrink-0 tw:rounded-full', severityDot(a.severity)]" aria-hidden="true" />
                  <div class="tw:flex tw:min-w-0 tw:flex-col tw:gap-1">
                    <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                      <Badge variant="outline" :class="anomalyBadge">{{ a.type || a.reason_code || '異常' }}</Badge>
                      <span class="tw:text-tpl-caption tw:tabular-nums tw:text-tpl-secondary">{{ formatDateTime(a.occurred_at) }}</span>
                    </div>
                    <p class="tw:text-tpl-body tw:[overflow-wrap:anywhere]">{{ campusLabel(a) }}{{ a.student_name ? ` · ${a.student_name}` : '' }}</p>
                  </div>
                </li>
              </ul>
              <div v-else :class="panelEmpty">
                <ShieldCheck class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
                <p class="tw:font-semibold">近 7 天無異常事件</p>
              </div>
            </Card>
          </section>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { ChartLine, CircleCheck, Eye, HeartPulse, RefreshCw, ShieldCheck } from 'lucide-vue-next';
import { ActivityRing } from '@/components/ui/activity-ring';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { Sparkline } from '@/components/ui/sparkline';
import { fetchBindingMetrics } from '../lib/bindingsApi';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: 'director' },
});

const loading = ref(false);
const loadError = ref('');
const data = ref(null);
const granularity = ref('day');
const updatedLabel = ref('—');

// Presentation only. Controls are 48px tall; panels are hairline cards; the two-column grids fold to one at 768px and below.
const actionButton = 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none';
const panel = 'tw:flex tw:min-w-0';
const panelCard = 'tw:w-full tw:min-w-0 tw:gap-4 tw:p-4 tw:shadow-none';
const panelTitle = 'tw:text-tpl-list tw:font-bold';
const panelEmpty = 'tw:flex tw:items-start tw:gap-3 tw:rounded-lg tw:border tw:border-dashed tw:border-border tw:px-4 tw:py-5 tw:text-tpl-body';
const twoColumns = 'bhd-grid tw:grid tw:grid-cols-1 tw:gap-4 tw:min-[769px]:grid-cols-2';
const listRow = 'tw:flex tw:gap-3 tw:rounded-lg tw:border tw:border-border tw:bg-secondary tw:px-3 tw:py-2';
const anomalyBadge = 'tw:border-transparent tw:bg-[var(--ds-warning-wash)] tw:px-3 tw:py-1 tw:text-[length:var(--tpl-text-caption)] tw:font-bold tw:text-[color:var(--ds-warning-text)]';
const SEVERITY_DOT = { danger: 'tw:bg-destructive', info: 'tw:bg-[var(--ds-info)]' };
const severityDot = (severity) => SEVERITY_DOT[severity] || 'tw:bg-[var(--ds-warning)]';
const granularities = [{ value: 'day', label: '日' }, { value: 'week', label: '週' }];

const overall = computed(() => data.value?.overall || {});
const weekly = computed(() => data.value?.weekly_summary || {});
const campusRates = computed(() => Array.isArray(data.value?.by_campus) ? data.value.by_campus : []);
const unboundActive = computed(() => Array.isArray(data.value?.unbound_active) ? data.value.unbound_active : []);
const anomalies = computed(() => Array.isArray(data.value?.anomalies) ? data.value.anomalies : []);
const hasMetricsData = computed(() => {
  const d = data.value || {};
  return Object.keys(d.overall || {}).length > 0
    || Object.keys(d.weekly_summary || {}).length > 0
    || campusRates.value.length > 0
    || Array.isArray(d.trend?.points) && d.trend.points.length > 0
    || Array.isArray(d.trend?.day) && d.trend.day.length > 0
    || Array.isArray(d.trend?.week) && d.trend.week.length > 0
    || unboundActive.value.length > 0
    || anomalies.value.length > 0;
});

const overallRateLabel = computed(() => {
  const o = overall.value;
  if (!o || !o.student_total) return '—';
  return `${((o.bound_count / o.student_total) * 100).toFixed(1)}%`;
});
const overallBound = computed(() => Number(overall.value?.bound_count) || 0);
const overallTotal = computed(() => Number(overall.value?.student_total) || 0);
const weeklyMetrics = computed(() => [
  { label: '本週新增綁定', value: weekly.value.bound ?? '—', delta: '週綁定數', deltaClass: 'tw:text-[color:var(--ds-success-text)]', dot: 'tw:bg-[var(--ds-success)]' },
  { label: '本週解除綁定', value: weekly.value.unbound ?? '—', delta: '週解除數', deltaClass: 'tw:text-[color:var(--ds-danger-text)]', dot: 'tw:bg-[var(--ds-danger)]' },
  { label: '本週淨增', value: weekly.value.net ?? '—', delta: '', deltaClass: '', dot: 'tw:bg-primary' },
  { label: '全校綁定率', value: overallRateLabel.value, delta: '已綁定/活躍學生', deltaClass: 'tw:text-tpl-secondary', dot: 'tw:bg-[var(--ds-info)]' },
]);

const campusLabel = (row) => row.campus_name || `#${row.campus_id}`;
function rateLabel(rate) {
  if (rate == null) return '—';
  return `${(Number(rate) * 100).toFixed(0)}%`;
}
// The bar is the rate kept inside 0–100 (one decimal), as before.
function barValue(rate) {
  const pct = rate == null ? 0 : Number(rate) * 100;
  return Number(Math.max(0, Math.min(100, pct)).toFixed(1)) || 0;
}

/* ── 趨勢 ── */
const trendPoints = computed(() => {
  const raw = data.value?.trend || {};
  const points = Array.isArray(raw.points) ? raw.points
    : Array.isArray(raw[granularity.value]) ? raw[granularity.value]
    : [];
  return points.map((p) => ({
    key: (granularity.value === 'day' ? p.date : p.week) ?? p.period ?? '',
    bound: Number(p.bound || 0),
  }));
});
const trendValues = computed(() => trendPoints.value.map((p) => p.bound));

function setGranularity(g) { granularity.value = g; }

function formatDateTime(s) {
  if (!s) return '—';
  try {
    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return String(s);
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getMonth() + 1)}/${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  } catch { return String(s); }
}

function viewStudent(s) {
  try {
    window.location.hash = '#/students';
    sessionStorage.setItem('alltrue_binding_student_focus', JSON.stringify({ studentId: s.student_id, studentName: s.student_name }));
  } catch { /* ignore */ }
}

async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    data.value = await fetchBindingMetrics({ campusId: props.branchId || undefined });
    updatedLabel.value = formatDateTime(new Date().toISOString());
  } catch (e) {
    data.value = null;
    loadError.value = (e?.status === 404 || e?.status === 500)
      ? `後端健康度 API 尚未上線（HTTP ${e.status}）。此頁依賴 W31 P3-1 端點，合併後即可使用。`
      : (e?.payload?.message || e?.message || '無法載入健康度資料');
  } finally {
    loading.value = false;
  }
}
function refresh() { load(); }

watch(() => props.branchId, () => load());
onMounted(load);
</script>
