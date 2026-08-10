<template>
  <div class="bhd-page">
    <AtPageHeader
      title="綁定健康度"
      description="全校與各分校 LINE 綁定概況、趨勢與異常事件。"
      icon="monitor_heart"
    >
      <template #meta>
        <span>更新於 <strong>{{ updatedLabel }}</strong></span>
      </template>
      <template #actions>
        <AtButton shape="rect" variant="ghost" icon="refresh" @click="refresh">重新整理</AtButton>
      </template>
    </AtPageHeader>

    <AtSkeleton v-if="loading" rows="5" />
    <div v-else-if="loadError" class="bhd-state">
      <AtInlineAlert tone="danger" title="無法載入綁定健康度">
        <p>{{ loadError }}</p>
        <template #action>
          <AtButton shape="rect" size="sm" variant="ghost" @click="refresh">重試</AtButton>
        </template>
      </AtInlineAlert>
    </div>

    <template v-else>
      <!-- 每週摘要卡 -->
      <div class="bhd-stats">
        <AtMetric label="本週新增綁定" :value="weekly.bound ?? '—'" delta="週綁定數" delta-tone="positive" accent="var(--ds-success)" />
        <AtMetric label="本週解除綁定" :value="weekly.unbound ?? '—'" delta="週解除數" delta-tone="negative" accent="var(--ds-danger)" />
        <AtMetric label="本週淨增" :value="weekly.net ?? '—'" :delta-tone="(weekly.net ?? 0) >= 0 ? 'positive' : 'negative'" accent="var(--ds-primary)" />
        <AtMetric label="全校綁定率" :value="overallRateLabel" delta="已綁定/活躍學生" delta-tone="neutral" accent="var(--ds-info)" />
      </div>

      <div class="bhd-grid">
        <!-- 全校綁定率環形圖 -->
        <AtSection title="全校綁定率">
          <div class="bhd-ring-wrap">
            <svg :viewBox="`0 0 ${RING * 2} ${RING * 2}`" class="bhd-ring" role="img" :aria-label="`全校綁定率 ${overallRateLabel}`">
              <circle class="bhd-ring-bg" :cx="RING" :cy="RING" :r="RING - 6" fill="none" />
              <circle
                class="bhd-ring-fg"
                :cx="RING" :cy="RING" :r="RING - 6" fill="none"
                :stroke-dasharray="`${ringDash} ${CIRCUMFERENCE - ringDash}`"
                stroke-dashoffset="0"
              />
            </svg>
            <div class="bhd-ring-center">
              <strong>{{ overallRateLabel }}</strong>
              <span>已綁定 {{ overall.bound_count ?? 0 }}</span>
            </div>
          </div>
        </AtSection>

        <!-- 各分校水平長條圖 -->
        <AtSection title="各分校綁定率">
          <ul class="bhd-bars">
            <li v-for="c in campusRates" :key="c.campus_id">
              <div class="bhd-bar-head">
                <span>{{ c.campus_name || `#${c.campus_id}` }}</span>
                <span class="bhd-tabular">{{ rateLabel(c.rate) }}（{{ c.bound_count }}/{{ c.student_total }}）</span>
              </div>
              <div class="bhd-bar-track">
                <div class="bhd-bar-fill" :style="{ width: barWidth(c.rate) }"></div>
              </div>
            </li>
          </ul>
        </AtSection>
      </div>

      <!-- 趨勢折線圖 -->
      <AtSection title="綁定趨勢">
        <template #actions>
          <div class="bhd-gran">
            <button
              v-for="g in ['day', 'week']"
              :key="g"
              type="button"
              :class="['bhd-gran-btn', { active: granularity === g }]"
              @click="setGranularity(g)"
            >{{ g === 'day' ? '日' : '週' }}</button>
          </div>
        </template>
        <div class="bhd-line-wrap">
          <svg :viewBox="`0 0 ${CHART_W} ${CHART_H}`" class="bhd-line" role="img" aria-label="綁定趨勢折線圖">
            <polyline class="bhd-line-grid" :points="gridPoints" fill="none" />
            <polyline class="bhd-line-path" :points="linePoints" fill="none" />
            <polyline class="bhd-line-area" :points="areaPoints" />
            <circle
              v-for="p in linePointsArr" :key="p.key"
              class="bhd-line-dot" :cx="p.x" :cy="p.y" r="3"
            />
          </svg>
        </div>
      </AtSection>

      <div class="bhd-grid bhd-grid--lower">
        <!-- 未綁定活躍學生 -->
        <AtSection title="未綁定活躍學生（近 30 天有課）">
          <ul v-if="unboundActive.length" class="bhd-list">
            <li v-for="s in unboundActive" :key="s.student_id">
              <div>
                <strong>{{ s.student_name }}</strong>
                <span class="bhd-list-meta">{{ s.campus_name || `#${s.campus_id}` }}</span>
              </div>
              <AtButton shape="rect" size="sm" variant="ghost" icon="visibility" @click="viewStudent(s)">查看</AtButton>
            </li>
          </ul>
          <AtEmpty v-else icon="check_circle" title="沒有未綁定的活躍學生" />
        </AtSection>

        <!-- 異常事件時間軸 -->
        <AtSection title="異常事件（近 7 天）">
          <ul v-if="anomalies.length" class="bhd-timeline">
            <li v-for="a in anomalies" :key="a.id" class="bhd-tl-item">
              <span class="bhd-tl-dot" :class="`bhd-tl-dot--${a.severity || 'warning'}`" aria-hidden="true"></span>
              <div>
                <div class="bhd-tl-head">
                  <AtBadge tone="warning" :label="a.type || a.reason_code || '異常'" />
                  <span class="bhd-tl-time bhd-tabular">{{ formatDateTime(a.occurred_at) }}</span>
                </div>
                <div class="bhd-tl-desc">{{ a.campus_name || `#${a.campus_id}` }}{{ a.student_name ? ` · ${a.student_name}` : '' }}</div>
              </div>
            </li>
          </ul>
          <AtEmpty v-else icon="shield" title="近 7 天無異常事件" />
        </AtSection>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { fetchBindingMetrics } from '../lib/bindingsApi';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtSection from '../components/design-system/AtSection.vue';
import AtMetric from '../components/design-system/AtMetric.vue';
import AtSkeleton from '../components/design-system/AtSkeleton.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
import AtBadge from '../components/design-system/AtBadge.vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: 'director' },
});

const loading = ref(false);
const loadError = ref('');
const data = ref(null);
const granularity = ref('day');
const updatedLabel = ref('—');

const RING = 56;
const CIRCUMFERENCE = 2 * Math.PI * (RING - 6);
const CHART_W = 600;
const CHART_H = 200;
const PAD = 20;

const overall = computed(() => data.value?.overall || {});
const weekly = computed(() => data.value?.weekly_summary || {});
const campusRates = computed(() => Array.isArray(data.value?.by_campus) ? data.value.by_campus : []);
const unboundActive = computed(() => Array.isArray(data.value?.unbound_active) ? data.value.unbound_active : []);
const anomalies = computed(() => Array.isArray(data.value?.anomalies) ? data.value.anomalies : []);

const overallRateLabel = computed(() => {
  const o = overall.value;
  if (!o || !o.student_total) return '—';
  return `${((o.bound_count / o.student_total) * 100).toFixed(1)}%`;
});
const ringDash = computed(() => {
  const o = overall.value;
  const rate = o?.student_total ? o.bound_count / o.student_total : 0;
  return Math.max(0, Math.min(1, rate)) * CIRCUMFERENCE;
});

function rateLabel(rate) {
  if (rate == null) return '—';
  return `${(Number(rate) * 100).toFixed(0)}%`;
}
function barWidth(rate) {
  const pct = rate == null ? 0 : Number(rate) * 100;
  return `${Math.max(0, Math.min(100, pct)).toFixed(1)}%`;
}

/* ── 折線圖計算 ── */
const trendSeries = computed(() => {
  const raw = data.value?.trend || {};
  const points = Array.isArray(raw.points) ? raw.points
    : Array.isArray(raw[granularity.value]) ? raw[granularity.value]
    : [];
  if (!points.length) return [];
  const key = (p) => (granularity.value === 'day' ? p.date : p.week) ?? p.period ?? '';
  const max = Math.max(1, ...points.map((p) => Number(p.bound || 0)));
  const n = points.length;
  const stepX = (CHART_W - PAD * 2) / Math.max(1, n - 1);
  return points.map((p, i) => ({
    key: key(p) || i,
    x: PAD + i * stepX,
    y: CHART_H - PAD - (Number(p.bound || 0) / max) * (CHART_H - PAD * 2),
  }));
});
const linePoints = computed(() => trendSeries.value.map((p) => `${p.x},${p.y}`).join(' '));
const areaPoints = computed(() => {
  const pts = trendSeries.value;
  if (!pts.length) return '';
  return `${PAD},${CHART_H - PAD} ${pts.map((p) => `${p.x},${p.y}`).join(' ')} ${CHART_W - PAD},${CHART_H - PAD}`;
});
const gridPoints = computed(() => {
  const ys = [0.25, 0.5, 0.75].map((r) => PAD + r * (CHART_H - PAD * 2));
  return ys.map((y) => `${PAD},${y} ${CHART_W - PAD},${y}`).join(' ');
});
const linePointsArr = computed(() => trendSeries.value);

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

<style scoped>
.bhd-page { max-width: 1180px; margin: 0 auto; }
.bhd-state { padding: var(--ds-space-4) 0; }

.bhd-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--ds-space-3);
  margin-bottom: var(--ds-space-3);
}

.bhd-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--ds-space-3);
  margin-bottom: var(--ds-space-3);
}
.bhd-grid--lower { grid-template-columns: 1fr 1fr; }

/* 環形圖 */
.bhd-ring-wrap { position: relative; display: grid; place-items: center; padding: var(--ds-space-2); }
.bhd-ring { width: 140px; height: 140px; transform: rotate(-90deg); }
.bhd-ring-bg { stroke: var(--ds-hairline); stroke-width: 10; }
.bhd-ring-fg { stroke: var(--ds-primary); stroke-width: 10; stroke-linecap: round; transition: stroke-dasharray var(--ds-motion-slow) var(--ds-ease-standard); }
.bhd-ring-center {
  position: absolute; inset: 0; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 4px; pointer-events: none;
}
.bhd-ring-center strong { font-size: 20px; color: var(--ds-ink); font-variant-numeric: tabular-nums; }
.bhd-ring-center span { font-size: var(--ds-font-size-sm); color: var(--ds-ink-mute); }

/* 水平長條圖 */
.bhd-bars { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--ds-space-3); }
.bhd-bar-head { display: flex; justify-content: space-between; font-size: var(--ds-font-size-md); color: var(--ds-ink); margin-bottom: 4px; gap: 8px; }
.bhd-tabular { font-variant-numeric: tabular-nums; color: var(--ds-ink-mute); white-space: nowrap; }
.bhd-bar-track { height: 8px; border-radius: var(--ds-radius-pill); background: var(--ds-surface-0); overflow: hidden; }
.bhd-bar-fill { height: 100%; border-radius: var(--ds-radius-pill); background: var(--ds-primary); transition: width var(--ds-motion-slow) var(--ds-ease-standard); }

/* 折線圖 */
.bhd-gran { display: inline-flex; gap: 4px; }
.bhd-gran-btn {
  border: var(--ds-border-width) solid var(--ds-hairline); background: var(--ds-canvas);
  color: var(--ds-ink-mute); border-radius: var(--ds-radius-md); padding: 4px 12px;
  font-size: var(--ds-font-size-sm); cursor: pointer; font-weight: var(--ds-font-weight-semibold);
}
.bhd-gran-btn.active { background: var(--ds-primary-wash); color: var(--ds-primary-deep); border-color: var(--ds-primary); }
.bhd-line-wrap { overflow-x: auto; }
.bhd-line { width: 100%; min-width: 320px; height: auto; }
.bhd-line-grid { stroke: var(--ds-hairline); stroke-width: 1; stroke-dasharray: 4 4; }
.bhd-line-path { stroke: var(--ds-primary); stroke-width: 2; fill: none; }
.bhd-line-area { fill: var(--ds-primary-wash); stroke: none; }
.bhd-line-dot { fill: var(--ds-primary); }

/* 列表 */
.bhd-list, .bhd-timeline { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--ds-space-2); }
.bhd-list li { display: flex; align-items: center; justify-content: space-between; gap: var(--ds-space-3); padding: var(--ds-space-2) var(--ds-space-3); border: var(--ds-border-width) solid var(--ds-hairline); border-radius: var(--ds-radius-md); background: var(--ds-surface-1); }
.bhd-list-meta { display: block; font-size: var(--ds-font-size-sm); color: var(--ds-ink-mute); }

/* 時間軸 */
.bhd-tl-item { display: flex; gap: var(--ds-space-3); padding: var(--ds-space-2) var(--ds-space-3); border: var(--ds-border-width) solid var(--ds-hairline); border-radius: var(--ds-radius-md); background: var(--ds-surface-1); }
.bhd-tl-dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 8px; flex-shrink: 0; background: var(--ds-warning); }
.bhd-tl-dot--danger { background: var(--ds-danger); }
.bhd-tl-dot--info { background: var(--ds-info); }
.bhd-tl-head { display: flex; align-items: center; gap: var(--ds-space-2); flex-wrap: wrap; }
.bhd-tl-time { font-size: var(--ds-font-size-sm); color: var(--ds-ink-mute); }
.bhd-tl-desc { font-size: var(--ds-font-size-sm); color: var(--ds-ink-secondary); margin-top: 4px; }

@media (max-width: 768px) {
  .bhd-grid, .bhd-grid--lower { grid-template-columns: 1fr; }
}
</style>
