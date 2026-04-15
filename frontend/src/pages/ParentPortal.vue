<template>
  <div class="pp" data-guide="parent-portal-root">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet" />

    <!-- LIFF auto-login loading -->
    <div class="pp-card pp-loading-card" v-if="liffLoading">
      <div class="pp-spinner"></div>
      <p class="pp-loading-text">正在透過 LINE 驗證身分…</p>
    </div>

    <!-- Login -->
    <div class="pp-card pp-login-card" v-else-if="!token" data-guide="parent-login-card">
      <div class="pp-login-header">
        <div class="pp-login-icon">
          <span class="material-symbols-outlined">family_restroom</span>
        </div>
        <h2>家長 / 學生入口</h2>
        <p class="pp-hint" v-if="!autoLineMode">請輸入學生資料以查看學習狀況</p>
        <p class="pp-hint" v-else>偵測到 LINE 環境，正在自動登入…</p>
      </div>
      <div class="pp-login-form">
        <div class="pp-field">
          <label>
            <span class="material-symbols-outlined pp-field-icon">person</span>
            學生姓名
          </label>
          <input v-model="loginForm.Name" type="text" placeholder="請輸入學生姓名" />
        </div>
        <div class="pp-field">
          <label>
            <span class="material-symbols-outlined pp-field-icon">phone</span>
            聯絡手機號碼
          </label>
          <input v-model="loginForm.Phone" type="tel" placeholder="請輸入手機號碼" />
        </div>
      </div>
      <div class="pp-login-actions">
        <button class="pp-btn pp-btn-primary" @click="login">
          <span class="material-symbols-outlined">login</span>
          登入
        </button>
        <button class="pp-btn pp-btn-line" @click="loginWithLine" v-if="liffAvailable">
          <span style="font-weight:700;">LINE 登入</span>
        </button>
      </div>
      <p class="pp-error" v-if="loginError">{{ loginError }}</p>
    </div>

    <!-- ═══ Dashboard (logged in) ═══ -->
    <template v-if="token && dashboard">

      <!-- Student Profile Card -->
      <div class="pp-card pp-profile-card" data-guide="parent-student-card">
        <div class="pp-profile-top">
          <div class="pp-avatar">{{ (dashboard.student?.name || '?')[0] }}</div>
          <div class="pp-profile-info">
            <h2 class="pp-student-name">{{ dashboard.student?.name || '學生' }}</h2>
            <div class="pp-meta-row">
              <span v-if="dashboard.student?.grade" class="pp-tag pp-tag-grade">{{ dashboard.student.grade }}</span>
              <span v-if="dashboard.student?.school" class="pp-tag pp-tag-school">{{ dashboard.student.school }}</span>
              <span v-if="dashboard.student?.campus_name" class="pp-tag pp-tag-campus">{{ dashboard.student.campus_name }}</span>
            </div>
          </div>
          <button @click="logout" class="pp-btn-logout" title="登出">
            <span class="material-symbols-outlined">logout</span>
          </button>
        </div>
        <!-- Student Switcher (multi-child) -->
        <div class="pp-student-switcher" v-if="students && students.length > 1">
          <div class="pp-switcher-label">
            <span class="material-symbols-outlined" style="font-size:16px;">people</span>
            切換學生
          </div>
          <div class="pp-switcher-chips">
            <button v-for="s in students" :key="s.id"
              class="pp-chip"
              :class="{ active: s.id === dashboard.student?.id }"
              :disabled="switchingStudent"
              @click="switchStudent(s.id)">
              {{ s.name }}
            </button>
          </div>
          <p class="pp-error" v-if="switchError" style="margin-top:6px;">{{ switchError }}</p>
        </div>

        <!-- Remaining sessions ring -->
        <div class="pp-sessions-summary">
          <div class="pp-ring-container">
            <svg class="pp-ring" viewBox="0 0 80 80">
              <circle cx="40" cy="40" r="34" fill="none" stroke="#e8e8e8" stroke-width="6" />
              <circle cx="40" cy="40" r="34" fill="none" :stroke="ringColor" stroke-width="6"
                stroke-linecap="round" :stroke-dasharray="ringDash" stroke-dashoffset="0"
                transform="rotate(-90 40 40)" />
            </svg>
            <div class="pp-ring-value">
              <span class="pp-ring-number">{{ dashboard.remaining_sessions_total ?? 0 }}</span>
              <span class="pp-ring-label">堂</span>
            </div>
          </div>
          <div class="pp-sessions-detail">
            <div class="pp-sessions-title">總剩餘堂數</div>
            <div class="pp-sessions-sub">堂數制課程合計</div>
            <div class="pp-line-status" v-if="lineLinked">
              <span class="material-symbols-outlined" style="color:#06C755;font-size:16px;">check_circle</span>
              <span>LINE 已綁定</span>
            </div>
          </div>
        </div>
        <!-- Per-subject breakdown pills -->
        <div class="pp-subject-breakdown" v-if="dashboard.remaining_by_subject && Object.keys(dashboard.remaining_by_subject).length">
          <div v-for="(count, subject) in dashboard.remaining_by_subject" :key="subject"
               class="pp-subject-pill" :style="{ borderColor: remainingPillColor(count) }">
            <span class="pp-pill-subject">{{ subject }}</span>
            <span class="pp-pill-count" :style="{ color: remainingPillColor(count) }">{{ count }}堂</span>
          </div>
        </div>
      </div>

      <!-- Payment Alerts -->
      <div class="pp-card pp-alert-card" v-if="(dashboard.payment_alerts || []).length > 0">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:#E65100;">payments</span>
          <h3>繳費通知</h3>
        </div>
        <div v-for="alert in dashboard.payment_alerts" :key="alert.class_id" class="pp-alert-item">
          <span class="pp-subject-chip">{{ alert.subject || '課程' }}</span>
          <div class="pp-alert-badges">
            <span v-if="alert.remaining_sessions <= 0" class="pp-badge pp-badge-danger">已用完</span>
            <span v-else class="pp-badge pp-badge-warning">剩餘 {{ alert.remaining_sessions }} 堂</span>
            <span v-if="!alert.paid" class="pp-badge pp-badge-danger">未繳費</span>
          </div>
        </div>
      </div>

      <!-- Upcoming Sessions -->
      <div class="pp-card" v-if="(dashboard.upcoming_sessions || []).length > 0">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:#1565c0;">calendar_today</span>
          <h3>近期課程</h3>
        </div>
        <div class="pp-session-list">
          <div v-for="s in dashboard.upcoming_sessions" :key="s.id" class="pp-session-item">
            <div class="pp-session-date-col">
              <div class="pp-session-day">{{ formatDay(s.SessionDate) }}</div>
              <div class="pp-session-weekday">{{ formatWeekday(s.SessionDate) }}</div>
            </div>
            <div class="pp-session-info">
              <div class="pp-session-subject">{{ s.Subject || '課程' }}</div>
              <div class="pp-session-time">{{ formatHM(s.StartTime) }}~{{ formatHM(s.EndTime) }}</div>
            </div>
            <div class="pp-session-action">
              <span :class="['pp-status-dot', s.Status]"></span>
              <span class="pp-status-text">{{ statusLabel(s.Status) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Courses & Remaining Sessions (Card Layout) -->
      <div class="pp-card" v-if="(dashboard.classes || []).length > 0" data-guide="parent-classes-card">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:#2e7d32;">menu_book</span>
          <h3>進行中的課程</h3>
        </div>
        <div class="pp-course-grid">
          <div v-for="c in dashboard.classes" :key="c.id" class="pp-course-card" :class="courseCardClass(c)">
            <div class="pp-course-top">
              <span class="pp-course-subject">{{ c.subject || '課程' }}</span>
              <span v-if="c.is_stopped" class="pp-badge pp-badge-danger">已停課</span>
              <span v-else-if="c.paid" class="pp-badge pp-badge-success">已繳費</span>
              <span v-else class="pp-badge pp-badge-warning">未繳費</span>
            </div>
            <div class="pp-course-progress">
              <div class="pp-progress-bar">
                <div class="pp-progress-fill" :style="{ width: progressPercent(c) + '%', background: progressColor(c) }"></div>
              </div>
              <div class="pp-progress-labels">
                <span>已使用 {{ c.used_sessions ?? 0 }}</span>
                <span>購買 {{ c.sessions_purchased ?? 0 }}</span>
              </div>
            </div>
            <div class="pp-course-remaining" :style="{ color: remainingColor(c) }">
              <span class="pp-remaining-number">{{ c.remaining_sessions ?? 0 }}</span>
              <span class="pp-remaining-label">堂剩餘</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Learning Records (Expandable Cards) -->
      <!-- Learning Records — Report Card Style (paginated) -->
      <div class="pp-card" v-if="token && dashboard" data-guide="parent-learning-card">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:#5c6bc0;">assignment</span>
          <h3>學習評量</h3>
          <span v-if="lrTotal > 0" class="pp-section-count">共 {{ lrTotal }} 筆</span>
        </div>
        <div v-if="allLearningRecords.length && learningRecordsBySubject.length > 1" class="pp-lr-filter-row">
          <button
            type="button"
            class="pp-lr-chip"
            :class="{ active: !lrSubjectFilter }"
            @click="lrSubjectFilter = ''"
          >全部</button>
          <button
            v-for="[subj] in learningRecordsBySubject"
            :key="'chip-' + subj"
            type="button"
            class="pp-lr-chip"
            :class="{ active: lrSubjectFilter === subj }"
            @click="lrSubjectFilter = subj"
          >{{ subj }}</button>
        </div>
        <template v-if="allLearningRecords.length">
          <template v-for="[lrSubject, lrGroup] in visibleLearningRecordGroups" :key="'grp-' + lrSubject">
            <section class="pp-lr-subject-block">
            <h4 class="pp-lr-subject-heading">{{ lrSubject }}</h4>
            <div v-for="record in lrGroup" :key="record.id ?? record.ID"
                 class="pp-report" @click="toggleRecord(record.id ?? record.ID)">
            <!-- Report Header -->
            <div class="pp-report-head">
              <div class="pp-report-head-left">
                <div class="pp-report-subject">{{ record.Subject || '課程' }}</div>
                <div class="pp-report-meta">
                  <span class="material-symbols-outlined" style="font-size:14px;">calendar_today</span>
                  {{ record.SessionDate }}
                  <template v-if="record.StartTime">
                    <span class="material-symbols-outlined" style="font-size:14px;margin-left:6px;">schedule</span>
                    {{ record.StartTime?.substring(0,5) }}–{{ record.EndTime?.substring(0,5) }}
                  </template>
                </div>
              </div>
              <div class="pp-report-score-ring" v-if="record.QuizScore != null && record.QuizScore !== ''">
                <span class="pp-report-score-val">{{ record.QuizScore }}</span>
                <span class="pp-report-score-unit">分</span>
              </div>
              <span class="material-symbols-outlined pp-expand-icon" :class="{ expanded: expandedRecords.has(record.id ?? record.ID) }">expand_more</span>
            </div>

            <!-- Quick Indicators -->
            <div class="pp-report-indicators">
              <div class="pp-indicator" v-if="record.Performance">
                <span class="material-symbols-outlined pp-indicator-icon" :style="{ color: perfColor(record.Performance) }">{{ perfIcon(record.Performance) }}</span>
                <span class="pp-indicator-text">{{ perfLabel(record.Performance) }}</span>
              </div>
              <div class="pp-indicator" v-if="record.HomeworkStatus">
                <span class="material-symbols-outlined pp-indicator-icon" :style="{ color: hwColor(record.HomeworkStatus) }">{{ hwIcon(record.HomeworkStatus) }}</span>
                <span class="pp-indicator-text">作業{{ hwLabel(record.HomeworkStatus) }}</span>
              </div>
              <div class="pp-indicator" v-if="record.teacher_name">
                <span class="material-symbols-outlined pp-indicator-icon" style="color:#5c6bc0;">person</span>
                <span class="pp-indicator-text">{{ record.teacher_name }}</span>
              </div>
            </div>

            <!-- Expanded Detail -->
            <div class="pp-report-body" v-if="expandedRecords.has(record.id ?? record.ID)">
              <div class="pp-report-field" v-if="record.Progress || record.Content">
                <div class="pp-report-field-label">
                  <span class="material-symbols-outlined">trending_up</span>
                  授課進度
                </div>
                <div class="pp-report-field-value">{{ record.Progress || record.Content }}</div>
              </div>
              <div class="pp-report-field" v-if="record.NextHomework">
                <div class="pp-report-field-label">
                  <span class="material-symbols-outlined">edit_note</span>
                  下次作業
                </div>
                <div class="pp-report-field-value">{{ record.NextHomework }}</div>
              </div>
              <div class="pp-report-field" v-if="record.Comment">
                <div class="pp-report-field-label">
                  <span class="material-symbols-outlined">chat</span>
                  學習建議與家長溝通
                </div>
                <div class="pp-report-field-value pp-report-comment">{{ record.Comment }}</div>
              </div>
            </div>
            </div>
            </section>
          </template>
        </template>
        <button v-if="lrHasMore" class="pp-btn-more" @click="loadMoreRecords" :disabled="lrLoading">
          <template v-if="lrLoading">
            <div class="pp-spinner-inline"></div>
            載入中…
          </template>
          <template v-else>
            <span class="material-symbols-outlined">expand_more</span>
            載入更多（已顯示 {{ allLearningRecords.length }} / {{ lrTotal }} 筆）
          </template>
        </button>
        <div class="pp-empty" v-if="!allLearningRecords.length">
          <span class="material-symbols-outlined">description</span>
          <p>尚無已核准的學習評量紀錄</p>
        </div>
      </div>

      <!-- Attendance Timeline -->
      <div class="pp-card" v-if="token && dashboard">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:#00897b;">fact_check</span>
          <h3>出缺勤紀錄</h3>
        </div>
        <template v-if="(dashboard.attendance_history || []).length">
          <div class="pp-timeline">
            <div v-for="a in dashboard.attendance_history.slice(0, showAllAttendance ? undefined : 10)" :key="a.id" class="pp-timeline-item">
              <div class="pp-timeline-dot" :class="a.Status === 'present' ? 'present' : 'absent'">
                <span class="material-symbols-outlined">{{ a.Status === 'present' ? 'check' : 'close' }}</span>
              </div>
              <div class="pp-timeline-content">
                <span class="pp-timeline-date">{{ formatDateTime(a.SignInDT) }}</span>
                <span :class="['pp-timeline-status', a.Status]">{{ a.Status === 'present' ? '出席' : a.Status === 'absent' ? '缺席' : a.Status }}</span>
              </div>
            </div>
          </div>
          <button v-if="dashboard.attendance_history.length > 10 && !showAllAttendance"
                  class="pp-btn-more" @click="showAllAttendance = true">
            <span class="material-symbols-outlined">expand_more</span>
            顯示更多（共 {{ dashboard.attendance_history.length }} 筆）
          </button>
        </template>
        <div class="pp-empty" v-else>
          <span class="material-symbols-outlined">event_busy</span>
          <p>尚無出缺勤紀錄</p>
        </div>
      </div>

      <!-- Announcements -->
      <div class="pp-card" v-if="(dashboard.announcements || []).length > 0">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:#f57c00;">campaign</span>
          <h3>公告</h3>
        </div>
        <div v-for="ann in dashboard.announcements" :key="ann.id" class="pp-announcement">
          <div class="pp-ann-title">{{ ann.Title || ann.title || '公告' }}</div>
          <div class="pp-ann-body" v-if="ann.Content || ann.content">{{ ann.Content || ann.content }}</div>
          <div class="pp-ann-date">{{ ann.created_at ? ann.created_at.substring(0, 10) : '' }}</div>
        </div>
      </div>

      <!-- Invoices -->
      <div class="pp-card" v-if="(dashboard.invoices || []).length > 0">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:#6d4c41;">receipt_long</span>
          <h3>帳單紀錄</h3>
        </div>
        <div v-for="inv in dashboard.invoices" :key="inv.id" class="pp-invoice-item">
          <div class="pp-invoice-main">
            <div class="pp-invoice-date">{{ inv.IssueDate || inv.issue_date || '' }}</div>
            <div class="pp-invoice-amount">${{ inv.TotalAmount || inv.total_amount || 0 }}</div>
          </div>
          <span :class="['pp-badge', inv.Status === 'paid' || inv.status === 'paid' ? 'pp-badge-success' : 'pp-badge-warning']">
            {{ inv.Status === 'paid' || inv.status === 'paid' ? '已付款' : '未付款' }}
          </span>
        </div>
      </div>

      <!-- LINE Binding Info -->
      <div class="pp-card" v-if="token && dashboard">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:#06C755;">link</span>
          <h3>LINE 綁定</h3>
        </div>
        <div v-if="lineLinked" class="pp-line-bound">
          <span class="material-symbols-outlined" style="color:#06C755;">verified</span>
          <span>已綁定 LINE，可直接從 LINE 官方帳號進入查看</span>
        </div>
        <div v-else class="pp-line-unbound">
          <p>尚未綁定 LINE。請加入補習班 LINE 官方帳號，並輸入：</p>
          <code class="pp-bind-code">綁定 {{ dashboard.student?.name || '學生姓名' }} 手機號碼</code>
          <p class="pp-hint">綁定後即可透過 LINE 查看剩餘堂數與學習評量。</p>
        </div>
      </div>

    </template>
  </div>
</template>

<script setup>
import { onMounted, ref, computed, reactive } from 'vue';
import { getParentDashboard, parentLogin, parentLoginLine, parentSwitchStudent } from '../api';

/** For multi-branch: LINE Endpoint URL must pass this (e.g. ?parent_liff_id=xxx); do not rely on a single VITE_LINE_LIFF_ID per build. */
function resolveParentLiffId() {
  const q = new URLSearchParams(window.location.search);
  const fromQuery =
    q.get('parent_liff_id')
    || q.get('liff_id')
    || q.get('liff.id')
    || q.get('liffId');
  const trimmed = String(fromQuery || '').trim();
  if (trimmed) return trimmed;
  return String(import.meta.env.VITE_LINE_LIFF_ID || '').trim();
}

const props = defineProps({
  standalone: { type: Boolean, default: false },
});

const tokenKey = 'parent_portal_token';
const token = ref(localStorage.getItem(tokenKey) || '');
const loginForm = ref({ Name: '', Phone: '' });
const loginError = ref('');
const dashboard = ref(null);
const liffAvailable = ref(false);
const liffLoading = ref(false);
const autoLineMode = ref(false);
const lineLinked = computed(() => !!dashboard.value?.student?.line_linked);
const expandedRecords = reactive(new Set());
const showAllAttendance = ref(false);
const studentsKey = 'parent_portal_students';
const students = ref(JSON.parse(localStorage.getItem(studentsKey) || 'null'));
const switchingStudent = ref(false);

const setStudents = (list) => {
  students.value = list;
  if (list && list.length > 1) {
    localStorage.setItem(studentsKey, JSON.stringify(list));
  } else {
    localStorage.removeItem(studentsKey);
  }
};

const lrPage = ref(1);
const lrPerPage = 10;
const lrHasMore = ref(false);
const lrTotal = ref(0);
const lrLoading = ref(false);
const allLearningRecords = ref([]);
const lrSubjectFilter = ref('');

const recordSubjectKey = (record) => {
  const raw = record?.Subject ?? record?.subject ?? record?.SubjectName ?? '';
  const key = String(raw).trim();
  return key || '其他';
};

const learningRecordsBySubject = computed(() => {
  const groups = new Map();
  for (const record of allLearningRecords.value) {
    const key = recordSubjectKey(record);
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(record);
  }
  return Array.from(groups.entries()).sort((a, b) =>
    a[0].localeCompare(b[0], 'zh-Hant-TW')
  );
});

const visibleLearningRecordGroups = computed(() => {
  const all = learningRecordsBySubject.value;
  const f = String(lrSubjectFilter.value || '').trim();
  if (!f) return all;
  return all.filter(([subj]) => subj === f);
});

const statusLabel = (s) => {
  const map = { scheduled: '排定', rescheduled: '已調課', leave_requested: '已請假', cancelled: '已取消', completed: '已完成' };
  return map[s] || s;
};

const toggleRecord = (id) => {
  if (expandedRecords.has(id)) expandedRecords.delete(id);
  else expandedRecords.add(id);
};

const formatDay = (dateStr) => {
  if (!dateStr) return '';
  const d = dateStr.split('-');
  return d.length >= 3 ? `${parseInt(d[1])}/${parseInt(d[2])}` : dateStr;
};

const formatWeekday = (dateStr) => {
  if (!dateStr) return '';
  try {
    const d = new Date(dateStr);
    return ['日', '一', '二', '三', '四', '五', '六'][d.getDay()];
  } catch { return ''; }
};

const formatDateTime = (dt) => {
  if (!dt) return '';
  return dt.length > 16 ? dt.substring(0, 16).replace('T', ' ') : dt;
};

const formatHM = (t) => (t || '').substring(0, 5);

const progressPercent = (c) => {
  const total = c.sessions_purchased || 1;
  const used = c.used_sessions || 0;
  return Math.min(100, Math.round((used / total) * 100));
};

const progressColor = (c) => {
  const remaining = c.remaining_sessions ?? 0;
  if (remaining <= 0) return '#c62828';
  if (remaining <= 2) return '#e65100';
  if (remaining <= 4) return '#f9a825';
  return '#2e7d32';
};

const remainingColor = (c) => {
  const r = c.remaining_sessions ?? 0;
  if (r <= 0) return '#c62828';
  if (r <= 2) return '#e65100';
  if (r <= 4) return '#f57c00';
  return '#2e7d32';
};

// Performance helpers for learning record report card
const perfColor = (v) => ({ good: '#2e7d32', average: '#f57c00', bad: '#c62828' }[v] || '#90a4ae');
const perfIcon = (v) => ({ good: 'sentiment_satisfied', average: 'sentiment_neutral', bad: 'sentiment_dissatisfied' }[v] || 'help');
const perfLabel = (v) => ({ good: '表現優良', average: '表現普通', bad: '需加強' }[v] || v || '—');

// Homework status helpers
const hwColor = (v) => ({ completed: '#2e7d32', partial: '#f57c00', incomplete: '#e65100', missing: '#c62828' }[v] || '#90a4ae');
const hwIcon = (v) => ({ completed: 'task_alt', partial: 'pending', incomplete: 'warning', missing: 'cancel' }[v] || 'help');
const hwLabel = (v) => ({ completed: '已完成', partial: '部分完成', incomplete: '未完成', missing: '未繳交' }[v] || v || '—');

const courseCardClass = (c) => {
  if (c.is_stopped) return 'stopped';
  const r = c.remaining_sessions ?? 0;
  if (r <= 0) return 'danger';
  if (r <= 2) return 'warning';
  return '';
};

// Ring progress for total remaining sessions
const ringColor = computed(() => {
  const r = dashboard.value?.remaining_sessions_total ?? 0;
  if (r <= 0) return '#c62828';
  if (r <= 4) return '#e65100';
  if (r <= 8) return '#f9a825';
  return '#2e7d32';
});

const ringDash = computed(() => {
  const total = dashboard.value?.classes?.reduce((s, c) => s + (c.sessions_purchased || 0), 0) || 1;
  const remaining = dashboard.value?.remaining_sessions_total ?? 0;
  const pct = Math.min(1, remaining / Math.max(total, 1));
  const circumference = 2 * Math.PI * 34;
  return `${pct * circumference} ${circumference}`;
});

const loadDashboard = async () => {
  if (!token.value) return;
  try {
    lrPage.value = 1;
    lrSubjectFilter.value = '';
    const data = await getParentDashboard(token.value, { lrPage: 1, lrPerPage });
    dashboard.value = data;
    if (data.students && data.students.length > 1) setStudents(data.students);
    allLearningRecords.value = [...(data.learning_records || [])];
    const meta = data.learning_records_meta || {};
    lrHasMore.value = !!meta.has_more;
    lrTotal.value = meta.total || 0;
  } catch (e) {
    console.error('Dashboard load failed:', e);
    token.value = '';
    localStorage.removeItem(tokenKey);
  }
};

const loadMoreRecords = async () => {
  if (lrLoading.value || !lrHasMore.value) return;
  lrLoading.value = true;
  try {
    const nextPage = lrPage.value + 1;
    const data = await getParentDashboard(token.value, { lrPage: nextPage, lrPerPage });
    const newRecords = data.learning_records || [];
    allLearningRecords.value.push(...newRecords);
    const meta = data.learning_records_meta || {};
    lrHasMore.value = !!meta.has_more;
    lrTotal.value = meta.total || 0;
    lrPage.value = nextPage;
  } catch (e) {
    console.error('Load more records failed:', e);
  } finally {
    lrLoading.value = false;
  }
};

const login = async () => {
  loginError.value = '';
  if (!loginForm.value.Name?.trim() || !loginForm.value.Phone?.trim()) {
    loginError.value = '請輸入學生姓名和手機號碼';
    return;
  }
  try {
    const result = await parentLogin(loginForm.value);
    token.value = result.token;
    localStorage.setItem(tokenKey, result.token);
    if (result.students) setStudents(result.students);
    await loadDashboard();
  } catch (error) {
    loginError.value = '登入失敗，請確認學生姓名及手機號碼是否正確';
  }
};

const loginWithLine = async () => {
  loginError.value = '';
  try {
    if (!window.liff) {
      loginError.value = '請從 LINE 應用程式開啟此頁面';
      return;
    }
    if (!window.liff.isLoggedIn()) {
      // User not logged in to LIFF — trigger LINE login
      window.liff.login();
      return;
    }
    const profile = await window.liff.getProfile();
    const result = await parentLoginLine(profile.userId);
    token.value = result.token;
    localStorage.setItem(tokenKey, result.token);
    if (result.students) setStudents(result.students);
    await loadDashboard();
  } catch (e) {
    console.error('LINE auto-login error:', e);
    loginError.value = e.message || 'LINE 登入失敗';
  }
};

const remainingPillColor = (count) => {
  if (count <= 0) return '#c62828';
  if (count <= 2) return '#e65100';
  if (count <= 4) return '#f57c00';
  return '#2e7d32';
};

const switchError = ref('');

const switchStudent = async (studentId) => {
  if (switchingStudent.value) return;
  if (studentId === dashboard.value?.student?.id) return;
  switchingStudent.value = true;
  switchError.value = '';
  try {
    const result = await parentSwitchStudent(token.value, studentId);
    token.value = result.token;
    localStorage.setItem(tokenKey, result.token);
    allLearningRecords.value = [];
    lrPage.value = 1;
    expandedRecords.clear();
    await loadDashboard();
  } catch (e) {
    console.error('Switch student failed:', e);
    switchError.value = e.message || '切換學生失敗';
  } finally {
    switchingStudent.value = false;
  }
};

const logout = () => {
  token.value = '';
  localStorage.removeItem(tokenKey);
  dashboard.value = null;
  setStudents(null);
};

const loadLiffSdk = () => new Promise((resolve) => {
  if (window.liff) { resolve(); return; }
  const s = document.createElement('script');
  s.charset = 'utf-8';
  s.src = 'https://static.line-scdn.net/liff/edge/2/sdk.js';
  s.onload = resolve;
  s.onerror = resolve; // resolve anyway so caller can check window.liff
  document.head.appendChild(s);
});

onMounted(async () => {
  // If we already have a saved token, try loading dashboard first
  if (token.value) {
    try {
      await loadDashboard();
      if (dashboard.value) return;
    } catch { /* token expired, continue to LIFF login */ }
  }

  const liffId = resolveParentLiffId();
  const isLineInApp = /Line/i.test(navigator.userAgent);
  const hasLiffId = !!String(liffId).trim();

  // Load LIFF SDK on-demand only when a liffId is configured
  if (hasLiffId) await loadLiffSdk();

  // Only run auto LINE login in a configured LIFF entry.
  if (window.liff && hasLiffId) {
    liffLoading.value = true;
    try {
      try {
        await window.liff.init({ liffId });
      } catch (initErr) {
        const msg = initErr.message || '';
        if (!msg.includes('already initialized') && !msg.includes('INIT_FAILED')) {
          throw initErr;
        }
      }

      try {
        liffAvailable.value = typeof window.liff.isLoggedIn === 'function';
      } catch { liffAvailable.value = false; }
      autoLineMode.value = liffAvailable.value && (isLineInApp || window.liff.isInClient());

      if (autoLineMode.value && !dashboard.value) {
        if (window.liff.isLoggedIn()) {
          await loginWithLine();
        } else {
          window.liff.login();
          return;
        }
      }
    } catch (e) {
      console.warn('LIFF init failed:', e);
      liffAvailable.value = false;
      autoLineMode.value = false;
    } finally {
      liffLoading.value = false;
    }
  }
});
</script>

<style scoped>
/* ═══ Base ═══ */
.pp {
  max-width: 520px;
  margin: 0 auto;
  padding: 12px;
  font-family: 'Inter', 'Noto Sans TC', -apple-system, sans-serif;
  color: #37474F;
  min-height: 100vh;
  min-height: 100dvh;
  background: #f5f5f5;
}

.pp-card {
  background: #fff;
  border-radius: 12px;
  padding: 16px 18px;
  margin-bottom: 12px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.06), 0 0 1px rgba(0,0,0,0.08);
}

.pp-hint { color: #78909c; font-size: 0.85em; margin: 4px 0 0; }
.pp-error { color: #c62828; font-size: 0.88em; margin: 10px 0 0; padding: 8px 12px; background: #ffebee; border-radius: 6px; }

/* ═══ Loading ═══ */
.pp-loading-card { text-align: center; padding: 40px 20px; }
.pp-spinner {
  width: 36px; height: 36px; margin: 0 auto 12px;
  border: 3px solid #e0e0e0; border-top-color: #06C755;
  border-radius: 50%; animation: pp-spin 0.8s linear infinite;
}
@keyframes pp-spin { to { transform: rotate(360deg); } }
.pp-loading-text { color: #06C755; font-weight: 600; font-size: 0.95em; }

/* ═══ Login ═══ */
.pp-login-card { padding: 24px 20px; }
.pp-login-header { text-align: center; margin-bottom: 20px; }
.pp-login-icon { margin-bottom: 8px; }
.pp-login-icon .material-symbols-outlined { font-size: 40px; color: var(--primary, #E65100); }
.pp-login-header h2 { margin: 0; font-size: 1.3em; color: #263238; }
.pp-login-form { display: flex; flex-direction: column; gap: 14px; }
.pp-field label {
  display: flex; align-items: center; gap: 4px;
  font-weight: 600; font-size: 0.88em; margin-bottom: 5px; color: #455a64;
}
.pp-field-icon { font-size: 18px; color: #90a4ae; }
.pp-field input {
  width: 100%; padding: 10px 12px; border: 1.5px solid #e0e0e0;
  border-radius: 8px; box-sizing: border-box; font-size: 16px;
  transition: border-color 0.2s;
}
.pp-field input:focus { border-color: var(--primary, #E65100); outline: none; }
.pp-login-actions { display: flex; gap: 8px; margin-top: 18px; }

/* ═══ Buttons ═══ */
.pp-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 10px 20px; border: none; border-radius: 8px;
  font-size: 0.95em; font-weight: 600; cursor: pointer;
  min-height: 44px; transition: opacity 0.2s;
  -webkit-tap-highlight-color: transparent;
}
.pp-btn:active { opacity: 0.8; }
.pp-btn-primary { background: var(--primary, #E65100); color: #fff; flex: 1; justify-content: center; }
.pp-btn-primary .material-symbols-outlined { font-size: 20px; }
.pp-btn-line { background: #06C755; color: #fff; flex: 1; justify-content: center; }
.pp-btn-small {
  padding: 4px 12px; font-size: 0.8em; font-weight: 600;
  background: #e3f2fd; color: #1565c0; border: none; border-radius: 6px;
  cursor: pointer; min-height: 32px;
}
.pp-btn-logout {
  background: none; border: 1px solid #e0e0e0; border-radius: 8px;
  padding: 6px; cursor: pointer; color: #90a4ae;
  display: flex; align-items: center;
}
.pp-btn-logout:hover { background: #fafafa; }
.pp-btn-more {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  width: 100%; padding: 8px; margin-top: 8px;
  background: #fafafa; border: 1px solid #e8e8e8; border-radius: 8px;
  font-size: 0.85em; color: #607d8b; cursor: pointer;
}

/* ═══ Profile Card ═══ */
.pp-profile-card { padding: 18px; }
.pp-profile-top { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.pp-avatar {
  width: 48px; height: 48px; border-radius: 50%;
  background: linear-gradient(135deg, #E65100, #FF8A65);
  color: #fff; font-size: 22px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.pp-profile-info { flex: 1; min-width: 0; }
.pp-student-name { margin: 0; font-size: 1.2em; color: #263238; }
.pp-meta-row { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.pp-tag {
  display: inline-block; padding: 2px 8px; border-radius: 4px;
  font-size: 0.75em; font-weight: 600;
}
.pp-tag-grade { background: #e3f2fd; color: #1565c0; }
.pp-tag-school { background: #f3e5f5; color: #7b1fa2; }
.pp-tag-campus { background: #e8f5e9; color: #2e7d32; }

.pp-student-switcher { padding: 10px 0 4px; border-top: 1px solid #f0f0f0; margin-top: 4px; }
.pp-switcher-label { display: flex; align-items: center; gap: 4px; font-size: 0.82em; color: #78909c; margin-bottom: 8px; }
.pp-switcher-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.pp-chip {
  padding: 6px 14px; border-radius: 20px; border: 1.5px solid #cfd8dc;
  background: #fff; font-size: 0.88em; cursor: pointer; transition: all 0.2s;
  color: #455a64; font-weight: 500;
}
.pp-chip:hover:not(.active):not(:disabled) { border-color: #E65100; color: #E65100; background: #fff3e0; }
.pp-chip.active { background: #E65100; color: #fff; border-color: #E65100; cursor: default; }
.pp-chip:disabled { opacity: 0.6; cursor: wait; }

.pp-sessions-summary { display: flex; align-items: center; gap: 16px; padding: 12px 0 4px; }
.pp-ring-container { position: relative; width: 80px; height: 80px; flex-shrink: 0; }
.pp-ring { width: 100%; height: 100%; }
.pp-ring-value {
  position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
  text-align: center;
}
.pp-ring-number { font-size: 22px; font-weight: 800; display: block; line-height: 1; }
.pp-ring-label { font-size: 11px; color: #90a4ae; }
.pp-sessions-detail { flex: 1; }
.pp-sessions-title { font-weight: 700; font-size: 1em; }
.pp-sessions-sub { font-size: 0.82em; color: #90a4ae; }
.pp-line-status { display: flex; align-items: center; gap: 4px; margin-top: 4px; font-size: 0.82em; color: #06C755; }

/* ═══ Subject Breakdown Pills ═══ */
.pp-subject-breakdown {
  display: flex; flex-wrap: wrap; gap: 8px;
  margin-top: 12px; padding-top: 12px;
  border-top: 1px solid #eee;
}
.pp-subject-pill {
  display: flex; align-items: center; gap: 6px;
  background: #fff; border: 1.5px solid #e0e0e0;
  border-radius: 20px; padding: 5px 14px;
  font-size: 0.85em;
}
.pp-pill-subject { color: #37474f; font-weight: 600; }
.pp-pill-count { font-weight: 800; }

/* ═══ Section Header ═══ */
.pp-section-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.pp-section-icon { font-size: 22px; }
.pp-section-header h3 { margin: 0; font-size: 1.05em; color: #263238; }

/* ═══ Alert Card ═══ */
.pp-alert-card { border-left: 4px solid #ff9800; }
.pp-alert-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 0; border-bottom: 1px solid #f5f5f5; flex-wrap: wrap; gap: 6px;
}
.pp-alert-item:last-child { border-bottom: none; }
.pp-subject-chip {
  background: #e3f2fd; color: #1565c0; padding: 3px 10px;
  border-radius: 6px; font-size: 0.85em; font-weight: 600;
}
.pp-alert-badges { display: flex; gap: 4px; flex-wrap: wrap; }

/* ═══ Badges ═══ */
.pp-badge {
  display: inline-block; padding: 2px 10px; border-radius: 10px;
  font-size: 0.78em; font-weight: 600; white-space: nowrap;
}
.pp-badge-success { background: #e8f5e9; color: #2e7d32; }
.pp-badge-warning { background: #fff3e0; color: #e65100; }
.pp-badge-danger { background: #ffebee; color: #c62828; }

/* ═══ Upcoming Sessions ═══ */
.pp-session-list { display: flex; flex-direction: column; gap: 0; }
.pp-session-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 0; border-bottom: 1px solid #f5f5f5;
}
.pp-session-item:last-child { border-bottom: none; }
.pp-session-date-col { text-align: center; min-width: 44px; }
.pp-session-day { font-size: 1em; font-weight: 700; color: #263238; }
.pp-session-weekday { font-size: 0.75em; color: #90a4ae; }
.pp-session-info { flex: 1; min-width: 0; }
.pp-session-subject { font-weight: 600; font-size: 0.92em; }
.pp-session-time { font-size: 0.82em; color: #78909c; }
.pp-session-action { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.pp-status-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #90a4ae; flex-shrink: 0;
}
.pp-status-dot.scheduled { background: #1565c0; }
.pp-status-dot.rescheduled { background: #f57c00; }
.pp-status-dot.leave_requested { background: #7b1fa2; }
.pp-status-text { font-size: 0.78em; color: #78909c; }

/* ═══ Course Cards ═══ */
.pp-course-grid { display: flex; flex-direction: column; gap: 10px; }
.pp-course-card {
  background: #fafafa; border-radius: 10px; padding: 14px;
  border: 1px solid #eee; transition: border-color 0.2s;
}
.pp-course-card.warning { border-color: #ffcc80; }
.pp-course-card.danger { border-color: #ef9a9a; }
.pp-course-card.stopped { opacity: 0.6; }
.pp-course-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.pp-course-subject { font-weight: 700; font-size: 0.95em; }
.pp-course-progress { margin-bottom: 8px; }
.pp-progress-bar {
  height: 6px; background: #e8e8e8; border-radius: 3px; overflow: hidden;
}
.pp-progress-fill { height: 100%; border-radius: 3px; transition: width 0.4s ease; }
.pp-progress-labels { display: flex; justify-content: space-between; font-size: 0.75em; color: #90a4ae; margin-top: 4px; }
.pp-course-remaining { text-align: right; }
.pp-remaining-number { font-size: 1.6em; font-weight: 800; }
.pp-remaining-label { font-size: 0.78em; color: #90a4ae; margin-left: 2px; }

/* ═══ Learning Records ═══ */
.pp-record-card {
  background: #fafafa; border-radius: 10px; padding: 12px 14px;
  margin-bottom: 8px; cursor: pointer; border: 1px solid #eee;
  transition: background 0.15s;
}
.pp-record-card:active { background: #f0f0f0; }
.pp-record-header { display: flex; align-items: center; gap: 8px; }
.pp-record-main { flex: 1; min-width: 0; }
.pp-record-date { font-size: 0.82em; color: #90a4ae; display: block; }
.pp-record-subject { font-weight: 600; font-size: 0.95em; }
.pp-record-score { text-align: center; flex-shrink: 0; }
.pp-score-value { font-size: 1.4em; font-weight: 800; color: #1565c0; }
.pp-score-label { font-size: 0.7em; color: #90a4ae; }
.pp-expand-icon { color: #bdbdbd; transition: transform 0.2s; font-size: 20px; }
.pp-expand-icon.expanded { transform: rotate(180deg); }
.pp-record-detail {
  margin-top: 10px; padding-top: 10px; border-top: 1px solid #e8e8e8;
  display: flex; flex-direction: column; gap: 8px;
}
.pp-detail-row { display: flex; align-items: flex-start; gap: 6px; font-size: 0.88em; }
.pp-detail-icon { font-size: 16px; color: #90a4ae; flex-shrink: 0; margin-top: 1px; }
.pp-detail-label { font-weight: 600; color: #607d8b; white-space: nowrap; margin-right: 4px; }

/* ═══ Report Card (Learning Records) ═══ */
.pp-lr-filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0 0 14px;
  padding: 0 4px;
}
.pp-lr-chip {
  border: 1px solid #cfd8dc;
  background: #fff;
  color: #455a64;
  font-size: 13px;
  font-weight: 600;
  padding: 8px 14px;
  border-radius: 999px;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.pp-lr-chip.active {
  background: #5c6bc0;
  border-color: #5c6bc0;
  color: #fff;
}
.pp-lr-subject-block {
  margin-bottom: 8px;
}
.pp-lr-subject-heading {
  margin: 16px 0 8px;
  font-size: 15px;
  font-weight: 700;
  color: #37474f;
  padding-bottom: 6px;
  border-bottom: 1px solid #eceff1;
}
.pp-lr-subject-block:first-of-type .pp-lr-subject-heading {
  margin-top: 0;
}
.pp-report {
  background: #fafafa; border-radius: 10px; padding: 14px;
  margin-bottom: 10px; cursor: pointer; border: 1px solid #eee;
  transition: background 0.15s, box-shadow 0.2s;
}
.pp-report:active { background: #f0f0f0; }
.pp-report-head {
  display: flex; align-items: center; gap: 10px;
}
.pp-report-head-left { flex: 1; min-width: 0; }
.pp-report-subject {
  font-weight: 700; font-size: 1em; color: #263238;
  margin-bottom: 2px;
}
.pp-report-meta {
  display: flex; align-items: center; gap: 4px; flex-wrap: wrap;
  font-size: 0.8em; color: #90a4ae;
}
.pp-report-score-ring {
  width: 48px; height: 48px; flex-shrink: 0;
  background: linear-gradient(135deg, #e3f2fd, #bbdefb);
  border-radius: 50%; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
}
.pp-report-score-val { font-size: 1.1em; font-weight: 800; color: #1565c0; line-height: 1; }
.pp-report-score-unit { font-size: 0.6em; color: #64b5f6; }
.pp-report-indicators {
  display: flex; flex-wrap: wrap; gap: 8px;
  margin-top: 10px; padding-top: 8px;
  border-top: 1px dashed #e8e8e8;
}
.pp-indicator {
  display: flex; align-items: center; gap: 4px;
  background: #fff; border: 1px solid #eee; border-radius: 6px;
  padding: 4px 10px; font-size: 0.82em;
}
.pp-indicator-icon { font-size: 16px; }
.pp-indicator-text { color: #546e7a; font-weight: 500; }
.pp-report-body {
  margin-top: 12px; padding-top: 12px;
  border-top: 1px solid #e0e0e0;
  display: flex; flex-direction: column; gap: 12px;
}
.pp-report-field {
  background: #fff; border-radius: 8px; padding: 10px 12px;
  border: 1px solid #f0f0f0;
}
.pp-report-field-label {
  display: flex; align-items: center; gap: 6px;
  font-size: 0.8em; font-weight: 700; color: #78909c;
  margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px;
}
.pp-report-field-label .material-symbols-outlined { font-size: 16px; }
.pp-report-field-value {
  font-size: 0.92em; color: #37474f; line-height: 1.6;
  white-space: pre-line;
}
.pp-report-comment {
  background: #fffde7; padding: 10px 12px; border-radius: 6px;
  border-left: 3px solid #ffd54f; font-style: italic;
}

/* ═══ Attendance Timeline ═══ */
.pp-timeline { position: relative; padding-left: 28px; }
.pp-timeline::before {
  content: ''; position: absolute; left: 11px; top: 8px; bottom: 8px;
  width: 2px; background: #e0e0e0;
}
.pp-timeline-item { display: flex; align-items: center; gap: 10px; padding: 6px 0; position: relative; }
.pp-timeline-dot {
  position: absolute; left: -28px;
  width: 22px; height: 22px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  z-index: 1;
}
.pp-timeline-dot .material-symbols-outlined { font-size: 14px; color: #fff; }
.pp-timeline-dot.present { background: #43a047; }
.pp-timeline-dot.absent { background: #e53935; }
.pp-timeline-content { display: flex; align-items: center; gap: 8px; flex: 1; }
.pp-timeline-date { font-size: 0.85em; color: #607d8b; min-width: 100px; }
.pp-timeline-status { font-size: 0.85em; font-weight: 600; }
.pp-timeline-status.present { color: #2e7d32; }
.pp-timeline-status.absent { color: #c62828; }

/* ═══ Announcements ═══ */
.pp-announcement {
  padding: 10px 0; border-bottom: 1px solid #f0f0f0;
}
.pp-announcement:last-child { border-bottom: none; }
.pp-ann-title { font-weight: 700; font-size: 0.95em; color: #263238; }
.pp-ann-body { font-size: 0.88em; color: #546e7a; margin-top: 4px; white-space: pre-line; }
.pp-ann-date { font-size: 0.78em; color: #b0bec5; margin-top: 4px; }

/* ═══ Invoices ═══ */
.pp-invoice-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 0; border-bottom: 1px solid #f5f5f5;
}
.pp-invoice-item:last-child { border-bottom: none; }
.pp-invoice-date { font-size: 0.85em; color: #607d8b; }
.pp-invoice-amount { font-weight: 700; font-size: 1.05em; }

/* ═══ LINE Binding ═══ */
.pp-line-bound {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; background: #e8f5e9; border-radius: 8px;
  font-size: 0.9em; color: #2e7d32;
}
.pp-line-unbound { font-size: 0.9em; }
.pp-bind-code {
  display: block; background: #f5f5f5; padding: 10px 14px; border-radius: 8px;
  margin: 8px 0; font-family: monospace; font-size: 0.92em; color: #37474f;
  border: 1px solid #e0e0e0;
}

/* ═══ Empty State ═══ */
.pp-empty {
  text-align: center; padding: 20px; color: #b0bec5;
}
.pp-empty .material-symbols-outlined { font-size: 36px; margin-bottom: 4px; }
.pp-empty p { margin: 0; font-size: 0.88em; }

.pp-section-count {
  margin-left: auto; font-size: 0.78em; color: #90a4ae; font-weight: 500;
}
.pp-spinner-inline {
  width: 16px; height: 16px; border: 2px solid #e0e0e0;
  border-top-color: #5c6bc0; border-radius: 50%;
  animation: pp-spin 0.8s linear infinite; flex-shrink: 0;
}
.pp-btn-more:disabled { opacity: 0.7; cursor: default; }

/* ═══ Responsive ═══ */
@media (max-width: 640px) {
  .pp { padding: 8px; }
  .pp-card { padding: 14px; border-radius: 10px; }
  .pp-login-card { padding: 20px 16px; }
  .pp-sessions-summary { flex-direction: column; text-align: center; gap: 8px; }
}

@media (min-width: 521px) {
  .pp { padding: 20px; }
  .pp-card { padding: 20px 24px; }
  .pp-course-grid { display: grid; grid-template-columns: repeat(2, 1fr); }
}
</style>
