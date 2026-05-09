<template>
  <div class="th-page">
    <!-- Page Header -->
    <div class="page-header th-header">
      <div>
        <h2>教學工作台</h2>
        <p class="page-desc">今日待辦一覽、本週跨分校課表</p>
      </div>
      <div class="th-header-actions">
        <button class="ghost small" @click="refreshAll" :disabled="refreshing">
          <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle">refresh</span>
          重新整理
        </button>
      </div>
    </div>

    <!-- Clock-in Status Card -->
    <div class="th-clockin-card card" :class="clockinCardClass"
      @click="goAttendance" role="button" tabindex="0"
      @keydown.enter="goAttendance" aria-label="查看今日打卡狀態">

      <!-- Header row: icon + title + badge + arrow -->
      <div class="th-clockin-header">
        <div class="th-clockin-icon-wrap" :class="clockinIconWrapClass" aria-hidden="true">
          <span class="material-symbols-outlined">fingerprint</span>
        </div>
        <div class="th-clockin-title-group">
          <span class="th-clockin-title">今日打卡狀態</span>
          <span class="th-clockin-badge" :class="clockinBadgeClass">{{ clockinBadgeLabel }}</span>
        </div>
        <span class="material-symbols-outlined th-clockin-arrow">chevron_right</span>
      </div>

      <!-- Body: two time chips side by side -->
      <div class="th-clockin-chips" v-if="!clockinLoading">
        <!-- 簽到 chip -->
        <div class="th-clockin-chip">
          <span class="th-chip-label">簽到</span>
          <span class="th-chip-val" v-if="clockinRecord.sign_in_dt">
            {{ formatTime(clockinRecord.sign_in_dt) }}
          </span>
          <span class="th-chip-val th-chip-empty" v-else>—</span>
        </div>
        <!-- 簽退 chip -->
        <div class="th-clockin-chip">
          <span class="th-chip-label">簽退</span>
          <span class="th-chip-val" v-if="clockinRecord.sign_out_dt">
            {{ formatTime(clockinRecord.sign_out_dt) }}
          </span>
          <span class="th-chip-val th-chip-warn" v-else-if="clockinRecord.sign_in_dt">未簽退</span>
          <span class="th-chip-val th-chip-empty" v-else>—</span>
        </div>
      </div>

      <!-- Skeleton while loading -->
      <div class="th-clockin-chips" v-else aria-hidden="true">
        <div class="th-clockin-chip th-chip-skeleton"></div>
        <div class="th-clockin-chip th-chip-skeleton"></div>
      </div>

      <!-- Hint: first class time -->
      <div class="th-clockin-hint" v-if="!clockinLoading && clockinRecord.first_class_start_time">
        <span class="material-symbols-outlined" style="font-size:13px;vertical-align:-2px">schedule</span>
        第一堂課：{{ clockinRecord.first_class_start_time }}
      </div>
    </div>

    <!-- A. Today's Actions -->
    <div class="th-today card" data-guide="teacher-home-today">
      <h3 class="th-section-title">
        <span class="material-symbols-outlined th-section-icon">today</span>
        今日待辦
      </h3>

      <div class="th-actions">
        <!-- Pending Attendance CTA -->
        <button
          class="th-action-btn th-action-attendance"
          :class="{ 'th-done': pendingAttendanceCount === 0 && !loadingAttendance }"
          @click="goAttendance"
        >
          <div class="th-action-icon-wrap">
            <span class="material-symbols-outlined">fact_check</span>
          </div>
          <div class="th-action-body">
            <div class="th-action-label" v-if="loadingAttendance">載入中…</div>
            <div class="th-action-label" v-else-if="pendingAttendanceCount > 0">
              待點名 <strong>{{ pendingAttendanceCount }}</strong> 堂
            </div>
            <div class="th-action-label" v-else>今日點名已完成</div>
            <div class="th-action-hint">前往出缺勤管理</div>
          </div>
          <span class="material-symbols-outlined th-action-arrow">chevron_right</span>
        </button>

        <!-- Pending Learning Records CTA -->
        <button
          class="th-action-btn th-action-learning"
          :class="{ 'th-done': pendingLearningCount === 0 && !loadingLearning }"
          type="button"
          @click="fillNextPendingLearning"
        >
          <div class="th-action-icon-wrap">
            <span class="material-symbols-outlined">assignment</span>
          </div>
          <div class="th-action-body">
            <div class="th-action-label" v-if="loadingLearning && loadingOverdue">載入中…</div>
            <div class="th-action-label" v-else-if="pendingLearningCount > 0">
              待填／待修改 <strong>{{ pendingLearningCount }}</strong> 筆評量
              <span v-if="overdueCount > 0" class="th-overdue-hint">（含過往 {{ overdueCount }} 筆）</span>
            </div>
            <div class="th-action-label" v-else>今日評量已完成</div>
            <div class="th-action-hint th-action-hint--split">
              <span v-if="pendingLearningCount > 0">點擊優先開下一筆待填</span>
              <span v-else>前往課表與評量</span>
              <span
                v-if="pendingLearningCount > 0"
                class="th-inline-link"
                role="button"
                tabindex="0"
                @click.stop="goLearning"
                @keydown.enter.prevent.stop="goLearning"
              >僅開列表</span>
            </div>
          </div>
          <span class="material-symbols-outlined th-action-arrow">chevron_right</span>
        </button>

        <button
          v-if="unreadFeedbackCount > 0"
          class="th-action-btn th-action-feedback"
          @click="goLearning"
        >
          <div class="th-action-icon-wrap">
            <span class="material-symbols-outlined">mark_unread_chat_alt</span>
          </div>
          <div class="th-action-body">
            <div class="th-action-label">
              家長回饋待看 <strong>{{ unreadFeedbackCount }}</strong> 筆
            </div>
            <div class="th-action-hint">點此查看評量表回饋</div>
          </div>
          <span class="material-symbols-outlined th-action-arrow">chevron_right</span>
        </button>
      </div>

      <div class="th-priority-board">
        <div class="th-priority-head">
          <span class="material-symbols-outlined" style="font-size:16px">priority_high</span>
          今日最重要 3 件事
        </div>
        <div class="th-priority-items">
          <button
            v-for="item in teacherTodoCards"
            :key="item.id"
            type="button"
            class="th-priority-item"
            @click="handleTodoCardClick(item)"
          >
            <span class="th-priority-rank">{{ item.rank }}</span>
            <div class="th-priority-body">
              <span class="th-priority-title">{{ item.title }}</span>
              <span class="th-priority-desc">{{ item.description }}</span>
            </div>
            <span class="material-symbols-outlined">chevron_right</span>
          </button>
        </div>
      </div>

      <div class="th-task-board">
        <div class="th-task-board-head">
          <span class="material-symbols-outlined" style="font-size:16px">checklist</span>
          每日任務清單
        </div>
        <div class="th-task-items">
          <button class="th-task-item" type="button" @click="fillNextPendingLearning">
            <span class="th-task-item-label">今日待填</span>
            <span :class="['th-task-item-count', todayTaskCount > 0 ? 'warn' : 'ok']">{{ todayTaskCount }}</span>
          </button>
          <button class="th-task-item" type="button" @click="openOverdueTask">
            <span class="th-task-item-label">逾期待補</span>
            <span :class="['th-task-item-count', overdueTaskCount > 0 ? 'warn' : 'ok']">{{ overdueTaskCount }}</span>
          </button>
          <button class="th-task-item" type="button" @click="openChangesRequestedTask">
            <span class="th-task-item-label">需修改</span>
            <span :class="['th-task-item-count', changesRequestedTaskCount > 0 ? 'warn' : 'ok']">{{ changesRequestedTaskCount }}</span>
          </button>
        </div>
      </div>

      <div class="th-progress-board" :class="{ loading: learningProgressLoading }">
        <div class="th-progress-head">
          <span class="material-symbols-outlined" style="font-size:16px">insights</span>
          本週評量進度
        </div>
        <div class="th-progress-main">
          <strong>{{ progressSummary.completed_sessions }} / {{ progressSummary.expected_sessions }}</strong>
          <span>{{ progressSummary.completion_rate_pct }}%</span>
          <span class="th-progress-streak">連續 {{ progressSummary.streak_days }} 天全完成</span>
        </div>
        <div class="th-progress-trend">{{ progressTrendText }}</div>
      </div>

      <!-- Cross-branch hint -->
      <div v-if="otherBranchTodayCount > 0" class="th-cross-hint">
        <span class="material-symbols-outlined" style="font-size:16px">info</span>
        他校今日尚有 {{ otherBranchTodayCount }} 堂課，可切換分校查看
      </div>

      <div v-if="todayPendingEvents.length > 0" class="th-today-pending">
        <div class="th-today-pending-head">
          <span class="material-symbols-outlined" style="font-size:16px">playlist_add_check</span>
          今日待填評量清單（{{ todayPendingEvents.length }}）
        </div>
        <div class="th-overdue-list">
          <div
            v-for="ev in todayPendingEvents.slice(0, 4)"
            :key="`today-pending-${ev.key}`"
            class="th-overdue-row"
          >
            <div class="th-overdue-date">{{ ev.startTime }}-{{ ev.endTime }}</div>
            <div class="th-overdue-info">
              <span class="th-overdue-student">{{ ev.studentName || '—' }}</span>
              <span class="th-overdue-subject">{{ ev.subject || '' }}</span>
              <span v-if="ev.formStatus === 'changes_requested'" class="th-form-chip th-form-changes_requested">需修改</span>
            </div>
            <button class="th-fill-btn" @click="goFillRecord(ev)" title="填寫評量">
              <span class="material-symbols-outlined">edit_note</span>
            </button>
          </div>
        </div>
        <button v-if="todayPendingEvents.length > 4" class="th-overdue-more" @click="goLearning">
          前往評量頁查看全部
          <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle">arrow_forward</span>
        </button>
      </div>
    </div>

    <!-- A2. Overdue Learning Records Reminder -->
    <div v-if="overdueRecords.length > 0" class="th-overdue card" data-guide="teacher-home-overdue">
      <h3 class="th-section-title">
        <span class="material-symbols-outlined th-section-icon th-overdue-icon">history</span>
        補填提醒
        <span class="th-overdue-badge">{{ overdueRecords.length }}</span>
      </h3>
      <div class="th-overdue-list">
        <div
          v-for="item in overdueRecords.slice(0, 5)"
          :key="item.id"
          class="th-overdue-row"
        >
          <div class="th-overdue-date">{{ overdueDateLabel(item.session_date) }}</div>
          <div class="th-overdue-info">
            <span class="th-overdue-student">{{ item.student_name || '—' }}</span>
            <span class="th-overdue-subject">{{ item.subject_name || item.subject || '' }}</span>
            <span class="th-branch-chip" :style="{ background: branchColor(item.branch_id) }">{{ branchShortName(item.branch_id) }}</span>
          </div>
          <button class="th-fill-btn" @click="goFillRecord({ branchId: item.branch_id, recordId: null, classSessionId: item.id, sessionDate: item.session_date })" title="填寫評量">
            <span class="material-symbols-outlined">edit_note</span>
          </button>
        </div>
      </div>
      <button v-if="overdueRecords.length > 5" class="th-overdue-more" @click="goLearning">
        查看全部 {{ overdueRecords.length }} 筆
        <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle">arrow_forward</span>
      </button>
    </div>

    <!-- B. Weekly Schedule (merged across all branches) -->
    <div class="th-week card" data-guide="teacher-home-week">
      <div class="th-week-header">
        <h3 class="th-section-title">
          <span class="material-symbols-outlined th-section-icon">date_range</span>
          本週課表
          <span class="th-week-badge" v-if="allBranchNames.length > 1">{{ allBranchNames.length }} 校合併</span>
        </h3>
        <div class="th-week-nav">
          <button class="ghost small icon-btn" @click="weekOffset--" title="上一週">‹</button>
          <span class="th-week-label">{{ weekLabel }}</span>
          <button class="ghost small icon-btn" @click="weekOffset++" title="下一週">›</button>
        </div>
      </div>

      <div v-if="loadingWeek" class="th-loading">
        <div class="th-skeleton" v-for="i in 3" :key="i"></div>
      </div>
      <div v-else-if="weekLoadError" class="th-error">
        <span class="material-symbols-outlined">error_outline</span>
        {{ weekLoadError }}
        <button class="ghost small" @click="loadWeekSchedule">重試</button>
      </div>
      <div v-else-if="weekDays.length === 0" class="th-empty">本週無排課</div>

      <div v-else class="th-days">
        <details
          v-for="day in weekDays"
          :key="day.date"
          class="th-day"
          :class="{ 'th-day-today': day.isToday }"
          :open="day.isToday || day.events.length > 0"
        >
          <summary class="th-day-summary">
            <span class="th-day-dot" :class="{ 'th-day-dot-today': day.isToday }"></span>
            <span class="th-day-label">{{ day.label }}</span>
            <span class="th-day-short-date">{{ day.shortDate }}</span>
            <span v-if="day.isToday" class="th-today-tag">今天</span>
            <span class="th-day-count">{{ day.events.length }} 堂</span>
          </summary>
          <div v-if="day.events.length === 0" class="th-day-empty">無排課</div>
          <div v-else class="th-day-events">
            <div
              v-for="ev in day.events"
              :key="ev.key"
              class="th-event"
              :class="{
                'th-event-done': ev.status === 'attended' || ev.formStatus === 'approved',
                'th-event-leave': ev.formStatus === 'leave',
              }"
            >
              <div class="th-event-time">{{ ev.startTime }}<br>{{ ev.endTime }}</div>
              <div class="th-event-info">
                <div class="th-event-student">{{ ev.studentName }}</div>
                <div class="th-event-meta">
                  <span class="th-event-subject">{{ ev.subject }}</span>
                  <span class="th-branch-chip" :style="{ background: branchColor(ev.branchId) }">{{ branchShortName(ev.branchId) }}</span>
                  <span v-if="ev.formStatus && ev.formStatus !== 'missing'" :class="['th-form-chip', `th-form-${ev.formStatus}`]">{{ formStatusLabel(ev.formStatus) }}</span>
                </div>
              </div>
              <button
                v-if="ev.formStatus === 'missing' || ev.formStatus === 'changes_requested'"
                class="th-fill-btn"
                @click="goFillRecord(ev)"
                title="填寫評量"
              >
                <span class="material-symbols-outlined">edit_note</span>
              </button>
              <span v-else-if="ev.formStatus === 'approved'" class="th-check-icon material-symbols-outlined">check_circle</span>
              <button
                class="th-report-btn"
                :class="{ 'th-report-btn--active': activeReportMap[ev.id] }"
                :title="activeReportMap[ev.id] ? '查看課表回報' : '回報課表有誤'"
                @click.stop="openReport(ev)"
              >
                <span
                  v-if="reportFetching && reportModalSession?.sessionId === ev.id"
                  class="material-symbols-outlined th-report-loading"
                >hourglass_empty</span>
                <span v-else class="material-symbols-outlined">flag</span>
              </button>
            </div>
          </div>
        </details>
      </div>
    </div>

    <!-- C. Quick Links -->
    <div class="th-links card" data-guide="teacher-home-links">
      <!-- Chat entry card -->
      <button class="th-link-btn th-chat-btn" @click="$emit('navigate', 'chat')" style="position:relative">
        <span class="material-symbols-outlined" style="color:var(--primary)">forum</span>
        <span>內部聊天</span>
        <span v-if="chatUnreadLoading" class="th-chat-badge-skeleton"></span>
        <span v-else-if="chatUnreadCount > 0" class="th-chat-badge">{{ chatUnreadCount > 99 ? '99+' : chatUnreadCount }}</span>
        <span v-else class="th-link-sub">目前沒有未讀訊息</span>
      </button>
      <button class="th-link-btn" @click="$emit('navigate', 'subject-units')">
        <span class="material-symbols-outlined">calculate</span>
        <span>科目數統計</span>
        <span class="th-link-sub" v-if="allBranchNames.length > 1">可切換分校</span>
      </button>
      <button class="th-link-btn" @click="$emit('navigate', 'calendar')">
        <span class="material-symbols-outlined">calendar_today</span>
        <span>班級行事曆</span>
      </button>
    </div>
  </div>

  <ReportDiscrepancyModal
    v-if="reportModalOpen"
    :branch-id="reportModalSession?.branchId"
    :class-session-id="reportModalSession?.sessionId"
    :session-context="reportModalSession"
    :existing="reportModalExisting"
    @close="reportModalOpen = false"
    @submitted="handleReportSubmitted"
    @withdrawn="handleReportWithdrawn"
  />
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { supabase } from '../supabase';
import { branches, getBranchName } from '../lib/useBranches';
import { fetchClassSessions } from '../lib/classSessionsApi';
import { fetchChatUnreadCount } from '../lib/chatApi';
import ReportDiscrepancyModal from '../components/ReportDiscrepancyModal.vue';
import { fetchActiveForSession } from '../lib/scheduleDiscrepanciesApi.js';
import { sortTodoCards, markTodoAcknowledged, isTodoAcknowledged } from '../lib/adoptionTodo';
import { trackAdoptionEvent } from '../lib/adoptionTelemetry';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userId: { type: [Number, String], default: null },
  userRole: { type: String, default: '' },
  teacherBranchIds: { type: Array, default: () => [] },
  unreadFeedbackCount: { type: Number, default: 0 },
});

const emit = defineEmits(['navigate', 'navigate-learning']);

const getToken = async () => {
  const { data: { session } } = await supabase.auth.getSession();
  return session?.access_token;
};

const refreshing = ref(false);

// ── Teacher clock-in status ──
const clockinLoading = ref(true);
const clockinRecord  = ref({});

const clockinBadgeClass = computed(() => {
  const s = clockinRecord.value.status;
  if (!s || s === 'no_record') return 'th-badge-warn';
  if (s === 'normal') return 'th-badge-ok';
  if (s === 'late')   return 'th-badge-late';
  return 'th-badge-warn';
});

const clockinBadgeLabel = computed(() => {
  const s = clockinRecord.value.status;
  if (!s || s === 'no_record') return '尚未打卡';
  if (s === 'normal') return clockinRecord.value.sign_out_dt ? '已完成' : '上班中';
  if (s === 'late')   return '遲到';
  return '待確認';
});

// Card-level status class: controls left border colour
const clockinCardClass = computed(() => {
  const s = clockinRecord.value.status;
  if (!s || s === 'no_record') return 'th-ckin-empty';
  if (s === 'late')   return 'th-ckin-late';
  if (s === 'normal') return clockinRecord.value.sign_out_dt ? 'th-ckin-done' : 'th-ckin-working';
  return 'th-ckin-empty';
});

// Icon wrap colour class
const clockinIconWrapClass = computed(() => {
  const s = clockinRecord.value.status;
  if (!s || s === 'no_record') return 'th-icon-empty';
  if (s === 'late')   return 'th-icon-late';
  if (s === 'normal') return clockinRecord.value.sign_out_dt ? 'th-icon-done' : 'th-icon-working';
  return 'th-icon-empty';
});

async function fetchClockinStatus() {
  clockinLoading.value = true;
  try {
    const token = await getToken();
    const res = await fetch('/api/v1/teacher-attendance/today', {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (res.ok) clockinRecord.value = await res.json();
  } catch (_) { /* silent */ } finally {
    clockinLoading.value = false;
  }
}

function formatTime(dt) {
  if (!dt) return '';
  return dt.length >= 16 ? dt.slice(11, 16) : dt;
}

// ── Report discrepancy modal ──
const reportModalOpen = ref(false);
const reportModalSession = ref(null); // { sessionId, date, time, subject, student, branchId }
const reportModalExisting = ref(null);
const activeReportMap = ref({});      // { [sessionId]: discrepancy | null }
const reportFetching = ref(false);

// ── Chat unread count ──
const chatUnreadCount   = ref(0);
const chatUnreadLoading = ref(false);

async function fetchChatUnread() {
  chatUnreadLoading.value = true;
  try {
    const data = await fetchChatUnreadCount(props.branchId);
    chatUnreadCount.value = data?.unread_count ?? 0;
  } catch { chatUnreadCount.value = 0; }
  finally { chatUnreadLoading.value = false; }
}

// ── Today's pending attendance ──
const loadingAttendance = ref(true);
const pendingAttendanceCount = ref(0);
const todayAllSessions = ref([]);

function localTodayYmd() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

async function fetchPendingAttendance() {
  loadingAttendance.value = true;
  try {
    const token = await getToken();
    if (!token) return;
    const today = localTodayYmd();
    const qs = new URLSearchParams({ start: today, end: today, per_page: '500' });
    const res = await fetch(`/api/v1/class-sessions?${qs}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) return;
    const json = await res.json();
    const rows = Array.isArray(json?.data) ? json.data : (Array.isArray(json) ? json : []);
    const scheduled = rows.filter(r => {
      const s = String(r?.status || '').toLowerCase();
      return s === 'scheduled';
    });
    pendingAttendanceCount.value = scheduled.length;
    todayAllSessions.value = rows;
  } catch { /* ignore */ } finally {
    loadingAttendance.value = false;
  }
}

// ── Overdue learning records (past 7 days, attended but missing) ──
const loadingOverdue = ref(false);
const overdueRecords = ref([]);
const overdueCount = computed(() => overdueRecords.value.length);

// ── Today's pending learning records ──
const loadingLearning = ref(true);
const todayOnlyLearningCount = ref(0);
const changesRequestedLearningCount = ref(0);
const pendingLearningCount = computed(() => todayOnlyLearningCount.value + overdueCount.value);

async function fetchPendingLearning() {
  loadingLearning.value = true;
  try {
    const token = await getToken();
    if (!token) return;
    const params = new URLSearchParams({ sort: 'session_date', per_page: '200' });
    if (props.branchId) params.set('branch_id', String(props.branchId));
    const res = await fetch(`/api/v1/learning-records?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) return;
    const json = await res.json();
    const records = json.data || [];
    const today = localTodayYmd();
    const pending = records.filter(r => {
      const status = r.Status || r.status || '';
      return status === 'pending' || status === 'changes_requested';
    });
    changesRequestedLearningCount.value = pending.filter(r => (r.Status || r.status || '') === 'changes_requested').length;
    const missingToday = todayAllSessions.value.filter(s => {
      const st = String(s?.status || '').toLowerCase();
      return (st === 'scheduled' || st === 'attended') &&
        String(s?.session_date || '').slice(0, 10) === today &&
        (!s?.learning_record_id || s.learning_record_status === 'missing');
    });
    todayOnlyLearningCount.value = pending.length + missingToday.length;
  } catch { /* ignore */ } finally {
    loadingLearning.value = false;
  }
}

const learningProgressLoading = ref(false);
const learningProgress = ref({
  summary: {
    expected_sessions: 0,
    completed_sessions: 0,
    completion_rate_pct: 0,
    today_expected_sessions: 0,
    today_completed_sessions: 0,
    today_completion_rate_pct: 0,
    streak_days: 0,
  },
  by_day: [],
});

const progressSummary = computed(() => learningProgress.value?.summary || {
  expected_sessions: 0,
  completed_sessions: 0,
  completion_rate_pct: 0,
  today_expected_sessions: 0,
  today_completed_sessions: 0,
  today_completion_rate_pct: 0,
  streak_days: 0,
});

const progressTrendText = computed(() => {
  const byDay = Array.isArray(learningProgress.value?.by_day) ? learningProgress.value.by_day : [];
  if (!byDay.length) return '近 7 天尚無已到班紀錄';
  return byDay.slice(-7).map((d) => {
    const dateLabel = String(d.date || '').slice(5);
    return `${dateLabel} ${d.completed_sessions || 0}/${d.expected_sessions || 0}`;
  }).join('｜');
});

const todayTaskCount = computed(() => todayPendingEvents.value.length);
const overdueTaskCount = computed(() => overdueCount.value);
const changesRequestedTaskCount = computed(() => changesRequestedLearningCount.value);

const topPriorityItems = computed(() => {
  const items = [];
  if (overdueTaskCount.value > 0) {
    items.push({
      id: 'teacher-overdue',
      title: `優先補填逾期評量（${overdueTaskCount.value}）`,
      description: '先處理逾期內容，避免待辦持續累積',
      onClick: openOverdueTask,
      score: 100,
      status: 'overdue',
      owner: 'teacher',
      dueAt: localTodayYmd(),
    });
  }
  if (changesRequestedTaskCount.value > 0) {
    items.push({
      id: 'teacher-changes',
      title: `處理需修改評量（${changesRequestedTaskCount.value}）`,
      description: '回覆主任修改建議，避免反覆退回',
      onClick: openChangesRequestedTask,
      score: 90,
      status: 'pending',
      owner: 'teacher',
      dueAt: localTodayYmd(),
    });
  }
  if (todayTaskCount.value > 0) {
    items.push({
      id: 'teacher-today-learning',
      title: `完成今日待填評量（${todayTaskCount.value}）`,
      description: '把今天課程評量一次完成',
      onClick: fillNextPendingLearning,
      score: 80,
      status: 'pending',
      owner: 'teacher',
      dueAt: localTodayYmd(),
    });
  }
  if (pendingAttendanceCount.value > 0) {
    items.push({
      id: 'teacher-attendance',
      title: `完成待點名課程（${pendingAttendanceCount.value}）`,
      description: '先完成點名，避免課程狀態延遲',
      onClick: goAttendance,
      score: 70,
      status: 'pending',
      owner: 'teacher',
      dueAt: localTodayYmd(),
    });
  }
  if (items.length === 0) {
    items.push({
      id: 'teacher-all-clear',
      title: '今天重點任務已完成',
      description: '可查看班級行事曆或科目數進度',
      onClick: () => emit('navigate', 'calendar'),
      score: 0,
      status: 'done',
      owner: 'teacher',
      dueAt: localTodayYmd(),
    });
  }
  return items;
});

const teacherTodoCards = computed(() =>
  sortTodoCards(topPriorityItems.value)
    .slice(0, 3)
    .map((item, idx) => ({
      ...item,
      rank: item.status === 'done' ? '✓' : String(idx + 1),
      acknowledged: isTodoAcknowledged(item.id),
    })));

function handleTodoCardClick(item) {
  if (!item) return;
  markTodoAcknowledged(item.id);
  trackAdoptionEvent('todo_card_clicked', props.branchId, {
    todo_id: item.id,
    status: item.status,
    owner: item.owner,
  });
  item.onClick?.();
}

async function fetchLearningProgress() {
  learningProgressLoading.value = true;
  try {
    const token = await getToken();
    if (!token) return;
    const params = new URLSearchParams({ days: '7' });
    if (props.branchId) params.set('branch_id', String(props.branchId));
    const res = await fetch(`/api/v1/me/learning-progress-summary?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) return;
    const json = await res.json();
    learningProgress.value = {
      summary: json?.summary || learningProgress.value.summary,
      by_day: Array.isArray(json?.by_day) ? json.by_day : [],
    };
  } catch {
    // keep previous snapshot to avoid flicker
  } finally {
    learningProgressLoading.value = false;
  }
}

async function fetchOverdueLearning() {
  loadingOverdue.value = true;
  try {
    const token = await getToken();
    if (!token) return;

    const today = new Date();
    const sevenDaysAgo = new Date(today);
    sevenDaysAgo.setDate(today.getDate() - 7);
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    const startStr = formatDateUtil(sevenDaysAgo);
    const endStr = formatDateUtil(yesterday);

    const branchIds = props.teacherBranchIds.length > 0
      ? props.teacherBranchIds.map(Number).filter(id => id > 0)
      : (props.branchId ? [Number(props.branchId)] : []);

    let allItems = [];

    if (branchIds.length === 0) {
      const result = await fetchClassSessions({ token, start: startStr, end: endStr, perPage: 200 });
      allItems = result.items || [];
    } else {
      const results = await Promise.allSettled(
        branchIds.map(bid =>
          fetchClassSessions({ token, branchId: bid, start: startStr, end: endStr, perPage: 200 })
        )
      );
      const seenIds = new Set();
      results.forEach(r => {
        if (r.status === 'fulfilled') {
          for (const item of (r.value.items || [])) {
            if (!seenIds.has(item.id)) {
              seenIds.add(item.id);
              allItems.push(item);
            }
          }
        }
      });
    }

    const missing = allItems.filter(s => {
      const st = String(s.status || '').toLowerCase();
      const lr = String(s.learning_record_status || 'missing').toLowerCase();
      return st === 'attended' && lr === 'missing';
    });

    missing.sort((a, b) => b.session_date.localeCompare(a.session_date) || b.start_time.localeCompare(a.start_time));
    overdueRecords.value = missing;
  } catch { /* silent */ } finally {
    loadingOverdue.value = false;
  }
}

function formatDateUtil(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function overdueDateLabel(dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  const dayNames_ = ['日', '一', '二', '三', '四', '五', '六'];
  return `${d.getMonth() + 1}/${d.getDate()} 週${dayNames_[d.getDay()]}`;
}

// ── Cross-branch hint for today ──
const otherBranchTodayCount = computed(() => {
  if (!props.branchId || props.teacherBranchIds.length <= 1) return 0;
  const today = localTodayYmd();
  const currentBid = Number(props.branchId);
  return todayAllSessions.value.filter(s => {
    const bid = Number(s?.branch_id || s?.CampusID || 0);
    const st = String(s?.status || '').toLowerCase();
    return bid > 0 && bid !== currentBid && String(s?.session_date || '').slice(0, 10) === today && st !== 'cancelled';
  }).length;
});

// ── Weekly schedule (merged across ALL teacher branches) ──
const weekOffset = ref(0);
const loadingWeek = ref(true);
const weekLoadError = ref('');
const weekSessions = ref([]);
let weekAbort = null;

const dayNames = ['日', '一', '二', '三', '四', '五', '六'];

function getWeekStart(offset = 0) {
  const d = new Date();
  const day = d.getDay();
  const monday = new Date(d);
  monday.setDate(d.getDate() - ((day + 6) % 7) + offset * 7);
  monday.setHours(0, 0, 0, 0);
  return monday;
}

function formatDate(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const weekStart = computed(() => getWeekStart(weekOffset.value));
const weekEnd = computed(() => {
  const d = new Date(weekStart.value);
  d.setDate(d.getDate() + 6);
  return d;
});
const weekLabel = computed(() => {
  const s = weekStart.value;
  const e = weekEnd.value;
  return `${s.getMonth() + 1}/${s.getDate()} – ${e.getMonth() + 1}/${e.getDate()}`;
});

const allBranchNames = computed(() => {
  const ids = props.teacherBranchIds;
  if (!ids || ids.length === 0) return [];
  return ids.map(id => getBranchName(Number(id))).filter(Boolean);
});

async function loadWeekSchedule() {
  if (weekAbort) weekAbort.abort();
  weekAbort = new AbortController();
  loadingWeek.value = true;
  weekLoadError.value = '';
  weekSessions.value = [];

  const token = await getToken();
  if (!token) { loadingWeek.value = false; return; }

  const startStr = formatDate(weekStart.value);
  const endStr = formatDate(weekEnd.value);
  const branchIds = props.teacherBranchIds.length > 0
    ? props.teacherBranchIds.map(Number).filter(id => id > 0)
    : (props.branchId ? [Number(props.branchId)] : []);

  if (branchIds.length === 0) {
    try {
      const result = await fetchClassSessions({ token, start: startStr, end: endStr, perPage: 500 });
      weekSessions.value = result.items || [];
    } catch (e) {
      weekLoadError.value = '無法載入課表';
    }
    loadingWeek.value = false;
    return;
  }

  const results = await Promise.allSettled(
    branchIds.map(bid =>
      fetchClassSessions({ token, branchId: bid, start: startStr, end: endStr, perPage: 500 })
    )
  );

  const merged = [];
  const seenIds = new Set();
  let anySuccess = false;
  const failedBranches = [];

  results.forEach((r, i) => {
    if (r.status === 'fulfilled') {
      anySuccess = true;
      for (const item of (r.value.items || [])) {
        if (!seenIds.has(item.id)) {
          seenIds.add(item.id);
          merged.push(item);
        }
      }
    } else {
      failedBranches.push(getBranchName(branchIds[i]));
    }
  });

  if (!anySuccess) {
    weekLoadError.value = '無法載入課表，請稍後重試';
  } else if (failedBranches.length > 0) {
    weekLoadError.value = `${failedBranches.join('、')} 載入失敗，其餘分校已顯示`;
  }

  merged.sort((a, b) => {
    if (a.session_date !== b.session_date) return a.session_date.localeCompare(b.session_date);
    if (a.start_time !== b.start_time) return a.start_time.localeCompare(b.start_time);
    return (a.branch_id || 0) - (b.branch_id || 0);
  });

  weekSessions.value = merged;
  loadingWeek.value = false;
}

const todayStr = computed(() => localTodayYmd());

const weekDays = computed(() => {
  const days = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(weekStart.value);
    d.setDate(weekStart.value.getDate() + i);
    const dateStr = formatDate(d);
    const events = weekSessions.value
      .filter(s => s.session_date === dateStr && String(s.status || '').toLowerCase() !== 'cancelled')
      .map(s => {
        const status = String(s.status || '').toLowerCase();
        const isLeave = status === 'leave' || status === 'leave_adjusted' || status === 'excused';
        return {
          key: `${s.id}-${s.branch_id}`,
          id: s.id,
          studentClassId: s.student_class_id,
          studentName: s.student_name || '—',
          subject: s.teacher_name ? `${s.teacher_name}` : '—',
          date: s.session_date || dateStr,
          startTime: s.start_time || '—',
          endTime: s.end_time || '',
          branchId: s.branch_id || 0,
          status: s.status,
          formStatus: isLeave ? 'leave' : (s.learning_record_status || 'missing'),
          recordId: s.learning_record_id || null,
        };
      });
    days.push({
      date: dateStr,
      label: `週${dayNames[d.getDay()]}`,
      shortDate: `${d.getMonth() + 1}/${d.getDate()}`,
      isToday: dateStr === todayStr.value,
      events,
    });
  }
  return days;
});

const todayPendingEvents = computed(() => {
  const today = weekDays.value.find(d => d.isToday);
  if (!today) return [];
  return today.events
    .filter(ev => ev.formStatus === 'missing' || ev.formStatus === 'changes_requested')
    .sort((a, b) => (a.startTime || '').localeCompare(b.startTime || ''));
});

// ── Branch colors and short names ──
const BRANCH_COLORS = [
  'rgba(230, 81, 0, 0.85)',
  'rgba(21, 101, 192, 0.85)',
  'rgba(46, 125, 50, 0.85)',
  'rgba(142, 36, 170, 0.85)',
  'rgba(0, 121, 107, 0.85)',
  'rgba(198, 40, 40, 0.85)',
  'rgba(245, 127, 23, 0.85)',
  'rgba(55, 71, 79, 0.85)',
];

function branchColor(branchId) {
  const idx = branches.value.findIndex(b => b.id === Number(branchId));
  return BRANCH_COLORS[idx >= 0 ? idx % BRANCH_COLORS.length : 0];
}

function branchShortName(branchId) {
  const name = getBranchName(Number(branchId));
  return name.replace(/分校$/, '').replace(/校區$/, '');
}

function formStatusLabel(status) {
  const map = { pending: '待審', approved: '已核准', rejected: '退回', changes_requested: '需修改', missing: '', substituted: '代課', leave: '請假' };
  return map[status] || status;
}

// ── Navigation ──
function goAttendance() {
  emit('navigate', 'attendance');
}

function goLearning() {
  emit('navigate-learning', { listOnly: true });
}

/** 過往優先於今日待填；無明確堂次時只開評量列表（不帶錨點）。 */
function fillNextPendingLearning() {
  const o = overdueRecords.value[0];
  if (o) {
    goFillRecord({
      branchId: o.branch_id,
      recordId: null,
      classSessionId: o.id,
      sessionDate: o.session_date,
    });
    return;
  }
  const t = todayPendingEvents.value[0];
  if (t) {
    goFillRecord({
      branchId: t.branchId,
      recordId: t.recordId || null,
      classSessionId: t.id,
      sessionDate: t.date,
    });
    return;
  }
  goLearning();
}

function openOverdueTask() {
  const o = overdueRecords.value[0];
  if (o) {
    goFillRecord({
      branchId: o.branch_id,
      recordId: null,
      classSessionId: o.id,
      sessionDate: o.session_date,
    });
    return;
  }
  goLearning();
}

function openChangesRequestedTask() {
  goLearning();
}

function goFillRecord(ev) {
  if (ev.branchId && Number(ev.branchId) !== Number(props.branchId)) {
    localStorage.setItem('app_branch', String(ev.branchId));
  }
  emit('navigate-learning', {
    branchId: ev.branchId || null,
    recordId: ev.recordId || null,
    classSessionId: ev.classSessionId || null,
    sessionDate: ev.sessionDate || null,
  });
}

// ── Lifecycle ──
async function refreshAll() {
  refreshing.value = true;
  await Promise.all([fetchPendingAttendance(), fetchOverdueLearning(), fetchPendingLearning(), loadWeekSchedule(), fetchClockinStatus(), fetchLearningProgress()]);
  refreshing.value = false;
}

let pollTimer = null;
const POLL_INTERVAL = 60000;

function startPolling() {
  stopPolling();
  pollTimer = setInterval(() => {
    if (document.visibilityState === 'visible') {
      fetchPendingAttendance();
      fetchOverdueLearning();
      fetchPendingLearning();
      fetchLearningProgress();
    }
  }, POLL_INTERVAL);
}

function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

function onVisibilityChange() {
  if (document.visibilityState === 'visible') {
    fetchPendingAttendance();
    fetchOverdueLearning();
    fetchPendingLearning();
    fetchLearningProgress();
  }
}

function onLearningProgressRefreshEvent() {
  fetchLearningProgress();
}

// ── Report discrepancy helpers ──
async function refreshActiveReport(sessionId) {
  if (!sessionId) return;
  try {
    const result = await fetchActiveForSession(sessionId);
    activeReportMap.value = { ...activeReportMap.value, [sessionId]: result?.discrepancy ?? null };
  } catch {
    activeReportMap.value = { ...activeReportMap.value, [sessionId]: null };
  }
}

async function openReport(ev) {
  const sessionId = ev.id;
  reportModalSession.value = {
    sessionId,
    date: ev.date,
    time: `${ev.startTime}~${ev.endTime}`,
    subject: ev.subject,
    student: ev.studentName,
    branchId: ev.branchId,
  };

  // Lazy-load active report if not yet cached
  if (activeReportMap.value[sessionId] === undefined) {
    reportFetching.value = true;
    try {
      const result = await fetchActiveForSession(sessionId);
      activeReportMap.value = { ...activeReportMap.value, [sessionId]: result?.discrepancy ?? null };
    } catch {
      activeReportMap.value = { ...activeReportMap.value, [sessionId]: null };
    } finally {
      reportFetching.value = false;
    }
  }

  reportModalExisting.value = activeReportMap.value[sessionId] ?? null;
  reportModalOpen.value = true;
}

function handleReportSubmitted() {
  const sessionId = reportModalSession.value?.sessionId;
  reportModalOpen.value = false;
  if (sessionId) refreshActiveReport(sessionId);
}

function handleReportWithdrawn() {
  const sessionId = reportModalSession.value?.sessionId;
  reportModalOpen.value = false;
  if (sessionId) refreshActiveReport(sessionId);
}

onMounted(() => {
  trackAdoptionEvent('dashboard_opened', props.branchId, { role: 'teacher', page: 'teacher-home' });
  Promise.all([fetchPendingAttendance(), fetchOverdueLearning(), fetchPendingLearning(), loadWeekSchedule(), fetchChatUnread(), fetchClockinStatus(), fetchLearningProgress()]);
  startPolling();
  document.addEventListener('visibilitychange', onVisibilityChange);
  window.addEventListener('alltrue-teacher-learning-progress-refresh', onLearningProgressRefreshEvent);
  // Refresh chat badge when app emits the badge refresh event
  window.addEventListener('alltrue-refresh-badges', fetchChatUnread);
});

watch(weekOffset, () => loadWeekSchedule());
watch(() => props.branchId, () => {
  fetchPendingAttendance();
  fetchOverdueLearning();
  fetchPendingLearning();
  fetchLearningProgress();
});
watch(() => props.teacherBranchIds, () => loadWeekSchedule(), { deep: true });

onBeforeUnmount(() => {
  if (weekAbort) weekAbort.abort();
  stopPolling();
  document.removeEventListener('visibilitychange', onVisibilityChange);
  window.removeEventListener('alltrue-teacher-learning-progress-refresh', onLearningProgressRefreshEvent);
  window.removeEventListener('alltrue-refresh-badges', fetchChatUnread);
});
</script>

<style scoped>
/* ──────── Page Layout ──────── */
.th-page { max-width: 720px; margin: 0 auto; padding-bottom: 80px; }

.th-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.th-header-actions { display: flex; gap: 8px; }

/* ──────── Section Titles ──────── */
.th-section-title {
  display: flex; align-items: center; gap: 6px;
  font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 12px;
}
.th-section-icon { font-size: 20px; color: var(--accent); }

/* ──────── A. Today Actions ──────── */
.th-today { padding: 20px; }
.th-actions { display: flex; flex-direction: column; gap: 10px; }
.th-task-board,
.th-progress-board,
.th-priority-board {
  margin-top: 10px;
  padding: 10px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--card-bg);
}
.th-task-board-head,
.th-progress-head,
.th-priority-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
  font-size: 13px;
  color: var(--text-light);
  font-weight: 600;
}
.th-task-items {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.th-priority-items {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.th-priority-item {
  width: 100%;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: transparent;
  padding: 8px 10px;
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
}

.th-priority-rank {
  width: 24px;
  height: 24px;
  border-radius: 999px;
  background: var(--primary-bg);
  color: var(--primary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 700;
  flex-shrink: 0;
}

.th-priority-body {
  flex: 1;
  min-width: 0;
  text-align: left;
}

.th-priority-title {
  display: block;
  font-size: 13px;
  color: var(--text);
  font-weight: 600;
}

.th-priority-desc {
  display: block;
  margin-top: 2px;
  font-size: 12px;
  color: var(--text-light);
}
.th-task-item {
  border: 1px solid var(--border);
  border-radius: 8px;
  background: transparent;
  padding: 8px;
  text-align: left;
  cursor: pointer;
}
.th-task-item-label {
  display: block;
  font-size: 12px;
  color: var(--text-light);
}
.th-task-item-count {
  display: block;
  margin-top: 2px;
  font-size: 18px;
  font-weight: 700;
}
.th-task-item-count.warn { color: var(--warning); }
.th-task-item-count.ok { color: var(--success); }

.th-progress-main {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: baseline;
}
.th-progress-main strong {
  font-size: 20px;
  color: var(--primary);
}
.th-progress-streak {
  font-size: 12px;
  color: var(--text-light);
}
.th-progress-trend {
  margin-top: 6px;
  font-size: 12px;
  color: var(--text-light);
}
.th-progress-board.loading .th-progress-main,
.th-progress-board.loading .th-progress-trend {
  opacity: 0.6;
}

.th-action-btn {
  display: flex; align-items: center; gap: 14px;
  width: 100%; padding: 16px 14px; border-radius: 12px;
  border: 1.5px solid var(--border); background: var(--card-bg);
  cursor: pointer; transition: var(--transition); text-align: left;
}
.th-action-btn:hover { border-color: var(--accent); box-shadow: 0 2px 8px rgba(255,167,38,0.12); }
.th-action-btn:active { transform: scale(0.99); }

.th-action-icon-wrap {
  width: 44px; height: 44px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.th-action-attendance .th-action-icon-wrap { background: var(--primary-bg); color: var(--primary); }
.th-action-learning .th-action-icon-wrap { background: #e3f2fd; color: #1565c0; }
[data-theme="dark"] .th-action-learning .th-action-icon-wrap { background: #1a237e; color: #90caf9; }
.th-action-feedback .th-action-icon-wrap { background: #fff3e0; color: #ef6c00; }
[data-theme="dark"] .th-action-feedback .th-action-icon-wrap { background: #3b2612; color: #ffb74d; }
.th-done .th-action-icon-wrap { background: var(--success-bg); color: var(--success); }

.th-action-body { flex: 1; min-width: 0; }
.th-action-label { font-size: 15px; font-weight: 600; color: var(--text); }
.th-action-label strong { font-size: 18px; color: var(--primary); }
.th-done .th-action-label strong { color: var(--success); }
.th-action-hint { font-size: 12px; color: var(--text-light); margin-top: 2px; }
.th-action-hint--split {
  display: flex; flex-wrap: wrap; align-items: center;
  gap: 6px 10px;
}
.th-inline-link {
  color: var(--accent); font-weight: 600; text-decoration: underline;
  cursor: pointer; flex-shrink: 0;
}
.th-action-arrow { color: var(--text-light); font-size: 22px; flex-shrink: 0; }

.th-cross-hint {
  display: flex; align-items: center; gap: 6px;
  margin-top: 12px; padding: 8px 12px; border-radius: 8px;
  background: var(--warning-bg); color: var(--warning);
  font-size: 13px; font-weight: 500;
}

.th-today-pending {
  margin-top: 12px;
  padding: 10px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--card-bg);
}

.th-today-pending-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
  font-size: 13px;
  font-weight: 700;
  color: var(--text);
}

/* ──────── A2. Overdue Reminder ──────── */
.th-overdue {
  padding: 16px 20px;
  border-left: 4px solid var(--accent);
}
.th-overdue-icon { color: var(--accent) !important; }
.th-overdue-badge {
  font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px;
  background: var(--accent); color: #fff; margin-left: 4px;
}
.th-overdue-hint {
  font-size: 12px; font-weight: 400; color: var(--text-light); margin-left: 2px;
}
.th-overdue-list { display: flex; flex-direction: column; gap: 6px; }
.th-overdue-row {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 10px; border-radius: 10px;
  border: 1px solid var(--border); background: var(--card-bg);
  transition: var(--transition);
}
.th-overdue-row:hover { border-color: var(--accent); box-shadow: 0 1px 6px rgba(255,167,38,0.10); }
.th-overdue-date {
  font-size: 13px; font-weight: 600; color: var(--accent);
  min-width: 72px; white-space: nowrap; font-variant-numeric: tabular-nums;
}
.th-overdue-info { flex: 1; min-width: 0; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.th-overdue-student { font-size: 14px; font-weight: 600; color: var(--text); }
.th-overdue-subject { font-size: 12px; color: var(--text-light); }
.th-overdue-more {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  width: 100%; margin-top: 10px; padding: 8px 0;
  border: none; border-radius: 8px;
  background: rgba(255,167,38,0.08); color: var(--accent);
  font-size: 13px; font-weight: 600; cursor: pointer;
  transition: var(--transition);
}
.th-overdue-more:hover { background: rgba(255,167,38,0.16); }

/* ──────── B. Weekly Schedule ──────── */
.th-week { padding: 20px; }
.th-week-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 4px; }
.th-week-nav { display: flex; align-items: center; gap: 4px; }
.th-week-label { font-size: 14px; font-weight: 600; color: var(--text); min-width: 100px; text-align: center; }
.th-week-badge {
  font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;
  background: var(--accent); color: #fff; margin-left: 6px;
}

.th-loading, .th-empty, .th-error {
  padding: 24px 0; text-align: center; color: var(--text-light); font-size: 14px;
}
.th-error { color: var(--danger); display: flex; flex-direction: column; align-items: center; gap: 8px; }
.th-skeleton {
  height: 52px; border-radius: 10px; background: var(--border); opacity: 0.4;
  margin-bottom: 8px; animation: th-pulse 1.2s ease-in-out infinite;
}
@keyframes th-pulse { 0%, 100% { opacity: 0.3; } 50% { opacity: 0.6; } }

/* Day accordion */
.th-days { display: flex; flex-direction: column; gap: 2px; }
.th-day { border-radius: 10px; overflow: hidden; }
.th-day-summary {
  display: flex; align-items: center; gap: 8px; padding: 10px 12px;
  cursor: pointer; user-select: none; font-size: 14px; font-weight: 600;
  color: var(--text); list-style: none; border-radius: 10px;
  transition: background 0.15s;
}
.th-day-summary:hover { background: rgba(0,0,0,0.03); }
[data-theme="dark"] .th-day-summary:hover { background: rgba(255,255,255,0.04); }
.th-day-summary::-webkit-details-marker { display: none; }

.th-day-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--border); flex-shrink: 0; }
.th-day-dot-today { background: var(--accent); box-shadow: 0 0 0 3px rgba(255,167,38,0.25); }
.th-day-label { min-width: 36px; }
.th-day-short-date { color: var(--text-light); font-weight: 500; }
.th-today-tag {
  font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 6px;
  background: var(--accent); color: #fff;
}
.th-day-count { margin-left: auto; font-size: 12px; color: var(--text-light); font-weight: 500; }

.th-day-today { background: rgba(255,167,38,0.04); }
[data-theme="dark"] .th-day-today { background: rgba(255,167,38,0.06); }

.th-day-empty { padding: 8px 20px; font-size: 13px; color: var(--text-light); }

/* Event row */
.th-day-events { padding: 0 4px 8px; }
.th-event {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 10px; border-radius: 10px; margin-bottom: 4px;
  border: 1px solid transparent; transition: var(--transition);
}
.th-event:hover { background: rgba(0,0,0,0.02); border-color: var(--border); }
[data-theme="dark"] .th-event:hover { background: rgba(255,255,255,0.03); }

.th-event-time {
  font-size: 13px; font-weight: 600; color: var(--text-light);
  min-width: 44px; text-align: center; line-height: 1.4;
  font-variant-numeric: tabular-nums;
}
.th-event-info { flex: 1; min-width: 0; }
.th-event-student { font-size: 14px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.th-event-meta { display: flex; align-items: center; gap: 6px; margin-top: 2px; flex-wrap: wrap; }
.th-event-subject { font-size: 12px; color: var(--text-light); }

.th-branch-chip {
  font-size: 11px; font-weight: 600; padding: 1px 7px; border-radius: 6px;
  color: #fff; white-space: nowrap;
}

.th-form-chip {
  font-size: 11px; font-weight: 600; padding: 1px 7px; border-radius: 6px;
}
.th-form-pending { background: var(--warning-bg); color: var(--warning); }
.th-form-approved { background: var(--success-bg); color: var(--success); }
.th-form-changes_requested { background: var(--danger-bg); color: var(--danger); }
.th-form-rejected { background: var(--danger-bg); color: var(--danger); }
.th-form-substituted { background: #e0e0e0; color: #757575; }
.th-form-leave { background: #fff7ed; color: #c2410c; }
[data-theme="dark"] .th-form-substituted { background: #424242; color: #bdbdbd; }
[data-theme="dark"] .th-form-leave { background: rgba(194,65,12,0.18); color: #fdba74; }

.th-event-done { opacity: 0.7; }
.th-event-leave {
  border-left-color: #f97316;
  background: linear-gradient(90deg, rgba(249,115,22,0.10), transparent 55%);
}

.th-fill-btn {
  background: var(--primary-bg); border: none; border-radius: 8px;
  width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: var(--primary); transition: var(--transition); flex-shrink: 0;
}
.th-fill-btn:hover { background: var(--accent); color: #fff; }

.th-report-btn {
  background: transparent; border: none; border-radius: 8px;
  width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: var(--text-light); transition: var(--transition); flex-shrink: 0;
  font-size: 20px;
}
.th-report-btn:hover { background: #fef2f2; color: #ef4444; }
.th-report-btn--active { color: #ef4444; }
.th-report-btn--active:hover { background: #fee2e2; }
.th-report-loading { animation: spin-once 0.8s linear infinite; font-size: 18px; }
@keyframes spin-once { to { transform: rotate(360deg); } }

.th-check-icon { color: var(--success); font-size: 22px; flex-shrink: 0; }

/* ──────── C. Quick Links ──────── */
.th-links {
  display: flex; gap: 10px; padding: 16px;
}
.th-link-btn {
  flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;
  padding: 14px 8px; border-radius: 12px; border: 1.5px solid var(--border);
  background: var(--card-bg); cursor: pointer; transition: var(--transition);
  font-size: 13px; font-weight: 600; color: var(--text);
}
.th-link-btn:hover { border-color: var(--accent); }
.th-link-btn .material-symbols-outlined { font-size: 26px; color: var(--accent); }
.th-link-sub { font-size: 11px; color: var(--text-light); font-weight: 400; }

/* Chat entry card */
.th-chat-btn { border-color: var(--primary, #1976d2); }
.th-chat-btn:hover { border-color: var(--primary, #1976d2); background: rgba(25,118,210,0.05); box-shadow: 0 2px 8px rgba(25,118,210,0.10); }
.th-chat-badge {
  position: absolute; top: 6px; right: 8px;
  min-width: 20px; height: 20px; padding: 0 5px;
  border-radius: 10px; background: var(--danger, #e53935);
  color: #fff; font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}
.th-chat-badge-skeleton {
  position: absolute; top: 6px; right: 8px;
  width: 20px; height: 20px; border-radius: 10px;
  background: var(--border); animation: skeleton-pulse 1.2s infinite;
}

/* ──────── Responsive ──────── */
@media (max-width: 480px) {
  .th-page { padding-bottom: 100px; }
  .th-today, .th-week, .th-links, .th-overdue { padding: 14px; }
  .th-action-btn { padding: 14px 10px; gap: 10px; }
  .th-action-icon-wrap { width: 38px; height: 38px; }
  .th-action-label { font-size: 14px; }
  .th-task-items { grid-template-columns: 1fr; }
  .th-event { padding: 8px 6px; gap: 8px; }
  .th-event-time { font-size: 12px; min-width: 38px; }
  .th-event-student { font-size: 13px; }
  .th-week-label { font-size: 13px; min-width: 90px; }
}

@media (min-width: 640px) {
  .th-actions { flex-direction: row; }
  .th-action-btn { flex: 1; }
}

.icon-btn {
  width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
  padding: 0; font-size: 18px; font-weight: 700; border-radius: 8px;
}

/* ──────── Clock-in Card ──────── */
.th-clockin-card {
  display: flex; flex-direction: column; gap: 10px;
  padding: 14px 16px; cursor: pointer; min-height: 72px;
  border: 1px solid var(--border); border-left-width: 4px; border-radius: 12px;
  transition: background 0.15s, transform 0.1s;
  margin-bottom: 12px;
}
.th-clockin-card:hover  { background: var(--bg-hover, #f5f5f5); }
.th-clockin-card:active { transform: scale(0.99); }
.th-clockin-card:focus-visible { outline: 2px solid var(--primary); }

/* Status: left border colour */
.th-ckin-empty   { border-left-color: var(--border); }
.th-ckin-working { border-left-color: var(--primary); }
.th-ckin-done    { border-left-color: var(--success); }
.th-ckin-late    { border-left-color: #c62828; }

/* Header row */
.th-clockin-header {
  display: flex; align-items: center; gap: 10px;
}
.th-clockin-icon-wrap {
  width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
}
.th-icon-empty   { background: var(--bg, #f5f5f5); color: var(--text-light); }
.th-icon-working { background: var(--primary-bg); color: var(--primary); }
.th-icon-done    { background: var(--success-bg); color: var(--success); }
.th-icon-late    { background: #fce8e6; color: #c62828; }
[data-theme="dark"] .th-icon-late { background: #3b0c0c; color: #ef9a9a; }

.th-clockin-title-group {
  flex: 1; min-width: 0;
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.th-clockin-title  { font-weight: 600; font-size: 15px; white-space: nowrap; }
.th-clockin-arrow  { color: var(--text-light); font-size: 22px; flex-shrink: 0; margin-left: auto; }

.th-clockin-badge {
  padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 600;
}
.th-badge-ok   { background: var(--success-bg); color: var(--success); }
.th-badge-warn { background: var(--primary-bg);  color: var(--primary); }
.th-badge-late { background: #fce8e6; color: #c62828; }
[data-theme="dark"] .th-badge-late { background: #3b0c0c; color: #ef9a9a; }

/* Two chips row */
.th-clockin-chips {
  display: flex; gap: 8px;
}
.th-clockin-chip {
  flex: 1; min-width: 0;
  display: flex; flex-direction: column; gap: 3px;
  padding: 10px 12px; border-radius: 10px; min-height: 44px;
  border: 1px solid var(--border); background: var(--card-bg, #fff);
}
.th-chip-label { font-size: 11px; color: var(--text-light); font-weight: 500; }
.th-chip-val   { font-size: 16px; font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; }
.th-chip-empty { color: var(--text-light); font-weight: 400; }
.th-chip-warn  { color: var(--primary); font-size: 13px; font-weight: 600; }

/* Skeleton animation */
.th-chip-skeleton {
  min-height: 44px; border: none;
  background: var(--border); border-radius: 10px;
  animation: skeleton-pulse 1.2s ease-in-out infinite;
}

/* Hint row */
.th-clockin-hint {
  font-size: 12px; color: var(--text-light);
  display: flex; align-items: center; gap: 4px;
}
</style>
