<template>
  <div>
    <div v-if="branchId == null" class="card no-branch-card">
      <h2>尚無分校資料</h2>
      <p>系統尚未載入您的分校權限，或您尚未被指派到任何分校。</p>
      <p class="hint">請聯繫系統管理員設定您的分校權限後重新整理頁面。</p>
    </div>

    <template v-else>
      <div class="dash">
        <!-- ===== Header ===== -->
        <div class="dash-header">
          <div class="dash-title-block">
            <p class="dash-kicker">Campus Operations Command</p>
            <h2 class="dash-title">{{ branchName }}</h2>
            <p class="dash-subtitle">即時掌握今日課務、繳費風險、評量審核與分校營運節奏</p>
          </div>
          <div class="dash-date-panel">
            <span class="dash-date-label">Today</span>
            <span class="dash-date">{{ todayDisplay }}</span>
          </div>
        </div>

        <!-- ===== Layer 1: Action Lane ===== -->
        <div class="section-label-row">
          <span class="section-label">每日待辦</span>
          <span class="section-sublabel">今日需要處理的事項</span>
        </div>
        <section class="action-lane" data-guide="director-summary">
          <div v-if="pendingAttendanceCount > 0"
               class="ac ac--attend" tabindex="0"
               @click="goToAttendance" @keydown.enter="goToAttendance">
            <span class="material-symbols-outlined ac__icon">schedule</span>
            <div class="ac__body">
              <span class="ac__count">{{ pendingAttendanceCount }}</span>
              <span class="ac__label">堂待到班</span>
            </div>
            <button class="ac__cta" @click.stop="goToAttendance">前往點名</button>
          </div>

          <div v-if="lowBalanceStudents.length > 0"
               class="ac ac--pay" tabindex="0"
               @click="goToTuitionCollect" @keydown.enter="goToTuitionCollect">
            <span class="material-symbols-outlined ac__icon">payments</span>
            <div class="ac__body">
              <span class="ac__count">{{ lowBalanceStudents.length }}</span>
              <span class="ac__label">{{ paymentActionLaneLabel }}</span>
            </div>
            <button class="ac__cta" @click.stop="goToTuitionCollect">前往催繳</button>
          </div>

          <div v-if="pendingMakeupCount > 0"
               class="ac ac--makeup" tabindex="0"
               @click="goToAttendance" @keydown.enter="goToAttendance">
            <span class="material-symbols-outlined ac__icon">edit_note</span>
            <div class="ac__body">
              <span class="ac__count">{{ pendingMakeupCount }}</span>
              <span class="ac__label">堂待補點名</span>
            </div>
            <button class="ac__cta" @click.stop="goToAttendance">補點名</button>
          </div>

          <div v-if="pendingEvaluations.length > 0"
               class="ac ac--eval" tabindex="0"
               @click="scrollTo('evals')" @keydown.enter="scrollTo('evals')">
            <span class="material-symbols-outlined ac__icon">rate_review</span>
            <div class="ac__body">
              <span class="ac__count">{{ pendingEvaluations.length }}</span>
              <span class="ac__label">筆待審核</span>
            </div>
            <button class="ac__cta" @click.stop="scrollTo('evals')">去審核</button>
          </div>

          <div v-if="unreadFeedbackCount > 0"
               class="ac ac--feedback" tabindex="0"
               @click="emit('navigate', { target: 'learning' })"
               @keydown.enter="emit('navigate', { target: 'learning' })">
            <span class="material-symbols-outlined ac__icon">mark_unread_chat_alt</span>
            <div class="ac__body">
              <span class="ac__count">{{ unreadFeedbackCount }}</span>
              <span class="ac__label">筆家長回饋待看</span>
            </div>
            <button class="ac__cta" @click.stop="emit('navigate', { target: 'learning' })">去查看</button>
          </div>

          <div class="ac ac--import" tabindex="0"
               @click="triggerImport" @keydown.enter="triggerImport">
            <span class="material-symbols-outlined ac__icon">upload_file</span>
            <div class="ac__body">
              <span class="ac__label">匯入學生</span>
              <span class="ac__sub">CSV / Excel</span>
              <button class="ac__format-link" type="button" @click.stop="showImportFormatModal = true">查看範例格式</button>
            </div>
            <button class="ac__cta" :disabled="importState === 'uploading'" @click.stop="triggerImport">
              {{ importState === 'uploading' ? '上傳中...' : '選擇檔案' }}
            </button>
            <input ref="importFileInput" type="file" accept=".csv,.xlsx,.txt"
                   style="display:none" @change="handleImportFile" />
          </div>

          <div v-if="allClearActionLane" class="ac ac--clear">
            <span class="material-symbols-outlined ac__icon">check_circle</span>
            <div class="ac__body">
              <span class="ac__label">今日待辦已處理完畢</span>
            </div>
          </div>
        </section>

        <!-- ===== Import Result Banner ===== -->
        <div v-if="importState === 'done' || importState === 'error'"
             class="import-banner" :class="{ 'import-banner--err': importState === 'error' }">
          <div class="import-banner__head">
            <strong>{{ importState === 'error' ? '匯入失敗' : '匯入完成' }}</strong>
            <button class="import-banner__x" @click="dismissImport">&times;</button>
          </div>
          <div v-if="importState === 'done'" class="import-banner__stats">
            <span class="ib-chip ib-chip--ok">新增 {{ importResult.created }}</span>
            <span class="ib-chip ib-chip--info">更新 {{ importResult.updated }}</span>
            <span class="ib-chip">略過 {{ importResult.skipped }}</span>
            <span v-if="importResult.low_confidence" class="ib-chip ib-chip--warn">
              低信心 {{ importResult.low_confidence }}
            </span>
            <span v-if="importResult.errors.length" class="ib-chip ib-chip--err">
              錯誤 {{ importResult.errors.length }}
            </span>
          </div>
          <div v-if="importResult.errors.length" class="import-banner__list">
            <div v-for="(e, i) in importResult.errors.slice(0, 5)" :key="i">{{ e }}</div>
          </div>
          <div v-if="importResult.warnings.length" class="import-banner__list import-banner__list--warn">
            <div v-for="(w, i) in importResult.warnings.slice(0, 3)" :key="'w'+i">{{ w }}</div>
          </div>
          <div v-if="importState === 'error'" class="import-banner__list import-banner__list--err">
            {{ importErrorMsg }}
          </div>
          <div class="import-banner__foot">
            <button class="btn-o btn-xs" @click="triggerImport">重新匯入</button>
            <button class="btn-o btn-xs" @click="dismissImport">關閉</button>
          </div>
        </div>

        <!-- ===== Layer 2: Progress Board ===== -->
        <section class="progress-board">
          <div class="pb">
            <span class="pb__label">今日到班</span>
            <span class="pb__val"><strong>{{ attendedCount }}</strong><small> / {{ todaySchedules.length }}</small></span>
            <div class="pb__bar"><div class="pb__fill" :style="{ width: attendancePct + '%' }"></div></div>
          </div>
          <div class="pb">
            <span class="pb__label">待審評量</span>
            <span class="pb__val"><strong>{{ pendingEvaluations.length }}</strong> <small>筆</small></span>
          </div>
          <div class="pb">
            <span class="pb__label">未讀通知</span>
            <span class="pb__val"><strong>{{ unreadNotificationCount }}</strong></span>
          </div>
          <div class="pb">
            <span class="pb__label">未讀家長回饋</span>
            <span class="pb__val"><strong>{{ unreadFeedbackCount }}</strong></span>
          </div>
          <div class="pb">
            <span class="pb__label">本月科目數</span>
            <span class="pb__val"><strong>{{ monthlySubjectCountWith }}</strong></span>
          </div>
        </section>

        <!-- ===== Work Area (detail panels) ===== -->
        <div class="work-grid">
          <div class="work-col">
            <!-- Today Schedule -->
            <section class="wp" id="schedule-sec">
              <header class="wp__head">
                <span class="material-symbols-outlined wp__hi">calendar_today</span>
                <h3>今日課表</h3>
                <span v-if="todaySchedules.length" class="wp__badge">{{ todaySchedules.length }}</span>
              </header>
              <div v-if="!todaySchedules.length" class="wp__empty">今日無課程</div>
              <div v-for="s in todaySchedules" :key="s.id" class="sched-row">
                <span class="sched-row__time">{{ formatTime(s.start_time) }}</span>
                <span class="sched-row__name">{{ s.student_name || '—' }}</span>
                <span class="sched-row__subj">{{ s.subject || s.subject_name || '' }}</span>
                <span class="sched-row__tchr">{{ s.teacher_name || '' }}</span>
                <span :class="['sched-row__st', 'st--' + s.status]">{{ formatScheduleStatus(s.status) }}</span>
              </div>
              <footer v-if="pendingAttendanceCount > 0" class="wp__foot">
                <button class="btn-p btn-sm" @click="goToAttendance">前往出缺勤處理</button>
              </footer>
            </section>

            <!-- Schedule Discrepancy Reports -->
            <section
              class="wp sd-card"
              :class="{ 'sd-card--alert': sdSummary.pending > 0 }"
              @click="goToScheduleDiscrepancy"
              @keydown.enter="goToScheduleDiscrepancy"
              tabindex="0"
              role="button"
            >
              <header class="wp__head">
                <span class="material-symbols-outlined wp__hi">flag</span>
                <h3>課表回報</h3>
                <span v-if="sdSummary.pending > 0" class="wp__badge wp__badge--warn">{{ sdSummary.pending }}</span>
              </header>
              <div v-if="sdLoading" class="sd-skel-wrap" aria-hidden="true">
                <div class="sd-skel-num"></div>
                <div class="sd-skel-line"></div>
              </div>
              <template v-else>
                <div v-if="sdSummary.pending > 0" class="sd-dash-body">
                  <div class="sd-dash-num">{{ sdSummary.pending }}</div>
                  <div class="sd-dash-text">
                    <div class="sd-dash-title">筆待處理課表回報</div>
                    <div class="sd-dash-sub">
                      處理中 {{ sdSummary.acknowledged }} · 已解決 {{ sdSummary.resolved }}
                    </div>
                  </div>
                  <button class="btn-o btn-sm sd-dash-cta" type="button" @click.stop="goToScheduleDiscrepancy">前往處理</button>
                </div>
                <div v-else class="sd-dash-empty">
                  <span class="material-symbols-outlined" aria-hidden="true">task_alt</span>
                  <div>
                    <div class="sd-dash-title">目前課表無回報</div>
                    <div class="sd-dash-sub">老師沒有回報任何出入，一切正常</div>
                  </div>
                </div>
              </template>
            </section>

            <!-- Payment Alerts -->
            <section class="wp wp--warn" id="payments-sec" data-guide="director-alerts">
              <header class="wp__head">
                <span class="material-symbols-outlined wp__hi">warning</span>
                <h3>繳費／續課提醒</h3>
                <span v-if="lowBalanceStudents.length" class="wp__badge wp__badge--danger">{{ lowBalanceStudents.length }}</span>
              </header>
              <p class="wp__hint">堂數制：已標記繳費者，若剩 0～2 堂仍會列出（方便聯繫加購）；未繳費者亦會列出。</p>
              <div v-if="!lowBalanceStudents.length" class="wp__empty">目前無待繳費、月結將届或低堂數需續課之課程</div>
              <div v-for="s in displayPaymentAlerts" :key="s.id" class="pay-row">
                <div class="pay-row__info">
                  <span class="pay-row__name">{{ s.name }}</span>
                  <span :class="paymentAlertBadgeClass(s)">{{ paymentAlertBadgeText(s) }}</span>
                </div>
                <button class="btn-o btn-xs" @click="copyPaymentMessage(s)">複製通知</button>
              </div>
              <footer v-if="lowBalanceStudents.length > paymentAlertLimit" class="wp__foot">
                <button class="btn-o btn-xs" @click="showAllPayments = !showAllPayments">
                  {{ showAllPayments ? '收合' : `顯示全部 (${lowBalanceStudents.length})` }}
                </button>
              </footer>
            </section>
          </div>

          <div class="work-col">
            <!-- PRD 9c058f19：近 7 天代課記錄 -->
            <RecentSubstitutesCard :branch-id="branchId" :fetch-recent="fetchRecentSubstitutes" />

            <!-- Pending Evaluations -->
            <section class="wp" id="evals-sec" data-guide="director-pending-evals">
              <header class="wp__head">
                <span class="material-symbols-outlined wp__hi">assignment</span>
                <h3>待審核評量</h3>
                <span v-if="pendingEvaluations.length" class="wp__badge">{{ pendingEvaluations.length }}</span>
              </header>
              <p class="wp__hint">核准後老師科目數自動累計</p>
              <div v-if="!pendingEvaluations.length" class="wp__empty">無待審核評量</div>
              <div v-for="ev in pendingEvaluations" :key="ev.id" class="eval-card">
                <div class="eval-card__top">
                  <strong>{{ ev.student_name }}</strong>
                  <span class="eval-card__tag">{{ ev.student_class_label || ev.Subject }}</span>
                  <span class="eval-card__tchr">{{ ev.teacher_name }}</span>
                </div>
                <div class="eval-card__mid">
                  <span>{{ ev.SessionDate }}</span>
                  <span v-if="ev.Progress"> &middot; {{ ev.Progress }}</span>
                </div>
                <div v-if="ev.Comment" class="eval-card__comment">{{ ev.Comment }}</div>
                <div class="eval-card__acts">
                  <button class="btn-o btn-sm" @click="emit('navigate', { target: 'learning', recordId: ev.id })">檢視</button>
                  <button class="btn-p btn-sm" @click="approveEvaluation(ev)">核准</button>
                  <button class="btn-d btn-sm" @click="rejectEvaluation(ev)">退回</button>
                </div>
              </div>
              <footer v-if="pendingEvaluations.length" class="wp__foot">
                <button class="btn-o btn-sm" @click="emit('navigate', { target: 'learning' })">前往評量頁面</button>
              </footer>
            </section>

            <!-- Notifications -->
            <section class="wp">
              <header class="wp__head">
                <span class="material-symbols-outlined wp__hi">notifications</span>
                <h3>通知摘要</h3>
                <span v-if="unreadNotificationCount" class="wp__badge">{{ unreadNotificationCount }}</span>
              </header>
              <div v-if="!notificationSummary.length" class="wp__empty">目前無未讀通知</div>
              <div v-for="n in notificationSummary" :key="n.id" class="notif-row">
                <span>{{ n.title }}</span>
                <span class="badge-blue">{{ n.typeLabel }}</span>
              </div>
              <footer class="wp__foot">
                <button class="btn-o btn-sm" @click="goToNotifications">前往通知中心</button>
              </footer>
            </section>
          </div>
        </div>

        <!-- ===== Layer 3: KPI Panel (collapsed by default) ===== -->
        <details class="kpi" data-guide="director-teacher-stats">
          <summary class="kpi__sum">
            <span class="material-symbols-outlined kpi__sum-icon">analytics</span>
            <span>經營指標 — 本月科目數統計</span>
            <span class="kpi__sum-hint">{{ monthlySubjectCountWith }} 科目數</span>
            <span class="kpi__chev">&#x25BE;</span>
          </summary>
          <div class="kpi__body">
            <div class="kpi-totals">
              <div class="kpi-t">
                <div class="kpi-t__label">含輔導科目數</div>
                <div class="kpi-t__val">{{ monthlySubjectCountWith }}</div>
              </div>
              <div class="kpi-t">
                <div class="kpi-t__label">不含輔導</div>
                <div class="kpi-t__val">{{ monthlySubjectCountWithout }}</div>
              </div>
            </div>

            <table v-if="teacherStats.length" class="kpi-table">
              <thead>
                <tr><th>老師</th><th>含輔導</th><th>不含輔導</th><th>時數</th></tr>
              </thead>
              <tbody>
                <tr v-for="t in teacherStats" :key="t.id">
                  <td>{{ t.name }}</td>
                  <td><strong>{{ t.subjectCountWith }}</strong></td>
                  <td>{{ t.subjectCountWithout }}</td>
                  <td>{{ t.totalHours }}h</td>
                </tr>
              </tbody>
            </table>

            <div v-if="levelBreakdownTotals.length" class="level-chips">
              <span v-for="lb in levelBreakdownTotals" :key="'lv-'+lb.level" class="level-chip">
                {{ lb.levelLabel }}：{{ lb.totalHours }}h / {{ lb.unitsWith }} 科目數
              </span>
            </div>

            <div class="kpi__link">
              <button class="btn-o btn-sm" @click="emit('navigate', { target: 'subject-units' })">
                前往科目數統計頁面
              </button>
            </div>
          </div>
        </details>
      </div>
    </template>
  </div>

  <!-- ===== 匯入格式說明 Modal ===== -->
  <teleport to="body">
    <div v-if="showImportFormatModal" class="import-format-overlay" @click.self="showImportFormatModal = false">
      <div class="import-format-modal">
        <div class="import-format-header">
          <strong>匯入格式說明</strong>
          <button class="import-format-close" @click="showImportFormatModal = false">&times;</button>
        </div>
        <p class="import-format-desc">第一列為標題列，欄位名稱（中英文皆可）：</p>
        <table class="import-format-table">
          <thead>
            <tr><th>欄位</th><th>接受名稱</th><th>必填</th></tr>
          </thead>
          <tbody>
            <tr><td>學生姓名</td><td>學生 / 姓名 / name / student</td><td>✓ 必填</td></tr>
            <tr><td>年級</td><td>年級學校 / 年級 / grade</td><td>選填</td></tr>
            <tr><td>學校</td><td>學校 / school</td><td>選填</td></tr>
            <tr><td>家長手機</td><td>手機 / 電話 / phone / 家長手機</td><td>選填</td></tr>
          </tbody>
        </table>
        <p class="import-format-note">範例：</p>
        <pre class="import-format-example">姓名,年級,學校,手機
王小明,國中一年級,中正國中,0912345678
李小花,高中二年級,建國高中,
陳大雄,小學四年級,,0987654321</pre>
        <p class="import-format-note">※ 手機可留空，但填寫可提升重複比對準確度。</p>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { supabase } from '../supabase';
import { getBranchName } from '../lib/useBranches';
import { getSubjectLabel as getSubjectText } from '../lib/constants';
import { fetchDiscrepancySummary } from '../lib/scheduleDiscrepanciesApi';
import RecentSubstitutesCard from '../components/substitute/RecentSubstitutesCard.vue';
import { recentSubstitutes as fetchRecentSubstitutes } from '../lib/substituteApi.js';

const props = defineProps({
  branchId: [String, Number],
  unreadFeedbackCount: { type: Number, default: 0 },
});
const emit = defineEmits(['navigate']);

const todaySchedules = ref([]);
const pendingEvaluations = ref([]);
const teacherStats = ref([]);
const subjectTotals = ref({ subjectCountWith: 0, subjectCountWithout: 0 });
const levelBreakdownTotals = ref([]);
const lowBalanceStudents = ref([]);
const unreadNotificationCount = ref(0);
const notificationSummary = ref([]);
const showAllPayments = ref(false);
const paymentAlertLimit = 5;
const pendingMakeupCount = ref(0);

// Schedule-discrepancy summary card
const sdSummary = ref({ pending: 0, acknowledged: 0, resolved: 0, withdrawn: 0 });
const sdLoading = ref(true);

async function loadScheduleDiscrepancySummary() {
  if (props.branchId == null) return;
  sdLoading.value = true;
  try {
    sdSummary.value = await fetchDiscrepancySummary(props.branchId);
  } catch (e) {
    console.warn('loadScheduleDiscrepancySummary', e);
    sdSummary.value = { pending: 0, acknowledged: 0, resolved: 0, withdrawn: 0 };
  } finally {
    sdLoading.value = false;
  }
}

function goToScheduleDiscrepancy() {
  emit('navigate', { target: 'schedule-discrepancy' });
}

const importFileInput = ref(null);
const importState = ref('idle');
const importResult = ref({ created: 0, updated: 0, skipped: 0, errors: [], warnings: [], low_confidence: 0 });
const importErrorMsg = ref('');
const showImportFormatModal = ref(false);

const branchName = computed(() => getBranchName(props.branchId));

const todayDisplay = computed(() => {
  const d = new Date();
  const days = ['日', '一', '二', '三', '四', '五', '六'];
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}/${m}/${day} (${days[d.getDay()]})`;
});

const pendingAttendanceCount = computed(() =>
  todaySchedules.value.filter(s => s.status === 'scheduled').length
);

const attendedCount = computed(() =>
  todaySchedules.value.filter(s => s.status === 'attended').length
);

const attendancePct = computed(() => {
  const total = todaySchedules.value.length;
  return total ? Math.round((attendedCount.value / total) * 100) : 0;
});

const monthlySubjectCountWith = computed(() =>
  Number(subjectTotals.value.subjectCountWith || 0).toFixed(2)
);
const monthlySubjectCountWithout = computed(() =>
  Number(subjectTotals.value.subjectCountWithout || 0).toFixed(2)
);

const allClearActionLane = computed(() =>
  pendingAttendanceCount.value === 0
  && lowBalanceStudents.value.length === 0
  && pendingEvaluations.value.length === 0
  && pendingMakeupCount.value === 0
  && props.unreadFeedbackCount === 0
);

const displayPaymentAlerts = computed(() =>
  showAllPayments.value
    ? lowBalanceStudents.value
    : lowBalanceStudents.value.slice(0, paymentAlertLimit)
);

/** 上方快捷列用：區分「真的未繳」與「已繳但低堂數／月結」避免誤以為催繳失敗 */
const paymentActionLaneLabel = computed(() => {
  const rows = lowBalanceStudents.value;
  if (!rows.length) return '';
  const hasUnpaid = rows.some(s => s.alert_type === 'unpaid');
  const allLow = rows.every(s => s.alert_type === 'low_sessions');
  const allMonthly = rows.every(s => s.alert_type === 'monthly_due_soon');
  if (hasUnpaid) return '筆含未繳費';
  if (allLow) return '筆低堂數／續課';
  if (allMonthly) return '筆月結將届';
  return '筆待留意';
});

const paymentAlertBadgeClass = (s) => {
  if (s.alert_type === 'monthly_due_soon') return 'badge-amber';
  if (s.alert_type === 'low_sessions') return 'badge-orange';
  return 'badge-red';
};

const paymentAlertBadgeText = (s) => {
  if (s.alert_type === 'monthly_due_soon') {
    const d = Number(s.days_until_settlement);
    if (Number.isFinite(d) && d < 0) return `月結 · 已逾期 ${Math.abs(d)} 天`;
    if (d === 0) return '月結 · 今日繳費日';
    return `月結 · 剩 ${d} 天`;
  }
  if (s.alert_type === 'low_sessions') {
    const n = Number(s.remaining_lessons ?? 0);
    if (!Number.isFinite(n) || n <= 0) return '已繳 · 堂數已用完';
    return `已繳 · 剩 ${n} 堂`;
  }
  return `未繳 · ${s.remaining_lessons} 堂`;
};

const formatTime = (timeStr) => timeStr || '--:--';

const formatScheduleStatus = (status) => {
  const map = {
    scheduled: '待到班', attended: '已到班', completed: '已下課',
    cancelled: '已取消', leave: '請假', leave_adjusted: '請假(順延)',
    excused: '請假', absent: '缺席', late: '遲到',
  };
  return map[String(status || '').toLowerCase()] || String(status || '—');
};

const localTodayYmd = () => {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

const getToken = () => {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  return session?.access_token || '';
};
const getBaseUrl = () => import.meta.env.VITE_API_BASE || '/api';

const loadData = async () => {
  if (!props.branchId) return;

  const token = getToken();
  const baseUrl = getBaseUrl();

  try {
    const alertsParams = new URLSearchParams({ branch_id: String(props.branchId) });
    const alertsResp = await fetch(`${baseUrl}/v1/alerts/tuition?${alertsParams}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (alertsResp.ok) {
      const alertsJson = await alertsResp.json();
      const alertList = Array.isArray(alertsJson) ? alertsJson : (alertsJson.low_balance || []);
      const currentBranchId = Number(props.branchId) || 0;
      lowBalanceStudents.value = alertList
        .filter(c => {
          if (!c.student_name) return false;
          const campusId = Number(c.campus_id ?? c.CampusID ?? 0);
          return !currentBranchId || !campusId || campusId === currentBranchId;
        })
        .map(c => ({
          id: c.id || c.class_id,
          student_id: c.student_id || null,
          raw_name: c.student_name,
          name: `${c.student_name} — ${c.subject || getSubjectLabel(c.SubjectID) || ''}`,
          remaining_lessons: c.remaining_sessions ?? c.RemainingSessions ?? 0,
          alert_type: c.alert_type || 'unpaid',
          days_until_settlement: c.days_until_settlement ?? null,
          due_date: c.due_date ?? null,
          settlement_day: c.settlement_day ?? null,
          schedule_mode: c.schedule_mode ?? 'count',
        }));
    }
  } catch (err) {
    console.error('Failed to load alerts:', err);
  }

  await loadNotificationSummary(token, baseUrl);

  const today = localTodayYmd();
  try {
    const params = new URLSearchParams({
      branch_id: String(props.branchId), start: today, end: today, per_page: '500',
    });
    const res = await fetch(`${baseUrl}/v1/class-sessions?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (res.ok) {
      const json = await res.json().catch(() => ({}));
      const rows = Array.isArray(json?.data) ? json.data : [];
      todaySchedules.value = rows
        .map(row => ({
          id: Number(row?.id || 0),
          class_session_id: Number(row?.id || 0),
          student_class_id: Number(row?.student_class_id || 0),
          student_name: row?.student_name || '',
          teacher_name: row?.teacher_name || '',
          start_time: String(row?.start_time || '').slice(0, 5),
          end_time: String(row?.end_time || '').slice(0, 5),
          status: String(row?.status || '').toLowerCase(),
          subject: row?.subject || '',
          subject_name: row?.subject_name || '',
        }))
        .filter(s => s.id > 0 && !['cancelled'].includes(s.status))
        .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));
    } else {
      todaySchedules.value = [];
    }
  } catch (_) {
    const todayDow = new Date().getDay() || 7;
    const { data: dateSchedules } = await supabase
      .from('schedules').select('*')
      .eq('branch_id', props.branchId).eq('schedule_date', today);
    const { data: dowSchedules } = await supabase
      .from('schedules').select('*')
      .eq('branch_id', props.branchId).eq('day_of_week', todayDow)
      .is('schedule_date', null);
    const allToday = [...(dateSchedules || []), ...(dowSchedules || [])];
    const seenIds = new Set();
    todaySchedules.value = allToday
      .filter(s => {
        if (seenIds.has(s.id)) return false;
        seenIds.add(s.id);
        return !['cancelled', 'leave'].includes(String(s.status || '').toLowerCase());
      })
      .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));
  }

  try {
    const pendingRes = await fetch(
      `${baseUrl}/v1/learning-records?branch_id=${props.branchId}&status=pending,changes_requested&per_page=100&sort=session_date`,
      { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }
    );
    if (pendingRes.ok) {
      const pendingJson = await pendingRes.json();
      pendingEvaluations.value = pendingJson.data || [];
    }
  } catch (err) {
    console.error('Failed to load pending evaluations:', err);
  }

  try {
    const makeupParams = new URLSearchParams({ branch_id: String(props.branchId), per_page: '1' });
    const makeupRes = await fetch(`${baseUrl}/v1/attendance/ended-sessions?${makeupParams}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (makeupRes.ok) {
      const makeupJson = await makeupRes.json();
      pendingMakeupCount.value = Number(makeupJson?.meta?.total ?? makeupJson?.total ?? 0);
    }
  } catch (err) {
    console.error('Failed to load makeup count:', err);
  }

  calculateTeacherStats();
};

const notificationTypeLabel = (type) => {
  const map = { tuition: '繳費', learning_review: '評量', pending_swipe: '刷卡', low_sessions: '堂數不足' };
  return map[type] || '通知';
};

const loadNotificationSummary = async (token, baseUrl) => {
  try {
    const params = new URLSearchParams({
      branch_id: String(props.branchId), read: 'unread', per_page: '3',
    });
    const res = await fetch(`${baseUrl}/v1/notifications?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (!res.ok) { unreadNotificationCount.value = 0; notificationSummary.value = []; return; }
    const json = await res.json();
    unreadNotificationCount.value = Number(json.unread_count || 0);
    notificationSummary.value = (json.data || []).map(item => ({
      id: item.id, title: item.Title || '通知', typeLabel: notificationTypeLabel(item.Type),
    }));
  } catch (err) {
    console.error('Failed to load notification summary:', err);
    unreadNotificationCount.value = 0; notificationSummary.value = [];
  }
};

const goToNotifications = () => emit('navigate', { target: 'notifications' });
const goToAttendance = () => emit('navigate', { target: 'attendance' });
const goToTuitionCollect = () => emit('navigate', { target: 'tuition-collect' });

const getSubjectLabel = (val) => {
  const map = {
    '1': '國文', '2': '英文', '3': '數學', '4': '理化', '5': '社會',
    Chinese: '國文', English: '英文', Math: '數學',
    Science: '理化', Physics: '物理', Chemistry: '化學', Biology: '生物', Social: '社會',
  };
  return map[val] || getSubjectText(val);
};

const calculateTeacherStats = async () => {
  try {
    const now = new Date();
    const startDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;
    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
    const endDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
    const token = getToken();
    const baseUrl = getBaseUrl();
    const params = new URLSearchParams({
      start: startDate, end: endDate, branch_id: String(props.branchId), include_level: '1',
    });
    const res = await fetch(`${baseUrl}/v1/finance/subject-units?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (!res.ok) {
      teacherStats.value = [];
      subjectTotals.value = { subjectCountWith: 0, subjectCountWithout: 0 };
      return;
    }
    const json = await res.json();
    subjectTotals.value = {
      subjectCountWith: Number(json?.totals?.subject_count_with || 0),
      subjectCountWithout: Number(json?.totals?.subject_count_without || 0),
    };
    teacherStats.value = (json.teachers || []).map(t => ({
      id: t.teacher_id, name: t.teacher_name,
      subjectCountWith: Number(t.subject_count_with || 0).toFixed(2),
      subjectCountWithout: Number(t.subject_count_without || 0).toFixed(2),
      totalHours: Number(t.total_hours || 0),
    })).sort((a, b) => Number(b.subjectCountWith) - Number(a.subjectCountWith));
    levelBreakdownTotals.value = (json.level_breakdown_totals || []).map(lb => ({
      level: lb.level, levelLabel: lb.level_label,
      totalHours: lb.total_hours, unitsWith: lb.subject_count_with,
    }));
  } catch (e) {
    console.error('Failed to load teacher stats:', e);
    teacherStats.value = [];
    subjectTotals.value = { subjectCountWith: 0, subjectCountWithout: 0 };
    levelBreakdownTotals.value = [];
  }
};

const approveEvaluation = async (evalItem) => {
  if (!confirm('確認核准此評量？')) return;
  const token = getToken();
  const baseUrl = getBaseUrl();
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  try {
    const res = await fetch(`${baseUrl}/v1/learning-records/${evalItem.id}/approve`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ DirectorID: session?.user?.id }),
    });
    if (res.ok) {
      pendingEvaluations.value = pendingEvaluations.value.filter(e => e.id !== evalItem.id);
      loadData();
    } else { const err = await res.json(); alert('核准失敗: ' + (err.message || '')); }
  } catch { alert('核准失敗'); }
};

const rejectEvaluation = async (evalItem) => {
  const note = prompt('退回原因：');
  if (!note) return;
  const token = getToken();
  const baseUrl = getBaseUrl();
  try {
    await fetch(`${baseUrl}/v1/learning-records/${evalItem.id}/reject`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ ReviewNote: note }),
    });
    loadData();
  } catch (e) { console.error(e); }
};

const copyPaymentMessage = async (student) => {
  const token = getToken();
  const baseUrl = getBaseUrl();
  const studentId = student.student_id;

  if (student.alert_type === 'low_sessions') {
    const n = Number(student.remaining_lessons ?? 0);
    const name = student.raw_name || String(student.name || '').split(' — ')[0];
    const line = !Number.isFinite(n) || n <= 0
      ? '課程堂數已用完；若需繼續上課，請協助聯繫加購堂數。'
      : `課程目前僅剩 ${n} 堂，即將用完；如需續課請協助聯繫加購。`;
    const msg = `親愛的家長您好，\n\n${name} 同學：${line}\n\n如有疑問，歡迎聯繫補習班，謝謝！`;
    try { await navigator.clipboard.writeText(msg); alert('續課／加購提醒已複製到剪貼簿！'); }
    catch { prompt('請手動複製以下訊息：', msg); }
    return;
  }

  if (student.alert_type === 'monthly_due_soon') {
    const d = Number(student.days_until_settlement);
    let line = '';
    if (Number.isFinite(d) && d < 0) line = `本月月結費用已逾期 ${Math.abs(d)} 天，請盡快完成繳費。`;
    else if (d === 0) line = '今日為月結繳費日，請於今日完成繳費。';
    else line = `月結繳費日將至（剩 ${d} 天），請留意於期限內完成繳費。`;
    const due = student.due_date ? `（繳費日：${student.due_date}）` : '';
    const msg = `親愛的家長您好，\n\n${student.raw_name || student.name.split(' — ')[0]} 同學${due}\n${line}\n\n如有疑問，歡迎聯繫補習班，謝謝！`;
    try { await navigator.clipboard.writeText(msg); alert('繳費通知已複製到剪貼簿！'); }
    catch { prompt('請手動複製以下訊息：', msg); }
    return;
  }

  if (!studentId) {
    const msg = `親愛的家長您好，\n\n${student.raw_name || student.name.split(' — ')[0]} 同學的課程剩餘 ${student.remaining_lessons} 堂，請盡速繳費，以免影響上課。\n\n如有疑問，歡迎聯繫補習班，謝謝！`;
    try { await navigator.clipboard.writeText(msg); alert('繳費通知已複製到剪貼簿！'); }
    catch { prompt('請手動複製以下訊息：', msg); }
    return;
  }

  try {
    const res = await fetch(`${baseUrl}/v1/parent/payment-message/${studentId}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || '取得失敗');
    try { await navigator.clipboard.writeText(data.message); alert('繳費通知已複製到剪貼簿！'); }
    catch { prompt('請手動複製以下訊息：', data.message); }
  } catch (e) {
    alert('無法取得繳費通知：' + (e.message || ''));
  }
};

const triggerImport = () => {
  if (importState.value === 'uploading') return;
  importFileInput.value?.click();
};

const handleImportFile = async (event) => {
  const file = event.target.files[0];
  if (!file) return;
  if (!props.branchId) { alert('請先選擇分校'); event.target.value = ''; return; }

  importState.value = 'uploading';
  importErrorMsg.value = '';
  const token = getToken();
  const baseUrl = getBaseUrl();

  try {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('branch_id', String(props.branchId));
    const res = await fetch(`${baseUrl}/v1/students/import`, {
      method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: fd,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      importState.value = 'error';
      importErrorMsg.value = json?.message || json?.error || '匯入失敗';
      return;
    }
    const r = json?.result || {};
    importResult.value = {
      created: Number(r.created || 0), updated: Number(r.updated || 0),
      skipped: Number(r.skipped || 0),
      errors: Array.isArray(r.errors) ? r.errors : [],
      warnings: Array.isArray(r.warnings) ? r.warnings : [],
      low_confidence: Number(r.low_confidence_matches || 0),
    };
    importState.value = 'done';
  } catch (e) {
    importState.value = 'error';
    importErrorMsg.value = e?.message || '匯入失敗';
  } finally {
    event.target.value = '';
  }
};

const dismissImport = () => { importState.value = 'idle'; };

const scrollTo = (section) => {
  const el = document.getElementById(section + '-sec');
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

watch(() => props.branchId, () => {
  loadData();
  loadScheduleDiscrepancySummary();
});
onMounted(() => {
  loadData();
  loadScheduleDiscrepancySummary();
});
</script>

<style scoped>
/* ===== Schedule Discrepancy card ===== */
.sd-card {
  cursor: pointer;
  transition: background 120ms ease, border-color 120ms ease, transform 120ms ease;
  outline: none;
}
.sd-card:hover { background: var(--surface-muted, #f8fafc); }
.sd-card:focus-visible { box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25); }
.sd-card--alert { border-left: 4px solid var(--warning, #f59e0b); }

.sd-dash-body {
  display: flex;
  align-items: center;
  gap: 14px;
}
.sd-dash-num {
  font-size: 32px;
  font-weight: 800;
  color: var(--warning-strong, #b45309);
  min-width: 54px;
  text-align: center;
  background: var(--warning-soft, #fffbeb);
  border: 1px solid var(--warning-border, #fde68a);
  border-radius: 8px;
  padding: 6px 10px;
}
.sd-dash-text { flex: 1; min-width: 0; }
.sd-dash-title { font-weight: 700; font-size: 14px; color: var(--text, #0f172a); }
.sd-dash-sub { font-size: 12px; color: var(--text-light, #64748b); margin-top: 2px; }
.sd-dash-cta { align-self: center; }

.sd-dash-empty {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 4px;
}
.sd-dash-empty .material-symbols-outlined {
  font-size: 28px;
  color: var(--success-strong, #047857);
}

.sd-skel-wrap { padding: 6px 4px; display: flex; flex-direction: column; gap: 8px; }
.sd-skel-num, .sd-skel-line {
  background: linear-gradient(90deg, rgba(0,0,0,0.06) 25%, rgba(0,0,0,0.12) 50%, rgba(0,0,0,0.06) 75%);
  background-size: 200% 100%;
  border-radius: 6px;
  animation: sd-skel 1.4s ease-in-out infinite;
}
.sd-skel-num { width: 50%; height: 36px; }
.sd-skel-line { width: 80%; height: 12px; }
@keyframes sd-skel {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* ===== Layout ===== */
.dash {
  display: flex;
  flex-direction: column;
  gap: 18px;
  max-width: 1200px;
  margin: 0 auto;
  position: relative;
  padding: 0 8px 24px;
}
.dash::before {
  content: '';
  position: absolute;
  inset: -24px -28px auto;
  height: 380px;
  pointer-events: none;
  z-index: -1;
  background:
    radial-gradient(circle at 14% 18%, rgba(148, 163, 184, 0.16), transparent 30%),
    radial-gradient(circle at 86% 0%, rgba(245, 158, 11, 0.08), transparent 26%),
    linear-gradient(135deg, rgba(15, 23, 42, 0.045), transparent 60%);
  filter: blur(2px);
}

.no-branch-card {
  max-width: 480px;
  margin: 2rem auto;
  padding: 2rem;
  text-align: center;
}
.no-branch-card h2 { margin-bottom: 1rem; }
.no-branch-card .hint { margin-top: 1rem; font-size: 0.9rem; color: var(--text-light); }

/* ===== Header ===== */
.dash-header {
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 18px;
  flex-wrap: wrap;
  padding: 24px;
  border-radius: 26px;
  border: 1px solid rgba(15, 23, 42, 0.10);
  background:
    radial-gradient(circle at top right, rgba(15, 23, 42, 0.08), transparent 34%),
    linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.94));
  box-shadow: 0 24px 62px rgba(15, 23, 42, 0.11), inset 0 1px 0 rgba(255,255,255,0.9);
  color: #0f172a;
}
.dash-header::before {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  background:
    linear-gradient(rgba(15,23,42,0.035) 1px, transparent 1px),
    linear-gradient(90deg, rgba(15,23,42,0.035) 1px, transparent 1px);
  background-size: 38px 38px;
  mask-image: radial-gradient(circle at 42% 28%, #000 0%, transparent 72%);
}
.dash-header::after {
  content: '';
  position: absolute;
  top: -82px;
  right: -70px;
  width: 270px;
  height: 270px;
  border-radius: 999px;
  border: 1px solid rgba(15, 23, 42, 0.10);
  background:
    radial-gradient(circle, rgba(15,23,42,0.06), transparent 58%),
    conic-gradient(from 110deg, rgba(15,23,42,0), rgba(15,23,42,0.12), rgba(245,158,11,0.14), rgba(15,23,42,0));
  opacity: 0.72;
  pointer-events: none;
}
.dash-title-block,
.dash-date-panel {
  position: relative;
  z-index: 1;
}
.dash-kicker {
  margin: 0 0 5px;
  font-size: 11px;
  font-weight: 900;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: #7dd3fc;
  text-shadow: none;
}
.dash-title {
  font-size: clamp(2rem, 4vw, 3.4rem);
  font-weight: 950;
  color: #0f172a;
  margin: 0;
  letter-spacing: 0.04em;
  line-height: 0.95;
  text-shadow: none;
}
.dash-subtitle {
  margin: 10px 0 0;
  color: #475569;
  font-size: 14px;
  font-weight: 700;
}
.dash-date-panel {
  display: grid;
  gap: 4px;
  min-width: 170px;
  padding: 12px 14px;
  border-radius: 18px;
  border: 1px solid rgba(148, 163, 184, 0.28);
  background: rgba(248, 250, 252, 0.84);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.8);
}
.dash-date-label {
  font-size: 10px;
  font-weight: 900;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: #64748b;
}
.dash-date {
  font-size: 14px;
  color: #0f172a;
  font-weight: 800;
}

/* ===== Action Lane ===== */
.action-lane {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding: 12px;
  scroll-snap-type: x mandatory;
  border-radius: 22px;
  border: 1px solid rgba(15, 23, 42, 0.08);
  background:
    linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248,250,252,0.92));
  box-shadow: 0 20px 54px rgba(15, 23, 42, 0.1);
}

.ac {
  position: relative;
  overflow: hidden;
  flex: 1 1 0;
  min-width: 170px;
  max-width: 260px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 16px;
  border-radius: 18px;
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-left: 0;
  background:
    linear-gradient(145deg, rgba(255,255,255,0.96), rgba(248,250,252,0.88));
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.82);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
  scroll-snap-align: start;
}
.ac::before {
  content: '';
  position: absolute;
  inset: 0 0 auto;
  height: 3px;
  background: linear-gradient(90deg, #38bdf8, #f59e0b);
  opacity: 0.72;
}
.ac:hover {
  transform: translateY(-2px);
  border-color: rgba(14, 165, 233, 0.28);
  box-shadow: 0 16px 34px rgba(15,23,42,0.12);
}
.ac:focus-visible { outline: 2px solid var(--primary, #3b82f6); outline-offset: 2px; }

.ac--attend::before { background: linear-gradient(90deg, #ef4444, #fb7185); }
.ac--pay::before    { background: linear-gradient(90deg, #f97316, #f59e0b); }
.ac--eval::before   { background: linear-gradient(90deg, #3b82f6, #38bdf8); }
.ac--feedback::before { background: linear-gradient(90deg, #f59e0b, #facc15); }
.ac--makeup::before { background: linear-gradient(90deg, #8b5cf6, #38bdf8); }
.ac--import::before { background: linear-gradient(90deg, #10b981, #38bdf8); }
.ac--clear  {
  background:
    radial-gradient(circle at top right, rgba(34,197,94,0.14), transparent 38%),
    linear-gradient(145deg, rgba(240,253,244,0.98), rgba(255,255,255,0.92));
  cursor: default;
}
.ac--clear::before { background: linear-gradient(90deg, #22c55e, #86efac); }
.ac--clear:hover { transform: none; box-shadow: inset 0 1px 0 rgba(255,255,255,0.82); }

.ac__icon {
  font-size: 28px;
  flex-shrink: 0;
}
.ac--attend .ac__icon { color: #ef4444; }
.ac--pay    .ac__icon { color: #f97316; }
.ac--eval   .ac__icon { color: #3b82f6; }
.ac--feedback .ac__icon { color: #f59e0b; }
.ac--makeup .ac__icon { color: #8b5cf6; }
.ac--import .ac__icon { color: #10b981; }
.ac--clear  .ac__icon { color: #22c55e; }

.ac__body {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}
.ac__count {
  font-size: 28px;
  font-weight: 950;
  line-height: 1.1;
  color: var(--text, #0f172a);
  font-variant-numeric: tabular-nums;
}
.ac__label {
  font-size: 12px;
  font-weight: 800;
  color: var(--text-light, #64748b);
  white-space: nowrap;
}
.ac__sub {
  font-size: 11px;
  color: #94a3b8;
}

.ac__cta {
  flex-shrink: 0;
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 900;
  border: none;
  border-radius: 999px;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.15s;
}
.ac--attend .ac__cta { background: #fef2f2; color: #dc2626; }
.ac--attend .ac__cta:hover { background: #fee2e2; }
.ac--pay .ac__cta    { background: #fff7ed; color: #ea580c; }
.ac--pay .ac__cta:hover { background: #ffedd5; }
.ac--eval .ac__cta   { background: #eff6ff; color: #2563eb; }
.ac--eval .ac__cta:hover { background: #dbeafe; }
.ac--feedback .ac__cta { background: #fffbeb; color: #d97706; }
.ac--feedback .ac__cta:hover { background: #fef3c7; }
.ac--makeup .ac__cta { background: #f5f3ff; color: #7c3aed; }
.ac--makeup .ac__cta:hover { background: #ede9fe; }
.ac--import .ac__cta { background: #ecfdf5; color: #059669; }
.ac--import .ac__cta:hover { background: #d1fae5; }
.ac__cta:disabled { opacity: 0.5; cursor: not-allowed; }

/* ===== Import Banner ===== */
.import-banner {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 10px;
  padding: 14px 16px;
}
.import-banner--err {
  background: #fef2f2;
  border-color: #fecaca;
}
.import-banner__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.import-banner__head strong { font-size: 14px; }
.import-banner__x {
  border: none;
  background: none;
  font-size: 20px;
  cursor: pointer;
  color: #94a3b8;
  line-height: 1;
  padding: 0 4px;
}
.import-banner__x:hover { color: #475569; }

.import-banner__stats {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 6px;
}
.ib-chip {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  background: #f1f5f9;
  color: #475569;
}
.ib-chip--ok   { background: #dcfce7; color: #166534; }
.ib-chip--info { background: #dbeafe; color: #1e40af; }
.ib-chip--warn { background: #fef9c3; color: #854d0e; }
.ib-chip--err  { background: #fee2e2; color: #991b1b; }

.import-banner__list {
  font-size: 12px;
  color: #64748b;
  padding: 6px 0;
  border-top: 1px solid #e2e8f0;
  margin-top: 6px;
}
.import-banner__list > div { padding: 2px 0; }
.import-banner__list--warn { color: #92400e; }
.import-banner__list--err  { color: #991b1b; }
.import-banner__foot {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 8px;
}

/* ===== Progress Board ===== */
.progress-board {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 10px;
  background: rgba(248, 250, 252, 0.76);
  border: 1px solid rgba(148, 163, 184, 0.20);
  border-radius: 22px;
  padding: 12px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.86);
}

.pb {
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-height: 84px;
  padding: 13px;
  border-radius: 16px;
  border: 1px solid rgba(148, 163, 184, 0.22);
  background:
    linear-gradient(145deg, rgba(255,255,255,0.98), rgba(248,250,252,0.86));
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.9);
}
.pb::after {
  content: '';
  position: absolute;
  inset: auto 10px 10px;
  height: 2px;
  border-radius: 999px;
  background: linear-gradient(90deg, #0f172a, #f59e0b);
  opacity: 0.58;
}
.pb__label {
  font-size: 10px;
  font-weight: 900;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.12em;
}
.pb__val {
  font-size: 15px;
  color: #475569;
}
.pb__val strong {
  font-weight: 950;
  font-size: 30px;
  color: #0f172a;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}
.pb__val small  { font-weight: 700; color: #94a3b8; }
.pb__bar {
  height: 5px;
  border-radius: 999px;
  background: rgba(226, 232, 240, 0.9);
  overflow: hidden;
  margin-top: auto;
}
.pb__fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #0f172a, #f59e0b);
  transition: width 0.4s ease;
  box-shadow: none;
}

/* ===== Work Grid ===== */
.work-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
}
.work-col {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

/* ===== Work Panel ===== */
.wp {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 1px 4px rgba(15,23,42,0.06);
  overflow: hidden;
}
.wp--warn { border-top: 3px solid #f97316; }

.wp__head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 14px 16px 10px;
  border-bottom: 1px solid #f1f5f9;
}
.wp__head h3 {
  font-size: 14px;
  font-weight: 700;
  color: var(--text, #0f172a);
  margin: 0;
  flex: 1;
}
.wp__hi {
  font-size: 20px;
  color: var(--text-light, #64748b);
}
.wp__badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  font-size: 11px;
  font-weight: 700;
  border-radius: 11px;
  background: #eff6ff;
  color: #2563eb;
}
.wp__badge--danger { background: #fef2f2; color: #dc2626; }

.wp__hint {
  margin: 0;
  padding: 0 16px 8px;
  font-size: 12px;
  color: #94a3b8;
}
.wp__empty {
  padding: 20px 16px;
  text-align: center;
  font-size: 13px;
  color: #94a3b8;
}
.wp__foot {
  padding: 10px 16px;
  border-top: 1px solid #f1f5f9;
  display: flex;
  justify-content: flex-end;
}

/* ===== Schedule Row ===== */
.sched-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 16px;
  font-size: 13px;
  border-bottom: 1px solid #f8fafc;
  transition: background 0.1s;
}
.sched-row:hover { background: #f8fafc; }
.sched-row__time {
  font-weight: 700;
  color: var(--text, #0f172a);
  min-width: 44px;
  flex-shrink: 0;
}
.sched-row__name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sched-row__subj {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 10px;
  background: #f1f5f9;
  color: #475569;
  white-space: nowrap;
}
.sched-row__tchr {
  font-size: 11px;
  color: #94a3b8;
  white-space: nowrap;
}
.sched-row__st {
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}
.st--scheduled { color: #f97316; }
.st--attended  { color: #22c55e; }
.st--completed { color: #64748b; }
.st--leave, .st--leave_adjusted, .st--excused { color: #8b5cf6; }
.st--absent    { color: #ef4444; }
.st--late      { color: #eab308; }
.st--cancelled { color: #94a3b8; }

/* ===== Payment Row ===== */
.pay-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border-bottom: 1px solid #f8fafc;
}
.pay-row:hover { background: #fffbeb; }
.pay-row__info {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.pay-row__name {
  font-size: 13px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ===== Eval Card ===== */
.eval-card {
  padding: 12px 16px;
  border-bottom: 1px solid #f1f5f9;
}
.eval-card:last-of-type { border-bottom: none; }
.eval-card__top {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}
.eval-card__top strong { font-size: 13px; }
.eval-card__tag {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 10px;
  background: #eff6ff;
  color: #2563eb;
}
.eval-card__tchr {
  font-size: 11px;
  color: #94a3b8;
  margin-left: auto;
}
.eval-card__mid {
  font-size: 12px;
  color: #64748b;
  margin-bottom: 4px;
}
.eval-card__comment {
  font-size: 12px;
  color: #475569;
  background: #f8fafc;
  padding: 6px 8px;
  border-radius: 6px;
  margin-bottom: 6px;
  line-height: 1.5;
}
.eval-card__acts {
  display: flex;
  gap: 6px;
  justify-content: flex-end;
}

/* ===== Notification Row ===== */
.notif-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  font-size: 13px;
  border-bottom: 1px solid #f8fafc;
}

/* ===== KPI Panel ===== */
.kpi {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 1px 4px rgba(15,23,42,0.06);
  overflow: hidden;
}
.kpi__sum {
  list-style: none;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 18px;
  cursor: pointer;
  user-select: none;
  font-size: 14px;
  font-weight: 600;
  color: var(--text, #0f172a);
  transition: background 0.15s;
}
.kpi__sum::-webkit-details-marker { display: none; }
.kpi__sum:hover { background: #f8fafc; }
.kpi__sum-icon { font-size: 20px; color: #6d28d9; }
.kpi__sum-hint {
  margin-left: auto;
  font-size: 12px;
  font-weight: 400;
  color: #94a3b8;
}
.kpi__chev {
  font-size: 14px;
  color: #94a3b8;
  transition: transform 0.2s ease;
}
.kpi[open] .kpi__chev { transform: rotate(180deg); }

.kpi__body {
  padding: 0 18px 18px;
}

.kpi-totals {
  display: flex;
  gap: 24px;
  margin-bottom: 16px;
  padding-bottom: 14px;
  border-bottom: 1px solid #f1f5f9;
}
.kpi-t__label {
  font-size: 11px;
  font-weight: 600;
  color: var(--text-light, #64748b);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.kpi-t__val {
  font-size: 26px;
  font-weight: 800;
  color: var(--text, #0f172a);
  margin-top: 2px;
}

.kpi-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  margin-bottom: 12px;
}
.kpi-table th {
  text-align: left;
  font-size: 11px;
  font-weight: 600;
  color: var(--text-light, #64748b);
  padding: 8px 8px;
  border-bottom: 2px solid #f1f5f9;
}
.kpi-table td {
  padding: 8px 8px;
  border-bottom: 1px solid #f8fafc;
}
.kpi-table tr:hover td { background: #f8fafc; }

.kpi__link {
  margin-top: 14px;
  display: flex;
  justify-content: flex-end;
}

/* ===== Level Chips ===== */
.level-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}
.level-chip {
  display: inline-block;
  background: #f5f3ff;
  color: #6d28d9;
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  border: 1px solid #ddd6fe;
}

/* ===== Badges ===== */
.badge-blue {
  display: inline-block;
  background: #e3f2fd;
  color: #1565c0;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 10px;
}
.badge-red {
  display: inline-block;
  background: #fde8e8;
  color: #c0392b;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 10px;
  font-weight: 600;
}
.badge-orange {
  display: inline-block;
  background: #fff3e0;
  color: #e65100;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 10px;
  font-weight: 600;
}
.badge-amber {
  display: inline-block;
  background: #fffbeb;
  color: #b45309;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 10px;
  font-weight: 600;
  border: 1px solid #fcd34d;
}

/* ===== Buttons ===== */
.btn-p {
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  background: var(--primary, #3b82f6);
  color: #fff;
  transition: opacity 0.15s;
}
.btn-p:hover { opacity: 0.88; }
.btn-d {
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  background: #ef4444;
  color: #fff;
  transition: opacity 0.15s;
}
.btn-d:hover { opacity: 0.88; }
.btn-o {
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  background: #fff;
  color: #475569;
  transition: background 0.15s;
}
.btn-o:hover { background: #f8fafc; }

.btn-sm { padding: 6px 14px; font-size: 12px; }
.btn-xs { padding: 4px 10px; font-size: 11px; }

/* ===== Responsive ===== */
@media (max-width: 900px) {
  .dash-header {
    align-items: flex-start;
    flex-direction: column;
    padding: 20px;
  }
  .dash-date-panel {
    width: 100%;
  }
  .work-grid { grid-template-columns: 1fr; }
  .progress-board { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 600px) {
  .action-lane {
    gap: 10px;
  }
  .ac {
    min-width: 150px;
    padding: 10px 12px;
  }
  .ac__count { font-size: 18px; }
  .ac__icon  { font-size: 22px; }
  .progress-board {
    grid-template-columns: 1fr 1fr;
    padding: 12px 14px;
  }
  .dash { gap: 14px; }
}

@media (max-width: 420px) {
  .progress-board { grid-template-columns: 1fr; }
  .ac { min-width: 130px; }
  .ac__cta { display: none; }
}

/* ===== 每日待辦 section label ===== */
.section-label-row {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: -4px;
  padding: 0 4px;
}
.section-label {
  font-size: 12px;
  font-weight: 900;
  color: #0f172a;
  text-transform: uppercase;
  letter-spacing: 0.12em;
}
.section-sublabel {
  font-size: 11px;
  color: var(--text-light);
  font-weight: 700;
}

/* ===== 匯入格式連結 ===== */
.ac__format-link {
  font-size: 11px;
  color: #0f766e;
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
  text-decoration: underline;
  font-family: inherit;
  text-align: left;
}

/* ===== 匯入格式 Modal ===== */
.import-format-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.import-format-modal {
  background: var(--card-bg, #fff);
  border-radius: 14px;
  padding: 20px 24px;
  max-width: 500px;
  width: 100%;
  box-shadow: 0 8px 40px rgba(0, 0, 0, 0.22);
  max-height: 90vh;
  overflow-y: auto;
}
.import-format-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  font-size: 15px;
}
.import-format-close {
  background: none;
  border: none;
  font-size: 20px;
  cursor: pointer;
  color: var(--text-light);
  line-height: 1;
  padding: 0 4px;
}
.import-format-desc {
  font-size: 13px;
  color: var(--text-light);
  margin-bottom: 10px;
}
.import-format-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  margin-bottom: 14px;
}
.import-format-table th,
.import-format-table td {
  border: 1px solid var(--border);
  padding: 6px 10px;
  text-align: left;
}
.import-format-table thead th {
  background: var(--bg);
  font-weight: 600;
}
.import-format-example {
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 12px;
  font-family: monospace;
  overflow-x: auto;
  margin: 4px 0 12px;
  white-space: pre;
}
.import-format-note {
  font-size: 12px;
  color: var(--text-light);
  margin: 6px 0 2px;
}
</style>
