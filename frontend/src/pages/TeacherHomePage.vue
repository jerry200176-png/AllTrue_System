<template>
  <div class="th-page">
    <!-- Page Header -->
    <AtPageHeader
      title="教學工作台"
      description="今日待辦一覽、本週跨分校課表。"
      icon="today"
      data-guide="teacher-home-header"
    >
      <template #meta>
        <span v-if="streakChipVisible" class="th-streak-chip" role="status">
          <span class="material-symbols-outlined th-streak-icon" aria-hidden="true">local_fire_department</span>
          連續使用 <strong>{{ streakCurrent }}</strong> 天
          <span v-if="streakLongest > streakCurrent" class="th-streak-longest">（累積最高 {{ streakLongest }}）</span>
        </span>
        <span v-if="engagementChipVisible" class="th-engagement-chip" role="status">
          <EngagementRankStrip :engagement="effectiveEngagement" :reduced-motion="engagementReducedMotion" />
        </span>
      </template>
      <template #actions>
        <AtButton variant="ghost" shape="rect" icon="refresh" :loading="refreshing" @click="refreshAll">重新整理</AtButton>
      </template>
    </AtPageHeader>

    <!-- A small brand moment: warm and encouraging, without competing with the
      operational queue below. The illustration is decorative; all action copy
      remains available as real text and a keyboard-focusable link. -->
    <section class="th-companion" data-guide="teacher-home-companion" aria-labelledby="teacher-companion-title">
      <div class="th-companion__copy">
        <p class="th-companion__eyebrow">今天的節奏</p>
        <h3 id="teacher-companion-title">{{ teacherTasksLoading ? '先準備今天的課務' : (teacherTasksError ? '今天的工作需要重新整理' : (teacherTasks.length ? '先完成最重要的一件事' : '今天的課務完成了')) }}</h3>
        <p class="th-companion__description">
          {{ teacherTasksLoading ? '正在整理今天的任務，等一下就會顯示。' : (teacherTasksError ? '部分工作資料暫時無法載入，請重新整理後再開始處理。' : (teacherTasks.length ? `還有 ${teacherTaskCount} 項工作，完成一項就更接近下課。` : '可以放心查看本週課表，準備下一堂課。')) }}
        </p>
        <button v-if="teacherTasksError" type="button" class="th-companion__action" :disabled="refreshing" @click="refreshAll">
          <span>{{ refreshing ? '重新整理中…' : '重新整理今日任務' }}</span>
          <span class="material-symbols-outlined" aria-hidden="true">refresh</span>
        </button>
        <a v-else class="th-companion__action" href="#teacher-work-queue-title" @click="focusTeacherWorkQueue">
          <span>{{ teacherTasks.length || teacherTasksLoading ? '查看今日任務' : '查看今日摘要' }}</span>
          <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
        </a>
      </div>
      <div class="th-companion__art" aria-hidden="true">
        <span class="th-companion__spark th-companion__spark--one">✦</span>
        <span class="th-companion__spark th-companion__spark--two">✦</span>
        <img :src="learningCompanionUrl" alt="" width="180" height="198" fetchpriority="high" />
      </div>
    </section>

    <!-- Clock-in Status Card -->
    <button
      type="button"
      class="th-clockin-card card"
      :class="clockinCardClass"
      @click="goAttendance"
      aria-labelledby="teacher-clockin-title"
      aria-describedby="teacher-clockin-status"
    >

      <!-- Header row: icon + title + badge + arrow -->
      <div class="th-clockin-header">
        <div class="th-clockin-icon-wrap" :class="clockinIconWrapClass" aria-hidden="true">
          <span class="material-symbols-outlined">fingerprint</span>
        </div>
        <div class="th-clockin-title-group">
          <span id="teacher-clockin-title" class="th-clockin-title">今日打卡狀態</span>
          <span id="teacher-clockin-status" class="th-clockin-badge" :class="clockinBadgeClass" aria-live="polite">{{ clockinBadgeLabel }}</span>
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
    </button>

    <!-- Single source of truth for today's work. Secondary metrics stay below the fold. -->
    <section id="teacher-work-queue" class="th-work-queue card" data-guide="teacher-home-work-queue" aria-labelledby="teacher-work-queue-title">
      <div class="th-work-queue__header">
        <div>
          <p class="th-work-queue__eyebrow">今天</p>
          <h3 id="teacher-work-queue-title" tabindex="-1">今天要完成</h3>
          <p class="th-work-queue__description">依照期限與影響排序；完成後會從清單移除。</p>
        </div>
        <span class="th-work-queue__count" aria-live="polite">{{ teacherTasksLoading ? '載入中…' : (teacherTasksError ? '待確認' : `${teacherTaskCount} 項`) }}</span>
      </div>

      <details v-if="!teacherTasksLoading && teacherTasks.length" class="th-priority-disclosure">
        <summary>
          <span>查看排序規則</span>
          <strong>依期限與影響</strong>
          <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
        </summary>
        <section class="th-priority-rules-panel" aria-labelledby="th-priority-rules-title">
          <h4 id="th-priority-rules-title">今天的處理順序</h4>
          <ol class="th-priority-rules">
            <li class="th-priority-rules__item">
              <strong>先處理需修改或逾期</strong>
              <span>先排除會影響後續工作的項目。</span>
            </li>
            <li class="th-priority-rules__item">
              <strong>再處理今天尚未完成</strong>
              <span>包含點名與學習評量。</span>
            </li>
            <li class="th-priority-rules__item">
              <strong>最後查看家長回覆</strong>
              <span>需要回覆的訊息會保留在工作列。</span>
            </li>
          </ol>
        </section>
      </details>

      <div v-if="teacherTasksLoading" class="th-work-queue__list" aria-label="今日工作載入中">
        <div v-for="i in 3" :key="i" class="th-work-task th-work-task--skeleton"></div>
      </div>
      <div v-else-if="teacherTasksError" class="th-work-queue__error" role="alert">
        <span class="material-symbols-outlined" aria-hidden="true">cloud_off</span>
        <div>
          <strong>今天的工作清單尚未完整載入</strong>
          <p>為避免漏掉點名或評量，暫時不把空白清單當成已完成。</p>
        </div>
        <button type="button" class="ghost small" :disabled="refreshing" @click="refreshAll">
          {{ refreshing ? '整理中…' : '重新整理' }}
        </button>
      </div>
      <div v-else-if="teacherTasks.length === 0" class="th-work-queue__empty">
        <span class="material-symbols-outlined" aria-hidden="true">task_alt</span>
        <div>
          <strong>今天沒有待完成工作</strong>
          <p>可以查看本週課表，先準備下一堂課。</p>
        </div>
        <button type="button" class="ghost small" @click="scrollToWeekSchedule">查看本週課表</button>
      </div>
      <div v-else class="th-work-queue__list">
        <div v-if="teacherTasksPartialError" class="th-work-queue__partial-error" role="alert">
          <span class="material-symbols-outlined" aria-hidden="true">info</span>
          <div>
            <strong>部分待辦尚未載入</strong>
            <p>{{ teacherTasksPartialError }}其他工作仍可繼續處理。</p>
          </div>
        </div>
        <article
          class="th-next-action"
          data-guide="teacher-next-action"
          aria-labelledby="teacher-next-action-title"
        >
          <div class="th-next-action__marker" aria-hidden="true">
            <span class="material-symbols-outlined">arrow_forward</span>
          </div>
          <div class="th-next-action__content">
            <p class="th-next-action__eyebrow">現在先做</p>
            <div class="th-next-action__title-row">
              <span class="th-work-task__type">{{ teacherTaskTypeLabel(teacherTasks[0].type) }}</span>
              <h4 id="teacher-next-action-title">{{ teacherTasks[0].title }}</h4>
            </div>
            <p class="th-next-action__summary">{{ teacherTasks[0].summary }}</p>
            <small>期限：{{ teacherTasks[0].dueAt || '今天' }}</small>
          </div>
          <button type="button" class="primary small th-next-action__cta" @click="openTeacherTask(teacherTasks[0])">
            {{ teacherTasks[0].actionLabel }}
          </button>
        </article>

        <div v-if="teacherTasks.length > 1" class="th-work-queue__remaining" data-guide="teacher-secondary-actions">
          <p class="th-work-queue__remaining-label">接著處理</p>
          <article v-for="task in teacherTasks.slice(1)" :key="task.id" class="th-work-task">
            <div class="th-work-task__main">
              <div class="th-work-task__title-row">
                <span class="th-work-task__type">{{ teacherTaskTypeLabel(task.type) }}</span>
                <strong>{{ task.title }}</strong>
              </div>
              <p>{{ task.summary }}</p>
              <small>期限：{{ task.dueAt || '今天' }}</small>
            </div>
            <button type="button" class="ghost small th-work-task__cta" @click="openTeacherTask(task)">
              {{ task.actionLabel }}
            </button>
          </article>
        </div>
      </div>
    </section>


    <!-- B. Weekly Schedule (merged across all branches) -->
    <div class="th-week card" data-guide="teacher-home-week">
      <div class="th-week-header">
        <h3 class="th-section-title">
          <span class="material-symbols-outlined th-section-icon">date_range</span>
          本週課表
          <span class="th-week-badge" v-if="allBranchNames.length > 1">{{ allBranchNames.length }} 校合併</span>
        </h3>
        <div class="th-week-nav">
          <button type="button" class="ghost small icon-btn" @click="weekOffset--" title="上一週" aria-label="上一週">‹</button>
          <span class="th-week-label">{{ weekLabel }}</span>
          <button type="button" class="ghost small icon-btn" @click="weekOffset++" title="下一週" aria-label="下一週">›</button>
        </div>
      </div>

      <div v-if="loadingWeek" class="th-loading">
        <div class="th-skeleton" v-for="i in 3" :key="i"></div>
      </div>
      <div v-else-if="weekLoadError" class="th-error">
        <span class="material-symbols-outlined">error_outline</span>
        {{ weekLoadError }}
        <button type="button" class="ghost small" @click="loadWeekSchedule">重試</button>
      </div>
      <div v-else-if="weekDays.length === 0" class="th-empty">本週無排課</div>

      <div v-else class="th-days">
        <details
          v-for="day in weekDays"
          :key="day.date"
          class="th-day"
          :class="{ 'th-day-today': day.isToday }"
          :open="day.isToday"
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
                  <span v-if="campusIdFrom(ev.branchId)" class="th-branch-chip" :style="{ background: branchColor(ev.branchId) }">{{ branchShortName(ev.branchId) }}</span>
                  <span v-if="ev.formStatus && ev.formStatus !== 'missing'" :class="['th-form-chip', `th-form-${ev.formStatus}`]">{{ formStatusLabel(ev.formStatus) }}</span>
                </div>
              </div>
              <button
                v-if="!ev.isProjected && (ev.formStatus === 'missing' || ev.formStatus === 'changes_requested')"
                type="button"
                class="th-fill-btn"
                @click="goFillRecord(ev)"
                title="填寫評量"
                :aria-label="ev.formStatus === 'changes_requested' ? '開啟需修改的評量' : '開啟待填評量'"
              >
                <span class="material-symbols-outlined">edit_note</span>
              </button>
              <span v-else-if="ev.formStatus === 'approved'" class="th-check-icon material-symbols-outlined">check_circle</span>
              <button
                v-if="!ev.isProjected"
                type="button"
                class="th-report-btn"
                :class="{ 'th-report-btn--active': activeReportMap[ev.id] }"
                :title="activeReportMap[ev.id] ? '查看課表回報' : '回報課表有誤'"
                :aria-label="activeReportMap[ev.id] ? '查看課表回報' : '回報課表有誤'"
                @click.stop="openReport(ev)"
              >
                <span
                  v-if="reportFetching && reportModalSession?.sessionId === ev.id"
                  class="material-symbols-outlined th-report-loading"
                >hourglass_empty</span>
                <span v-else class="material-symbols-outlined">flag</span>
              </button>
              <span v-else class="th-form-chip th-form-pending">待建立堂次</span>
            </div>
          </div>
        </details>
      </div>
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
import learningCompanionUrl from '../assets/alltrue-learning-companion.png';
import { supabase } from '../supabase';
import { branches, campusIdFrom, getBranchName } from '../lib/useBranches';
import { fetchClassSessions, fetchClassSessionsProjection } from '../lib/classSessionsApi';
import { dedupeSessionsByStudentSlot } from '../lib/classSessionPick';
import ReportDiscrepancyModal from '../components/ReportDiscrepancyModal.vue';
import EngagementRankStrip from '../components/EngagementRankStrip.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import {
  isUserEngagementRankDisplayEnabled,
  USER_ENGAGEMENT_DISPLAY_REFRESH_EVENT,
} from '../lib/userEngagementDisplay';
import { fetchActiveForSession } from '../lib/scheduleDiscrepanciesApi.js';
import { trackAdoptionEvent } from '../lib/adoptionTelemetry';
import { buildTeacherTasks, countTeacherTasks } from '../lib/teacherDailyWorkflow.js';
import {
  readTeacherStreak,
  isTeacherStreakDisplayEnabled,
  TEACHER_STREAK_REFRESH_EVENT,
} from '../lib/teacherLoginStreak';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userId: { type: [Number, String], default: null },
  userRole: { type: String, default: '' },
  teacherBranchIds: { type: Array, default: () => [] },
  unreadFeedbackCount: { type: Number, default: 0 },
  initialEngagement: { type: Object, default: null },
});

const emit = defineEmits(['navigate', 'navigate-learning']);

const getToken = async () => {
  const { data: { session } } = await supabase.auth.getSession();
  return session?.access_token;
};

const refreshing = ref(false);
const streakCurrent = ref(0);
const streakLongest = ref(0);
const streakDisplayOn = ref(false);
const streakChipVisible = computed(() => streakDisplayOn.value && streakCurrent.value > 0);

const engagementSnapshot = ref(null);
const engagementDisplayOn = ref(true);
const engagementReducedMotion = ref(false);

const effectiveEngagement = computed(() => engagementSnapshot.value ?? props.initialEngagement ?? null);

function refreshEngagementUi() {
  engagementDisplayOn.value = isUserEngagementRankDisplayEnabled();
}

const engagementChipVisible = computed(
  () => Boolean(effectiveEngagement.value) && engagementDisplayOn.value,
);

function setupEngagementReducedMotion() {
  try {
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    engagementReducedMotion.value = mq.matches;
    mq.addEventListener?.('change', () => {
      engagementReducedMotion.value = mq.matches;
    });
  } catch {
    /* ignore */
  }
}

function onEngagementDisplayRefreshEvent() {
  refreshEngagementUi();
}

function refreshTeacherStreakUi() {
  streakDisplayOn.value = isTeacherStreakDisplayEnabled();
  const s = readTeacherStreak();
  streakCurrent.value = s?.current ?? 0;
  streakLongest.value = s?.longest ?? 0;
}

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
  } catch { /* silent */ } finally {
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
const awaitingReplyCount = ref(0);
const awaitingReplyLoading = ref(false);
const awaitingReplyLoadError = ref('');

// ── Today's pending attendance ──
const loadingAttendance = ref(true);
const pendingAttendanceCount = ref(0);
const todayAllSessions = ref([]);
const attendanceLoadError = ref('');

function localTodayYmd() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

async function fetchPendingAttendance() {
  loadingAttendance.value = true;
  attendanceLoadError.value = '';
  try {
    const token = await getToken();
    if (!token) {
      attendanceLoadError.value = '今日點名資料暫時無法載入';
      return;
    }
    const today = localTodayYmd();
    const result = await fetchClassSessions({ token, start: today, end: today, perPage: 500 });
    const rows = result.items || [];
    pendingAttendanceCount.value = rows.filter((r) => String(r?.status || '').toLowerCase() === 'scheduled').length;
    todayAllSessions.value = rows;
  } catch {
    attendanceLoadError.value = '今日點名資料暫時無法載入';
  } finally {
    loadingAttendance.value = false;
  }
}

async function fetchAwaitingReplyCount() {
  awaitingReplyLoading.value = true;
  awaitingReplyLoadError.value = '';
  try {
    const token = await getToken();
    if (!token) {
      awaitingReplyLoadError.value = '家長回覆資料暫時無法載入';
      return;
    }
    const res = await fetch('/api/v1/me/awaiting-reply-count', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) {
      awaitingReplyCount.value = 0;
      awaitingReplyLoadError.value = '家長回覆資料暫時無法載入';
      return;
    }
    const json = await res.json().catch(() => ({}));
    awaitingReplyCount.value = Number(json?.awaiting_reply_count || 0);
  } catch {
    awaitingReplyCount.value = 0;
    awaitingReplyLoadError.value = '家長回覆資料暫時無法載入';
  } finally {
    awaitingReplyLoading.value = false;
  }
}

// ── Overdue learning records (past 7 days, attended but missing) ──
const loadingOverdue = ref(false);
const overdueRecords = ref([]);
const overdueLoadError = ref('');

async function fetchOverdueLearning() {
  loadingOverdue.value = true;
  overdueLoadError.value = '';
  try {
    const token = await getToken();
    if (!token) {
      overdueLoadError.value = '補填提醒資料暫時無法載入';
      return;
    }

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
      if (results.every(result => result.status === 'rejected')) {
        throw new Error('all branch overdue requests failed');
      }
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

    allItems = dedupeSessionsByStudentSlot(allItems);

    const missing = allItems.filter(s => {
      const st = String(s.status || '').toLowerCase();
      const lr = String(s.learningRecordStatus || 'missing').toLowerCase();
      return st === 'attended' && lr === 'missing';
    });

    missing.sort((a, b) => b.date.localeCompare(a.date) || b.startTime.localeCompare(a.startTime));
    overdueRecords.value = missing;
  } catch {
    overdueLoadError.value = '補填提醒資料暫時無法載入';
  } finally {
    loadingOverdue.value = false;
  }
}

function formatDateUtil(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// ── Weekly schedule (merged across ALL teacher branches) ──
const weekOffset = ref(0);
const loadingWeek = ref(true);
const weekLoadError = ref('');
const weekSessions = ref([]);
let weekLoadSequence = 0;

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
  // Multiple reactive inputs can refresh this view at once. The projection API
  // is the completeness-safe contract for a whole week; ignore stale responses
  // instead of allowing one to overwrite the current week/branch projection.
  const requestSequence = ++weekLoadSequence;
  loadingWeek.value = true;
  weekLoadError.value = '';

  const token = await getToken();
  if (!token) {
    if (requestSequence === weekLoadSequence) loadingWeek.value = false;
    return;
  }

  const startStr = formatDate(weekStart.value);
  const endStr = formatDate(weekEnd.value);

  // in-app bug 178 (GH-941): fetch the teacher's whole week WITHOUT branch_id. The class-sessions
  // endpoint already scopes the teacher role to their own sessions (contract or
  // substitute) across every campus they can access (auth_campus_ids). Passing a
  // single branch_id collapsed that to one campus and hid cross-campus classes
  // (e.g. a class at branch 9 while the workbench branch was 15) — even though the
  // attendance page, which omits branch_id, showed them. This matches that path.
  try {
    const result = await fetchClassSessionsProjection({ token, start: startStr, end: endStr });
    const items = dedupeSessionsByStudentSlot(result.items || []);
    items.sort((a, b) => {
      if (a.date !== b.date) return a.date.localeCompare(b.date);
      if (a.startTime !== b.startTime) return a.startTime.localeCompare(b.startTime);
      return (a.branchId || 0) - (b.branchId || 0);
    });
    if (requestSequence === weekLoadSequence) weekSessions.value = items;
  } catch {
    if (requestSequence !== weekLoadSequence) return;
    weekLoadError.value = '無法載入課表，請稍後重試';
  }
  if (requestSequence === weekLoadSequence) loadingWeek.value = false;
}

const todayStr = computed(() => localTodayYmd());

const weekDays = computed(() => {
  const days = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(weekStart.value);
    d.setDate(weekStart.value.getDate() + i);
    const dateStr = formatDate(d);
    const events = weekSessions.value
      .filter(s => s.date === dateStr && String(s.status || '').toLowerCase() !== 'cancelled')
      .map(s => {
        const status = String(s.status || '').toLowerCase();
        const isLeave = status === 'leave' || status === 'leave_adjusted' || status === 'excused';
        // 請假待審核：與出缺勤管理／課表與評量同一認定，不列入今日待填（in-app bug 194）
        const isLeaveRequested = status === 'leave_requested';
        return {
          key: `${s.studentClassId}-${s.date}-${s.startTime}-${campusIdFrom(s.branchId) || 'none'}`,
          id: s.id,
          studentClassId: s.studentClassId,
          studentName: s.studentName || '—',
          subject: s.subjectName || s.subject || (s.teacherName ? `${s.teacherName}` : '—'),
          date: s.date || dateStr,
          startTime: s.startTime || '—',
          endTime: s.endTime || '',
          branchId: campusIdFrom(s.branchId),
          status: s.status,
          formStatus: isLeave ? 'leave' : (isLeaveRequested ? 'leave_requested' : (s.learningRecordStatus || 'missing')),
          recordId: s.learningRecordId || null,
          isProjected: !!s.isProjected,
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

const teacherTasks = computed(() => buildTeacherTasks({
  pendingAttendance: todayAllSessions.value.filter((row) => String(row?.status || '').toLowerCase() === 'scheduled'),
  pendingLearning: todayPendingEvents.value,
  overdueLearning: overdueRecords.value,
  awaitingReplies: [{ count: Math.max(Number(props.unreadFeedbackCount || 0), Number(awaitingReplyCount.value || 0)) }],
}));
const teacherTaskCount = computed(() => countTeacherTasks(teacherTasks.value));

const teacherTasksLoading = computed(() => (
  loadingAttendance.value || loadingOverdue.value || loadingWeek.value || awaitingReplyLoading.value
));
// Attendance and weekly projection are critical task sources: if either fails,
// fail closed to avoid a false all-clear state. Overdue reminders and reply
// counts are supplemental; when another known task exists, surface their
// failure without hiding actionable attendance or assessment work.
const teacherTasksCriticalError = computed(() => (
  attendanceLoadError.value
  || weekLoadError.value
));
const teacherTasksHasNonFeedbackWork = computed(() => teacherTasks.value.some((task) => task.type !== 'feedback'));
const teacherTasksError = computed(() => (
  teacherTasksCriticalError.value
  || (overdueLoadError.value && teacherTasks.value.length === 0)
  || (awaitingReplyLoadError.value && !teacherTasksHasNonFeedbackWork.value)
));
const teacherTasksPartialError = computed(() => {
  if (teacherTasksCriticalError.value || teacherTasks.value.length === 0) return '';

  const messages = [];
  if (overdueLoadError.value) messages.push(overdueLoadError.value);
  if (awaitingReplyLoadError.value && teacherTasksHasNonFeedbackWork.value) {
    messages.push(awaitingReplyLoadError.value);
  }
  return messages.length > 0 ? `${messages.join('。')}。` : '';
});

function teacherTaskTypeLabel(type) {
  return ({ attendance: '出缺勤', learning: '學習評量', feedback: '家長回饋' }[type] || '待辦');
}

function focusTeacherWorkQueue(event) {
  event.preventDefault();
  const target = document.getElementById('teacher-work-queue-title');
  target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  target?.focus({ preventScroll: true });
}

function openTeacherTask(task) {
  if (!task) return;
  if (task.type === 'attendance') {
    goAttendance();
    return;
  }
  if (task.type === 'feedback') {
    goParentMessages();
    return;
  }
  if (task.type === 'learning') {
    const row = task.source || {};
    goFillRecord({
      branchId: row.branch_id ?? row.branchId,
      recordId: row.learning_record_id ?? row.record_id ?? row.recordId ?? null,
      classSessionId: row.class_session_id ?? row.session_id ?? row.id,
      sessionDate: row.session_date ?? row.date,
    });
  }
}

function scrollToWeekSchedule() {
  document.querySelector('[data-guide="teacher-home-week"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

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
  if (campusIdFrom(branchId) == null) return '';
  const name = getBranchName(branchId);
  return name.replace(/分校$/, '').replace(/校區$/, '');
}

function formStatusLabel(status) {
  const map = { pending: '待審', approved: '已核准', rejected: '退回', changes_requested: '需修改', missing: '', substituted: '代課', leave: '請假', leave_requested: '請假(待審)' };
  return map[status] || status;
}

// ── Navigation ──
function goAttendance() {
  emit('navigate', 'attendance');
}

function goParentMessages() {
  emit('navigate-learning', { listOnly: true, focus: 'awaiting_reply' });
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
  await Promise.all([
    fetchPendingAttendance(),
    fetchAwaitingReplyCount(),
    fetchOverdueLearning(),
    loadWeekSchedule(),
    fetchClockinStatus(),
  ]);
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
    }
  }, POLL_INTERVAL);
}

function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

function onVisibilityChange() {
  if (document.visibilityState === 'visible') {
    refreshTeacherStreakUi();
    refreshEngagementUi();
    fetchPendingAttendance();
    fetchAwaitingReplyCount();
    fetchOverdueLearning();
  }
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

function onTeacherStreakRefreshEvent() {
  refreshTeacherStreakUi();
}

onMounted(() => {
  refreshTeacherStreakUi();
  refreshEngagementUi();
  setupEngagementReducedMotion();
  trackAdoptionEvent('dashboard_opened', props.branchId, { role: 'teacher', page: 'teacher-home' });
  Promise.all([fetchPendingAttendance(), fetchOverdueLearning(), loadWeekSchedule(), fetchAwaitingReplyCount(), fetchClockinStatus()]);
  startPolling();
  document.addEventListener('visibilitychange', onVisibilityChange);
  window.addEventListener(TEACHER_STREAK_REFRESH_EVENT, onTeacherStreakRefreshEvent);
  window.addEventListener(USER_ENGAGEMENT_DISPLAY_REFRESH_EVENT, onEngagementDisplayRefreshEvent);
});

watch(weekOffset, () => loadWeekSchedule());
watch(() => props.branchId, () => {
  fetchPendingAttendance();
  fetchOverdueLearning();
});
watch(() => props.teacherBranchIds, () => loadWeekSchedule(), { deep: true });
onBeforeUnmount(() => {
  weekLoadSequence++;
  stopPolling();
  document.removeEventListener('visibilitychange', onVisibilityChange);
  window.removeEventListener(TEACHER_STREAK_REFRESH_EVENT, onTeacherStreakRefreshEvent);
  window.removeEventListener(USER_ENGAGEMENT_DISPLAY_REFRESH_EVENT, onEngagementDisplayRefreshEvent);
});
</script>

<style scoped>
.th-work-queue {
  margin-top: 14px;
  padding: 20px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
}

.th-work-queue__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--ds-hairline);
}

.th-work-queue__eyebrow {
  margin: 0 0 4px;
  color: var(--ds-primary-deep);
  font-size: 12px;
  font-weight: 700;
}

.th-work-queue h3 { margin: 0; color: var(--ds-ink); font-size: 20px; }
.th-work-queue__description { margin: 5px 0 0; color: var(--ds-ink-mute); font-size: 13px; }
.th-work-queue__count { flex: 0 0 auto; color: var(--ds-ink); font-size: 18px; font-variant-numeric: tabular-nums; font-weight: 700; white-space: nowrap; }
.th-priority-disclosure { margin: 12px 0 4px; border-top: 1px solid var(--ds-hairline); border-bottom: 1px solid var(--ds-hairline); }
.th-priority-disclosure summary { display: flex; align-items: center; gap: 10px; min-height: 42px; color: var(--ds-ink-secondary); font-size: 11px; font-weight: 800; cursor: pointer; list-style: none; }
.th-priority-disclosure summary::-webkit-details-marker { display: none; }
.th-priority-disclosure summary::after { content: '＋'; margin-left: auto; color: var(--ds-ink-mute); font-size: 15px; }
.th-priority-disclosure[open] summary::after { content: '−'; }
.th-priority-disclosure summary strong { color: var(--ds-warning); font-size: 11px; }
.th-priority-disclosure summary:focus-visible { outline: 3px solid var(--ds-info-wash); outline-offset: 2px; border-radius: 4px; }
.th-priority-rules-panel { margin: 12px 0 4px; padding: 14px 16px; border: 1px solid var(--ds-hairline); border-radius: 12px; background: var(--ds-canvas-soft); }
.th-priority-rules-panel h4 { margin: 0 0 8px; color: var(--ds-ink-secondary); font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.02em; }
.th-priority-rules { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 8px; }
.th-priority-rules__item { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; border-radius: 8px; background: var(--ds-canvas); border-left: 3px solid var(--ds-primary); }
.th-priority-rules__item strong { color: var(--ds-ink); font-size: 13px; }
.th-priority-rules__item span { color: var(--ds-ink-mute); font-size: 12px; }
.th-work-queue__list { display: grid; gap: 8px; padding-top: 12px; }
.th-next-action {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 14px;
  min-width: 0;
  padding: 16px;
  border: 1px solid var(--ds-primary-soft);
  border-radius: 12px;
  background: var(--ds-primary-wash);
}
.th-next-action__marker {
  display: grid;
  width: 36px;
  height: 36px;
  place-items: center;
  border-radius: 50%;
  background: var(--ds-primary);
  color: var(--ds-canvas);
}
.th-next-action__marker .material-symbols-outlined { font-size: 20px; }
.th-next-action__content { min-width: 0; }
.th-next-action__eyebrow { margin: 0 0 4px; color: var(--ds-primary-deep); font-size: 12px; font-weight: 800; letter-spacing: 0.04em; }
.th-next-action__title-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 8px; }
.th-next-action__title-row h4 { margin: 0; color: var(--ds-ink); font-size: 16px; }
.th-next-action__summary { margin: 5px 0 0; color: var(--ds-ink-secondary); font-size: 13px; line-height: 1.45; overflow-wrap: anywhere; }
.th-next-action__content small { display: block; margin-top: 5px; color: var(--ds-ink-mute); font-size: 12px; font-variant-numeric: tabular-nums; }
.th-next-action__cta { flex: 0 0 auto; min-width: 104px; }
.th-work-queue__remaining { display: grid; gap: 0; padding-top: 4px; }
.th-work-queue__remaining-label { margin: 4px 0 0; color: var(--ds-ink-mute); font-size: 12px; font-weight: 800; }
.th-work-task { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-width: 0; padding: 14px 0; border-bottom: 1px solid var(--ds-hairline); }
.th-work-task:last-child { border-bottom: 0; }
.th-work-task__main { min-width: 0; }
.th-work-task__title-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 8px; }
.th-work-task__title-row strong { color: var(--ds-ink); font-size: 15px; }
.th-work-task__type { color: var(--ds-ink-mute); font-size: 12px; font-weight: 700; }
.th-work-task__main p { margin: 5px 0 0; color: var(--ds-ink-secondary); font-size: 13px; line-height: 1.45; overflow-wrap: anywhere; }
.th-work-task__main small { display: block; margin-top: 5px; color: var(--ds-ink-mute); font-size: 12px; font-variant-numeric: tabular-nums; }
.th-work-task__cta { flex: 0 0 auto; min-width: 104px; }
.th-work-queue__empty { display: flex; align-items: center; gap: 12px; padding-top: 16px; color: var(--ds-ink-secondary); }
.th-work-queue__empty > .material-symbols-outlined { color: var(--ds-success); font-size: 26px; }
.th-work-queue__empty strong { color: var(--ds-ink); }
.th-work-queue__empty p { margin: 4px 0 0; font-size: 13px; }
.th-work-queue__empty button { margin-left: auto; }
.th-work-queue__error { display: flex; align-items: flex-start; gap: 12px; padding: 16px 0 2px; color: var(--ds-ink-secondary); }
.th-work-queue__error > .material-symbols-outlined { flex: 0 0 auto; color: var(--ds-danger); font-size: 24px; }
.th-work-queue__error strong { color: var(--ds-ink); }
.th-work-queue__error p { margin: 4px 0 0; font-size: 13px; line-height: 1.5; }
.th-work-queue__error button { flex: 0 0 auto; margin-left: auto; }
.th-work-queue__partial-error { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1px solid var(--ds-warning); border-radius: 8px; background: var(--ds-warning-wash); color: var(--ds-ink-secondary); }
.th-work-queue__partial-error > .material-symbols-outlined { flex: 0 0 auto; color: var(--ds-warning); font-size: 20px; }
.th-work-queue__partial-error strong { color: var(--ds-ink); }
.th-work-queue__partial-error p { margin: 3px 0 0; font-size: 13px; line-height: 1.45; }
.th-work-task--skeleton { height: 72px; border-radius: 8px; background: var(--ds-canvas-soft); animation: th-work-skeleton 1.2s ease-in-out infinite alternate; }
@keyframes th-work-skeleton { to { opacity: 0.55; } }
@media (max-width: 640px) {
  .th-work-queue { padding: 16px; }
  .th-work-queue__header { gap: 10px; }
  .th-work-queue__description { max-width: 260px; }
  .th-next-action { grid-template-columns: auto minmax(0, 1fr); align-items: start; gap: 10px 12px; }
  .th-next-action__cta { grid-column: 1 / -1; width: 100%; }
  .th-work-task { align-items: stretch; flex-direction: column; gap: 10px; }
  .th-work-task__cta { width: 100%; }
  .th-work-queue__empty { align-items: flex-start; flex-wrap: wrap; }
  .th-work-queue__empty button { width: 100%; margin-left: 38px; }
  .th-work-queue__error { align-items: flex-start; flex-wrap: wrap; }
  .th-work-queue__error button { width: 100%; margin-left: 36px; }
}
@media (prefers-reduced-motion: reduce) { .th-work-task--skeleton { animation: none; } }

/* ──────── Page Layout ──────── */
.th-page { max-width: 720px; margin: 0 auto; padding-bottom: 80px; }

/* Brand moment: a contained illustration surface keeps ops data calm while
   giving the daily workflow the warmth and emotional feedback of a learning
   product. The image is decorative; the copy and link carry the meaning. */
.th-companion {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  min-height: 164px;
  margin-bottom: 12px;
  padding: 24px 22px 20px 24px;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--ds-primary) 24%, var(--ds-hairline));
  border-radius: 20px;
  background: linear-gradient(112deg, var(--ds-primary-wash) 0%, var(--ds-canvas) 58%, color-mix(in srgb, var(--ds-primary-soft) 18%, var(--ds-canvas)) 100%);
  box-shadow: 0 8px 22px color-mix(in srgb, var(--ds-cta) 12%, transparent);
}
.th-companion::before {
  position: absolute;
  inset: 0 0 auto;
  height: 6px;
  content: '';
  background: var(--ds-brand-gradient);
}
.th-companion::after {
  position: absolute;
  right: 86px;
  bottom: -50px;
  width: 150px;
  height: 150px;
  content: '';
  border-radius: 50%;
  background: color-mix(in srgb, var(--ds-primary-soft) 24%, transparent);
}
.th-companion__copy { position: relative; z-index: 1; min-width: 0; }
.th-companion__eyebrow {
  margin: 0 0 5px;
  color: var(--ds-primary-deep);
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
}
.th-companion h3 {
  margin: 0;
  color: var(--ds-ink);
  font-size: clamp(19px, 3vw, 24px);
  font-weight: 800;
  letter-spacing: -0.03em;
}
.th-companion__description {
  max-width: 34ch;
  margin: 7px 0 14px;
  color: var(--ds-ink-secondary);
  font-size: 13px;
  line-height: 1.55;
}
.th-companion__action {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--ds-cta);
  font-family: inherit;
  font-size: 13px;
  font-weight: 800;
  text-decoration: none;
  cursor: pointer;
}
.th-companion__action:hover { color: var(--ds-cta-hover); text-decoration: underline; }
.th-companion__action:disabled { cursor: wait; opacity: 0.65; }
.th-companion__action:focus-visible { outline: 3px solid var(--ds-focus-ring); outline-offset: 4px; border-radius: 6px; }
.th-companion__action .material-symbols-outlined { font-size: 18px; }
.th-companion__art {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  align-self: stretch;
  flex: 0 0 164px;
  margin: -16px -8px -20px 0;
}
.th-companion__art img {
  display: block;
  width: 150px;
  height: 164px;
  object-fit: contain;
  object-position: center bottom;
  filter: drop-shadow(0 8px 5px rgba(94, 46, 14, 0.14));
}
.th-companion__spark { position: absolute; color: var(--ds-brand-orange); font-size: 18px; line-height: 1; }
.th-companion__spark--one { top: 24px; right: 24px; }
.th-companion__spark--two { top: 54px; left: 14px; color: var(--ds-primary-soft); font-size: 13px; }

[data-theme="dark"] .th-companion {
  border-color: color-mix(in srgb, var(--ds-primary) 40%, var(--ds-hairline));
  background: linear-gradient(112deg, color-mix(in srgb, var(--ds-primary-deep) 28%, var(--ds-canvas)) 0%, var(--ds-canvas) 62%, color-mix(in srgb, var(--ds-primary) 18%, var(--ds-canvas)) 100%);
}

@media (max-width: 480px) {
  .th-companion { min-height: 146px; padding: 22px 14px 18px 16px; gap: 6px; border-radius: 18px; }
  .th-companion__description { margin-bottom: 11px; max-width: 28ch; }
  .th-companion__art { flex-basis: 116px; margin-right: -12px; }
  .th-companion__art img { width: 116px; height: 136px; }
  .th-companion__spark--one { top: 28px; right: 8px; }
  .th-companion__spark--two { left: 0; }
}

.th-streak-chip {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  margin: 6px 0 0;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 13px;
  color: var(--text);
  background: color-mix(in srgb, var(--ds-primary) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--ds-primary) 25%, transparent);
}
.th-streak-icon {
  font-size: 16px;
  vertical-align: -3px;
  color: var(--ds-primary);
}
.th-streak-longest {
  opacity: 0.75;
  font-size: 12px;
}

.th-engagement-chip {
  display: block;
  margin: 8px 0 0;
  padding: 8px 12px;
  border-radius: 12px;
  font-size: 13px;
  color: var(--text);
  background: color-mix(in srgb, var(--ds-primary) 8%, transparent);
  border: 1px solid color-mix(in srgb, var(--ds-primary) 18%, transparent);
}

/* ──────── Section Titles ──────── */
.th-section-title {
  display: flex; align-items: center; gap: 6px;
  font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 12px;
}
.th-section-icon { font-size: 20px; color: var(--ds-primary); }
/* ──────── B. Weekly Schedule ──────── */
.th-week { padding: 20px; }
.th-week-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 4px; }
.th-week-nav { display: flex; align-items: center; gap: 4px; }
.th-week-label { font-size: 14px; font-weight: 600; color: var(--text); min-width: 100px; text-align: center; }
.th-week-badge {
  font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;
  background: var(--ds-primary); color: var(--ds-on-primary); margin-left: 6px;
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
.th-day-dot-today { background: var(--accent); box-shadow: 0 0 0 3px rgba(245,124,0,0.25); }
.th-day-label { min-width: 36px; }
.th-day-short-date { color: var(--text-light); font-weight: 500; }
.th-today-tag {
  font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 6px;
  background: var(--ds-primary); color: var(--ds-on-primary);
}
.th-day-count { margin-left: auto; font-size: 12px; color: var(--text-light); font-weight: 500; }

.th-day-today { background: rgba(245,124,0,0.04); }
[data-theme="dark"] .th-day-today { background: rgba(245,124,0,0.06); }

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
  color: var(--ds-on-primary); white-space: nowrap;
}

.th-form-chip {
  font-size: 11px; font-weight: 600; padding: 1px 7px; border-radius: 6px;
}
.th-form-pending { background: var(--warning-bg); color: var(--warning); }
.th-form-approved { background: var(--success-bg); color: var(--success); }
.th-form-changes_requested { background: var(--danger-bg); color: var(--danger); }
.th-form-rejected { background: var(--danger-bg); color: var(--danger); }
.th-form-substituted { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.th-form-leave { background: var(--ds-warning-wash); color: var(--ds-danger); }
[data-theme="dark"] .th-form-leave { background: rgba(194,65,12,0.18); color: var(--ds-warning); }

.th-event-done { opacity: 0.7; }
.th-event-leave {
  border-left-color: var(--ds-warning);
  background: linear-gradient(90deg, rgba(249,115,22,0.10), transparent 55%);
}

.th-fill-btn {
  background: var(--primary-bg); border: none; border-radius: 8px;
  width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: var(--primary); transition: var(--transition); flex-shrink: 0;
}
.th-fill-btn:hover { background: var(--ds-primary); color: var(--ds-on-primary); }

.th-report-btn {
  background: transparent; border: none; border-radius: 8px;
  width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: var(--text-light); transition: var(--transition); flex-shrink: 0;
  font-size: 20px;
}
.th-report-btn:hover { background: var(--ds-danger-wash); color: var(--ds-danger); }
.th-report-btn--active { color: var(--ds-danger); }
.th-report-btn--active:hover { background: var(--ds-danger-wash); filter: brightness(0.95); }
.th-report-loading { animation: spin-once 0.8s linear infinite; font-size: 18px; }
@keyframes spin-once { to { transform: rotate(360deg); } }

.th-check-icon { color: var(--success); font-size: 22px; flex-shrink: 0; }

/* ──────── Responsive ──────── */
@media (max-width: 480px) {
  .th-page { padding-bottom: 100px; }
  .th-week { padding: 14px; }
  .th-event { padding: 8px 6px; gap: 8px; }
  .th-event-time { font-size: 12px; min-width: 38px; }
  .th-event-student { font-size: 13px; }
  .th-week-label { font-size: 13px; min-width: 90px; }
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
  width: 100%; text-align: left; font: inherit; color: var(--text); background: var(--card-bg);
  transition: background 0.15s, transform 0.1s;
  margin-bottom: 12px;
}
.th-clockin-card:hover  { background: var(--ds-canvas-soft); }
.th-clockin-card:active { transform: scale(0.99); }
.th-clockin-card:focus-visible { outline: 3px solid var(--ds-focus-ring); outline-offset: 2px; }

/* Status: left border colour */
.th-ckin-empty   { border-left-color: var(--border); }
.th-ckin-working { border-left-color: var(--primary); }
.th-ckin-done    { border-left-color: var(--success); }
.th-ckin-late    { border-left-color: var(--ds-danger); }

/* Header row */
.th-clockin-header {
  display: flex; align-items: center; gap: 10px;
}
.th-clockin-icon-wrap {
  width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
}
.th-icon-empty   { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.th-icon-working { background: var(--primary-bg); color: var(--primary); }
.th-icon-done    { background: var(--success-bg); color: var(--success); }
.th-icon-late    { background: var(--ds-danger-wash); color: var(--ds-danger); }

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
.th-badge-late { background: var(--ds-danger-wash); color: var(--ds-danger); }

/* Two chips row */
.th-clockin-chips {
  display: flex; gap: 8px;
}
.th-clockin-chip {
  flex: 1; min-width: 0;
  display: flex; flex-direction: column; gap: 3px;
  padding: 10px 12px; border-radius: 10px; min-height: 44px;
  border: 1px solid var(--ds-hairline); background: var(--ds-canvas);
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
