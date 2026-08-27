<template>
  <div class="course-page">
    <!-- Top Bar -->
    <div class="card course-header-card" data-guide="course-mgmt-header">
      <div class="header-actions">
        <div class="page-title-block">
          <div class="course-lens-kicker">
            <p class="command-kicker">課務營運</p>
            <span class="course-lens-badge">唯讀營運視圖</span>
          </div>
          <h2 class="page-title">課程管理</h2>
          <p class="ref-hint">查找課程、編輯月結日期與新增堂次，都在這一頁完成。</p>
          <div class="meta-pills">
            <span class="meta-pill">{{ groupedCourses.length }} 位學生</span>
            <span v-if="pagination.lastPage > 1" class="meta-pill">第 {{ pagination.page }} / {{ pagination.lastPage }} 頁</span>
          </div>
        </div>
        <div class="header-buttons">
          <button class="course-lens-primary-action" type="button" @click="emit('navigate', 'students')">
            <span class="material-symbols-outlined btn-icon" aria-hidden="true">person_search</span>
            前往學生管理
          </button>
          <button class="btn-soft" @click="expandAllGroups"><span class="material-symbols-outlined btn-icon" aria-hidden="true">unfold_more</span>全部展開</button>
          <button class="btn-soft" @click="collapseAllGroups"><span class="material-symbols-outlined btn-icon" aria-hidden="true">unfold_less</span>全部收合</button>
          <button class="btn-soft" @click="showBulkLeaveModal = true">
            <span class="material-symbols-outlined btn-icon" aria-hidden="true">event_busy</span> 連假批次請假
          </button>
          <button class="btn-soft" @click="emit('navigate', 'subject-settings')">
            <span class="material-symbols-outlined btn-icon" aria-hidden="true">library_books</span> 管理科目
          </button>
        </div>
      </div>

      <div class="course-lens-guidance" role="note" data-testid="course-lens-guidance">
        <span class="material-symbols-outlined course-lens-guidance__icon" aria-hidden="true">near_me</span>
        <div>
          <strong>這一頁適合查找與分流</strong>
          <span>建立、續報與加購課程仍從「學生管理」的學生主檔進入；本頁可直接編輯既有課程、設定月結日期與新增堂次。</span>
        </div>
      </div>

      <div class="course-lens-summary" aria-label="課程管理摘要" data-testid="course-lens-summary">
        <article v-for="metric in courseLensMetrics" :key="metric.key" class="course-lens-metric" :class="`course-lens-metric--${metric.tone}`">
          <span class="course-lens-metric__label">{{ metric.label }}</span>
          <strong class="course-lens-metric__value">{{ metric.value }}</strong>
          <span class="course-lens-metric__hint">{{ metric.hint }}</span>
        </article>
      </div>

      <!-- Filters -->
      <div class="filter-bar grid" data-guide="course-mgmt-filters">
        <div class="filter-field">
          <label for="course-filter-student">搜尋學生</label>
          <input id="course-filter-student" v-model="filters.name" placeholder="輸入姓名..." @input="debouncedLoad" />
        </div>
        <div class="filter-field">
          <label for="course-filter-type">上課類型</label>
          <select id="course-filter-type" v-model="filters.class_type" @change="loadCourses(1)">
            <option value="">全部</option>
            <option value="one_on_one">一對一</option>
            <option value="one_on_two">一對二</option>
            <option value="one_on_three">一對三</option>
            <option value="tutoring">輔導</option>
            <option value="trial">試聽</option>
          </select>
        </div>
        <div class="filter-field">
          <label for="course-filter-teacher">搜尋老師</label>
          <input id="course-filter-teacher" v-model="filters.teacher_name" placeholder="輸入老師姓名..." @input="debouncedLoad" />
        </div>
        <div class="filter-field">
          <label for="course-filter-status">課程狀態</label>
          <select id="course-filter-status" v-model="filters.course_status" @change="loadCourses(1)">
            <option value="">全部</option>
            <option value="active">進行中</option>
            <option value="inactive">已暫停</option>
          </select>
        </div>
        <button v-if="hasActiveCourseFilters" class="course-filter-clear" type="button" data-testid="course-filter-clear" @click="clearCourseFilters">
          <span class="material-symbols-outlined" aria-hidden="true">filter_alt_off</span>
          清除篩選
        </button>
      </div>

      <!-- Performance cockpit stats -->
      <div class="stats-strip" aria-label="本頁課程類型分布">
        <span class="stats-orb stats-orb-total">
          <span class="stats-orb-label">課程總覽</span>
          <span class="stats-orb-num">{{ courses.length }}</span>
          <span class="stats-orb-caption">筆課程在線</span>
        </span>
        <span class="stats-orb"><span class="stats-orb-label">1:1</span><span class="stats-orb-num">{{ coursesByType.one_on_one }}</span><span class="stats-orb-caption">一對一</span></span>
        <span class="stats-orb"><span class="stats-orb-label">1:2</span><span class="stats-orb-num">{{ coursesByType.one_on_two }}</span><span class="stats-orb-caption">一對二</span></span>
        <span class="stats-orb"><span class="stats-orb-label">1:3</span><span class="stats-orb-num">{{ coursesByType.one_on_three }}</span><span class="stats-orb-caption">一對三</span></span>
        <span class="stats-orb"><span class="stats-orb-label">輔導</span><span class="stats-orb-num">{{ coursesByType.tutoring }}</span><span class="stats-orb-caption">輔導</span></span>
        <span class="stats-orb"><span class="stats-orb-label">試聽</span><span class="stats-orb-num">{{ coursesByType.trial }}</span><span class="stats-orb-caption">試聽</span></span>
        <template v-if="coursesBySubject.length">
          <span class="stats-subject-deck">
            <span class="stats-subject-title">科目</span>
            <span
              v-for="s in coursesBySubject"
              :key="s.subject"
              class="stats-strip-subject"
            >{{ s.label }} {{ s.count }}</span>
          </span>
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

    <section v-if="pendingLeaveLoaded || pendingLeaveLoading || pendingLeaveError" class="pending-leave-summary" aria-live="polite">
      <div class="pending-leave-summary__header">
        <div class="pending-leave-summary__icon material-symbols-outlined" aria-hidden="true">inbox</div>
        <div class="pending-leave-summary__body">
          <strong>家長請假待處理</strong>
          <span v-if="pendingLeaveLoading">正在同步待辦案件…</span>
          <span v-else-if="pendingLeaveError" class="pending-leave-summary__error" role="alert">{{ pendingLeaveError }}</span>
          <span v-else-if="pendingLeaveWorkflows.length">先確認案件摘要，再進入主任收件匣完成決策。</span>
          <span v-else>目前沒有待處理的家長請假。</span>
        </div>
        <button v-if="pendingLeaveWorkflows.length" class="pending-leave-summary__cta pending-leave-summary__cta--all" type="button" @click="emit('navigate', { target: 'director', section: 'exception-workflows' })">查看全部請假<span aria-hidden="true">→</span></button>
      </div>
      <div v-if="pendingLeaveLoading" class="pending-leave-case-list" aria-hidden="true">
        <div v-for="idx in 2" :key="idx" class="pending-leave-case pending-leave-case--skeleton"></div>
      </div>
      <div v-else-if="pendingLeaveError" class="pending-leave-summary__error-actions">
        <button class="pending-leave-summary__cta" type="button" @click="loadCourses(pagination.page)">再試一次</button>
      </div>
      <div v-else-if="pendingLeaveWorkflows.length" class="pending-leave-case-list">
        <article v-for="workflow in pendingLeavePreview" :key="workflow.id" class="pending-leave-case" data-testid="pending-leave-case">
          <div class="pending-leave-case__content">
            <div class="pending-leave-case__title-row">
              <strong>{{ workflow.student?.name || '未命名學生' }}</strong>
              <span class="pending-leave-case__status">{{ pendingLeaveStatusLabel(workflow.status) }}</span>
            </div>
            <p>{{ pendingLeaveSessionLabel(workflow) }}</p>
            <p class="pending-leave-case__reason">原因：{{ workflow.payload?.reason || '家長未提供原因' }}</p>
          </div>
          <button class="pending-leave-case__cta" type="button" :aria-label="`處理這筆請假：${workflow.student?.name || '未命名學生'}`" @click="openPendingLeaveWorkflow(workflow)">處理這筆請假<span aria-hidden="true">→</span></button>
        </article>
        <button v-if="pendingLeaveWorkflows.length > pendingLeavePreview.length" class="pending-leave-summary__more" type="button" @click="emit('navigate', { target: 'director', section: 'exception-workflows' })">還有 {{ pendingLeaveWorkflows.length - pendingLeavePreview.length }} 筆，查看全部</button>
      </div>
    </section>

    <!-- Course Table -->
    <div class="card table-card" data-guide="course-mgmt-table">
      <div v-if="coursesLoading && !groupedCourses.length" class="course-list-skeleton" role="status" aria-label="課程資料載入中">
        <div v-for="idx in 3" :key="idx" class="course-skeleton-group">
          <div class="course-skeleton-header"></div>
          <div class="course-skeleton-row"></div>
          <div class="course-skeleton-row short"></div>
        </div>
      </div>
      <div v-else-if="groupedCourses.length" class="grouped-course-list" :class="{ 'focus-fullscreen-mode': focusedStudentKey }">
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
          <div
            class="student-group-header"
            role="button"
            tabindex="0"
            :aria-expanded="expandedStudentGroups.has(group.key)"
            @click="toggleStudentGroup(group.key)"
            @keydown.enter.prevent="toggleStudentGroup(group.key)"
            @keydown.space.prevent="toggleStudentGroup(group.key)"
          >
            <span class="student-group-left">
              <span class="expand-indicator">{{ expandedStudentGroups.has(group.key) ? '▼' : '▶' }}</span>
              <span class="cell-student">{{ group.student_name }}</span>
              <span v-if="groupHasPausedCourse(group)" class="student-group-paused-badge">含暫停課程</span>
            </span>
            <span class="student-group-meta">
              <span>{{ activeCourses(group).length }} 筆進行中</span>
              <span v-if="historyCourses(group).length" class="student-group-history-count">{{ historyCourses(group).length }} 筆歷史</span>
            </span>
            <div class="student-group-header-actions">
              <button
                class="focus-btn"
                :class="{ active: focusedStudentKey === group.key }"
                @click="focusStudent(group, $event)"
                @keydown.stop
                :title="focusedStudentKey === group.key ? '取消專注' : '專注此學生'"
              >⊙</button>
            </div>
          </div>
          <div
            class="student-group-view-tabs"
            role="tablist"
            aria-label="學生課程與帳務"
            data-testid="student-group-view-tabs"
            @click.stop
            @keydown.stop
          >
            <button
              type="button"
              class="student-group-view-tab"
              :class="{ active: studentGroupTab(group.key) === 'courses' }"
              role="tab"
              data-testid="student-tab-courses"
              :aria-selected="studentGroupTab(group.key) === 'courses'"
              @click.stop="selectStudentGroupTab(group, 'courses', $event)"
            >課程資料</button>
            <button
              type="button"
              class="student-group-view-tab"
              :class="{ active: studentGroupTab(group.key) === 'billing' }"
              role="tab"
              data-testid="student-tab-billing"
              :aria-selected="studentGroupTab(group.key) === 'billing'"
              @click.stop="selectStudentGroupTab(group, 'billing', $event)"
            >帳務資料</button>
          </div>
          <div v-if="expandedStudentGroups.has(group.key)" class="student-group-add-row">
            <button type="button" class="btn-soft student-group-add-btn" data-testid="student-group-goto-students" @click="emit('navigate', { target: 'students', studentId: group.student_id })">
              <span class="material-symbols-outlined btn-icon" aria-hidden="true">person_add</span>
              到學生管理新增課程
            </button>
          </div>
          <div v-if="expandedStudentGroups.has(group.key) && studentGroupTab(group.key) === 'courses'" class="table-wrap group-table-wrap">
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
                  <!-- Full-width status strip for paused courses (enterprise-style notice row) -->
                  <tr v-if="c.status === 'inactive' && !effectiveClosedReason(c)" class="paused-notice-row" role="status">
                    <td colspan="6" class="paused-notice-td">
                      <div class="paused-notice">
                        <span class="paused-notice__dot" aria-hidden="true"></span>
                        <span class="paused-notice__label">課程暫停中</span>
                        <span class="paused-notice__sep" aria-hidden="true">·</span>
                        <span class="paused-notice__desc">未恢復前不排新課、不計入待辦</span>
                        <button class="paused-notice__btn" type="button" @click.stop="requestCoursePause(c)">▶ 恢復課程</button>
                      </div>
                    </td>
                  </tr>
                  <tr :class="['course-row', courseRowClass(c)]">
                    <td class="td-subject">
                      <div v-if="effectiveClosedReason(c) === 'settled' || effectiveClosedReason(c) === 'completed'" class="settled-course-callout" role="status">
                        <span class="settled-course-callout__icon" aria-hidden="true">✅</span>
                        <span class="settled-course-callout__main">已結案</span>
                        <span class="settled-course-callout__sub">{{ effectiveClosedReason(c) === 'settled' ? '手動結案，無需續報' : '堂數已用完' }}</span>
                      </div>
                      <div class="subject-line">
                        <span class="tag subject-tag" :class="{ 'subject-tag--paused': c.status === 'inactive' }">{{ getSubjectLabel(c.subject) }}</span>
                        <span class="status-tag" :class="c.class_type">{{ classTypeLabel(c.class_type) }}</span>
                        <span v-if="c.PackageID" class="tag tag-package" :title="c.PackageName || '多科方案'">方案</span>
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
                      <div v-if="coursePaymentSummary(c)" class="payment-summary-line" role="note">
                        <span class="payment-summary-label">最近繳費：</span>{{ formatPaymentSummary(c.latest_payment_summary) }}
                      </div>
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
                      <!-- #2007 phase 2: up to 3 warning badges used to stack inline after the
                           time string, competing with it for attention. Collapse to one summary
                           chip on its own line; the full list is still in its tooltip. -->
                      <template v-for="w in rowWarningSummary(group, c)" :key="w.label">
                        <div class="course-row-badges">
                          <span class="row-badge" :class="'row-badge--' + w.tone" :title="w.title">{{ w.label }}</span>
                        </div>
                      </template>
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
                        <button class="small primary course-primary-action" @click="editCourse(c)">編輯</button>
                        <button
                          v-if="canCloseCourse(c)"
                          class="small ghost course-settle-action"
                          @click="closeCourseNoRenew(c)"
                        >結案（不續報）</button>
                        <button
                          v-if="isManualOccurrenceCourse(c)"
                          class="small btn-add-session manual-occurrence-action"
                          @click="openManualSessionModal(c)"
                        >＋新增下一堂</button>
                        <button
                          v-if="(isSessionMode(c) || isMonthlyMode(c)) && !isManualOccurrenceCourse(c)"
                          class="small btn-add-session manual-occurrence-action"
                          @click="openManualSessionModal(c)"
                        >{{ isMonthlyMode(c) ? '排月結' : '排課' }}</button>
                        <button class="small ghost btn-toggle" @click="toggleDatesAndMakeups(c)">
                          {{ expandedDates.has(c.id) ? '收起' : '詳情' }}
                        </button>
                        <div class="action-menu-wrapper">
                          <button class="small ghost action-menu-trigger" @click.stop="toggleActionMenu(c.id)" title="其他課程操作" aria-haspopup="menu" :aria-expanded="activeActionMenu === c.id">更多 ▾</button>
                          <div v-if="activeActionMenu === c.id" class="action-dropdown" role="menu" aria-label="其他課程操作" @click.stop>
                            <p v-if="isSessionMode(c) || isMonthlyMode(c)" class="action-section-label">排課與課堂</p>
                            <button
                              v-if="(isSessionMode(c) || isMonthlyMode(c)) && !isManualOccurrenceCourse(c)"
                              class="action-dropdown-item action-dropdown-add-session-mobile"
                              role="menuitem"
                              :class="{ 'action-dropdown-item--disabled': isSessionMode(c) && !canQuickAddSession(c) }"
                              :disabled="isSessionMode(c) && !canQuickAddSession(c)"
                              :title="isMonthlyMode(c) ? '在課程起訖日內新增月結堂次' : (canQuickAddSession(c) ? '' : quickAddDisabledReason(c))"
                              @click="isMonthlyMode(c) ? (openMonthlySessionModal(c), closeActionMenu()) : (canQuickAddSession(c) && (openQuickAddSessionModal(c), closeActionMenu()))"
                            ><span class="material-symbols-outlined action-icon" aria-hidden="true">add_task</span> {{ isMonthlyMode(c) ? '新增月結堂次' : '補課 / 補登' }}</button>
                            <p class="action-section-label">帳務與合約</p>
                            <button class="action-dropdown-item" role="menuitem" @click="openInvoiceModal(c); closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">receipt_long</span> 帳單與對帳</button>
                            <button
                              v-if="isSessionMode(c) && !c.PackageID"
                              class="action-dropdown-item"
                              role="menuitem"
                              @click="openPackageConversion(c); closeActionMenu()"
                            ><span class="material-symbols-outlined action-icon" aria-hidden="true">account_tree</span> 轉多科共用</button>
                            <button
                              :class="['action-dropdown-item', { 'action-dropdown-renew': purchaseActionIsRenew(c) }]"
                              role="menuitem"
                              :title="purchaseActionTitle(c)"
                              @click="openPurchaseModal(c); closeActionMenu()"
                            ><span class="material-symbols-outlined action-icon" aria-hidden="true">shopping_cart</span> {{ purchaseActionLabel(c) }}</button>
                            <button class="action-dropdown-item action-dropdown-adjustment" role="menuitem" title="依情境選擇更正未付款堂數或轉移已上課紀錄" @click="openContractAdjustmentModal(c); closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">edit_note</span> 合約／堂次調整</button>
                            <p class="action-section-label">其他操作</p>
                            <button class="action-dropdown-item" role="menuitem" @click="duplicateCourseForTeacher(c); closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">content_copy</span> 換師複製</button>
                            <p class="action-section-label">狀態管理</p>
                            <button v-if="c.status !== 'inactive'" class="action-dropdown-item" role="menuitem" @click="requestCoursePause(c); closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">pause_circle</span> 暫停課程</button>
                            <button v-if="c.status === 'inactive'" class="action-dropdown-item action-dropdown-resume" role="menuitem" @click="requestCoursePause(c); closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">play_circle</span> 恢復課程</button>
                            <button v-if="canCloseCourse(c)" class="action-dropdown-item action-dropdown-close" role="menuitem" @click="closeCourseNoRenew(c); closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">check_circle</span> 結案（不續報）</button>
                            <hr class="action-dropdown-divider" />
                            <p class="action-section-label action-section-label--danger">危險操作</p>
                            <button class="action-dropdown-item action-dropdown-danger" role="menuitem" @click="confirmDeleteTarget = c; closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">delete</span> 刪除課程</button>
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
                            <strong class="dates-panel-title">上課日期（{{ packageMemberSessionSummary(c, { completed: sessionSummaryCount(c), cancelled: cancelledSessionCount(c) }).text }}）</strong>
                            <span
                              v-if="sessionDataLoadFailed || planningStatusVisible(c)"
                              role="status"
                              :class="[
                                'drift-hint',
                                sessionDataLoadFailed || planningStatusFor(c)?.severity === 'danger' ? 'session-load-error-hint' : null,
                                !sessionDataLoadFailed && planningStatusFor(c)?.severity === 'info' ? 'drift-hint-info' : null,
                              ]"
                            >
                              <strong>{{ sessionDataLoadFailed ? '堂次載入失敗' : planningStatusFor(c).title }}</strong>
                              — {{ sessionDataLoadFailed ? '目前無法確認最新堂次狀態，尚未變更。' : planningStatusFor(c).message }}
                              <button
                                v-if="sessionDataLoadFailed"
                                type="button" class="small primary" style="margin-left:6px"
                                @click.stop="retryLoadCourseSessions(c)"
                              >重新載入</button>
                              <button
                                v-else-if="['quick_add','arrange_makeup'].includes(planningStatusFor(c)?.action) && canQuickAddSession(c)"
                                type="button" class="small primary" style="margin-left:6px"
                                @click.stop="openQuickAddSessionModal(c)"
                              >{{ planningStatusFor(c)?.action === 'arrange_makeup' ? '安排補課' : '補排堂次' }}</button>
                            </span>
                            <span v-if="primarySessionUnits(c).length === 0" class="hint">無法計算（請確認排課設定）</span>
                            <button
                              v-if="cancelledSessionCount(c) > 0"
                              type="button"
                              class="notes-toggle-btn"
                              @click.stop="toggleCancelledSessions(c.id)"
                              :title="showCancelledSessions.has(c.id) ? '隱藏已取消／已調走' : '顯示已取消／已調走'"
                            >
                              {{ showCancelledSessions.has(c.id) ? '已調走／取消 ▲' : `含 ${cancelledSessionCount(c)} 堂已調走／取消 ▼` }}
                            </button>
                            <button class="notes-toggle-btn" @click.stop="toggleSessionNotes" :title="showSessionNotes ? '隱藏備註' : '顯示備註'">
                              {{ showSessionNotes ? '備註 ▲' : '備註 ▼' }}
                            </button>
                          </div>
                          <div v-if="primarySessionUnits(c).length > 0" class="dates-chip-grid">
                            <button
                              v-for="u in primarySessionUnits(c)"
                              :key="sessionRowKey(u)"
                              type="button"
                              :class="[
                                'date-chip',
                                'date-chip-clickable',
                                u.isProjected ? 'date-chip--projected' : 'date-chip--materialized',
                                getSessionStateClass(c, (u.date || '').slice(0,10), u.id)
                              ]"
                              :title="projectedChipTitle(c, u)"
                              @click="openSessionEdit(c, (u.date || '').slice(0,10), u.id, u)"
                            >
                              <template v-if="getSessionNumber(c, (u.date || '').slice(0,10), u.id)"><span class="chip-seq">第{{ getSessionNumber(c, (u.date || '').slice(0,10), u.id) }}堂</span></template><span class="chip-date">{{ formatSessionChipDate(u) }}</span><template v-if="u.isProjected"><span class="chip-state chip-state--projected">預排</span></template><template v-else-if="getSessionStateLabel(c, (u.date || '').slice(0,10), u.id)"><span class="chip-state">{{ getSessionStateLabel(c, (u.date || '').slice(0,10), u.id) }}</span></template><template v-if="u.isContractException"><span class="chip-state">例外</span></template><template v-if="showSessionNotes && isUserNote(u.note)"><span class="chip-note-text">{{ u.note }}</span></template>
                            </button>
                          </div>
                          <div v-if="showCancelledSessions.has(c.id) && movedOrCancelledUnits(c).length > 0" class="dates-chip-grid cancelled-sessions-grid" style="margin-top:8px">
                            <span
                              v-for="u in movedOrCancelledUnits(c)"
                              :key="'cx-' + sessionRowKey(u)"
                              class="date-chip cancelled"
                              :title="getSessionTooltip(c, (u.date || '').slice(0,10), u.id)"
                            >
                              <span class="chip-date">{{ formatSessionChipDate(u) }}</span>
                              <span class="chip-state">已取消</span>
                            </span>
                          </div>
                        </div>
                        <!-- Pending makeup schedules (issue #527) -->
                        <div v-if="(pendingMakeupsByCourse[c.id] ?? []).length > 0" class="pending-makeups-panel">
                          <strong class="pending-makeups-title">待補課（{{ (pendingMakeupsByCourse[c.id] ?? []).length }} 堂）</strong>
                          <div class="pending-makeups-list">
                            <div
                              v-for="ms in pendingMakeupsByCourse[c.id]"
                              :key="ms.id"
                              class="pending-makeup-row"
                            >
                              <span class="pending-makeup-date">{{ formatMakeupDate(ms) }}</span>
                              <button class="small pending-makeup-cancel" @click="cancelMakeupSchedule(ms, c)">取消補課</button>
                            </div>
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
                    <span class="history-course-card__detail"><span class="history-course-card__detail-label">堂數</span> <template v-if="hc.PackageID">已上 {{ getCompletedSessionCount(hc) }} 堂｜方案共用 {{ getPackageTotalSessions(hc) }} 堂</template><template v-else>已上 {{ getCompletedSessionCount(hc) }}<template v-if="isSessionMode(hc)"> / 購買 {{ getPurchasedSessions(hc) }}</template> 堂</template></span>
                    <span class="history-course-card__detail" v-if="hc.last_paid_at"><span class="history-course-card__detail-label">繳費</span> {{ hc.last_paid_at }}</span>
                  </div>
                  <div class="history-course-card__actions">
                    <button class="small ghost btn-toggle" @click="toggleDates(hc)">
                      {{ expandedDates.has(hc.id) ? '收起詳情' : '查看詳情' }}
                    </button>
                    <div class="action-menu-wrapper">
                      <button class="small ghost action-menu-trigger" @click.stop="toggleActionMenu(hc.id)" title="其他歷史課程操作" aria-haspopup="menu" :aria-expanded="activeActionMenu === hc.id">更多 ▾</button>
                      <div v-if="activeActionMenu === hc.id" class="action-dropdown" role="menu" aria-label="其他歷史課程操作" @click.stop>
                        <p class="action-section-label">課程與帳務</p>
                        <button class="action-dropdown-item" role="menuitem" @click="navigateToStudentCourse(hc); closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">edit</span> 編輯</button>
                        <button class="action-dropdown-item" role="menuitem" @click="openInvoiceModal(hc); closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">receipt_long</span> 帳單與對帳</button>
                        <button class="action-dropdown-item" role="menuitem" @click="duplicateCourseForTeacher(hc); closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">content_copy</span> 換師複製</button>
                        <p class="action-section-label">狀態管理</p>
                        <button class="action-dropdown-item action-dropdown-resume" role="menuitem" @click="requestCoursePause(hc); closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">play_circle</span> 恢復課程</button>
                        <hr class="action-dropdown-divider" />
                        <p class="action-section-label action-section-label--danger">危險操作</p>
                        <button class="action-dropdown-item action-dropdown-danger" role="menuitem" @click="confirmDeleteTarget = hc; closeActionMenu()"><span class="material-symbols-outlined action-icon" aria-hidden="true">delete</span> 刪除課程</button>
                      </div>
                    </div>
                  </div>
                  <div v-if="expandedDates.has(hc.id)" class="history-course-card__dates">
                    <div class="detail-panel">
                      <div class="dates-panel">
                        <div class="dates-panel-heading">
                          <strong class="dates-panel-title">上課日期（{{ packageMemberSessionSummary(hc, { completed: sessionSummaryCount(hc), cancelled: cancelledSessionCount(hc) }).text }}）</strong>
                        </div>
                        <div v-if="primarySessionUnits(hc).length > 0" class="dates-chip-grid">
                          <span
                            v-for="u in primarySessionUnits(hc)"
                            :key="sessionRowKey(u)"
                            :class="['date-chip', getSessionStateClass(hc, (u.date || '').slice(0,10), u.id)]"
                            :title="getSessionTooltip(hc, (u.date || '').slice(0,10), u.id)"
                          >
                            <template v-if="getSessionNumber(hc, (u.date || '').slice(0,10), u.id)"><span class="chip-seq">第{{ getSessionNumber(hc, (u.date || '').slice(0,10), u.id) }}堂</span></template><span class="chip-date">{{ formatSessionChipDate(u) }}</span><template v-if="getSessionStateLabel(hc, (u.date || '').slice(0,10), u.id)"><span class="chip-state">{{ getSessionStateLabel(hc, (u.date || '').slice(0,10), u.id) }}</span></template>
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
          <div
            v-if="expandedStudentGroups.has(group.key) && studentGroupTab(group.key) === 'billing'"
            class="table-wrap group-table-wrap student-billing-wrap"
          >
            <div v-if="studentBillingState[group.key]?.loading" class="student-billing-state">載入帳務中…</div>
            <div v-else-if="studentBillingState[group.key]?.error" class="student-billing-state student-billing-error" role="alert">
              {{ studentBillingState[group.key].error }}
            </div>
            <table v-else class="course-table student-billing-table" aria-label="帳務資料">
              <thead>
                <tr>
                  <th>科目</th>
                  <th>繳費</th>
                  <th>最近回報</th>
                  <th>收據</th>
                  <th class="col-actions">操作</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!(studentBillingState[group.key]?.rows || []).length">
                  <td colspan="5" class="student-billing-state">此學生目前沒有課程可對帳</td>
                </tr>
                <tr v-for="row in (studentBillingState[group.key]?.rows || [])" :key="row.course.id">
                  <td>
                    <div class="subject-line">
                      <span class="tag subject-tag">{{ getSubjectLabel(row.course.subject) }}</span>
                    </div>
                    <div class="price-line">應繳 ${{ formatMoney(row.course.Charge ?? row.course.charge ?? 0) }}</div>
                  </td>
                  <td>
                    <span :class="['small', 'btn-status', paymentStatusButtonClass(row.course)]">{{ paymentStatusButtonLabel(row.course) }}</span>
                  </td>
                  <td>
                    <template v-if="row.reports?.[0]">
                      {{ row.reports[0].payment_date || '—' }}
                      · {{ reportStatusLabel(row.reports[0].status) }}
                      <span v-if="row.reports[0].account_last5"> · {{ row.reports[0].account_last5 }}</span>
                    </template>
                    <span v-else class="hint">尚無回報</span>
                  </td>
                  <td>
                    <span v-if="row.reports?.some((r) => r.status === 'confirmed')" class="hint">確認入帳後可開</span>
                    <span v-else class="hint">待確認入帳</span>
                  </td>
                  <td class="cell-actions">
                    <div class="action-btns-row">
                      <button
                        v-if="row.course.payment_status !== 'paid' && row.course.payment_status !== 'pending_report'"
                        class="small primary"
                        type="button"
                        @click="togglePaymentStatus(row.course)"
                      >登記已回報</button>
                      <button class="small ghost btn-invoices" type="button" @click="openInvoiceModal(row.course)">帳單與對帳</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
      <div v-else class="empty-state">
        <div class="empty-icon">📋</div>
        <p class="empty-title">目前尚無課程資料</p>
        <p class="empty-desc">請在「學生管理」為學生建立課程。</p>
        <button type="button" class="btn-accent" data-testid="empty-state-goto-students" @click="emit('navigate', 'students')">
          <span class="material-symbols-outlined btn-icon" aria-hidden="true">groups</span> 前往學生管理
        </button>
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
      :allow-package-mode="true"
      mode="backfill"
      @cancel="showBackfillModal = false"
      @success="handleUniversalBackfillSuccess"
      @duplicate-course="handleSchedulerDuplicateCM"
    />

    <!-- Edit Course Modal -->
    <div v-if="showEditModal" class="modal-overlay">
      <div class="modal course-modal">
        <h3 class="modal-title">編輯課程</h3>
        <AtInlineAlert v-if="editabilityLoading" tone="info" title="正在檢查課程狀態" style="margin: 0 0 14px;">
          <p>正在確認付款、扣堂與對帳狀態；一般欄位仍可編輯。</p>
        </AtInlineAlert>
        <AtInlineAlert v-if="editabilityError" tone="warning" title="無法完成預檢" style="margin: 0 0 14px;">
          <p>{{ editabilityError }} 儲存時仍會由後端再次檢查。</p>
        </AtInlineAlert>
        <AtInlineAlert v-if="editSaveError" tone="danger" title="儲存失敗" style="margin: 0 0 14px;">
          <p>{{ editSaveError.message }}</p>
          <p v-if="editSaveError.details" class="alert-detail">{{ editSaveError.details }}</p>
          <p v-if="editSaveError.hint" class="alert-detail">{{ editSaveError.hint }}</p>
        </AtInlineAlert>
        <section v-if="editability?.reasons?.length" class="editability-action-panel" data-testid="course-editability-panel" aria-label="課程編輯分流">
          <div class="editability-action-panel__intro">
            <strong>這門課有資料不能用一般編輯改寫</strong>
            <span>一般欄位可以繼續修改；要處理受保護資料，請從對應流程進入。</span>
          </div>
          <div v-for="reason in editability.reasons" :key="reason.code" class="editability-action-row">
            <div class="editability-action-row__copy">
              <strong>{{ reason.message }}</strong>
              <span v-if="editabilityAffectedFields.length" class="editability-action-row__fields">
                受保護欄位：{{ editabilityAffectedFields.join('、') }}
              </span>
              <span v-if="editabilityActionDescription(reason.next_step)" class="editability-action-row__description">
                {{ editabilityActionDescription(reason.next_step) }}
              </span>
            </div>
            <button
              v-if="canOpenEditabilityAction(reason.next_step)"
              type="button"
              class="ghost small editability-action-row__button"
              @click="openEditabilityAction(reason.next_step)"
            >{{ editabilityActionLabel(reason.next_step) }} <span aria-hidden="true">→</span></button>
          </div>
        </section>
        <div class="form-section">
          <CourseEditForm
            ref="editFormRef"
            v-model="editForm"
            :teachers="editTeacherOptions"
            :rooms="rooms"
            :subjects="subjectOptions"
            :day-options="DAY_OPTIONS"
            :time-options="TIME_OPTIONS_30"
            :settlement-day-options="settlementDayOptions"
            :show-remaining="true"
            :package-info="editPackageInfo"
            :context-title="editContextTitle"
            :editability="editability"
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
          <button class="primary" :disabled="editFormRef?.hasErrors || editabilityLoading" @click="submitEdit">儲存</button>
        </div>
      </div>
    </div>

    <ContractAdjustmentChoiceModal
      :show="showContractAdjustmentModal"
      :student-name="contractAdjustmentCourse?.student_name || ''"
      :subject="contractAdjustmentCourse?.subject_name || contractAdjustmentCourse?.subject || ''"
      @close="showContractAdjustmentModal = false"
      @choose="chooseContractAdjustment"
    />

    <!-- Unpaid post-deduction billing correction -->
    <div v-if="showBillingCorrectionModal" class="modal-overlay" @click.self="!billingCorrectionSubmitting && (showBillingCorrectionModal = false)">
      <div class="modal course-modal billing-correction-modal">
        <h3 class="modal-title">更正未收款堂數</h3>
        <p class="modal-desc">
          {{ billingCorrectionCourse?.student_name || '此學生' }}／{{ billingCorrectionCourse?.subject_name || billingCorrectionCourse?.subject || '課程' }}
        </p>
        <div class="billing-correction-warning">
          僅適用於尚未收款的按堂課程。已上課紀錄不會被刪除；超出新堂數的未上課排程會取消，並留下稽核紀錄。
        </div>
        <AtInlineAlert v-if="billingCorrectionBlocked" tone="danger" title="無法更正" style="margin-bottom: 12px;">
          <p style="margin: 0;">{{ billingCorrectionBlocked.message }}</p>
          <p v-if="billingCorrectionBlocked.hint" style="margin: 6px 0 0;">{{ billingCorrectionBlocked.hint }}</p>
        </AtInlineAlert>
        <label class="form-label">更正後購買堂數
          <input v-model.number="billingCorrectionForm.new_session_count" type="number" min="1" step="1" class="form-input" />
        </label>
        <label class="form-label">更正後總費用
          <input v-model.number="billingCorrectionForm.new_charge" type="number" min="0" step="1" class="form-input" />
        </label>
        <p class="form-hint">依目前單堂費用試算：${{ billingCorrectionExpectedCharge.toLocaleString() }}</p>
        <label class="form-label">更正原因
          <textarea v-model="billingCorrectionForm.reason" class="form-input" rows="3" maxlength="255" placeholder="例如：主任確認本期理化實際收 7 堂"></textarea>
        </label>
        <div class="actions">
          <button class="ghost" :disabled="billingCorrectionSubmitting" @click="showBillingCorrectionModal = false">取消</button>
          <button class="primary" :disabled="billingCorrectionSubmitting" @click="submitBillingCorrection">{{ billingCorrectionSubmitting ? '處理中…' : '確認更正' }}</button>
        </div>
      </div>
    </div>

    <PurchaseSessionsModal
      :show="showPurchaseModal"
      :form="purchaseForm"
      :submitting="purchaseSubmitting"
      :is-package-mode="isPackageMember(purchaseCourse)"
      :current-total="getPackageTotalSessions(purchaseCourse)"
      :used-sessions="getPackageUsedSessions(purchaseCourse)"
      @close="!purchaseSubmitting && (showPurchaseModal = false)"
      @submit="submitPurchaseSessions"
    />

    <RenewMonthlyModal
      :show="showRenewMonthlyModal"
      :form="renewMonthlyForm"
      :submitting="renewMonthlySubmitting"
      :warnings="renewMonthlyWarnings"
      @close="!renewMonthlySubmitting && (showRenewMonthlyModal = false)"
      @submit="submitRenewMonthly"
    />

    <TransferSessionsModal
      :show="showTransferSessionsModal"
      :student-name="transferSessionsCourse?.student_name || ''"
      :subject="transferSessionsCourse?.subject_name || transferSessionsCourse?.subject || ''"
      :sessions="transferSessionsSessionOptions"
      :target-courses="transferTargetCourses"
      :target-courses-loading="transferTargetCoursesLoading"
      :submitting="transferSessionsSubmitting"
      :error-message="transferSessionsError"
      @close="!transferSessionsSubmitting && (showTransferSessionsModal = false)"
      @submit="submitTransferSessions"
    />

    <EnrollmentConflictDecisionModal
      :show="showDuplicateInterceptModal"
      :conflicts="duplicateConflicts"
      :class-type="interceptPendingClassType"
      :submitting="forceSubmitting"
      :subject-label-fn="getSubjectLabel"
      @cancel="showDuplicateInterceptModal = false"
      @purchase="interceptGoToPurchaseCM"
      @decision="onEnrollmentConflictDecision"
    />

    <QuickAddSessionModal
      :show="showQuickAddSessionModal"
      :form="quickAddSessionForm"
      :time-options="TIME_OPTIONS_30"
      :conflict="quickAddConflict"
      :checking="quickAddChecking"
      @close="closeQuickAddSessionModal"
      @submit="submitQuickAddSession"
      @check="runQuickAddCheck"
    />

    <ManualSessionModal
      :show="showManualSessionModal"
      :form="manualSessionForm"
      :result="manualSessionCheck"
      :checking="manualSessionChecking"
      :submitting="manualSessionSubmitting"
      :is-monthly="isMonthlyMode(manualSessionCourse)"
      :today="todayYmd"
      @close="closeManualSessionModal"
      @check="runManualSessionCheck"
      @submit="submitManualSession"
      @edit-course="editManualSessionCourse"
    />

    <div v-if="showPackageConversionModal" class="modal-overlay" @click.self="!packageConversionSubmitting && (showPackageConversionModal = false)">
      <div class="modal course-modal package-conversion-modal" role="dialog" aria-modal="true" aria-labelledby="package-conversion-title">
        <h3 id="package-conversion-title" class="modal-title">轉成多科共用</h3>
        <p class="modal-desc">原合約、已上課與帳務紀錄會保留；只有未使用堂數會放入共用池，不會重新收費。</p>
        <div class="package-conversion-summary">
          <strong>{{ packageConversionCourse?.student_name || '學生' }}／{{ packageConversionCourse?.subject_name || packageConversionCourse?.subject || '目前科目' }}</strong>
          <span>總堂數 {{ getPurchasedSessions(packageConversionCourse) }} 堂</span>
          <span>已上 {{ getUsedSessions(packageConversionCourse) }} 堂</span>
          <span>可共用 {{ Math.max(0, getPurchasedSessions(packageConversionCourse) - getUsedSessions(packageConversionCourse)) }} 堂</span>
        </div>
        <label class="form-group">方案名稱
          <input v-model="packageConversionForm.name" type="text" maxlength="128" placeholder="例如：學生多科共用方案" />
        </label>
        <label class="form-group">加入第二科目
          <select v-model="packageConversionForm.subject_name">
            <option value="">請選擇科目</option>
            <option v-for="subject in packageConversionSubjects" :key="String(subject.id ?? subject.value)" :value="subject.label || subject.value">{{ subject.label || subject.value }}</option>
          </select>
        </label>
        <label class="form-group">第二科目老師
          <select v-model="packageConversionForm.teacher_id">
            <option value="">請選擇老師</option>
            <option v-for="teacher in teachers" :key="teacher.id" :value="String(teacher.id)">{{ teacher.username || teacher.name || teacher.Name || `老師 #${teacher.id}` }}</option>
          </select>
        </label>
        <div class="actions">
          <button class="ghost" :disabled="packageConversionSubmitting" @click="showPackageConversionModal = false">取消</button>
          <button class="primary" :disabled="packageConversionSubmitting" @click="submitPackageConversion">{{ packageConversionSubmitting ? '建立中…' : '確認建立共用方案' }}</button>
        </div>
      </div>
    </div>

    <LeaveModal
      :show="showLeaveModal"
      :form="leaveForm"
      :session-options="leaveSessionOptions"
      :is-retro-leave="isSelectedRetroLeave"
      :day-label="dayLabel"
      :impact-preview="leaveImpactPreview"
      @close="showLeaveModal = false"
      @submit="submitLeave"
    />

    <BulkLeaveModal
      :show="showBulkLeaveModal"
      :form="bulkLeaveForm"
      :result="bulkLeaveResult"
      :submitting="bulkLeaveSubmitting"
      :impact-preview="bulkLeaveImpactPreview"
      :courses="courses"
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
      :error="rescheduleError"
      :submitting="rescheduleSubmitting"
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

    <div
      v-if="chipActionDialog"
      class="modal-overlay" role="dialog" aria-modal="true"
      @click.self="closeChipActionDialog" @keydown.esc.prevent="closeChipActionDialog"
    >
      <div class="modal course-modal" style="max-width: 420px;">
        <h3 class="modal-title">{{ chipActionDialog.title }}</h3>
        <p class="modal-desc">{{ chipActionDialog.message }}</p>
        <p v-if="chipActionDialog.meta" class="modal-hint">{{ chipActionDialog.meta }}</p>
        <div class="actions">
          <button type="button" class="ghost" @click="closeChipActionDialog">{{ chipActionDialog.secondaryLabel || '關閉' }}</button>
          <button type="button" class="primary" :disabled="chipActionDialog.busy" @click="confirmChipActionDialog">
            {{ chipActionDialog.busy ? '載入中…' : (chipActionDialog.primaryLabel || '確定') }}
          </button>
        </div>
      </div>
    </div>

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

    <!-- Payment Entry Modal — 登記已回報（待對帳，確認入帳後才已繳費） -->
    <PaymentEntryModal
      :show="paymentEntryOpen"
      :row="paymentEntryRow"
      @close="paymentEntryOpen = false"
      @confirmed="onPaymentEntryConfirmed"
    />

    <AccountingLedgerModal
      :show="ledgerOpen"
      :student-class-id="ledgerStudentClassId"
      :branch-id="props.branchId"
      @close="ledgerOpen = false"
    />

    <!-- 帳單記錄 Modal -->
    <div v-if="invoiceModalOpen" class="modal-overlay" @click.self="closeInvoiceModal">
      <div class="modal course-modal invoice-modal">
        <div class="invoice-modal-header">
          <div>
            <h3 class="modal-title">帳單與對帳紀錄</h3>
            <p class="modal-desc">
              {{ invoiceModalCourse?.student_name || '學生' }} — {{ getSubjectLabel(invoiceModalCourse?.subject) }}
            </p>
          </div>
          <div class="invoice-modal-tools">
            <button class="small ghost btn-ledger" type="button" @click="openLedgerForCourse(invoiceModalCourse)">
              對帳
            </button>
            <button class="icon-btn" type="button" aria-label="關閉帳單記錄" @click="closeInvoiceModal">×</button>
          </div>
        </div>

        <div v-if="invoiceModalLoading" class="invoice-modal-state" role="status">
          <div class="invoice-skeleton"></div>
          <div class="invoice-skeleton invoice-skeleton-short"></div>
        </div>
        <div v-else-if="invoiceModalError" class="invoice-modal-state invoice-modal-error" role="alert">
          {{ invoiceModalError }}
        </div>
        <div v-else-if="invoiceModalList.length === 0" class="invoice-modal-state">
          尚無帳單流水（舊有或堂數制課程可能只保留課程主檔繳費狀態）。可按「對帳」查看同學生收據與例外資料。
        </div>
        <div v-else class="invoice-table-scroll">
          <table class="invoice-table">
            <thead>
              <tr>
                <th>帳單（期別）</th>
                <th>應繳日</th>
                <th>付款日</th>
                <th>已收款紀錄</th>
                <th class="invoice-amount-cell">金額</th>
                <th class="invoice-amount-cell">已繳</th>
                <th class="invoice-status-cell">狀態</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in invoiceModalList" :key="inv.id">
                <td>
                  <strong>{{ formatLedgerInvoiceLabel(inv) }}</strong>
                  <div class="hint">{{ formatLedgerCourseLabel({ course_ref: inv.course_ref, subject: invoiceModalCourse?.subject_name || invoiceModalCourse?.subject }) }} · {{ formatBillingPeriod(inv.billing_period) }}</div>
                </td>
                <td>{{ inv.due_date || '—' }}</td>
                <td>{{ invoicePaidDateLabel(inv) }}</td>
                <td>
                  <div v-if="inv.payments?.length" class="invoice-payment-list">
                    <div
                      v-for="payment in inv.payments"
                      :key="payment.id"
                      :class="['invoice-payment-row', { 'invoice-payment-row--void': payment.is_void }]"
                    >
                      <span class="invoice-payment-date">{{ payment.paid_at || '未記錄日期' }}</span>
                      <span class="invoice-payment-amount">{{ payment.is_void ? '已更正 ' : '已收 ' }}${{ formatMoney(Math.abs(payment.amount || 0)) }}</span>
                      <span class="invoice-payment-method">{{ invoicePaymentMethodLabel(payment.method) }}</span>
                      <span v-if="payment.receipt_no" class="invoice-payment-receipt">{{ humanizeDocumentRef(payment.receipt_no) }}</span>
                      <span v-if="payment.is_void" class="invoice-payment-void">更正</span>
                    </div>
                  </div>
                  <span v-else class="hint">—</span>
                </td>
                <td class="invoice-amount-cell">
                  <strong>${{ formatMoney(inv.total_amount) }}</strong>
                  <div v-if="inv.amount_discrepancy" class="invoice-amount-warning" role="status">
                    依實際 {{ inv.period_sessions }} 堂計算；原帳單 ${{ formatMoney(inv.stored_total_amount) }}
                  </div>
                </td>
                <td class="invoice-amount-cell">${{ formatMoney(inv.paid_amount) }}</td>
                <td class="invoice-status-cell">
                  <span :class="['invoice-status-chip', invoiceStatusClass(inv)]">
                    {{ invoiceStatusLabel(inv) }}
                  </span>
                </td>
                <td>
                  <div class="invoice-row-actions">
                    <button
                      v-if="inv.status !== 'paid'"
                      class="small primary invoice-pay-btn"
                      type="button"
                      @click="openPaymentEntryForInvoice(inv)"
                    >登記已回報</button>
                    <button
                      v-if="canVoidInvoice(inv)"
                      class="small ghost invoice-void-btn"
                      type="button"
                      @click="openInvoiceVoidDialog(inv)"
                    >作廢</button>
                    <button
                      v-else-if="canExceptionVoidInvoice(inv)"
                      class="small ghost invoice-void-btn invoice-void-btn--exception"
                      type="button"
                      @click="openInvoiceExceptionVoidDialog(inv)"
                    >更正並作廢</button>
                    <span v-if="inv.status === 'paid' && !canVoidInvoice(inv) && !canExceptionVoidInvoice(inv)" class="hint">—</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="actions invoice-modal-actions">
          <button class="ghost" type="button" @click="closeInvoiceModal">關閉</button>
        </div>
      </div>
    </div>

    <div v-if="invoiceVoidTarget" class="modal-overlay" @click.self="!invoiceVoidSubmitting && closeInvoiceVoidDialog()">
      <div class="modal course-modal invoice-void-modal">
        <div class="premium-danger-header">
          <span class="premium-danger-icon">!</span>
          <div>
            <p class="premium-danger-kicker">Accounting Control</p>
            <h3 class="modal-title">{{ invoiceVoidMode === 'exception' ? '更正並作廢帳單' : '作廢帳單' }}</h3>
            <p class="modal-desc">
              {{ formatLedgerInvoiceLabel(invoiceVoidTarget) }} · {{ formatLedgerCourseLabel({ course_ref: invoiceVoidTarget.course_ref, subject: invoiceModalCourse?.subject_name || invoiceModalCourse?.subject }) }} · {{ formatBillingPeriod(invoiceVoidTarget.billing_period) }}
            </p>
          </div>
        </div>
        <div class="invoice-void-warning">
          <template v-if="invoiceVoidMode === 'exception'">
            這張帳單已有收款，或已繳足但狀態異常。系統會建立更正紀錄、保留原始收款與收據，並將帳單標記作廢後不再列入應收。
          </template>
          <template v-else>
            這會將帳單標記作廢，並從家長應收、課程帳單與催繳名單排除。已收款或部分收款的帳單不可在此作廢，請改走「撤銷收款」。
          </template>
        </div>
        <label class="field-label" for="invoice-void-reason">作廢原因（必填）</label>
        <textarea
          id="invoice-void-reason"
          v-model.trim="invoiceVoidReason"
          class="invoice-void-reason"
          rows="4"
          maxlength="255"
          placeholder="例：歷史錯帳，不應產生 2026年5月這筆應收"
          :disabled="invoiceVoidSubmitting"
        ></textarea>
        <p class="modal-desc">原因會寫入帳單稽核紀錄，之後可追查。</p>
        <div class="actions">
          <button class="ghost" type="button" :disabled="invoiceVoidSubmitting" @click="closeInvoiceVoidDialog">取消</button>
          <button class="danger-btn" type="button" :disabled="invoiceVoidSubmitting || invoiceVoidReason.trim().length < 3" @click="submitInvoiceVoid">
            {{ invoiceVoidSubmitting ? '處理中…' : (invoiceVoidMode === 'exception' ? '確認更正並作廢' : '確認作廢') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="pauseConfirmTarget" class="modal-overlay" @click.self="!pauseConfirmSubmitting && (pauseConfirmTarget = null)">
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
          <label v-if="!pauseConfirmIsResume" style="display:flex;align-items:flex-start;gap:8px;margin-top:12px;font-size:13px;cursor:pointer">
            <input v-model="pauseCancelRemaining" type="checkbox" style="margin-top:2px" />
            <span>取消剩餘未上排課（建議勾選；不勾選則只暫停課程、堂次仍留在行事曆）</span>
          </label>
        </div>
        <div class="actions">
          <button class="ghost" :disabled="pauseConfirmSubmitting" @click="pauseConfirmTarget = null">取消</button>
          <button class="primary" :disabled="pauseConfirmSubmitting" :class="{ 'btn-resume-primary': pauseConfirmIsResume }" @click="confirmCoursePause">
            {{ pauseConfirmSubmitting ? '處理中…' : (pauseConfirmIsResume ? '確認恢復' : '確認暫停') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Delete Confirm Modal (FR-013) -->
    <div v-if="confirmDeleteTarget" class="modal-overlay" @click.self="!deleteCourseSubmitting && (confirmDeleteTarget = null)">
      <div class="modal premium-danger-modal">
        <div class="premium-danger-header">
          <span class="premium-danger-icon">!</span>
          <div>
            <p class="premium-danger-kicker">Irreversible Action</p>
            <h3 class="modal-title">確認刪除課程</h3>
          </div>
        </div>
        <div class="premium-danger-body">
          <p>確定要刪除以下課程？</p>
          <p style="margin: 8px 0;">
            <strong>{{ confirmDeleteTarget.subject_name || confirmDeleteTarget.subject }}</strong>
            <span v-if="confirmDeleteTarget.student_name"> — {{ confirmDeleteTarget.student_name }}</span>
          </p>
          <p class="premium-danger-warning">刪除後無法復原，所有堂次紀錄將一併移除。</p>
        </div>
        <div class="actions">
          <button class="ghost" :disabled="deleteCourseSubmitting" @click="confirmDeleteTarget = null">取消</button>
          <button class="danger" :disabled="deleteCourseSubmitting" style="background: #dc2626; color: #fff; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer;" @click="executeDeleteCourse">
            {{ deleteCourseSubmitting ? '刪除中…' : '確認刪除' }}
          </button>
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
import { fetchClassSessions, normalizeClassSessionsPayload, sessionViewModelPatchFromApi } from '../lib/classSessionsApi';
import { buildTransferableSessionOption } from '../lib/sessionTransferEligibility';
import { getPerSessionFee, getCourseTotalFee } from '../lib/coursePricing';
import { coursesWithSlotConflicts } from '../lib/slotOccupancy';
import { courseRowWarningSummary } from '../lib/courseRowWarnings';
import {
  formatRenewSuccessMessage,
  formatDuplicatePurchaseHint,
  formatLedgerCourseLabel,
  formatLedgerInvoiceLabel,
  humanizeDocumentRef,
} from '../lib/studentClassDisplay.js';
import { createUniversalClassSchedule } from '../lib/universalSchedulerApi';
import { convertSingleCourseToPackage, updatePackage } from '../lib/coursePackagesApi';
import { buildEditTeacherOptions, shouldClearTeacherSelection } from '../lib/courseTeacherOptions';
import { computePackageNextTotal, packageMemberSessionSummary } from '../lib/packageSessions';
import {
  editabilityActionDescription,
  editabilityActionLabel,
  editabilityFieldLabel,
  editabilityNextStepForError,
  editabilityNextStepLabel,
} from '../lib/courseEditability';
import { useCourseSessionsDisplay } from '../composables/course-management/useCourseSessionsDisplay';
import { useRescheduleAndMakeup } from '../composables/course-management/useRescheduleAndMakeup';
import { useSessionEditFlow } from '../composables/course-management/useSessionEditFlow';
import CourseEditForm from '../components/CourseEditForm.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
import UniversalClassScheduler from '../components/UniversalClassScheduler.vue';
import EnrollmentConflictDecisionModal from '../components/EnrollmentConflictDecisionModal.vue';
import { buildForceOverrideFields } from '../lib/enrollmentConflictDecision';
import { isPendingWorkflowStatus } from '../lib/exceptionWorkflowFocus.js';
import { nextManualSessionDate } from '../lib/manualSessionDate.js';
import PurchaseSessionsModal from '../components/course-management/PurchaseSessionsModal.vue';
import RenewMonthlyModal from '../components/course-management/RenewMonthlyModal.vue';
import TransferSessionsModal from '../components/course-management/TransferSessionsModal.vue';
import ContractAdjustmentChoiceModal from '../components/course-management/ContractAdjustmentChoiceModal.vue';
import QuickAddSessionModal from '../components/course-management/QuickAddSessionModal.vue';
import ManualSessionModal from '../components/course-management/ManualSessionModal.vue';
import LeaveModal from '../components/course-management/LeaveModal.vue';
import BulkLeaveModal from '../components/course-management/BulkLeaveModal.vue';
import RescheduleModal from '../components/course-management/RescheduleModal.vue';
import MakeupSlotsModal from '../components/course-management/MakeupSlotsModal.vue';
import SessionEditModal from '../components/course-management/SessionEditModal.vue';
import SubstituteTeacherPickerModal from '../components/substitute/SubstituteTeacherPickerModal.vue';
import PaymentEntryModal from '../components/PaymentEntryModal.vue';
import AccountingLedgerModal from '../components/AccountingLedgerModal.vue';
import ToastWithUndo from '../components/substitute/ToastWithUndo.vue';
import { fetchTeacherAvailability, undoSubstitute } from '../lib/substituteApi.js';
import { listExceptionWorkflows } from '../api';
import {
  buildCourseLeaveDeepLink,
  pendingLeaveSessionLabel,
  pendingLeaveStatusLabel,
} from '../lib/courseLeaveWorkflowDisplay.js';

// PRD 9c058f19 — 代課流程 UX 優化旗標；env 為字串，需解析。
// 與 SmartCalendar.vue 對齊：預設開啟（'1'），設為 '0' 回退舊版 <select> 模式。
const FEATURE_SUBSTITUTE_V2 = ((import.meta?.env?.VITE_FEATURE_SUBSTITUTE_V2 ?? '1') + '') !== '0';

const DAY_OPTIONS = [
  { value: 1, label: '一' }, { value: 2, label: '二' }, { value: 3, label: '三' },
  { value: 4, label: '四' }, { value: 5, label: '五' }, { value: 6, label: '六' },
  { value: 7, label: '日' },
];
const isPackageMember = (course) => Number(course?.PackageID ?? course?.package_id ?? 0) > 0;
const getPackageTotalSessions = (course) => {
  const total = Number(course?.package_total_sessions ?? course?.PackageTotalSessions ?? course?.sessions_purchased ?? 0);
  return Number.isFinite(total) && total > 0 ? total : 0;
};
const getPackageUsedSessions = (course) => {
  const total = getPackageTotalSessions(course);
  const remaining = Number(course?.package_remaining_sessions ?? course?.PackageRemainingSessions ?? 0);
  const used = total - (Number.isFinite(remaining) ? remaining : 0);
  return used > 0 ? used : 0;
};
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
const coursesLoading = ref(false);
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
const pendingLeaveWorkflows = ref([]);
const pendingLeaveLoading = ref(false);
const pendingLeaveError = ref('');
const pendingLeaveLoaded = ref(false);
const pendingLeavePreview = computed(() => pendingLeaveWorkflows.value.slice(0, 3));

function openPendingLeaveWorkflow(workflow) {
  emit('navigate', buildCourseLeaveDeepLink(workflow));
}

async function loadPendingLeaveWorkflows(token) {
  if (!props.branchId || !token) {
    pendingLeaveLoaded.value = true;
    return;
  }
  pendingLeaveLoading.value = true;
  pendingLeaveError.value = '';
  try {
    const rows = await listExceptionWorkflows(token, { branchId: props.branchId, type: 'student_leave' });
    pendingLeaveWorkflows.value = rows
      .filter((row) => isPendingWorkflowStatus(row?.status))
      .sort((a, b) => String(a?.due_at || a?.created_at || '').localeCompare(String(b?.due_at || b?.created_at || '')));
  } catch (e) {
    pendingLeaveWorkflows.value = [];
    pendingLeaveError.value = e?.message || '家長請假待辦載入失敗';
  } finally {
    pendingLeaveLoading.value = false;
    pendingLeaveLoaded.value = true;
  }
}

const completedSessionDatesByCourse = ref({});
const sessionsByCourse = ref({});
const classSessionsByCourse = sessionsByCourse;
const sessionDataLoadFailed = ref(false);
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
  expandedDates, toggleDates, sessions, sessionUnits, primarySessionUnits, allSessionUnits, cancelledSessionCount, movedOrCancelledUnits, sessionRowKey, getSessionNumber, countNonLeaveSessions, effectiveSessionCount, leaveSessionCount,
  getSessionPlanningStatus, canMaterializeProjectedSession,
  getCourseSessionRows, getSessionRowsForDate, getSessionRowById, getSessionDisplayRow,
  getSessionState, getSessionStateLabel, getSessionStateClass, getSessionTooltip,
  getCourseCompletedDates, getCompletedSessionCount, isCompletedDate, displaySessions,
  isSessionMode, getPurchasedSessions, getRawRemainingSessions, getUsedSessions, displayRemainingSessions,
  formatAttendanceTooltipTime, updateLocalSessionRow,
  ensureCompletedSessionDatesLoaded, reloadCourseSessions, loadClassSessionsForCourses, loadEffectiveSessionDates,
  LEAVE_STATUSES, ATTENDED_SESSION_STATUSES,
} = useCourseSessionsDisplay({
  sessionsByCourse: classSessionsByCourse, completedSessionDatesByCourse,
  fetchClassSessionsFn: fetchClassSessions, supabase,
  branchId: computed(() => props.branchId),
});

// Keep the expanded summary aligned with the table's backend entitlement
// numbers. Package members still show their own attended rows; the shared pool
// usage is rendered separately by packageMemberSessionSummary.
const sessionSummaryCount = (course) => isPackageMember(course)
  ? getCompletedSessionCount(course)
  : getUsedSessions(course);

function planningStatusFor(course) {
  return getSessionPlanningStatus(course, { sessionLoadFailed: false });
}

function planningStatusVisible(course) {
  if (sessionDataLoadFailed.value) return false;
  const status = planningStatusFor(course);
  return !!(status && status.code !== 'healthy' && status.severity && status.severity !== 'none');
}

function projectedChipTitle(course, unit) {
  if (!unit?.isProjected) {
    return getSessionTooltip(course, String(unit?.date || '').slice(0, 10), unit?.id);
  }
  if (canMaterializeProjectedSession(course)) {
    return '預排日期（月結固定時段）；點擊後建立正式堂次並開啟編輯';
  }
  return '預排日期（尚未建立正式堂次）；點擊後可手動補排';
}

async function retryLoadCourseSessions(course) {
  // Global load failure: retry the whole visible course list.
  // Single-course miss path uses the resolve dialog + reloadCourseSessions instead.
  if (sessionDataLoadFailed.value) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) return;
      const ok = await loadClassSessionsForCourses(courses.value, token);
      if (ok !== false) {
        sessionDataLoadFailed.value = false;
        await loadEffectiveSessionDates(courses.value, token);
      }
    } catch (_) { /* keep failed flag */ }
    return;
  }
  await reloadCourseSessions(course);
}

/** Format a session unit into a readable chip label: "04/11（六）15:00–17:00" */
const DAY_LABELS = ['日', '一', '二', '三', '四', '五', '六'];
function formatSessionChipDate(u) {
  const dateStr = String(u?.date || '').slice(0, 10);
  if (!dateStr) return '—';
  const [, mm, dd] = dateStr.split('-');
  const dow = DAY_LABELS[new Date(`${dateStr}T12:00:00`).getDay()] ?? '';
  const base = `${mm}/${dd}（${dow}）`;
  const start = String(u?.startTime || '').slice(0, 5);
  const end = String(u?.endTime || '').slice(0, 5);
  if (start && end) return `${base} ${start}–${end}`;
  if (start) return `${base} ${start}`;
  return base;
}

// Bulk Holiday Leave
const showBulkLeaveModal = ref(false);
const bulkLeaveSubmitting = ref(false);
const bulkLeaveResult = ref(null);
const bulkLeaveForm = ref({ start_date: '', end_date: '' });
const bulkLeaveImpactPreview = computed(() => {
  const start = bulkLeaveForm.value.start_date;
  const end = bulkLeaveForm.value.end_date;
  if (!start || !end) return null;
  const startDate = new Date(`${start}T00:00:00`);
  const endDate = new Date(`${end}T00:00:00`);
  const days = Number.isFinite(startDate.getTime()) && Number.isFinite(endDate.getTime())
    ? Math.max(1, Math.round((endDate - startDate) / 86400000) + 1)
    : 1;
  return {
    title: '批次請假送出前確認',
    summary: `將掃描 ${start} 至 ${end}（共 ${days} 天）的可請假堂次。`,
    items: [
      '系統會逐堂標記請假並於尾端補堂（未來既有日期不變），無法只靠前端一次復原',
      '已有核准評量、已取消、已請假的堂次會被略過',
      '送出後請查看略過清單，必要時改用單堂補請假處理',
    ],
  };
});

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

const showDuplicateInterceptModal = ref(false);
const duplicateConflicts = ref([]);
const interceptOriginalPayload = ref(null);
const interceptPendingClassType = ref('');
const forceSubmitting = ref(false);

async function onEnrollmentConflictDecision(decision) {
  const payload = interceptOriginalPayload.value;
  if (!payload) {
    showDuplicateInterceptModal.value = false;
    return;
  }
  forceSubmitting.value = true;
  try {
    const result = await createUniversalClassSchedule({
      ...payload,
      ...buildForceOverrideFields(decision),
    });
    showDuplicateInterceptModal.value = false;
    interceptOriginalPayload.value = null;
    const created = Number(result?.created_confirmed_sessions ?? 0) + Number(result?.created_future_sessions ?? 0);
    const label = decision?.force_reason === 'create_trial'
      ? '已建立試聽'
      : decision?.force_reason === 'renewal_next_term'
        ? '已建立下一期續報'
        : '已建立獨立課程';
    alert(`${label}（${created} 堂）`);
    await loadCourses();
  } catch (err) {
    alert(err?.message || '建立失敗，請稍後再試');
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
  interceptPendingClassType.value = String(evt?.originalPayload?.class_type || '');
  showDuplicateInterceptModal.value = true;
}

// Edit
const showEditModal = ref(false);
const editingId = ref(null);
const editingCourseFromLaravel = ref(false);
const editingCourseRaw = ref(null);
const editFormRef = ref(null);
const editForm = ref({});
const editSaveError = ref(null);
const editability = ref(null);
const editabilityLoading = ref(false);
const editabilityError = ref('');
let editabilityRequestId = 0;
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
const editTeacherOptions = computed(() => buildEditTeacherOptions(teachers.value, editingCourseRaw.value));
const editabilityAffectedFields = computed(() => (
  (editability.value?.locked_fields || []).map(editabilityFieldLabel).filter(Boolean)
));

const EDITABILITY_ACTION_HANDLERS = new Set([
  'billing_correction',
  'transfer_sessions',
  'void_payment',
  'payment_report',
  'package_adjustment',
  'reconcile_usage',
  'new_contract',
]);

function canOpenEditabilityAction(action) {
  return EDITABILITY_ACTION_HANDLERS.has(action);
}

const navigateToStudentCourse = (course) => {
  const studentId = Number(course?.student_id ?? course?.StudentID ?? 0);
  const courseId = Number(course?.id ?? 0);
  emit('navigate', {
    target: 'students',
    studentId: Number.isSafeInteger(studentId) && studentId > 0 ? studentId : null,
    courseId: Number.isSafeInteger(courseId) && courseId > 0 ? courseId : null,
    intent: 'edit',
  });
};

function openEditabilityAction(action) {
  const course = editingCourseRaw.value;
  if (!course) return;
  showEditModal.value = false;
  if (action === 'billing_correction') {
    openBillingCorrectionModal(course);
  } else if (action === 'transfer_sessions') {
    openTransferSessionsModal(course);
  } else if (action === 'void_payment' || action === 'payment_report') {
    void openInvoiceModal(course);
  } else if (action === 'package_adjustment') {
    openPackageAdjustmentModal(course);
  } else if (action === 'reconcile_usage') {
    emit('navigate', 'duplicate-review');
  } else if (action === 'new_contract') {
    emit('navigate', { target: 'students', studentId: course.student_id ?? course.StudentID ?? null });
  }
}

async function loadCourseEditability(courseId) {
  const requestId = ++editabilityRequestId;
  editabilityLoading.value = true;
  editabilityError.value = '';
  try {
    const { data: { session } } = await supabase.auth.getSession();
    const token = session?.access_token;
    if (!token) throw new Error('登入狀態已失效，無法完成預檢。');
    const res = await fetch(`/api/v1/student-classes/${courseId}/editability`, {
      credentials: 'include',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(body?.message || `預檢失敗（${res.status}）`);
    if (requestId === editabilityRequestId) editability.value = body;
  } catch (error) {
    if (requestId === editabilityRequestId) {
      editability.value = null;
      editabilityError.value = error?.message || '課程狀態預檢失敗。';
    }
  } finally {
    if (requestId === editabilityRequestId) editabilityLoading.value = false;
  }
}
/** 開啟編輯時的排課指紋；儲存時若變更則自動 force_partial_rebuild 同步未上預排堂次 */
const editScheduleBaseline = ref(null);
const originalFirstClassDate = ref('');
const rooms = ref([]);
const settlementDayOptions = Array.from({ length: 31 }, (_, i) => i + 1);
const showPurchaseModal = ref(false);
const purchaseCourse = ref(null);
const purchaseSubmitting = ref(false);
const showRenewMonthlyModal = ref(false);
const renewMonthlyCourse = ref(null);
const renewMonthlyForm = ref({});
const renewMonthlySubmitting = ref(false);
const renewMonthlyWarnings = ref([]);

const showTransferSessionsModal = ref(false);
const transferSessionsCourse = ref(null);
const transferSessionsSubmitting = ref(false);
const transferSessionsError = ref('');
const transferTargetCourses = ref([]);
const transferTargetCoursesLoading = ref(false);
let transferTargetCoursesRequest = 0;

const showBillingCorrectionModal = ref(false);
const showContractAdjustmentModal = ref(false);
const contractAdjustmentCourse = ref(null);
const billingCorrectionCourse = ref(null);
const billingCorrectionSubmitting = ref(false);
const billingCorrectionForm = ref({ new_session_count: 1, new_charge: 0, reason: '' });
const billingCorrectionBlocked = ref(null);
const BILLING_CORRECTION_NEXT_STEP_HINT = {
  edit_charge_only: '堂數不能再改了，但費用還能改：請關閉本視窗，改到課程「編輯」畫面直接調整總費用，堂數維持不變即可。',
};
const billingCorrectionExpectedCharge = computed(() => {
  const course = billingCorrectionCourse.value;
  const count = Number(billingCorrectionForm.value.new_session_count || 0);
  const rate = Number(course?.rate_per_30min ?? course?.Rate ?? course?.rate ?? 0);
  return Math.round(rate * count);
});

function openBillingCorrectionModal(course) {
  const count = Number(course?.sessions_purchased ?? course?.SessionCount ?? 0);
  const rate = Number(course?.rate_per_30min ?? course?.Rate ?? course?.rate ?? 0);
  billingCorrectionCourse.value = course;
  billingCorrectionForm.value = {
    new_session_count: count,
    new_charge: Math.round(rate * count),
    reason: '',
  };
  billingCorrectionBlocked.value = null;
  showBillingCorrectionModal.value = true;
}

function isUnpaidCountCourse(course) {
  return isSessionMode(course) && !course?.PackageID && course?.payment_status !== 'paid';
}

function usageBalanceWarningTitle(course) {
  const diagnostic = course?.usage_balance_diagnostic;
  if (!diagnostic) return '課堂狀態與扣堂紀錄不一致，請先完成重複堂次／扣堂對帳。';
  return `課堂狀態顯示已上 ${diagnostic.class_session_used_sessions} 堂，但扣堂紀錄為 ${diagnostic.ledger_used_sessions} 堂；請先完成對帳，再作為收費依據。`;
}

function openContractAdjustmentModal(course) {
  contractAdjustmentCourse.value = course;
  if (!isUnpaidCountCourse(course)) {
    openTransferSessionsModal(course);
    return;
  }
  showContractAdjustmentModal.value = true;
}

function chooseContractAdjustment(action) {
  const course = contractAdjustmentCourse.value;
  showContractAdjustmentModal.value = false;
  if (!course) return;
  if (action === 'billing') openBillingCorrectionModal(course);
  if (action === 'transfer') openTransferSessionsModal(course);
}

async function submitBillingCorrection() {
  const course = billingCorrectionCourse.value;
  if (!course || billingCorrectionSubmitting.value) return;
  const count = Number(billingCorrectionForm.value.new_session_count || 0);
  const charge = Number(billingCorrectionForm.value.new_charge || 0);
  const reason = String(billingCorrectionForm.value.reason || '').trim();
  if (!Number.isInteger(count) || count < 1 || !Number.isInteger(charge) || charge < 0 || !reason) {
    toastRef.value?.show?.({ title: '資料不完整', description: '請填寫有效堂數、金額與更正原因。', variant: 'error', durationMs: 4000 });
    return;
  }
  billingCorrectionBlocked.value = null;
  billingCorrectionSubmitting.value = true;
  try {
    const { data: { session } } = await supabase.auth.getSession();
    const token = session?.access_token;
    if (!token) throw new Error('登入狀態已失效，請重新登入。');
    const res = await fetch(`/api/v1/student-classes/${course.id}/billing-correction`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ new_session_count: count, new_charge: charge, reason }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      // Keep this on-screen (not just a toast that vanishes) — the person acting on
      // it needs to see *why* it's blocked and what to do instead, not just retry.
      billingCorrectionBlocked.value = {
        message: body?.message || '更正失敗',
        hint: BILLING_CORRECTION_NEXT_STEP_HINT[body?.next_step] || null,
      };
      return;
    }
    showBillingCorrectionModal.value = false;
    await loadCourses();
    toastRef.value?.show?.({
      title: '未收款堂數已更正',
      description: `已更新為 ${body?.new_session_count ?? count} 堂／$${Number(body?.new_charge ?? charge).toLocaleString()}，已上課紀錄維持不變。`,
      variant: 'success',
      durationMs: 5000,
    });
  } catch (error) {
    billingCorrectionBlocked.value = { message: error?.message || '請稍後再試。', hint: null };
  } finally {
    billingCorrectionSubmitting.value = false;
  }
}

const transferSessionsSessionOptions = computed(() => {
  const c = transferSessionsCourse.value;
  if (!c) return [];
  return allSessionUnits(c)
    .map(buildTransferableSessionOption)
    .filter(Boolean);
});

function openTransferSessionsModal(course) {
  transferSessionsCourse.value = course;
  transferSessionsError.value = '';
  transferTargetCourses.value = [];
  showTransferSessionsModal.value = true;
  loadTransferTargetCourses(course);
}

function normalizedCourseValue(value) {
  return String(value ?? '').trim().toLowerCase();
}

function sameCourseSubject(source, target) {
  const sourceValues = [source?.subject, source?.subject_name].filter(Boolean).map(normalizedCourseValue);
  const targetValues = [target?.subject, target?.subject_name].filter(Boolean).map(normalizedCourseValue);
  if (sourceValues.length === 0 || targetValues.length === 0) return true;
  return sourceValues.some((value) => targetValues.includes(value))
    || sourceValues.some((value) => getSubjectText(value) && targetValues.includes(normalizedCourseValue(getSubjectText(value))));
}

function sameCourseStudent(source, target) {
  const sourceId = source?.student_id ?? source?.StudentID;
  const targetId = target?.student_id ?? target?.StudentID;
  if (sourceId != null && targetId != null && String(sourceId) !== String(targetId)) return false;
  const sourceName = normalizedCourseValue(source?.student_name);
  const targetName = normalizedCourseValue(target?.student_name);
  return !sourceName || !targetName || sourceName === targetName;
}

async function loadTransferTargetCourses(sourceCourse) {
  const requestId = ++transferTargetCoursesRequest;
  transferTargetCoursesLoading.value = true;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token || !props.branchId || !sourceCourse) return;
    const params = new URLSearchParams({
      branch_id: String(props.branchId),
      per_page: '100',
      page: '1',
    });
    if (sourceCourse.student_name) params.set('name', String(sourceCourse.student_name));
    const res = await fetch(`/api/v1/student-classes?${params}`, {
      credentials: 'include',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!res.ok) return;
    const json = await res.json().catch(() => ({}));
    const list = json?.data ?? json;
    const rows = Array.isArray(list) ? list : (list?.data ?? []);
    const candidates = rows
      .map((course) => ({
        ...course,
        id: Number(course?.id ?? course?.ID),
        student_name: course?.student_name ?? course?.student?.name ?? '',
        subject_name: course?.subject_name ?? '',
        teacher_name: course?.teacher_name ?? course?.teacher?.name ?? course?.teacher?.username ?? '',
        start_date: course?.start_date ?? course?.StartDate ?? '',
      }))
      .filter((course) => Number.isFinite(course.id) && course.id > 0)
      .filter((course) => course.id !== Number(sourceCourse.id))
      .filter((course) => sameCourseStudent(sourceCourse, course))
      .filter((course) => sameCourseSubject(sourceCourse, course));
    if (requestId === transferTargetCoursesRequest) transferTargetCourses.value = candidates;
  } catch (_) {
    // The manual ID fallback remains available if the lookup endpoint is unavailable.
  } finally {
    if (requestId === transferTargetCoursesRequest) transferTargetCoursesLoading.value = false;
  }
}

async function submitTransferSessions({ targetCourseId, sessionIds, reason }) {
  const course = transferSessionsCourse.value;
  if (!course || sessionIds.length === 0) return;
  transferSessionsSubmitting.value = true;
  transferSessionsError.value = '';
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { transferSessionsError.value = '請重新登入後再試'; return; }
    const hasRecovery = transferSessionsSessionOptions.value.some(
      (session) => sessionIds.includes(Number(session.id)) && session.recoverableCancelled
    );
    const endpoint = hasRecovery ? 'recover-transfer-sessions' : 'transfer-sessions';
    const res = await fetch(`/api/v1/student-classes/${course.id}/${endpoint}`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        session_ids: sessionIds,
        target_student_class_id: targetCourseId,
        ...(hasRecovery ? { reason } : {}),
      }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
      transferSessionsError.value = details || json?.message || '轉移失敗';
      return;
    }
    showTransferSessionsModal.value = false;
    toastRef.value?.show?.({
      title: '已轉移堂次紀錄',
      description: json?.message || `已轉移 ${sessionIds.length} 堂到課程 #${targetCourseId}`,
      variant: 'success',
      durationMs: 7000,
    });
    await loadCourses();
  } catch (e) {
    transferSessionsError.value = '轉移失敗：' + (e?.message || '請稍後再試');
  } finally {
    transferSessionsSubmitting.value = false;
  }
}
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
let quickAddCheckVersion = 0;
let quickAddCheckController = null;
const quickAddSessionForm = ref({
  session_date: '',
  start_time: '16:00',
  duration_minutes: 120,
  note: '',
  auto_approve: true,
  student_name: '',
  subject: 'Math',
});
const showManualSessionModal = ref(false);
const manualSessionCourse = ref(null);
const manualSessionCheck = ref(null);
const manualSessionChecking = ref(false);
const manualSessionSubmitting = ref(false);
const manualSessionForm = ref({ session_date: '', start_time: '16:00' });
// 每次檢查都帶版本，避免較早發出的 422 覆蓋主任剛選好的有效日期結果。
let manualSessionCheckVersion = 0;
let manualSessionCheckController = null;
const showPackageConversionModal = ref(false);
const packageConversionCourse = ref(null);
const packageConversionSubmitting = ref(false);
const packageConversionForm = ref({ name: '', subject_name: '', teacher_id: '' });
const packageConversionSubjects = computed(() => {
  const source = String(packageConversionCourse.value?.subject_name || packageConversionCourse.value?.subject || '').trim();
  return (subjectOptions.value || []).filter((subject) => String(subject?.label || subject?.value || '').trim() !== source);
});
const courseIdForAction = (course) => Number(course?.id ?? course?.ID ?? 0);
const isManualOccurrenceCourse = (course) => String(course?.scheduling_policy || 'auto_recurrence') === 'manual_occurrence';
const pauseConfirmTarget = ref(null);
const pauseConfirmSubmitting = ref(false);
const pauseCancelRemaining = ref(true);
const pauseConfirmIsResume = computed(() => pauseConfirmTarget.value?.status === 'inactive');
const pauseConfirmImpacts = computed(() => pauseConfirmIsResume.value
  ? ['恢復後可繼續排課與補課', '後續仍依原課程設定計算堂數與提醒', '已取消的未來堂次不會自動重建，需依需要重新排課']
  : [
      pauseCancelRemaining.value ? '取消未來尚未上課堂次' : '不取消剩餘排課（堂次仍會留在行事曆）',
      '暫停期間不排新課、不計入待辦',
      '可從歷史課程或暫停清單恢復',
    ]);

const showCancelledSessions = ref(new Set());
function toggleCancelledSessions(courseId) {
  const next = new Set(showCancelledSessions.value);
  if (next.has(courseId)) next.delete(courseId);
  else next.add(courseId);
  showCancelledSessions.value = next;
}

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
  pauseCancelRemaining.value = true;
  pauseConfirmTarget.value = course;
}

async function confirmCoursePause() {
  if (pauseConfirmSubmitting.value) return;
  const course = pauseConfirmTarget.value;
  if (!course) return;
  const isPaused = course.status === 'inactive';
  const action = isPaused ? '恢復' : '暫停';
  pauseConfirmSubmitting.value = true;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }

    const body = { action: isPaused ? 'resume' : 'pause' };
    if (!isPaused) body.cancel_remaining = !!pauseCancelRemaining.value;

    const res = await fetch(`/api/v1/student-classes/${course.id}/pause`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify(body),
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
  } finally {
    pauseConfirmSubmitting.value = false;
  }
}

function canCloseCourse(c) {
  return c.status !== 'inactive'
    && !isPackageMember(c)
    && (isSessionMode(c) || isMonthlyMode(c))
    && c.payment_status === 'paid';
}

async function closeCourseNoRenew(course) {
  const courseId = courseIdForAction(course);
  if (!courseId) { alert('課程資料缺少識別碼，請重新整理後再試'); return; }
  const studentName = course.student_name || '學生';
  const subject = getSubjectLabel(course.subject);
  const remaining = Math.max(0, Number(course.remaining_sessions ?? 0));
  const balanceWarning = remaining > 0
    ? `\n\n目前還有 ${remaining} 堂未使用。結案會取消未來排課，並放棄這 ${remaining} 堂剩餘額度。`
    : '';
  if (!confirm(`確定要結案「${studentName}」的 ${subject} 課程嗎？${balanceWarning}\n\n結案後此課程不再出現在繳費／續課提醒中，已繳費與已上課紀錄仍會保留。`)) return;

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }

    const res = await fetch(`/api/v1/student-classes/${courseId}/pause`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        action: 'pause',
        reason: 'settled',
        ...(remaining > 0 ? { forfeit_remaining: true } : {}),
      }),
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
    memo: `雙師課程（同學生另一位老師，接續「${course.subject_name || course.subject || '原科目'}」）`,
  };
  const sid = Number(course.student_id ?? course.StudentID) || '';
  schedulerInitialStudentId.value = sid;
  schedulerInitialTeacherId.value = '';
  showBackfillModal.value = true;
  loadRoomsForBranch();
}

// 月結課程的此入口其實是「結算／續約下月」（開 RenewMonthlyModal），
// 但舊標籤「加購堂數」對月結語意不通，主任找不到結算功能（in-app #141）。
function purchaseActionLabel(c) {
  if (!isSessionMode(c)) return '結算 / 續約下月';
  return Number(displayRemainingSessions(c) ?? 0) <= 2 ? '續報加購' : '加購堂數';
}
function purchaseActionIsRenew(c) {
  if (!isSessionMode(c)) return true;
  return Number(displayRemainingSessions(c) ?? 0) <= 2;
}
function purchaseActionTitle(c) {
  return isSessionMode(c) ? '加購／續報堂數' : '月結課程結算本期並續約下個月';
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
    renewMonthlyWarnings.value = [];
    showRenewMonthlyModal.value = true;
    loadRenewMonthlyPreview(course);
    return;
  }
  purchaseCourse.value = course;
  purchaseForm.value = {
    sessions: 8,
    start_date: localTodayYmd(),
    student_name: course?.student_name || '—',
    subject: course?.subject || 'Math',
    package_op: 'add', // 'add' (加購) | 'set' (設定總堂數) — package members only (#553)
  };
  showPurchaseModal.value = true;
}

function openPackageAdjustmentModal(course) {
  openPurchaseModal(course);
  if (!isPackageMember(course)) return;
  purchaseForm.value.package_op = 'set';
  purchaseForm.value.sessions = getPackageTotalSessions(course);
}

async function loadRenewMonthlyPreview(course) {
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token || !course?.id) return;
    const currentEnd = course?.end_date || course?.EndDate || null;
    let endDate = '';
    if (currentEnd) {
      const d = new Date(currentEnd);
      d.setMonth(d.getMonth() + 1);
      endDate = d.toISOString().slice(0, 10);
    } else {
      const d = new Date();
      d.setMonth(d.getMonth() + 1);
      endDate = d.toISOString().slice(0, 10);
    }
    const res = await fetch(`/api/v1/student-classes/${course.id}/renewal-preview`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({ mode: 'renew_monthly', end_date: endDate }),
    });
    const json = await res.json().catch(() => ({}));
    if (res.ok && Array.isArray(json.warnings)) {
      renewMonthlyWarnings.value = json.warnings;
    }
  } catch {
    /* preview is advisory only */
  }
}

async function submitPurchaseSessions() {
  if (purchaseSubmitting.value) return;
  const course = purchaseCourse.value;
  if (!course?.id) return;
  const isPackage = isPackageMember(course);
  if (!isPackage && (!Number.isFinite(Number(purchaseForm.value.sessions)) || Number(purchaseForm.value.sessions) <= 0)) {
    alert('請輸入正確堂數');
    return;
  }
  if (!isPackage && !purchaseForm.value.start_date) {
    alert('請選擇新批次開始日期');
    return;
  }
  purchaseSubmitting.value = true;
  try {
    if (isPackage) {
      const packageId = Number(course.PackageID ?? course.package_id);
      const op = purchaseForm.value.package_op === 'set' ? 'set' : 'add';
      const currentTotal = getPackageTotalSessions(course);
      const usedSessions = getPackageUsedSessions(course);
      const { ok, nextTotal, error } = computePackageNextTotal({
        mode: op,
        currentTotal,
        value: Number(purchaseForm.value.sessions),
        usedSessions,
      });
      if (!ok) {
        alert(error);
        return;
      }
      if (!packageId) {
        alert('找不到方案，請先重新整理後再試');
        return;
      }
      await updatePackage(packageId, { total_sessions: nextTotal });
      showPurchaseModal.value = false;
      await loadCourses();
      const groupKey = course?.student_id != null ? `sid:${course.student_id}` : null;
      if (groupKey) {
        expandedStudentGroups.value = new Set([...expandedStudentGroups.value, groupKey]);
        focusedStudentKey.value = groupKey;
      }
      toastRef.value?.show?.({
        title: op === 'set' ? '已設定共用方案總堂數' : '已加購共用方案堂數',
        description: `方案總堂數已由 ${currentTotal} 堂調整為 ${nextTotal} 堂；此方案的所有科目共用同一個堂數池。`,
        variant: 'success',
        durationMs: 7000,
      });
      return;
    }

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
      const duplicateHint = json?.duplicate_course?.id
        ? formatDuplicatePurchaseHint({ subject: course?.subject_name || course?.subject || '' })
        : '';
      alert((details || json?.message || '加購失敗') + duplicateHint);
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
    toastRef.value?.show?.({
      title: '已建立加購批次',
      description: formatRenewSuccessMessage({
        kind: 'purchase',
        studentName: course?.student_name || '',
        subject: course?.subject_name || course?.subject || '',
        sessions: newCourse.created_sessions,
        firstDate: newCourse.first_session_date || '',
        lastDate: newCourse.last_session_date || '',
      }),
      variant: 'success',
      durationMs: 7000,
    });
  } catch (e) {
    alert('加購失敗：' + (e?.message || '請稍後再試'));
  } finally {
    purchaseSubmitting.value = false;
  }
}

async function submitRenewMonthly(endDate) {
  if (renewMonthlySubmitting.value) return;
  const course = renewMonthlyCourse.value;
  if (!course?.id) return;
  if (!endDate) {
    alert('請選擇新到期日或延長月數');
    return;
  }
  renewMonthlySubmitting.value = true;
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
    const newCourse = json?.new_course || {};
    toastRef.value?.show?.({
      title: '已建立月結新一期',
      description: formatRenewSuccessMessage({
        kind: 'monthly',
        studentName: course?.student_name || '',
        subject: course?.subject_name || course?.subject || '',
      }),
      variant: 'success',
      durationMs: 7000,
    });
    await loadCourses();
  } catch (e) {
    alert('續約失敗：' + (e?.message || '請稍後再試'));
  } finally {
    renewMonthlySubmitting.value = false;
  }
}

function openQuickAddSessionModal(course, prefill = null) {
  quickAddSessionCourse.value = course;
  quickAddConflict.value = null;
  quickAddChecking.value = false;
  const prefillDate = prefill?.date ? String(prefill.date).slice(0, 10) : '';
  const prefillStart = prefill?.startTime ? normalizeTo30Min(String(prefill.startTime).slice(0, 5)) : '';
  let durationMinutes = Math.max(30, Math.round((Number(course?.duration_hours) || 2) * 60));
  if (prefill?.startTime && prefill?.endTime) {
    const [sh, sm] = String(prefill.startTime).slice(0, 5).split(':').map(Number);
    const [eh, em] = String(prefill.endTime).slice(0, 5).split(':').map(Number);
    const mins = (eh * 60 + em) - (sh * 60 + sm);
    if (Number.isFinite(mins) && mins >= 30) durationMinutes = mins;
  }
  quickAddSessionForm.value = {
    session_date: prefillDate || nextManualSessionDate(course),
    start_time: prefillStart || normalizeTo30Min(course?.start_time || '16:00'),
    duration_minutes: durationMinutes,
    note: '',
    auto_approve: true,
    student_name: course?.student_name || '—',
    subject: course?.subject || 'Math',
  };
  showQuickAddSessionModal.value = true;
  runQuickAddCheck(course?.id);
}

let _quickAddCheckTimer = null;
function closeQuickAddSessionModal() {
  clearTimeout(_quickAddCheckTimer);
  _quickAddCheckTimer = null;
  quickAddCheckVersion += 1;
  quickAddCheckController?.abort();
  quickAddCheckController = null;
  quickAddChecking.value = false;
  showQuickAddSessionModal.value = false;
}

async function runQuickAddCheck(courseIdOverride) {
  const courseId = courseIdOverride || quickAddSessionCourse.value?.id;
  if (!courseId) return;
  const form = quickAddSessionForm.value;
  if (!form.session_date || !form.start_time) return;
  const requestVersion = ++quickAddCheckVersion;
  // Disable submit during the debounce window as well as during the network
  // request; otherwise the previous check could briefly authorize new input.
  quickAddChecking.value = true;
  quickAddConflict.value = null;
  quickAddCheckController?.abort();
  quickAddCheckController = null;
  clearTimeout(_quickAddCheckTimer);
  _quickAddCheckTimer = setTimeout(async () => {
    quickAddChecking.value = true;
    const controller = new AbortController();
    quickAddCheckController = controller;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) return;
      const res = await fetch(`/api/v1/student-classes/${courseId}/add-session/check`, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ session_date: form.session_date, start_time: form.start_time }),
        signal: controller.signal,
      });
      const json = await res.json().catch(() => ({}));
      if (requestVersion !== quickAddCheckVersion) return;
      quickAddConflict.value = json;
    } catch (error) {
      if (error?.name === 'AbortError') return;
      if (requestVersion !== quickAddCheckVersion) return;
      quickAddConflict.value = null;
    } finally {
      if (requestVersion === quickAddCheckVersion) quickAddChecking.value = false;
      if (quickAddCheckController === controller) quickAddCheckController = null;
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
    closeQuickAddSessionModal();
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
function openManualSessionModal(course) {
  const courseId = courseIdForAction(course);
  if (!courseId) { alert('課程資料缺少識別碼，請重新整理後再試'); return; }
  manualSessionCourse.value = { ...course, id: courseId };
  manualSessionCheck.value = null;
  manualSessionForm.value = {
    session_date: nextManualSessionDate(course),
    start_time: String(course.start_time || '16:00').slice(0, 5),
  };
  showManualSessionModal.value = true;
  runManualSessionCheck();
}

function closeManualSessionModal() {
  manualSessionCheckVersion += 1;
  manualSessionCheckController?.abort();
  manualSessionCheckController = null;
  manualSessionChecking.value = false;
  showManualSessionModal.value = false;
}

function openMonthlySessionModal(course) {
  openManualSessionModal(course);
}

function editManualSessionCourse() {
  const course = manualSessionCourse.value;
  showManualSessionModal.value = false;
  if (course?.id) editCourse(course);
}

function openPackageConversion(course) {
  packageConversionCourse.value = course;
  packageConversionForm.value = {
    name: `${course?.student_name || '學生'}多科共用方案`,
    subject_name: '',
    teacher_id: '',
  };
  packageConversionSubmitting.value = false;
  showPackageConversionModal.value = true;
}

async function submitPackageConversion() {
  const course = packageConversionCourse.value;
  const form = packageConversionForm.value;
  const courseId = Number(course?.id ?? course?.ID ?? 0);
  if (!courseId || !form.name.trim() || !form.subject_name || !form.teacher_id) {
    alert('請填寫方案名稱、第二科目與老師');
    return;
  }
  if (!confirm('確認建立共用方案？原合約與已收款紀錄會保留，不會再次收費。')) return;
  packageConversionSubmitting.value = true;
  try {
    const result = await convertSingleCourseToPackage(courseId, {
      name: form.name.trim(),
      additional_subject: { subject_name: form.subject_name, teacher_id: Number(form.teacher_id) },
    });
    showPackageConversionModal.value = false;
    await loadCourses();
    alert(`${result?.message || '已轉成多科共用方案'}\n${result?.next_step || ''}`);
  } catch (error) {
    alert(error?.message || '建立共用方案失敗，資料未變更');
  } finally {
    packageConversionSubmitting.value = false;
  }
}

async function runManualSessionCheck() {
  const course = manualSessionCourse.value;
  const form = manualSessionForm.value;
  const courseId = courseIdForAction(course);
  if (!courseId || !form.session_date || !form.start_time) {
    manualSessionCheck.value = { can_add: false, message: '課程資料不完整，請重新整理後再試' };
    return;
  }
  const requestVersion = ++manualSessionCheckVersion;
  manualSessionCheckController?.abort();
  const controller = new AbortController();
  manualSessionCheckController = controller;
  manualSessionChecking.value = true;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) throw new Error('請先登入');
    const res = await fetch(`/api/v1/student-classes/${courseId}/manual-sessions/check`, {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ session_date: form.session_date, start_time: form.start_time }),
      signal: controller.signal,
    });
    const result = await res.json().catch(() => ({ can_add: false, message: '檢查失敗' }));
    if (requestVersion !== manualSessionCheckVersion) return;
    manualSessionCheck.value = result;
  } catch (e) {
    if (e?.name === 'AbortError') return;
    if (requestVersion !== manualSessionCheckVersion) return;
    manualSessionCheck.value = { can_add: false, message: e?.message || '檢查失敗' };
  } finally {
    if (requestVersion === manualSessionCheckVersion) manualSessionChecking.value = false;
    if (manualSessionCheckController === controller) manualSessionCheckController = null;
  }
}

async function submitManualSession() {
  const course = manualSessionCourse.value;
  const form = manualSessionForm.value;
  const courseId = courseIdForAction(course);
  if (!courseId || !manualSessionCheck.value?.can_add) return;
  manualSessionSubmitting.value = true;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) throw new Error('請先登入');
    const res = await fetch(`/api/v1/student-classes/${courseId}/manual-sessions`, {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ session_date: form.session_date, start_time: form.start_time }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      manualSessionCheck.value = json;
      return;
    }
    closeManualSessionModal();
    alert(json.created === false ? '這一堂已存在，未重複建立。' : '下一堂已建立，已加入課表。');
    await loadCourses();
  } catch (e) {
    alert(e?.message || '建立手動排課失敗');
  } finally {
    manualSessionSubmitting.value = false;
  }
}

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
    if (row?.isProjected) return;
    const status = String(row?.status || '').toLowerCase();
    if (['cancelled', 'leave', 'leave_adjusted'].includes(status)) return;
    const date = String(row?.date || '').slice(0, 10);
    if (!date) return;
    const isRetro = RETRO_LEAVE_STATUSES.has(status);
    const startTime = String(row?.startTime || '').slice(0, 5);
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
const selectedLeaveOption = computed(() => {
  const sid = leaveForm.value.session_id;
  const date = leaveForm.value.schedule_date;
  return leaveSessionOptions.value.find((opt) => {
    if (sid && opt.session_id) return opt.session_id === sid;
    return opt.date === date;
  }) || null;
});
const leaveCascadePlan = ref(null);
const leaveCascadePlanLoading = ref(false);

function formatLeavePreviewDate(ymd) {
  const s = String(ymd || '').slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
  return `${s.slice(5, 7)}/${s.slice(8, 10)}`;
}

function buildLeaveCascadeImpactItems(baseItems, plan) {
  const items = [...baseItems];
  if (!plan) return items;
  const policy = String(plan.policy || 'KEEP_FUTURE_DATES_APPEND_TAIL');
  if (policy === 'KEEP_FUTURE_DATES_APPEND_TAIL' || plan.future_dates_unchanged) {
    items.push('未來既有上課日期與時間維持不變');
  }
  const next = plan.next_billable_session;
  if (next && next.date) {
    const ord = next.ordinal != null ? `第 ${next.ordinal} 堂` : '下一堂';
    items.push(`下一堂：${formatLeavePreviewDate(next.date)}（${ord}）`);
  }
  const vacated = Array.isArray(plan.vacated) ? plan.vacated : [];
  if (vacated.length && policy === 'SHIFT_FUTURE_DATES_APPEND_TAIL') {
    items.push(
      `（整體順延）原定上課日將被空出：${vacated.map(formatLeavePreviewDate).join('、')}`,
    );
  }
  const moves = Array.isArray(plan.moves) ? plan.moves : [];
  if (moves.length && policy === 'SHIFT_FUTURE_DATES_APPEND_TAIL') {
    const sample = moves
      .slice(0, 4)
      .map((m) => `${formatLeavePreviewDate(m.from)}→${formatLeavePreviewDate(m.to)}`)
      .join('、');
    const more = moves.length > 4 ? `…共 ${moves.length} 堂改期` : '';
    items.push(`後續堂次改期：${sample}${more}`);
  }
  if (plan.append) {
    items.push(`尾堂補上：${formatLeavePreviewDate(plan.append)}（課程結束日→${formatLeavePreviewDate(plan.extended_end_date || plan.append)}）`);
  }
  return items;
}

const leaveImpactPreview = computed(() => {
  const form = leaveForm.value;
  if (!form.schedule_date) return null;
  const retro = isSelectedRetroLeave.value;
  const option = selectedLeaveOption.value;
  const label = option?.label || `${form.schedule_date} ${form.start_time || ''}`.trim();
  const baseItems = retro
    ? [
        '會沖回該堂已扣堂數，並重新計算課程剩餘堂數',
        '會作廢該堂出缺勤與學習評量紀錄',
        '未來既有上課日不變，僅於尾端補上堂次',
      ]
    : [
        '本堂會標記為請假，不扣堂數',
        '未來既有上課日不變，僅於尾端補上堂次',
        '該堂不需要填寫學習評量',
      ];
  const items = buildLeaveCascadeImpactItems(baseItems, leaveCascadePlan.value);
  if (leaveCascadePlanLoading.value) {
    items.push('正在計算尾堂補上日期…');
  }
  return {
    title: retro ? '補請假高風險影響預覽' : '請假送出前影響預覽',
    summary: `${form.student_name || '學生'}｜${getSubjectLabel(form.subject)}｜${label}`,
    items,
  };
});

async function refreshLeaveCascadePreview() {
  const form = leaveForm.value;
  const courseId = Number(form.course_id || 0);
  const date = String(form.schedule_date || '').slice(0, 10);
  if (!courseId || !date || !showLeaveModal.value) {
    leaveCascadePlan.value = null;
    return;
  }
  leaveCascadePlanLoading.value = true;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      leaveCascadePlan.value = null;
      return;
    }
    const res = await fetch('/api/v1/schedules/leave-cascade-preview', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        student_course_id: courseId,
        schedule_date: date,
        class_session_id: form.session_id || undefined,
      }),
    });
    if (!res.ok) {
      leaveCascadePlan.value = null;
      return;
    }
    leaveCascadePlan.value = await res.json();
  } catch (_) {
    leaveCascadePlan.value = null;
  } finally {
    leaveCascadePlanLoading.value = false;
  }
}
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
  await refreshLeaveCascadePreview();
}
async function submitLeave() {
  if (!leaveForm.value.schedule_date) return;
  const branchId = Number(props.branchId) || 0;
  if (!branchId) { alert('請先選擇分校'); return; }
  const form = leaveForm.value;
  const isRetro = isSelectedRetroLeave.value;

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
        class_session_id: form.session_id || undefined,
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
      const undoScheduleId = Number(json?.undo?.schedule_id || 0);
      const undoWindowSec = Number(json?.undo?.undo_window_seconds || 30);
      const canUndo = undoScheduleId > 0 && Number.isFinite(undoWindowSec) && undoWindowSec > 0;
      if (canUndo) {
        toastRef.value?.show?.({
          title: '請假已送出',
          description: `本堂已請假（未來日期不變，已補尾堂），${undoWindowSec} 秒內可復原`,
          variant: 'success',
          durationMs: undoWindowSec * 1000,
          undoDescription: '已撤銷請假，尾堂已回復',
          onUndo: async () => {
            const undoRes = await fetch(`/api/v1/schedules/${undoScheduleId}/undo-leave`, {
              method: 'POST',
              credentials: 'include',
              headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
            });
            const undoJson = await undoRes.json().catch(() => ({}));
            if (!undoRes.ok) {
              throw new Error(undoJson?.message || '撤銷請假失敗');
            }
            await loadCourses();
          },
        });
      } else {
        toastRef.value?.show?.({
          title: '請假已送出',
          description: '本堂已請假（未來日期不變，已補尾堂）',
          variant: 'success',
          durationMs: 4000,
        });
      }
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
  if (!date) {
    leaveCascadePlan.value = null;
    return;
  }
  leaveForm.value.day_of_week = dayOfWeekFromDate(date);
  const option = leaveSessionOptions.value.find((opt) => opt.date === date);
  if (option) {
    leaveForm.value.session_id = option.session_id || null;
    if (option.start_time) {
      leaveForm.value.start_time = option.start_time;
      leaveForm.value.end_time = computeEndTime(option.start_time, leaveForm.value.duration_hours || 2);
    }
  }
  refreshLeaveCascadePreview();
});
watch(() => showLeaveModal.value, (open) => {
  if (!open) {
    leaveCascadePlan.value = null;
    leaveCascadePlanLoading.value = false;
  }
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
const coursePaymentSummary = (course) => course?.latest_payment_summary || null;
const formatPaymentSummary = (summary) => {
  if (!summary) return '';
  const parts = [];
  if (summary.payment_date) parts.push(`日期 ${summary.payment_date}`);
  if (summary.amount !== null && summary.amount !== undefined && summary.amount !== '') {
    parts.push(`金額 $${formatMoney(summary.amount)}`);
  }
  if (summary.account_last5) parts.push(`後5碼 ${summary.account_last5}`);
  if (summary.note) parts.push(`備註 ${summary.note}`);
  if (summary.status === 'pending') parts.push('待對帳');
  return parts.join(' · ') || '已有繳費回報';
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
    const d = String(r.date || '').slice(0, 10);
    if (!d || !r.startTime) continue;
    if (!byDate.has(d)) byDate.set(d, new Set());
    byDate.get(d).add(String(r.startTime).slice(0, 5));
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
    const d = String(r.date || '').slice(0, 10);
    if (!d || !r.startTime) continue;
    const dow = dayOfWeekFromDate(d);
    const start = String(r.startTime).slice(0, 5);
    const key = `${dow}|${start}`;
    let dur = globalDur;
    if (r.endTime) {
      const mins = diffMinutesStartEnd(r.startTime, r.endTime);
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
  /^auto-extended-after-leave/,
  /^leave-policy-shift$/,
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
      const group = { key, student_id: studentId, student_name: studentName, courses: [] };
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

// #2007/#2006: a renewal that left the old course open shows up here as two
// "進行中" rows fighting over the same teacher slot. Flag them so a director
// doesn't have to guess why a request-leave didn't free the time up.
// A student rarely has more than a handful of active courses, so recomputing
// per render is cheap — no memoization needed.
const hasSlotConflict = (group, c) => coursesWithSlotConflicts(activeCourses(group)).has(c.id);

// #2007 phase 2: collapse this row's schedule warnings (slot conflict / drift /
// contract exception / usage-balance review) to one summary chip instead of
// stacking every badge inline — see frontend/src/lib/courseRowWarnings.js.
const rowWarningSummary = (group, c) => courseRowWarningSummary(
  { ...c, hasSlotConflict: hasSlotConflict(group, c) },
  usageBalanceWarningTitle,
);
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

const hasActiveCourseFilters = computed(() => (
  ['name', 'class_type', 'teacher_name', 'teacher_id', 'course_status']
    .some((key) => String(filters.value[key] ?? '').trim() !== '')
));

function clearCourseFilters() {
  filters.value = { name: '', class_type: '', teacher_name: '', teacher_id: '', course_status: '' };
  loadCourses(1);
}

const courseLensMetrics = computed(() => {
  const visibleCourses = courses.value;
  const activeCount = visibleCourses.filter((course) => !isHistoryCourse(course) && course.status !== 'inactive').length;
  const attentionCount = visibleCourses.filter((course) => {
    const remaining = displayRemainingSessions(course);
    return isSessionMode(course)
      && remaining != null
      && Number(remaining) <= 2
      && !effectiveClosedReason(course);
  }).length;
  const pausedCount = visibleCourses.filter((course) => course.status === 'inactive' && !effectiveClosedReason(course)).length;

  return [
    {
      key: 'students',
      label: '本頁學生',
      value: groupedCourses.value.length,
      hint: pagination.value.total ? `篩選結果 ${pagination.value.total} 筆課程` : '目前沒有篩選結果',
      tone: 'neutral',
    },
    {
      key: 'active',
      label: '進行中',
      value: activeCount,
      hint: '可優先查看排課與堂數',
      tone: 'success',
    },
    {
      key: 'attention',
      label: '續報提醒',
      value: attentionCount,
      hint: attentionCount ? '剩餘 2 堂以下' : '目前沒有低堂數提醒',
      tone: attentionCount ? 'warning' : 'neutral',
    },
    {
      key: 'paused',
      label: '暫停中',
      value: pausedCount,
      hint: pausedCount ? '不列入新增排課' : '目前沒有暫停課程',
      tone: pausedCount ? 'info' : 'neutral',
    },
  ];
});

const paymentStatusButtonClass = (course) => {
  if (course?.payment_status === 'paid') return 'tag-paid';
  if (course?.payment_status === 'pending_report') return 'tag-pending-report';
  return 'tag-unpaid';
};
const paymentStatusButtonLabel = (course) => {
  if (course?.payment_status === 'paid') return '已繳費';
  if (course?.payment_status === 'pending_report') return '待對帳';
  if (course?.payment_status === 'partial') return '部分繳';
  return '未繳費';
};
const reportStatusLabel = (status) => ({
  pending: '待對帳',
  confirmed: '已入帳',
  rejected: '已退回',
}[status] || status || '—');

const formatMoney = (value) => {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n.toLocaleString() : '0';
};

const formatBillingPeriod = (period) => {
  if (!period || String(period).length < 7) return period || '—';
  const [year, month] = String(period).split('-');
  const monthNum = Number.parseInt(month, 10);
  return monthNum ? `${year}年${monthNum}月` : period;
};

const invoiceStatusLabel = (invoice) => {
  if (invoice?.ledger_label) return invoice.ledger_label;
  const status = typeof invoice === 'string' ? invoice : invoice?.status;
  return ({
  paid: '已繳',
  unpaid: '未繳',
  partial: '部分繳',
  void: '已作廢',
  }[status] || status || '未知');
};
const invoiceStatusClass = (invoice) => {
  const ledgerStatus = invoice?.ledger_status || '';
  if (ledgerStatus && ledgerStatus !== invoice?.status) return 'invoice-status-exception';
  return `invoice-status-${invoice?.status || 'unknown'}`;
};
const invoicePaidDateLabel = (invoice) => {
  if (invoice?.paid_at) return invoice.paid_at;
  return invoice?.status === 'paid' ? '舊資料未記錄' : '—';
};
const invoicePaymentMethodLabel = (method) => ({
  cash: '現金',
  transfer: '匯款',
  void: '更正收款',
}[method] || method || '—');

const loadCourses = async (page = 1) => {
  if (!props.branchId) {
    coursesLoading.value = false;
    courses.value = [];
    pagination.value = { page: 1, lastPage: 1, total: 0, perPage: 50 };
    completedSessionDatesByCourse.value = {};
    sessionsByCourse.value = {};
    expandedStudentGroups.value = new Set();
    return;
  }
  coursesLoading.value = true;
  completedSessionDatesByCourse.value = {};
  sessionsByCourse.value = {};
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    let token = sess?.access_token;
    if (!token) {
      try {
        token = JSON.parse(localStorage.getItem('alltrue_session') || '{}')?.access_token || '';
      } catch { token = ''; }
    }
    loadPendingLeaveWorkflows(token);
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
          id: Number(c?.id ?? c?.ID ?? 0),
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
        sessionDataLoadFailed.value = false;
        const sessionsOk = await loadClassSessionsForCourses(result, token);
        if (sessionsOk === false) sessionDataLoadFailed.value = true;
        await loadEffectiveSessionDates(result, token);
        coursesLoading.value = false;
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
    id: Number(c?.id ?? c?.ID ?? 0),
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
    sessionDataLoadFailed.value = false;
    const sessionsOk = await loadClassSessionsForCourses(result, token || '');
    if (sessionsOk === false) sessionDataLoadFailed.value = true;
    await loadEffectiveSessionDates(result, token || '');
  } catch (_) {
    sessionsByCourse.value = {};
    sessionDataLoadFailed.value = true;
  }
  coursesLoading.value = false;
};

const {
  showRescheduleModal, rescheduleCourse, rescheduleForm, rescheduleSessionOptions,
  rescheduleSubmitting, rescheduleError,
  openReschedule, onRescheduleNewStartChange, submitReschedule,
  showMakeupSlotsModal, makeupLoading, makeupDateRange, availableMakeupSlots,
  makeupSlotsGrouped, fetchMakeupSlots, selectMakeupSlot,
  pendingMakeupsByCourse, fetchPendingMakeups, cancelMakeupSchedule,
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

function toggleDatesAndMakeups(c) {
  const wasExpanded = expandedDates.value.has(c.id);
  toggleDates(c);
  if (!wasExpanded) fetchPendingMakeups(c).catch(() => {});
}

function formatMakeupDate(ms) {
  const days = ['日', '一', '二', '三', '四', '五', '六'];
  const d = new Date(ms.schedule_date + 'T00:00:00');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  const dow = days[d.getDay()] ?? '';
  const start = (ms.start_time || '').slice(0, 5);
  const end = (ms.end_time || '').slice(0, 5);
  return `${mm}/${dd}（${dow}）${start}${end ? '–' + end : ''}`;
}


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
  chipActionDialog, closeChipActionDialog, confirmChipActionDialog,
} = useSessionEditFlow({
  supabase,
  branchId: computed(() => props.branchId),
  computeEndTime,
  normalizeTo30Min,
  dayOfWeekFromDate,
  getSessionDisplayRow,
  getSessionRowsForDate,
  reloadCourseSessions,
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
  // in-app #205 / #203 family: student_id drives exclude_student_id on availability
  // so the candidate is not blocked by this student's own scheduled occupancy.
  const studentId = Number(
    form.student_id ?? course.student_id ?? course.StudentID ?? 0
  ) || null;
  substituteV2Context.value = {
    student_id: studentId,
    student_name: form.student_name || course.student_name || '',
    subject_id: course.subject_id || null,
    subject_label: getSubjectLabel(form.subject || course.subject) || '',
    class_type: form.class_type || course.class_type || '',
    session_date: form.session_date || '',
    start_time: (form.start_time || '').toString().slice(0, 5),
    end_time: (form.end_time || '').toString().slice(0, 5),
    current_teacher_id: form.teacher_id ?? null,
    current_teacher_name: form.teacher_name || '',
    original_teacher_id: course.teacher_id ?? null,
    original_teacher_name: course.teacher_name || '',
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
    if (!token) {
      substituteV2PickerRef.value?.setError?.('請重新登入');
      return;
    }
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
      return;
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
      const patch = sessionViewModelPatchFromApi({
        id: sessionId,
        teacher_id: json.substitute_teacher_id,
        teacher_name: json.substitute_teacher_name || teacherObj?.username || '',
        session_date: isCombined ? effDate : undefined,
        start_time: isCombined ? effStart : undefined,
        end_time: isCombined ? effEnd : undefined,
      });
      if (patch) updateLocalSessionRow(courseKey, patch);
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
        const undoPatch = sessionViewModelPatchFromApi({
          id: sessionId,
          teacher_id: ctx.original_teacher_id,
          teacher_name: ctx.original_teacher_name,
          session_date: isCombined ? origDate : undefined,
          start_time: isCombined ? origStart : undefined,
          end_time: isCombined ? origEnd : undefined,
        });
        if (undoPatch) updateLocalSessionRow(courseKey, undoPatch);
        await loadCourses();
      },
    });
    await loadCourses();
  } catch (e) {
    substituteV2PickerRef.value?.setError?.(e?.message || '代課設定失敗');
    // Expected validation/business rejection should stay in form state, not global error channel.
    console.warn('[CourseManagement] substitute submit failed', e);
  }
};

const togglePaymentStatus = async (c) => {
  if (!c?.id) return;

  if (c.payment_status === 'pending_report') {
    alert('此課程已有待對帳回報，請到帳務中心確認入帳或退回後再登錄。');
    return;
  }

  // 未繳費 → 已回報：走登記 Modal（強制填繳款日期）；確認入帳後才變已繳費
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

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('登入狀態已過期，請重新登入後再試。');
      return;
    }
    const res = await fetch(`/api/v1/student-classes/${c.id}`, {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ payment_status: 'unpaid', paid_at: null }),
    });
    // #799 阻擋＋導引：有收款入帳紀錄時後端回 409，提示去收費頁作廢，不再靜默回跳
    if (res.status === 409) {
      const errBody = await res.json().catch(() => ({}));
      const w = errBody?.warnings || {};
      const amount = Number(w.total_paid_amount || 0).toLocaleString();
      alert(errBody?.message || [
        `此課程已有收款入帳紀錄（${w.last_paid_at || ''} 共 NT$ ${amount}），無法直接改為未繳費。`,
        '若該筆收款是誤登錄，請至「收費」頁將該帳單作廢，狀態會自動恢復為未繳費。',
      ].join('\n'));
      return;
    }
    if (!res.ok) {
      const errBody = await res.json().catch(() => ({}));
      alert(errBody?.message || '改為未繳費失敗，請稍後再試。');
      return;
    }
    c.payment_status = 'unpaid';
    c.paid_at = null;
    c.last_paid_at = null;
  } catch (_) {
    alert('網路連線異常，狀態尚未變更，請稍後再試。');
  }
};

const onPaymentEntryConfirmed = async () => {
  paymentEntryOpen.value = false;
  if (invoiceModalOpen.value) {
    closeInvoiceModal();
  }
  alert('已送出待對帳。請到帳務中心按確認入帳後才會開電子收據。');
  await loadCourses();
  for (const group of visibleGroups.value || []) {
    if (studentGroupTab(group.key) === 'billing') {
      await loadStudentGroupBilling(group);
    }
  }
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

    if (shouldClearTeacherSelection(backfillForm.value.teacher_id, teachers.value)) {
      backfillForm.value.teacher_id = '';
    }
    if (shouldClearTeacherSelection(editForm.value?.teacher_id, teachers.value, { isEditing: showEditModal.value })) {
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
  const end = String(f.end_date || '').slice(0, 10);
  const mode = String(f.payment_type || 'session');
  return `${days}|${slots}|${dur}|${start}|${first}|${end}|${mode}`;
}

const editCourse = (c) => {
  editingId.value = c.id;
  editSaveError.value = null;
  editability.value = null;
  editabilityError.value = '';
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
    original_teacher_id: c.teacher_id || '',
    start_time: normalizeTo30Min(c.start_time || '16:00'),
    end_time: c.end_time || '',
    payment_type: c.payment_type || 'session',
    settlement_day: c.settlement_day ?? null,
    monthly_sessions: c.monthly_sessions ?? null,
    first_class_date: c.first_class_date || '',
    end_date: c.end_date || (c.EndDate ? String(c.EndDate).slice(0, 10) : ''),
    scheduling_policy: c.scheduling_policy || 'auto_recurrence',
    room_id: c.room_id ?? null,
    memo: c.memo ?? c.Memo ?? '',
    paid_at: c.paid_at || c.last_paid_at || '',
    original_paid_at: c.paid_at || c.last_paid_at || ''
  };
  originalFirstClassDate.value = c.first_class_date || '';
  loadRoomsForBranch();
  showEditModal.value = true;
  if (editingCourseFromLaravel.value) void loadCourseEditability(c.id);
  nextTick(() => {
    editScheduleBaseline.value = scheduleFingerprintForEdit(editForm.value);
  });
};

const submitEdit = async () => {
  const id = editingId.value;
  const form = editForm.value;
  editSaveError.value = null;
  if (editingCourseFromLaravel.value) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const endTime = computeEndTime(form.start_time, form.duration_hours);
        const isPackageCourse = !!editingCourseRaw.value?.PackageID;
        // A memo/payment/teacher edit must not be interpreted as a schedule
        // edit. Sending the schedule fields on every save caused the backend
        // to reconcile or rebuild future projected sessions even when the
        // director only added a note (#231).
        const baseline = editScheduleBaseline.value;
        const scheduleChanged = baseline != null && scheduleFingerprintForEdit(form) !== baseline;
        const scheduleFields = scheduleChanged ? {
          duration_hours: form.duration_hours,
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
          first_class_date: form.first_class_date || null,
          end_date: form.payment_type === 'monthly' ? form.end_date || null : null,
          force_rebuild_if_mismatch: true,
        } : {};
        const body = {
          subject: form.subject,
          teacher_id: form.teacher_id || null,
          class_type: form.class_type,
          rate_per_30min: form.rate_per_30min,
          sessions_purchased: form.sessions_purchased,
          ...(isPackageCourse ? {} : { remaining_sessions: form.remaining_sessions }),
          payment_type: form.payment_type,
          scheduling_policy: form.scheduling_policy || 'auto_recurrence',
          settlement_day: form.payment_type === 'monthly' ? form.settlement_day : null,
          monthly_sessions: form.payment_type === 'monthly' ? form.monthly_sessions : null,
          room_id: form.room_id || null,
          Memo: form.memo || null,
          ...scheduleFields,
        };
        if (String(form.paid_at || '') !== String(form.original_paid_at || '')) {
          body.paid_at = form.paid_at ? form.paid_at : null;
        }
        const res = await fetch(`/api/v1/student-classes/${id}`, {
          method: 'PUT',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify(body)
        });
        if (res.ok) {
          const payload = await res.json().catch(() => ({}));
          const sync = payload?.session_sync || {};
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
        const details = err?.errors
          ? Object.values(err.errors).flat().filter(Boolean).join(' ')
          : '';
        editSaveError.value = {
          message: err?.message || '更新失敗，請檢查欄位後再試。',
          details,
          hint: editabilityNextStepLabel(editabilityNextStepForError(err)),
        };
        return;
      }
    } catch (e) {
      editSaveError.value = {
        message: '連線失敗，請稍後再試。',
        details: e?.message || '',
      };
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
    editSaveError.value = {
      message: '更新失敗，請檢查欄位後再試。',
      details: error?.message || '',
    };
    return;
  }
  editScheduleBaseline.value = null;
  showEditModal.value = false;
  await loadCourses();
  alert('課程已更新。');
};

const confirmDeleteTarget = ref(null);
const deleteCourseSubmitting = ref(false);
const paymentEntryOpen = ref(false);
const paymentEntryRow = ref(null);
const ledgerOpen = ref(false);
const ledgerStudentClassId = ref(null);
const studentGroupTabs = ref({});
const studentBillingState = ref({});
const invoiceModalOpen = ref(false);
const invoiceModalCourse = ref(null);
const invoiceModalList = ref([]);
const invoiceModalLoading = ref(false);
const invoiceModalError = ref('');
const invoiceVoidTarget = ref(null);
const invoiceVoidReason = ref('');
const invoiceVoidMode = ref('direct');
const invoiceVoidSubmitting = ref(false);

const closeInvoiceModal = () => {
  invoiceModalOpen.value = false;
};

const studentGroupTab = (key) => studentGroupTabs.value[key] || 'courses';

const setStudentGroupTab = async (group, tab) => {
  studentGroupTabs.value = { ...studentGroupTabs.value, [group.key]: tab };
  if (tab === 'billing') {
    await loadStudentGroupBilling(group);
  }
};

const selectStudentGroupTab = async (group, tab, event) => {
  event?.stopPropagation?.();
  const next = new Set(expandedStudentGroups.value);
  next.add(group.key);
  expandedStudentGroups.value = next;
  await setStudentGroupTab(group, tab);
};

const loadStudentGroupBilling = async (group) => {
  const key = group?.key;
  if (!key) return;
  studentBillingState.value = {
    ...studentBillingState.value,
    [key]: { loading: true, error: '', rows: studentBillingState.value[key]?.rows || [] },
  };
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      studentBillingState.value = {
        ...studentBillingState.value,
        [key]: { loading: false, error: '請重新登入後再查看帳務。', rows: [] },
      };
      return;
    }
    const courses = [...activeCourses(group), ...historyCourses(group)];
    const rows = await Promise.all(courses.map(async (c) => {
      const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` };
      const [invRes, rptRes] = await Promise.all([
        fetch(`/api/v1/student-classes/${c.id}/invoices`, { credentials: 'include', headers }),
        fetch(`/api/v1/payment-reports?student_class_id=${c.id}`, { credentials: 'include', headers }),
      ]);
      const invJson = await invRes.json().catch(() => ({}));
      const rptJson = await rptRes.json().catch(() => ({}));
      return {
        course: c,
        invoices: Array.isArray(invJson?.invoices) ? invJson.invoices : [],
        reports: Array.isArray(rptJson?.data) ? rptJson.data : [],
      };
    }));
    studentBillingState.value = { ...studentBillingState.value, [key]: { loading: false, error: '', rows } };
  } catch (e) {
    studentBillingState.value = {
      ...studentBillingState.value,
      [key]: { loading: false, error: e?.message || '帳務載入失敗，請稍後再試。', rows: [] },
    };
  }
};

const openLedgerForCourse = (course) => {
  if (!course?.id) return;
  ledgerStudentClassId.value = course.id;
  ledgerOpen.value = true;
};

const openInvoiceModal = async (course) => {
  if (!course?.id) return;
  invoiceModalCourse.value = course;
  invoiceModalList.value = [];
  invoiceModalError.value = '';
  invoiceModalLoading.value = true;
  invoiceModalOpen.value = true;

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      invoiceModalError.value = '請重新登入後再查看帳單。';
      return;
    }

    const res = await fetch(`/api/v1/student-classes/${course.id}/invoices`, {
      credentials: 'include',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      invoiceModalError.value = json?.message || '帳單載入失敗，請稍後再試。';
      return;
    }
    invoiceModalList.value = Array.isArray(json?.invoices) ? json.invoices : [];
  } catch (e) {
    invoiceModalError.value = e?.message || '帳單載入失敗，請稍後再試。';
  } finally {
    invoiceModalLoading.value = false;
  }
};

const canVoidInvoice = (invoice) => {
  if (!invoice) return false;
  if (invoice.can_direct_void === true) return true;
  const status = String(invoice.status || '').toLowerCase();
  const paidAmount = Number(invoice.paid_amount ?? 0) || 0;
  const hasPayment = Array.isArray(invoice.payments)
    ? invoice.payments.some((payment) => Number(payment?.amount ?? 0) > 0 && String(payment?.method || '') !== 'void')
    : Number(invoice.payment_count ?? 0) > 0;
  return !['paid', 'partial', 'void'].includes(status) && paidAmount === 0 && !hasPayment;
};
const canExceptionVoidInvoice = (invoice) => {
  if (!invoice) return false;
  const status = String(invoice.status || '').toLowerCase();
  return status !== 'void' && invoice.can_exception_void === true;
};

const openInvoiceVoidDialog = (invoice) => {
  if (!canVoidInvoice(invoice)) {
    toastRef.value?.show?.({
      title: '不可直接作廢',
      description: '此帳單已有收款或狀態不是未繳，請改走「撤銷收款」。',
      variant: 'warning',
      durationMs: 5000,
    });
    return;
  }
  invoiceVoidTarget.value = invoice;
  invoiceVoidMode.value = 'direct';
  invoiceVoidReason.value = '';
};

const openInvoiceExceptionVoidDialog = (invoice) => {
  if (!canExceptionVoidInvoice(invoice)) {
    toastRef.value?.show?.({
      title: '不可更正並作廢',
      description: '此帳單沒有收款痕跡或不是帳務例外，請使用一般作廢流程。',
      variant: 'warning',
      durationMs: 5000,
    });
    return;
  }
  invoiceVoidTarget.value = invoice;
  invoiceVoidMode.value = 'exception';
  invoiceVoidReason.value = '';
};

const closeInvoiceVoidDialog = () => {
  if (invoiceVoidSubmitting.value) return;
  invoiceVoidTarget.value = null;
  invoiceVoidReason.value = '';
  invoiceVoidMode.value = 'direct';
};

const submitInvoiceVoid = async () => {
  const invoice = invoiceVoidTarget.value;
  const reason = invoiceVoidReason.value.trim();
  if (!invoice?.id || reason.length < 3 || invoiceVoidSubmitting.value) return;

  invoiceVoidSubmitting.value = true;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      toastRef.value?.show?.({ title: '請重新登入', description: '登入逾時，請重新登入後再作廢帳單。', variant: 'error', durationMs: 5000 });
      return;
    }

    const path = invoiceVoidMode.value === 'exception' ? 'exception-void' : 'void';
    const res = await fetch(`/api/v1/invoices/${invoice.id}/${path}`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({ reason }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      toastRef.value?.show?.({ title: '作廢失敗', description: json?.message || '帳單作廢失敗，請稍後再試。', variant: 'error', durationMs: 6000 });
      return;
    }

    const periodLabel = formatBillingPeriod(invoice.billing_period);
    toastRef.value?.show?.({
      title: invoiceVoidMode.value === 'exception' ? '已更正並作廢帳單' : '已作廢帳單',
      description: `${periodLabel} 帳單已作廢並排除應收。`,
      variant: 'success',
      durationMs: 5000,
    });
    invoiceVoidTarget.value = null;
    invoiceVoidReason.value = '';
    invoiceVoidMode.value = 'direct';
    if (invoiceModalCourse.value) {
      await openInvoiceModal(invoiceModalCourse.value);
    }
    await loadCourses(pagination.value.page || 1);
  } catch (e) {
    toastRef.value?.show?.({ title: '作廢失敗', description: e?.message || '帳單作廢失敗，請稍後再試。', variant: 'error', durationMs: 6000 });
  } finally {
    invoiceVoidSubmitting.value = false;
  }
};

const openPaymentEntryForInvoice = (invoice) => {
  const course = invoiceModalCourse.value;
  if (!course?.id || !invoice?.id) return;
  paymentEntryRow.value = {
    id: course.id,
    invoice_id: invoice.id,
    student_name: course.student_name || '此學生',
    subject: course.subject_name || course.subject || '',
    billing_period: formatBillingPeriod(invoice.billing_period),
    charge: Number(invoice.total_amount ?? course.Charge ?? course.charge ?? 0) || 0,
  };
  invoiceModalOpen.value = false;
  paymentEntryOpen.value = true;
};

const executeDeleteCourse = async () => {
  if (deleteCourseSubmitting.value) return;
  const c = confirmDeleteTarget.value;
  if (!c) return;
  deleteCourseSubmitting.value = true;
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
          confirmDeleteTarget.value = null;
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
    } finally {
      deleteCourseSubmitting.value = false;
    }
  }
  try {
    await supabase.from('student-classes').delete().eq('id', c.id);
    confirmDeleteTarget.value = null;
    courses.value = courses.value.filter(x => x.id !== c.id);
  } finally {
    deleteCourseSubmitting.value = false;
  }
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
  try {
    const raw = sessionStorage.getItem('alltrue_ops_trust_focus');
    if (raw) {
      const focus = JSON.parse(raw);
      const ageMs = Date.now() - Number(focus?.at || 0);
      if (ageMs >= 0 && ageMs < 5 * 60 * 1000 && focus?.student_name) {
        filters.value.name = String(focus.student_name).slice(0, 40);
      }
      sessionStorage.removeItem('alltrue_ops_trust_focus');
    }
  } catch (_) { /* ignore */ }
  loadCourses(); loadStudents(); loadTeachers(); loadSubjects();
  document.addEventListener('click', closeActionMenu);
});
onUnmounted(() => {
  document.removeEventListener('click', closeActionMenu);
  clearTimeout(_quickAddCheckTimer);
  quickAddCheckController?.abort();
  manualSessionCheckController?.abort();
  // #143：聚焦單一學生時會 lockScroll（body position:fixed/overflow:hidden）。
  // 若使用者在「聚焦中」直接切換頁面，元件卸載不會觸發 focusedStudentKey watcher，
  // scroll lock 會洩漏並殘留在 body，導致之後的頁面看起來像蓋了一層灰白遮罩、無法點選/捲動。
  // 卸載時若仍處於聚焦狀態，補一次解除以平衡計數。
  if (focusedStudentKey.value) unlockScroll();
});
</script>

<style scoped>
.course-page {
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
  padding: 0 12px 24px;
  box-sizing: border-box;
  position: relative;
}
/* ----- Page header ----- */
.course-header-card {
  position: relative;
  padding: 22px;
  border-radius: 16px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
  box-shadow: var(--ds-shadow-1);
  color: var(--ds-ink);
}

.header-actions {
  position: relative;
  z-index: 1;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}

.page-title-block {
  min-width: 0;
}

.course-lens-kicker {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.course-lens-badge {
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  padding: 3px 9px;
  border: 1px solid var(--ds-primary);
  border-radius: var(--ds-radius-pill);
  background: var(--ds-primary-wash);
  color: var(--ds-primary-deep);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
}

.course-lens-primary-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  min-height: 40px;
  padding: 9px 14px;
  border: 1px solid var(--ds-cta);
  border-radius: var(--ds-radius-pill);
  background: var(--ds-cta);
  color: var(--ds-on-cta);
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: var(--transition);
}

.course-lens-primary-action:hover {
  border-color: var(--ds-cta-hover);
  background: var(--ds-cta-hover);
}

.course-lens-guidance {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-top: 18px;
  padding: 12px 14px;
  border: 1px solid var(--ds-hairline);
  border-left: 3px solid var(--ds-primary);
  border-radius: var(--ds-radius-lg);
  background: var(--ds-primary-wash);
  color: var(--ds-ink-secondary);
  font-size: 13px;
  line-height: 1.5;
}

.course-lens-guidance__icon {
  flex: 0 0 auto;
  color: var(--ds-primary-deep);
  font-size: 20px;
}

.course-lens-guidance strong {
  margin-right: 6px;
  color: var(--ds-ink);
}

.course-lens-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  margin-top: 16px;
}

.course-lens-metric {
  display: grid;
  min-width: 0;
  gap: 4px;
  padding: 14px 16px;
  border: 1px solid var(--ds-hairline);
  border-top: 3px solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg);
  background: var(--ds-canvas);
  box-shadow: var(--ds-shadow-1);
}

.course-lens-metric--success { border-top-color: var(--ds-success); }
.course-lens-metric--warning { border-top-color: var(--ds-warning); }
.course-lens-metric--info { border-top-color: var(--ds-primary); }

.course-lens-metric__label {
  color: var(--ds-ink-mute);
  font-size: 12px;
  font-weight: 700;
}

.course-lens-metric__value {
  color: var(--ds-ink);
  font-size: 26px;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.course-lens-metric__hint {
  overflow-wrap: anywhere;
  color: var(--ds-ink-secondary);
  font-size: 12px;
  line-height: 1.35;
}

.command-kicker {
  margin: 0 0 4px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--ds-ink-mute);
}

.page-title {
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: 700;
  color: var(--ds-ink);
  margin-bottom: 6px;
  letter-spacing: -0.01em;
  line-height: 1.15;
}

.ref-hint {
  color: var(--ds-ink-mute);
  font-size: 14px;
  margin-top: 8px;
  font-weight: 500;
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
  font-weight: 600;
  color: var(--ds-ink-secondary);
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
}

.pending-leave-summary {
  display: grid;
  gap: 12px;
  margin: 14px 0;
  padding: 14px 16px;
  border: 1px solid var(--ds-hairline);
  border-left: 4px solid var(--ds-primary);
  border-radius: 14px;
  background: var(--ds-canvas);
  box-shadow: 0 4px 16px rgba(15, 35, 64, 0.05);
}
.pending-leave-summary__header {
  display: flex;
  align-items: center;
  gap: 12px;
}
.pending-leave-summary__icon {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  flex: 0 0 36px;
  border-radius: 10px;
  background: var(--ds-canvas);
  color: var(--ds-primary-deep);
}
.pending-leave-summary__body {
  display: grid;
  gap: 3px;
  min-width: 0;
  flex: 1;
}
.pending-leave-summary__body strong { color: var(--ds-ink); }
.pending-leave-summary__body span { color: var(--ds-ink-secondary); font-size: 13px; line-height: 1.4; }
.pending-leave-summary__error { color: var(--ds-danger) !important; }
.pending-leave-summary__cta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  flex: 0 0 auto;
  min-height: 40px;
  padding: 9px 14px;
  border: 1px solid var(--ds-primary);
  border-radius: 10px;
  background: var(--ds-primary);
  color: var(--ds-on-primary);
  font-weight: 700;
  cursor: pointer;
  transition: var(--transition);
}
.pending-leave-summary__cta:hover { background: var(--ds-primary-deep); border-color: var(--ds-primary-deep); }
.pending-leave-summary__cta--all { margin-left: auto; }
.pending-leave-case-list { display: grid; gap: 8px; }
.pending-leave-case {
  display: flex;
  align-items: center;
  gap: 16px;
  min-width: 0;
  padding: 12px 14px;
  border: 1px solid var(--ds-hairline);
  border-radius: 10px;
  background: var(--ds-canvas-soft);
}
.pending-leave-case__content { min-width: 0; flex: 1; }
.pending-leave-case__title-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pending-leave-case__title-row strong { color: var(--ds-ink); }
.pending-leave-case__status { color: var(--ds-primary-deep); font-size: 12px; font-weight: 700; }
.pending-leave-case p { margin: 4px 0 0; color: var(--ds-ink-secondary); font-size: 13px; line-height: 1.4; overflow-wrap: anywhere; }
.pending-leave-case__reason { color: var(--ds-ink-mute) !important; }
.pending-leave-case__cta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  gap: 6px;
  min-height: 40px;
  padding: 8px 12px;
  border: 1px solid var(--ds-primary);
  border-radius: 9px;
  background: var(--ds-primary);
  color: var(--ds-on-primary);
  font-weight: 700;
  cursor: pointer;
}
.pending-leave-case__cta:hover { background: var(--ds-primary-deep); }
.pending-leave-summary__more { justify-self: start; border: 0; padding: 2px 0; background: transparent; color: var(--ds-primary-deep); font-weight: 700; cursor: pointer; }
.pending-leave-summary__error-actions { display: flex; }
.pending-leave-case--skeleton { height: 66px; background: linear-gradient(90deg, var(--ds-canvas-soft) 25%, var(--ds-canvas) 50%, var(--ds-canvas-soft) 75%); background-size: 200% 100%; animation: pending-leave-shimmer 1.2s infinite; }
@keyframes pending-leave-shimmer { to { background-position: -200% 0; } }
@media (max-width: 700px) {
  .pending-leave-summary__header { align-items: flex-start; flex-wrap: wrap; }
  .pending-leave-summary__cta--all { width: 100%; margin-left: 0; }
  .pending-leave-case { align-items: stretch; flex-direction: column; gap: 10px; }
  .pending-leave-case__cta { width: 100%; }
}

.header-buttons {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.btn-soft {
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
  color: var(--ds-ink-secondary);
  border-radius: 999px;
  padding: 9px 14px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: var(--transition);
}

.btn-soft:hover {
  border-color: var(--ds-hairline-input);
  color: var(--ds-ink);
  background: var(--ds-canvas-soft);
}

.btn-accent {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--ds-primary);
  color: var(--ds-on-primary);
  border: none;
  padding: 10px 18px;
  border-radius: 999px;
  font-weight: 600;
  font-size: 14px;
  cursor: pointer;
  transition: var(--transition);
}

.btn-accent:hover {
  background: var(--ds-primary-deep);
}

.btn-icon {
  font-size: 1em;
}

/* ----- Filters (Epic D denser ops) ----- */
.filter-bar {
  margin-top: 12px;
  padding: 10px 12px;
  border: 1px solid var(--ds-hairline);
  border-radius: 10px;
  background: var(--ds-canvas-soft);
  position: relative;
  z-index: 1;
}

.filter-bar.grid {
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 8px 10px;
}

.filter-field label {
  font-size: 11px;
  font-weight: 600;
  color: var(--ds-ink-mute);
  margin-bottom: 4px;
}

.filter-field input,
.filter-field select {
  padding: 7px 10px;
  border-radius: 6px;
  font-size: 13px;
  border: 1px solid var(--ds-hairline-input);
  background: var(--ds-canvas);
  color: var(--ds-ink);
}

.course-filter-clear {
  align-self: end;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  min-height: 34px;
  padding: 7px 11px;
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-pill);
  background: var(--ds-canvas);
  color: var(--ds-ink-secondary);
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: var(--transition);
}

.course-filter-clear:hover {
  border-color: var(--ds-primary);
  background: var(--ds-primary-wash);
  color: var(--ds-primary-deep);
}

.course-lens-primary-action:focus-visible,
.course-filter-clear:focus-visible,
.btn-soft:focus-visible {
  outline: 3px solid var(--ds-focus-ring);
  outline-offset: 2px;
}

/* ----- Compact stats strip ----- */
.creation-success-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  margin-bottom: 12px;
  background: var(--ds-success-wash);
  border: 1px solid var(--ds-success);
  border-radius: 8px;
  color: var(--ds-success);
  font-size: 0.95rem;
  font-weight: 500;
}
.creation-success-banner__icon {
  font-size: 1.2rem;
  color: var(--ds-success);
  flex-shrink: 0;
}
.creation-success-banner__close {
  margin-left: auto;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--ds-success);
  font-size: 1rem;
  opacity: 0.7;
  padding: 0 4px;
}
.creation-success-banner__close:hover { opacity: 1; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.stats-strip {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: minmax(160px, 1.35fr) repeat(5, minmax(96px, 1fr)) minmax(180px, 1.8fr);
  gap: 10px;
  margin-top: 16px;
  padding: 12px;
  border-radius: 12px;
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
  color: var(--ds-ink-mute);
}

.stats-orb {
  position: relative;
  display: grid;
  gap: 2px;
  padding: 12px 13px;
  min-height: 82px;
  border-radius: 12px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
}
.stats-orb-total {
  border-bottom: 3px solid var(--ds-primary);
}
.stats-orb-label,
.stats-subject-title {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ds-ink-mute);
}
.stats-orb-num {
  font-size: 28px;
  font-weight: 700;
  line-height: 1;
  color: var(--ds-ink);
  font-variant-numeric: tabular-nums;
}
.stats-orb-caption {
  font-size: 12px;
  font-weight: 600;
  color: var(--ds-ink-mute);
}

.stats-subject-deck {
  display: flex;
  flex-wrap: wrap;
  align-content: center;
  gap: 7px;
  padding: 12px;
  border-radius: 12px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
}
.stats-subject-title {
  flex: 0 0 100%;
}
.stats-strip-subject {
  padding: 4px 9px;
  border-radius: 999px;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-secondary);
  border: 1px solid var(--ds-hairline);
  font-size: 11.5px;
  font-weight: 600;
}

@media (max-width: 980px) {
  .course-header-card {
    padding: 18px;
  }
  .header-actions {
    align-items: flex-start;
    flex-direction: column;
  }
  .header-buttons {
    width: 100%;
    justify-content: flex-start;
  }
  .course-lens-primary-action {
    margin-right: auto;
  }
  .course-lens-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .stats-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .stats-orb-total,
  .stats-subject-deck {
    grid-column: 1 / -1;
  }
}

/* ----- Table ----- */
.table-card {
  padding: 0;
  overflow: visible;
  margin-top: 16px;
  border-radius: 12px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
  box-shadow: var(--ds-shadow-1);
}

.table-wrap {
  overflow-x: auto;
  max-height: 70vh;
  overflow-y: auto;
}

.grouped-course-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.course-list-skeleton {
  display: grid;
  gap: 14px;
}
.course-skeleton-group {
  position: relative;
  overflow: hidden;
  padding: 14px;
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  background: var(--ds-canvas);
  box-shadow: var(--ds-shadow-1);
}
.course-skeleton-header,
.course-skeleton-row {
  height: 16px;
  border-radius: 999px;
  background: linear-gradient(90deg, var(--ds-canvas-soft) 25%, var(--ds-hairline) 37%, var(--ds-canvas-soft) 63%);
  background-size: 400% 100%;
  animation: course-loading 1.4s ease infinite;
}
.course-skeleton-header {
  width: 34%;
  height: 18px;
  margin-bottom: 14px;
}
.course-skeleton-row {
  width: 86%;
  margin-top: 10px;
}
.course-skeleton-row.short {
  width: 58%;
}
@keyframes course-loading {
  0% { background-position: 100% 50%; }
  100% { background-position: 0 50%; }
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
  border-top: 1px solid var(--ds-hairline);
  font-size: 0.9rem;
  color: var(--ds-ink-mute);
}
.pagination-controls {
  display: flex;
  align-items: center;
  gap: 8px;
}
.pagination-btn { min-width: 80px; }
.pagination-btn:disabled { opacity: 0.4; cursor: default; }
.pagination-current { font-weight: 600; color: var(--ds-ink); }

.student-group-card {
  position: relative;
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  overflow: visible;
  background: var(--ds-canvas);
  box-shadow: var(--ds-shadow-1);
  transition: border-color 0.18s ease;
}
.student-group-card:hover {
  border-color: var(--ds-hairline-input);
}

.student-group-header {
  width: 100%;
  border: none;
  background: var(--ds-canvas-soft);
  padding: 16px 18px 13px;
  border-radius: 12px 12px 0 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  cursor: pointer;
}

.student-group-header:hover {
  background: var(--ds-canvas-soft);
}
.student-group-header:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--ds-primary) 28%, transparent);
  outline-offset: -3px;
}

.student-group-left {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.expand-indicator {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 999px;
  color: var(--ds-ink);
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
  font-size: 12px;
  text-align: center;
}

.student-group-meta {
  font-size: 13px;
  color: var(--ds-ink-mute);
  font-weight: 600;
  white-space: nowrap;
  padding: 5px 10px;
  border-radius: 999px;
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
}

.student-group-header-actions {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
  flex-shrink: 0;
}

.focus-btn {
  margin-left: 0;
  padding: 3px 9px;
  border: 1px solid var(--ds-hairline);
  border-radius: 999px;
  background: var(--ds-canvas);
  color: var(--ds-ink-mute);
  font-size: 13px;
  cursor: pointer;
  flex-shrink: 0;
}
.focus-btn:hover, .focus-btn.active {
  background: var(--ds-primary-wash);
  border-color: var(--ds-primary);
  color: var(--ds-primary-deep);
}
.focus-mode-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 14px;
  margin-bottom: 8px;
  background: var(--ds-info-wash);
  border: 1px solid var(--ds-primary);
  border-radius: 8px;
  font-size: 13px;
  color: var(--ds-primary-deep);
}
.focus-mode-banner button {
  font-size: 12px;
  padding: 3px 10px;
  border: 1px solid var(--ds-primary);
  border-radius: 999px;
  background: var(--ds-canvas);
  color: var(--ds-primary-deep);
  cursor: pointer;
}
.focus-mode-banner button:hover { background: var(--ds-primary-wash); }
.student-group-has-paused {
  box-shadow: inset 0 0 0 1px var(--ds-warning);
  border-radius: 10px;
}

.student-group-paused-badge {
  margin-left: 8px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: var(--ds-warning);
  background: var(--ds-warning-wash);
  border: 1px solid var(--ds-warning);
  border-radius: 999px;
  padding: 2px 10px;
  vertical-align: middle;
}

.student-group-add-row {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding: 8px 12px 6px;
  background: var(--ds-canvas-soft);
  border-bottom: 1px solid var(--ds-hairline);
}

.student-group-view-tabs {
  display: flex;
  width: 100%;
  gap: 0;
  padding: 0 10px;
  background: var(--ds-canvas);
  border-top: 1px solid var(--ds-hairline);
}
.student-group-view-tab {
  flex: 1;
  border: none;
  background: transparent;
  color: var(--ds-ink);
  font-size: 15px;
  font-weight: 700;
  padding: 11px 12px;
  cursor: pointer;
}
.student-group-view-tab.active {
  color: var(--ds-ink);
  background: var(--ds-primary-wash);
  box-shadow: inset 0 -3px 0 var(--ds-primary);
}
.student-billing-state {
  padding: 16px 12px;
  color: var(--ds-ink-secondary);
  font-size: 13px;
}
.student-billing-error { color: var(--ds-danger); }
.student-billing-table { min-width: 640px; }

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
  border-collapse: separate;
  border-spacing: 0 4px;
  font-size: 13px;
  padding: 0 8px 8px;
  font-variant-numeric: tabular-nums;
}

.course-table thead {
  position: sticky;
  top: 0;
  z-index: 2;
  background: var(--ds-canvas-soft);
  border-bottom: none;
}
@media (min-width: 641px) {
  .course-table thead {
    background: var(--ds-canvas-soft);
    backdrop-filter: blur(6px);
  }
}

.course-table th {
  padding: 8px 8px 4px;
  text-align: left;
  font-weight: 700;
  color: var(--ds-ink-secondary);
  white-space: nowrap;
}

.course-table td {
  padding: 8px;
  border-top: 1px solid var(--ds-hairline);
  border-bottom: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
  vertical-align: middle;
  word-break: keep-all;
  line-height: 1.35;
}
.course-table .course-row td:first-child {
  border-left: 1px solid var(--ds-hairline);
  border-radius: 10px 0 0 10px;
  box-shadow: inset 3px 0 0 var(--ds-primary);
}
.course-table .course-row td:last-child {
  border-right: 1px solid rgba(226, 232, 240, 0.82);
  border-radius: 0 10px 10px 0;
}

.course-row:hover {
  background: transparent;
}
.course-row:hover td {
  border-color: rgba(14, 165, 233, 0.28);
  background: linear-gradient(90deg, rgba(240,249,255,0.98), rgba(255,255,255,0.96));
  box-shadow: 0 14px 32px rgba(15, 23, 42, 0.07);
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

/* #2007 phase 2: was font-weight 800 — same visual weight as the subject/
   class-type tags above it, so nothing signalled which line was primary.
   Demoted so the tags read first, price second (Cal.com text-emphasis vs
   text-subtle pattern). */
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

.payment-summary-line {
  margin-top: 4px;
  font-size: 12px;
  color: var(--ds-success);
  line-height: 1.45;
  word-break: break-word;
}

.payment-summary-label {
  font-weight: 800;
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

/* ── Enterprise-style paused notice row ─────────────────────────── */
.paused-notice-row td {
  padding: 0;
  background: #fffbeb;
  border-top: 1px solid #fde68a;
  border-bottom: none;
}
.paused-notice-td {
  padding: 0 !important;
}
.paused-notice {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 14px 5px 12px;
  border-left: 3px solid #f59e0b;
  font-size: 12px;
}
.paused-notice__dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #f59e0b;
  flex-shrink: 0;
}
.paused-notice__label {
  font-weight: 700;
  color: #7c2d12;
  white-space: nowrap;
}
.paused-notice__sep {
  color: #d97706;
  font-size: 10px;
}
.paused-notice__desc {
  color: #92400e;
  flex: 1;
}
.paused-notice__btn {
  background: none;
  border: 1px solid #93c5fd;
  color: #1d4ed8;
  border-radius: 6px;
  padding: 3px 10px;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.12s, border-color 0.12s;
}
.paused-notice__btn:hover {
  background: #eff6ff;
  border-color: #60a5fa;
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
  font-weight: 900;
  color: var(--text);
  letter-spacing: 0.02em;
}

.subject-tag {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 800;
  background: var(--ds-primary-wash);
  color: var(--primary);
  border: 1px solid rgba(245, 124, 0, 0.18);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.78);
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
/* #2007 phase 2: one badge line, one tone-coded chip — replaces the old
   schedule-drift-badge/contract-exception-badge/usage-balance-warning trio
   that stacked inline after the schedule text. */
.course-row-badges {
  margin-top: 3px;
}
.row-badge {
  display: inline-block;
  font-size: 11px;
  font-weight: 600;
  border-radius: 4px;
  padding: 1px 6px;
}
.row-badge--warning {
  color: var(--ds-warning);
  background: #fef3c7;
  border: 1px solid #fcd34d;
}
.row-badge--info {
  color: #1d4ed8;
  background: #dbeafe;
  border: 1px solid #93c5fd;
}
.row-badge--danger {
  color: var(--ds-danger);
  background: var(--ds-danger-wash);
  border: 1px solid var(--ds-hairline);
}

.cell-remaining {
  font-weight: 950;
  font-size: 15px;
  color: #0f172a;
}

.cell-remaining.low {
  color: var(--danger);
  text-shadow: 0 0 18px rgba(220, 38, 38, 0.18);
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
  font-weight: 900 !important;
  letter-spacing: 0;
  padding: 6px 12px !important;
  border-radius: 999px;
  border: 1px solid rgba(15, 23, 42, 0.12) !important;
  background: linear-gradient(135deg, #0f172a, #1e293b);
  color: #e0f2fe !important;
  cursor: pointer;
  line-height: 1.2;
  box-shadow: 0 10px 22px rgba(15,23,42,0.18);
}

.action-dropdown {
  position: static;
  margin-top: 6px;
  min-width: 170px;
  max-height: 260px;
  overflow-y: auto;
  background: rgba(15, 23, 42, 0.96);
  border: 1px solid rgba(125, 211, 252, 0.22);
  border-radius: 14px;
  box-shadow: 0 22px 48px rgba(15, 23, 42, 0.34);
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
  color: #e2e8f0;
  cursor: pointer;
  white-space: nowrap;
}

.action-dropdown-item:hover {
  background: rgba(245, 124, 0, 0.10);
}

.action-dropdown-resume {
  color: var(--ds-primary-deep);
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
  font-weight: 900;
  color: #7dd3fc;
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
  border-radius: 999px !important;
  font-weight: 900 !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.72);
}

.btn-toggle {
  white-space: nowrap;
  border-radius: 999px;
  border: 1px solid rgba(15, 23, 42, 0.12);
  background: linear-gradient(135deg, #fff, #f8fafc);
  color: #0f172a;
  font-weight: 800;
}

.btn-add-session {
  white-space: nowrap;
  border-radius: 999px;
  border: 1px solid rgba(245, 124, 0, 0.36);
  background: var(--ds-primary-wash);
  color: var(--ds-primary-deep);
  font-weight: 900;
  font-size: 13px;
  padding: 5px 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.btn-add-session:hover:not(:disabled) {
  background: var(--ds-primary-soft);
  border-color: var(--ds-primary);
  transform: translateY(-1px);
  box-shadow: 0 10px 24px rgba(245, 124, 0, 0.18);
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
  position: relative;
  overflow: hidden;
  padding: 64px 24px;
  text-align: center;
  border: 1px solid rgba(14, 165, 233, 0.22);
  border-radius: 24px;
  background:
    radial-gradient(circle at top, rgba(14,165,233,0.16), transparent 36%),
    linear-gradient(135deg, rgba(15,23,42,0.05), transparent 38%),
    linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.95));
  box-shadow: 0 22px 58px rgba(15,23,42,0.08), inset 0 1px 0 rgba(255,255,255,0.82);
}
.empty-state::before {
  content: '';
  position: absolute;
  inset: 0;
  background:
    linear-gradient(rgba(15,23,42,0.035) 1px, transparent 1px),
    linear-gradient(90deg, rgba(15,23,42,0.035) 1px, transparent 1px);
  background-size: 34px 34px;
  pointer-events: none;
  mask-image: radial-gradient(circle at center, #000, transparent 72%);
}

.empty-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 72px;
  height: 72px;
  position: relative;
  border-radius: 26px;
  font-size: 2.5rem;
  margin-bottom: 12px;
  background: linear-gradient(135deg, #0f172a, #1e293b);
  border: 1px solid rgba(125, 211, 252, 0.32);
  box-shadow: 0 18px 42px rgba(15,23,42,0.22), 0 0 30px rgba(14,165,233,0.14);
  color: #e0f2fe;
}

.empty-title {
  position: relative;
  font-size: 1.2rem;
  font-weight: 900;
  color: var(--text);
  margin-bottom: 8px;
}

.empty-desc {
  position: relative;
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
.package-conversion-modal { max-width: 520px; }
.package-conversion-summary { display: grid; gap: 4px; margin: 0 0 14px; padding: 12px; border-radius: 10px; background: var(--ds-info-wash); color: var(--ds-ink); font-size: 13px; }
.package-conversion-summary span { color: var(--ds-ink-mute); }

.editability-action-panel {
  display: grid;
  gap: 10px;
  margin: 0 0 16px;
  padding: 12px;
  border: 1px solid var(--ds-warning);
  border-radius: 12px;
  background: var(--ds-warning-wash);
  color: var(--ds-ink);
}
.editability-action-panel__intro,
.editability-action-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}
.editability-action-panel__intro {
  flex-direction: column;
  gap: 3px;
  font-size: 13px;
  line-height: 1.5;
}
.editability-action-panel__intro span,
.editability-action-row__fields,
.editability-action-row__description {
  color: var(--ds-ink-mute);
  font-size: 12px;
  line-height: 1.45;
}
.editability-action-row {
  justify-content: space-between;
  padding-top: 10px;
  border-top: 1px solid color-mix(in srgb, var(--ds-warning) 30%, transparent);
}
.editability-action-row__copy {
  display: grid;
  gap: 2px;
  min-width: 0;
}
.editability-action-row__copy > strong {
  font-size: 13px;
  line-height: 1.45;
}
.editability-action-row__button {
  flex: 0 0 auto;
  white-space: nowrap;
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

.premium-danger-modal,
.pause-confirm-modal {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(125, 211, 252, 0.24);
  box-shadow: 0 30px 86px rgba(15, 23, 42, 0.34), inset 0 1px 0 rgba(255,255,255,0.12);
  background:
    radial-gradient(circle at top right, rgba(14,165,233,0.18), transparent 34%),
    linear-gradient(135deg, rgba(15,23,42,0.96), rgba(30,41,59,0.94));
  color: #e2e8f0;
}
.premium-danger-modal {
  width: min(440px, calc(100vw - 32px));
}
.premium-danger-modal::before,
.pause-confirm-modal::before {
  content: '';
  position: absolute;
  inset: 0 0 auto;
  height: 4px;
  background: linear-gradient(90deg, #38bdf8, #6366f1, #f59e0b);
}
.premium-danger-modal::before {
  background: linear-gradient(90deg, #fb7185, #ef4444, #f97316);
}
.premium-danger-header {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  margin-bottom: 14px;
}
.premium-danger-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 16px;
  color: #fecaca;
  background: linear-gradient(135deg, rgba(127,29,29,0.92), rgba(15,23,42,0.88));
  border: 1px solid rgba(248,113,113,0.42);
  box-shadow: 0 14px 34px rgba(220,38,38,0.24);
  font-weight: 900;
}
.premium-danger-kicker {
  margin: 0 0 2px;
  color: #dc2626;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}
.premium-danger-body {
  margin: 12px 0 20px;
  padding: 12px 14px;
  border: 1px solid rgba(248,113,113,0.28);
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(127,29,29,0.2), rgba(15,23,42,0.44));
  font-size: 14px;
  line-height: 1.6;
}
.premium-danger-warning {
  margin: 0;
  color: #fca5a5;
  font-size: 13px;
  font-weight: 700;
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

.status-tag {
  border: 1px solid transparent;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.75);
}
.status-tag.one_on_one { background: linear-gradient(135deg, #FFF3E0, #FFEDD5); color: #C2410C; border-color: #FDBA74; }
.status-tag.one_on_two { background: linear-gradient(135deg, #FFF8E1, #FEF3C7); color: #B45309; border-color: #FCD34D; }
.status-tag.one_on_three { background: linear-gradient(135deg, #FBE9E7, #FFE4E6); color: #BE123C; border-color: #FDA4AF; }
.status-tag.tutoring { background: linear-gradient(135deg, #E8F5E9, #DCFCE7); color: #15803D; border-color: #86EFAC; }
.status-tag.trial { background: linear-gradient(135deg, #E8EAF6, #E0E7FF); color: #4338CA; border-color: #A5B4FC; }

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

  .course-header-card {
    padding: 16px;
  }

  .course-lens-primary-action {
    width: 100%;
  }

  .course-lens-summary {
    gap: 8px;
  }

  .course-lens-metric {
    padding: 12px;
  }

  .course-lens-metric__value {
    font-size: 22px;
  }

  .course-lens-guidance {
    display: grid;
    grid-template-columns: auto 1fr;
  }

  .course-filter-clear {
    width: 100%;
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
  padding: 16px 18px;
  background:
    radial-gradient(circle at top right, rgba(14,165,233,0.12), transparent 36%),
    linear-gradient(180deg, #f8fbff, #f1f5f9);
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 16px;
  margin: 2px 10px 10px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.82);
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
  padding: 4px 9px;
  border-radius: 999px;
  background: rgba(255,255,255,0.76);
  border: 1px solid rgba(148, 163, 184, 0.18);
}

.detail-label {
  font-size: 11px;
  font-weight: 900;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.dates-row td { padding: 0; }
.pending-makeups-panel {
  margin-top: 4px;
  padding: 10px 14px;
  background: #fefce8;
  border: 1px solid #fde68a;
  border-radius: 8px;
}
.pending-makeups-title {
  font-size: 13px;
  font-weight: 700;
  color: #92400e;
  display: block;
  margin-bottom: 8px;
}
.pending-makeups-list { display: flex; flex-direction: column; gap: 6px; }
.pending-makeup-row { display: flex; align-items: center; gap: 12px; }
.pending-makeup-date { font-size: 13px; color: #44403c; flex: 1; }
.pending-makeup-cancel {
  font-size: 12px;
  padding: 3px 10px;
  border: 1px solid #ef4444;
  color: #ef4444;
  background: #fff;
  border-radius: 6px;
  cursor: pointer;
  white-space: nowrap;
}
.pending-makeup-cancel:hover { background: #fee2e2; }
.dates-panel {
  background:
    linear-gradient(180deg, rgba(248,251,255,0.98), rgba(241,245,249,0.92));
  border-top: 1px solid rgba(148, 163, 184, 0.16);
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
.session-load-error-hint {
  color: #991b1b;
  background: #fee2e2;
}
.dates-chip-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 8px;
}
.date-chip {
  background: linear-gradient(135deg, #fff, #f8fafc);
  border: 1px solid rgba(147, 197, 253, 0.9);
  border-radius: 999px;
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
  font: inherit;
  font-size: 12px;
  line-height: inherit;
}
.date-chip-clickable {
  cursor: pointer;
}
/* Projected chips: dashed affordance + "預排" label (not the same as materialized). */
.date-chip.date-chip--projected {
  opacity: 0.85;
  border-style: dashed;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
}
.date-chip.date-chip--projected .chip-date {
  color: var(--ds-ink-mute);
}
.chip-state--projected {
  color: var(--ds-ink-secondary) !important;
  background: var(--ds-canvas-soft) !important;
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
  border: 1px solid var(--ds-hairline);
  border-radius: 999px;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-secondary);
  cursor: pointer;
  white-space: nowrap;
}
.notes-toggle-btn:hover { background: var(--ds-canvas); }
.date-chip:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px rgba(14, 165, 233, 0.14);
}
.date-chip.date-chip--projected:hover {
  transform: translateY(-1px);
  box-shadow: 0 2px 6px rgba(71, 85, 105, 0.18);
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
.date-chip.exception {
  background: #eef2ff;
  border-color: #a5b4fc;
  color: #3730a3;
  font-weight: 700;
}
.date-chip.exception .chip-state {
  color: #3730a3;
  background: #c7d2fe;
}
.date-chip.over-quota {
  background: #fef2f2;
  border-color: #f87171;
  color: #991b1b;
  font-weight: 800;
}
.date-chip.over-quota .chip-state {
  color: #fff;
  background: #dc2626;
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
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fcd34d;
  border-radius: 6px;
  font-size: 11px;
  padding: 2px 7px;
  font-weight: 600;
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
  background: linear-gradient(135deg, rgba(120,53,15,0.78), rgba(15,23,42,0.86));
  color: #fef3c7;
  border: 1px solid rgba(251,191,36,0.38);
  font-weight: 800;
  flex: 0 0 auto;
  box-shadow: 0 10px 26px rgba(180,83,9,0.16);
}

.pause-confirm-icon.resume {
  background: linear-gradient(135deg, rgba(30,64,175,0.78), rgba(15,23,42,0.86));
  color: #bfdbfe;
  border-color: rgba(96,165,250,0.42);
}

.pause-impact-card {
  background: rgba(15, 23, 42, 0.44);
  border: 1px solid rgba(148, 163, 184, 0.24);
  border-radius: 14px;
  padding: 12px 14px;
  margin: 12px 0 18px;
}

.pause-impact-title {
  margin: 0 0 8px;
  color: #e0f2fe;
  font-weight: 800;
  font-size: 13px;
}

.pause-impact-card ul {
  margin: 0;
  padding-left: 18px;
  color: #cbd5e1;
  line-height: 1.7;
  font-size: 13px;
}

.btn-resume-primary {
  background: var(--ds-primary);
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
  background: linear-gradient(180deg, rgba(248,250,252,0.82), rgba(255,255,255,0.96));
}
.empty-active-courses__inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  max-width: 320px;
  margin: 0 auto;
  padding: 18px;
  border: 1px dashed rgba(148, 163, 184, 0.36);
  border-radius: 16px;
  background: rgba(255,255,255,0.74);
}
.empty-active-courses__icon {
  font-size: 32px;
  color: #94a3b8;
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
  border-top: 1px solid rgba(226, 232, 240, 0.75);
  background:
    radial-gradient(circle at top right, rgba(14,165,233,0.1), transparent 34%),
    #fafbfc;
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
  background: rgba(241, 245, 249, 0.88);
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
  background:
    linear-gradient(135deg, rgba(15,23,42,0.035), transparent 34%),
    linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.94));
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-radius: 16px;
  padding: 12px 14px;
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
  position: relative;
  border-left: 4px solid #64748b;
}
.history-course-card:hover {
  border-color: rgba(148, 163, 184, 0.55);
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
  transform: translateY(-1px);
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
  border-radius: 999px;
  font-size: 11px;
  padding: 3px 9px;
  font-weight: 900;
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
.tag-pending-report {
  background: var(--ds-warning-wash);
  color: var(--ds-warning);
  border: 1px solid var(--ds-warning);
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 3px 10px;
}
.paid-date-hint { font-size: 11px; color: #2e7d32; margin-top: 2px; white-space: nowrap; }
.btn-invoices {
  border-color: var(--ds-hairline) !important;
  color: var(--ds-ink-secondary) !important;
  background: var(--ds-canvas) !important;
}
.btn-invoices:hover {
  background: var(--ds-canvas-soft) !important;
}
.btn-ledger {
  border-color: var(--ds-hairline) !important;
  color: var(--ds-ink-secondary) !important;
  background: var(--ds-canvas) !important;
}
.btn-ledger:hover {
  background: var(--ds-canvas-soft) !important;
}
.invoice-modal {
  width: min(920px, calc(100vw - 32px));
  max-width: min(920px, calc(100vw - 32px));
  overflow-x: hidden;
}
.invoice-modal-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}
.invoice-modal-header > div:first-child {
  min-width: 0;
}
.invoice-modal-tools {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  gap: 8px;
}
.invoice-modal-header .modal-desc {
  margin: 4px 0 0;
  color: var(--text-light);
  font-size: 13px;
}
.icon-btn {
  width: 32px;
  height: 32px;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: #fff;
  color: var(--text-light);
  cursor: pointer;
  font-size: 20px;
  line-height: 1;
}
.icon-btn:hover {
  background: #f8fafc;
  color: var(--text);
}
.invoice-modal-state {
  padding: 22px 16px;
  text-align: center;
  color: var(--text-light);
  background: #f8fafc;
  border: 1px dashed var(--border);
  border-radius: 12px;
  font-size: 14px;
}
.invoice-modal-error {
  color: #b91c1c;
  background: #fef2f2;
  border-color: #fecaca;
}
.invoice-skeleton {
  height: 14px;
  margin: 8px auto;
  border-radius: 999px;
  background: linear-gradient(90deg, #e5e7eb 25%, #f3f4f6 37%, #e5e7eb 63%);
  background-size: 400% 100%;
  animation: invoice-loading 1.4s ease infinite;
  width: 88%;
}
.invoice-skeleton-short {
  width: 58%;
}
@keyframes invoice-loading {
  0% { background-position: 100% 50%; }
  100% { background-position: 0 50%; }
}
.invoice-table-scroll {
  max-width: 100%;
  overflow-x: auto;
  padding-bottom: 4px;
}
.invoice-table {
  width: 100%;
  min-width: 760px;
  border-collapse: collapse;
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
  font-size: 13px;
}
.invoice-table th,
.invoice-table td {
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
  text-align: left;
}
.invoice-table th {
  background: #f8fafc;
  color: var(--text-light);
  font-weight: 700;
}
.invoice-table tbody tr:last-child td {
  border-bottom: none;
}
.invoice-amount-cell,
.invoice-status-cell {
  text-align: right !important;
  white-space: nowrap;
}
.invoice-amount-warning {
  margin-top: 3px;
  color: #b45309;
  font-size: 11px;
  line-height: 1.35;
  white-space: normal;
  min-width: 150px;
}
.invoice-status-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 52px;
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}
.invoice-status-paid {
  background: #dcfce7;
  color: #166534;
}
.invoice-status-unpaid {
  background: #fee2e2;
  color: #b91c1c;
}
.invoice-status-partial {
  background: #fef3c7;
  color: #92400e;
}
.invoice-status-exception {
  background: #fff7ed;
  color: #9a3412;
}
.invoice-status-unknown {
  background: #e5e7eb;
  color: #4b5563;
}
.invoice-pay-btn {
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
}
.invoice-row-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  min-width: 92px;
}
.invoice-void-btn {
  border-color: #fecaca !important;
  color: #b91c1c !important;
  background: #fff7f7 !important;
  border-radius: 999px;
  font-size: 12px;
  padding: 4px 10px;
}
.invoice-void-btn:hover {
  background: #fee2e2 !important;
}
.invoice-void-btn--exception {
  border-color: #fed7aa !important;
  color: #9a3412 !important;
  background: #fff7ed !important;
}
.invoice-void-btn--exception:hover {
  background: #ffedd5 !important;
}
.danger-btn {
  border: none;
  border-radius: 10px;
  background: #b91c1c;
  color: #fff;
  cursor: pointer;
  font-weight: 800;
  padding: 10px 18px;
}
.danger-btn:disabled {
  cursor: not-allowed;
  opacity: 0.58;
}
.danger-btn:not(:disabled):hover {
  background: #991b1b;
}
.invoice-void-modal {
  width: min(480px, calc(100vw - 32px));
}
.invoice-void-warning {
  margin: 16px 0;
  padding: 12px 14px;
  border: 1px solid #fecaca;
  border-radius: 14px;
  background: #fff7f7;
  color: #7f1d1d;
  font-size: 13px;
  line-height: 1.65;
}
.invoice-void-reason {
  width: 100%;
  margin-top: 8px;
  resize: vertical;
  min-height: 104px;
  font-family: var(--font-sans);
  line-height: 1.6;
}
.invoice-payment-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 220px;
}
.invoice-payment-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  color: var(--text);
  font-size: 12px;
}
.invoice-payment-row--void {
  color: var(--text-light);
  text-decoration: line-through;
}
.invoice-payment-date,
.invoice-payment-amount {
  font-variant-numeric: tabular-nums;
  font-weight: 700;
}
.invoice-payment-method,
.invoice-payment-receipt,
.invoice-payment-void {
  padding: 1px 6px;
  border-radius: 999px;
  background: #f1f5f9;
  color: var(--text-light);
}
.invoice-payment-void {
  background: #fef3c7;
  color: #92400e;
}
.invoice-modal-actions {
  margin-top: 18px;
}
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
  background:
    radial-gradient(circle at top right, rgba(255,167,38,0.10), transparent 34%),
    #0f172a;
}
[data-theme="dark"] .course-page::before {
  background:
    radial-gradient(circle at 14% 18%, rgba(125, 211, 252, 0.16), transparent 28%),
    radial-gradient(circle at 88% 2%, rgba(245, 158, 11, 0.12), transparent 24%);
}
[data-theme="dark"] .course-header-card {
  border-color: rgba(125, 211, 252, 0.28);
  background:
    linear-gradient(120deg, rgba(2, 6, 23, 0.98), rgba(15, 23, 42, 0.96) 50%, rgba(30, 64, 175, 0.72));
}
[data-theme="dark"] .table-card {
  border-color: #334155;
  background: linear-gradient(180deg, rgba(15,23,42,0.98), rgba(30,41,59,0.9));
}
[data-theme="dark"] .student-group-card,
[data-theme="dark"] .course-skeleton-group {
  border-color: #334155;
  background:
    radial-gradient(circle at top right, rgba(255,167,38,0.11), transparent 30%),
    #0f172a;
  box-shadow: 0 18px 44px rgba(0, 0, 0, 0.32);
}
[data-theme="dark"] .student-group-header {
  background: linear-gradient(180deg, rgba(30,41,59,0.96), rgba(15,23,42,0.92));
}
[data-theme="dark"] .course-table td {
  background: rgba(15, 23, 42, 0.86);
  border-color: #334155;
}
[data-theme="dark"] .course-row:hover td {
  background: linear-gradient(90deg, rgba(14, 165, 233, 0.12), rgba(15, 23, 42, 0.92));
  border-color: rgba(56, 189, 248, 0.24);
}
[data-theme="dark"] .student-group-meta,
[data-theme="dark"] .detail-item {
  background: rgba(15, 23, 42, 0.72);
  border-color: #334155;
}
[data-theme="dark"] .cell-remaining,
[data-theme="dark"] .price-line {
  color: #e2e8f0;
}
[data-theme="dark"] .detail-panel,
[data-theme="dark"] .dates-panel {
  background: linear-gradient(180deg, rgba(15,23,42,0.98), rgba(30,41,59,0.9));
  border-color: #334155;
}
[data-theme="dark"] .student-group-add-row,
[data-theme="dark"] .empty-active-courses {
  background: rgba(15, 23, 42, 0.88);
}
[data-theme="dark"] .empty-state,
[data-theme="dark"] .empty-active-courses__inner {
  border-color: #334155;
  background: linear-gradient(180deg, rgba(15,23,42,0.98), rgba(30,41,59,0.9));
}
[data-theme="dark"] .empty-icon {
  background: linear-gradient(135deg, #020617, #0f172a);
  border-color: rgba(125, 211, 252, 0.26);
}
[data-theme="dark"] .modal-title {
  color: #f8fafc;
}
[data-theme="dark"] .modal-desc {
  color: #cbd5e1;
}
[data-theme="dark"] .course-skeleton-header,
[data-theme="dark"] .course-skeleton-row {
  background: linear-gradient(90deg, #334155 25%, #475569 37%, #334155 63%);
  background-size: 400% 100%;
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
[data-theme="dark"] .btn-invoices {
  background: #172554 !important;
  color: #93c5fd !important;
  border-color: #1d4ed8 !important;
}
[data-theme="dark"] .icon-btn {
  background: #1e293b;
  color: #cbd5e1;
  border-color: #334155;
}
[data-theme="dark"] .invoice-void-warning {
  background: #450a0a;
  color: #fecaca;
  border-color: #7f1d1d;
}
[data-theme="dark"] .invoice-void-btn {
  background: #450a0a !important;
  color: #fecaca !important;
  border-color: #7f1d1d !important;
}
[data-theme="dark"] .invoice-modal-state,
[data-theme="dark"] .invoice-table th {
  background: #0f172a;
}
[data-theme="dark"] .invoice-table,
[data-theme="dark"] .invoice-table th,
[data-theme="dark"] .invoice-table td,
[data-theme="dark"] .invoice-modal-state {
  border-color: #334155;
}
[data-theme="dark"] .invoice-skeleton {
  background: linear-gradient(90deg, #334155 25%, #475569 37%, #334155 63%);
  background-size: 400% 100%;
}

/* ── Disabled button UX: cursor + tooltip affordance ── */
.btn-add-session.disabled {
  opacity: 0.5;
  cursor: not-allowed;
  position: relative;
}
</style>
