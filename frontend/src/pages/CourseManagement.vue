<template>
  <div class="course-page">
    <!-- Top Bar -->
    <div class="card course-header-card" data-guide="course-mgmt-header">
      <div class="header-actions">
        <div class="page-title-block">
          <h2 class="page-title">課程管理</h2>
          <p class="ref-hint">管理所有學生的課程安排，快速新增課程</p>
          <div class="meta-pills">
            <span class="meta-pill">{{ groupedCourses.length }} 位學生</span>
            <span v-if="pagination.lastPage > 1" class="meta-pill">第 {{ pagination.page }} / {{ pagination.lastPage }} 頁</span>
          </div>
        </div>
        <div class="header-buttons">
          <button class="btn-soft" @click="expandAllGroups">全部展開</button>
          <button class="btn-soft" @click="collapseAllGroups">全部收合</button>
          <button class="btn-soft" @click="showBulkLeaveModal = true">
            <span class="btn-icon">🏖️</span> 連假批次請假
          </button>
          <button class="btn-soft" @click="emit('navigate', 'subject-settings')">
            <span class="btn-icon">📚</span> 管理科目
          </button>
          <button class="btn-accent" @click="openBackfillModal">
            <span class="btn-icon">📥</span> 新增課程
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="filter-bar grid" data-guide="course-mgmt-filters">
        <div class="filter-field">
          <label>搜尋學生</label>
          <input v-model="filters.name" placeholder="輸入姓名..." @input="debouncedLoad" />
        </div>
        <div class="filter-field">
          <label>上課類型</label>
          <select v-model="filters.class_type" @change="loadCourses(1)">
            <option value="">全部</option>
            <option value="one_on_one">一對一</option>
            <option value="one_on_two">一對二</option>
            <option value="one_on_three">一對三</option>
            <option value="tutoring">輔導</option>
            <option value="trial">試聽</option>
          </select>
        </div>
        <div class="filter-field">
          <label>搜尋老師</label>
          <input v-model="filters.teacher_name" placeholder="輸入老師姓名..." @input="debouncedLoad" />
        </div>
        <div class="filter-field">
          <label>課程狀態</label>
          <select v-model="filters.course_status" @change="loadCourses(1)">
            <option value="">全部</option>
            <option value="active">進行中</option>
            <option value="inactive">已暫停</option>
          </select>
        </div>
      </div>

      <!-- Compact stats strip -->
      <div class="stats-strip">
        <span class="stats-strip-item stats-strip-total">
          <span class="stats-strip-num">{{ courses.length }}</span> 筆課程
        </span>
        <span class="stats-strip-sep">·</span>
        <span class="stats-strip-item">一對一 <strong>{{ coursesByType.one_on_one }}</strong></span>
        <span class="stats-strip-sep">·</span>
        <span class="stats-strip-item">一對二 <strong>{{ coursesByType.one_on_two }}</strong></span>
        <span class="stats-strip-sep">·</span>
        <span class="stats-strip-item">一對三 <strong>{{ coursesByType.one_on_three }}</strong></span>
        <span class="stats-strip-sep">·</span>
        <span class="stats-strip-item">輔導 <strong>{{ coursesByType.tutoring }}</strong></span>
        <span class="stats-strip-sep">·</span>
        <span class="stats-strip-item">試聽 <strong>{{ coursesByType.trial }}</strong></span>
        <template v-if="coursesBySubject.length">
          <span class="stats-strip-sep stats-strip-pipe">|</span>
          <span
            v-for="s in coursesBySubject"
            :key="s.subject"
            class="stats-strip-subject"
          >{{ s.label }} {{ s.count }}</span>
        </template>
      </div>
    </div>

    <!-- Post-creation success banner -->
    <transition name="fade">
      <div v-if="creationSuccessBanner" class="creation-success-banner" role="status">
        <span class="material-symbols-outlined creation-success-banner__icon">check_circle</span>
        <span>{{ creationSuccessBanner }}</span>
        <button class="creation-success-banner__close" @click="creationSuccessBanner = null" aria-label="關閉">✕</button>
      </div>
    </transition>

    <!-- Course Table -->
    <div class="card table-card" data-guide="course-mgmt-table">
      <div v-if="groupedCourses.length" class="grouped-course-list" :class="{ 'focus-fullscreen-mode': focusedStudentKey }">
        <div v-if="focusedStudentKey" class="focus-mode-banner">
          <span>專注模式：只顯示 {{ visibleGroups[0]?.student_name }}</span>
          <button @click="focusedStudentKey = null">✕ 回復全部顯示</button>
        </div>
        <section
          v-for="group in visibleGroups"
          :key="group.key"
          class="student-group-card"
          :class="{ 'student-group-has-paused': groupHasPausedCourse(group) }"
        >
          <button class="student-group-header" @click="toggleStudentGroup(group.key)">
            <span class="student-group-left">
              <span class="expand-indicator">{{ expandedStudentGroups.has(group.key) ? '▼' : '▶' }}</span>
              <span class="cell-student">{{ group.student_name }}</span>
              <span v-if="groupHasPausedCourse(group)" class="student-group-paused-badge">含暫停課程</span>
            </span>
            <span class="student-group-meta">
              <span>{{ activeCourses(group).length }} 筆進行中</span>
              <span v-if="historyCourses(group).length" class="student-group-history-count">{{ historyCourses(group).length }} 筆歷史</span>
            </span>
            <button
              class="focus-btn"
              :class="{ active: focusedStudentKey === group.key }"
              @click="focusStudent(group, $event)"
              :title="focusedStudentKey === group.key ? '取消專注' : '專注此學生'"
            >⊙</button>
          </button>
          <div v-if="expandedStudentGroups.has(group.key)" class="student-group-add-row">
            <button type="button" class="btn-soft student-group-add-btn" @click="openBackfillModalForGroup(group)">
              <span class="btn-icon" aria-hidden="true">＋</span>
              為此學生新增課程
            </button>
          </div>
          <div v-if="expandedStudentGroups.has(group.key)" class="table-wrap group-table-wrap">
            <table class="course-table">
              <thead>
                <tr>
                  <th>科目</th>
                  <th>老師</th>
                  <th>時段</th>
                  <th>繳費</th>
                  <th>剩餘堂數</th>
                  <th class="col-actions">操作</th>
                </tr>
              </thead>
              <tbody>
                <template v-if="activeCourses(group).length === 0">
                  <tr>
                    <td colspan="6" class="empty-active-courses">
                      <div class="empty-active-courses__inner">
                        <span class="material-symbols-outlined empty-active-courses__icon" aria-hidden="true">school</span>
                        <span class="empty-active-courses__text">目前沒有進行中的課程</span>
                        <span v-if="historyCourses(group).length" class="empty-active-courses__hint">下方有 {{ historyCourses(group).length }} 筆歷史課程</span>
                      </div>
                    </td>
                  </tr>
                </template>
                <template v-for="c in activeCourses(group)" :key="c.id">
                  <tr :class="['course-row', courseRowClass(c)]">
                    <td class="td-subject">
                      <div v-if="c.status === 'inactive' && !effectiveClosedReason(c)" class="paused-course-callout" role="status">
                        <span class="paused-course-callout__icon" aria-hidden="true">⏸</span>
                        <span class="paused-course-callout__main">暫停中</span>
                        <span class="paused-course-callout__sub">未恢復前不排新課、不計入待辦</span>
                        <button class="paused-course-callout__action" type="button" @click.stop="requestCoursePause(c)">恢復課程</button>
                      </div>
                      <div v-else-if="effectiveClosedReason(c) === 'settled' || effectiveClosedReason(c) === 'completed'" class="settled-course-callout" role="status">
                        <span class="settled-course-callout__icon" aria-hidden="true">✅</span>
                        <span class="settled-course-callout__main">已結案</span>
                        <span class="settled-course-callout__sub">{{ effectiveClosedReason(c) === 'settled' ? '手動結案，無需續報' : '堂數已用完' }}</span>
                      </div>
                      <div class="subject-line">
                        <span class="tag subject-tag" :class="{ 'subject-tag--paused': c.status === 'inactive' }">{{ getSubjectLabel(c.subject) }}</span>
                        <span class="status-tag" :class="c.class_type">{{ classTypeLabel(c.class_type) }}</span>
                        <span v-if="c.PackageID" class="tag tag-package" :title="c.PackageName || '多科方案'">方案</span>
                        <span v-if="c.status === 'inactive' && !effectiveClosedReason(c)" class="tag tag-paused">暫停中</span>
                        <span v-else-if="effectiveClosedReason(c) === 'settled' || effectiveClosedReason(c) === 'completed'" class="tag tag-settled">已結案</span>
                      </div>
                      <div class="price-line">
                        <span>每堂 ${{ sessionPrice(c) }}</span>
                        <span class="price-sep">｜</span>
                        <template v-if="isMonthlyMode(c)">
                          <span>已上堂費用 ${{ monthlyAttendedFee(c) }}</span>
                        </template>
                        <template v-else>
                          <span>總費用 ${{ totalPrice(c) }}</span>
                        </template>
                      </div>
                      <div v-if="courseMemo(c)" class="memo-line">備註：{{ courseMemo(c) }}</div>
                    </td>
                    <td>{{ c.teacher_name || '待指派' }}</td>
                    <td class="cell-schedule">
                      <div v-if="formatDayTimeSlotLines(c).length > 0" class="schedule-slot-lines">
                        <div
                          v-for="(line, sidx) in formatDayTimeSlotLines(c)"
                          :key="`${c.id}-slot-${sidx}`"
                          class="schedule-slot-line"
                        >
                          {{ line }}
                        </div>
                      </div>
                      <span v-else-if="(c.days_of_week || []).length > 0">
                        {{ (c.days_of_week || []).map(d => dayLabel(d)).join('、') }} {{ c.start_time }}~{{ c.end_time }}
                      </span>
                      <span v-else-if="c.day_of_week">
                        {{ dayLabel(c.day_of_week) }} {{ c.start_time }}~{{ c.end_time }}
                      </span>
                      <span v-else class="hint">未排定</span>
                      <span v-if="c.schedule_drift" class="schedule-drift-badge" :title="c.contract_exception_count > 0 ? '堂次偏移（另含 ' + c.contract_exception_count + ' 堂補課例外，不受影響）。若偏移非刻意調課，請開啟「編輯」確認固定排課後按儲存，系統會自動同步偏移堂次。' : '未上預排堂次與固定排課（契約）的星期／時段不一致。若偏移非刻意調課，請開啟「編輯」確認固定排課後按儲存，系統會自動同步未上預排堂次。'">⚠ 堂次偏移</span>
                      <span v-else-if="c.contract_exception_count > 0" class="contract-exception-badge" :title="'含 ' + c.contract_exception_count + ' 堂非固定星期的補課／加課，不會被重建覆寫。'">補課例外</span>
                    </td>
                    <td>
                      <button
                        :class="['small', 'btn-status', paymentStatusButtonClass(c)]"
                        title="點擊切換繳費狀態"
                        @click="togglePaymentStatus(c)"
                      >{{ paymentStatusButtonLabel(c) }}</button>
                      <div v-if="c.last_paid_at" class="paid-date-hint">{{ c.last_paid_at }}</div>
                    </td>
                    <td :class="{ 'cell-remaining': true, 'low': isSessionMode(c) && Number(displayRemainingSessions(c) ?? 0) <= 2 }">
                      <template v-if="isSessionMode(c)">{{ displayRemainingSessions(c) ?? '—' }}<span v-if="c.PackageID" class="tag-package-hint">（方案共用）</span></template>
                      <template v-else>已上 {{ getCompletedSessionCount(c) }} 堂</template>
                    </td>
                    <td class="cell-actions">
                      <div class="action-btns-row">
                        <button
                          v-if="isSessionMode(c)"
                          class="small btn-add-session"
                          :class="{ disabled: !canQuickAddSession(c) }"
                          :disabled="!canQuickAddSession(c)"
                          :title="canQuickAddSession(c) ? '補課／補登（總堂數不變）' : quickAddDisabledReason(c)"
                          @click="canQuickAddSession(c) && openQuickAddSessionModal(c)"
                        >+ 補課</button>
                        <button class="small ghost btn-toggle" @click="toggleDates(c)">
                          {{ expandedDates.has(c.id) ? '收起' : '詳情' }}
                        </button>
                        <div class="action-menu-wrapper">
                          <button class="small ghost action-menu-trigger" @click.stop="toggleActionMenu(c.id)" title="更多操作">操作 ▾</button>
                          <div v-if="activeActionMenu === c.id" class="action-dropdown" @click.stop>
                            <p class="action-section-label">日常操作</p>
                            <button class="action-dropdown-item" @click="editCourse(c); closeActionMenu()"><span class="action-icon">✏️</span> 編輯</button>
                            <button
                              v-if="isSessionMode(c)"
                              class="action-dropdown-item action-dropdown-add-session-mobile"
                              :class="{ 'action-dropdown-item--disabled': !canQuickAddSession(c) }"
                              :disabled="!canQuickAddSession(c)"
                              :title="canQuickAddSession(c) ? '' : quickAddDisabledReason(c)"
                              @click="canQuickAddSession(c) && (openQuickAddSessionModal(c), closeActionMenu())"
                            ><span class="action-icon">＋</span> 補課 / 補登</button>
                            <button
                              :class="['action-dropdown-item', { 'action-dropdown-renew': isSessionMode(c) && Number(displayRemainingSessions(c) ?? 0) <= 2 }]"
                              @click="openPurchaseModal(c); closeActionMenu()"
                            ><span class="action-icon">⚡</span> {{ isSessionMode(c) && Number(displayRemainingSessions(c) ?? 0) <= 2 ? '續報加購' : '加購堂數' }}</button>
                            <button class="action-dropdown-item" @click="duplicateCourseForTeacher(c); closeActionMenu()"><span class="action-icon">📋</span> 換師複製</button>
                            <p class="action-section-label">狀態管理</p>
                            <button v-if="c.status !== 'inactive'" class="action-dropdown-item" @click="requestCoursePause(c); closeActionMenu()"><span class="action-icon">⏸</span> 暫停課程</button>
                            <button v-if="c.status === 'inactive'" class="action-dropdown-item action-dropdown-resume" @click="requestCoursePause(c); closeActionMenu()"><span class="action-icon">▶</span> 恢復課程</button>
                            <button v-if="canCloseCourse(c)" class="action-dropdown-item action-dropdown-close" @click="closeCourseNoRenew(c); closeActionMenu()"><span class="action-icon">✓</span> 結案（不續報）</button>
                            <hr class="action-dropdown-divider" />
                            <p class="action-section-label action-section-label--danger">危險操作</p>
                            <button class="action-dropdown-item action-dropdown-danger" @click="confirmDeleteTarget = c; closeActionMenu()"><span class="action-icon">🗑</span> 刪除課程</button>
                          </div>
                        </div>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="expandedDates.has(c.id)" :class="['dates-row', { 'dates-row-paused': c.status === 'inactive' }]">
                    <td colspan="6">
                      <div class="detail-panel">
                        <div class="detail-meta">
                          <span class="detail-item"><span class="detail-label">每堂</span> ${{ sessionPrice(c) }}</span>
                          <span class="detail-item">
                            <span class="detail-label">{{ isMonthlyMode(c) ? '已上堂費用' : '總費用' }}</span>
                            <strong>${{ isMonthlyMode(c) ? monthlyAttendedFee(c) : totalPrice(c) }}</strong>
                          </span>
                          <span class="detail-item" v-if="c.branch_name || c.room_name"><span class="detail-label">地點</span> {{ [c.branch_name, c.room_name].filter(Boolean).join(' — ') }}</span>
                          <span class="detail-item"><span class="detail-label">繳費方式</span>
                            <template v-if="c.payment_type === 'session'">堂數制</template>
                            <template v-else>月結<template v-if="c.settlement_day">（每月{{ c.settlement_day }}號）</template></template>
                          </span>
                        </div>
                        <div class="dates-panel">
                          <div class="dates-panel-heading">
                            <strong class="dates-panel-title">上課日期（已上 {{ getCompletedSessionCount(c) }} / 購買 {{ getPurchasedSessions(c) }} 堂<template v-if="cancelledSessionCount(c) > 0">，{{ cancelledSessionCount(c) }} 堂已取消</template>）</strong>
                            <span v-if="sessionCountWarning(c)" :class="['drift-hint', { 'drift-hint-info': sessionCountWarning(c)?.type === 'under_leave' }]">⚠ {{ sessionCountWarning(c)?.message }}</span>
                            <span v-if="allSessionUnits(c).length === 0" class="hint">無法計算（請確認排課設定）</span>
                            <button class="notes-toggle-btn" @click.stop="toggleSessionNotes" :title="showSessionNotes ? '隱藏備註' : '顯示備註'">
                              {{ showSessionNotes ? '備註 ▲' : '備註 ▼' }}
                            </button>
                          </div>
                          <div v-if="allSessionUnits(c).length > 0" class="dates-chip-grid">
                            <span
                              v-for="u in allSessionUnits(c)"
                              :key="sessionRowKey(u)"
                              :class="[
                                'date-chip',
                                'date-chip-clickable',
                                u._synthetic && 'date-chip-synthetic',
                                getSessionStateClass(c, (u.session_date || '').slice(0,10), u.id)
                              ]"
                              :title="u._synthetic ? '依月結固定時段推算；點擊後會建立實體堂次並開啟編輯' : getSessionTooltip(c, (u.session_date || '').slice(0,10), u.id)"
                              @click="openSessionEdit(c, (u.session_date || '').slice(0,10), u.id, u)"
                            >
                              <template v-if="getSessionNumber(c, (u.session_date || '').slice(0,10), u.id)"><span class="chip-seq">第{{ getSessionNumber(c, (u.session_date || '').slice(0,10), u.id) }}堂</span></template><span class="chip-date">{{ formatSessionChipDate(u) }}</span><template v-if="getSessionStateLabel(c, (u.session_date || '').slice(0,10), u.id)"><span class="chip-state">{{ getSessionStateLabel(c, (u.session_date || '').slice(0,10), u.id) }}</span></template><template v-if="showSessionNotes && isUserNote(u.note)"><span class="chip-note-text">{{ u.note }}</span></template>
                            </span>
                          </div>
                        </div>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
            <!-- History courses collapsible section -->
            <div v-if="historyCourses(group).length" class="history-section">
              <button class="history-section__toggle" @click="toggleHistoryGroup(group.key)">
                <span class="material-symbols-outlined history-section__icon" aria-hidden="true">inventory_2</span>
                <span class="history-section__label">歷史課程</span>
                <span class="history-section__count">{{ historyCourses(group).length }} 筆</span>
                <span class="history-section__chevron">{{ expandedHistoryGroups.has(group.key) ? '▲' : '▼' }}</span>
              </button>
              <div v-if="expandedHistoryGroups.has(group.key)" class="history-section__body">
                <div v-for="hc in historyCourses(group)" :key="hc.id" class="history-course-card">
                  <div class="history-course-card__header">
                    <span class="tag subject-tag history-course-card__subject">{{ getSubjectLabel(hc.subject) }}</span>
                    <span class="status-tag" :class="hc.class_type">{{ classTypeLabel(hc.class_type) }}</span>
                    <span v-if="hc.PackageID" class="tag tag-package" :title="hc.PackageName || '多科方案'">方案</span>
                    <span v-if="effectiveClosedReason(hc) === 'settled'" class="tag tag-history tag-history--settled">已結算</span>
                    <span v-else class="tag tag-history tag-history--completed">已完課</span>
                  </div>
                  <div class="history-course-card__details">
                    <span class="history-course-card__detail"><span class="history-course-card__detail-label">老師</span> {{ hc.teacher_name || '—' }}</span>
                    <span class="history-course-card__detail"><span class="history-course-card__detail-label">費用</span> ${{ totalPrice(hc) }}（每堂 ${{ sessionPrice(hc) }}）</span>
                    <span class="history-course-card__detail"><span class="history-course-card__detail-label">堂數</span> 已上 {{ getCompletedSessionCount(hc) }}<template v-if="isSessionMode(hc)"> / 購買 {{ getPurchasedSessions(hc) }}</template> 堂</span>
                    <span class="history-course-card__detail" v-if="hc.last_paid_at"><span class="history-course-card__detail-label">繳費</span> {{ hc.last_paid_at }}</span>
                  </div>
                  <div class="history-course-card__actions">
                    <button class="small ghost btn-toggle" @click="toggleDates(hc)">
                      {{ expandedDates.has(hc.id) ? '收起詳情' : '查看堂次' }}
                    </button>
                    <div class="action-menu-wrapper">
                      <button class="small ghost action-menu-trigger" @click.stop="toggleActionMenu(hc.id)" title="更多操作">操作 ▾</button>
                      <div v-if="activeActionMenu === hc.id" class="action-dropdown" @click.stop>
                        <p class="action-section-label">日常操作</p>
                        <button class="action-dropdown-item" @click="editCourse(hc); closeActionMenu()"><span class="action-icon">✏️</span> 編輯</button>
                        <button class="action-dropdown-item" @click="duplicateCourseForTeacher(hc); closeActionMenu()"><span class="action-icon">📋</span> 換師複製</button>
                        <p class="action-section-label">狀態管理</p>
                        <button class="action-dropdown-item action-dropdown-resume" @click="requestCoursePause(hc); closeActionMenu()"><span class="action-icon">▶</span> 恢復課程</button>
                        <hr class="action-dropdown-divider" />
                        <p class="action-section-label action-section-label--danger">危險操作</p>
                        <button class="action-dropdown-item action-dropdown-danger" @click="confirmDeleteTarget = hc; closeActionMenu()"><span class="action-icon">🗑</span> 刪除課程</button>
                      </div>
                    </div>
                  </div>
                  <div v-if="expandedDates.has(hc.id)" class="history-course-card__dates">
                    <div class="detail-panel">
                      <div class="dates-panel">
                        <div class="dates-panel-heading">
                          <strong class="dates-panel-title">上課日期（已上 {{ getCompletedSessionCount(hc) }} / 購買 {{ getPurchasedSessions(hc) }} 堂<template v-if="cancelledSessionCount(hc) > 0">，{{ cancelledSessionCount(hc) }} 堂已取消</template>）</strong>
                        </div>
                        <div v-if="allSessionUnits(hc).length > 0" class="dates-chip-grid">
                          <span
                            v-for="u in allSessionUnits(hc)"
                            :key="sessionRowKey(u)"
                            :class="['date-chip', getSessionStateClass(hc, (u.session_date || '').slice(0,10), u.id)]"
                            :title="getSessionTooltip(hc, (u.session_date || '').slice(0,10), u.id)"
                          >
                            <template v-if="getSessionNumber(hc, (u.session_date || '').slice(0,10), u.id)"><span class="chip-seq">第{{ getSessionNumber(hc, (u.session_date || '').slice(0,10), u.id) }}堂</span></template><span class="chip-date">{{ formatSessionChipDate(u) }}</span><template v-if="getSessionStateLabel(hc, (u.session_date || '').slice(0,10), u.id)"><span class="chip-state">{{ getSessionStateLabel(hc, (u.session_date || '').slice(0,10), u.id) }}</span></template>
                          </span>
                        </div>
                        <span v-else class="hint">無排課資料</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
      <div v-else class="empty-state">
        <div class="empty-icon">📋</div>
        <p class="empty-title">目前尚無課程資料</p>
        <p class="empty-desc">請在「學生管理」為學生建立課程，或使用上方「新增課程」快速建立課程。</p>
      </div>
      <div v-if="pagination.lastPage > 1" class="pagination-bar">
        <span class="pagination-info">第 {{ (pagination.page - 1) * pagination.perPage + 1 }}–{{ Math.min(pagination.page * pagination.perPage, pagination.total) }} 筆，共 {{ pagination.total }} 筆</span>
        <div class="pagination-controls">
          <button class="btn-soft pagination-btn" :disabled="pagination.page <= 1" @click="goToPage(pagination.page - 1)">‹ 上一頁</button>
          <span class="pagination-current">{{ pagination.page }} / {{ pagination.lastPage }}</span>
          <button class="btn-soft pagination-btn" :disabled="pagination.page >= pagination.lastPage" @click="goToPage(pagination.page + 1)">下一頁 ›</button>
        </div>
      </div>
      <div v-else-if="pagination.total > 0" class="pagination-bar">
        <span class="pagination-info">共 {{ pagination.total }} 筆課程</span>
      </div>
    </div>

    <UniversalClassScheduler
      v-if="showBackfillModal"
      title="新增課程（統一排課介面）"
      submit-label="建立課程"
      :branch-id="props.branchId"
      :students="schedulerStudents"
      :teachers="schedulerTeachers"
      :rooms="rooms"
      :initial-student-id="schedulerInitialStudentId"
      :initial-teacher-id="schedulerInitialTeacherId"
      mode="backfill"
      @cancel="showBackfillModal = false"
      @success="handleUniversalBackfillSuccess"
      @duplicate-course="handleSchedulerDuplicateCM"
    />

    <!-- Edit Course Modal -->
    <div v-if="showEditModal" class="modal-overlay">
      <div class="modal course-modal">
        <h3 class="modal-title">編輯課程</h3>
        <div class="form-section">
          <CourseEditForm
            ref="editFormRef"
            v-model="editForm"
            :teachers="teachers"
            :rooms="rooms"
            :subjects="subjectOptions"
            :day-options="DAY_OPTIONS"
            :time-options="TIME_OPTIONS_30"
            :settlement-day-options="settlementDayOptions"
            :show-remaining="true"
            :package-info="editPackageInfo"
            :context-title="editContextTitle"
          />
        </div>
        <div
          v-if="editForm.payment_type === 'session' && editingCourseFromLaravel"
          class="quick-add-session-link"
          style="margin: 12px 0 4px; text-align: right;"
        >
          <button type="button" class="ghost small" @click="openQuickAddSessionFromEditModal">＋ 補課 / 補登（總堂數不變）</button>
        </div>
        <div class="actions">
          <button class="ghost" @click="showEditModal = false">取消</button>
          <button class="primary" :disabled="editFormRef?.hasErrors" @click="submitEdit">儲存</button>
        </div>
      </div>
    </div>

    <PurchaseSessionsModal
      :show="showPurchaseModal"
      :form="purchaseForm"
      @close="showPurchaseModal = false"
      @submit="submitPurchaseSessions"
    />

    <RenewMonthlyModal
      :show="showRenewMonthlyModal"
      :form="renewMonthlyForm"
      @close="showRenewMonthlyModal = false"
      @submit="submitRenewMonthly"
    />

    <!-- Duplicate Course Intercept Modal -->
    <div v-if="showDuplicateInterceptModal" class="modal-overlay" @click.self="showDuplicateInterceptModal = false">
      <div class="modal" style="width: 480px;">
        <h3 style="color: #e65100;">⚠️ 此學生已有進行中的課程</h3>
        <p style="margin-bottom: 12px;">
          此學生目前有以下進行中的課程，通常續報應使用「加購堂數」延續原課程，而非新增：
        </p>
        <div style="max-height: 200px; overflow-y: auto; margin-bottom: 16px;">
          <table class="course-table" style="font-size: 13px;">
            <thead>
              <tr>
                <th>科目</th>
                <th>類型</th>
                <th>剩餘堂數</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in duplicateConflicts" :key="c.existing_course_id">
                <td>{{ getSubjectLabel(c.subject_name) || c.subject_name }}</td>
                <td>{{ { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導' }[c.class_type] || c.class_type }}</td>
                <td :style="{ color: (c.remaining_sessions ?? 0) <= 2 ? '#c62828' : 'inherit', fontWeight: 600 }">{{ c.remaining_sessions ?? 0 }} 堂</td>
                <td><button class="small btn-renew-warn" @click="interceptGoToPurchaseCM(c)">去加購</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="modal-actions" style="gap: 8px;">
          <button class="ghost" @click="showDuplicateInterceptModal = false" :disabled="forceSubmitting">取消</button>
          <button class="ghost" @click="forceCreateCourse()" :disabled="forceSubmitting">{{ forceSubmitting ? '建立中...' : '我知道，仍要新增課程' }}</button>
        </div>
      </div>
    </div>

    <QuickAddSessionModal
      :show="showQuickAddSessionModal"
      :form="quickAddSessionForm"
      :time-options="TIME_OPTIONS_30"
      :conflict="quickAddConflict"
      :checking="quickAddChecking"
      @close="showQuickAddSessionModal = false"
      @submit="submitQuickAddSession"
      @check="runQuickAddCheck"
    />

    <LeaveModal
      :show="showLeaveModal"
      :form="leaveForm"
      :session-options="leaveSessionOptions"
      :is-retro-leave="isSelectedRetroLeave"
      :day-label="dayLabel"
      @close="showLeaveModal = false"
      @submit="submitLeave"
    />

    <BulkLeaveModal
      :show="showBulkLeaveModal"
      :form="bulkLeaveForm"
      :result="bulkLeaveResult"
      :submitting="bulkLeaveSubmitting"
      @close="showBulkLeaveModal = false; bulkLeaveResult = null"
      @submit="submitBulkLeave"
    />

    <RescheduleModal
      :show="showRescheduleModal"
      :form="rescheduleForm"
      :session-options="rescheduleSessionOptions"
      :time-options="TIME_OPTIONS_30"
      :makeup-loading="makeupLoading"
      :day-label="dayLabel"
      :day-of-week-from-date="dayOfWeekFromDate"
      :compute-end-time="computeEndTime"
      @close="showRescheduleModal = false"
      @submit="submitReschedule"
      @query-makeup="fetchMakeupSlots"
    />

    <MakeupSlotsModal
      :show="showMakeupSlotsModal"
      :student-name="rescheduleForm.student_name"
      :subject="rescheduleForm.subject"
      :date-range="makeupDateRange"
      :loading="makeupLoading"
      :slots-grouped="makeupSlotsGrouped"
      :day-label="dayLabel"
      @close="showMakeupSlotsModal = false"
      @select="selectMakeupSlot"
      @update:date-range="makeupDateRange = $event"
      @refresh="fetchMakeupSlots"
    />

    <SessionEditModal
      :show="showSessionEditModal"
      :form="sessionEditForm"
      :mode="sessionEditMode"
      :submitting="sessionEditSubmitting"
      :time-options="TIME_OPTIONS_30"
      :today-ymd="todayYmd"
      :makeup-loading="makeupLoading"
      :compute-end-time="computeEndTime"
      :teachers="teachers"
      :feature-substitute-v2="featureSubstituteV2"
      @close="closeSessionEdit"
      @set-mode="sessionEditMode = $event"
      @status-change="doStatusChange"
      @start-retro-leave="startRetroLeave"
      @do-retro-leave="doRetroLeave"
      @start-reschedule="startSessionReschedule"
      @do-reschedule="doSessionReschedule"
      @fetch-makeup="fetchMakeupSlotsForEdit"
      @add-session="addSessionFromModal"
      @start-substitute="startSubstitute"
      @do-substitute="doSubstitute"
      @open-substitute-v2="openSubstituteV2FromEdit"
      @start-edit-note-time="startEditNoteTime"
      @do-edit-note-time="doEditNoteTime"
    />

    <!-- PRD 9c058f19：卡片式代課選擇器 + ToastWithUndo（與 SmartCalendar 共用元件） -->
    <SubstituteTeacherPickerModal
      v-if="featureSubstituteV2"
      ref="substituteV2PickerRef"
      v-model="showSubstituteV2Modal"
      :context="substituteV2Context"
      :teachers="teachersForPicker"
      :branch-name-map="branchNameMap"
      :fetch-availability="fetchTeacherAvailability"
      @submit="onSubstituteV2Submit"
    />
    <ToastWithUndo ref="toastRef" />

    <!-- Payment Entry Modal — 核帳登記（未繳費→已繳費必須填繳款日期） -->
    <PaymentEntryModal
      :show="paymentEntryOpen"
      :row="paymentEntryRow"
      @close="paymentEntryOpen = false"
      @confirmed="onPaymentEntryConfirmed"
    />

    <div v-if="pauseConfirmTarget" class="modal-overlay" @click.self="pauseConfirmTarget = null">
      <div class="modal course-modal pause-confirm-modal">
        <div class="pause-confirm-header">
          <span class="pause-confirm-icon" :class="{ resume: pauseConfirmIsResume }">{{ pauseConfirmIsResume ? '▶' : '⏸' }}</span>
          <div>
            <h3 class="modal-title">{{ pauseConfirmIsResume ? '恢復課程？' : '暫停課程？' }}</h3>
            <p class="modal-desc">
              {{ pauseConfirmTarget.student_name || '學生' }} — {{ getSubjectLabel(pauseConfirmTarget.subject) }}
            </p>
          </div>
        </div>
        <div class="pause-impact-card">
          <p class="pause-impact-title">{{ pauseConfirmIsResume ? '恢復後的影響' : '暫停後的影響' }}</p>
          <ul>
            <li v-for="item in pauseConfirmImpacts" :key="item">{{ item }}</li>
          </ul>
        </div>
        <div class="actions">
          <button class="ghost" @click="pauseConfirmTarget = null">取消</button>
          <button class="primary" :class="{ 'btn-resume-primary': pauseConfirmIsResume }" @click="confirmCoursePause">
            {{ pauseConfirmIsResume ? '確認恢復' : '確認暫停' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Delete Confirm Modal (FR-013) -->
    <div v-if="confirmDeleteTarget" class="modal-overlay" @click.self="confirmDeleteTarget = null">
      <div class="modal" style="width: 420px;">
        <h3 class="modal-title" style="color: #dc2626;">確認刪除課程</h3>
        <div style="margin: 12px 0 20px; font-size: 14px; line-height: 1.6;">
          <p>確定要刪除以下課程？</p>
          <p style="margin: 8px 0;">
            <strong>{{ confirmDeleteTarget.subject_name || confirmDeleteTarget.subject }}</strong>
            <span v-if="confirmDeleteTarget.student_name"> — {{ confirmDeleteTarget.student_name }}</span>
          </p>
          <p style="color: #dc2626; font-size: 13px;">刪除後無法復原，所有堂次紀錄將一併移除。</p>
        </div>
        <div class="actions">
          <button class="ghost" @click="confirmDeleteTarget = null">取消</button>
          <button class="danger" style="background: #dc2626; color: #fff; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer;" @click="executeDeleteCourse">確認刪除</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { supabase } from '../supabase';
import { lockScroll, unlockScroll } from '../lib/useScrollLock';
import { SUBJECTS, getSubjectLabel as getSubjectText } from '../lib/constants';
import { fetchSubjectOptions } from '../lib/subjectsApi';
import { fetchClassSessions, normalizeClassSessionsPayload } from '../lib/classSessionsApi';
import { getPerSessionFee, getCourseTotalFee } from '../lib/coursePricing';
import { createUniversalClassSchedule } from '../lib/universalSchedulerApi';
import { useCourseSessionsDisplay } from '../composables/course-management/useCourseSessionsDisplay';
import { useRescheduleAndMakeup } from '../composables/course-management/useRescheduleAndMakeup';
import { useSessionEditFlow } from '../composables/course-management/useSessionEditFlow';
import CourseEditForm from '../components/CourseEditForm.vue';
import UniversalClassScheduler from '../components/UniversalClassScheduler.vue';
import PurchaseSessionsModal from '../components/course-management/PurchaseSessionsModal.vue';
import RenewMonthlyModal from '../components/course-management/RenewMonthlyModal.vue';
import QuickAddSessionModal from '../components/course-management/QuickAddSessionModal.vue';
import LeaveModal from '../components/course-management/LeaveModal.vue';
import BulkLeaveModal from '../components/course-management/BulkLeaveModal.vue';
import RescheduleModal from '../components/course-management/RescheduleModal.vue';
import MakeupSlotsModal from '../components/course-management/MakeupSlotsModal.vue';
import SessionEditModal from '../components/course-management/SessionEditModal.vue';
import SubstituteTeacherPickerModal from '../components/substitute/SubstituteTeacherPickerModal.vue';
import PaymentEntryModal from '../components/PaymentEntryModal.vue';
import ToastWithUndo from '../components/substitute/ToastWithUndo.vue';
import { fetchTeacherAvailability, undoSubstitute } from '../lib/substituteApi.js';

// PRD 9c058f19 — 代課流程 UX 優化旗標；env 為字串，需解析。
// 與 SmartCalendar.vue 對齊：預設開啟（'1'），設為 '0' 回退舊版 <select> 模式。
const FEATURE_SUBSTITUTE_V2 = ((import.meta?.env?.VITE_FEATURE_SUBSTITUTE_V2 ?? '1') + '') !== '0';

const DAY_OPTIONS = [
  { value: 1, label: '一' }, { value: 2, label: '二' }, { value: 3, label: '三' },
  { value: 4, label: '四' }, { value: 5, label: '五' }, { value: 6, label: '六' },
  { value: 7, label: '日' },
];
// 時間以半小時為單位：07:00 ~ 22:30
const TIME_OPTIONS_30 = (() => {
  const opts = [];
  for (let h = 7; h <= 22; h++) {
    opts.push(`${String(h).padStart(2, '0')}:00`);
    if (h < 22) opts.push(`${String(h).padStart(2, '0')}:30`);
  }
  return opts;
})();
function computeEndTime(startTime, durationHours) {
  if (!startTime || durationHours == null) return '';
  const [h, m] = startTime.split(':').map(Number);
  const totalMins = (h * 60 + (m || 0)) + durationHours * 60;
  const endH = Math.floor(totalMins / 60) % 24;
  const endM = totalMins % 60;
  return `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
}
function normalizeTo30Min(timeStr) {
  if (!timeStr) return '16:00';
  const [h, m] = timeStr.split(':').map(Number);
  const totalMins = h * 60 + (m || 0);
  const rounded = Math.round(totalMins / 30) * 30;
  const nh = Math.floor(rounded / 60) % 24;
  const nm = rounded % 60;
  return `${String(nh).padStart(2, '0')}:${String(nm).padStart(2, '0')}`;
}
/** 由 YYYY-MM-DD 取得星期幾，1=週一 … 7=週日 */
function dayOfWeekFromDate(ymd) {
  if (!ymd) return 1;
  const d = new Date(ymd + 'T12:00:00');
  const n = d.getDay();
  return n === 0 ? 7 : n;
}
function toYmd(date) {
  if (!date) return '';
  return new Date(date.getTime() - (date.getTimezoneOffset() * 60000)).toISOString().slice(0, 10);
}
function addDays(ymd, days) {
  const d = new Date(ymd + 'T12:00:00');
  d.setDate(d.getDate() + days);
  return toYmd(d);
}

const props = defineProps({ branchId: [String, Number], initialTeacherId: [String, Number] });
const emit = defineEmits(['clear-initial-teacher', 'navigate']);

const courses = ref([]);
const allStudents = ref([]);
const teachers = ref([]);

const subjectOptions = ref([...SUBJECTS]);

async function loadSubjects() {
  try {
    const opts = await fetchSubjectOptions({ branchId: props.branchId });
    if (opts.length > 0) subjectOptions.value = opts;
  } catch { /* keep defaults */ }
}
const schedulerStudents = computed(() => (
  (allStudents.value || []).map((s) => ({
    id: Number(s?.id ?? 0),
    name: s?.name || `#${s?.id ?? ''}`,
  })).filter((s) => Number.isFinite(s.id) && s.id > 0)
));
const schedulerTeachers = computed(() => (
  (teachers.value || []).map((t) => ({
    id: Number(t?.id ?? 0),
    name: t?.username || t?.name || t?.Name || `#${t?.id ?? ''}`,
  })).filter((t) => Number.isFinite(t.id) && t.id > 0)
));
const filters = ref({ name: '', class_type: '', teacher_name: '', teacher_id: '', course_status: '' });
const pagination = ref({ page: 1, lastPage: 1, total: 0, perPage: 50 });
const creationSuccessBanner = ref(null);
let creationBannerTimer = null;
function showCreationBanner(msg) {
  creationSuccessBanner.value = msg;
  if (creationBannerTimer) clearTimeout(creationBannerTimer);
  creationBannerTimer = setTimeout(() => { creationSuccessBanner.value = null; }, 6000);
}
const completedSessionDatesByCourse = ref({});
const classSessionsByCourse = ref({});
const effectiveSessionDatesByCourse = ref({});
const expandedStudentGroups = ref(new Set());
const focusedStudentKey = ref(null);
const focusStudent = (group, e) => {
  e.stopPropagation();
  if (focusedStudentKey.value === group.key) {
    focusedStudentKey.value = null;
  } else {
    focusedStudentKey.value = group.key;
    const next = new Set(expandedStudentGroups.value);
    next.add(group.key);
    expandedStudentGroups.value = next;
  }
};
watch(focusedStudentKey, (v, prev) => {
  if (v && !prev) lockScroll();
  else if (!v && prev) unlockScroll();
});
const visibleGroups = computed(() =>
  focusedStudentKey.value
    ? groupedCourses.value.filter((g) => g.key === focusedStudentKey.value)
    : groupedCourses.value
);

const {
  expandedDates, toggleDates, sessions, sessionUnits, allSessionUnits, cancelledSessionCount, sessionRowKey, getSessionNumber, countNonLeaveSessions, effectiveSessionCount, leaveSessionCount, sessionCountWarning,
  getCourseSessionRows, getSessionRowsForDate, getSessionRowById, getSessionDisplayRow,
  getSessionState, getSessionStateLabel, getSessionStateClass, getSessionTooltip,
  getCourseCompletedDates, getCompletedSessionCount, isCompletedDate, displaySessions,
  isSessionMode, getPurchasedSessions, getRawRemainingSessions, getUsedSessions, displayRemainingSessions,
  formatAttendanceTooltipTime, updateLocalSessionRow,
  ensureCompletedSessionDatesLoaded, loadClassSessionsForCourses, loadEffectiveSessionDates,
  LEAVE_STATUSES, ATTENDED_SESSION_STATUSES,
} = useCourseSessionsDisplay({
  classSessionsByCourse, completedSessionDatesByCourse, effectiveSessionDatesByCourse,
  fetchClassSessionsFn: fetchClassSessions, supabase,
  branchId: computed(() => props.branchId),
});

/** Format a session unit into a readable chip label: "04/11（六）15:00–17:00" */
const DAY_LABELS = ['日', '一', '二', '三', '四', '五', '六'];
function formatSessionChipDate(u) {
  const dateStr = String(u?.session_date || '').slice(0, 10);
  if (!dateStr) return '—';
  const [, mm, dd] = dateStr.split('-');
  const dow = DAY_LABELS[new Date(`${dateStr}T12:00:00`).getDay()] ?? '';
  const base = `${mm}/${dd}（${dow}）`;
  const start = String(u?.start_time || '').slice(0, 5);
  const end = String(u?.end_time || '').slice(0, 5);
  if (start && end) return `${base} ${start}–${end}`;
  if (start) return `${base} ${start}`;
  return base;
}

// Bulk Holiday Leave
const showBulkLeaveModal = ref(false);
const bulkLeaveSubmitting = ref(false);
const bulkLeaveResult = ref(null);
const bulkLeaveForm = ref({ start_date: '', end_date: '' });

// Backfill (aligned with edit form fields)
const showBackfillModal = ref(false);
/** Preset for UniversalClassScheduler when opened from this page */
const schedulerInitialStudentId = ref('');
const schedulerInitialTeacherId = ref('');
const backfillForm = ref({
  student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
  rate_per_30min: 500, duration_hours: 2,
  payment_type: 'session', sessions_purchased: 8, sessions_used: 0,
  settlement_day: null, monthly_sessions: null,
  days_of_week: [],
  start_time: '16:00', end_time: '18:00',
  room_id: null, memo: ''
});
const backfillSelectedDates = ref([]);
const backfillCalendarMonth = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const backfillCsvInputRef = ref(null);
const backfillImporting = ref(false);
const todayYmd = computed(() => toYmd(new Date()));
const backfillRemainingSessions = computed(() => {
  const purchased = Math.max(0, Number(backfillForm.value.sessions_purchased) || 0);
  return Math.max(0, purchased - backfillSelectedDates.value.length);
});
const backfillCalendarTitle = computed(() =>
  new Intl.DateTimeFormat('zh-TW', { year: 'numeric', month: 'long' }).format(backfillCalendarMonth.value)
);
const backfillCalendarCells = computed(() => {
  const monthStart = new Date(backfillCalendarMonth.value.getFullYear(), backfillCalendarMonth.value.getMonth(), 1);
  const firstWeekday = monthStart.getDay() === 0 ? 7 : monthStart.getDay();
  const gridStart = new Date(monthStart);
  gridStart.setDate(monthStart.getDate() - (firstWeekday - 1));
  const selectedSet = new Set(backfillSelectedDates.value);
  const cells = [];
  for (let i = 0; i < 42; i++) {
    const d = new Date(gridStart);
    d.setDate(gridStart.getDate() + i);
    const ymd = toYmd(d);
    const inCurrentMonth = d.getMonth() === monthStart.getMonth();
    const disabled = ymd > todayYmd.value;
    cells.push({
      ymd,
      day: d.getDate(),
      inCurrentMonth,
      disabled,
      selected: selectedSet.has(ymd),
    });
  }
  return cells;
});
function resetBackfillDatePicker() {
  backfillSelectedDates.value = [];
  backfillCalendarMonth.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
}
function shiftBackfillCalendarMonth(delta) {
  const base = backfillCalendarMonth.value;
  backfillCalendarMonth.value = new Date(base.getFullYear(), base.getMonth() + delta, 1);
}
function toggleBackfillDate(ymd) {
  if (!ymd || ymd > todayYmd.value) return;
  const set = new Set(backfillSelectedDates.value);
  if (set.has(ymd)) set.delete(ymd);
  else set.add(ymd);
  backfillSelectedDates.value = [...set].sort();
}
function clearBackfillDates() {
  backfillSelectedDates.value = [];
}
function openBackfillCsvImporter() {
  if (!props.branchId) {
    alert('請先選擇分校，再匯入補登課程');
    return;
  }
  if (backfillCsvInputRef.value) {
    backfillCsvInputRef.value.click();
  }
}
function parseCsvLine(line) {
  const out = [];
  let cur = '';
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (ch === '"') {
      if (inQuotes && line[i + 1] === '"') {
        cur += '"';
        i++;
      } else {
        inQuotes = !inQuotes;
      }
      continue;
    }
    if (ch === ',' && !inQuotes) {
      out.push(cur);
      cur = '';
      continue;
    }
    cur += ch;
  }
  out.push(cur);
  return out;
}
function normalizeStudentName(name) {
  return String(name || '').replace(/\u3000/g, ' ').replace(/\s+/g, '').trim();
}
function normalizeTeacherName(name) {
  return normalizeStudentName(name).replace(/老師/g, '').replace(/teacher/ig, '');
}
function mapSubjectFromCsv(text) {
  const key = normalizeStudentName(text).toLowerCase();
  if (!key) return null;
  if (key.includes('數學') || key === 'math') return 'Math';
  if (key.includes('英文') || key === 'english') return 'English';
  if (key.includes('國文') || key.includes('中文') || key === 'chinese') return 'Chinese';
  if (key.includes('理化') || key.includes('自然') || key === 'science') return 'Science';
  if (key.includes('化學') || key === 'chemistry') return 'Chemistry';
  if (key.includes('物理') || key === 'physics') return 'Physics';
  if (key.includes('生物') || key === 'biology') return 'Biology';
  if (key.includes('社會') || key === 'social') return 'Social';
  return null;
}
function parseStudentCell(cell) {
  const raw = String(cell || '').replace(/\u3000/g, ' ').trim();
  if (!raw) return null;
  const typeMatch = raw.match(/1\s*對\s*([123])/);
  let classType = 'one_on_one';
  if (typeMatch?.[1] === '2') classType = 'one_on_two';
  else if (typeMatch?.[1] === '3') classType = 'one_on_three';

  let studentName = raw
    .replace(/1\s*對\s*[123]/g, '')
    .replace(/\d+(\.\d+)?\s*[Hh]/g, '')
    .replace(/[()（）]/g, ' ')
    .trim();
  studentName = normalizeStudentName(studentName);
  if (!studentName) return null;
  return { student_name: studentName, class_type: classType };
}
function parseBackfillCourseCsv(text) {
  const normalized = String(text || '').replace(/^\uFEFF/, '');
  const lines = normalized.split(/\r?\n/);
  const entries = [];
  for (const line of lines) {
    if (!line || !line.trim()) continue;
    const cols = parseCsvLine(line).map((c) => String(c || '').trim());
    const subjectRaw = cols[0] || '';
    const teacherRaw = cols[1] || '';
    if (!subjectRaw && !teacherRaw) continue;
    if (subjectRaw === '科目' || subjectRaw.includes('備註')) continue;
    const subject = mapSubjectFromCsv(subjectRaw);
    for (let i = 2; i < cols.length; i++) {
      const parsed = parseStudentCell(cols[i]);
      if (!parsed) continue;
      entries.push({
        subject,
        teacher_name: teacherRaw,
        student_name: parsed.student_name,
        class_type: parsed.class_type,
      });
    }
  }
  return entries;
}
async function importBackfillCoursesFromCsv(event) {
  const file = event?.target?.files?.[0];
  if (!file) return;
  if (!props.branchId) {
    alert('請先選擇分校，再匯入補登課程');
    event.target.value = '';
    return;
  }

  backfillImporting.value = true;
  try {
    await Promise.all([loadStudents(), loadTeachers()]);

    const text = await file.text();
    const parsedEntries = parseBackfillCourseCsv(text);
    if (!parsedEntries.length) {
      alert('檔案沒有可匯入的課程資料，請確認 CSV 格式');
      return;
    }

    const studentMap = new Map();
    (allStudents.value || []).forEach((s) => {
      studentMap.set(normalizeStudentName(s.name), s);
    });
    const teacherMap = new Map();
    (teachers.value || []).forEach((t) => {
      teacherMap.set(normalizeTeacherName(t.username), t);
    });

    const token = (await supabase.auth.getSession())?.data?.session?.access_token;
    if (!token) {
      alert('請重新登入後再試');
      return;
    }

    const sessionsPurchased = Math.max(0, Number(backfillForm.value.sessions_purchased) || 0);
    if (sessionsPurchased <= 0) {
      alert('請先設定「已購買堂數」大於 0，再進行 CSV 匯入');
      return;
    }
    const selectedDays = (backfillForm.value.days_of_week || []).map(Number).filter((d) => d >= 1 && d <= 7);
    const startTime = normalizeTo30Min(backfillForm.value.start_time || '16:00');
    const durationHours = Number(backfillForm.value.duration_hours) || 2;
    const endTime = computeEndTime(startTime, durationHours);
    const selectedLegacyDates = [...new Set((backfillSelectedDates.value || []).filter(Boolean))].sort();
    const firstClassDate = selectedLegacyDates[0] || null;

    let created = 0;
    const skipped = [];
    const failed = [];

    const toImport = parsedEntries.slice(0, 500);
    for (const row of toImport) {
      if (!row.subject) {
        skipped.push(`科目無法辨識：${row.student_name}`);
        continue;
      }
      const student = studentMap.get(normalizeStudentName(row.student_name));
      if (!student) {
        skipped.push(`找不到學生：${row.student_name}`);
        continue;
      }
      const teacher = teacherMap.get(normalizeTeacherName(row.teacher_name));
      if (!teacher) {
        skipped.push(`找不到老師（分校內）：${row.teacher_name}`);
        continue;
      }

      const payload = {
        student_id: student.id,
        subject: row.subject,
        teacher_id: teacher.id,
        class_type: row.class_type || backfillForm.value.class_type || 'one_on_one',
        rate_per_30min: Number(backfillForm.value.rate_per_30min) || 500,
        duration_hours: durationHours,
        payment_type: 'session',
        sessions_purchased: sessionsPurchased,
        remaining_sessions: sessionsPurchased,
        settlement_day: null,
        monthly_sessions: null,
        first_class_date: firstClassDate,
        days_of_week: selectedDays,
        start_time: startTime,
        end_time: endTime,
        room_id: backfillForm.value.room_id || null,
        Memo: [backfillForm.value.memo, `CSV匯入:${file.name}`].filter(Boolean).join(' | '),
        skip_auto_sessions: true,
      };

      const res = await fetch('/api/v1/student-classes', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(payload),
      });
      if (res.ok) {
        created += 1;
      } else {
        const err = await res.json().catch(() => ({}));
        failed.push(`${row.student_name}/${row.teacher_name}: ${err?.message || '建立失敗'}`);
      }
    }

    const summary = [
      `CSV 匯入完成：${file.name}`,
      `成功建立課程：${created} 筆`,
      `略過：${skipped.length} 筆`,
      `失敗：${failed.length} 筆`,
    ];
    if (skipped.length) {
      summary.push('', `略過（前 10 筆）：`, ...skipped.slice(0, 10).map((x) => `- ${x}`));
    }
    if (failed.length) {
      summary.push('', `失敗（前 10 筆）：`, ...failed.slice(0, 10).map((x) => `- ${x}`));
    }
    alert(summary.join('\n'));
    await loadCourses();
  } catch (e) {
    alert('CSV 匯入補登失敗：' + (e?.message || '請稍後再試'));
  } finally {
    backfillImporting.value = false;
    if (event?.target) event.target.value = '';
  }
}

function resolveGroupStudentId(group) {
  if (!group) return '';
  const key = String(group.key || '');
  if (key.startsWith('sid:')) {
    const n = Number(key.slice(4));
    if (Number.isFinite(n) && n > 0) return n;
  }
  const c0 = group.courses?.[0];
  const raw = c0?.student_id ?? c0?.StudentID;
  if (raw != null && raw !== '') {
    const n = Number(raw);
    if (Number.isFinite(n) && n > 0) return n;
  }
  return '';
}

const showDuplicateInterceptModal = ref(false);
const duplicateConflicts = ref([]);
const interceptPendingGroup = ref(null);
const interceptOriginalPayload = ref(null);
const forceSubmitting = ref(false);

async function openBackfillModalForGroup(group) {
  const sid = resolveGroupStudentId(group);
  if (!sid) {
    alert('無法取得此學生的編號，請改從上方「新增課程」手動選擇學生。');
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const res = await fetch(`/api/v1/students/${sid}/active-courses`, {
        headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      });
      if (res.ok) {
        const json = await res.json();
        const active = json?.courses || [];
        if (active.length > 0) {
          duplicateConflicts.value = active;
          interceptPendingGroup.value = group;
          showDuplicateInterceptModal.value = true;
          return;
        }
      }
    }
  } catch { /* proceed normally */ }
  proceedOpenBackfillForGroup(group);
}

function proceedOpenBackfillForGroup(group) {
  showDuplicateInterceptModal.value = false;
  const sid = resolveGroupStudentId(group);
  schedulerInitialStudentId.value = sid;
  schedulerInitialTeacherId.value = '';
  resetBackfillDatePicker();
  showBackfillModal.value = true;
  loadRoomsForBranch();
}

async function forceCreateCourse() {
  const payload = interceptOriginalPayload.value;
  if (!payload) {
    interceptPendingGroup.value
      ? proceedOpenBackfillForGroup(interceptPendingGroup.value)
      : openBackfillModal();
    return;
  }
  forceSubmitting.value = true;
  try {
    const result = await createUniversalClassSchedule({ ...payload, force: true });
    showDuplicateInterceptModal.value = false;
    interceptOriginalPayload.value = null;
    const created = Number(result?.created_confirmed_sessions ?? 0) + Number(result?.created_future_sessions ?? 0);
    alert(`已強制建立 ${created} 堂課`);
    await loadCourses();
  } catch (err) {
    alert(err?.message || '強制建立失敗，請稍後再試');
  } finally {
    forceSubmitting.value = false;
  }
}

function interceptGoToPurchaseCM(conflict) {
  showDuplicateInterceptModal.value = false;
  const target = courses.value.find(c => c.id === conflict.existing_course_id);
  if (target) {
    openPurchaseModal(target);
  }
}

function openBackfillModal() {
  schedulerInitialStudentId.value = '';
  schedulerInitialTeacherId.value = '';
  resetBackfillDatePicker();
  showBackfillModal.value = true;
  loadRoomsForBranch();
}

async function handleUniversalBackfillSuccess(result) {
  showBackfillModal.value = false;
  await loadCourses();
  if (result?.package_id) {
    const memberCount = result?.members?.length ?? 0;
    const pkgName = result?.package?.name ?? '多科方案';
    showCreationBanner(memberCount > 0
      ? `方案「${pkgName}」已建立，共 ${memberCount} 個科目已加入課程管理列表`
      : `方案「${pkgName}」已建立，課程已加入課程管理列表`);
  } else if (result) {
    showCreationBanner('課程建立成功，已更新課程管理列表');
  }
}

function handleSchedulerDuplicateCM(evt) {
  showBackfillModal.value = false;
  duplicateConflicts.value = (evt?.conflicts || []).map(c => ({
    existing_course_id: c.existing_course_id,
    subject_name: c.subject || '',
    remaining_sessions: c.remaining_sessions ?? 0,
    class_type: c.class_type || '',
  }));
  interceptOriginalPayload.value = evt?.originalPayload || null;
  showDuplicateInterceptModal.value = true;
}

// Edit
const showEditModal = ref(false);
const editingId = ref(null);
const editingCourseFromLaravel = ref(false);
const editingCourseRaw = ref(null);
const editFormRef = ref(null);
const editForm = ref({});
const editPackageInfo = computed(() => {
  const c = editingCourseRaw.value;
  if (!c?.PackageID) return null;
  return {
    id: c.PackageID,
    name: c.package_name || '共用方案',
    total_sessions: c.package_total_sessions ?? 0,
    remaining_sessions: c.package_remaining_sessions ?? 0,
  };
});
const editContextTitle = computed(() => {
  const c = editingCourseRaw.value;
  if (!c) return '';
  const subjectLabel = c.subject_name || c.subject || '';
  const studentName = c.student_name || '';
  return studentName ? `正在編輯：${subjectLabel} ／ ${studentName}` : `正在編輯：${subjectLabel}`;
});
/** 開啟編輯時的排課指紋；儲存時若變更則自動 force_partial_rebuild 同步未上預排堂次 */
const editScheduleBaseline = ref(null);
const originalFirstClassDate = ref('');
const rooms = ref([]);
const settlementDayOptions = Array.from({ length: 31 }, (_, i) => i + 1);
const showPurchaseModal = ref(false);
const purchaseCourse = ref(null);
const showRenewMonthlyModal = ref(false);
const renewMonthlyCourse = ref(null);
const renewMonthlyForm = ref({});
const purchaseForm = ref({
  sessions: 8,
  start_date: '',
  student_name: '',
  subject: 'Math',
});
const showQuickAddSessionModal = ref(false);
const quickAddSessionCourse = ref(null);
const quickAddConflict = ref(null);
const quickAddChecking = ref(false);
const quickAddSessionForm = ref({
  session_date: '',
  start_time: '16:00',
  duration_minutes: 120,
  note: '',
  auto_approve: true,
  student_name: '',
  subject: 'Math',
});
const pauseConfirmTarget = ref(null);
const pauseConfirmIsResume = computed(() => pauseConfirmTarget.value?.status === 'inactive');
const pauseConfirmImpacts = computed(() => pauseConfirmIsResume.value
  ? ['恢復後可繼續排課與補課', '後續仍依原課程設定計算堂數與提醒', '已取消的未來堂次不會自動重建，需依需要重新排課']
  : ['取消未來尚未上課堂次', '暫停期間不排新課、不計入待辦', '可從歷史課程或暫停清單恢復']);

const activeActionMenu = ref(null);
const toggleActionMenu = (courseId) => {
  activeActionMenu.value = activeActionMenu.value === courseId ? null : courseId;
};
const closeActionMenu = () => { activeActionMenu.value = null; };

const localTodayYmd = () => {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

function requestCoursePause(course) {
  pauseConfirmTarget.value = course;
}

async function confirmCoursePause() {
  const course = pauseConfirmTarget.value;
  if (!course) return;
  const isPaused = course.status === 'inactive';
  const action = isPaused ? '恢復' : '暫停';
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }

    const res = await fetch(`/api/v1/student-classes/${course.id}/pause`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ action: isPaused ? 'resume' : 'pause' }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      alert(`${action}失敗：` + (json.message || res.statusText));
      return;
    }
    alert(json.message || `已${action}`);
    pauseConfirmTarget.value = null;
    await loadCourses();
  } catch (e) {
    alert('操作失敗：' + (e?.message || '請稍後再試'));
  }
}

function canCloseCourse(c) {
  return c.status !== 'inactive'
    && isSessionMode(c)
    && c.payment_status === 'paid'
    && Number(c.remaining_sessions ?? 0) <= 0;
}

async function closeCourseNoRenew(course) {
  const studentName = course.student_name || '學生';
  const subject = getSubjectLabel(course.subject);
  if (!confirm(`確定要結案「${studentName}」的 ${subject} 課程嗎？\n\n結案後此課程將不再出現在繳費／續課提醒中。\n（等同暫停課程，之後仍可手動恢復。）`)) return;

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }

    const res = await fetch(`/api/v1/student-classes/${course.id}/pause`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ action: 'pause', reason: 'completed' }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      alert('結案失敗：' + (json.message || res.statusText));
      return;
    }
    alert('已結案，此課程不再出現在繳費／續課提醒中。');
    await loadCourses();
  } catch (e) {
    alert('操作失敗：' + (e?.message || '請稍後再試'));
  }
}

function duplicateCourseForTeacher(course) {
  const teacherName = course.teacher_name || '目前老師';
  const studentName = course.student_name || '學生';
  if (!confirm(`將為「${studentName}」複製此課程設定並指定另一位老師。\n（原課程 ${teacherName} 不受影響）\n\n確定要開啟新增課程介面嗎？`)) return;
  backfillForm.value = {
    ...backfillForm.value,
    student_id: course.student_id || '',
    subject: course.subject || 'Math',
    teacher_id: '',
    class_type: course.class_type || 'one_on_one',
    rate_per_30min: course.rate_per_30min ?? 500,
    duration_hours: course.duration_hours ?? 2,
    payment_type: course.payment_type || 'session',
    sessions_purchased: course.sessions_purchased || 8,
    days_of_week: [],
    start_time: course.start_time || '16:00',
    room_id: course.room_id || null,
    memo: `雙師課程（同學生另一位老師，原課程#${course.id}）`,
  };
  const sid = Number(course.student_id ?? course.StudentID) || '';
  schedulerInitialStudentId.value = sid;
  schedulerInitialTeacherId.value = '';
  showBackfillModal.value = true;
  loadRoomsForBranch();
}

function openPurchaseModal(course) {
  if (!isSessionMode(course)) {
    renewMonthlyCourse.value = course;
    renewMonthlyForm.value = {
      student_name: course?.student_name || '—',
      subject: course?.subject || 'Math',
      settlement_day: course?.settlement_day ?? null,
      monthly_sessions: course?.monthly_sessions ?? null,
      current_end_date: course?.end_date || course?.EndDate || null,
      months: 1,
      end_date: '',
    };
    showRenewMonthlyModal.value = true;
    return;
  }
  purchaseCourse.value = course;
  purchaseForm.value = {
    sessions: 8,
    start_date: localTodayYmd(),
    student_name: course?.student_name || '—',
    subject: course?.subject || 'Math',
  };
  showPurchaseModal.value = true;
}

async function submitPurchaseSessions() {
  const course = purchaseCourse.value;
  if (!course?.id) return;
  if (!Number.isFinite(Number(purchaseForm.value.sessions)) || Number(purchaseForm.value.sessions) <= 0) {
    alert('請輸入正確堂數');
    return;
  }
  if (!purchaseForm.value.start_date) {
    alert('請選擇新批次開始日期');
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('請重新登入後再試');
      return;
    }
    const res = await fetch(`/api/v1/student-classes/${course.id}/purchase-batch`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        sessions: Number(purchaseForm.value.sessions),
        start_date: purchaseForm.value.start_date,
        mode: 'new_purchase',
      }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
      alert(details || json?.message || '加購失敗');
      return;
    }
    showPurchaseModal.value = false;
    await loadCourses();
    const newCourse = json?.new_course || {};
    const groupKey = course?.student_id != null ? `sid:${course.student_id}` : null;
    if (groupKey) {
      expandedStudentGroups.value = new Set([...expandedStudentGroups.value, groupKey]);
      focusedStudentKey.value = groupKey;
    }
    const sessionRange = newCourse.first_session_date && newCourse.last_session_date
      ? `，上課日期 ${newCourse.first_session_date} 至 ${newCourse.last_session_date}`
      : '';
    toastRef.value?.show?.({
      title: '已建立加購批次',
      description: `新批次課程 #${newCourse.id || '—'} 已建立 ${Number(newCourse.created_sessions || 0)} 堂${sessionRange}。請查看此新批次詳情，原課程不會追加堂次。`,
      variant: 'success',
      durationMs: 7000,
    });
  } catch (e) {
    alert('加購失敗：' + (e?.message || '請稍後再試'));
  }
}

async function submitRenewMonthly(endDate) {
  const course = renewMonthlyCourse.value;
  if (!course?.id) return;
  if (!endDate) {
    alert('請選擇新到期日或延長月數');
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入後再試'); return; }
    const res = await fetch(`/api/v1/student-classes/${course.id}/renew-monthly`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({ end_date: endDate }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
      alert(details || json?.message || '續約失敗');
      return;
    }
    showRenewMonthlyModal.value = false;
    alert('月結續約成功，到期日已更新為 ' + endDate);
    await loadCourses();
  } catch (e) {
    alert('續約失敗：' + (e?.message || '請稍後再試'));
  }
}

function openQuickAddSessionModal(course) {
  quickAddSessionCourse.value = course;
  quickAddConflict.value = null;
  quickAddChecking.value = false;
  quickAddSessionForm.value = {
    session_date: localTodayYmd(),
    start_time: normalizeTo30Min(course?.start_time || '16:00'),
    duration_minutes: Math.max(30, Math.round((Number(course?.duration_hours) || 2) * 60)),
    note: '',
    auto_approve: true,
    student_name: course?.student_name || '—',
    subject: course?.subject || 'Math',
  };
  showQuickAddSessionModal.value = true;
  runQuickAddCheck(course?.id);
}

let _quickAddCheckTimer = null;
async function runQuickAddCheck(courseIdOverride) {
  const courseId = courseIdOverride || quickAddSessionCourse.value?.id;
  if (!courseId) return;
  const form = quickAddSessionForm.value;
  if (!form.session_date || !form.start_time) return;
  clearTimeout(_quickAddCheckTimer);
  _quickAddCheckTimer = setTimeout(async () => {
    quickAddChecking.value = true;
    quickAddConflict.value = null;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) return;
      const res = await fetch(`/api/v1/student-classes/${courseId}/add-session/check`, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ session_date: form.session_date, start_time: form.start_time }),
      });
      const json = await res.json().catch(() => ({}));
      quickAddConflict.value = json;
    } catch (_) {
      quickAddConflict.value = null;
    } finally {
      quickAddChecking.value = false;
    }
  }, 300);
}

/** 編輯課程彈窗內開啟補課/補登（與列表補課同一支 API） */
function openQuickAddSessionFromEditModal() {
  const id = editingId.value;
  if (!id || !editingCourseFromLaravel.value) return;
  const row = courses.value.find((x) => String(x.id) === String(id));
  const form = editForm.value;
  openQuickAddSessionModal({
    id,
    student_name: row?.student_name || '—',
    subject: form.subject || row?.subject || 'Math',
    start_time: form.start_time || row?.start_time || '16:00',
    duration_hours: form.duration_hours ?? row?.duration_hours ?? 2,
  });
}

async function submitQuickAddSession() {
  const course = quickAddSessionCourse.value;
  if (!course?.id) return;
  if (!quickAddSessionForm.value.session_date) {
    alert('請選擇上課日期');
    return;
  }
  if (!quickAddSessionForm.value.start_time) {
    alert('請選擇開始時間');
    return;
  }
  const durationMinutes = Number(quickAddSessionForm.value.duration_minutes || 0);
  if (!Number.isFinite(durationMinutes) || durationMinutes < 30) {
    alert('時長至少 30 分鐘');
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('請重新登入後再試');
      return;
    }
    const res = await fetch(`/api/v1/student-classes/${course.id}/add-session`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        session_date: quickAddSessionForm.value.session_date,
        start_time: quickAddSessionForm.value.start_time,
        duration_minutes: durationMinutes,
        note: quickAddSessionForm.value.note || null,
        auto_approve: !!quickAddSessionForm.value.auto_approve,
      }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      if (res.status === 409 && json?.suggested_actions?.length) {
        quickAddConflict.value = json;
        runQuickAddCheck();
      } else {
        const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
        alert(details || json?.message || '補課失敗');
      }
      return;
    }
    showQuickAddSessionModal.value = false;
    quickAddConflict.value = null;
    const movedFrom = String(json?.moved_from_date || '').slice(0, 10);
    const defaultMsg = movedFrom
      ? `已補登完成，已將原 ${movedFrom} 的堂次調整到新日期（總堂數不變）。`
      : (json?.no_total_increase ? '已補登完成（總堂數不變）。' : '已補登完成。');
    alert(json?.message ? `${json.message}\n${defaultMsg}` : defaultMsg);
    await loadCourses();
  } catch (e) {
    alert('補課失敗：' + (e?.message || '請稍後再試'));
  }
}
// ----- Leave (請假) -----
const showLeaveModal = ref(false);
const leaveCourse = ref(null);
const leaveForm = ref({
  student_id: '', student_name: '', subject: '', teacher_id: '', day_of_week: 1,
  start_time: '', end_time: '', duration_hours: 2, class_type: 'one_on_one',
  schedule_date: '', course_id: null, reason: ''
});
const RETRO_LEAVE_STATUSES = new Set(['completed', 'attended', 'late', 'excused', 'absent']);
const getLeaveSessionOptionsForCourse = (course) => {
  const cid = String(course?.id ?? '');
  const rows = classSessionsByCourse.value[cid];
  if (!Array.isArray(rows) || rows.length === 0) {
    const fallbackDates = sessions(course);
    return fallbackDates.map((date, i) => ({ date, index: i + 1, isRetro: false, session_id: null, start_time: null }));
  }

  const options = [];
  rows.forEach((row, idx) => {
    const status = String(row?.status || '').toLowerCase();
    if (['cancelled', 'leave', 'leave_adjusted'].includes(status)) return;
    const date = String(row?.session_date || '').slice(0, 10);
    if (!date) return;
    const isRetro = RETRO_LEAVE_STATUSES.has(status);
    const startTime = String(row?.start_time || '').slice(0, 5);
    options.push({
      date,
      index: idx + 1,
      isRetro,
      session_id: row?.id || null,
      start_time: startTime || null,
      label: startTime ? `${date} ${startTime}` : date,
    });
  });
  return options;
};
const leaveSessionOptions = computed(() => {
  const c = leaveCourse.value;
  if (!c) return [];
  return getLeaveSessionOptionsForCourse(c);
});
const isSelectedRetroLeave = computed(() => {
  const sid = leaveForm.value.session_id;
  const date = leaveForm.value.schedule_date;
  if (!date && !sid) return false;
  return leaveSessionOptions.value.some((opt) => {
    if (sid && opt.session_id) return opt.session_id === sid && opt.isRetro;
    return opt.date === date && opt.isRetro;
  });
});
async function openLeave(c) {
  await ensureCompletedSessionDatesLoaded(c);
  const opts = getLeaveSessionOptionsForCourse(c);
  if (!opts || opts.length === 0) {
    alert('此課程無可請假堂次（請確認開課日與排課設定）。');
    return;
  }
  const first = opts[0];
  leaveCourse.value = c;
  leaveForm.value = {
    student_id: c.student_id,
    student_name: c.student_name || '—',
    subject: c.subject,
    teacher_id: c.teacher_id || null,
    day_of_week: dayOfWeekFromDate(first.date),
    start_time: first.start_time || c.start_time || '16:00',
    end_time: c.end_time || computeEndTime(first.start_time || c.start_time || '16:00', c.duration_hours ?? 2),
    duration_hours: c.duration_hours ?? 2,
    class_type: c.class_type || 'one_on_one',
    schedule_date: first.date || '',
    session_id: first.session_id || null,
    course_id: c.id,
    reason: ''
  };
  showLeaveModal.value = true;
}
async function submitLeave() {
  if (!leaveForm.value.schedule_date) return;
  const branchId = Number(props.branchId) || 0;
  if (!branchId) { alert('請先選擇分校'); return; }
  const form = leaveForm.value;
  const isRetro = isSelectedRetroLeave.value;

  if (isRetro && !confirm('此堂已上課/已點名，確認要執行補請假嗎？\n（將沖回堂數、作廢出缺勤與評量記錄）')) {
    return;
  }

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('請假登記失敗：請重新登入後再試');
      return;
    }

    if (isRetro) {
      const retroPayload = {
        student_course_id: form.course_id,
        session_date: form.schedule_date,
        reason: form.reason || '',
      };
      const res = await fetch('/api/v1/schedules/retro-leave', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(retroPayload)
      });
      if (res.ok) {
        const json = await res.json().catch(() => ({}));
        const classKey = String(form.course_id || '');
        const normalized = normalizeClassSessionsPayload({ data: json?.class_sessions || [] });
        if (classKey && Array.isArray(normalized.byClass[classKey])) {
          classSessionsByCourse.value = {
            ...classSessionsByCourse.value,
            [classKey]: normalized.byClass[classKey]
          };
        }
        showLeaveModal.value = false;
        leaveCourse.value = null;
        alert('補請假完成：堂數已沖回');
        await loadCourses();
        return;
      }
      const errBody = await res.json().catch(() => ({}));
      alert('補請假失敗：' + (errBody.message || res.statusText || '請稍後再試'));
      return;
    }

    const payload = {
      student_id: form.student_id,
      teacher_id: form.teacher_id || null,
      subject: form.subject,
      day_of_week: form.day_of_week,
      start_time: form.start_time,
      end_time: form.end_time,
      duration_hours: form.duration_hours || 2,
      class_type: form.class_type || 'one_on_one',
      status: 'leave',
      type: 'normal',
      deduction: 0,
      branch_id: branchId,
      schedule_date: form.schedule_date,
      student_course_id: form.course_id
    };
    const res = await fetch('/api/v1/schedules', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify(payload)
    });
    if (res.ok) {
      const json = await res.json().catch(() => ({}));
      const classKey = String(form.course_id || '');
      const normalized = normalizeClassSessionsPayload({ data: json?.class_sessions || [] });
      if (classKey && Array.isArray(normalized.byClass[classKey])) {
        classSessionsByCourse.value = {
          ...classSessionsByCourse.value,
          [classKey]: normalized.byClass[classKey]
        };
      }
      showLeaveModal.value = false;
      leaveCourse.value = null;
      alert('請假登記完成');
      await loadCourses();
      return;
    }
    const errBody = await res.json().catch(() => ({}));
    alert('請假登記失敗：' + (errBody.message || res.statusText || '請稍後再試'));
    return;
  } catch (e) {
    alert('請假登記失敗：' + (e?.message || '請稍後再試'));
    return;
  }
}

// ----- Reschedule (調課) -----
async function submitBulkLeave() {
  const branchId = Number(props.branchId) || 0;
  if (!branchId) { alert('請先選擇分校'); return; }
  if (!bulkLeaveForm.value.start_date || !bulkLeaveForm.value.end_date) return;
  if (!confirm(`確定要將「${bulkLeaveForm.value.start_date}」至「${bulkLeaveForm.value.end_date}」區間所有課程批次請假嗎？`)) return;
  bulkLeaveSubmitting.value = true;
  bulkLeaveResult.value = null;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); bulkLeaveSubmitting.value = false; return; }
    const res = await fetch('/api/v1/schedules/bulk-leave', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        branch_id: branchId,
        start_date: bulkLeaveForm.value.start_date,
        end_date: bulkLeaveForm.value.end_date,
      })
    });
    const json = await res.json().catch(() => ({}));
    if (res.ok) {
      bulkLeaveResult.value = json;
      await loadCourses();
    } else {
      alert('批次請假失敗：' + (json.message || res.statusText));
    }
  } catch (e) {
    alert('批次請假失敗：' + (e?.message || '請稍後再試'));
  } finally {
    bulkLeaveSubmitting.value = false;
  }
}

watch(() => leaveForm.value.schedule_date, (date) => {
  if (!date) return;
  leaveForm.value.day_of_week = dayOfWeekFromDate(date);
});
function effectiveClosedReason(c) {
  if (c.closed_reason) return c.closed_reason;
  if (c.status === 'inactive' && isSessionMode(c) && c.payment_status === 'paid' && Number(c.remaining_sessions ?? 0) <= 0) {
    return 'completed';
  }
  // 月結制課程停用即視為完課（DB 無 closed_reason 的歷史髒資料也走此分支）
  if (c.status === 'inactive' && !isSessionMode(c)) {
    return 'completed';
  }
  return null;
}


function canQuickAddSession(c) {
  if (!isSessionMode(c)) return false;
  if (c.status === 'inactive') return false;
  if (effectiveClosedReason(c)) return false;
  return true;
}

function quickAddDisabledReason(c) {
  if (!isSessionMode(c)) return '僅堂數制課程可補課';
  if (effectiveClosedReason(c) === 'settled') return '已結算課程無法補課';
  if (effectiveClosedReason(c) === 'completed') return '已完課課程無法補課';
  if (c.status === 'inactive') return '課程已暫停，請先恢復後再補課';
  return '';
}

const courseRowClass = (c) => {
  if (c.status !== 'inactive') return {};
  const reason = effectiveClosedReason(c);
  if (reason === 'settled' || reason === 'completed') return { 'course-settled': true };
  return { 'course-paused': true };
};
const getSubjectLabel = (val) => getSubjectText(val);
const classTypeLabel = (type) => {
  const map = { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導', trial: '試聽' };
  return map[type] || type;
};
const courseMemo = (course) => {
  const text = String(course?.memo ?? course?.Memo ?? '').trim();
  return text || '';
};

const CLASS_CAPACITY = { one_on_one: 1, one_on_two: 2, one_on_three: 3, tutoring: 4, trial: 1 };
function getCapacityForClassType(type) { return CLASS_CAPACITY[type] ?? 1; }
const dayLabel = (d) => ['', '週一', '週二', '週三', '週四', '週五', '週六', '週日'][d] || '';

const SESSION_INFER_SKIP = new Set(['cancelled', 'leave_adjusted']);

function sessionsRowsForCourse(course) {
  const cid = String(course?.id ?? course?.ID ?? '');
  const rows = classSessionsByCourse.value[cid];
  return Array.isArray(rows) ? rows : [];
}

/** 同一天已排多個不同開始時間（與下方上課日期列表一致） */
function hasMultiStartSameCalendarDay(rows) {
  const byDate = new Map();
  for (const r of rows) {
    const st = String(r.status || '').toLowerCase();
    if (SESSION_INFER_SKIP.has(st)) continue;
    const d = String(r.session_date || '').slice(0, 10);
    if (!d || !r.start_time) continue;
    if (!byDate.has(d)) byDate.set(d, new Set());
    byDate.get(d).add(String(r.start_time).slice(0, 5));
  }
  for (const starts of byDate.values()) {
    if (starts.size >= 2) return true;
  }
  return false;
}

function diffMinutesStartEnd(startRaw, endRaw) {
  const parse = (t) => {
    const s = String(t || '').slice(0, 5);
    const [h, m] = s.split(':').map(Number);
    return (Number(h) || 0) * 60 + (Number(m) || 0);
  };
  let diff = parse(endRaw) - parse(startRaw);
  if (diff <= 0) diff += 24 * 60;
  return diff;
}

/** 由已載入的 ClassSession 推斷固定 (星期幾, 開始) 時段（不含取消堂） */
function distinctDowStartSlotsFromSessions(course, rows) {
  const globalDur = Number(course?.duration_hours) || 2;
  const map = new Map();
  for (const r of rows) {
    const st = String(r.status || '').toLowerCase();
    if (SESSION_INFER_SKIP.has(st)) continue;
    const d = String(r.session_date || '').slice(0, 10);
    if (!d || !r.start_time) continue;
    const dow = dayOfWeekFromDate(d);
    const start = String(r.start_time).slice(0, 5);
    const key = `${dow}|${start}`;
    let dur = globalDur;
    if (r.end_time) {
      const mins = diffMinutesStartEnd(r.start_time, r.end_time);
      if (mins >= 30) dur = Math.max(0.5, Math.round(mins / 30) / 2);
    }
    if (!map.has(key)) {
      map.set(key, { day: dow, start, dur });
    } else {
      const cur = map.get(key);
      map.set(key, { ...cur, dur: Math.max(Number(cur.dur) || 0, dur) });
    }
  }
  return [...map.values()].sort((a, b) => a.day - b.day || a.start.localeCompare(b.start));
}

/** 每段一行（同日多時段會多行），供「時段」欄位顯示；以契約 day_time_slots 為準 */
const formatDayTimeSlotLines = (course) => {
  const slots = Array.isArray(course?.day_time_slots) ? course.day_time_slots : [];
  const globalDur = Number(course?.duration_hours) || 2;
  const normalized = slots
    .map((s) => ({
      day: Number(s?.day || 0),
      start: String(s?.start_time || '').slice(0, 5),
      dur: Number(s?.duration_hours || 0) || globalDur,
    }))
    .filter((s) => s.day >= 1 && s.day <= 7 && s.start)
    .sort((a, b) => a.day - b.day || a.start.localeCompare(b.start));

  const allSameDur = new Set(normalized.map((s) => s.dur)).size <= 1;
  return normalized.map((s) => {
    const end = computeEndTime(s.start, s.dur) || '';
    const durSuffix = !allSameDur ? ` ${s.dur}h` : '';
    return `${dayLabel(s.day)} ${s.start}~${end}${durSuffix}`;
  });
};

const formatDayTimeSlots = (course) => formatDayTimeSlotLines(course).join('、');

// 與學生管理共用單一費用邏輯（Single Source of Truth）
const sessionPrice = (c) => getPerSessionFee(c);
const totalPrice = (c) => getCourseTotalFee(c);
// 月結制：已上堂費用 = 實際已上堂數 × 每堂費用（月結無預購堂數，用 completed count）
const isMonthlyMode = (c) => (c?.payment_type || 'session') !== 'session';
const monthlyAttendedFee = (c) => Math.round(getPerSessionFee(c) * getCompletedSessionCount(c));

// 備註開關（預設關閉，截圖給家長時保持乾淨）
const showSessionNotes = ref(localStorage.getItem('cm_show_notes') === '1');
const toggleSessionNotes = () => {
  showSessionNotes.value = !showSessionNotes.value;
  localStorage.setItem('cm_show_notes', showSessionNotes.value ? '1' : '0');
};

// 系統自動產生的 Note 片段 pattern，符合的不算使用者備註
const SYSTEM_NOTE_PATTERNS = [
  /^系統/,                        // 系統重建堂次、系統判定補登、系統調整堂次…
  /^auto-extended-after-leave$/,
  /^leave$/,
  /^retro-leave$/,
  /^cancelled-after-attended$/,
  /^revert-to-scheduled$/,
  /^請假自動順延$/,
];
const isSystemNotePart = (part) => SYSTEM_NOTE_PATTERNS.some((p) => p.test(part.trim()));
// 備註被 '; ' 分隔後，只要有任一片段不是系統備註，就視為使用者備註
const isUserNote = (note) => {
  if (!note || !note.trim()) return false;
  return note.trim().split(/;\s*/).some((part) => part.trim() && !isSystemNotePart(part));
};

const groupCoursesByStudent = (list = []) => {
  const grouped = [];
  const groupedMap = new Map();
  for (const c of list) {
    const studentName = String(c?.student_name || '').trim() || '未命名學生';
    const studentId = c?.student_id ?? c?.StudentID ?? null;
    const key = studentId != null && studentId !== ''
      ? `sid:${studentId}`
      : `name:${studentName}`;
    if (!groupedMap.has(key)) {
      const group = { key, student_name: studentName, courses: [] };
      groupedMap.set(key, group);
      grouped.push(group);
    }
    groupedMap.get(key).courses.push(c);
  }
  return grouped;
};

const groupedCourses = computed(() => groupCoursesByStudent(courses.value));

const resetExpandedStudentGroups = (groups = groupedCourses.value) => {
  expandedStudentGroups.value = new Set(groups.map((g) => g.key));
};

const toggleStudentGroup = (groupKey) => {
  const next = new Set(expandedStudentGroups.value);
  if (next.has(groupKey)) next.delete(groupKey);
  else next.add(groupKey);
  expandedStudentGroups.value = next;
};

const groupHasPausedCourse = (group) =>
  (group?.courses || []).some((c) => c.status === 'inactive' && !effectiveClosedReason(c));

const isHistoryCourse = (c) => {
  const reason = effectiveClosedReason(c);
  return reason === 'settled' || reason === 'completed';
};
const activeCourses = (group) => (group?.courses || []).filter(c => !isHistoryCourse(c));
const historyCourses = (group) => (group?.courses || []).filter(c => isHistoryCourse(c));
const expandedHistoryGroups = ref(new Set());
const toggleHistoryGroup = (key) => {
  const s = new Set(expandedHistoryGroups.value);
  if (s.has(key)) s.delete(key);
  else s.add(key);
  expandedHistoryGroups.value = s;
};

const expandAllGroups = () => {
  resetExpandedStudentGroups(groupedCourses.value);
};
const collapseAllGroups = () => {
  expandedStudentGroups.value = new Set();
};

const coursesByType = computed(() => {
  const c = courses.value;
  return {
    one_on_one: c.filter(x => x.class_type === 'one_on_one').length,
    one_on_two: c.filter(x => x.class_type === 'one_on_two').length,
    one_on_three: c.filter(x => x.class_type === 'one_on_three').length,
    tutoring: c.filter(x => x.class_type === 'tutoring').length,
    trial: c.filter(x => x.class_type === 'trial').length
  };
});

const coursesBySubject = computed(() => {
  const map = {};
  for (const c of courses.value) {
    const s = c.subject || '';
    if (!s) continue;
    map[s] = (map[s] || 0) + 1;
  }
  return Object.entries(map).map(([subject, count]) => ({
    subject,
    label: getSubjectLabel(subject),
    count
  })).sort((a, b) => b.count - a.count);
});

const paymentStatusButtonClass = (course) => {
  return course?.payment_status === 'paid' ? 'tag-paid' : 'tag-unpaid';
};
const paymentStatusButtonLabel = (course) => {
  return course?.payment_status === 'paid' ? '已繳費' : '未繳費';
};

const loadCourses = async (page = 1) => {
  if (!props.branchId) {
    courses.value = [];
    pagination.value = { page: 1, lastPage: 1, total: 0, perPage: 50 };
    completedSessionDatesByCourse.value = {};
    classSessionsByCourse.value = {};
    effectiveSessionDatesByCourse.value = {};
    expandedStudentGroups.value = new Set();
    return;
  }
  completedSessionDatesByCourse.value = {};
  classSessionsByCourse.value = {};
  effectiveSessionDatesByCourse.value = {};
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const params = new URLSearchParams({
        branch_id: String(props.branchId),
        per_page: String(pagination.value.perPage),
        page: String(page),
      });
      if (filters.value.class_type) params.set('class_type', filters.value.class_type);
      if (filters.value.teacher_id) params.set('teacher_id', String(filters.value.teacher_id));
      if (filters.value.teacher_name?.trim()) params.set('teacher_name', filters.value.teacher_name.trim());
      if (filters.value.course_status) params.set('status', filters.value.course_status);
      if (filters.value.name) params.set('name', filters.value.name);
      const res = await fetch(`/api/v1/student-classes?${params}`, {
        credentials: 'include',
        headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` }
      });
      if (res.ok) {
        const json = await res.json();
        const list = json?.data ?? json;
        const arr = Array.isArray(list) ? list : (list?.data ?? []);
        const result = arr.map(c => ({
          ...c,
          data_source: 'laravel',
          student_name: c.student_name ?? '—',
          teacher_name: c.teacher_name ?? '',
          memo: c.memo ?? c.Memo ?? '',
          sessions_used: c.sessions_used ?? c.UsedSessions ?? null,
          remaining_sessions: c.remaining_sessions ?? c.RemainingSessions ?? null
        }));
        pagination.value = {
          page: Number(json?.current_page ?? page),
          lastPage: Number(json?.last_page ?? 1),
          total: Number(json?.total ?? arr.length),
          perPage: pagination.value.perPage,
        };
        courses.value = result;
        resetExpandedStudentGroups(groupCoursesByStudent(result));
        await loadClassSessionsForCourses(result, token);
        await loadEffectiveSessionDates(result, token);
        return;
      }
    }
  } catch (_) {}
  let query = supabase
    .from('student-classes')
    .select('*, student:students(name, remaining_lessons), teacher:profiles(username)')
    .eq('branch_id', props.branchId);

  if (filters.value.class_type) query = query.eq('class_type', filters.value.class_type);
  if (filters.value.teacher_id) query = query.eq('teacher_id', Number(filters.value.teacher_id));
  const { data } = await query;
  let result = (data || []).map(c => ({
    ...c,
    data_source: 'supabase',
    student_name: c.student?.name || '—',
    teacher_name: c.teacher_name || c.teacher?.username || '',
    memo: c.memo ?? c.Memo ?? '',
    sessions_used: c.sessions_used ?? c.used_sessions ?? c.UsedSessions ?? null,
    remaining_sessions: c.remaining_sessions ?? c.RemainingSessions ?? null,
    branch_name: null,
    room_name: null,
    settlement_day: null
  }));

  if (filters.value.teacher_id) {
    const id = String(filters.value.teacher_id);
    result = result.filter(c => String(c.teacher_id ?? c.TeacherID ?? '') === id);
  }
  if (filters.value.name) {
    const q = filters.value.name.toLowerCase();
    result = result.filter(c => c.student_name.toLowerCase().includes(q));
  }
  if (filters.value.teacher_name?.trim()) {
    const q = filters.value.teacher_name.trim().toLowerCase();
    result = result.filter(c => (c.teacher_name || '').toLowerCase().includes(q));
  }

  pagination.value = { page: 1, lastPage: 1, total: result.length, perPage: pagination.value.perPage };
  courses.value = result;
  resetExpandedStudentGroups(groupCoursesByStudent(result));
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    await loadClassSessionsForCourses(result, token || '');
    await loadEffectiveSessionDates(result, token || '');
  } catch (_) {
    classSessionsByCourse.value = {};
    effectiveSessionDatesByCourse.value = {};
  }
};

const {
  showRescheduleModal, rescheduleCourse, rescheduleForm, rescheduleSessionOptions,
  openReschedule, onRescheduleNewStartChange, submitReschedule,
  showMakeupSlotsModal, makeupLoading, makeupDateRange, availableMakeupSlots,
  makeupSlotsGrouped, fetchMakeupSlots, selectMakeupSlot,
} = useRescheduleAndMakeup({
  supabase,
  branchId: computed(() => props.branchId),
  computeEndTime,
  normalizeTo30Min,
  dayOfWeekFromDate,
  classSessionsByCourse,
  sessions,
  ensureCompletedSessionDatesLoaded,
  loadCourses,
  getCapacityForClassType,
});

const {
  showSessionEditModal, sessionEditMode, sessionEditSubmitting, sessionEditForm,
  secondaryStatusSelection, secondaryStatusOptions,
  SESSION_STATUS_TRANSITIONS, SESSION_STATUS_LABELS,
  sessionStatusLabel, canTransitionTo, applySecondaryStatus,
  openSessionEdit, openSessionEditFromAction, closeSessionEdit,
  addSessionFromModal, doStatusChange,
  startRetroLeave, doRetroLeave,
  startSessionReschedule, fetchMakeupSlotsForEdit, doSessionReschedule,
  startSubstitute, doSubstitute,
  startEditNoteTime, doEditNoteTime,
} = useSessionEditFlow({
  supabase,
  branchId: computed(() => props.branchId),
  computeEndTime,
  normalizeTo30Min,
  dayOfWeekFromDate,
  getSessionDisplayRow,
  formatAttendanceTooltipTime,
  updateLocalSessionRow,
  ensureCompletedSessionDatesLoaded,
  displaySessions,
  todayYmd,
  rescheduleCourse,
  rescheduleForm,
  fetchMakeupSlots,
  loadCourses,
  openQuickAddSessionModal,
});

// ===== PRD 9c058f19 代課 V2（與 SmartCalendar 對齊：卡片式 Picker + ToastWithUndo） =====
const featureSubstituteV2 = FEATURE_SUBSTITUTE_V2;
const showSubstituteV2Modal = ref(false);
const substituteV2PickerRef = ref(null);
const toastRef = ref(null);
const substituteV2SessionId = ref(null);
const substituteV2Context = ref({});

// 多數使用者為單分校主任，名稱由後端返回時可擴充；此處使用 id → label 降級，保持 UX 可用。
const branchNameMap = computed(() => {
  const m = {};
  const bid = Number(props.branchId || 0);
  if (bid > 0) m[bid] = `分校#${bid}`;
  return m;
});

// Picker 期望 { id, name, branch_ids }；本頁 teachers 欄位是 username，需映射補上 name。
const teachersForPicker = computed(() =>
  (teachers.value || []).map((t) => ({
    id: t.id,
    name: t.name || t.username || `老師#${t.id}`,
    username: t.username || '',
    branch_ids: Array.isArray(t.branch_ids) ? t.branch_ids : [],
  }))
);

// 從「單堂檢視」觸發 V2 代課選擇器：以 sessionEditForm 內容建構 context。
const openSubstituteV2FromEdit = () => {
  const form = sessionEditForm.value || {};
  const course = form.course || {};
  if (!form.session_id) {
    alert('找不到該堂次 ClassSession，無法設定代課。');
    return;
  }
  substituteV2SessionId.value = form.session_id;
  substituteV2Context.value = {
    student_name: form.student_name || course.student_name || '',
    subject_id: course.subject_id || null,
    subject_label: getSubjectLabel(form.subject || course.subject) || '',
    session_date: form.session_date || '',
    start_time: (form.start_time || '').toString().slice(0, 5),
    end_time: (form.end_time || '').toString().slice(0, 5),
    original_teacher_id: course.teacher_id ?? null,
    original_teacher_name: form.teacher_name || course.teacher_name || '',
    session_campus_id: Number(props.branchId || 0) || null,
  };
  closeSessionEdit();
  showSubstituteV2Modal.value = true;
};

const onSubstituteV2Submit = async (submitPayload) => {
  const { substitute_teacher_id, reason, new_date, new_start_time, new_end_time } = submitPayload || {};
  const sessionId = substituteV2SessionId.value;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) throw new Error('請重新登入');
    const body = {
      substitute_teacher_id: Number(substitute_teacher_id),
      reason: reason || null,
    };
    // PRD f0cce4d5：合併代課+換時（三欄同填同省）
    if (new_date && new_start_time && new_end_time) {
      body.new_date = new_date;
      body.new_start_time = new_start_time;
      body.new_end_time = new_end_time;
    }
    const res = await fetch(`/api/v1/class-sessions/${sessionId}/substitute`, {
      method: 'POST', credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const msg = json.message || res.statusText || '代課設定失敗';
      substituteV2PickerRef.value?.setError?.(msg);
      throw new Error(msg);
    }
    showSubstituteV2Modal.value = false;

    // 對齊 SmartCalendar：先在本地 patch 這堂的授課老師，再整體重載
    const ctx = substituteV2Context.value || {};
    const courseKey = sessionEditForm.value?.student_class_id || sessionEditForm.value?.course?.id;
    const teacherName =
      json.substitute_teacher_name ||
      (teachers.value || []).find((t) => Number(t.id) === Number(substitute_teacher_id))?.username ||
      `#${substitute_teacher_id}`;
    const uiSeconds = Number(json.undo_window_seconds);
    const durationMs = Number.isFinite(uiSeconds) && uiSeconds > 0 ? uiSeconds * 1000 : 5000;
    const isCombined = json.rescheduled === true || json.operation_type === 'substitute_with_reschedule';
    const effDate = json.session_date || ctx.session_date;
    const effStart = json.start_time || ctx.start_time;
    const effEnd = json.end_time || ctx.end_time;
    const origDate = json.original_session_date || ctx.session_date;
    const origStart = json.original_start_time || ctx.start_time;
    const origEnd = json.original_end_time || ctx.end_time;
    // PRD f0cce4d5 P2：不等整頁重載，立即在本地 patch 代課老師 + （若換時）新日期/新時段
    if (json.substitute_teacher_id) {
      const teacherObj = (teachers.value || []).find((t) => Number(t.id) === Number(json.substitute_teacher_id));
      const patch = {
        id: sessionId,
        teacher_id: json.substitute_teacher_id,
        teacher_name: json.substitute_teacher_name || teacherObj?.username || '',
      };
      if (isCombined) {
        if (effDate) patch.session_date = effDate;
        if (effStart) patch.start_time = effStart;
        if (effEnd) patch.end_time = effEnd;
      }
      updateLocalSessionRow(courseKey, patch);
    }

    const description = isCombined
      ? `${ctx.student_name ? ctx.student_name + ' · ' : ''}已調整至 ${effDate} ${effStart}~${effEnd}`
      : (ctx.student_name ? `${ctx.student_name} · ${ctx.session_date} ${ctx.start_time}` : '');
    toastRef.value?.show?.({
      title: isCombined ? `已指派 ${teacherName} 代課並調整時間` : `已指派 ${teacherName} 代課`,
      description,
      variant: 'success',
      durationMs,
      undoDescription: isCombined ? '代課與換時已撤銷，家長通知已作廢' : '代課已撤銷，家長通知已作廢',
      onUndo: async () => {
        await undoSubstitute(sessionId);
        // PRD f0cce4d5 P2：Undo 也先就地還原本地 row（老師 + 若含換時則還原時間），不等重載
        const undoPatch = { id: sessionId };
        if (ctx.original_teacher_id) undoPatch.teacher_id = ctx.original_teacher_id;
        if (ctx.original_teacher_name) undoPatch.teacher_name = ctx.original_teacher_name;
        if (isCombined) {
          if (origDate) undoPatch.session_date = origDate;
          if (origStart) undoPatch.start_time = origStart;
          if (origEnd) undoPatch.end_time = origEnd;
        }
        updateLocalSessionRow(courseKey, undoPatch);
        await loadCourses();
      },
    });
    await loadCourses();
  } catch (e) {
    substituteV2PickerRef.value?.setError?.(e?.message || '代課設定失敗');
    throw e;
  }
};

const togglePaymentStatus = async (c) => {
  if (!c?.id) return;

  // 未繳費 → 已繳費：一律走核帳登記 Modal（強制填繳款日期）
  if (c.payment_status !== 'paid') {
    paymentEntryRow.value = {
      id: c.id,
      student_name: c.student_name || '此學生',
      subject: c.subject_name || c.subject || '',
      charge: c.Charge ?? c.charge ?? 0,
    };
    paymentEntryOpen.value = true;
    return;
  }

  // 已繳費 → 未繳費：保留原有 confirm 流程
  if (!confirm(`確定將「${c.student_name || '此學生'}」課程改為「未繳費」嗎？`)) return;

  if (c.data_source === 'laravel' || c.branch_name != null || c.room_name != null || c.settlement_day != null) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const sendUnpaid = async (extra = {}) => fetch(`/api/v1/student-classes/${c.id}`, {
          method: 'PUT',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify({ payment_status: 'unpaid', paid_at: null, ...extra }),
        });
        let res = await sendUnpaid();
        if (res.status === 409) {
          const errBody = await res.json().catch(() => ({}));
          const w = errBody?.warnings || {};
          const amount = Number(w.total_paid_amount || 0).toLocaleString();
          const msg = [
            '此課程已有發票收款記錄：',
            `  • 發票 ${w.invoice_count || 0} 筆`,
            `  • 付款 ${w.payment_count || 0} 筆，共 NT$ ${amount}`,
            '',
            '直接改為未繳費將與發票資料不同步（會計建議走「付款報表 → 作廢」）。',
            '仍要強制改為未繳費嗎？',
          ].join('\n');
          if (!confirm(msg)) return;
          res = await sendUnpaid({ force_clear_paid: true });
        }
        if (res.ok) {
          c.payment_status = 'unpaid';
          c.paid_at = null;
          c.last_paid_at = null;
          return;
        }
      }
    } catch (_) {}
  }
  await supabase.from('student-classes').update({ payment_status: 'unpaid' }).eq('id', c.id);
  c.payment_status = 'unpaid';
  c.paid_at = null;
  c.last_paid_at = null;
};

const onPaymentEntryConfirmed = async () => {
  paymentEntryOpen.value = false;
  await loadCourses();
};

const loadStudents = async () => {
  const branchId = props.branchId != null ? String(props.branchId) : '';
  if (!branchId) {
    allStudents.value = [];
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const params = new URLSearchParams({
        branch_id: branchId,
        per_page: '500',
      });
      const res = await fetch(`/api/v1/students?${params.toString()}`, {
        credentials: 'include',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (res.ok) {
        const json = await res.json().catch(() => ({}));
        const list = json?.data ?? json;
        const arr = Array.isArray(list) ? list : (Array.isArray(list?.data) ? list.data : []);
        allStudents.value = arr.map((s) => ({
          id: Number(s?.id ?? 0),
          name: String(s?.name ?? '').trim(),
        })).filter((s) => s.id > 0 && s.name);
        return;
      }
    }
  } catch (_) {
    // fallback below
  }

  // Legacy fallback for older local data sources.
  const { data } = await supabase.from('students').select('id, name').eq('branch_id', props.branchId).order('name');
  allStudents.value = data || [];
};

const loadTeachers = async () => {
  const currentBranchId = props.branchId != null ? String(props.branchId) : '';
  if (!currentBranchId) {
    teachers.value = [];
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { teachers.value = []; return; }
    const params = new URLSearchParams({
      per_page: 'all',
      status: 'active',
      branch_id: currentBranchId,
    });
    const res = await fetch(`/api/v1/teachers?${params.toString()}`, {
      headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` }
    });
    const data = await res.json().catch(() => ({}));
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    const filteredRows = (Array.isArray(list) ? list : []).filter((teacher) => {
      if ((teacher?.status || 'active') !== 'active') return false;
      const branchIds = Array.isArray(teacher?.branch_ids)
        ? teacher.branch_ids.map((id) => String(id))
        : [];
      if (branchIds.length > 0) return branchIds.includes(currentBranchId);
      if (teacher?.branch_id == null) return false;
      return String(teacher.branch_id) === currentBranchId;
    });
    const dedupById = new Map();
    filteredRows.forEach((teacher) => dedupById.set(String(teacher.id), teacher));
    teachers.value = Array.from(dedupById.values()).map((t) => ({
      id: t.id,
      username: t.username,
      branch_ids: Array.isArray(t.branch_ids) ? t.branch_ids : [],
    }));

    const teacherIdSet = new Set(teachers.value.map((t) => String(t.id)));
    if (backfillForm.value.teacher_id && !teacherIdSet.has(String(backfillForm.value.teacher_id))) {
      backfillForm.value.teacher_id = '';
    }
    if (editForm.value?.teacher_id && !teacherIdSet.has(String(editForm.value.teacher_id))) {
      editForm.value.teacher_id = '';
    }
  } catch (_) {
    teachers.value = [];
  }
};

let _debouncedTimer = null;
const debouncedLoad = () => {
  clearTimeout(_debouncedTimer);
  _debouncedTimer = setTimeout(() => loadCourses(1), 300);
};
const goToPage = (p) => {
  if (p < 1 || p > pagination.value.lastPage) return;
  loadCourses(p);
};

const loadRoomsForBranch = async () => {
  if (!props.branchId) { rooms.value = []; return; }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    const headers = { 'Accept': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    const res = await fetch(`/api/v1/rooms?branch_id=${props.branchId}`, { credentials: 'include', headers });
    if (res.ok) {
      const data = await res.json();
      rooms.value = Array.isArray(data) ? data : [];
    } else rooms.value = [];
  } catch (_) { rooms.value = []; }
};

/** 固定排課／開課日／預設時長是否變更（不含老師費率等非排程欄位） */
function scheduleFingerprintForEdit(form) {
  const f = form || {};
  const days = [...new Set((f.days_of_week || []).map(Number).filter((d) => d >= 1 && d <= 7))]
    .sort((a, b) => a - b)
    .join(',');
  const slotRows = (f.day_time_slots || [])
    .map((s) => ({
      day: Number(s?.day || 0),
      start: normalizeTo30Min(String(s?.start_time || f.start_time || '16:00').slice(0, 5)),
      dur: Math.round((Number(s?.duration_hours || 0) || Number(f.duration_hours) || 2) * 10) / 10,
    }))
    .filter((s) => s.day >= 1 && s.day <= 7)
    .sort((a, b) => a.day - b.day || a.start.localeCompare(b.start) || a.dur - b.dur);
  const slots = slotRows.map((s) => `${s.day}|${s.start}|${s.dur}`).join(';');
  const dur = Math.round((Number(f.duration_hours) || 2) * 10) / 10;
  const start = normalizeTo30Min(String(f.start_time || '16:00').slice(0, 5));
  const first = String(f.first_class_date || '').slice(0, 10);
  return `${days}|${slots}|${dur}|${start}|${first}`;
}

const editCourse = (c) => {
  editingId.value = c.id;
  editingCourseRaw.value = c;
  editingCourseFromLaravel.value = !!(
    c.data_source === 'laravel'
    || c.branch_name != null
    || c.room_name != null
    || c.settlement_day != null
  );
  const existingDaysRaw = Array.isArray(c.days_of_week) && c.days_of_week.length
    ? c.days_of_week.map(Number).filter((d) => d >= 1 && d <= 7)
    : (c.day_of_week ? [Number(c.day_of_week)] : []);

  const existingSlots = Array.isArray(c.day_time_slots)
    ? c.day_time_slots
        .map((slot) => ({
          day: Number(slot?.day || 0),
          start_time: normalizeTo30Min(slot?.start_time || c.start_time || '16:00'),
          duration_hours: Number(slot?.duration_hours || 0) || c.duration_hours || 2,
        }))
        .filter((slot) => slot.day >= 1 && slot.day <= 7)
    : [];

  const slotDays = existingSlots.map((s) => s.day).filter((d) => d >= 1 && d <= 7);
  const existingDays = [...new Set([...existingDaysRaw, ...slotDays])].sort((a, b) => a - b);

  editForm.value = {
    subject: c.subject,
    teacher_id: c.teacher_id || '',
    class_type: c.class_type,
    rate_per_30min: c.rate_per_30min,
    duration_hours: c.duration_hours ?? 2,
    sessions_purchased: c.sessions_purchased ?? 8,
    remaining_sessions: c.remaining_sessions ?? 0,
    days_of_week: existingDays,
    day_time_slots: existingSlots,
    start_time: normalizeTo30Min(c.start_time || '16:00'),
    end_time: c.end_time || '',
    payment_type: c.payment_type || 'session',
    settlement_day: c.settlement_day ?? null,
    monthly_sessions: c.monthly_sessions ?? null,
    first_class_date: c.first_class_date || '',
    room_id: c.room_id ?? null,
    memo: c.memo ?? c.Memo ?? '',
    paid_at: c.paid_at || c.last_paid_at || ''
  };
  originalFirstClassDate.value = c.first_class_date || '';
  loadRoomsForBranch();
  showEditModal.value = true;
  nextTick(() => {
    editScheduleBaseline.value = scheduleFingerprintForEdit(editForm.value);
  });
};

const submitEdit = async () => {
  const id = editingId.value;
  const form = editForm.value;
  if (editingCourseFromLaravel.value) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const endTime = computeEndTime(form.start_time, form.duration_hours);
        const isPackageCourse = !!editingCourseRaw.value?.PackageID;
        const body = {
          subject: form.subject,
          teacher_id: form.teacher_id || null,
          class_type: form.class_type,
          rate_per_30min: form.rate_per_30min,
          duration_hours: form.duration_hours,
          sessions_purchased: form.sessions_purchased,
          ...(isPackageCourse ? {} : { remaining_sessions: form.remaining_sessions }),
          days_of_week: (form.days_of_week || []).length ? form.days_of_week : [],
          start_time: form.start_time,
          day_time_slots: (form.day_time_slots || [])
            .map((slot) => ({
              day: Number(slot?.day || 0),
              start_time: normalizeTo30Min(slot?.start_time || form.start_time || '16:00'),
              duration_minutes: Number(slot?.duration_hours || 0) > 0 ? Math.round(Number(slot.duration_hours) * 60) : undefined,
            }))
            .filter((slot) => slot.day >= 1 && slot.day <= 7),
          end_time: endTime,
          payment_type: form.payment_type,
          settlement_day: form.payment_type === 'monthly' ? form.settlement_day : null,
          monthly_sessions: form.payment_type === 'monthly' ? form.monthly_sessions : null,
          first_class_date: form.first_class_date || null,
          force_rebuild_if_mismatch: true,
          room_id: form.room_id || null,
          Memo: form.memo || null
        };
        body.paid_at = form.paid_at ? form.paid_at : null;
        const res = await fetch(`/api/v1/student-classes/${id}`, {
          method: 'PUT',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify(body)
        });
        if (res.ok) {
          const payload = await res.json().catch(() => ({}));
          const sync = payload?.session_sync || {};
          const baseline = editScheduleBaseline.value;
          const scheduleChanged = baseline != null && scheduleFingerprintForEdit(form) !== baseline;
          let scheduleAutoRebuildOk = false;
          if (scheduleChanged) {
            const rbRes = await fetch(`/api/v1/student-classes/${id}`, {
              method: 'PUT',
              credentials: 'include',
              headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
              body: JSON.stringify({ force_partial_rebuild: true }),
            });
            const rbPayload = await rbRes.json().catch(() => ({}));
            if (rbRes.ok) {
              scheduleAutoRebuildOk = true;
              sync._auto_rebuild_updated = Number(rbPayload?.session_sync?.updated_future_sessions ?? 0);
            } else {
              sync._auto_rebuild_failed = rbPayload?.message || rbRes.statusText;
            }
          }
          editScheduleBaseline.value = null;
          let successMsg = '課程已更新。';
          if (scheduleChanged && scheduleAutoRebuildOk) {
            const u = Number(sync._auto_rebuild_updated ?? 0) + Number(sync.updated_future_sessions ?? 0);
            if (u > 0) {
              successMsg += ` 已依新固定排課同步 ${u} 筆未上堂次（已點名／已核准堂次維持不變）。`;
            } else {
              successMsg += ' 未上預排堂次已與新固定排課對齊（無需變更或已無未上堂次）。';
            }
          } else if (sync?._auto_rebuild_failed) {
            successMsg += ` 未上堂次未自動同步：${sync._auto_rebuild_failed}。請稍後再開啟編輯並按儲存重試；若仍失敗請洽技術支援。`;
          }
          if (sync?.rebuilt) {
            successMsg += ` 已依新開課日重排 ${Number(sync.created_sessions || 0)} 堂。`;
            if (sync?.reason === 'start_date_aligned') {
              successMsg += '（堂次首日已與開課日重新對齊）';
            }
          } else if (sync?.reason === 'partial_rebuild') {
            // 有歷史記錄但開課日改變：已鎖定堂次保留，未來未鎖定堂次重排
            const updated = Number(sync.updated_future_sessions || 0);
            if (updated > 0) {
              successMsg += ` 已鎖定已點名／已核准堂次，並將 ${updated} 筆未來未上堂次依新開課日重新排程。`;
            } else {
              successMsg += ' 已鎖定已點名／已核准堂次；未來堂次日期無需調整。';
            }
          } else if (sync?.reason === 'history_exists' && !(scheduleChanged && scheduleAutoRebuildOk)) {
            // 開課日無變動但有歷史記錄阻擋（或 slots 無法解析）
            if (sync?.reconcile_skipped) {
              successMsg += ' 課程時段已更新，但部分未來堂次因狀態鎖定未同步時間，請至堂次列表確認。';
            } else {
              successMsg += ' 本課已有出缺勤/核准紀錄，為保留歷史資料未重排堂次。';
            }
          } else if (sync?.reason === 'start_date_unchanged' && !(scheduleChanged && scheduleAutoRebuildOk)) {
            successMsg += ' 開課日未變更，故未重排堂次。';
          } else if (sync?.reason === 'start_date_not_updated') {
            successMsg += ' 本次未更新開課日，故未重排堂次。';
          }
          showEditModal.value = false;
          await loadCourses();
          toastRef.value?.show?.({ title: '已儲存', description: successMsg, variant: 'success', durationMs: 4000 });
          return;
        }
        const err = await res.json().catch(() => ({}));
        toastRef.value?.show?.({ title: '儲存失敗', description: err?.message || '更新失敗', variant: 'error', durationMs: 5000 });
        return;
      }
    } catch (e) {
      alert('連線失敗：' + (e?.message || '請稍後再試'));
      return;
    }
  }
  const endTime = computeEndTime(form.start_time, form.duration_hours);
  const { error } = await supabase.from('student-classes').update({
    subject: form.subject, teacher_id: form.teacher_id || null, class_type: form.class_type,
    rate_per_30min: form.rate_per_30min, remaining_sessions: form.remaining_sessions,
    days_of_week: form.days_of_week, start_time: form.start_time, end_time: endTime,
    payment_type: form.payment_type,
    first_class_date: form.first_class_date || null
  }).eq('id', id);
  if (error) {
    alert('更新失敗：' + (error?.message || '請稍後再試'));
    return;
  }
  editScheduleBaseline.value = null;
  showEditModal.value = false;
  await loadCourses();
  alert('課程已更新。');
};

const confirmDeleteTarget = ref(null);
const paymentEntryOpen = ref(false);
const paymentEntryRow = ref(null);
const executeDeleteCourse = async () => {
  const c = confirmDeleteTarget.value;
  if (!c) return;
  confirmDeleteTarget.value = null;
  const fromLaravel = c.data_source === 'laravel' || c.branch_name != null || c.room_name != null || c.settlement_day != null;
  if (fromLaravel) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const res = await fetch(`/api/v1/student-classes/${c.id}`, {
          method: 'DELETE',
          credentials: 'include',
          headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
          courses.value = courses.value.filter(x => x.id !== c.id);
          toastRef.value?.show?.({ title: '已刪除', description: `${c.subject_name || c.subject || ''} 課程已刪除`, variant: 'success', durationMs: 3000 });
          return;
        }
        const err = await res.json().catch(() => ({}));
        toastRef.value?.show?.({ title: '刪除失敗', description: err?.message || '刪除失敗', variant: 'error', durationMs: 5000 });
        return;
      }
    } catch (e) {
      toastRef.value?.show?.({ title: '刪除失敗', description: e?.message || '請稍後再試', variant: 'error', durationMs: 5000 });
      return;
    }
  }
  await supabase.from('student-classes').delete().eq('id', c.id);
  courses.value = courses.value.filter(x => x.id !== c.id);
};

const submitBackfill = async () => {
  if (!backfillForm.value.student_id) { alert('請選擇學生'); return; }
  if (!backfillForm.value.teacher_id) { alert('請選擇老師'); return; }

  const form = backfillForm.value;
  const isSessionPayment = (form.payment_type || 'session') === 'session';
  const selectedLegacyDates = [...new Set((backfillSelectedDates.value || []).filter(Boolean))].sort();
  const derivedFirstClassDate = selectedLegacyDates[0] || null;
  const sessionsPurchased = Math.max(0, Number(form.sessions_purchased) || 0);
  const sessionsUsed = selectedLegacyDates.length;
  if (isSessionPayment && sessionsUsed === 0) {
    alert('請先在月曆勾選至少 1 個已上課日期');
    return;
  }
  if (isSessionPayment && sessionsPurchased <= 0) {
    alert('購買堂數需大於 0');
    return;
  }
  if (isSessionPayment && sessionsUsed > sessionsPurchased) {
    alert('已選上課日期不可超過購買堂數');
    return;
  }

  const endTime = computeEndTime(form.start_time, form.duration_hours);
  const remaining = Math.max(0, sessionsPurchased - sessionsUsed);
  const daysOfWeek = (form.days_of_week || []).length ? form.days_of_week : [];

  // 1. Create course record: try Laravel API first, then Supabase
  let created = false;
  let createdCourseId = null;
  let usedApi = false;
  let token = null;
  let directorId = null;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    token = sess?.access_token;
    directorId = sess?.user?.id || null;
    if (token && props.branchId) {
      usedApi = true;
      const body = {
        student_id: form.student_id,
        subject: form.subject,
        teacher_id: form.teacher_id || null,
        class_type: form.class_type,
        rate_per_30min: form.rate_per_30min,
        duration_hours: form.duration_hours,
        payment_type: form.payment_type || 'session',
        sessions_purchased: sessionsPurchased,
        remaining_sessions: isSessionPayment ? sessionsPurchased : remaining,
        settlement_day: form.payment_type === 'monthly' ? form.settlement_day : null,
        monthly_sessions: form.payment_type === 'monthly' ? form.monthly_sessions : null,
        first_class_date: derivedFirstClassDate,
        days_of_week: daysOfWeek,
        start_time: form.start_time,
        end_time: endTime,
        room_id: form.room_id || null,
        Memo: form.memo || null,
        skip_auto_sessions: isSessionPayment,
      };
      const res = await fetch('/api/v1/student-classes', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(body)
      });
      if (res.ok) {
        const createdBody = await res.json().catch(() => ({}));
        const createdEntity = createdBody?.data ?? createdBody?.course ?? createdBody ?? {};
        createdCourseId = createdEntity?.ID ?? createdEntity?.id ?? createdBody?.ID ?? createdBody?.id ?? null;
        created = true;
      } else {
        const err = await res.json().catch(() => ({}));
        alert('補登失敗: ' + (err?.message || '請稍後再試'));
        return;
      }
    }
  } catch (e) {
    alert('補登失敗：' + (e?.message || '請稍後再試'));
    return;
  }

  if (!created) {
    const insertPayload = {
      student_id: form.student_id,
      branch_id: props.branchId,
      subject: form.subject,
      teacher_id: form.teacher_id || null,
      class_type: form.class_type,
      rate_per_30min: form.rate_per_30min,
      duration_hours: form.duration_hours,
      payment_type: form.payment_type || 'session',
      sessions_purchased: sessionsPurchased,
      remaining_sessions: remaining,
      start_time: form.start_time,
      end_time: endTime,
      days_of_week: daysOfWeek.length ? daysOfWeek : undefined,
      day_of_week: daysOfWeek.length ? daysOfWeek[0] : 0
    };
    if (derivedFirstClassDate) insertPayload.first_class_date = derivedFirstClassDate;
    if (form.room_id != null) insertPayload.room_id = form.room_id;
    if (form.memo) insertPayload.memo = form.memo;
    const { error } = await supabase.from('student-classes').insert([insertPayload]);
    if (error) {
      alert('補登失敗: ' + error.message);
      return;
    }
  }

  // 2. 若走 Laravel API，將勾選的歷史日期直接補登為已核准並扣堂
  if (usedApi && isSessionPayment && selectedLegacyDates.length > 0 && (!createdCourseId || !token)) {
    alert('課程已建立，但無法取得課程 ID，請重新整理後確認剩餘堂數。');
    return;
  }

  if (usedApi && isSessionPayment && selectedLegacyDates.length > 0 && createdCourseId && token) {
    if (!directorId) {
      const meRes = await fetch('/api/v1/me', {
        credentials: 'include',
        headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      }).catch(() => null);
      if (meRes?.ok) {
        const me = await meRes.json().catch(() => ({}));
        directorId = me?.id ?? null;
      }
    }
    if (!directorId) {
      alert('課程已建立，但無法取得操作者身分，請重新登入後再補登上課日期。');
      return;
    }
    const bulkRes = await fetch('/api/v1/learning-records/bulk-backdoor-approve', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        StudentClassID: createdCourseId,
        TeacherID: Number(form.teacher_id),
        DirectorID: Number(directorId),
        session_dates: selectedLegacyDates,
      })
    });
    if (!bulkRes.ok) {
      const bulkErr = await bulkRes.json().catch(() => ({}));
      alert('課程已建立，但補登上課日期失敗：' + (bulkErr?.message || '請到學習評量頁補登'));
      return;
    }

    // 以使用者勾選日期為最終依據，確保剩餘堂數與補登堂數一致。
    const syncRemainingRes = await fetch(`/api/v1/student-classes/${createdCourseId}`, {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ remaining_sessions: remaining })
    }).catch(() => null);
    if (!syncRemainingRes?.ok) {
      alert('課程與補登日期已建立，但剩餘堂數同步失敗，請重新整理後檢查。');
      return;
    }
  }

  // 3. Update student remaining lessons
  await supabase.from('students')
    .update({ remaining_lessons: remaining })
    .eq('id', form.student_id);

  alert('補登成功！');
  showBackfillModal.value = false;
  resetBackfillDatePicker();
  backfillForm.value = {
    student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
    rate_per_30min: 500, duration_hours: 2,
    payment_type: 'session', sessions_purchased: 8, sessions_used: 0,
    settlement_day: null, monthly_sessions: null,
    days_of_week: [],
    start_time: '16:00', end_time: '18:00',
    room_id: null, memo: ''
  };
  loadCourses();
};

watch(() => props.branchId, () => { loadCourses(); loadStudents(); loadTeachers(); });
watch(
  () => [props.initialTeacherId, teachers.value],
  () => {
    const id = props.initialTeacherId;
    if (id == null || id === '') return;
    filters.value.teacher_id = String(id);
    const t = (teachers.value || []).find((x) => String(x.id) === String(id));
    if (t) {
      const label = t.username || t.name || t.Name || '';
      if (label) filters.value.teacher_name = label;
    }
    loadCourses(1);
    emit('clear-initial-teacher');
  },
  { immediate: true },
);
onMounted(() => {
  loadCourses(); loadStudents(); loadTeachers(); loadSubjects();
  document.addEventListener('click', closeActionMenu);
});
onUnmounted(() => {
  document.removeEventListener('click', closeActionMenu);
});
</script>

<style scoped>
.course-page {
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
  padding: 0 12px 24px;
  box-sizing: border-box;
}

/* ----- Page header ----- */
.course-header-card {
  padding: 18px;
  border-radius: 16px;
  border: 1px solid rgba(99, 102, 241, 0.18);
  background:
    radial-gradient(140% 120% at 0% 0%, rgba(99, 102, 241, 0.12) 0%, rgba(255, 255, 255, 0) 48%),
    var(--card-bg);
  box-shadow: 0 12px 30px rgba(15, 23, 42, 0.07);
}

.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}

.page-title-block {
  min-width: 0;
}

.page-title {
  font-size: 1.35rem;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 4px;
  letter-spacing: 0.02em;
}

.ref-hint {
  color: var(--text-light);
  font-size: 13px;
  margin-top: 3px;
}

.meta-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

.meta-pill {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  color: #334155;
  background: rgba(255, 255, 255, 0.7);
  border: 1px solid rgba(148, 163, 184, 0.35);
}

.header-buttons {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.btn-soft {
  border: 1px solid rgba(100, 116, 139, 0.28);
  background: rgba(255, 255, 255, 0.86);
  color: #334155;
  border-radius: 10px;
  padding: 9px 14px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: var(--transition);
}

.btn-soft:hover {
  border-color: rgba(79, 70, 229, 0.35);
  color: #3730a3;
  transform: translateY(-1px);
}

.btn-accent {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: linear-gradient(135deg, var(--accent), var(--primary));
  color: #fff;
  border: none;
  padding: 10px 18px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 14px;
  cursor: pointer;
  transition: var(--transition);
  box-shadow: 0 2px 8px rgba(230, 81, 0, 0.25);
}

.btn-accent:hover {
  opacity: 0.95;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(230, 81, 0, 0.35);
}

.btn-icon {
  font-size: 1em;
}

/* ----- Filters ----- */
.filter-bar {
  margin-top: 16px;
  padding: 14px;
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.82);
}

.filter-bar.grid {
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
}

.filter-field label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light);
  margin-bottom: 6px;
}

.filter-field input,
.filter-field select {
  padding: 9px 12px;
  border-radius: 10px;
  font-size: 14px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: #fff;
}

/* ----- Compact stats strip ----- */
.creation-success-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  margin-bottom: 12px;
  background: #d1fae5;
  border: 1px solid #6ee7b7;
  border-radius: 8px;
  color: #065f46;
  font-size: 0.95rem;
  font-weight: 500;
}
.creation-success-banner__icon {
  font-size: 1.2rem;
  color: #059669;
  flex-shrink: 0;
}
.creation-success-banner__close {
  margin-left: auto;
  background: none;
  border: none;
  cursor: pointer;
  color: #065f46;
  font-size: 1rem;
  opacity: 0.7;
  padding: 0 4px;
}
.creation-success-banner__close:hover { opacity: 1; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.stats-strip {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-top: 12px;
  padding: 8px 12px;
  border-radius: 10px;
  background: rgba(248, 250, 252, 0.85);
  border: 1px solid rgba(148, 163, 184, 0.18);
  font-size: 12.5px;
  color: var(--text-light);
}

.stats-strip-item {
  color: #475569;
}

.stats-strip-item strong {
  font-weight: 700;
  color: var(--text);
}

.stats-strip-total {
  font-weight: 600;
  color: var(--primary);
}

.stats-strip-total .stats-strip-num {
  font-size: 14px;
  font-weight: 800;
}

.stats-strip-sep {
  color: rgba(148, 163, 184, 0.6);
  font-size: 11px;
}

.stats-strip-pipe {
  margin: 0 2px;
  color: rgba(148, 163, 184, 0.5);
  font-size: 13px;
}

.stats-strip-subject {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--primary-bg);
  color: var(--primary);
  font-size: 11.5px;
  font-weight: 600;
}

/* ----- Table ----- */
.table-card {
  padding: 0;
  overflow: visible;
  border-radius: 16px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
}

.table-wrap {
  overflow-x: auto;
  max-height: 70vh;
  overflow-y: auto;
}

.grouped-course-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.grouped-course-list.focus-fullscreen-mode {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: #fff;
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
  padding: 16px;
  gap: 12px;
}
.grouped-course-list.focus-fullscreen-mode .group-table-wrap,
.grouped-course-list.focus-fullscreen-mode .table-wrap {
  max-height: none;
  overflow-y: visible;
}
.pagination-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-top: 1px solid rgba(148, 163, 184, 0.15);
  font-size: 0.9rem;
  color: #64748b;
}
.pagination-controls {
  display: flex;
  align-items: center;
  gap: 8px;
}
.pagination-btn { min-width: 80px; }
.pagination-btn:disabled { opacity: 0.4; cursor: default; }
.pagination-current { font-weight: 600; color: #334155; }

.student-group-card {
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 14px;
  overflow: visible;
  background: var(--card-bg);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
}

.student-group-header {
  width: 100%;
  border: none;
  background: linear-gradient(180deg, #eef2ff 0%, #fff 92%);
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  cursor: pointer;
}

.student-group-header:hover {
  background: var(--primary-bg);
}

.student-group-left {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.expand-indicator {
  color: var(--text-light);
  font-size: 12px;
  width: 12px;
  text-align: center;
}

.student-group-meta {
  font-size: 13px;
  color: var(--text-light);
  font-weight: 600;
  white-space: nowrap;
}

.focus-btn {
  margin-left: auto;
  padding: 2px 8px;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: #f8fafc;
  color: #64748b;
  font-size: 13px;
  cursor: pointer;
  flex-shrink: 0;
}
.focus-btn:hover, .focus-btn.active {
  background: #dbeafe;
  border-color: #93c5fd;
  color: #1d4ed8;
}
.focus-mode-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 14px;
  margin-bottom: 8px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  font-size: 13px;
  color: #1e40af;
}
.focus-mode-banner button {
  font-size: 12px;
  padding: 3px 10px;
  border: 1px solid #93c5fd;
  border-radius: 999px;
  background: #fff;
  color: #1d4ed8;
  cursor: pointer;
}
.focus-mode-banner button:hover { background: #dbeafe; }
.student-group-has-paused {
  box-shadow: inset 0 0 0 1px rgba(217, 119, 6, 0.35);
  border-radius: 10px;
}

.student-group-paused-badge {
  margin-left: 8px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #9a3412;
  background: linear-gradient(180deg, #fff7ed 0%, #ffedd5 100%);
  border: 1px solid #fdba74;
  border-radius: 999px;
  padding: 2px 10px;
  vertical-align: middle;
}

.student-group-add-row {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding: 8px 12px 6px;
  background: rgba(248, 250, 252, 0.9);
  border-bottom: 1px solid rgba(148, 163, 184, 0.2);
}

.student-group-add-btn {
  font-size: 12.5px;
  font-weight: 600;
}

.group-table-wrap {
  border-top: 1px solid var(--border);
  max-height: 56vh;
}

.course-table {
  width: 100%;
  min-width: 540px;
  border-collapse: collapse;
  font-size: 13.5px;
}

.course-table thead {
  position: sticky;
  top: 0;
  z-index: 2;
  background: #f8fafc;
  border-bottom: 1px solid rgba(99, 102, 241, 0.25);
}
@media (min-width: 641px) {
  .course-table thead {
    background: rgba(248, 250, 252, 0.95);
    backdrop-filter: blur(6px);
  }
}

.course-table th {
  padding: 12px 10px;
  text-align: left;
  font-weight: 700;
  color: #334155;
  white-space: nowrap;
}

.course-table td {
  padding: 9px 10px;
  border-bottom: 1px solid rgba(226, 232, 240, 0.85);
  vertical-align: middle;
  word-break: keep-all;
  line-height: 1.4;
}

.course-row:hover {
  background: #f8fafc;
}

.course-row.course-paused:hover td {
  background: linear-gradient(180deg, rgba(255, 247, 237, 0.98) 0%, rgba(245, 245, 244, 0.92) 100%);
}

.td-subject {
  min-width: 140px;
}

.subject-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}

.price-line {
  margin-top: 4px;
  font-size: 13px;
  color: #475569;
  font-weight: 600;
}

.memo-line {
  margin-top: 4px;
  font-size: 13px;
  color: #64748b;
  line-height: 1.4;
  word-break: break-word;
}

.price-sep {
  margin: 0 6px;
  color: #94a3b8;
}

.settled-course-callout {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 5px;
  padding: 5px 10px;
  margin-bottom: 6px;
  border-radius: 7px;
  background: #f0fdf4;
  border: 1px solid #86efac;
  box-shadow: 0 1px 2px rgba(22, 101, 52, 0.08);
}
.settled-course-callout__icon { font-size: 13px; line-height: 1; }
.settled-course-callout__main {
  font-size: 13px;
  font-weight: 800;
  color: #14532d;
  letter-spacing: 0.03em;
}
.settled-course-callout__sub {
  font-size: 11px;
  font-weight: 600;
  color: #166534;
}
.tag-settled {
  background: #dcfce7;
  color: #14532d;
  border: 1px solid #86efac;
  display: inline-block;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 600;
}

.paused-course-callout {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
  padding: 7px 10px;
  border-radius: 999px;
  background: #fffbeb;
  border: 1px solid #fde68a;
}

.paused-course-callout__icon {
  font-size: 14px;
  line-height: 1;
  opacity: 0.9;
}

.paused-course-callout__main {
  font-size: 12px;
  font-weight: 800;
  color: #7c2d12;
}

.paused-course-callout__sub {
  font-size: 11px;
  font-weight: 600;
  color: #b45309;
  flex: 1;
}

.paused-course-callout__action {
  border: 0;
  border-radius: 999px;
  background: #2563eb;
  color: #fff;
  padding: 4px 10px;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
}

.subject-tag--paused {
  background: #e7e5e4 !important;
  color: #57534e !important;
  border: 1px solid #d6d3d1 !important;
}

.dates-row-paused .dates-panel {
  margin-top: 2px;
  padding-left: 12px;
  border-left: 3px solid #f59e0b;
  background: #fffbeb;
  border-radius: 0 8px 8px 0;
}

.cell-student {
  font-size: 16px;
  font-weight: 600;
  color: var(--text);
}

.subject-tag {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 600;
  background: var(--primary-bg);
  color: var(--primary);
}

.cell-fee {
  font-weight: 600;
  color: var(--text);
}

.cell-total {
  font-weight: 700;
  color: var(--primary);
}

.cell-schedule {
  font-size: 13px;
  color: var(--text);
  word-break: keep-all;
  min-width: 100px;
}

.schedule-slot-lines {
  display: flex;
  flex-direction: column;
  gap: 4px;
  align-items: flex-start;
}

.schedule-slot-line {
  line-height: 1.35;
}
.schedule-drift-badge {
  display: inline-block;
  margin-top: 3px;
  font-size: 11px;
  font-weight: 600;
  color: #b45309;
  background: #fef3c7;
  border: 1px solid #fcd34d;
  border-radius: 4px;
  padding: 1px 6px;
}
.contract-exception-badge {
  display: inline-block;
  margin-top: 3px;
  font-size: 11px;
  font-weight: 600;
  color: #1d4ed8;
  background: #dbeafe;
  border: 1px solid #93c5fd;
  border-radius: 4px;
  padding: 1px 6px;
}

.cell-remaining {
  font-weight: 700;
}

.cell-remaining.low {
  color: var(--danger);
}

.col-actions,
.cell-actions {
  white-space: nowrap;
  min-width: 128px;
}

.action-btns-row {
  display: flex;
  gap: 6px;
  align-items: center;
}

.action-menu-wrapper {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}

.action-menu-trigger {
  font-size: 13px !important;
  font-weight: 700 !important;
  letter-spacing: 0;
  padding: 6px 12px !important;
  border-radius: 8px;
  border: 1px solid rgba(148, 163, 184, 0.26) !important;
  background: #fff;
  cursor: pointer;
  line-height: 1.2;
}

.action-dropdown {
  position: static;
  margin-top: 6px;
  min-width: 170px;
  max-height: 260px;
  overflow-y: auto;
  background: #fff;
  border: 1px solid rgba(148, 163, 184, 0.3);
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
  z-index: 1;
  padding: 4px 0;
  animation: dropdown-fade 0.12s ease;
}

@keyframes dropdown-fade {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

.action-dropdown-item {
  display: block;
  width: 100%;
  padding: 10px 16px;
  border: none;
  background: none;
  text-align: left;
  font-size: 14px;
  color: #334155;
  cursor: pointer;
  white-space: nowrap;
}

.action-dropdown-item:hover {
  background: #f1f5f9;
}

.action-dropdown-resume {
  color: #2563eb;
  font-weight: 600;
}

.action-dropdown-close {
  color: #92400e;
  font-weight: 500;
}
.action-dropdown-close:hover {
  background: #fef3c7;
}

.action-dropdown-danger {
  color: #dc2626;
}

.action-dropdown-danger:hover {
  background: #fef2f2;
}

.action-section-label {
  margin: 0;
  padding: 6px 14px 4px;
  font-size: 0.7em;
  font-weight: 600;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.action-section-label--danger {
  color: #f87171;
}
.action-icon {
  display: inline-block;
  width: 18px;
  text-align: center;
  margin-right: 4px;
  font-size: 13px;
}

button.danger {
  background: #dc2626;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 8px 18px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
}

button.danger:hover:not(:disabled) {
  background: #b91c1c;
}

button.danger:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.action-dropdown-divider {
  margin: 4px 0;
  border: none;
  border-top: 1px solid #e2e8f0;
}

.btn-status {
  cursor: pointer;
}

.btn-toggle {
  white-space: nowrap;
  border-radius: 999px;
  border: 1px solid rgba(148, 163, 184, 0.28);
  background: #fff;
  color: #334155;
}

.btn-add-session {
  white-space: nowrap;
  border-radius: 999px;
  border: 1px solid #93c5fd;
  background: #eff6ff;
  color: #1d4ed8;
  font-weight: 600;
  font-size: 13px;
  padding: 5px 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.btn-add-session:hover:not(:disabled) {
  background: #dbeafe;
  border-color: #60a5fa;
  transform: translateY(-1px);
  box-shadow: 0 2px 6px rgba(37, 99, 235, 0.12);
}
.btn-add-session:disabled,
.btn-add-session.disabled {
  opacity: 0.45;
  cursor: not-allowed;
  background: #f1f5f9;
  border-color: #cbd5e1;
  color: #94a3b8;
  transform: none;
  box-shadow: none;
}

.action-dropdown-add-session-mobile {
  display: none;
  color: #1d4ed8;
  font-weight: 600;
}

.action-dropdown-item--disabled {
  opacity: 0.45;
  cursor: not-allowed;
  color: #94a3b8 !important;
}

@media (max-width: 640px) {
  .btn-add-session {
    display: none;
  }
  .action-dropdown-add-session-mobile {
    display: block;
  }
}

/* ----- Empty state ----- */
.empty-state {
  padding: 48px 24px;
  text-align: center;
}

.empty-icon {
  font-size: 3rem;
  margin-bottom: 12px;
  opacity: 0.6;
}

.empty-title {
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 8px;
}

.empty-desc {
  font-size: 14px;
  color: var(--text-light);
  max-width: 360px;
  margin: 0 auto;
  line-height: 1.6;
}

/* ----- Subject Modal ----- */
.subject-modal {
  width: 100%;
  max-width: 420px;
}
.subject-modal .modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.subject-modal .modal-header h3 { margin: 0; font-size: 1.1rem; }
.subject-modal .modal-body { display: flex; flex-direction: column; gap: 12px; }
.subject-add-row {
  display: flex;
  gap: 8px;
}
.subject-add-row .input-field {
  flex: 1;
  padding: 6px 10px;
  border: 1px solid var(--border);
  border-radius: 6px;
  font-size: 14px;
}
.subject-list {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 300px;
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: 6px;
}
.subject-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  border-bottom: 1px solid var(--border);
  font-size: 14px;
}
.subject-item:last-child { border-bottom: none; }
.btn-danger-soft {
  background: none;
  border: none;
  color: #e53935;
  cursor: pointer;
  font-size: 13px;
  padding: 2px 6px;
  border-radius: 4px;
}
.btn-danger-soft:hover { background: #fdecea; }
.btn-close {
  background: none;
  border: none;
  font-size: 16px;
  cursor: pointer;
  color: var(--text-light);
  padding: 2px 6px;
}
.error-text { color: #e53935; font-size: 13px; margin: 0; }

/* ----- Modals ----- */
.course-modal {
  width: 100%;
  max-width: 560px;
  max-height: 90vh;
  overflow-y: auto;
}

.modal-title {
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 8px;
}

.modal-desc {
  color: var(--text-light);
  font-size: 13px;
  margin-bottom: 20px;
  line-height: 1.6;
}

.form-section {
  margin-bottom: 20px;
}

.form-section-title {
  font-size: 12px;
  font-weight: 700;
  color: var(--text-light);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: 12px;
  padding-bottom: 6px;
  border-bottom: 1px solid var(--border);
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 16px;
}

.form-textarea {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  resize: vertical;
  font-size: 14px;
  font-family: inherit;
}

.text-red { color: var(--danger) !important; }
.text-secondary { color: var(--text-light); font-size: 0.9rem; }
.computed-end-time { margin: 0; font-weight: 600; font-size: 1rem; }

.small.danger {
  background: #fef2f2;
  color: #b91c1c;
  border: 1px solid #fecaca;
}
.small.danger:hover { background: #fee2e2; color: #991b1b; }

.status-tag.one_on_one { background: #FFF3E0; color: #E65100; }
.status-tag.one_on_two { background: #FFF8E1; color: #F57F17; }
.status-tag.one_on_three { background: #FBE9E7; color: #BF360C; }
.status-tag.tutoring { background: #E8F5E9; color: #2E7D32; }
.status-tag.trial { background: #E8EAF6; color: #3949AB; }

.legacy-box {
  background: #FFF8E1;
  border: 1px solid #FFE082;
  border-radius: 10px;
  padding: 16px;
  margin-top: 16px;
}

.legacy-box h4 {
  font-size: 13px;
  font-weight: 700;
  margin-bottom: 12px;
  color: #5D4037;
}
.backfill-import-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.legacy-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 16px;
}

.remaining-display {
  font-size: 28px;
  font-weight: 800;
  color: var(--primary);
  padding: 6px 0;
  text-align: center;
}

.preview-dates-box {
  margin-top: 14px;
  padding: 12px;
  background: #E8F5E9;
  border: 1px solid #A5D6A7;
  border-radius: 8px;
}
.preview-dates-box h4 { font-size: 13px; margin-bottom: 6px; color: #2E7D32; }
.backfill-calendar-box {
  margin-top: 12px;
  border: 1px solid #ffd180;
  background: #fffdf7;
  border-radius: 10px;
  padding: 10px;
}
.backfill-calendar-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.backfill-calendar-weekdays,
.backfill-calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
}
.backfill-calendar-weekdays {
  font-size: 12px;
  color: var(--text-light);
  text-align: center;
  margin-bottom: 6px;
}
.calendar-day {
  border: 1px solid #ffe0b2;
  border-radius: 8px;
  background: #fff;
  min-height: 34px;
  font-size: 12px;
  color: var(--text);
  cursor: pointer;
}
.calendar-day.muted {
  opacity: 0.55;
}
.calendar-day.selected {
  background: #e8f5e9;
  border-color: #81c784;
  color: #2e7d32;
  font-weight: 700;
}
.calendar-day.disabled {
  background: #f5f5f5;
  color: #9e9e9e;
  border-color: #eeeeee;
  cursor: not-allowed;
}
.backfill-calendar-actions {
  margin-top: 8px;
  display: flex;
  justify-content: flex-end;
}

@media (max-width: 640px) {
  .course-page {
    padding: 0 8px 16px;
  }

  .header-actions {
    flex-direction: column;
    align-items: stretch;
  }

  .header-buttons {
    justify-content: flex-start;
  }

  .summary-row {
    grid-template-columns: repeat(2, 1fr);
  }
  .form-grid {
    grid-template-columns: 1fr;
  }
  .legacy-grid {
    grid-template-columns: 1fr;
  }
  .col-actions,
  .cell-actions {
    min-width: 90px;
  }
  .detail-meta {
    gap: 4px 12px;
  }
}
.preview-dates-box .hint-small { font-size: 12px; margin-top: 6px; color: #558B2F; }
.preview-dates-list {
  font-size: 13px;
  color: var(--text);
  word-break: break-all;
  margin-top: 6px;
}

.day-chips {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  padding: 6px 0;
}
.day-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 2px solid var(--border);
  cursor: pointer;
  font-weight: 700;
  font-size: 14px;
  color: var(--text-light);
  transition: var(--transition);
  user-select: none;
}
.day-chip.selected {
  background: var(--primary);
  color: #fff;
  border-color: var(--primary);
}

.detail-panel {
  padding: 14px 16px;
  background: #f8fbff;
  border-top: 1px solid rgba(148, 163, 184, 0.24);
}

.detail-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 20px;
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid rgba(148, 163, 184, 0.18);
  font-size: 13px;
  color: var(--text);
}

.detail-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.detail-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--text-light);
}

.dates-row td { padding: 0; }
.dates-panel {
  background: #f8fbff;
  border-top: 1px solid rgba(148, 163, 184, 0.24);
  padding: 12px 16px 14px;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 10px;
  font-size: 13px;
}
.dates-panel-heading {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px 12px;
}
.dates-panel-title {
  font-size: 13px;
  font-weight: 700;
  color: #334155;
  line-height: 1.4;
}
.drift-hint {
  display: inline-block;
  margin-left: 8px;
  font-size: 12px;
  color: #b45309;
  background: #fef3c7;
  border-radius: 4px;
  padding: 1px 6px;
  font-weight: 500;
}
.drift-hint.drift-hint-info {
  color: #1e40af;
  background: #dbeafe;
}
.dates-chip-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 8px;
}
.date-chip {
  background: #fff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  padding: 5px 10px;
  font-size: 12px;
  color: #1d4ed8;
  white-space: nowrap;
  cursor: help;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  flex-shrink: 0;
}
.date-chip-clickable {
  cursor: pointer;
}
/* Synthetic chips (placeholder rows rendered from schedule before ClassSession loads).
 * Grayed out to communicate "not interactive yet"; paired with a title tooltip
 * that guides the user to refresh. See PRD 薪資計算與調課按鈕修正 §5b + FR-004/005. */
.date-chip.date-chip-synthetic {
  opacity: 0.45;
  cursor: default;
}
.chip-seq {
  font-weight: 700;
  color: #0f172a;
  font-size: 11px;
  background: #dbeafe;
  border-radius: 999px;
  padding: 1px 6px;
}
.chip-date {
  color: #1d4ed8;
}
.chip-state {
  font-size: 11px;
  color: #92400e;
  background: #fef3c7;
  border-radius: 999px;
  padding: 1px 5px;
}
.chip-note-text {
  display: block;
  font-size: 11px;
  color: #6366f1;
  margin-top: 3px;
  font-style: italic;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 160px;
}
.notes-toggle-btn {
  font-size: 11px;
  padding: 2px 8px;
  border: 1px solid #c7d2fe;
  border-radius: 999px;
  background: #eef2ff;
  color: #4f46e5;
  cursor: pointer;
  white-space: nowrap;
}
.notes-toggle-btn:hover { background: #e0e7ff; }
.date-chip:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 14px rgba(15, 23, 42, 0.08);
}
/* Synthetic chips stay flat on hover — they are not interactive. */
.date-chip.date-chip-synthetic:hover {
  transform: none;
  box-shadow: none;
}
/* Session Edit Modal */
.session-edit-modal .session-edit-info {
  background: #f8fafc; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; flex-direction: column; gap: 6px;
}
.session-edit-modal .se-row { display: flex; align-items: center; gap: 8px; font-size: 0.93em; }
.session-edit-modal .se-label { font-weight: 600; color: #475569; min-width: 70px; }
.se-status-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.85em; font-weight: 600; }
.se-st-scheduled { background: #e0f2fe; color: #0369a1; }
.se-st-attended, .se-st-late { background: #dcfce7; color: #166534; }
.se-st-absent { background: #fee2e2; color: #991b1b; }
.se-st-excused, .se-st-leave { background: #fef3c7; color: #92400e; }
.se-st-leave_adjusted { background: #ffedd5; color: #9a3412; }
.se-st-cancelled { background: #f3f4f6; color: #6b7280; }
.se-section-title { font-size: 0.95em; font-weight: 600; color: #334155; margin: 0 0 10px; }
.se-action-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
.se-action-grid-compact { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.se-secondary-action { margin-top: 10px; }
.se-secondary-action label { display: block; font-size: 0.85em; color: #64748b; margin-bottom: 6px; }
.se-secondary-row { display: flex; gap: 8px; align-items: center; }
.se-secondary-row select { flex: 1; }
.se-action-btn {
  padding: 8px 12px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff;
  font-size: 0.88em; font-weight: 500; cursor: pointer; text-align: center;
  transition: all 0.15s ease;
}
.se-action-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.se-btn-attended { border-color: #86efac; color: #166534; } .se-btn-attended:hover { background: #dcfce7; }
.se-btn-late { border-color: #fbbf24; color: #92400e; } .se-btn-late:hover { background: #fef3c7; }
.se-btn-absent { border-color: #fca5a5; color: #991b1b; } .se-btn-absent:hover { background: #fee2e2; }
.se-btn-excused { border-color: #fde68a; color: #78350f; } .se-btn-excused:hover { background: #fef9c3; }
.se-btn-leave { border-color: #fcd34d; color: #b45309; } .se-btn-leave:hover { background: #fef3c7; }
.se-btn-retro-leave { border-color: #fb923c; color: #9a3412; } .se-btn-retro-leave:hover { background: #ffedd5; }
.se-btn-scheduled { border-color: #93c5fd; color: #1d4ed8; } .se-btn-scheduled:hover { background: #dbeafe; }
.se-btn-cancelled { border-color: #d1d5db; color: #6b7280; } .se-btn-cancelled:hover { background: #f3f4f6; }
.se-btn-reschedule { border-color: #a78bfa; color: #6d28d9; } .se-btn-reschedule:hover { background: #ede9fe; }
.se-loading { text-align: center; color: #64748b; padding: 8px 0; font-size: 0.9em; }
.session-edit-reschedule .se-reschedule-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.session-edit-reschedule .se-reschedule-grid .form-group:last-child { grid-column: 1 / -1; }
.date-chip.completed {
  background: #e8f5e9;
  border-color: #a5d6a7;
  color: #2e7d32;
}
.date-chip.completed .chip-date {
  color: #1b5e20;
}
.date-chip.leave {
  background: #fff1e0;
  border-color: #fb923c;
  color: #9a3412;
  font-weight: 600;
}
.date-chip.absent {
  background: #ffebee;
  border-color: #ef9a9a;
  color: #c62828;
}
.date-chip.cancelled {
  background: #f3f4f6;
  border-color: #d1d5db;
  color: #6b7280;
}
.course-paused td {
  background: #fffdf7;
  box-shadow: inset 3px 0 0 #f59e0b;
  color: #44403c;
}

.course-paused .cell-remaining.low {
  color: #78716c;
}

.course-paused .action-btns-compact .small.ghost,
.course-paused .action-btns-compact .small.danger {
  opacity: 0.72;
}

.course-paused .action-btns-compact .small.primary {
  opacity: 1;
  box-shadow: 0 0 0 1px rgba(217, 119, 6, 0.35);
}

.tag-paused {
  background: #ffedd5;
  color: #7c2d12;
  border: 1px solid #ea580c;
  border-radius: 6px;
  font-size: 12px;
  padding: 3px 8px;
  font-weight: 800;
  letter-spacing: 0.02em;
}

.pause-confirm-modal {
  max-width: 480px;
}

.pause-confirm-header {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin-bottom: 14px;
}

.pause-confirm-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 14px;
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
  font-weight: 800;
  flex: 0 0 auto;
}

.pause-confirm-icon.resume {
  background: #eff6ff;
  color: #1d4ed8;
  border-color: #bfdbfe;
}

.pause-impact-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 12px 14px;
  margin: 12px 0 18px;
}

.pause-impact-title {
  margin: 0 0 8px;
  color: #334155;
  font-weight: 800;
  font-size: 13px;
}

.pause-impact-card ul {
  margin: 0;
  padding-left: 18px;
  color: #475569;
  line-height: 1.7;
  font-size: 13px;
}

.btn-resume-primary {
  background: #2563eb;
}

.tag-package {
  background: #ede9fe;
  color: #6d28d9;
  border: 1px solid #c4b5fd;
  font-size: 0.65rem;
  cursor: help;
}
.tag-package-hint {
  font-size: 0.7em;
  color: #6d28d9;
  font-weight: 400;
}

/* ── Empty active courses state ── */
.empty-active-courses {
  padding: 28px 16px !important;
  text-align: center;
  border-bottom: none !important;
}
.empty-active-courses__inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.empty-active-courses__icon {
  font-size: 32px;
  color: #cbd5e1;
}
.empty-active-courses__text {
  font-size: 14px;
  color: #94a3b8;
  font-weight: 500;
}
.empty-active-courses__hint {
  font-size: 12px;
  color: #94a3b8;
}

/* ── Student group header: history count ── */
.student-group-history-count {
  margin-left: 6px;
  font-size: 12px;
  color: #94a3b8;
  font-weight: 400;
}
.student-group-history-count::before {
  content: '·';
  margin-right: 6px;
}

/* ── History section ── */
.history-section {
  border-top: 1px dashed #e2e8f0;
  background: #fafbfc;
}
.history-section__toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 10px 14px;
  border: none;
  background: transparent;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  color: #64748b;
  cursor: pointer;
  transition: background 0.15s;
}
.history-section__toggle:hover {
  background: #f1f5f9;
}
.history-section__icon {
  font-size: 18px;
  color: #94a3b8;
}
.history-section__count {
  font-weight: 400;
  font-size: 12px;
  color: #94a3b8;
}
.history-section__chevron {
  margin-left: auto;
  font-size: 11px;
  color: #94a3b8;
}
.history-section__body {
  padding: 4px 14px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* ── History course card ── */
.history-course-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 12px 14px;
  transition: box-shadow 0.15s;
  position: relative;
  border-left: 3px solid #d1d5db;
}
.history-course-card:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.history-course-card__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
}
.history-course-card__subject {
  background: #f1f5f9 !important;
  color: #475569 !important;
  border: 1px solid #cbd5e1 !important;
}
.tag-history {
  border-radius: 6px;
  font-size: 11px;
  padding: 2px 8px;
  font-weight: 700;
  letter-spacing: 0.02em;
}
.tag-history--settled {
  background: #f0fdf4;
  color: #15803d;
  border: 1px solid #86efac;
}
.tag-history--completed {
  background: #eff6ff;
  color: #1d4ed8;
  border: 1px solid #93c5fd;
}
.history-course-card__details {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
  font-size: 13px;
  color: #64748b;
}
.history-course-card__detail-label {
  font-weight: 600;
  color: #94a3b8;
  margin-right: 4px;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.history-course-card__actions {
  display: flex;
  gap: 8px;
  margin-top: 10px;
  align-items: center;
}
.history-course-card__dates {
  margin-top: 10px;
  border-top: 1px solid #f1f5f9;
  padding-top: 10px;
}
@media (max-width: 640px) {
  .history-section__body {
    padding: 4px 8px 12px;
  }
  .history-course-card {
    padding: 10px 12px;
  }
  .history-course-card__details {
    flex-direction: column;
    gap: 2px;
  }
}
.tag-paid {
  background: #e8f5e9;
  color: #2e7d32;
  border: 1px solid #a5d6a7;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 3px 10px;
}
.tag-unpaid {
  background: #fff3e0;
  color: #e65100;
  border: 1px solid #ffcc80;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 3px 10px;
}
.paid-date-hint { font-size: 11px; color: #2e7d32; margin-top: 2px; white-space: nowrap; }
.tag-armed {
  background: #ffebee;
  color: #c62828;
  border: 1px solid #ef9a9a;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  padding: 3px 10px;
}

/* ----- Makeup Slots ----- */
.btn-makeup-query {
  width: 100%;
  padding: 8px 12px !important;
  font-size: 13px !important;
  font-weight: 600;
  border: 1px dashed var(--primary) !important;
  color: var(--primary) !important;
  border-radius: 8px;
  transition: var(--transition);
}
.btn-makeup-query:hover:not(:disabled) {
  background: var(--primary-bg) !important;
}
.btn-makeup-query:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.makeup-range-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}
.makeup-range-bar label {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-light);
  white-space: nowrap;
}
.makeup-range-bar select {
  padding: 6px 10px;
  border-radius: 6px;
  font-size: 13px;
  flex: 1;
}
.makeup-status {
  text-align: center;
  padding: 28px 16px;
  color: var(--text-light);
  font-size: 14px;
}
.makeup-slots-list {
  max-height: 380px;
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: 8px;
  margin-bottom: 16px;
}
.makeup-date-group + .makeup-date-group {
  border-top: 1px solid var(--border);
}
.makeup-date-header {
  padding: 8px 12px;
  font-size: 13px;
  font-weight: 700;
  background: var(--primary-bg);
  color: var(--text);
  position: sticky;
  top: 0;
  z-index: 1;
}
.makeup-slot-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 12px;
  border-top: 1px solid #f0f0f0;
  font-size: 13px;
}
.makeup-slot-row:first-child {
  border-top: none;
}
.makeup-slot-row:hover {
  background: #fafafa;
}
.slot-info {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  min-width: 0;
  flex: 1;
}
.slot-time {
  color: var(--text);
  font-weight: 500;
}
.slot-capacity {
  font-size: 12px;
  font-weight: 600;
  padding: 1px 8px;
  border-radius: 10px;
}
.slot-capacity.cap-free {
  background: #e8f5e9;
  color: #2e7d32;
}
.slot-capacity.cap-partial {
  background: #fff3e0;
  color: #e65100;
}
.slot-students {
  font-size: 11px;
  color: var(--text-light);
  flex-basis: 100%;
}
.slot-has-students {
  background: #fffde7;
}
.btn-renew-warn {
  background: #ff9800 !important;
  color: #fff !important;
  border: 1px solid #e65100 !important;
  font-weight: 600;
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 12px;
  cursor: pointer;
}
.btn-renew-warn:hover {
  background: #e65100 !important;
}
.action-dropdown-renew {
  color: #e65100 !important;
  font-weight: 600 !important;
}

/* ── Dark mode: history section ── */
[data-theme="dark"] .history-section {
  border-top-color: #334155;
  background: #0f172a;
}
[data-theme="dark"] .history-section__toggle {
  color: #94a3b8;
}
[data-theme="dark"] .history-section__toggle:hover {
  background: #1e293b;
}
[data-theme="dark"] .history-course-card {
  background: #1e293b;
  border-color: #334155;
  border-left-color: #475569;
}
[data-theme="dark"] .history-course-card:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
[data-theme="dark"] .history-course-card__subject {
  background: #334155 !important;
  color: #e2e8f0 !important;
  border-color: #475569 !important;
}
[data-theme="dark"] .tag-history--settled {
  background: #052e16;
  color: #4ade80;
  border-color: #166534;
}
[data-theme="dark"] .tag-history--completed {
  background: #172554;
  color: #60a5fa;
  border-color: #1e40af;
}
[data-theme="dark"] .history-course-card__details {
  color: #94a3b8;
}
[data-theme="dark"] .history-course-card__detail-label {
  color: #64748b;
}
[data-theme="dark"] .history-course-card__dates {
  border-top-color: #334155;
}
[data-theme="dark"] .empty-active-courses__icon {
  color: #475569;
}
[data-theme="dark"] .empty-active-courses__text,
[data-theme="dark"] .empty-active-courses__hint {
  color: #64748b;
}

/* ── Disabled button UX: cursor + tooltip affordance ── */
.btn-add-session.disabled {
  opacity: 0.5;
  cursor: not-allowed;
  position: relative;
}
</style>
