<template>
  <div class="dsc-page tw:mx-auto tw:flex tw:max-w-6xl tw:flex-col tw:gap-4 tw:px-[calc(var(--ds-space-4)-10px)] tw:text-foreground tw:md:px-0" data-testid="schedule-discrepancy-page">
    <header class="tw:relative tw:pb-2">
      <details class="tw:group/help" data-testid="sdp-help">
        <summary
          class="tw:flex tw:min-h-12 tw:w-fit tw:max-w-full tw:cursor-pointer tw:list-none tw:items-center tw:gap-2 tw:rounded-md tw:outline-none tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50 tw:[&::-webkit-details-marker]:hidden"
          title="快速處理 SOP"
          aria-label="課表回報管理，快速處理 SOP"
        >
          <h2 class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-page tw:font-bold tw:tracking-tight">
            <Flag class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
            課表回報管理
          </h2>
          <Info class="tw:size-5 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
        </summary>
        <div class="tw:mt-2 tw:w-full tw:rounded-xl tw:border tw:bg-card tw:p-4 tw:text-tpl-body tw:shadow-none" role="note">
          <strong>快速處理 SOP（建議流程）</strong>
          <ol class="tw:mt-2 tw:list-decimal tw:pl-5">
            <li>先按「已確認」：代表你已接手此回報，避免同事重複處理。</li>
            <li>確認堂次與時段：必要時先複製老師建議時間，完成資料修正後再關單。</li>
            <li>填寫處理說明後按「標記已修正」：請寫清楚「修了什麼」與「影響範圍」。</li>
          </ol>
        </div>
      </details>
      <div class="tw:mt-2 tw:sm:absolute tw:sm:top-0 tw:sm:right-0 tw:sm:mt-0">
        <Button variant="outline" class="tw:h-12 tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold" @click="refresh">
          <RefreshCw class="tw:size-4" :stroke-width="2" aria-hidden="true" />
          重新整理
        </Button>
      </div>
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">老師回報的課表與實際不符情形。處理後系統會留下稽核紀錄。</p>
    </header>

    <!-- Tabs -->
    <Tabs :model-value="activeTab" class="dsc-tabs tw:gap-4" @update:model-value="setTab">
      <TabsList aria-label="回報狀態" class="tw:h-auto tw:w-full tw:justify-start tw:gap-1 tw:overflow-x-auto tw:p-1 tw:sm:w-fit">
        <TabsTrigger
          v-for="tab in tabs"
          :key="tab.value"
          :value="tab.value"
          class="tw:min-h-12 tw:flex-1 tw:gap-2 tw:px-2 tw:text-[length:var(--tpl-text-body)] tw:sm:flex-none tw:sm:px-4 tw:font-semibold tw:motion-reduce:transition-none"
        >
          {{ tab.label }}
          <DiscrepancyStatusBadge v-if="counts[tab.value] > 0" :status="tab.value">{{ counts[tab.value] }}</DiscrepancyStatusBadge>
        </TabsTrigger>
      </TabsList>

      <TabsContent :value="activeTab" class="dsc-list-wrap tw:flex tw:flex-col tw:gap-4" :aria-busy="loading">
        <Card v-if="!hasBranch" class="dsc-state dsc-state-empty dsc-state-no-branch tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none">
          <MapPin class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
          <h3 class="dsc-empty-title tw:text-tpl-list tw:font-bold">請先選擇要管理的分校</h3>
          <p class="dsc-empty-sub tw:max-w-prose tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">課表回報屬於各分校的內部資料；請於上方切換分校後再查看。</p>
        </Card>
        <Card v-else-if="loading" class="dsc-state dsc-state-loading tw:flex-row tw:items-center tw:justify-center tw:gap-3 tw:py-10 tw:text-tpl-body tw:text-tpl-secondary tw:shadow-none" role="status" aria-live="polite">
          <Loader2 class="tw:size-5 tw:animate-spin tw:motion-reduce:animate-none" :stroke-width="2" aria-hidden="true" />
          <span>載入中…</span>
        </Card>
        <Card v-else-if="errorMsg" class="dsc-state dsc-state-error tw:flex-row tw:flex-wrap tw:items-center tw:justify-center tw:gap-3 tw:px-5 tw:py-8 tw:text-tpl-body tw:text-[color:var(--ds-danger-text)] tw:shadow-none" role="alert" aria-live="assertive">
          <CircleAlert class="tw:size-5 tw:shrink-0" :stroke-width="2" aria-hidden="true" />
          <span>{{ errorMsg }}</span>
          <Button type="button" variant="outline" class="tw:h-12 tw:px-5 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:text-foreground" @click="refresh">重試</Button>
        </Card>
        <Card v-else-if="rows.length === 0" class="dsc-state dsc-state-empty tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none" role="status">
          <span class="material-symbols-outlined dsc-empty-icon tw:text-[length:var(--tpl-text-num-sm)] tw:text-tpl-secondary" aria-hidden="true">{{ emptyIcon }}</span>
          <h3 class="dsc-empty-title tw:text-tpl-list tw:font-bold">{{ emptyTitle }}</h3>
          <p class="dsc-empty-sub tw:max-w-prose tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">{{ emptySub }}</p>
        </Card>

        <!-- Desktop table -->
        <Card v-else-if="hasBranch" class="dsc-desktop tw:gap-0 tw:overflow-hidden tw:py-0 tw:shadow-none tw:max-[768px]:hidden">
          <Table class="dsc-table tw:text-[length:var(--tpl-text-body)] tw:[&_td]:text-[length:var(--tpl-text-body)] tw:[&_th]:text-[length:var(--tpl-text-caption)]">
            <TableHeader>
              <TableRow class="tw:hover:bg-transparent">
                <TableHead class="tw:h-12 tw:px-4 tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">回報時間</TableHead>
                <TableHead class="tw:h-12 tw:px-4 tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">老師</TableHead>
                <TableHead class="tw:h-12 tw:px-4 tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">分校</TableHead>
                <TableHead class="tw:h-12 tw:px-4 tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">堂次 / 出入類型</TableHead>
                <TableHead class="tw:h-12 tw:px-4 tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">狀態</TableHead>
                <TableHead class="tw:h-12 tw:px-4 tw:text-right tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <template v-for="row in rows" :key="row.id">
                <TableRow :class="['tw:align-top', expandedId === row.id && 'dsc-row-expanded tw:bg-muted/50']">
                  <TableCell class="tw:px-4 tw:py-3 tw:whitespace-nowrap tw:tabular-nums">
                    <div class="dsc-ts">{{ formatDateTime(row.created_at) }}</div>
                  </TableCell>
                  <TableCell class="tw:px-4 tw:py-3 tw:break-keep tw:whitespace-normal">{{ row.reporter_name || '未提供老師姓名' }}</TableCell>
                  <TableCell class="tw:px-4 tw:py-3 tw:break-keep tw:whitespace-normal">{{ row.branch_name || '未指定分校' }}</TableCell>
                  <TableCell class="tw:px-4 tw:py-3 tw:whitespace-normal">
                    <div class="dsc-cell-main tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                      <Badge variant="secondary" class="dsc-type-pill tw:px-3 tw:py-1 tw:text-[length:var(--tpl-text-caption)]">{{ row.discrepancy_type_label }}</Badge>
                      <span v-if="row.student_name" class="dsc-student tw:font-semibold">{{ row.student_name }}</span>
                    </div>
                    <div class="dsc-cell-sub tw:mt-1 tw:flex tw:flex-wrap tw:items-center tw:gap-x-1 tw:text-tpl-caption tw:text-tpl-secondary tw:tabular-nums">
                      <span v-if="row.session_date">{{ row.session_date }}</span>
                      <template v-if="row.time_range">
                        <span class="dsc-time-original" :class="{ 'dsc-time-has-correction': row.corrected_time_range, 'tw:line-through': row.corrected_time_range }">
                          · {{ row.time_range }}
                        </span>
                        <template v-if="row.corrected_time_range">
                          <ArrowRight class="dsc-time-arrow tw:size-4" :stroke-width="2" aria-hidden="true" />
                          <span class="dsc-time-corrected tw:font-semibold tw:text-foreground" :title="'老師建議修正為 ' + row.corrected_time_range">{{ row.corrected_time_range }}</span>
                        </template>
                      </template>
                      <span v-if="row.subject">· {{ row.subject }}</span>
                    </div>
                  </TableCell>
                  <TableCell class="tw:px-4 tw:py-3">
                    <DiscrepancyStatusBadge class="dsc-status" :status="row.status">{{ statusLabel(row.status) }}</DiscrepancyStatusBadge>
                  </TableCell>
                  <TableCell class="tw:px-4 tw:py-3 tw:text-right">
                    <div class="dsc-row-ops tw:flex tw:flex-nowrap tw:justify-end tw:gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        :class="rowBtn"
                        :aria-expanded="expandedId === row.id"
                        :aria-controls="`sdp-detail-${row.id}`"
                        @click="toggleExpand(row)"
                      >
                        {{ expandedId === row.id ? '收合' : scheduleDiscrepancyActionLabel(row.status) }}
                      </Button>
                      <Button
                        v-if="row.status === 'pending'"
                        type="button"
                        :class="rowBtn"
                        :disabled="busyId === row.id"
                        @click="acknowledge(row)"
                      >
                        {{ busyId === row.id && busyAction === 'ack' ? '處理中…' : '標記已確認' }}
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
                <TableRow v-if="expandedId === row.id" class="dsc-detail-row tw:hover:bg-transparent">
                  <TableCell colspan="6" class="tw:px-4 tw:py-4 tw:whitespace-normal">
                    <div :id="`sdp-detail-${row.id}`" class="dsc-detail tw:flex tw:flex-col tw:gap-4">
                      <dl class="dsc-detail-grid tw:grid tw:grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] tw:gap-4">
                        <div>
                          <dt class="dsc-detail-label tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">備註</dt>
                          <dd class="dsc-detail-value tw:break-words">{{ row.notes || '（無）' }}</dd>
                        </div>
                        <div>
                          <dt class="dsc-detail-label tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">堂次編號</dt>
                          <dd class="dsc-detail-value">{{ row.class_session_id ? `#${row.class_session_id}` : '— 無對應堂次 —' }}</dd>
                        </div>
                        <div>
                          <dt class="dsc-detail-label tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">確認時間</dt>
                          <dd class="dsc-detail-value tw:tabular-nums">{{ formatDateTime(row.acknowledged_at) || '—' }}</dd>
                        </div>
                        <div>
                          <dt class="dsc-detail-label tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">解決時間</dt>
                          <dd class="dsc-detail-value tw:tabular-nums">{{ formatDateTime(row.resolved_at) || '—' }}</dd>
                        </div>
                      </dl>

                      <div v-if="row.corrected_time_range" class="dsc-detail-corrected">
                        <div class="dsc-detail-label tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">老師建議修正為</div>
                        <div class="dsc-detail-corrected-body tw:mt-1 tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:tabular-nums">
                          <span class="dsc-detail-time-original tw:line-through">{{ row.time_range || '—' }}</span>
                          <ArrowRight class="dsc-detail-arrow tw:size-4" :stroke-width="2" aria-hidden="true" />
                          <span class="dsc-detail-time-corrected tw:font-semibold">{{ row.corrected_time_range }}</span>
                          <Button
                            type="button"
                            variant="outline"
                            :class="rowBtn"
                            :title="'複製「' + row.corrected_time_range + '」'"
                            @click="copyCorrectedTime(row)"
                          >
                            <Copy class="tw:size-4" :stroke-width="2" aria-hidden="true" />
                            複製
                          </Button>
                        </div>
                      </div>

                      <div v-if="row.status === 'resolved' && row.resolution_note" class="dsc-detail-note">
                        <div class="dsc-detail-label tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">處理說明</div>
                        <div class="dsc-detail-value dsc-detail-note-text tw:break-words tw:whitespace-pre-wrap">{{ row.resolution_note }}</div>
                      </div>

                      <DiscrepancyResolveForm
                        v-if="row.status !== 'resolved' && row.status !== 'withdrawn'"
                        :row-id="row.id"
                        :draft="resolutionDrafts[row.id]"
                        :templates="noteTemplates"
                        :can-resolve="canResolve(row)"
                        :busy="busyId === row.id"
                        :submitting="busyId === row.id && busyAction === 'resolve'"
                        :error="resolveError[row.id]"
                        @update:draft="resolutionDrafts[row.id] = $event"
                        @template="applyTemplate(row, $event)"
                        @resolve="resolve(row)"
                      />
                    </div>
                  </TableCell>
                </TableRow>
              </template>
            </TableBody>
          </Table>
        </Card>

        <!-- Mobile card list -->
        <div v-if="hasBranch && rows.length > 0" class="dsc-mobile tw:flex tw:flex-col tw:gap-3 tw:min-[769px]:hidden">
          <Card v-for="row in rows" :key="'m-' + row.id" role="article" class="dsc-mcard tw:gap-3 tw:py-4 tw:shadow-none">
            <CardContent class="tw:flex tw:flex-col tw:gap-3 tw:px-4">
              <div class="dsc-mcard-head tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
                <Badge variant="secondary" class="dsc-type-pill tw:px-3 tw:py-1 tw:text-[length:var(--tpl-text-caption)]">{{ row.discrepancy_type_label }}</Badge>
                <DiscrepancyStatusBadge class="dsc-status" :status="row.status">{{ statusLabel(row.status) }}</DiscrepancyStatusBadge>
              </div>
              <div class="dsc-mcard-meta tw:flex tw:flex-col tw:gap-1 tw:text-tpl-body tw:break-words">
                <div><span class="dsc-mcard-label tw:text-tpl-secondary">老師</span> {{ row.reporter_name || '未提供老師姓名' }}</div>
                <div><span class="dsc-mcard-label tw:text-tpl-secondary">分校</span> {{ row.branch_name || '未指定分校' }}</div>
                <div v-if="row.student_name"><span class="dsc-mcard-label tw:text-tpl-secondary">學生</span> {{ row.student_name }}</div>
                <div v-if="row.session_date || row.time_range" class="tw:tabular-nums">
                  <span class="dsc-mcard-label tw:mr-1 tw:text-tpl-secondary">時段</span>
                  <span>{{ row.session_date || '' }}</span>
                  <template v-if="row.time_range">
                    <span class="dsc-time-original" :class="{ 'dsc-time-has-correction': row.corrected_time_range, 'tw:line-through': row.corrected_time_range }">
                      {{ ' ' + row.time_range }}
                    </span>
                    <template v-if="row.corrected_time_range">
                      <ArrowRight class="dsc-time-arrow tw:inline tw:size-4" :stroke-width="2" aria-hidden="true" />
                      <span class="dsc-time-corrected tw:font-semibold">{{ row.corrected_time_range }}</span>
                    </template>
                  </template>
                </div>
                <div v-if="row.notes"><span class="dsc-mcard-label tw:text-tpl-secondary">備註</span> {{ row.notes }}</div>
                <div v-if="row.resolution_note"><span class="dsc-mcard-label tw:text-tpl-secondary">處理說明</span> {{ row.resolution_note }}</div>
              </div>
              <div class="dsc-mcard-ops tw:flex tw:flex-wrap tw:gap-2">
                <Button v-if="row.status === 'pending'" type="button" :class="rowBtn" :disabled="busyId === row.id" @click="acknowledge(row)">
                  {{ busyId === row.id && busyAction === 'ack' ? '…' : '已確認' }}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  :class="rowBtn"
                  :aria-expanded="expandedId === row.id"
                  :aria-controls="`sdp-mobile-detail-${row.id}`"
                  @click="toggleExpand(row)"
                >
                  {{ expandedId === row.id ? '收合' : scheduleDiscrepancyActionLabel(row.status) }}
                </Button>
              </div>
              <div v-if="expandedId === row.id && row.status !== 'resolved' && row.status !== 'withdrawn'" :id="`sdp-mobile-detail-${row.id}`">
                <DiscrepancyResolveForm
                  compact
                  id-prefix="sdp-m"
                  :row-id="row.id"
                  :draft="resolutionDrafts[row.id]"
                  :templates="noteTemplates"
                  :can-resolve="canResolve(row)"
                  :busy="busyId === row.id"
                  :submitting="busyId === row.id && busyAction === 'resolve'"
                  :error="resolveError[row.id]"
                  @update:draft="resolutionDrafts[row.id] = $event"
                  @template="applyTemplate(row, $event)"
                  @resolve="resolve(row)"
                />
              </div>
            </CardContent>
          </Card>
        </div>
      </TabsContent>
    </Tabs>

    <!-- Toast -->
    <Teleport to="body">
      <Transition
        enter-active-class="tw:transition tw:duration-200 tw:motion-reduce:transition-none"
        leave-active-class="tw:transition tw:duration-200 tw:motion-reduce:transition-none"
        enter-from-class="tw:-translate-y-2 tw:opacity-0"
        leave-to-class="tw:opacity-0"
      >
        <div
          v-if="toast.visible"
          class="dsc-toast tw:fixed tw:inset-x-4 tw:top-4 tw:z-[100000] tw:mx-auto tw:flex tw:max-w-md tw:items-center tw:gap-3 tw:rounded-xl tw:border tw:bg-card tw:px-4 tw:py-3 tw:text-tpl-body tw:text-card-foreground tw:shadow-tpl-card"
          :class="toast.tone === 'error' ? 'tw:border-[var(--ds-danger-line)]' : ''"
          role="status"
        >
          <CircleAlert v-if="toast.tone === 'error'" class="tw:size-5 tw:shrink-0 tw:text-[color:var(--ds-danger-text)]" :stroke-width="2" aria-hidden="true" />
          <CircleCheck v-else-if="toast.tone === 'success'" class="tw:size-5 tw:shrink-0 tw:text-[color:var(--ds-success-text)]" :stroke-width="2" aria-hidden="true" />
          <Info v-else class="tw:size-5 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
          <span>{{ toast.text }}</span>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { ArrowRight, CircleAlert, CircleCheck, Copy, Flag, Info, Loader2, MapPin, RefreshCw } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import DiscrepancyResolveForm from '@/components/schedule-discrepancy/DiscrepancyResolveForm.vue';
import DiscrepancyStatusBadge from '@/components/schedule-discrepancy/DiscrepancyStatusBadge.vue';
import { fetchDiscrepancies, fetchDiscrepancySummary, updateDiscrepancyStatus, STATUS_LABELS } from '../lib/scheduleDiscrepanciesApi';
import { scheduleDiscrepancyActionLabel } from '../lib/calendarViewDisplay.js';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
});

// Markup only (#4989): shared class for the row-level action buttons (48px high, body text).
const rowBtn = 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold';

const tabs = [
  { value: 'pending', label: '待處理' },
  { value: 'acknowledged', label: '處理中' },
  { value: 'resolved', label: '已解決' },
];

const activeTab = ref('pending');
const rows = ref([]);
const counts = ref({ pending: 0, acknowledged: 0, resolved: 0, withdrawn: 0 });
const loading = ref(false);
const errorMsg = ref('');

const expandedId = ref(null);
const resolutionDrafts = reactive({});
const resolveError = reactive({});
const busyId = ref(null);
const busyAction = ref('');

const toast = reactive({ visible: false, text: '', tone: 'success' });
let toastTimer = null;
const noteTemplates = [
  {
    key: 'time-adjusted',
    label: '時段已修正',
    text: '已依老師回報修正堂次時段，並確認後續課表顯示一致。',
  },
  {
    key: 'session-linked',
    label: '堂次已補登',
    text: '已補齊對應堂次資料並重新對齊課表，後續處理流程可正常延續。',
  },
  {
    key: 'no-action',
    label: '回報已釐清',
    text: '經查為顯示時差/操作順序差異，資料本體正確，已與回報老師確認。',
  },
];

const emptyIcon = computed(() => {
  if (activeTab.value === 'resolved') return 'flag_circle';
  if (activeTab.value === 'acknowledged') return 'hourglass_empty';
  return 'task_alt';
});
const emptyTitle = computed(() => {
  if (activeTab.value === 'resolved') return '尚無已解決的回報';
  if (activeTab.value === 'acknowledged') return '目前沒有處理中的回報';
  return '目前沒有待處理的回報';
});
const emptySub = computed(() => {
  if (activeTab.value === 'resolved') return '處理完畢的回報會集中顯示在這裡，便於日後追溯。';
  if (activeTab.value === 'acknowledged') return '老師送出新回報並由主任點擊「已確認」後，會進入此列表。';
  return '太棒了，老師沒有回報任何課表出入情形。';
});

// FR-008: never hit the API when no branch is selected; show an explicit empty-state instead.
const hasBranch = computed(() => {
  const v = props.branchId;
  return v !== null && v !== undefined && v !== '' && Number(v) > 0;
});

function statusLabel(s) { return STATUS_LABELS[s] || s; }

function formatDateTime(s) {
  if (!s) return '';
  try {
    const d = new Date(s);
    return `${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  } catch { return ''; }
}

function showToast(text, tone = 'success') {
  if (toastTimer) clearTimeout(toastTimer);
  toast.text = text;
  toast.tone = tone;
  toast.visible = true;
  toastTimer = setTimeout(() => { toast.visible = false; }, 3000);
}

// ── Page-level polling (FR-001 ~ FR-004, FR-006) ─────────────────────────────
// pending tab → 30s; other tabs → 60s. Pauses when page is hidden.
let _pagePollingTimer = null;

const POLL_INTERVAL_PENDING = 30_000;
const POLL_INTERVAL_OTHER   = 60_000;

function _pollInterval() {
  return activeTab.value === 'pending' ? POLL_INTERVAL_PENDING : POLL_INTERVAL_OTHER;
}

function startPagePolling() {
  stopPagePolling();
  _pagePollingTimer = setInterval(() => {
    if (!hasBranch.value || document.visibilityState !== 'visible') return;
    load();
  }, _pollInterval());
}

function stopPagePolling() {
  if (_pagePollingTimer) { clearInterval(_pagePollingTimer); _pagePollingTimer = null; }
}

function _onVisibilityChange() {
  if (document.visibilityState === 'visible' && hasBranch.value) load();
}

function setTab(v) {
  activeTab.value = v;
  expandedId.value = null;
  stopPagePolling();
  load();
  startPagePolling();
}

function toggleExpand(row) {
  if (expandedId.value === row.id) {
    expandedId.value = null;
  } else {
    expandedId.value = row.id;
    if (resolutionDrafts[row.id] === undefined) {
      resolutionDrafts[row.id] = '';
    }
  }
}

function canResolve(row) {
  const note = (resolutionDrafts[row.id] || '').trim();
  return note.length >= 10 && row.status !== 'resolved' && row.status !== 'withdrawn';
}

function applyTemplate(row, templateKey) {
  const tpl = noteTemplates.find((item) => item.key === templateKey);
  if (!tpl) return;
  resolutionDrafts[row.id] = tpl.text;
  resolveError[row.id] = '';
}

async function load() {
  // FR-008: guard — never call the API without a branch; surfaces as empty-state instead.
  if (!hasBranch.value) {
    rows.value = [];
    counts.value = { pending: 0, acknowledged: 0, resolved: 0, withdrawn: 0 };
    loading.value = false;
    errorMsg.value = '';
    return;
  }
  loading.value = true;
  errorMsg.value = '';
  try {
    const [list, summary] = await Promise.all([
      fetchDiscrepancies({
        branchId: props.branchId,
        status: activeTab.value,
        perPage: 50,
      }),
      fetchDiscrepancySummary(props.branchId),
    ]);
    rows.value = Array.isArray(list?.data) ? list.data : [];
    counts.value = summary || counts.value;
  } catch (e) {
    errorMsg.value = e?.message || '載入失敗，請重試';
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

async function copyCorrectedTime(row) {
  const v = row?.corrected_time_range;
  if (!v) return;
  try {
    if (navigator?.clipboard?.writeText) {
      await navigator.clipboard.writeText(v);
    } else {
      const ta = document.createElement('textarea');
      ta.value = v;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    }
    showToast(`已複製「${v}」`, 'info');
  } catch {
    showToast('複製失敗，請手動選取', 'error');
  }
}

function refresh() { load(); }

async function acknowledge(row) {
  busyId.value = row.id;
  busyAction.value = 'ack';
  try {
    await updateDiscrepancyStatus(row.id, 'acknowledged');
    showToast('已標記為處理中');
    // Optimistically remove from pending list; refresh counts.
    if (activeTab.value === 'pending') {
      rows.value = rows.value.filter((r) => r.id !== row.id);
    }
    await refreshSummary();
  } catch (e) {
    showToast(e?.payload?.message || e?.message || '操作失敗', 'error');
  } finally {
    busyId.value = null;
    busyAction.value = '';
  }
}

async function resolve(row) {
  const note = (resolutionDrafts[row.id] || '').trim();
  if (note.length < 10) return;
  busyId.value = row.id;
  busyAction.value = 'resolve';
  resolveError[row.id] = '';
  try {
    await updateDiscrepancyStatus(row.id, 'resolved', note);
    showToast('已標記為已修正');
    resolutionDrafts[row.id] = '';
    expandedId.value = null;
    if (activeTab.value !== 'resolved') {
      rows.value = rows.value.filter((r) => r.id !== row.id);
    } else {
      await load();
    }
    await refreshSummary();
  } catch (e) {
    resolveError[row.id] = e?.payload?.message || e?.message || '送出失敗，請稍後再試';
  } finally {
    busyId.value = null;
    busyAction.value = '';
  }
}

async function refreshSummary() {
  try {
    counts.value = await fetchDiscrepancySummary(props.branchId);
  } catch { /* non-fatal */ }
}

watch(() => props.branchId, () => {
  stopPagePolling();
  load();
  startPagePolling();
});
onMounted(() => {
  load();
  startPagePolling();
  document.addEventListener('visibilitychange', _onVisibilityChange);
});
onBeforeUnmount(() => {
  stopPagePolling();
  document.removeEventListener('visibilitychange', _onVisibilityChange);
});
</script>
