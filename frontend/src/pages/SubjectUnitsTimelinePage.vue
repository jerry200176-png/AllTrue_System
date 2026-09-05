<template>
  <div class="subject-units-page">
    <AtPageHeader
      title="科目數統計"
      description="按日期、分校與科目查看正課、輔導／試聽與核薪科目數的變化。"
      icon="calculate"
      data-guide="subject-units-header"
    >
      <template #actions>
        <div class="period-actions" aria-label="日期範圍">
          <button class="ghost small" type="button" aria-label="上一個月份" @click="shiftMonth(-1)">←</button>
          <label class="date-field">
            <span>起日</span>
            <input v-model="startDate" type="date" @change="loadData" />
          </label>
          <span class="date-separator" aria-hidden="true">至</span>
          <label class="date-field">
            <span>迄日</span>
            <input v-model="endDate" type="date" @change="loadData" />
          </label>
          <button class="ghost small" type="button" aria-label="下一個月份" @click="shiftMonth(1)">→</button>
          <button class="secondary small" type="button" @click="setCurrentMonth">本月</button>
        </div>
        <label v-if="branchOptions.length > 0" class="branch-field">
          <span>分校</span>
          <select v-model="selectedBranchId" @change="loadData">
            <option value="all">全部可見分校</option>
            <option v-for="branch in branchOptions" :key="branch.id" :value="String(branch.id)">
              {{ branch.name }}
            </option>
          </select>
        </label>
      </template>
    </AtPageHeader>

    <div class="scope-strip" role="status">
      <span class="material-symbols-outlined scope-icon" aria-hidden="true">verified_user</span>
      <span>{{ scopeLabel }}</span>
      <span class="scope-separator" aria-hidden="true">·</span>
      <span class="scope-period">{{ periodLabel }}</span>
    </div>

    <div v-if="loading" class="state-card" aria-live="polite">
      <span class="material-symbols-outlined state-icon loading-icon" aria-hidden="true">progress_activity</span>
      <span>載入中…</span>
    </div>

    <div v-else-if="errorMessage" class="state-card state-card--error" role="alert">
      <span class="material-symbols-outlined state-icon" aria-hidden="true">error</span>
      <div>
        <strong>科目數資料載入失敗</strong>
        <p>{{ errorMessage }}</p>
        <button class="secondary small" type="button" @click="loadData">重新載入</button>
      </div>
    </div>

    <template v-else>
      <section class="summary-grid" data-guide="subject-units-summary" aria-label="科目數摘要">
        <AtMetric
          label="核薪科目數"
          :value="formatCount(totals.payroll_subject_count)"
          :delta="`${totals.session_count} 堂 · ${days.length} 個有資料日`"
          delta-tone="positive"
          accent="var(--ds-cta)"
        />
        <AtMetric
          label="正課科目數"
          :value="formatCount(totals.regular_subject_count)"
          :delta="`${formatHours(totals.regular_hours)} 小時`"
          accent="var(--ds-primary)"
        />
        <AtMetric
          label="輔導／試聽科目數"
          :value="formatCount(totals.tutoring_trial_subject_count)"
          :delta="`${formatHours(totals.tutoring_trial_hours)} 小時`"
          accent="var(--ds-warning)"
        />
        <AtMetric
          label="明細列"
          :value="entries.length"
          delta="老師 × 日期 × 分校 × 科目"
          accent="var(--ds-info)"
        />
      </section>

      <section class="card daily-trend-card" aria-labelledby="daily-trend-title">
        <div class="section-heading">
          <div>
            <p class="eyebrow">每日變化</p>
            <h3 id="daily-trend-title">每日核薪科目數</h3>
          </div>
          <span class="section-hint">選一天可快速聚焦下方明細</span>
        </div>
        <div v-if="days.length > 0" class="trend-grid" role="list" aria-label="每日核薪科目數趨勢">
          <button
            v-for="day in days"
            :key="day.date"
            type="button"
            class="trend-day"
            :class="{ 'trend-day--selected': focusedDate === day.date }"
            :aria-label="`${formatDate(day.date)} 核薪科目數 ${formatCount(day.payroll_subject_count)}`"
            @click="toggleFocusedDate(day.date)"
          >
            <span class="trend-value">{{ formatCount(day.payroll_subject_count) }}</span>
            <span class="trend-track" aria-hidden="true">
              <span class="trend-bar" :style="{ height: `${barHeight(day.payroll_subject_count)}%` }" />
            </span>
            <span class="trend-date">{{ shortDate(day.date) }}</span>
          </button>
        </div>
        <div v-else class="inline-empty">
          <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
          <span>這段期間沒有已認列的科目數。可調整日期或分校範圍。</span>
        </div>
      </section>

      <section class="card detail-card" data-guide="subject-units-table" aria-labelledby="detail-title">
        <div class="detail-heading">
          <div>
            <p class="eyebrow">日明細</p>
            <h3 id="detail-title">老師 × 日期 × 分校 × 科目</h3>
          </div>
          <span class="row-count">{{ filteredEntries.length }} 筆明細</span>
        </div>
        <div class="table-toolbar">
          <label class="search-field">
            <span class="material-symbols-outlined" aria-hidden="true">search</span>
            <span class="sr-only">搜尋老師、分校或科目</span>
            <input v-model.trim="searchQuery" type="search" placeholder="搜尋老師、分校或科目" />
          </label>
          <label class="filter-field">
            <span>顯示</span>
            <select v-model="categoryFilter">
              <option value="all">全部明細</option>
              <option value="regular">只看正課</option>
              <option value="tutoring_trial">只看輔導／試聽</option>
            </select>
          </label>
          <button v-if="focusedDate" class="ghost small clear-focus" type="button" @click="focusedDate = ''">清除日期篩選</button>
        </div>

        <div v-if="filteredEntries.length === 0" class="table-empty">
          <span class="material-symbols-outlined" aria-hidden="true">filter_alt_off</span>
          <span>找不到符合條件的明細。請調整搜尋或篩選條件。</span>
        </div>
        <div v-else class="table-wrap">
          <table class="detail-table">
            <caption class="sr-only">科目數日明細</caption>
            <thead>
              <tr>
                <th scope="col">日期</th>
                <th scope="col">老師</th>
                <th scope="col">分校</th>
                <th scope="col">科目</th>
                <th scope="col" class="number-cell">正課</th>
                <th scope="col" class="number-cell">輔導／試聽</th>
                <th scope="col" class="number-cell highlight-cell">核薪</th>
                <th scope="col" class="number-cell">堂數</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="group in groupedEntries" :key="group.date">
                <tr class="day-summary-row">
                  <th colspan="4" scope="rowgroup">
                    {{ formatDate(group.date) }}
                    <span>{{ group.entries.length }} 個科目明細</span>
                  </th>
                  <td class="number-cell">{{ formatCount(group.summary.regular_subject_count) }}</td>
                  <td class="number-cell">{{ formatCount(group.summary.tutoring_trial_subject_count) }}</td>
                  <td class="number-cell highlight-cell">{{ formatCount(group.summary.payroll_subject_count) }}</td>
                  <td class="number-cell">{{ group.summary.session_count }}</td>
                </tr>
                <tr v-for="entry in group.entries" :key="entryKey(entry)" class="detail-row">
                  <td class="date-cell">{{ formatDate(entry.date) }}</td>
                  <td class="teacher-cell"><strong>{{ entry.teacher_name }}</strong></td>
                  <td><span class="campus-pill">{{ entry.campus_name }}</span></td>
                  <td><span class="subject-name">{{ entry.subject_name }}</span></td>
                  <td class="number-cell">{{ formatCount(entry.regular_subject_count) }}</td>
                  <td class="number-cell">{{ formatCount(entry.tutoring_trial_subject_count) }}</td>
                  <td class="number-cell highlight-cell"><strong>{{ formatCount(entry.payroll_subject_count) }}</strong></td>
                  <td class="number-cell">{{ entry.session_count }}</td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card disclosure-card calc-guide" data-guide="subject-units-formula">
        <div class="calc-guide-header">
          <h3 id="subject-units-calc-guide-title">
            <button
              type="button"
              class="ghost small calc-guide-toggle"
              :aria-expanded="showCalcGuide"
              aria-controls="subject-units-calc-guide-body"
              @click="showCalcGuide = !showCalcGuide"
            >
              <span class="material-symbols-outlined" aria-hidden="true">info</span>
              <span>查看計算方式</span>
              <span aria-hidden="true">{{ showCalcGuide ? '收合' : '展開' }}</span>
            </button>
          </h3>
        </div>
        <div
        v-show="showCalcGuide"
          id="subject-units-calc-guide-body"
          class="disclosure-body"
          aria-labelledby="subject-units-calc-guide-title"
        >
          <p>數字沿用既有核薪科目數規則；本頁只改成可按日、分校與科目追查的呈現方式。</p>
          <div class="formula-row"><span>正課</span><strong>一對一 × 1.5 ＋ 一對二 × 0.75 ＋ 一對三 × 0.5</strong></div>
          <div class="formula-row"><span>輔導／試聽</span><strong>已認列到班時數 × 0.5</strong></div>
          <div class="formula-row"><span>核薪科目數</span><strong>（正課加權 ＋ 輔導／試聽加權）÷ 8</strong></div>
        </div>
      </section>

      <section class="card disclosure-card level-breakdown-card">
        <div class="level-breakdown-header">
          <h3 id="subject-units-level-breakdown-title">
            <button
              type="button"
              class="ghost small level-breakdown-toggle"
              :aria-expanded="showLevelBreakdown"
              aria-controls="subject-units-level-breakdown-body"
              @click="showLevelBreakdown = !showLevelBreakdown"
            >
              <span class="material-symbols-outlined" aria-hidden="true">tune</span>
              <span>分組與資料範圍</span>
              <span aria-hidden="true">{{ showLevelBreakdown ? '收合' : '展開' }}</span>
            </button>
          </h3>
        </div>
        <div
        v-show="showLevelBreakdown"
          id="subject-units-level-breakdown-body"
          class="disclosure-body compact-copy"
          aria-labelledby="subject-units-level-breakdown-title"
        >
          <p>主任會看到自己分校權限內的老師；老師只會看到自己的資料。分校取學生目前所屬分校，避免同一堂跨校重複歸類。</p>
          <p>資料來源為已核准且未排除的評量，以及既有出勤認列規則；堂次只在同一個老師、日期、分校、科目列計一次。</p>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { branches, loadBranches } from '../lib/useBranches';
import AtMetric from '../components/design-system/AtMetric.vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';

const props = defineProps({
  branchId: [String, Number],
  userRole: { type: String, default: '' },
});

const loading = ref(true);
const errorMessage = ref('');
const entries = ref([]);
const days = ref([]);
const totals = ref({ regular_subject_count: 0, tutoring_trial_subject_count: 0, payroll_subject_count: 0, regular_hours: 0, tutoring_trial_hours: 0, session_count: 0 });
const currentDate = ref(new Date());
const startDate = ref(monthStart(currentDate.value));
const endDate = ref(monthEnd(currentDate.value));
const selectedBranchId = ref(props.branchId ? String(props.branchId) : 'all');
const searchQuery = ref('');
const categoryFilter = ref('all');
const focusedDate = ref('');
const showCalcGuide = ref(false);
const showLevelBreakdown = ref(false);
let requestSerial = 0;

const sessionCampusIds = computed(() => {
  try {
    const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    return (Array.isArray(session?.user?.campuses) ? session.user.campuses : []).map((id) => Number(id)).filter((id) => Number.isFinite(id));
  } catch { return []; }
});

const branchOptions = computed(() => (branches.value || []).filter((branch) => {
  const id = Number(branch?.id);
  return Number.isFinite(id) && (props.userRole !== 'teacher' || sessionCampusIds.value.includes(id));
}).map((branch) => ({ id: Number(branch.id), name: branch.name || `分校 #${branch.id}` })));

const selectedBranchName = computed(() => selectedBranchId.value === 'all'
  ? '全部可見分校'
  : branchOptions.value.find((branch) => String(branch.id) === String(selectedBranchId.value))?.name || '所選分校');
const scopeLabel = computed(() => props.userRole === 'teacher' ? `只顯示我的資料 · ${selectedBranchName.value}` : `主任權限範圍 · ${selectedBranchName.value}`);
const periodLabel = computed(() => `${formatDate(startDate.value)} 至 ${formatDate(endDate.value)}`);

const filteredEntries = computed(() => {
  const query = searchQuery.value.toLowerCase();
  return entries.value.filter((entry) => !focusedDate.value || entry.date === focusedDate.value)
    .filter((entry) => categoryFilter.value === 'all'
      || (categoryFilter.value === 'regular' && Number(entry.regular_subject_count) > 0)
      || (categoryFilter.value === 'tutoring_trial' && Number(entry.tutoring_trial_subject_count) > 0))
    .filter((entry) => !query || [entry.teacher_name, entry.campus_name, entry.subject_name].some((value) => String(value || '').toLowerCase().includes(query)))
    .sort((a, b) => a.date.localeCompare(b.date) || String(a.teacher_name).localeCompare(String(b.teacher_name), 'zh-Hant') || String(a.campus_name).localeCompare(String(b.campus_name), 'zh-Hant') || String(a.subject_name).localeCompare(String(b.subject_name), 'zh-Hant'));
});
const dayMap = computed(() => new Map(days.value.map((day) => [day.date, day])));
const groupedEntries = computed(() => {
  const groups = new Map();
  filteredEntries.value.forEach((entry) => {
    if (!groups.has(entry.date)) groups.set(entry.date, { date: entry.date, entries: [], summary: dayMap.value.get(entry.date) || emptyDay(entry.date) });
    groups.get(entry.date).entries.push(entry);
  });
  return Array.from(groups.values());
});
const maxDailyValue = computed(() => Math.max(0.01, ...days.value.map((day) => Number(day.payroll_subject_count) || 0)));

function monthStart(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-01`; }
function monthEnd(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()).padStart(2, '0')}`; }
function emptyDay(date) { return { date, regular_subject_count: 0, tutoring_trial_subject_count: 0, payroll_subject_count: 0, session_count: 0 }; }
function formatCount(value) { const number = Number(value ?? 0); return (Number.isFinite(number) ? number : 0).toFixed(2); }
function formatHours(value) { const number = Number(value ?? 0); return (Number.isFinite(number) ? number : 0).toFixed(1); }
function formatDate(value) { if (!value) return '—'; const [, month, day] = String(value).slice(0, 10).split('-'); return `${month}/${day}`; }
function shortDate(value) { return formatDate(value); }
function entryKey(entry) { return `${entry.teacher_id}-${entry.date}-${entry.campus_id}-${entry.subject_id}`; }
function barHeight(value) { return Math.max(8, Math.round((Number(value || 0) / maxDailyValue.value) * 100)); }
function toggleFocusedDate(date) { focusedDate.value = focusedDate.value === date ? '' : date; }
function shiftMonth(delta) { const date = new Date(`${startDate.value}T00:00:00`); date.setMonth(date.getMonth() + delta); currentDate.value = date; startDate.value = monthStart(date); endDate.value = monthEnd(date); focusedDate.value = ''; loadData(); }
function setCurrentMonth() { const date = new Date(); currentDate.value = date; startDate.value = monthStart(date); endDate.value = monthEnd(date); focusedDate.value = ''; loadData(); }

async function loadData() {
  if (!startDate.value || !endDate.value || startDate.value > endDate.value) { errorMessage.value = '日期範圍無效，請確認起日與迄日。'; return; }
  const serial = ++requestSerial;
  loading.value = true; errorMessage.value = '';
  try {
    const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const token = session?.access_token || '';
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const params = new URLSearchParams({ start: startDate.value, end: endDate.value });
    if (selectedBranchId.value !== 'all') params.set('branch_id', selectedBranchId.value);
    const response = await fetch(`${baseUrl}/v1/finance/subject-units/timeline?${params}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
    if (!response.ok) throw new Error(response.status === 403 ? '您沒有查看此分校資料的權限。' : '請稍後再試。');
    const payload = await response.json();
    if (serial !== requestSerial) return;
    entries.value = Array.isArray(payload.entries) ? payload.entries : [];
    days.value = Array.isArray(payload.days) ? payload.days : [];
    totals.value = { ...totals.value, ...(payload.totals || {}) };
  } catch (error) {
    if (serial !== requestSerial) return;
    entries.value = []; days.value = []; errorMessage.value = error?.message || '網路連線逾時，請確認網路後重試。';
  } finally { if (serial === requestSerial) loading.value = false; }
}

watch(() => props.branchId, (value) => { selectedBranchId.value = value ? String(value) : 'all'; loadData(); });
onMounted(async () => { await loadBranches(); loadData(); });
</script>

<style scoped>
.subject-units-page { color: var(--ds-ink); }
.period-actions, .branch-field { display: flex; align-items: center; gap: 8px; }
.date-field, .branch-field { display: flex; flex-direction: column; gap: 4px; }
.date-field span, .branch-field span, .filter-field span { color: var(--ds-ink-mute); font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
input, select { min-height: 36px; border: 1px solid var(--ds-hairline-input); border-radius: 8px; background: var(--ds-canvas); color: var(--ds-ink); padding: 7px 10px; font: inherit; }
input:focus-visible, select:focus-visible, button:focus-visible { outline: 3px solid var(--ds-primary-wash); outline-offset: 1px; border-color: var(--ds-primary); }
.scope-strip { display: flex; align-items: center; gap: 8px; margin: -4px 0 16px; color: var(--ds-ink-mute); font-size: 13px; }
.scope-icon { color: var(--ds-success); font-size: 18px; }.scope-separator { color: var(--ds-hairline-input); }.scope-period { font-variant-numeric: tabular-nums; }
.state-card, .card { border: 1px solid var(--ds-hairline); border-radius: 12px; background: var(--ds-canvas); box-shadow: 0 1px 3px rgba(0,55,112,.08); }
.state-card { display: flex; align-items: center; gap: 12px; min-height: 150px; justify-content: center; color: var(--ds-ink-mute); }.state-card--error { justify-content: flex-start; padding: 24px; color: var(--ds-danger); }.state-card--error p { margin: 6px 0 12px; color: var(--ds-ink-mute); }
.state-icon { font-size: 24px; }.loading-icon { animation: spin 1.1s linear infinite; color: var(--ds-primary); }
.summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.daily-trend-card, .detail-card, .disclosure-card { padding: 20px; margin-bottom: 16px; }.section-heading, .detail-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }.eyebrow { margin: 0 0 4px; color: var(--ds-primary-deep); font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; } h3 { margin: 0; color: var(--ds-ink); font-size: 18px; }.section-hint, .row-count { color: var(--ds-ink-mute); font-size: 12px; }
.trend-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(44px, 1fr)); gap: 6px; align-items: end; min-height: 144px; }.trend-day { display: flex; min-width: 0; flex-direction: column; align-items: center; gap: 5px; border: 0; border-radius: 8px; background: transparent; color: var(--ds-ink-mute); padding: 4px 2px; cursor: pointer; }.trend-day:hover, .trend-day--selected { background: var(--ds-primary-wash); color: var(--ds-ink); }.trend-value, .trend-date { font-size: 10px; font-variant-numeric: tabular-nums; white-space: nowrap; }.trend-track { display: flex; width: 100%; height: 88px; align-items: flex-end; justify-content: center; border-bottom: 1px solid var(--ds-hairline); }.trend-bar { width: min(22px, 70%); min-height: 5px; border-radius: 6px 6px 2px 2px; background: var(--ds-primary); transition: height .22s ease, background .22s ease; }.trend-day--selected .trend-bar { background: var(--ds-cta); }
.inline-empty, .table-empty { display: flex; align-items: center; justify-content: center; gap: 8px; min-height: 100px; color: var(--ds-ink-mute); font-size: 13px; }
.table-toolbar { display: flex; align-items: end; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }.search-field { display: flex; min-width: min(100%, 320px); flex: 1 1 260px; align-items: center; gap: 8px; border: 1px solid var(--ds-hairline-input); border-radius: 8px; background: var(--ds-canvas); padding: 0 10px; }.search-field input { width: 100%; border: 0; outline: 0; padding-left: 0; }.search-field .material-symbols-outlined { color: var(--ds-ink-mute); font-size: 19px; }.filter-field { display: flex; flex-direction: column; gap: 4px; }.clear-focus { margin-left: auto; }
.table-wrap { overflow-x: auto; border: 1px solid var(--ds-hairline); border-radius: 8px; }.detail-table { width: 100%; min-width: 820px; border-collapse: collapse; font-size: 13px; }.detail-table th, .detail-table td { border-bottom: 1px solid var(--ds-hairline); padding: 11px 12px; text-align: left; white-space: nowrap; }.detail-table thead th { position: sticky; top: 0; z-index: 1; background: var(--ds-canvas-soft); color: var(--ds-ink-mute); font-size: 11px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }.detail-table tbody tr.detail-row:hover { background: var(--ds-canvas-soft); }.detail-table tbody tr:last-child td { border-bottom: 0; }.number-cell { text-align: right !important; font-variant-numeric: tabular-nums; }.highlight-cell { color: var(--ds-cta); }.day-summary-row { background: var(--ds-primary-wash); }.day-summary-row th { color: var(--ds-ink); font-weight: 700; }.day-summary-row th span { margin-left: 8px; color: var(--ds-ink-mute); font-size: 11px; font-weight: 500; }.day-summary-row td { border-bottom-color: var(--ds-hairline-input); font-weight: 700; }.date-cell { color: var(--ds-ink-mute); font-variant-numeric: tabular-nums; }.teacher-cell { min-width: 100px; }.subject-name { font-weight: 600; }.campus-pill { display: inline-flex; border: 1px solid var(--ds-hairline-input); border-radius: 999px; padding: 3px 8px; color: var(--ds-ink-secondary); background: var(--ds-canvas); font-size: 12px; }
.disclosure-card { padding: 0; }.calc-guide-header, .level-breakdown-header { padding: 12px 16px; }.calc-guide-header h3, .level-breakdown-header h3 { margin: 0; }.calc-guide-toggle, .level-breakdown-toggle { display: inline-flex; align-items: center; gap: 8px; min-height: 34px; }.disclosure-body { border-top: 1px solid var(--ds-hairline); padding: 16px; color: var(--ds-ink-secondary); font-size: 13px; line-height: 1.7; }.disclosure-body p { margin: 0 0 8px; }.disclosure-body p:last-child { margin-bottom: 0; }.formula-row { display: flex; gap: 16px; padding: 6px 0; border-top: 1px solid var(--ds-hairline); }.formula-row span { min-width: 100px; color: var(--ds-ink-mute); }.compact-copy { color: var(--ds-ink-mute); }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; clip-path: inset(50%); } @keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 900px) { .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }.period-actions { flex-wrap: wrap; }.branch-field { width: 100%; }.branch-field select { width: 100%; } }
@media (max-width: 560px) { .summary-grid { grid-template-columns: 1fr 1fr; gap: 8px; }.daily-trend-card, .detail-card, .disclosure-card { padding: 14px; }.section-heading, .detail-heading { flex-direction: column; gap: 6px; }.date-field input { max-width: 142px; }.date-separator { align-self: end; padding-bottom: 9px; }.clear-focus { margin-left: 0; }.formula-row { display: block; }.formula-row span { display: block; margin-bottom: 2px; } }
</style>
