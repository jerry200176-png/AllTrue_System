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
          <h2 class="dash-title">{{ branchName }}</h2>
          <span class="dash-date">{{ todayDisplay }}</span>
        </div>

        <!-- ===== Layer 1: Action Lane ===== -->
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
               @click="scrollTo('payments')" @keydown.enter="scrollTo('payments')">
            <span class="material-symbols-outlined ac__icon">payments</span>
            <div class="ac__body">
              <span class="ac__count">{{ lowBalanceStudents.length }}</span>
              <span class="ac__label">筆待催繳</span>
            </div>
            <button class="ac__cta" @click.stop="scrollTo('payments')">查看催繳</button>
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

          <div class="ac ac--import" tabindex="0"
               @click="triggerImport" @keydown.enter="triggerImport">
            <span class="material-symbols-outlined ac__icon">upload_file</span>
            <div class="ac__body">
              <span class="ac__label">匯入學生</span>
              <span class="ac__sub">CSV / Excel</span>
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

            <!-- Payment Alerts -->
            <section class="wp wp--warn" id="payments-sec" data-guide="director-alerts">
              <header class="wp__head">
                <span class="material-symbols-outlined wp__hi">warning</span>
                <h3>繳費提醒</h3>
                <span v-if="lowBalanceStudents.length" class="wp__badge wp__badge--danger">{{ lowBalanceStudents.length }}</span>
              </header>
              <div v-if="!lowBalanceStudents.length" class="wp__empty">目前無符合催繳條件之課程</div>
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
                  <span class="eval-card__tag">{{ ev.Subject }}</span>
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
</template>

<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { supabase } from '../supabase';
import { getBranchName } from '../lib/useBranches';
import { getSubjectLabel as getSubjectText } from '../lib/constants';

const props = defineProps({
  branchId: [String, Number]
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

const importFileInput = ref(null);
const importState = ref('idle');
const importResult = ref({ created: 0, updated: 0, skipped: 0, errors: [], warnings: [], low_confidence: 0 });
const importErrorMsg = ref('');

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
);

const displayPaymentAlerts = computed(() =>
  showAllPayments.value
    ? lowBalanceStudents.value
    : lowBalanceStudents.value.slice(0, paymentAlertLimit)
);

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
  return `${s.remaining_lessons} 堂`;
};

const formatTime = (timeStr) => timeStr || '--:--';

const formatScheduleStatus = (status) => {
  const map = {
    scheduled: '待到班', attended: '已到班', completed: '已下課',
    cancelled: '已取消', leave: '已請假',
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
        .filter(s => s.id > 0 && !['cancelled', 'leave'].includes(s.status))
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
      `${baseUrl}/v1/learning-records?branch_id=${props.branchId}&status=pending&only_due=1&per_page=50&sort=session_date`,
      { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }
    );
    if (pendingRes.ok) {
      const pendingJson = await pendingRes.json();
      pendingEvaluations.value = pendingJson.data || [];
    }
  } catch (err) {
    console.error('Failed to load pending evaluations:', err);
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
    if (res.ok) { loadData(); }
    else { const err = await res.json(); alert('核准失敗: ' + (err.message || '')); }
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

watch(() => props.branchId, loadData);
onMounted(loadData);
</script>

<style scoped>
/* ===== Layout ===== */
.dash {
  display: flex;
  flex-direction: column;
  gap: 18px;
  max-width: 1200px;
  margin: 0 auto;
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
  display: flex;
  align-items: baseline;
  gap: 12px;
  flex-wrap: wrap;
}
.dash-title {
  font-size: 20px;
  font-weight: 800;
  color: var(--text, #0f172a);
  margin: 0;
}
.dash-date {
  font-size: 13px;
  color: var(--text-light, #64748b);
}

/* ===== Action Lane ===== */
.action-lane {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding-bottom: 4px;
  scroll-snap-type: x mandatory;
}

.ac {
  flex: 1 1 0;
  min-width: 170px;
  max-width: 260px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 16px;
  border-radius: 12px;
  border-left: 4px solid transparent;
  background: #fff;
  box-shadow: 0 1px 4px rgba(15,23,42,0.07);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
  scroll-snap-align: start;
}
.ac:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(15,23,42,0.12); }
.ac:focus-visible { outline: 2px solid var(--primary, #3b82f6); outline-offset: 2px; }

.ac--attend { border-left-color: #ef4444; }
.ac--pay    { border-left-color: #f97316; }
.ac--eval   { border-left-color: #3b82f6; }
.ac--import { border-left-color: #10b981; }
.ac--clear  {
  border-left-color: #22c55e;
  background: #f0fdf4;
  cursor: default;
}
.ac--clear:hover { transform: none; box-shadow: 0 1px 4px rgba(15,23,42,0.07); }

.ac__icon {
  font-size: 28px;
  flex-shrink: 0;
}
.ac--attend .ac__icon { color: #ef4444; }
.ac--pay    .ac__icon { color: #f97316; }
.ac--eval   .ac__icon { color: #3b82f6; }
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
  font-size: 22px;
  font-weight: 800;
  line-height: 1.1;
  color: var(--text, #0f172a);
}
.ac__label {
  font-size: 12px;
  font-weight: 600;
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
  font-weight: 600;
  border: none;
  border-radius: 8px;
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
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  background: #fff;
  border-radius: 12px;
  padding: 14px 18px;
  box-shadow: 0 1px 4px rgba(15,23,42,0.06);
}

.pb {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.pb__label {
  font-size: 11px;
  font-weight: 600;
  color: var(--text-light, #64748b);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.pb__val {
  font-size: 15px;
  color: var(--text, #0f172a);
}
.pb__val strong { font-weight: 800; font-size: 18px; }
.pb__val small  { font-weight: 400; color: var(--text-light, #94a3b8); }
.pb__bar {
  height: 4px;
  border-radius: 2px;
  background: #e2e8f0;
  overflow: hidden;
  margin-top: 2px;
}
.pb__fill {
  height: 100%;
  border-radius: 2px;
  background: linear-gradient(90deg, #3b82f6, #60a5fa);
  transition: width 0.4s ease;
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
</style>
