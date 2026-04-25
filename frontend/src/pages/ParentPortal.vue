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
        <button class="pp-btn pp-btn-primary" @click="login" :disabled="loginLoading">
          <template v-if="loginLoading">
            <div class="pp-spinner-inline"></div>
            登入中…
          </template>
          <template v-else>
            <span class="material-symbols-outlined">login</span>
            登入
          </template>
        </button>
        <button class="pp-btn pp-btn-line" @click="loginWithLine" v-if="liffAvailable">
          <span style="font-weight:700;">LINE 登入</span>
        </button>
      </div>
      <p class="pp-error" v-if="loginError">{{ loginError }}</p>
    </div>

    <!-- Skeleton loader while dashboard is loading (token exists but no data yet) -->
    <template v-if="token && !dashboard && !liffLoading">
      <div class="pp-card pp-skeleton-card">
        <div class="pp-skel-row pp-skel-row--avatar">
          <div class="pp-skel pp-skel--circle"></div>
          <div style="flex:1; display:flex; flex-direction:column; gap:8px;">
            <div class="pp-skel" style="height: 18px; width: 55%;"></div>
            <div class="pp-skel" style="height: 12px; width: 80%;"></div>
          </div>
        </div>
        <div class="pp-skel" style="height: 90px; border-radius: 10px; margin-top: 14px;"></div>
      </div>
      <div class="pp-card pp-skeleton-card">
        <div class="pp-skel" style="height: 16px; width: 30%;"></div>
        <div class="pp-skel" style="height: 60px; border-radius: 8px; margin-top: 10px;"></div>
        <div class="pp-skel" style="height: 60px; border-radius: 8px; margin-top: 10px;"></div>
      </div>
      <div class="pp-card pp-skeleton-card">
        <div class="pp-skel" style="height: 16px; width: 25%;"></div>
        <div class="pp-skel" style="height: 44px; border-radius: 8px; margin-top: 10px;"></div>
        <div class="pp-skel" style="height: 44px; border-radius: 8px; margin-top: 8px;"></div>
        <div class="pp-skel" style="height: 44px; border-radius: 8px; margin-top: 8px;"></div>
      </div>
    </template>

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
      <!-- PRD-H (2026-04-18)：月結制改顯示「本月已上 X 堂 / 預定 Y 堂」＋「月繳 $Z 預估」，
           堂數制仍維持已用/購買/剩餘；付款狀態 badge 兩種模式共用。 -->
      <div class="pp-card" v-if="(dashboard.classes || []).length > 0" data-guide="parent-classes-card">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:#2e7d32;">menu_book</span>
          <h3>進行中的課程</h3>
        </div>
        <div class="pp-course-grid">
          <div v-for="c in dashboard.classes" :key="c.id" class="pp-course-card" :class="courseCardClass(c)">
            <div class="pp-course-top">
              <span class="pp-course-subject">{{ c.subject || '課程' }}</span>
              <span v-if="isMonthlyCourse(c)" class="pp-badge pp-badge-info">月結</span>
              <span v-if="c.is_stopped" class="pp-badge pp-badge-danger">已停課</span>
              <span v-else-if="c.paid" class="pp-badge pp-badge-success">已繳費</span>
              <span v-else class="pp-badge pp-badge-warning">未繳費</span>
            </div>

            <!-- 堂數制：顯示已用/購買進度條 + 剩餘堂數 -->
            <template v-if="!isMonthlyCourse(c)">
              <div class="pp-course-progress">
                <div class="pp-progress-bar">
                  <div class="pp-progress-fill" :style="{ width: progressPercent(c) + '%', background: progressColor(c) }"></div>
                </div>
                <div class="pp-progress-labels">
                  <span>已上 {{ c.used_sessions ?? 0 }}</span>
                  <span>購買 {{ c.sessions_purchased ?? 0 }}</span>
                </div>
              </div>
              <div class="pp-course-remaining" :style="{ color: remainingColor(c) }">
                <span class="pp-remaining-number">{{ c.remaining_sessions ?? 0 }}</span>
                <span class="pp-remaining-label">堂剩餘</span>
              </div>
            </template>

            <!-- 月結制：顯示本月已上堂數 + 預估月費 -->
            <template v-else>
              <div class="pp-course-progress" v-if="(c.monthly_target || 0) > 0">
                <div class="pp-progress-bar">
                  <div class="pp-progress-fill" :style="{ width: monthlyProgressPercent(c) + '%', background: '#1976d2' }"></div>
                </div>
                <div class="pp-progress-labels">
                  <span>{{ dashboard.current_month_label || '本月' }}已上 {{ c.attended_this_month ?? 0 }}</span>
                  <span>預定 {{ c.monthly_target }} 堂/月</span>
                </div>
              </div>
              <div class="pp-monthly-stats">
                <div class="pp-monthly-attended">
                  <span class="pp-remaining-number" style="color:#1976d2;">{{ c.attended_this_month ?? 0 }}</span>
                  <span class="pp-remaining-label">{{ dashboard.current_month_label || '本月' }}已上</span>
                </div>
                <div v-if="(c.monthly_fee_estimate || 0) > 0" class="pp-monthly-fee">
                  <span class="pp-monthly-fee-amount">${{ formatMoney(c.monthly_fee_estimate) }}</span>
                  <span class="pp-monthly-fee-label">月費預估</span>
                </div>
              </div>
            </template>
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
                <div class="pp-report-subject">
                  {{ record.Subject || '課程' }}
                  <span v-if="record.session_number" class="pp-session-num">第{{ record.session_number }}堂</span>
                </div>
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
                  本次作業
                </div>
                <div class="pp-report-field-value">{{ record.NextHomework }}</div>
              </div>
              <div class="pp-report-field" v-if="record.NextWeekTestScope">
                <div class="pp-report-field-label">
                  <span class="material-symbols-outlined">quiz</span>
                  下次週考範圍
                </div>
                <div class="pp-report-field-value">{{ record.NextWeekTestScope }}</div>
              </div>
              <div class="pp-report-field" v-if="record.Comment">
                <div class="pp-report-field-label">
                  <span class="material-symbols-outlined">chat</span>
                  學習建議與家長溝通
                </div>
                <div class="pp-report-field-value pp-report-comment">{{ record.Comment }}</div>
              </div>
              <div class="pp-feedback-box" @click.stop="prepareFeedbackDraft(record)">
                <div class="pp-feedback-title">
                  <span class="material-symbols-outlined">rate_review</span>
                  給老師的回饋
                </div>
                <p class="pp-feedback-hint">
                  {{ record.parent_feedback ? '已送出給老師與主任查看。' : '有想補充給老師的嗎？可留下問題、觀察或鼓勵。' }}
                </p>
                <textarea
                  v-model="record._feedbackDraft"
                  class="pp-feedback-textarea"
                  maxlength="500"
                  aria-label="給老師的回饋"
                  :placeholder="record.parent_feedback?.content || '例如：孩子回家說這個單元還不太熟，想請老師下次協助加強。'"
                  @focus="prepareFeedbackDraft(record)"
                ></textarea>
                <div class="pp-feedback-actions">
                  <span :class="['pp-feedback-count', { warn: feedbackLength(record) >= 480 }]">{{ feedbackLength(record) }}/500</span>
                  <button class="pp-btn pp-btn-primary pp-feedback-submit" :disabled="record._feedbackSaving || !canSubmitFeedback(record)" @click="submitFeedback(record)">
                    {{ record._feedbackSaving ? '送出中...' : (record.parent_feedback ? '更新回饋' : '送出回饋') }}
                  </button>
                </div>
                <p v-if="record._feedbackError" class="pp-error pp-feedback-error">{{ record._feedbackError }}</p>
                <p v-if="record.parent_feedback?.updated_at" class="pp-feedback-time">上次更新：{{ formatFeedbackTime(record.parent_feedback.updated_at) }}</p>
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

      <!-- Attendance Timeline (FR-B-003) -->
      <div class="pp-card" v-if="token && dashboard">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:#00897b;">fact_check</span>
          <h3>出缺勤紀錄</h3>
        </div>
        <template v-if="(dashboard.attendance_history || []).length">
          <div class="pp-timeline">
            <div v-for="a in dashboard.attendance_history.slice(0, showAllAttendance ? undefined : 10)"
                 :key="a.id"
                 class="pp-timeline-item"
                 :class="attendanceRowClass(a.Status)">
              <div class="pp-timeline-dot" :class="attendanceDotClass(a.Status)">
                <span class="material-symbols-outlined">{{ attendanceIcon(a.Status) }}</span>
              </div>
              <div class="pp-timeline-content">
                <div class="pp-timeline-head">
                  <span class="pp-timeline-date">{{ a.date || (a.SignInDT ? a.SignInDT.substring(0, 10) : '') }}</span>
                  <span :class="['pp-timeline-status', attendanceStatusClass(a.Status)]">{{ a.status_label || attendanceLabel(a.Status) }}</span>
                </div>
                <div class="pp-timeline-sub" v-if="a.time || a.subject || a.teacher_name">
                  <span v-if="a.time"><span class="material-symbols-outlined pp-mini-icon">schedule</span>{{ a.time }}</span>
                  <span v-if="a.subject"><span class="material-symbols-outlined pp-mini-icon">menu_book</span>{{ a.subject }}</span>
                  <span v-if="a.teacher_name"><span class="material-symbols-outlined pp-mini-icon">person</span>{{ a.teacher_name }}</span>
                </div>
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
          <p>目前無出缺勤記錄</p>
          <p class="pp-empty-hint">老師完成點名後將自動顯示於此</p>
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
          <div class="pp-invoice-badges">
            <span :class="['pp-badge', inv.Status === 'paid' || inv.status === 'paid' ? 'pp-badge-success' : 'pp-badge-warning']">
              {{ inv.Status === 'paid' || inv.status === 'paid' ? '已付款' : '未付款' }}
            </span>
            <span v-if="inv.reconciled_at" class="pp-badge pp-badge-reconciled" title="已核帳確認">
              <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle">verified</span>
              已核帳
            </span>
          </div>
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
import { getParentDashboard, parentLogin, parentLoginLine, parentSwitchStudent, upsertParentLearningRecordFeedback } from '../api';

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

async function resolveParentLiffIdAsync() {
  const local = resolveParentLiffId();
  if (local) return local;
  try {
    const res = await fetch('/api/v1/parent/resolve-liff');
    if (res.ok) {
      const json = await res.json();
      if (json.liff_id) return json.liff_id;
    }
  } catch { /* ignore */ }
  return '';
}

const props = defineProps({
  standalone: { type: Boolean, default: false },
});

const tokenKey = 'parent_portal_token';
const token = ref(localStorage.getItem(tokenKey) || '');
// campus_id from the URL (injected by LINE webhook portal link via ?campus_id=X)
const urlCampusId = new URLSearchParams(window.location.search).get('campus_id') || '';
const loginForm = ref({ Name: '', Phone: '' });
const loginError = ref('');
const loginLoading = ref(false);
const dashboard = ref(null);
const liffAvailable = ref(false);
const liffLoading = ref(false);
const autoLineMode = ref(false);
const lineLinked = computed(() => !!dashboard.value?.student?.line_linked);
const expandedRecords = reactive(new Set());
const showAllAttendance = ref(false);
// PRD-B 追加修正（2026-04-18 晚間）：家長端仍會看到舊版「相同 Phone」帶出的兄弟姊妹名單，
// 根因為 localStorage 的 parent_portal_students 舊快取未清。改用「伺服器回應為唯一來源」，
// 登入/dashboard/切換成功後 setStudents() 一律以最新回應覆寫；不再從 localStorage 初始化。
// 同時於 mount 時主動清除舊 key，確保所有舊版客戶端一上線即失效。
const studentsKey = 'parent_portal_students';
try { localStorage.removeItem(studentsKey); } catch (_) {}
const students = ref(null);
const switchingStudent = ref(false);

const setStudents = (list) => {
  const next = Array.isArray(list) && list.length > 1 ? list : null;
  students.value = next;
  try { localStorage.removeItem(studentsKey); } catch (_) {}
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

const prepareFeedbackDraft = (record) => {
  if (record._feedbackDraft == null) record._feedbackDraft = record.parent_feedback?.content || '';
};
const feedbackLength = (record) => String(record._feedbackDraft ?? record.parent_feedback?.content ?? '').length;
const canSubmitFeedback = (record) => {
  const text = String(record._feedbackDraft ?? '').trim();
  return text.length > 0 && text.length <= 500;
};
const formatFeedbackTime = (value) => {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  return `${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};
const submitFeedback = async (record) => {
  prepareFeedbackDraft(record);
  record._feedbackError = '';
  if (!canSubmitFeedback(record)) {
    record._feedbackError = '請輸入 1-500 字的回饋內容';
    return;
  }
  record._feedbackSaving = true;
  try {
    const id = record.id ?? record.ID;
    record.parent_feedback = await upsertParentLearningRecordFeedback(token.value, id, String(record._feedbackDraft).trim());
    record._feedbackDraft = record.parent_feedback?.content || '';
  } catch (e) {
    record._feedbackError = e?.message || '暫時無法送出，請稍後再試';
  } finally {
    record._feedbackSaving = false;
  }
};

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

const isMonthlyCourse = (c) => {
  const mode = String(c?.schedule_mode ?? 'count');
  return mode !== 'count';
};

const monthlyProgressPercent = (c) => {
  const target = c?.monthly_target || 0;
  const attended = c?.attended_this_month || 0;
  if (target <= 0) return 0;
  return Math.min(100, Math.round((attended / target) * 100));
};

const formatMoney = (v) => {
  const n = Number(v || 0);
  if (!Number.isFinite(n)) return '0';
  return n.toLocaleString('en-US');
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
  if (isMonthlyCourse(c)) {
    if (!c.paid) return 'warning';
    return '';
  }
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
    // PRD-B hotfix：以後端為唯一真相，伺服器沒回 students 即表示本人無關聯學生；
    // 主動 null 覆寫以清除舊版本殘留的 localStorage 快取。
    setStudents(data.students || null);
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
  if (loginLoading.value) return;
  loginLoading.value = true;
  try {
    const result = await parentLogin(loginForm.value);
    token.value = result.token;
    localStorage.setItem(tokenKey, result.token);
    setStudents(result.students || null);
    await loadDashboard();
  } catch (error) {
    loginError.value = error.message || '登入失敗，請確認學生姓名及手機號碼是否正確';
  } finally {
    loginLoading.value = false;
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
    const result = await parentLoginLine(profile.userId, urlCampusId || null);
    token.value = result.token;
    localStorage.setItem(tokenKey, result.token);
    setStudents(result.students || null);
    await loadDashboard();
  } catch (e) {
    console.error('LINE auto-login error:', e);
    loginError.value = e.message || 'LINE 登入失敗';
  }
};

const attendanceStatusClass = (status) => {
  const s = String(status || '').toLowerCase();
  if (s === 'present') return 'present';
  if (s === 'late') return 'late';
  if (s === 'absent') return 'absent';
  if (s === 'leave' || s === 'excused') return 'leave';
  return 'other';
};

const attendanceDotClass = (status) => attendanceStatusClass(status);
const attendanceRowClass = (status) => attendanceStatusClass(status);

const attendanceIcon = (status) => {
  const s = attendanceStatusClass(status);
  return ({
    present: 'check',
    late: 'schedule',
    absent: 'close',
    leave: 'event_busy',
  })[s] || 'help';
};

const attendanceLabel = (status) => {
  const s = attendanceStatusClass(status);
  return ({
    present: '到班',
    late: '遲到',
    absent: '缺席',
    leave: '請假',
  })[s] || String(status || '—');
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
  if (token.value) {
    try {
      await loadDashboard();
      if (dashboard.value) {
        // If URL specifies a campus that doesn't match the cached token's student campus,
        // the user likely opened a different branch's link — clear the stale token and
        // fall through to LIFF auto-login so the correct binding is used.
        if (urlCampusId && String(dashboard.value.student?.campus_id) !== String(urlCampusId)) {
          token.value = '';
          localStorage.removeItem(tokenKey);
          dashboard.value = null;
          setStudents(null);
          allLearningRecords.value = [];
          // Fall through to LIFF login below
        } else {
          return;
        }
      }
    } catch { /* token expired, continue to LIFF login */ }
  }

  const isLineInApp = /Line/i.test(navigator.userAgent);
  let liffId = resolveParentLiffId();

  if (!liffId && isLineInApp) {
    liffId = await resolveParentLiffIdAsync();
  }

  const hasLiffId = !!String(liffId).trim();

  if (hasLiffId) await loadLiffSdk();

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
.pp-error { color: #DC2626; font-size: 0.875em; margin: 12px 0 0; padding: 10px 14px; background: #FEE2E2; border-radius: 8px; line-height: 1.5; word-break: break-word; }

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
.pp-btn-primary:disabled { opacity: 0.7; cursor: not-allowed; }
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
.pp-badge-info { background: #e3f2fd; color: #1565c0; }

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

/* PRD-H：月結課程「本月已上 + 月費預估」區塊 */
.pp-monthly-stats {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 12px;
  margin-top: 6px;
}
.pp-monthly-attended { text-align: left; display: flex; align-items: baseline; gap: 4px; }
.pp-monthly-fee { text-align: right; display: flex; flex-direction: column; align-items: flex-end; }
.pp-monthly-fee-amount { font-size: 1.15em; font-weight: 700; color: #37474f; }
.pp-monthly-fee-label { font-size: 0.72em; color: #90a4ae; margin-top: 2px; }

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
.pp-feedback-box { margin-top: 12px; padding: 12px; border: 1px solid #e0e7ff; border-radius: 12px; background: rgba(92,107,192,.06); }
.pp-feedback-title { display: flex; align-items: center; gap: 6px; font-weight: 700; color: #3949ab; }
.pp-feedback-hint, .pp-feedback-time { margin: 6px 0; font-size: .86em; color: #607d8b; }
.pp-feedback-textarea { width: 100%; min-height: 96px; resize: vertical; border: 1px solid #c5cae9; border-radius: 10px; padding: 10px; font: inherit; box-sizing: border-box; }
.pp-feedback-actions { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 8px; }
.pp-feedback-count { font-size: .8em; color: #78909c; }
.pp-feedback-count.warn { color: #e65100; }
.pp-feedback-submit { min-height: 44px; }
.pp-feedback-error { margin-top: 6px; }

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
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
}
.pp-session-num {
  font-size: 0.75em; font-weight: 600; color: #1e40af;
  background: #dbeafe; padding: 1px 7px; border-radius: 8px;
  white-space: nowrap;
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
.pp-timeline-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; position: relative; min-height: 44px; }
.pp-timeline-item.absent { background: linear-gradient(90deg, rgba(229,57,53,0.06) 0%, transparent 50%); border-radius: 6px; padding-left: 4px; margin-left: -4px; }
.pp-timeline-dot {
  position: absolute; left: -28px; top: 10px;
  width: 22px; height: 22px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  z-index: 1; background: #90a4ae;
}
.pp-timeline-dot .material-symbols-outlined { font-size: 14px; color: #fff; }
.pp-timeline-dot.present { background: #43a047; }
.pp-timeline-dot.late { background: #fb8c00; }
.pp-timeline-dot.absent { background: #e53935; }
.pp-timeline-dot.leave { background: #757575; }
.pp-timeline-content { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.pp-timeline-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pp-timeline-date { font-size: 0.88em; color: #455a64; font-weight: 600; }
.pp-timeline-status { font-size: 0.82em; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
.pp-timeline-status.present { color: #2e7d32; background: #e8f5e9; }
.pp-timeline-status.late { color: #e65100; background: #fff3e0; }
.pp-timeline-status.absent { color: #c62828; background: #ffebee; }
.pp-timeline-status.leave { color: #424242; background: #eeeeee; }
.pp-timeline-sub { display: flex; flex-wrap: wrap; gap: 10px; font-size: 0.8em; color: #607d8b; }
.pp-timeline-sub span { display: inline-flex; align-items: center; gap: 3px; }
.pp-mini-icon { font-size: 13px !important; vertical-align: middle; }
.pp-empty-hint { font-size: 0.78em !important; color: #b0bec5; margin-top: 4px; }

/* ═══ Skeleton Loader ═══ */
.pp-skeleton-card { padding: 18px; }
.pp-skel {
  background: linear-gradient(90deg, #f0f0f0 0%, #e0e0e0 50%, #f0f0f0 100%);
  background-size: 200% 100%;
  animation: pp-skel-shimmer 1.4s ease-in-out infinite;
  border-radius: 4px;
}
.pp-skel--circle { width: 48px; height: 48px; border-radius: 50%; }
.pp-skel-row { display: flex; align-items: center; gap: 12px; }
@keyframes pp-skel-shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* ═══ Fade In Animation on dashboard render ═══ */
.pp-profile-card, .pp-alert-card { animation: pp-card-fade-in 0.25s ease-out; }
@keyframes pp-card-fade-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

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
.pp-invoice-badges { display: flex; gap: 4px; align-items: center; flex-wrap: wrap; }
.pp-badge-reconciled {
  background: #E8F5E9; color: #2E7D32;
  display: inline-flex; align-items: center; gap: 2px;
}

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
