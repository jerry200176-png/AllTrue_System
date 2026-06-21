<template>
  <div class="pp" data-guide="parent-portal-root">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet" />

    <!-- LIFF auto-login loading -->
    <div class="pp-card pp-loading-card enterprise-loading" v-if="liffLoading">
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
        <p class="pp-hint pp-hint-bind" v-if="autoLineNotBound">
          此 LINE 帳號尚未綁定學生。請用下方「學生姓名 + 手機號碼」登入；或在本官方帳號輸入「綁定 學生姓名 手機號碼」完成綁定後，再從選單開啟。
        </p>
        <p class="pp-hint" v-else-if="autoLineMode">已嘗試透過 LINE 自動登入；若未進入，請用下方學生資料登入。</p>
        <p class="pp-hint" v-else>請輸入學生資料以查看學習狀況</p>
        <ul v-if="!autoLineMode || autoLineNotBound" class="pp-login-benefits" aria-label="登入後可使用">
          <li><strong>逐堂留言給老師</strong>：在「學習評量」展開任一堂課，於底部填寫（老師與主任同步可看）</li>
          <li>課表、出缺勤、繳費提醒集中在一處，不必再逐則訊息翻找</li>
        </ul>
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

      <!-- Student Profile Card (simplified — no fee ring) -->
      <div class="pp-card pp-profile-card enterprise-page-header" data-guide="parent-student-card">
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
      </div>

      <!-- ═══ Progress Hub (PRD enterprise v2) ═══ -->
      <div class="pp-card pp-hub-card enterprise-page-header" v-if="progressSummary" data-guide="parent-progress-hub">
        <div class="pp-hub-header">
          <div class="pp-hub-title">
            <span class="material-symbols-outlined">flag</span>
            進度中心
          </div>
          <span class="pp-hub-week">本週 {{ progressSummary.week_label }}</span>
        </div>
        <div class="pp-hub-grid pp-hub-grid--home">
          <button type="button" class="pp-hub-cell" @click="gotoParentTarget('learning', 'week_progress')">
            <span class="pp-hub-cell-label">本週學習</span>
            <span class="pp-hub-cell-val">
              <strong>{{ progressSummary.week_progress.attended }}</strong>
              <small> / {{ progressSummary.week_progress.scheduled || 0 }} 堂</small>
            </span>
            <span class="pp-hub-cell-cta">查看學習評量</span>
          </button>

          <button type="button" class="pp-hub-cell" :class="{ 'pp-hub-cell--accent': progressSummary.next_session?.is_today }" @click="gotoParentTarget('schedule', 'next_session')">
            <span class="pp-hub-cell-label">下次課程</span>
            <span class="pp-hub-cell-val pp-hub-cell-val--small" v-if="progressSummary.next_session">
              <strong>{{ formatHubDate(progressSummary.next_session.date) }}</strong>
              <small>{{ formatHM(progressSummary.next_session.start_time) }}–{{ formatHM(progressSummary.next_session.end_time) }}</small>
              <small v-if="progressSummary.next_session.subject" class="pp-hub-cell-sub">{{ progressSummary.next_session.subject }}</small>
            </span>
            <span class="pp-hub-cell-val pp-hub-cell-val--small" v-else>
              <strong>—</strong>
              <small>近期無課程</small>
            </span>
            <span class="pp-hub-cell-cta">查看課表</span>
          </button>

          <button type="button" class="pp-hub-cell pp-hub-cell--span-2" :class="paymentHubClass" @click="gotoParentTarget('billing', 'payment')">
            <span class="pp-hub-cell-label">繳費狀態</span>
            <span class="pp-hub-cell-val">
              <strong>{{ progressSummary.payment.paid_courses || 0 }}</strong>
              <small> / {{ progressSummary.payment.total_courses || 0 }} 已繳</small>
            </span>
            <span class="pp-hub-cell-cta">{{ paymentHubLabel }}</span>
          </button>
        </div>
        <!-- 類 Remind / ClassDojo：把「雙向溝通」從隱藏欄位提升為一級入口 -->
        <button
          v-if="allLearningRecords.length"
          type="button"
          class="pp-hub-feedback-cta"
          @click="inviteParentFeedbackFromHub"
        >
          <span class="material-symbols-outlined pp-hub-feedback-cta__icon">mark_chat_unread</span>
          <span class="pp-hub-feedback-cta__main">
            <span class="pp-hub-feedback-cta__title">想跟老師說什麼？</span>
            <span class="pp-hub-feedback-cta__sub">前往學習評量，針對<strong>每一堂課</strong>留言，老師與主任都會收到</span>
          </span>
          <span class="material-symbols-outlined pp-hub-feedback-cta__chev">chevron_right</span>
        </button>
      </div>

      <div class="pp-card pp-parent-update" v-if="progressSummary && parentReleaseNotes.length">
        <button type="button" class="pp-parent-update__btn" @click="openReleaseNote(parentReleaseNotes[0])">
          <span class="material-symbols-outlined pp-parent-update__icon">new_releases</span>
          <span class="pp-parent-update__main">
            <span class="pp-parent-update__k">與您有關的更新</span>
            <span class="pp-parent-update__meta">{{ parentReleaseNotes[0].version }} · {{ parentReleaseNotes[0].date }}</span>
            <span class="pp-parent-update__t">{{ parentNoteTeaser(parentReleaseNotes[0]) }}</span>
          </span>
          <span class="material-symbols-outlined pp-parent-update__chev">chevron_right</span>
        </button>
        <button
          v-if="parentReleaseNotes.length > 1"
          type="button"
          class="pp-parent-update__more"
          @click="openReleaseNote(parentReleaseNotes[1])"
        >
          <span class="pp-parent-update__more-label">上一則</span>
          <span class="pp-parent-update__more-t">{{ parentReleaseNotes[1].version }} — {{ parentNoteTeaser(parentReleaseNotes[1]) }}</span>
        </button>
      </div>
      <div class="pp-card pp-release-detail" v-if="selectedReleaseNote">
        <div class="pp-release-detail-head">
          <strong>{{ selectedReleaseNote.title }}</strong>
          <button type="button" class="pp-link-btn" @click="selectedReleaseNote = null">關閉</button>
        </div>
        <p class="pp-release-detail-summary">{{ selectedReleaseNote.summary }}</p>
      </div>

      <!-- ═══ Tab Bar ═══ -->
      <div class="pp-tab-bar">
        <button :class="['pp-tab', { active: activeTab === 'learning' }]" @click="activeTab = 'learning'">
          <span class="material-symbols-outlined">assignment</span>
          學習
          <span v-if="lrRecordsMissingFeedbackCount > 0" class="pp-tab-badge pp-tab-badge--soft" :title="`有 ${lrRecordsMissingFeedbackCount} 堂尚未留言`">{{ lrRecordsMissingFeedbackCount > 9 ? '9+' : lrRecordsMissingFeedbackCount }}</span>
        </button>
        <button :class="['pp-tab', { active: activeTab === 'schedule' }]" @click="activeTab = 'schedule'">
          <span class="material-symbols-outlined">calendar_today</span>
          課表
        </button>
        <button :class="['pp-tab', { active: activeTab === 'billing' }]" @click="activeTab = 'billing'">
          <span class="material-symbols-outlined">receipt_long</span>
          帳務
          <span v-if="billingBadgeCount > 0" class="pp-tab-badge">{{ billingBadgeCount }}</span>
        </button>
      </div>

      <!-- ═══ Tab: 學習（預設首頁） ═══ -->
      <template v-if="activeTab === 'learning'">

        <!-- Announcements -->
        <div class="pp-card" v-if="(dashboard.announcements || []).length > 0">
          <div class="pp-section-header">
            <span class="material-symbols-outlined pp-section-icon" style="color:var(--ds-warning);">campaign</span>
            <h3>公告</h3>
          </div>
          <div v-for="ann in dashboard.announcements" :key="ann.id" class="pp-announcement">
            <div class="pp-ann-title">{{ ann.Title || ann.title || '公告' }}</div>
            <div class="pp-ann-body" v-if="ann.Content || ann.content">{{ ann.Content || ann.content }}</div>
            <div class="pp-ann-date">{{ ann.created_at ? ann.created_at.substring(0, 10) : '' }}</div>
          </div>
        </div>

        <!-- Learning Records — Report Card Style (paginated) -->
        <div class="pp-card" data-guide="parent-learning-card">
        <div class="pp-section-header">
          <span class="material-symbols-outlined pp-section-icon" style="color:var(--ds-ink-mute);">assignment</span>
          <h3>學習評量</h3>
          <span v-if="lrTotal > 0" class="pp-section-count">共 {{ lrTotal }} 筆</span>
        </div>
        <div v-if="allLearningRecords.length" class="pp-lr-engage-strip">
          <p class="pp-lr-engage-copy">
            <span class="material-symbols-outlined pp-lr-engage-ic" aria-hidden="true">forum</span>
            <span>點開任一堂課後，在<strong>「給老師的回饋」</strong>留言即可與老師互動（主任同步可見）。不會填？可先點下方捷徑。</span>
          </p>
          <button type="button" class="pp-lr-engage-jump" @click="jumpToFirstFeedbackSlot">
            <span class="material-symbols-outlined" style="font-size:18px;">edit_note</span>
            {{ lrRecordsMissingFeedbackCount > 0 ? `前往留言（尚有 ${lrRecordsMissingFeedbackCount} 堂未留）` : '前往補充／更新留言' }}
          </button>
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
                  <span v-if="!record.parent_feedback" class="pp-fb-badge pp-fb-badge--open">可留言</span>
                  <span v-else class="pp-fb-badge pp-fb-badge--done">已回饋</span>
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
                <span class="material-symbols-outlined pp-indicator-icon" style="color:var(--ds-ink-mute);">person</span>
                <span class="pp-indicator-text">{{ record.teacher_name }}</span>
              </div>
              <button type="button" class="pp-fb-quick" @click.stop="openFeedbackForRecord(record)">
                <span class="material-symbols-outlined" style="font-size:16px;">chat</span>
                {{ record.parent_feedback ? '更新給老師的留言' : '留言給老師' }}
              </button>
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
              <div class="pp-feedback-box" :id="'pp-fb-' + (record.id ?? record.ID)" @click.stop="onFeedbackBoxOpen(record)">
                <div class="pp-feedback-title">
                  <span class="material-symbols-outlined">rate_review</span>
                  給老師的回饋
                  <span v-if="record.parent_feedback?.has_unread_reply" class="pp-feedback-newdot">老師回覆了</span>
                </div>
                <p class="pp-feedback-hint">
                  {{ record.parent_feedback ? '已送出給老師與主任查看。' : '有想補充給老師的嗎？可留下問題、觀察或鼓勵。' }}
                </p>
                <div class="pp-feedback-quick" role="group" aria-label="快速帶入開頭">
                  <button
                    v-for="(line, idx) in parentFeedbackStarters"
                    :key="'pfs-' + idx"
                    type="button"
                    class="pp-feedback-chip"
                    @click.stop="appendFeedbackStarter(record, line)"
                  >{{ line }}</button>
                </div>
                <textarea
                  :id="'pp-fb-ta-' + (record.id ?? record.ID)"
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

                <!-- 雙向對話串：老師/主任回覆 + 家長追問 -->
                <div v-if="record.parent_feedback?.replies?.length" class="pp-feedback-thread">
                  <div class="pp-feedback-thread-title">
                    <span class="material-symbols-outlined">forum</span>
                    對話紀錄
                  </div>
                  <div
                    v-for="rep in record.parent_feedback.replies"
                    :key="'pfr-' + rep.id"
                    :class="['pp-feedback-reply', rep.author_role === 'parent' ? 'is-parent' : 'is-staff']"
                  >
                    <div class="pp-feedback-reply-who">
                      {{ rep.author_role === 'parent' ? '我（家長）' : (rep.author_name || (rep.author_role === 'director' ? '主任' : '老師')) }}
                      <span class="pp-feedback-reply-time">{{ formatFeedbackTime(rep.created_at) }}</span>
                    </div>
                    <div class="pp-feedback-reply-body">{{ rep.content }}</div>
                  </div>
                </div>
                <div v-if="record.parent_feedback" class="pp-feedback-followup">
                  <textarea
                    v-model="record._replyDraft"
                    class="pp-feedback-textarea"
                    maxlength="500"
                    aria-label="回覆老師"
                    placeholder="想再回覆老師嗎？例如：謝謝老師，孩子這週有按進度複習。"
                    @click.stop
                  ></textarea>
                  <div class="pp-feedback-actions">
                    <span :class="['pp-feedback-count', { warn: (record._replyDraft || '').length >= 480 }]">{{ (record._replyDraft || '').length }}/500</span>
                    <button class="pp-btn pp-btn-primary pp-feedback-submit" :disabled="record._replySaving || !(record._replyDraft || '').trim()" @click.stop="submitParentReply(record)">
                      {{ record._replySaving ? '送出中...' : '回覆老師' }}
                    </button>
                  </div>
                  <p v-if="record._replyError" class="pp-error pp-feedback-error">{{ record._replyError }}</p>
                </div>
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
        <div class="pp-empty enterprise-empty" v-if="!allLearningRecords.length">
          <span class="material-symbols-outlined">description</span>
          <p>尚無已核准的學習評量紀錄</p>
        </div>
      </div>

        <!-- Attendance Timeline (FR-B-003) -->
        <div class="pp-card">
          <div class="pp-section-header">
            <span class="material-symbols-outlined pp-section-icon" style="color:var(--ds-success);">fact_check</span>
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
          <div class="pp-empty enterprise-empty" v-else>
            <span class="material-symbols-outlined">event_busy</span>
            <p>目前無出缺勤記錄</p>
            <p class="pp-empty-hint">老師完成點名後將自動顯示於此</p>
          </div>
        </div>

        <!-- 家長建議回饋卡片（Brand + Mobile-first） — 與「逐堂給老師留言」分區，避免誤以為只能填這一張 -->
        <div class="pp-card pp-voice-card">
          <p class="pp-voice-scope-hint">
            <span class="material-symbols-outlined pp-voice-scope-ic" aria-hidden="true">info</span>
            此區是給<strong>校方／行政</strong>的意見箱。若要回應<strong>某位老師、某一堂課</strong>，請在上方該堂評量的「給老師的回饋」留言。
          </p>
          <div class="pp-voice-brand">
            <span class="material-symbols-outlined pp-voice-logo">school</span>
            <div>
              <div class="pp-voice-brand-name">台北全真一對一補習班</div>
              <div class="pp-voice-brand-sub">自主研發管理系統 · 您的意見讓我們更好</div>
            </div>
          </div>

          <!-- 成功提示 -->
          <div v-if="fbSuccess" class="pp-voice-success">
            <span class="material-symbols-outlined">check_circle</span>
            感謝您的寶貴建議！全真團隊將認真參考。
          </div>

          <template v-else>
            <!-- 分類 Chips（手機優先，大觸控目標） -->
            <div class="pp-voice-label">選擇類型</div>
            <div class="pp-voice-chips">
              <button v-for="c in fbCategories" :key="c.key"
                type="button"
                :class="['pp-voice-chip', { active: fbCategory === c.key }]"
                @click="fbCategory = c.key">
                {{ c.label }}
              </button>
            </div>

            <!-- 星評（選填） -->
            <div class="pp-voice-label">滿意度 <span class="pp-voice-optional">（選填）</span></div>
            <div class="pp-voice-stars">
              <button v-for="n in 5" :key="n" type="button"
                class="pp-star-btn"
                @click="fbRating = fbRating === n ? 0 : n">
                <span class="material-symbols-outlined"
                  :style="{ color: n <= fbRating ? 'var(--ds-warning)' : 'var(--ds-canvas-soft)', fontSize: '32px' }">
                  {{ n <= fbRating ? 'star' : 'star_border' }}
                </span>
              </button>
            </div>

            <!-- 文字輸入 -->
            <div class="pp-voice-label">您的建議 <span class="pp-voice-required">（最少 10 字）</span></div>
            <textarea
              v-model="fbContent"
              class="pp-voice-textarea"
              maxlength="500"
              rows="4"
              placeholder="例如：孩子對這學期的數學進步很多，希望繼續加強英文…"
              aria-label="建議內容"
            ></textarea>
            <div class="pp-voice-count" :class="{ warn: fbContent.length >= 480 }">
              {{ fbContent.length }} / 500
            </div>

            <p v-if="fbError" class="pp-error">{{ fbError }}</p>

            <button
              class="pp-voice-submit"
              :disabled="!fbCanSubmit"
              @click="submitFeedbackForm">
              <span class="material-symbols-outlined">send</span>
              {{ fbSubmitting ? '送出中…' : '送出建議' }}
            </button>
          </template>
        </div>

      </template>
      <!-- ／學習 Tab -->

      <!-- ═══ Tab: 課表 ═══ -->
      <template v-if="activeTab === 'schedule'">

        <!-- Remaining Sessions by Subject -->
        <div class="pp-card" v-if="dashboard.remaining_by_subject && Object.keys(dashboard.remaining_by_subject).length">
          <div class="pp-section-header">
            <span class="material-symbols-outlined pp-section-icon" style="color:var(--ds-ink-mute);">layers</span>
            <h3>剩餘堂數</h3>
          </div>
          <div class="pp-subject-breakdown">
            <div v-for="(count, subject) in dashboard.remaining_by_subject" :key="subject"
                 class="pp-subject-pill" :style="{ borderColor: remainingPillColor(count) }">
              <span class="pp-pill-subject">{{ subject }}</span>
              <span class="pp-pill-count" :style="{ color: remainingPillColor(count) }">{{ count }}堂</span>
            </div>
          </div>
        </div>

        <!-- Upcoming Sessions -->
        <div class="pp-card" v-if="(dashboard.upcoming_sessions || []).length > 0">
          <div class="pp-section-header">
            <span class="material-symbols-outlined pp-section-icon" style="color:var(--ds-ink-mute);">calendar_today</span>
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
                <button
                  v-if="canRequestLeave(s)"
                  type="button"
                  class="pp-link-btn"
                  @click="openLeaveBox(s)"
                >請假</button>
              </div>
              <div v-if="leaveSessionId === s.id" class="pp-leave-box">
                <label>請假原因（選填）</label>
                <textarea v-model="leaveReason" rows="2" maxlength="500" placeholder="例如：身體不適、學校活動、臨時有事"></textarea>
                <div class="pp-leave-actions">
                  <button class="pp-btn pp-btn-primary pp-btn-small" type="button" :disabled="leaveSubmitting" @click="submitLeaveRequest(s)">
                    {{ leaveSubmitting ? '送出中…' : '送出請假' }}
                  </button>
                  <button class="pp-btn pp-btn-ghost pp-btn-small" type="button" :disabled="leaveSubmitting" @click="closeLeaveBox">取消</button>
                </div>
                <p v-if="leaveError" class="pp-error pp-leave-msg">{{ leaveError }}</p>
              </div>
            </div>
          </div>
          <p v-if="leaveSuccess" class="pp-success-msg">{{ leaveSuccess }}</p>
        </div>
        <div class="pp-empty enterprise-empty" v-if="!(dashboard.upcoming_sessions || []).length">
          <span class="material-symbols-outlined">event_available</span>
          <p>近期無安排課程</p>
        </div>

        <!-- Courses & Remaining Sessions -->
        <!-- PRD-H (2026-04-18)：月結制改顯示「本月已上 X 堂 / 預定 Y 堂」＋「月繳 $Z 預估」，
             堂數制仍維持已用/購買/剩餘；付款狀態 badge 兩種模式共用。 -->
        <div class="pp-card" v-if="(dashboard.classes || []).length > 0" data-guide="parent-classes-card">
          <div class="pp-section-header">
            <span class="material-symbols-outlined pp-section-icon" style="color:var(--ds-success);">menu_book</span>
            <h3>進行中的課程</h3>
          </div>
          <div class="pp-course-grid">
            <div v-for="c in dashboard.classes" :key="c.id" class="pp-course-card" :class="courseCardClass(c)">
              <div class="pp-course-top">
                <span class="pp-course-subject">{{ c.subject || '課程' }}</span>
                <span v-if="isMonthlyCourse(c)" class="pp-badge pp-badge-info">月結</span>
                <span v-if="c.is_package" class="pp-badge pp-badge-info-soft">共用方案</span>
                <span v-if="c.paid" class="pp-badge pp-badge-success">{{ c.payment_status_label || '已繳費' }}</span>
                <span v-else class="pp-badge pp-badge-warning">未繳費</span>
                <span v-if="c.is_stopped" class="pp-badge pp-badge-neutral">{{ c.lifecycle_status_label || '課程已結束' }}</span>
              </div>
              <!-- 堂數制 -->
              <template v-if="!isMonthlyCourse(c)">
                <div class="pp-course-progress">
                  <div class="pp-progress-bar">
                    <div class="pp-progress-fill" :style="{ width: progressPercent(c) + '%', background: progressColor(c) }"></div>
                  </div>
                  <div class="pp-progress-labels">
                    <span>{{ c.is_package ? '方案已上' : '已上' }} {{ c.used_sessions ?? 0 }}</span>
                    <span>{{ c.is_package ? '方案共' : '購買' }} {{ c.sessions_purchased ?? 0 }}</span>
                  </div>
                </div>
                <div class="pp-course-remaining" :style="{ color: remainingColor(c) }">
                  <span class="pp-remaining-number">{{ c.remaining_sessions ?? 0 }}</span>
                  <span class="pp-remaining-label">堂剩餘</span>
                </div>
                <p v-if="c.is_package" class="pp-package-hint">
                  與其他科目共用同一方案（共 {{ c.package_total_sessions ?? 0 }} 堂），剩餘為共用堂數，扣堂一起計算。
                </p>
              </template>
              <!-- 月結制 -->
              <template v-else>
                <div class="pp-course-progress" v-if="(c.monthly_target || 0) > 0">
                  <div class="pp-progress-bar">
                    <div class="pp-progress-fill" :style="{ width: monthlyProgressPercent(c) + '%', background: 'var(--ds-ink-mute)' }"></div>
                  </div>
                  <div class="pp-progress-labels">
                    <span>{{ courseMonthLabel(c) }}已上 {{ c.attended_this_month ?? 0 }}</span>
                    <span>預定 {{ c.monthly_target }} 堂/月</span>
                  </div>
                </div>
                <div class="pp-monthly-stats">
                  <div class="pp-monthly-attended">
                    <span class="pp-remaining-number" style="color:var(--ds-ink-mute);">{{ c.attended_this_month ?? 0 }}</span>
                    <span class="pp-remaining-label">{{ courseMonthLabel(c) }}已上</span>
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

      </template>
      <!-- ／課表 Tab -->

      <!-- ═══ Tab: 帳務 ═══ -->
      <template v-if="activeTab === 'billing'">

        <!-- Payment Alerts (soft info style, not alarming orange) -->
        <div class="pp-card pp-info-card" v-if="(dashboard.payment_alerts || []).length > 0">
          <div class="pp-section-header">
            <span class="material-symbols-outlined pp-section-icon" style="color:var(--ds-ink-mute);">info</span>
            <h3>繳費提醒</h3>
          </div>
          <div v-for="alert in dashboard.payment_alerts" :key="alert.class_id" class="pp-alert-item">
            <span class="pp-subject-chip">{{ alert.subject || '課程' }}</span>
            <div class="pp-alert-badges">
              <span v-if="alert.remaining_sessions <= 0" class="pp-badge pp-badge-neutral">堂數用完</span>
              <span v-else class="pp-badge pp-badge-info-soft">剩餘 {{ alert.remaining_sessions }} 堂</span>
              <span v-if="!alert.paid" class="pp-badge pp-badge-info-warn">待繳費</span>
            </div>
          </div>
          <p class="pp-info-hint">如需繳費，請聯絡補習班。</p>
        </div>
        <div class="pp-empty enterprise-empty" v-if="!(dashboard.payment_alerts || []).length">
          <span class="material-symbols-outlined" style="color:var(--ds-success);">check_circle</span>
          <p>目前無待繳費項目</p>
        </div>

        <!-- Invoices -->
        <div class="pp-card" v-if="(dashboard.invoices || []).length > 0">
          <div class="pp-section-header">
            <span class="material-symbols-outlined pp-section-icon" style="color:var(--ds-danger);">receipt_long</span>
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
        <div class="pp-card">
          <div class="pp-section-header">
            <span class="material-symbols-outlined pp-section-icon" style="color:var(--ds-success);">link</span>
            <h3>LINE 綁定</h3>
          </div>
          <div v-if="lineLinked" class="pp-line-bound">
            <span class="material-symbols-outlined" style="color:var(--ds-success);">verified</span>
            <span>已綁定 LINE，可直接從 LINE 官方帳號進入查看</span>
          </div>
          <div v-else class="pp-line-unbound">
            <p>尚未綁定 LINE。請加入補習班 LINE 官方帳號，並輸入：</p>
            <code class="pp-bind-code">綁定 {{ dashboard.student?.name || '學生姓名' }} 手機號碼</code>
            <p class="pp-hint">綁定後即可透過 LINE 查看剩餘堂數與學習評量。</p>
          </div>
        </div>

      </template>
      <!-- ／帳務 Tab -->

    </template>
  </div>
</template>

<script setup>
import { onMounted, ref, computed, reactive, nextTick } from 'vue';
import { getParentDashboard, parentLogin, parentLoginLine, parentSwitchStudent, upsertParentLearningRecordFeedback, parentReplyLearningRecordFeedback, getParentLearningRecordFeedback, submitParentFeedback, parentRequestLeave } from '../api';
import { notesForRole, parentReleaseNoteTeaser } from '../lib/releaseNotes';
import { trackParentPortalEvent } from '../lib/adoptionTelemetry';

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

// 多分校共用網域時，campus_id 才能定位正確的 LINE Login channel（LIFF）。
// 入口連結一律帶 campus_id，故優先用它解析，避免拿到別分校的 LIFF 導致 userId 對不上、自動登入失敗。
async function resolveParentLiffIdByCampus(campusId) {
  if (!campusId) return '';
  try {
    const res = await fetch('/api/v1/parent/resolve-liff?campus_id=' + encodeURIComponent(campusId));
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
// LINE 自動登入「已嘗試但查無綁定」狀態：用來把登入卡的文案從「正在自動登入…」
// 切換為清楚的綁定/手動登入指引，避免家長卡在矛盾畫面（畫面同時顯示登入中＋錯誤）。
const autoLineNotBound = ref(false);
const lineLinked = computed(() => !!dashboard.value?.student?.line_linked);
const expandedRecords = reactive(new Set());
const showAllAttendance = ref(false);
const activeTab = ref('learning');
const leaveSessionId = ref(null);
const leaveReason = ref('');
const leaveSubmitting = ref(false);
const leaveError = ref('');
const leaveSuccess = ref('');
const selectedReleaseNote = ref(null);

const progressSummary = computed(() => dashboard.value?.progress_summary || null);
const parentReleaseNotes = computed(() => notesForRole('parent').slice(0, 2));

function parentNoteTeaser(note) {
  return parentReleaseNoteTeaser(note);
}

const paymentHubClass = computed(() => {
  const status = progressSummary.value?.payment?.status;
  if (status === 'all_pending') return 'pp-hub-cell--warn';
  if (status === 'partial') return 'pp-hub-cell--accent';
  if (status === 'all_clear') return 'pp-hub-cell--ok';
  return '';
});

const paymentHubLabel = computed(() => {
  const status = progressSummary.value?.payment?.status;
  if (status === 'all_pending') return '前往繳費';
  if (status === 'partial') return '部分待繳，前往查看';
  if (status === 'all_clear') return '本期已全數繳清';
  return '前往帳務';
});

function formatHubDate(value) {
  if (!value) return '—';
  const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${parseInt(m[2], 10)}/${parseInt(m[3], 10)}` : value;
}

function gotoParentTarget(target, source = 'hub_card') {
  const resolved = ['learning', 'schedule', 'billing'].includes(String(target)) ? String(target) : 'learning';
  activeTab.value = resolved;
  trackParentPortalEvent(token.value, 'parent.progress_card_clicked', {
    card: source,
    target: resolved,
  });
}

function openReleaseNote(note) {
  selectedReleaseNote.value = note;
  trackParentPortalEvent(token.value, 'parent.release_note_opened', {
    version: note?.version || '',
  });
}

const billingBadgeCount = computed(() => {
  const alerts = dashboard.value?.payment_alerts || [];
  return alerts.length;
});

// ─── 家長建議回饋 ────────────────────────────────────────────────────
const fbCategory = ref('');
const fbRating = ref(0);
const fbContent = ref('');
const fbSubmitting = ref(false);
const fbError = ref('');
const fbSuccess = ref(false);

const fbCategories = [
  { key: 'teaching', label: '老師教學' },
  { key: 'schedule', label: '課程安排' },
  { key: 'system',   label: '系統功能' },
  { key: 'other',    label: '其他建議' },
];

const fbCanSubmit = computed(() => fbCategory.value && fbContent.value.length >= 10 && !fbSubmitting.value);

async function submitFeedbackForm() {
  if (!fbCanSubmit.value) return;
  fbSubmitting.value = true;
  fbError.value = '';
  try {
    await submitParentFeedback(token.value, {
      category: fbCategory.value,
      content:  fbContent.value.trim(),
      rating:   fbRating.value || null,
    });
    fbSuccess.value = true;
    fbCategory.value = '';
    fbRating.value = 0;
    fbContent.value = '';
    setTimeout(() => { fbSuccess.value = false; }, 5000);
  } catch (e) {
    fbError.value = e.message || '送出失敗，請稍後再試';
  } finally {
    fbSubmitting.value = false;
  }
}
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

/** 已載入的評量中，尚未填「給老師的回饋」的堂數（用於 Tab 角標／引導） */
const lrRecordsMissingFeedbackCount = computed(
  () => (allLearningRecords.value || []).filter((r) => !r.parent_feedback).length
);

/** 降低空白恐懼：一鍵帶入禮貌開頭（可再改寫）— 類似 Slack / Intercom quick replies */
const parentFeedbackStarters = [
  '謝謝老師這週的指導。',
  '孩子回家說這堂課內容大致跟得上。',
  '想請老師協助加強：',
  '作業量對孩子來說',
  '想約時間與老師電話／面談，請方便時回覆。',
];

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
    trackParentPortalEvent(token.value, 'parent.learning_feedback_submitted', {
      record_id: id,
    });
  } catch (e) {
    record._feedbackError = e?.message || '暫時無法送出，請稍後再試';
  } finally {
    record._feedbackSaving = false;
  }
};

// 家長追問：在既有評量回饋上追加一則家長回覆，送出後重新取得對話串（同時清掉「有新回覆」紅點）。
const submitParentReply = async (record) => {
  const content = String(record._replyDraft || '').trim();
  record._replyError = '';
  if (!content) {
    record._replyError = '請輸入回覆內容';
    return;
  }
  if (content.length > 500) {
    record._replyError = '回覆內容不可超過 500 字';
    return;
  }
  const id = record.id ?? record.ID;
  record._replySaving = true;
  try {
    await parentReplyLearningRecordFeedback(token.value, id, content);
    record._replyDraft = '';
    const refreshed = await getParentLearningRecordFeedback(token.value, id);
    if (refreshed) record.parent_feedback = refreshed;
    trackParentPortalEvent(token.value, 'parent.learning_feedback_reply', { record_id: id });
  } catch (e) {
    record._replyError = e?.message || '暫時無法送出，請稍後再試';
  } finally {
    record._replySaving = false;
  }
};

// 家長點開回饋區塊：準備草稿並（若已有回饋）載入最新對話串、標記已讀。
const onFeedbackBoxOpen = (record) => {
  prepareFeedbackDraft(record);
  if (record.parent_feedback && !record._threadLoaded) {
    record._threadLoaded = true;
    loadFeedbackThread(record);
  }
};

// 家長展開對話時取得最新回覆並標記已讀（清除紅點）。
const loadFeedbackThread = async (record) => {
  const id = record.id ?? record.ID;
  if (id == null || !record.parent_feedback) return;
  try {
    const refreshed = await getParentLearningRecordFeedback(token.value, id);
    if (refreshed) record.parent_feedback = refreshed;
  } catch (e) {
    // 靜默：對話串載入失敗不影響評量本身顯示
  }
};

const appendFeedbackStarter = (record, line) => {
  prepareFeedbackDraft(record);
  const cur = String(record._feedbackDraft || '').trim();
  record._feedbackDraft = cur ? `${cur}\n${line}` : line;
  trackParentPortalEvent(token.value, 'parent.learning_feedback_starter', { line_len: line.length });
};

const openFeedbackForRecord = async (record, source = 'card_quick') => {
  const id = record.id ?? record.ID;
  if (id == null) return;
  expandedRecords.add(id);
  prepareFeedbackDraft(record);
  await nextTick();
  await new Promise((r) => setTimeout(r, 80));
  document.getElementById(`pp-fb-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  document.getElementById(`pp-fb-ta-${id}`)?.focus();
  trackParentPortalEvent(token.value, 'parent.learning_feedback_opened', { record_id: id, source });
};

const jumpToFirstFeedbackSlot = async (source = 'engage_strip') => {
  const list = allLearningRecords.value || [];
  if (!list.length) return;
  const target = list.find((r) => !r.parent_feedback) || list[0];
  await openFeedbackForRecord(target, source);
};

const inviteParentFeedbackFromHub = async () => {
  activeTab.value = 'learning';
  trackParentPortalEvent(token.value, 'parent.hub_feedback_cta', {
    missing_count: lrRecordsMissingFeedbackCount.value,
  });
  await nextTick();
  await new Promise((r) => setTimeout(r, 220));
  await jumpToFirstFeedbackSlot('hub_cta');
};

const statusLabel = (s) => {
  const map = { scheduled: '排定', rescheduled: '已調課', leave_requested: '已請假', cancelled: '已取消', completed: '已完成' };
  return map[s] || s;
};

const canRequestLeave = (session) => ['scheduled', 'rescheduled'].includes(String(session?.Status || '').toLowerCase());

const openLeaveBox = (session) => {
  leaveSessionId.value = session?.id ?? null;
  leaveReason.value = '';
  leaveError.value = '';
  leaveSuccess.value = '';
};

const closeLeaveBox = () => {
  leaveSessionId.value = null;
  leaveReason.value = '';
  leaveError.value = '';
};

const submitLeaveRequest = async (session) => {
  if (!session?.id || leaveSubmitting.value) return;
  leaveSubmitting.value = true;
  leaveError.value = '';
  leaveSuccess.value = '';
  try {
    const result = await parentRequestLeave(token.value, session.id, { reason: leaveReason.value });
    session.Status = result?.session?.status || 'leave_requested';
    leaveSuccess.value = '請假申請已送出，補習班會安排補課時段。';
    trackParentPortalEvent(token.value, 'parent.leave_submitted', {
      session_id: session.id,
      status: session.Status,
    });
    closeLeaveBox();
  } catch (e) {
    leaveError.value = e?.message || '請假申請失敗，請稍後再試';
  } finally {
    leaveSubmitting.value = false;
  }
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

const courseMonthLabel = (c) => c?.display_month_label || dashboard.value?.current_month_label || '本月';

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
const perfColor = (v) => ({ good: 'var(--ds-success)', average: 'var(--ds-warning)', bad: 'var(--ds-danger)' }[v] || 'var(--ds-ink-mute)');
const perfIcon = (v) => ({ good: 'sentiment_satisfied', average: 'sentiment_neutral', bad: 'sentiment_dissatisfied' }[v] || 'help');
const perfLabel = (v) => ({ good: '表現優良', average: '表現普通', bad: '需加強' }[v] || v || '—');

// Homework status helpers
const hwColor = (v) => ({ completed: 'var(--ds-success)', partial: 'var(--ds-warning)', incomplete: 'var(--ds-warning)', missing: 'var(--ds-danger)' }[v] || 'var(--ds-ink-mute)');
const hwIcon = (v) => ({ completed: 'task_alt', partial: 'pending', incomplete: 'warning', missing: 'cancel' }[v] || 'help');
const hwLabel = (v) => ({ completed: '已完成', partial: '部分完成', incomplete: '未完成', missing: '未繳交' }[v] || v || '—');

const courseCardClass = (c) => {
  if (c.is_stopped) return c.paid ? 'settled' : 'stopped';
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
    trackParentPortalEvent(token.value, 'parent.dashboard_opened', {
      student_id: data?.student?.id || null,
      pending_total: data?.progress_summary?.pending_total || 0,
    });
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
    autoLineNotBound.value = false;
    await loadDashboard();
  } catch (e) {
    console.error('LINE auto-login error:', e);
    const msg = e.message || 'LINE 登入失敗';
    // 查無綁定（後端 404）：不要顯示紅字錯誤，改走清楚的綁定/手動登入指引。
    if (/尚未綁定|未綁定/.test(msg)) {
      autoLineNotBound.value = true;
      loginError.value = '';
    } else {
      loginError.value = msg;
    }
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
    activeTab.value = 'learning';
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

  // 共用網域多分校：campus_id 指定的分校 LIFF 為權威，覆蓋 build-time 預設，
  // 確保拿到「這位家長所屬分校」的 LINE Login channel，userId 才能對上綁定。
  if (urlCampusId) {
    const byCampus = await resolveParentLiffIdByCampus(urlCampusId);
    if (byCampus) liffId = byCampus;
  }

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
  color: var(--ds-ink);
  min-height: 100vh;
  min-height: 100dvh;
  background: var(--ds-canvas-soft);
}

.pp-card {
  background: var(--ds-canvas);
  border-radius: 12px;
  padding: 16px 18px;
  margin-bottom: 12px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.06), 0 0 1px rgba(0,0,0,0.08);
}

.pp-hint { color: var(--ds-ink-mute); font-size: 0.85em; margin: 4px 0 0; }
.pp-hint-bind {
  color: var(--ds-ink);
  background: var(--ds-canvas-soft);
  border-radius: 10px;
  padding: 10px 12px;
  line-height: 1.6;
  text-align: left;
}
.pp-error { color: var(--ds-danger); font-size: 0.875em; margin: 12px 0 0; padding: 10px 14px; background: var(--ds-danger-wash); border-radius: 8px; line-height: 1.5; word-break: break-word; }

/* ═══ Loading ═══ */
.pp-loading-card { text-align: center; padding: 40px 20px; }
.pp-spinner {
  width: 36px; height: 36px; margin: 0 auto 12px;
  border: 3px solid var(--ds-canvas-soft); border-top-color: var(--ds-success);
  border-radius: 50%; animation: pp-spin 0.8s linear infinite;
}
@keyframes pp-spin { to { transform: rotate(360deg); } }
.pp-loading-text { color: var(--ds-success); font-weight: 600; font-size: 0.95em; }

/* ═══ Login ═══ */
.pp-login-card { padding: 24px 20px; }
.pp-login-header { text-align: center; margin-bottom: 20px; }
.pp-login-icon { margin-bottom: 8px; }
.pp-login-icon .material-symbols-outlined { font-size: 40px; color: var(--primary, var(--ds-primary)); }
.pp-login-header h2 { margin: 0; font-size: 1.3em; color: var(--ds-ink); }
.pp-login-form { display: flex; flex-direction: column; gap: 14px; }
.pp-field label {
  display: flex; align-items: center; gap: 4px;
  font-weight: 600; font-size: 0.88em; margin-bottom: 5px; color: var(--ds-ink);
}
.pp-field-icon { font-size: 18px; color: var(--ds-ink-mute); }
.pp-field input {
  width: 100%; padding: 10px 12px; border: 1.5px solid var(--ds-canvas-soft);
  border-radius: 8px; box-sizing: border-box; font-size: 16px;
  transition: border-color 0.2s;
}
.pp-field input:focus { border-color: var(--primary, var(--ds-primary)); outline: none; }
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
.pp-btn-primary { background: var(--primary, var(--ds-primary)); color: var(--ds-canvas); flex: 1; justify-content: center; }
.pp-btn-primary:disabled { opacity: 0.7; cursor: not-allowed; }
.pp-btn-primary .material-symbols-outlined { font-size: 20px; }
.pp-btn-line { background: var(--ds-success); color: var(--ds-canvas); flex: 1; justify-content: center; }
.pp-btn-small {
  padding: 4px 12px; font-size: 0.8em; font-weight: 600;
  background: var(--ds-canvas-soft); color: var(--ds-ink-mute); border: none; border-radius: 6px;
  cursor: pointer; min-height: 32px;
}
.pp-btn-ghost { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.pp-link-btn {
  border: none; background: var(--ds-warning-wash); color: var(--ds-primary);
  border-radius: 999px; padding: 4px 9px; font-size: 0.78em;
  font-weight: 700; cursor: pointer;
}
.pp-btn-logout {
  background: none; border: 1px solid var(--ds-canvas-soft); border-radius: 8px;
  padding: 6px; cursor: pointer; color: var(--ds-ink-mute);
  display: flex; align-items: center;
}
.pp-btn-logout:hover { background: var(--ds-canvas); }
.pp-btn-more {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  width: 100%; padding: 8px; margin-top: 8px;
  background: var(--ds-canvas); border: 1px solid var(--ds-canvas-soft); border-radius: 8px;
  font-size: 0.85em; color: var(--ds-ink-mute); cursor: pointer;
}

/* ═══ Profile Card ═══ */
.pp-profile-card { padding: 18px; }
.pp-profile-top { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.pp-avatar {
  width: 48px; height: 48px; border-radius: 50%;
  background: linear-gradient(135deg, var(--ds-primary), var(--ds-danger));
  color: var(--ds-canvas); font-size: 22px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.pp-profile-info { flex: 1; min-width: 0; }
.pp-student-name { margin: 0; font-size: 1.2em; color: var(--ds-ink); }
.pp-meta-row { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.pp-tag {
  display: inline-block; padding: 2px 8px; border-radius: 4px;
  font-size: 0.75em; font-weight: 600;
}
.pp-tag-grade { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.pp-tag-school { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.pp-tag-campus { background: var(--ds-success-wash); color: var(--ds-success); }

.pp-student-switcher { padding: 10px 0 4px; border-top: 1px solid var(--ds-canvas-soft); margin-top: 4px; }
.pp-switcher-label { display: flex; align-items: center; gap: 4px; font-size: 0.82em; color: var(--ds-ink-mute); margin-bottom: 8px; }
.pp-switcher-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.pp-chip {
  padding: 6px 14px; border-radius: 20px; border: 1.5px solid var(--ds-canvas-soft);
  background: var(--ds-canvas); font-size: 0.88em; cursor: pointer; transition: all 0.2s;
  color: var(--ds-ink); font-weight: 500;
}
.pp-chip:hover:not(.active):not(:disabled) { border-color: var(--ds-primary); color: var(--ds-primary); background: var(--ds-warning-wash); }
.pp-chip.active { background: var(--ds-primary); color: var(--ds-canvas); border-color: var(--ds-primary); cursor: default; }
.pp-chip:disabled { opacity: 0.6; cursor: wait; }

.pp-sessions-summary { display: flex; align-items: center; gap: 16px; padding: 12px 0 4px; }
.pp-ring-container { position: relative; width: 80px; height: 80px; flex-shrink: 0; }
.pp-ring { width: 100%; height: 100%; }
.pp-ring-value {
  position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
  text-align: center;
}
.pp-ring-number { font-size: 22px; font-weight: 800; display: block; line-height: 1; }
.pp-ring-label { font-size: 11px; color: var(--ds-ink-mute); }
.pp-sessions-detail { flex: 1; }
.pp-sessions-title { font-weight: 700; font-size: 1em; }
.pp-sessions-sub { font-size: 0.82em; color: var(--ds-ink-mute); }
.pp-line-status { display: flex; align-items: center; gap: 4px; margin-top: 4px; font-size: 0.82em; color: var(--ds-success); }

/* ═══ Subject Breakdown Pills ═══ */
.pp-subject-breakdown {
  display: flex; flex-wrap: wrap; gap: 8px;
  margin-top: 12px; padding-top: 12px;
  border-top: 1px solid var(--ds-canvas-soft);
}
.pp-subject-pill {
  display: flex; align-items: center; gap: 6px;
  background: var(--ds-canvas); border: 1.5px solid var(--ds-canvas-soft);
  border-radius: 20px; padding: 5px 14px;
  font-size: 0.85em;
}
.pp-pill-subject { color: var(--ds-ink); font-weight: 600; }
.pp-pill-count { font-weight: 800; }

/* ═══ Section Header ═══ */
.pp-section-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.pp-section-icon { font-size: 22px; }
.pp-section-header h3 { margin: 0; font-size: 1.05em; color: var(--ds-ink); }

/* ═══ Alert Card ═══ */

/* ═══ Progress Hub (PRD enterprise v2) ═══ */
.pp-hub-card { padding: 14px 14px 12px; }
.pp-hub-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px;
}
.pp-hub-title {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 14px; font-weight: 800; color: var(--ds-ink);
}
.pp-hub-week { font-size: 12px; color: var(--ds-ink-mute); font-weight: 600; }
.pp-hub-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}
.pp-hub-grid--home .pp-hub-cell--span-2 {
  grid-column: 1 / -1;
}
.pp-hub-cell {
  position: relative;
  display: flex; flex-direction: column; align-items: flex-start; gap: 6px;
  padding: 12px 12px 14px;
  background: var(--ds-canvas);
  border: 1px solid rgba(148, 163, 184, 0.28);
  border-radius: 14px;
  cursor: pointer;
  text-align: left;
  transition: border-color 0.15s ease, transform 0.15s ease, background 0.15s ease;
}
.pp-hub-cell:hover {
  border-color: rgba(15, 23, 42, 0.32);
  transform: translateY(-1px);
  background: var(--ds-canvas-soft);
}
.pp-hub-cell-label {
  font-size: 11px; font-weight: 800; color: var(--ds-ink-mute);
  text-transform: uppercase; letter-spacing: 0.06em;
}
.pp-hub-cell-val {
  display: inline-flex; align-items: baseline; gap: 4px;
  font-size: 14px; color: var(--ds-ink);
}
.pp-hub-cell-val strong {
  font-size: 24px; font-weight: 800; color: var(--ds-ink);
  font-variant-numeric: tabular-nums;
}
.pp-hub-cell-val--small strong { font-size: 16px; }
.pp-hub-cell-val--small { display: flex; flex-direction: column; gap: 2px; }
.pp-hub-cell-val--small small { font-size: 12px; color: var(--ds-ink); }
.pp-hub-cell-sub { color: var(--ds-ink-mute) !important; }
.pp-hub-cell-cta {
  font-size: 12px; font-weight: 700; color: var(--ds-ink-mute);
}
.pp-hub-cell--accent { border-color: rgba(245, 158, 11, 0.55); background: var(--ds-warning-wash); }
.pp-hub-cell--accent:hover { background: var(--ds-warning-wash); }
.pp-hub-cell--warn { border-color: rgba(220, 38, 38, 0.45); background: var(--ds-danger-wash); }
.pp-hub-cell--warn:hover { background: var(--ds-danger-wash); }
.pp-hub-cell--warn .pp-hub-cell-cta { color: var(--ds-danger); }
.pp-hub-cell--ok { border-color: rgba(34, 197, 94, 0.4); background: var(--ds-success-wash); }
.pp-hub-cell--ok .pp-hub-cell-cta { color: var(--ds-success); }
.pp-parent-update {
  margin-top: 10px;
  margin-bottom: 8px;
  padding: 0;
  overflow: hidden;
  border-radius: 14px;
}
.pp-parent-update__btn {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  width: 100%;
  padding: 12px 14px;
  border: none;
  background: var(--ds-canvas-soft);
  text-align: left;
  cursor: pointer;
  font: inherit;
  color: inherit;
  -webkit-tap-highlight-color: transparent;
}
.pp-parent-update__btn:active { background: var(--ds-canvas-soft); }
.pp-parent-update__icon {
  font-size: 24px;
  color: var(--ds-ink-mute);
  flex-shrink: 0;
  margin-top: 2px;
}
.pp-parent-update__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.pp-parent-update__k {
  font-size: 11px;
  font-weight: 800;
  color: var(--ds-ink-mute);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.pp-parent-update__meta {
  font-size: 11px;
  font-weight: 700;
  color: var(--ds-ink-mute);
}
.pp-parent-update__t {
  font-size: 14px;
  color: var(--ds-ink);
  line-height: 1.45;
  font-weight: 600;
}
.pp-parent-update__chev {
  font-size: 22px;
  color: var(--ds-ink-mute);
  flex-shrink: 0;
  align-self: center;
}
.pp-parent-update__more {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  width: 100%;
  padding: 10px 14px 12px;
  border: none;
  border-top: 1px solid rgba(148, 163, 184, 0.22);
  background: var(--ds-canvas);
  text-align: left;
  cursor: pointer;
  font: inherit;
  color: var(--ds-ink);
  -webkit-tap-highlight-color: transparent;
}
.pp-parent-update__more:active { background: var(--ds-canvas); }
.pp-parent-update__more-label {
  font-size: 10px;
  font-weight: 800;
  color: var(--ds-ink-mute);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.pp-parent-update__more-t {
  font-size: 13px;
  line-height: 1.4;
  color: var(--ds-ink);
}
.pp-release-detail {
  margin-top: 8px;
  margin-bottom: 8px;
  padding: 12px 14px;
  border: 1px solid rgba(148, 163, 184, 0.25);
  background: var(--ds-canvas);
}
.pp-release-detail-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.pp-release-detail-summary { margin: 8px 0 0; color: var(--ds-ink); font-size: 13px; line-height: 1.5; }
.pp-empty--inline { padding: 10px 0 0; min-height: 0; }

@media (max-width: 480px) {
  .pp-hub-grid--home { grid-template-columns: 1fr; }
  .pp-hub-grid--home .pp-hub-cell--span-2 { grid-column: auto; }
}

/* ═══ Tab Bar ═══ */
.pp-tab-bar {
  display: flex; gap: 0; background: var(--ds-canvas); border-radius: 12px;
  box-shadow: 0 1px 4px rgba(0,0,0,.08); overflow: hidden; margin-bottom: 4px;
}
.pp-tab {
  flex: 1; display: flex; flex-direction: column; align-items: center;
  gap: 3px; padding: 10px 4px; background: none; border: none;
  cursor: pointer; font-size: 0.8em; color: var(--ds-ink-mute); transition: color .2s, background .2s;
  position: relative;
}
.pp-tab .material-symbols-outlined { font-size: 20px; }
.pp-tab.active { color: var(--ds-ink-mute); background: var(--ds-canvas-soft); font-weight: 600; }
.pp-tab:not(.active):hover { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.pp-tab-badge {
  position: absolute; top: 6px; right: calc(50% - 18px);
  background: var(--ds-danger); color: var(--ds-canvas); border-radius: 10px;
  font-size: 0.7em; padding: 1px 5px; font-weight: 700; min-width: 16px;
  text-align: center;
}
.pp-tab-badge--soft {
  background: var(--ds-ink-mute);
  color: var(--ds-canvas);
  font-weight: 800;
}

.pp-login-benefits {
  margin: 12px 0 0;
  padding: 0 0 0 1.1em;
  font-size: 0.82em;
  color: var(--ds-ink-mute);
  line-height: 1.55;
}
.pp-login-benefits li { margin: 6px 0; }

.pp-hub-feedback-cta {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  margin-top: 12px;
  padding: 12px 14px;
  border-radius: 12px;
  border: 1px solid var(--ds-canvas-soft);
  background: linear-gradient(135deg, var(--ds-canvas-soft) 0%, var(--ds-canvas-soft) 100%);
  cursor: pointer;
  text-align: left;
  font: inherit;
  color: var(--ds-ink);
  transition: box-shadow 0.15s, border-color 0.15s;
  -webkit-tap-highlight-color: transparent;
}
.pp-hub-feedback-cta:active {
  box-shadow: inset 0 1px 4px rgba(49, 27, 146, 0.08);
}
.pp-hub-feedback-cta__icon {
  font-size: 28px;
  color: var(--ds-ink-mute);
  flex-shrink: 0;
}
.pp-hub-feedback-cta__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.pp-hub-feedback-cta__title {
  font-weight: 800;
  font-size: 0.95em;
}
.pp-hub-feedback-cta__sub {
  font-size: 0.78em;
  color: var(--ds-ink-mute);
  opacity: 0.92;
  line-height: 1.35;
}
.pp-hub-feedback-cta__chev {
  font-size: 22px;
  color: var(--ds-ink-mute);
  flex-shrink: 0;
}

.pp-lr-engage-strip {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 0 0 14px;
  padding: 12px 14px;
  border-radius: 12px;
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-canvas-soft);
}
.pp-lr-engage-copy {
  margin: 0;
  display: flex;
  gap: 8px;
  align-items: flex-start;
  font-size: 0.82em;
  color: var(--ds-ink);
  line-height: 1.5;
}
.pp-lr-engage-ic {
  font-size: 20px;
  color: var(--ds-ink-mute);
  flex-shrink: 0;
  margin-top: 1px;
}
.pp-lr-engage-jump {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  min-height: 46px;
  padding: 10px 14px;
  border-radius: 10px;
  border: none;
  background: var(--ds-ink-mute);
  color: var(--ds-canvas);
  font-weight: 700;
  font-size: 0.88em;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.pp-lr-engage-jump:active {
  filter: brightness(0.95);
}

.pp-fb-badge {
  font-size: 0.68em;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
  white-space: nowrap;
}
.pp-fb-badge--open {
  background: var(--ds-warning-wash);
  color: var(--ds-primary);
  border: 1px solid var(--ds-warning);
}
.pp-fb-badge--done {
  background: var(--ds-success-wash);
  color: var(--ds-success);
  border: 1px solid var(--ds-success);
}

.pp-fb-quick {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: auto;
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid var(--ds-canvas-soft);
  background: var(--ds-canvas);
  color: var(--ds-ink-mute);
  font-size: 0.8em;
  font-weight: 700;
  cursor: pointer;
  flex-shrink: 0;
  -webkit-tap-highlight-color: transparent;
}
.pp-fb-quick:active {
  background: var(--ds-canvas-soft);
}

.pp-feedback-quick {
  display: flex;
  flex-wrap: nowrap;
  gap: 8px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  margin: 8px 0 6px;
  padding-bottom: 4px;
}
.pp-feedback-chip {
  flex-shrink: 0;
  max-width: 220px;
  padding: 8px 12px;
  border-radius: 999px;
  border: 1px dashed var(--ds-ink-mute);
  background: var(--ds-canvas);
  color: var(--ds-ink-mute);
  font-size: 0.78em;
  line-height: 1.25;
  cursor: pointer;
  text-align: left;
  -webkit-tap-highlight-color: transparent;
}
.pp-feedback-chip:active {
  background: var(--ds-canvas-soft);
}

.pp-voice-scope-hint {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  margin: 0 0 14px;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.65);
  border: 1px solid var(--ds-ink-mute);
  font-size: 0.8em;
  color: var(--ds-ink);
  line-height: 1.45;
}
.pp-voice-scope-ic {
  font-size: 18px;
  color: var(--ds-ink-mute);
  flex-shrink: 0;
  margin-top: 1px;
}

/* ═══ Info Card (帳務：非警報色，柔和藍) ═══ */
.pp-info-card { border-left: 4px solid var(--ds-ink-mute); }
.pp-info-hint { font-size: 0.82em; color: var(--ds-ink-mute); margin: 10px 0 0; }

.pp-alert-card { border-left: 4px solid var(--ds-warning); }
.pp-alert-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 0; border-bottom: 1px solid var(--ds-canvas-soft); flex-wrap: wrap; gap: 6px;
}
.pp-alert-item:last-child { border-bottom: none; }
.pp-subject-chip {
  background: var(--ds-canvas-soft); color: var(--ds-ink-mute); padding: 3px 10px;
  border-radius: 6px; font-size: 0.85em; font-weight: 600;
}
.pp-alert-badges { display: flex; gap: 4px; flex-wrap: wrap; }

/* ═══ Badges ═══ */
.pp-badge {
  display: inline-block; padding: 2px 10px; border-radius: 10px;
  font-size: 0.78em; font-weight: 600; white-space: nowrap;
}
.pp-badge-success { background: var(--ds-success-wash); color: var(--ds-success); }
.pp-badge-warning { background: var(--ds-warning-wash); color: var(--ds-primary); }
.pp-badge-danger { background: var(--ds-danger-wash); color: var(--ds-danger); }
.pp-badge-info { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.pp-badge-neutral { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.pp-badge-info-soft { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.pp-badge-info-warn { background: var(--ds-primary-wash); color: var(--ds-warning); }

/* ═══ 家長建議回饋卡片（Voice Card）═══ */
.pp-voice-card {
  background: linear-gradient(135deg, var(--ds-canvas-soft) 0%, var(--ds-canvas-soft) 100%);
  border: 1px solid var(--ds-canvas-soft);
}
.pp-voice-brand {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 16px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--ds-canvas-soft);
}
.pp-voice-logo { font-size: 28px; color: var(--ds-ink-mute); flex-shrink: 0; }
.pp-voice-brand-name { font-size: 0.95em; font-weight: 700; color: var(--ds-ink-mute); line-height: 1.3; }
.pp-voice-brand-sub { font-size: 0.75em; color: var(--ds-ink-mute); margin-top: 2px; }

.pp-voice-label { font-size: 0.82em; font-weight: 600; color: var(--ds-ink); margin: 12px 0 6px; }
.pp-voice-optional { font-weight: 400; color: var(--ds-ink-mute); }
.pp-voice-required { font-weight: 400; color: var(--ds-danger); }

.pp-voice-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.pp-voice-chip {
  padding: 9px 16px; border-radius: 20px; border: 1.5px solid var(--ds-ink-mute);
  background: var(--ds-canvas); color: var(--ds-ink-mute); font-size: 0.88em; cursor: pointer;
  transition: all .15s; min-height: 40px;
}
.pp-voice-chip.active {
  background: var(--ds-ink-mute); color: var(--ds-canvas); border-color: var(--ds-ink-mute); font-weight: 600;
}
.pp-voice-chip:not(.active):active { background: var(--ds-canvas-soft); }

.pp-voice-stars { display: flex; gap: 4px; align-items: center; padding: 4px 0; }
.pp-star-btn {
  background: none; border: none; cursor: pointer; padding: 4px;
  min-width: 44px; min-height: 44px; display: flex; align-items: center; justify-content: center;
}

.pp-voice-textarea {
  width: 100%; border: 1.5px solid var(--ds-ink-mute); border-radius: 10px;
  padding: 12px; font-size: 0.92em; line-height: 1.6; resize: vertical;
  background: var(--ds-canvas); outline: none; box-sizing: border-box; font-family: inherit;
  transition: border-color .2s;
}
.pp-voice-textarea:focus { border-color: var(--ds-ink-mute); }
.pp-voice-count { text-align: right; font-size: 0.75em; color: var(--ds-ink-mute); margin-top: 4px; }
.pp-voice-count.warn { color: var(--ds-danger); }

.pp-voice-submit {
  margin-top: 14px; width: 100%; padding: 14px;
  background: var(--ds-ink-mute); color: var(--ds-canvas); border: none; border-radius: 12px;
  font-size: 1em; font-weight: 700; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: background .2s; min-height: 50px;
}
.pp-voice-submit:disabled { background: var(--ds-ink-mute); cursor: not-allowed; }
.pp-voice-submit:not(:disabled):active { background: var(--ds-ink); }
.pp-voice-submit .material-symbols-outlined { font-size: 20px; }

.pp-voice-success {
  display: flex; align-items: center; gap: 10px;
  background: var(--ds-success-wash); color: var(--ds-success); border-radius: 10px;
  padding: 14px 16px; font-weight: 600; font-size: 0.95em;
}
.pp-voice-success .material-symbols-outlined { font-size: 24px; flex-shrink: 0; }

/* ═══ Upcoming Sessions ═══ */
.pp-session-list { display: flex; flex-direction: column; gap: 0; }
.pp-session-item {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  padding: 10px 0; border-bottom: 1px solid var(--ds-canvas-soft);
}
.pp-session-item:last-child { border-bottom: none; }
.pp-session-date-col { text-align: center; min-width: 44px; }
.pp-session-day { font-size: 1em; font-weight: 700; color: var(--ds-ink); }
.pp-session-weekday { font-size: 0.75em; color: var(--ds-ink-mute); }
.pp-session-info { flex: 1; min-width: 0; }
.pp-session-subject { font-weight: 600; font-size: 0.92em; }
.pp-session-time { font-size: 0.82em; color: var(--ds-ink-mute); }
.pp-session-action { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.pp-status-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--ds-ink-mute); flex-shrink: 0;
}
.pp-status-dot.scheduled { background: var(--ds-ink-mute); }
.pp-status-dot.rescheduled { background: var(--ds-warning); }
.pp-status-dot.leave_requested { background: var(--ds-ink-mute); }
.pp-status-text { font-size: 0.78em; color: var(--ds-ink-mute); }
.pp-leave-box {
  flex: 1 0 100%;
  margin-left: 56px;
  padding: 10px 12px;
  border-radius: 10px;
  background: var(--ds-warning-wash);
  border: 1px solid var(--ds-warning-wash);
}
.pp-leave-box label { display: block; font-size: 0.8em; font-weight: 700; color: var(--ds-danger); margin-bottom: 6px; }
.pp-leave-box textarea {
  width: 100%; box-sizing: border-box; border: 1px solid var(--ds-warning);
  border-radius: 8px; padding: 8px 10px; font-size: 0.9em; resize: vertical;
}
.pp-leave-actions { display: flex; gap: 8px; margin-top: 8px; }
.pp-leave-msg { margin-top: 6px; }
.pp-success-msg {
  margin: 10px 0 0; padding: 9px 10px; border-radius: 8px;
  background: var(--ds-success-wash); color: var(--ds-success); font-size: 0.86em; font-weight: 600;
}

/* ═══ Course Cards ═══ */
.pp-course-grid { display: flex; flex-direction: column; gap: 10px; }
.pp-course-card {
  background: var(--ds-canvas); border-radius: 10px; padding: 14px;
  border: 1px solid var(--ds-canvas-soft); transition: border-color 0.2s;
}
.pp-course-card.warning { border-color: var(--ds-warning); }
.pp-course-card.danger { border-color: var(--ds-danger); }
.pp-course-card.stopped { opacity: 0.6; }
.pp-course-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.pp-course-subject { font-weight: 700; font-size: 0.95em; }
.pp-course-progress { margin-bottom: 8px; }
.pp-progress-bar {
  height: 6px; background: var(--ds-canvas-soft); border-radius: 3px; overflow: hidden;
}
.pp-progress-fill { height: 100%; border-radius: 3px; transition: width 0.4s ease; }
.pp-progress-labels { display: flex; justify-content: space-between; font-size: 0.75em; color: var(--ds-ink-mute); margin-top: 4px; }
.pp-course-remaining { text-align: right; }
.pp-remaining-number { font-size: 1.6em; font-weight: 800; }
.pp-remaining-label { font-size: 0.78em; color: var(--ds-ink-mute); margin-left: 2px; }
.pp-package-hint { font-size: 0.76em; line-height: 1.5; color: var(--ds-ink-mute); margin: 8px 0 0; }

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
.pp-monthly-fee-amount { font-size: 1.15em; font-weight: 700; color: var(--ds-ink); }
.pp-monthly-fee-label { font-size: 0.72em; color: var(--ds-ink-mute); margin-top: 2px; }

/* ═══ Learning Records ═══ */
.pp-record-card {
  background: var(--ds-canvas); border-radius: 10px; padding: 12px 14px;
  margin-bottom: 8px; cursor: pointer; border: 1px solid var(--ds-canvas-soft);
  transition: background 0.15s;
}
.pp-record-card:active { background: var(--ds-canvas-soft); }
.pp-record-header { display: flex; align-items: center; gap: 8px; }
.pp-record-main { flex: 1; min-width: 0; }
.pp-record-date { font-size: 0.82em; color: var(--ds-ink-mute); display: block; }
.pp-record-subject { font-weight: 600; font-size: 0.95em; }
.pp-record-score { text-align: center; flex-shrink: 0; }
.pp-score-value { font-size: 1.4em; font-weight: 800; color: var(--ds-ink-mute); }
.pp-score-label { font-size: 0.7em; color: var(--ds-ink-mute); }
.pp-expand-icon { color: var(--ds-hairline); transition: transform 0.2s; font-size: 20px; }
.pp-expand-icon.expanded { transform: rotate(180deg); }
.pp-record-detail {
  margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--ds-canvas-soft);
  display: flex; flex-direction: column; gap: 8px;
}
.pp-detail-row { display: flex; align-items: flex-start; gap: 6px; font-size: 0.88em; }
.pp-detail-icon { font-size: 16px; color: var(--ds-ink-mute); flex-shrink: 0; margin-top: 1px; }
.pp-detail-label { font-weight: 600; color: var(--ds-ink-mute); white-space: nowrap; margin-right: 4px; }
.pp-feedback-box {
  margin-top: 12px;
  padding: 12px;
  border: 1px solid var(--ds-canvas-soft);
  border-radius: 12px;
  background: rgba(92,107,192,.06);
  scroll-margin-top: 72px;
}
.pp-feedback-title { display: flex; align-items: center; gap: 6px; font-weight: 700; color: var(--ds-ink-mute); }
.pp-feedback-hint, .pp-feedback-time { margin: 6px 0; font-size: .86em; color: var(--ds-ink-mute); }
.pp-feedback-textarea { width: 100%; min-height: 96px; resize: vertical; border: 1px solid var(--ds-canvas-soft); border-radius: 10px; padding: 10px; font: inherit; box-sizing: border-box; }
.pp-feedback-actions { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 8px; }
.pp-feedback-count { font-size: .8em; color: var(--ds-ink-mute); }
.pp-feedback-count.warn { color: var(--ds-primary); }
.pp-feedback-newdot { margin-left: 8px; padding: 1px 8px; border-radius: 999px; background: var(--ds-danger); color: var(--ds-canvas); font-size: .72em; font-weight: 700; }
.pp-feedback-thread { margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--ds-canvas-soft); display: flex; flex-direction: column; gap: 8px; }
.pp-feedback-thread-title { display: flex; align-items: center; gap: 6px; font-size: .84em; font-weight: 700; color: var(--ds-ink-mute); }
.pp-feedback-reply { padding: 8px 10px; border-radius: 10px; font-size: .9em; max-width: 92%; }
.pp-feedback-reply.is-staff { background: var(--ds-canvas); border: 1px solid var(--ds-canvas-soft); align-self: flex-start; }
.pp-feedback-reply.is-parent { background: var(--ds-canvas-soft); align-self: flex-end; }
.pp-feedback-reply-who { font-size: .8em; font-weight: 700; color: var(--ds-ink-mute); display: flex; gap: 8px; align-items: baseline; }
.pp-feedback-reply-time { font-weight: 400; color: var(--ds-ink-mute); font-size: .92em; }
.pp-feedback-reply-body { margin-top: 3px; color: var(--ds-ink); white-space: pre-wrap; word-break: break-word; }
.pp-feedback-followup { margin-top: 10px; }
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
  border: 1px solid var(--ds-canvas-soft);
  background: var(--ds-canvas);
  color: var(--ds-ink);
  font-size: 13px;
  font-weight: 600;
  padding: 8px 14px;
  border-radius: 999px;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.pp-lr-chip.active {
  background: var(--ds-ink-mute);
  border-color: var(--ds-ink-mute);
  color: var(--ds-canvas);
}
.pp-lr-subject-block {
  margin-bottom: 8px;
}
.pp-lr-subject-heading {
  margin: 16px 0 8px;
  font-size: 15px;
  font-weight: 700;
  color: var(--ds-ink);
  padding-bottom: 6px;
  border-bottom: 1px solid var(--ds-canvas-soft);
}
.pp-lr-subject-block:first-of-type .pp-lr-subject-heading {
  margin-top: 0;
}
.pp-report {
  background: var(--ds-canvas); border-radius: 10px; padding: 14px;
  margin-bottom: 10px; cursor: pointer; border: 1px solid var(--ds-canvas-soft);
  transition: background 0.15s, box-shadow 0.2s;
}
.pp-report:active { background: var(--ds-canvas-soft); }
.pp-report-head {
  display: flex; align-items: center; gap: 10px;
}
.pp-report-head-left { flex: 1; min-width: 0; }
.pp-report-subject {
  font-weight: 700; font-size: 1em; color: var(--ds-ink);
  margin-bottom: 2px;
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
}
.pp-session-num {
  font-size: 0.75em; font-weight: 600; color: var(--ds-ink-mute);
  background: var(--ds-canvas-soft); padding: 1px 7px; border-radius: 8px;
  white-space: nowrap;
}
.pp-report-meta {
  display: flex; align-items: center; gap: 4px; flex-wrap: wrap;
  font-size: 0.8em; color: var(--ds-ink-mute);
}
.pp-report-score-ring {
  width: 48px; height: 48px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--ds-canvas-soft), var(--ds-canvas-soft));
  border-radius: 50%; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
}
.pp-report-score-val { font-size: 1.1em; font-weight: 800; color: var(--ds-ink-mute); line-height: 1; }
.pp-report-score-unit { font-size: 0.6em; color: var(--ds-ink-mute); }
.pp-report-indicators {
  display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
  margin-top: 10px; padding-top: 8px;
  border-top: 1px dashed var(--ds-canvas-soft);
}
.pp-indicator {
  display: flex; align-items: center; gap: 4px;
  background: var(--ds-canvas); border: 1px solid var(--ds-canvas-soft); border-radius: 6px;
  padding: 4px 10px; font-size: 0.82em;
}
.pp-indicator-icon { font-size: 16px; }
.pp-indicator-text { color: var(--ds-ink-mute); font-weight: 500; }
.pp-report-body {
  margin-top: 12px; padding-top: 12px;
  border-top: 1px solid var(--ds-canvas-soft);
  display: flex; flex-direction: column; gap: 12px;
}
.pp-report-field {
  background: var(--ds-canvas); border-radius: 8px; padding: 10px 12px;
  border: 1px solid var(--ds-canvas-soft);
}
.pp-report-field-label {
  display: flex; align-items: center; gap: 6px;
  font-size: 0.8em; font-weight: 700; color: var(--ds-ink-mute);
  margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px;
}
.pp-report-field-label .material-symbols-outlined { font-size: 16px; }
.pp-report-field-value {
  font-size: 0.92em; color: var(--ds-ink); line-height: 1.6;
  white-space: pre-line;
}
.pp-report-comment {
  background: var(--ds-warning-wash); padding: 10px 12px; border-radius: 6px;
  border-left: 3px solid var(--ds-warning); font-style: italic;
}

/* ═══ Attendance Timeline ═══ */
.pp-timeline { position: relative; padding-left: 28px; }
.pp-timeline::before {
  content: ''; position: absolute; left: 11px; top: 8px; bottom: 8px;
  width: 2px; background: var(--ds-canvas-soft);
}
.pp-timeline-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; position: relative; min-height: 44px; }
.pp-timeline-item.absent { background: linear-gradient(90deg, rgba(229,57,53,0.06) 0%, transparent 50%); border-radius: 6px; padding-left: 4px; margin-left: -4px; }
.pp-timeline-dot {
  position: absolute; left: -28px; top: 10px;
  width: 22px; height: 22px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  z-index: 1; background: var(--ds-ink-mute);
}
.pp-timeline-dot .material-symbols-outlined { font-size: 14px; color: var(--ds-canvas); }
.pp-timeline-dot.present { background: var(--ds-success); }
.pp-timeline-dot.late { background: var(--ds-warning); }
.pp-timeline-dot.absent { background: var(--ds-danger); }
.pp-timeline-dot.leave { background: var(--ds-ink-mute); }
.pp-timeline-content { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.pp-timeline-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pp-timeline-date { font-size: 0.88em; color: var(--ds-ink); font-weight: 600; }
.pp-timeline-status { font-size: 0.82em; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
.pp-timeline-status.present { color: var(--ds-success); background: var(--ds-success-wash); }
.pp-timeline-status.late { color: var(--ds-primary); background: var(--ds-warning-wash); }
.pp-timeline-status.absent { color: var(--ds-danger); background: var(--ds-danger-wash); }
.pp-timeline-status.leave { color: var(--ds-ink-mute); background: var(--ds-canvas-soft); }
.pp-timeline-sub { display: flex; flex-wrap: wrap; gap: 10px; font-size: 0.8em; color: var(--ds-ink-mute); }
.pp-timeline-sub span { display: inline-flex; align-items: center; gap: 3px; }
.pp-mini-icon { font-size: 13px !important; vertical-align: middle; }
.pp-empty-hint { font-size: 0.78em !important; color: var(--ds-ink-mute); margin-top: 4px; }

/* ═══ Skeleton Loader ═══ */
.pp-skeleton-card { padding: 18px; }
.pp-skel {
  background: linear-gradient(90deg, var(--ds-canvas-soft) 0%, var(--ds-canvas-soft) 50%, var(--ds-canvas-soft) 100%);
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
  padding: 10px 0; border-bottom: 1px solid var(--ds-canvas-soft);
}
.pp-announcement:last-child { border-bottom: none; }
.pp-ann-title { font-weight: 700; font-size: 0.95em; color: var(--ds-ink); }
.pp-ann-body { font-size: 0.88em; color: var(--ds-ink-mute); margin-top: 4px; white-space: pre-line; }
.pp-ann-date { font-size: 0.78em; color: var(--ds-ink-mute); margin-top: 4px; }

/* ═══ Invoices ═══ */
.pp-invoice-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 0; border-bottom: 1px solid var(--ds-canvas-soft);
}
.pp-invoice-item:last-child { border-bottom: none; }
.pp-invoice-date { font-size: 0.85em; color: var(--ds-ink-mute); }
.pp-invoice-amount { font-weight: 700; font-size: 1.05em; }
.pp-invoice-badges { display: flex; gap: 4px; align-items: center; flex-wrap: wrap; }
.pp-badge-reconciled {
  background: var(--ds-success-wash); color: var(--ds-success);
  display: inline-flex; align-items: center; gap: 2px;
}

/* ═══ LINE Binding ═══ */
.pp-line-bound {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; background: var(--ds-success-wash); border-radius: 8px;
  font-size: 0.9em; color: var(--ds-success);
}
.pp-line-unbound { font-size: 0.9em; }
.pp-bind-code {
  display: block; background: var(--ds-canvas-soft); padding: 10px 14px; border-radius: 8px;
  margin: 8px 0; font-family: monospace; font-size: 0.92em; color: var(--ds-ink);
  border: 1px solid var(--ds-canvas-soft);
}

/* ═══ Empty State ═══ */
.pp-empty {
  text-align: center; padding: 20px; color: var(--ds-ink-mute);
}
.pp-empty .material-symbols-outlined { font-size: 36px; margin-bottom: 4px; }
.pp-empty p { margin: 0; font-size: 0.88em; }

.pp-section-count {
  margin-left: auto; font-size: 0.78em; color: var(--ds-ink-mute); font-weight: 500;
}
.pp-spinner-inline {
  width: 16px; height: 16px; border: 2px solid var(--ds-canvas-soft);
  border-top-color: var(--ds-ink-mute); border-radius: 50%;
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
