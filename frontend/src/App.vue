<template>
  <!-- Update available banner -->
  <Transition name="update-banner">
    <div v-if="updateAvailable && !updateDismissed" class="update-banner" role="alert">
      <span class="material-symbols-outlined update-banner-icon">system_update</span>
      <span class="update-banner-text">系統已更新，請重新整理頁面以取得最新版本</span>
      <button class="update-banner-btn" @click="reloadForUpdate">重新整理</button>
      <button class="update-banner-close" @click="dismissUpdate" title="稍後再說">&times;</button>
    </div>
  </Transition>

  <!-- Standalone parent portal (accessible without login via #/parent or ?parent=1) -->
  <div v-if="isStandaloneAdmission" class="standalone-admission-shell">
    <AdmissionInquiriesPage :standalone="true" />
  </div>

  <div v-else-if="isStandaloneParent" class="standalone-parent-shell">
    <ParentPortal :standalone="true" />
    <button
      class="global-guide-btn"
      type="button"
      title="開啟本頁導覽（可拖移，放開後靠齊最近邊）"
      aria-label="開啟本頁導覽"
      :class="{ 'is-dragging': guideFabDragging }"
      :style="guideFabStyle"
      @pointerdown="onGuideFabPointerDown"
      @pointermove="onGuideFabPointerMove"
      @pointerup="onGuideFabPointerUp"
      @pointercancel="onGuideFabPointerUp"
      @click="onGuideFabClick"
    >?</button>
  </div>

  <div v-else-if="loading" class="loading-screen">
    <div class="spinner"></div>
    <span>載入中…</span>
  </div>

  <Login v-else-if="!session" @login-success="handleLoginSuccess" />

  <div v-else class="app-layout" :class="{ 'sidebar-is-collapsed': sidebarCollapsed }">
    <!-- Sidebar -->
    <aside class="sidebar" :class="{ collapsed: sidebarCollapsed }">
      <div class="sidebar-brand">
        <div class="brand-logo-container">
          <img :src="logoUrl" alt="全真一對一 Logo" class="brand-logo" onerror="this.style.display='none'" />
        </div>
        <div class="brand-text" v-show="!sidebarCollapsed">
          <h1>全真一對一</h1>
          <div class="brand-sub">教務管理系統</div>
        </div>
      </div>
      <button
        type="button"
        class="sidebar-collapse-btn"
        @click="toggleSidebarCollapsed"
        :title="sidebarCollapsed ? '展開側欄' : '收起側欄'"
        :aria-label="sidebarCollapsed ? '展開側欄' : '收起側欄'"
        :aria-expanded="String(!sidebarCollapsed)"
      >
        <span class="material-symbols-outlined">{{ sidebarCollapsed ? 'chevron_right' : 'chevron_left' }}</span>
      </button>

      <nav class="sidebar-nav" data-guide="app-sidebar-nav">
        <template v-if="sidebarNavGroups.length > 0">
          <details
            v-for="group in sidebarPrimaryGroups"
            :key="group.key"
            class="nav-group"
            :open="isSidebarGroupOpen(group)"
            @toggle="onSidebarGroupToggle(group.key, $event)"
          >
            <summary
              class="nav-group-summary"
              v-show="!sidebarCollapsed"
              :aria-controls="`sidebar-group-${group.key}`"
              :aria-expanded="String(isSidebarGroupOpen(group))"
            >
              <span class="nav-group-title">{{ group.title.replace(/^[A-Z]\s*組：\s*/i, '') }}</span>
              <span class="nav-group-chevron">▾</span>
            </summary>
            <div :id="`sidebar-group-${group.key}`" class="nav-group-list">
              <button
                v-for="item in group.items"
                :key="item.page"
                type="button"
                @click="setActivePage(item.page)"
                :class="{ active: active === item.page }"
                :disabled="isNavItemDisabled(item.page)"
                :title="sidebarCollapsed ? item.label : ''"
                :aria-label="sidebarCollapsed ? item.label : undefined"
                :aria-current="active === item.page ? 'page' : undefined"
              >
                <span class="material-symbols-outlined nav-icon" aria-hidden="true">{{ item.icon }}</span>
                <span class="nav-label" v-show="!sidebarCollapsed">{{ item.label }}</span>
                <span
                  v-if="getItemBadgeCount(item) > 0"
                  :class="['nav-badge', { 'nav-badge-urgent': isItemBadgeUrgent(item) }]"
                  v-show="!sidebarCollapsed"
                >{{ getItemBadgeCount(item) > 99 ? '99+' : getItemBadgeCount(item) }}</span>
              </button>
            </div>
          </details>
          <button
            type="button"
            class="sidebar-more-trigger"
            id="sidebar-more-trigger"
            :class="{ active: activeInSidebarMore || showSidebarMore }"
            :aria-expanded="String(showSidebarMore)"
            aria-controls="sidebar-more-panel"
            :aria-label="sidebarCollapsed ? '開啟更多功能' : undefined"
            @click="toggleSidebarMore"
          >
            <span class="material-symbols-outlined nav-icon" aria-hidden="true">apps</span>
            <span class="nav-label" v-show="!sidebarCollapsed">更多功能</span>
            <span
              v-if="sidebarMoreBadgeCount > 0"
              class="nav-badge"
              v-show="!sidebarCollapsed"
            >{{ sidebarMoreBadgeCount > 99 ? '99+' : sidebarMoreBadgeCount }}</span>
            <span class="sidebar-more-trigger-chevron" v-show="!sidebarCollapsed" aria-hidden="true">›</span>
          </button>
        </template>
        <template v-else>
          <div class="nav-no-role-hint">無選單（身分未設定）</div>
        </template>
      </nav>

      <div class="sidebar-footer">
        <div class="user-block">
          <div class="user-avatar">
            <img v-if="avatarUrl" :src="avatarUrl" alt="avatar" class="user-avatar-image" />
            <span v-else>{{ avatarLetter }}</span>
          </div>
          <div>
            <div class="user-name">{{ userProfile?.username || session?.user?.name || 'User' }}</div>
            <div class="user-role">{{ roleLabel }}</div>
            <div v-if="role === 'super_admin'" class="user-role-hint">可檢視所有分校</div>
          </div>
        </div>
        <div v-if="isDirector" class="branch-switcher" data-guide="app-branch-switcher">
          <div class="branch-switcher-label">切換分校</div>
          <div class="branch-buttons">
            <button
              v-for="b in branches"
              :key="b.id"
              :class="['branch-btn', { active: currentBranch === b.id }]"
              @click="currentBranch = b.id"
            >{{ b.name.split('(')[0].trim() }}</button>
          </div>
        </div>
        <div v-else-if="isTeacher && teacherBranches.length > 1" class="branch-switcher" data-guide="app-branch-switcher">
          <div class="branch-switcher-label">切換分校</div>
          <div class="branch-buttons">
            <button
              v-for="b in teacherBranches"
              :key="b.id"
              :class="['branch-btn', { active: currentBranch === b.id }]"
              @click="currentBranch = b.id"
            >{{ b.name.split('(')[0].trim() }}</button>
          </div>
        </div>

      </div>
    </aside>

    <!-- Desktop low-frequency navigation: keep the daily workspace visible and reveal the rest on demand. -->
    <div
      v-if="showSidebarMore"
      class="sidebar-more-overlay"
      @click.self="closeSidebarMore()"
    >
      <section
        id="sidebar-more-panel"
        class="sidebar-more-panel"
        :style="{ '--sidebar-more-left': sidebarCollapsed ? '64px' : 'var(--sidebar-w)' }"
        role="dialog"
        aria-modal="false"
        aria-labelledby="sidebar-more-title"
        tabindex="-1"
        @keydown.esc.prevent="closeSidebarMore()"
      >
        <div class="sidebar-more-header">
          <div>
            <span class="sidebar-more-kicker">工作工具</span>
            <h2 id="sidebar-more-title">更多功能</h2>
          </div>
          <button
            type="button"
            class="sidebar-more-close"
            aria-label="關閉更多功能"
            title="關閉"
            @click="closeSidebarMore()"
          >
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
          </button>
        </div>
        <p class="sidebar-more-description">不常用的報表、教學工具與系統設定集中在這裡。</p>
        <div class="sidebar-more-groups">
          <div v-for="group in sidebarMoreGroups" :key="group.key" class="sidebar-more-group">
            <div class="sidebar-more-group-title">{{ group.title }}</div>
            <div class="sidebar-more-items">
              <button
                v-for="item in group.items"
                :key="item.page"
                type="button"
                class="sidebar-more-item"
                :class="{ active: active === item.page }"
                :disabled="isNavItemDisabled(item.page)"
                :aria-current="active === item.page ? 'page' : undefined"
                @click="setActivePage(item.page)"
              >
                <span class="material-symbols-outlined" aria-hidden="true">{{ item.icon }}</span>
                <span class="sidebar-more-item-label">{{ item.label }}</span>
                <span
                  v-if="getItemBadgeCount(item) > 0"
                  :class="['sidebar-more-item-badge', { 'nav-badge-urgent': isItemBadgeUrgent(item) }]"
                >{{ getItemBadgeCount(item) > 99 ? '99+' : getItemBadgeCount(item) }}</span>
              </button>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- Bug Report Launcher (floating button, all staff pages) -->
    <BugReportLauncher
      v-if="session && !isStandaloneParent && (isDirector || isTeacher)"
      :branch-id="currentBranch"
      :current-page-key="active"
      @open-bugs="openSubmittedBug"
    />

    <!-- Mobile Bottom Nav (5 tabs + More) -->
    <nav class="mobile-bottom-nav" v-if="session && !isStandaloneParent">
      <button
        v-for="tab in mobileTabItems"
        :key="tab.page"
        type="button"
        :id="tab.page === 'more' ? 'mobile-more-trigger' : undefined"
        :class="['mob-tab', { 'mob-tab-more': tab.page === 'more', active: tab.page === 'more' ? showMoreMenu : active === tab.page }]"
        :aria-current="tab.page !== 'more' && active === tab.page ? 'page' : undefined"
        :aria-expanded="tab.page === 'more' ? String(showMoreMenu) : undefined"
        :aria-controls="tab.page === 'more' ? 'mobile-more-sheet' : undefined"
        @click="tab.page === 'more' ? toggleMoreMenu() : (setActivePage(tab.page), closeMoreMenu(false))"
      >
        <span class="material-symbols-outlined mob-tab-icon">{{ tab.icon }}</span>
        <span class="mob-tab-label">{{ tab.label }}</span>
        <span
          v-if="getMobileTabBadgeCount(tab) > 0"
          class="mob-tab-badge"
        >{{ getMobileTabBadgeCount(tab) > 99 ? '99+' : getMobileTabBadgeCount(tab) }}</span>
      </button>
    </nav>

    <!-- More Menu Bottom Sheet -->
    <div class="more-overlay" v-if="showMoreMenu" @click="closeMoreMenu()"></div>
    <section
      v-if="showMoreMenu"
      id="mobile-more-sheet"
      class="more-sheet open"
      role="dialog"
      aria-modal="true"
      aria-labelledby="mobile-more-title"
      tabindex="-1"
      @keydown.esc.prevent="closeMoreMenu()"
    >
      <div class="more-sheet-handle" aria-hidden="true"></div>
      <div class="more-sheet-header">
        <h2 id="mobile-more-title" class="more-sheet-title">更多功能</h2>
        <button type="button" class="more-sheet-close" aria-label="關閉更多功能" @click="closeMoreMenu()">
          <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
      </div>
      <div v-for="group in sidebarNavGroups" :key="group.key" class="more-group">
        <div class="more-group-label">{{ group.title }}</div>
        <div class="more-group-items">
          <button
            v-for="item in group.items.filter(i => !mobileTabPages.has(i.page))"
            :key="item.page"
            type="button"
            :class="['more-item', { active: active === item.page }]"
            :aria-current="active === item.page ? 'page' : undefined"
            @click="setActivePage(item.page); closeMoreMenu(false)"
          >
            <span class="material-symbols-outlined">{{ item.icon }}</span>
            <span>{{ item.label }}</span>
            <span
              v-if="getMoreSheetItemBadgeCount(item) > 0"
              class="more-item-badge"
            >{{ getMoreSheetItemBadgeCount(item) > 99 ? '99+' : getMoreSheetItemBadgeCount(item) }}</span>
          </button>
        </div>
      </div>
    </section>

    <!-- Main Content -->
    <div class="main-content">
      <div class="main-topbar">
        <span
          class="build-stamp-bar"
          :title="`部署時間 ${buildTimeDisplay}`"
        >建置 {{ buildTimeDisplay }}</span>
        <button
          v-if="dashboardReturnContext"
          type="button"
          class="dashboard-return-button"
          :title="dashboardReturnContext.label"
          :aria-label="dashboardReturnContext.label"
          @click="returnToDashboard"
        >
          <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
          {{ dashboardReturnContext.label }}
        </button>
        <div class="main-topbar-spacer"></div>
        <AmbientMusicPlayer
          v-if="perfFlags.AMBIENT_MUSIC_ENABLED && (isDirector || isTeacher)"
        />
        <details class="account-menu" data-guide="app-account-menu">
          <summary class="account-menu-trigger">
            <div class="account-avatar">
              <img v-if="avatarUrl" :src="avatarUrl" alt="avatar" class="account-avatar-image" />
              <span v-else>{{ avatarLetter }}</span>
            </div>
            <div class="account-meta">
              <div class="account-name">{{ userProfile?.username || session?.user?.name || 'User' }}</div>
              <div class="account-role">{{ roleLabel }}</div>
            </div>
            <span class="material-symbols-outlined account-menu-chevron" aria-hidden="true">expand_more</span>
          </summary>
          <div class="account-menu-panel">
            <button type="button" class="account-menu-btn" @click="setActivePage('profile')">
              <span class="material-symbols-outlined" aria-hidden="true">manage_accounts</span>
              <span>個人管理</span>
            </button>
            <button
              v-if="isDirector || isTeacher"
              type="button"
              class="account-menu-btn"
              @click="setActivePage('release-notes')"
            >
              <span class="material-symbols-outlined" aria-hidden="true">new_releases</span>
              <span>版本更新</span>
            </button>
            <button
              v-if="isDirector || isTeacher"
              type="button"
              class="account-menu-btn"
              @click="startRoleOnboarding({ force: true })"
            >
              <span class="material-symbols-outlined" aria-hidden="true">school</span>
              <span>重新觀看新手教學</span>
            </button>
            <button type="button" class="account-menu-btn account-menu-btn-danger" @click="logout">
              <span class="material-symbols-outlined" aria-hidden="true">logout</span>
              <span>登出系統</span>
            </button>
            <div class="account-menu-divider" aria-hidden="true"></div>
            <div class="account-menu-tools">
              <span class="account-menu-tools-label">顯示模式</span>
              <div class="theme-buttons">
                <button
                  v-for="opt in themeOptions"
                  :key="opt.value"
                  type="button"
                  :class="['theme-btn', { active: themePreference === opt.value }]"
                  :title="opt.label"
                  :aria-label="`切換為${opt.label}模式`"
                  @click="setTheme(opt.value)"
                >
                  <span class="material-symbols-outlined theme-btn-icon" aria-hidden="true">{{ opt.icon }}</span>
                  <span class="theme-btn-label">{{ opt.label }}</span>
                </button>
              </div>
            </div>
            <details class="account-menu-shortcuts">
              <summary><span class="material-symbols-outlined" aria-hidden="true">keyboard</span>快捷鍵提示</summary>
              <ul class="shortcut-hint__list">
                <li><kbd>Win</kbd>+<kbd>Shift</kbd>+<kbd>S</kbd> <span>截圖</span></li>
                <li><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>R</kbd> <span>更新網頁</span></li>
                <li><kbd>Ctrl</kbd>+<kbd>C</kbd> <span>複製</span></li>
                <li><kbd>Ctrl</kbd>+<kbd>V</kbd> <span>貼上</span></li>
              </ul>
            </details>
          </div>
        </details>
      </div>
      <!-- 手機版：頂部分校選擇列（小螢幕時側欄分校區塊會被隱藏，改由此選擇） -->
      <div v-if="isDirector && branches.length > 0" class="mobile-branch-bar">
        <label class="mobile-branch-label" for="mobile-branch-select">分校</label>
        <select
          id="mobile-branch-select"
          class="mobile-branch-select"
          :value="currentBranch"
          @change="(e) => { const v = (e.target).value; if (v !== '') currentBranch = Number(v); }"
        >
          <option value="">請選擇分校</option>
          <option v-for="b in branches" :key="b.id" :value="b.id">
            {{ b.name.split('(')[0].trim() }}
          </option>
        </select>
      </div>
      <div v-else-if="isTeacher && teacherBranches.length > 0" class="mobile-branch-bar">
        <label class="mobile-branch-label" for="mobile-branch-select-teacher">分校</label>
        <select
          v-if="teacherBranches.length > 1"
          id="mobile-branch-select-teacher"
          class="mobile-branch-select"
          :value="currentBranch"
          @change="(e) => { const v = (e.target).value; if (v !== '') currentBranch = Number(v); }"
        >
          <option v-for="b in teacherBranches" :key="b.id" :value="b.id">
            {{ b.name.split('(')[0].trim() }}
          </option>
        </select>
        <span v-else class="mobile-branch-label">{{ teacherBranches[0]?.name?.split('(')[0]?.trim() }}</span>
      </div>
      <div v-if="isPasswordChangeLocked" class="card password-lock-card">
        為了帳號安全，請先到「右上角帳號選單 > 個人管理 > 安全性」完成初始密碼修改後再繼續使用系統。
      </div>
      <PinLockModal
        v-if="pinModalActive"
        :token="session?.access_token ?? ''"
        @unlocked="onPinUnlocked"
        @skip="onPinSkip"
        @dismiss="onPinDismiss"
      />
      <div
        v-if="pinModalActive"
        class="pin-gate-placeholder"
        role="status"
      >
        <p class="pin-gate-placeholder-title">此頁需要 PIN 才能查看</p>
        <p class="pin-gate-placeholder-body">薪資、當月學收與老師管理會先解鎖。請在中央視窗輸入 PIN，或按「暫不啟用，直接進入」。帳務中心可直接進入，不必先解 PIN。</p>
      </div>
      <DirectorDashboard v-if="!isPasswordChangeLocked && isDirector && active === 'director'" :branch-id="currentBranch" :unread-feedback-count="unreadFeedbackCount" :initial-engagement="userProfile?.engagement ?? null" :focus-workflow-id="directorFocusWorkflowId" :focus-section="directorFocusSection" @navigate="onNavigateFromNotifications" />
      <NotificationsCenter
        v-if="!isPasswordChangeLocked && isDirector && active === 'notifications'"
        :branch-id="currentBranch"
        @navigate="onNavigateFromNotifications"
        @unread-change="onUnreadChange"
      />
      <SmartCalendar v-if="!isPasswordChangeLocked && active === 'calendar'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" :initial-teacher-id="initialTeacherIdForNav" :initial-student-id="calendarInitialStudentId" :initial-course-id="calendarInitialCourseId" :initial-date="calendarInitialDate" :reset-week-token="calendarResetToken" :initial-intent="calendarInitialIntent" @clear-initial-teacher="initialTeacherIdForNav = null" @clear-initial-intent="calendarInitialIntent = ''" @clear-initial-context="clearCalendarNavigationContext" @navigate="onNavigateFromNotifications" />
      <StudentsList v-if="!isPasswordChangeLocked && isDirector && active === 'students'" :branch-id="currentBranch" :initial-student-id="studentFocusIdForNav" :initial-course-id="studentFocusCourseIdForNav" :initial-student-intent="studentFocusIntentForNav" @clear-initial-student="clearStudentNavigationContext" @navigate="onNavigateFromNotifications" />
      <TuitionCollectionPage v-if="!isPasswordChangeLocked && isDirector && active === 'tuition-collect'" :branch-id="currentBranch" :initial-tab="tuitionInitialTab" :initial-student-id="tuitionInitialStudentId" :initial-course-id="tuitionInitialCourseId" @clear-initial-tab="tuitionInitialTab = ''" @clear-initial-context="clearTuitionNavigationContext" />
      <TuitionReportPage v-if="!isPasswordChangeLocked && isDirector && active === 'tuition-report' && !pinModalActive" :branch-id="currentBranch" />
      <ParttimePayrollPage v-if="!isPasswordChangeLocked && isDirector && active === 'parttime-payroll' && !pinModalActive" :branch-id="currentBranch" :user-role="role" />
      <TeacherEligibilityPage v-if="!isPasswordChangeLocked && isDirector && active === 'teacher-eligibility' && !pinModalActive" :branch-id="currentBranch" :user-role="role" />
      <TeachersList v-if="!isPasswordChangeLocked && isDirector && active === 'teachers' && !pinModalActive" :branch-id="currentBranch" @navigate-to-schedule="onNavigateToSchedule" />
      <CourseManagement v-if="!isPasswordChangeLocked && isDirector && active === 'course-mgmt'" :branch-id="currentBranch" :initial-teacher-id="initialTeacherIdForNav" :initial-student-id="courseMgmtFocusStudentId" :initial-student-name="courseMgmtFocusStudentName" @clear-initial-teacher="initialTeacherIdForNav = null" @clear-initial-student="clearCourseMgmtNavigationContext" @navigate="onNavigateFromCourseManagement" />
      <AdmissionInquiriesPage v-if="!isPasswordChangeLocked && isDirector && active === 'admission-inquiries'" :branch-id="currentBranch" :token="session?.access_token ?? ''" />
      <ClassroomManagement v-if="!isPasswordChangeLocked && isDirector && active === 'classroom'" :branch-id="currentBranch" />
      <SubjectSettingsPage v-if="!isPasswordChangeLocked && isDirector && active === 'subject-settings'" :branch-id="currentBranch" :user-role="role" />
      <SubjectUnitsPage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'subject-units'" :branch-id="currentBranch" :user-role="role" />

      <TeacherHomePage
        v-if="!isPasswordChangeLocked && isTeacher && active === 'teacher-home'"
        :branch-id="currentBranch"
        :user-id="session.user.id"
        :user-role="role"
        :teacher-branch-ids="teacherBranches.map(b => b.id)"
        :unread-feedback-count="unreadFeedbackCount"
        :initial-engagement="userProfile?.engagement ?? null"
        @navigate="setActivePage($event)"
        @navigate-learning="onNavigateLearningFromTeacherHome"
      />
      <AttendancePage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'attendance'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" />
      <LearningRecordsPage v-if="!isPasswordChangeLocked && active === 'learning'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" :target-record-id="learningTargetRecordId" :target-session="learningTargetSession" :feedback-focus-token="learningFeedbackFocusToken" @feedback-read="refreshUnreadNotifications" />
      <AssessmentPage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'assessments'" :branch-id="currentBranch" :user-role="role" />
      <QuestionBankPage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'question-banks'" :branch-id="currentBranch" :user-role="role" />
      <ProfileCenterPage
        v-if="(isTeacher || isDirector) && active === 'profile'"
        :token="session?.access_token ?? ''"
        :force-password-change="isPasswordChangeLocked"
        :initial-tab="isPasswordChangeLocked ? 'security' : (profileFocusTab || 'profile')"
        @profile-updated="onProfileUpdated"
        @password-change-complete="onPasswordChangeComplete"
      />
      <ParentPortal v-if="!isPasswordChangeLocked && active === 'parent'" />
      <LineIntegration v-if="!isPasswordChangeLocked && isDirector && active === 'line-integration'" :branch-id="currentBranch" />
      <BindingManagementPage v-if="!isPasswordChangeLocked && isDirector && active === 'binding-management'" :branch-id="currentBranch" :user-role="role" :initial-student-name="bindingMgmtFocusStudentName" @clear-initial-student="bindingMgmtFocusStudentName = ''" />
      <BindingConflictReviewPage v-if="!isPasswordChangeLocked && isDirector && active === 'binding-conflicts'" :branch-id="currentBranch" :user-role="role" />
      <BindingHealthDashboard v-if="!isPasswordChangeLocked && isDirector && active === 'binding-health'" :branch-id="currentBranch" :user-role="role" />
      <DirectorAccountsPage v-if="!isPasswordChangeLocked && role === 'super_admin' && active === 'director-accounts'" :token="session?.access_token ?? ''" />
      <BranchManagementPage v-if="!isPasswordChangeLocked && role === 'super_admin' && active === 'branch-management'" :token="session?.access_token ?? ''" />
      <BranchHealthBoard v-if="!isPasswordChangeLocked && role === 'super_admin' && active === 'branch-health-board'" :token="session?.access_token ?? ''" />
      <NightlyReconcilePanel v-if="!isPasswordChangeLocked && role === 'super_admin' && active === 'nightly-reconcile'" :token="session?.access_token ?? ''" />
      <ChatPage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'chat'" :branch-id="currentBranch" :user-id="session?.user?.id" :avatar-url="avatarUrl" :super-admin="role === 'super_admin'" :user-role="role" />
      <BugReportsPage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'bugs'" :branch-id="currentBranch" :user-role="role" :focus-bug-id="focusBugId" />
      <ScheduleDiscrepancyPage v-if="!isPasswordChangeLocked && isDirector && active === 'schedule-discrepancy'" :branch-id="currentBranch" />
      <DuplicateSessionReviewPage v-if="!isPasswordChangeLocked && isDirector && active === 'duplicate-review'" :branch-id="currentBranch" :user-role="role" />
      <ReleaseNotesPage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'release-notes'" :user-role="role" />

      <!-- 身分無法辨識時顯示說明，避免登入後一片空白 -->
      <div v-if="!isDirector && !isTeacher" class="card" style="max-width: 480px; margin: 2rem auto; padding: 2rem; text-align: center;">
        <h2 style="margin-bottom: 1rem;">⚠️ 無法顯示功能</h2>
        <p style="color: var(--text-light); margin-bottom: 1rem;">您的帳號尚未設定為「主任」或「老師」身分，因此無法顯示操作選單。</p>
        <p style="font-size: 0.9rem; color: var(--text-light);">請聯繫系統管理員，在後台將您的帳號身分設為主任或老師後再登入。</p>
        <p style="margin-top: 1.5rem; font-size: 0.85rem;">目前辨識到的身分：<strong>{{ role || '（未取得）' }}</strong></p>
      </div>
    </div>

  </div>

  <div
    v-if="releaseNudgeOpen"
    class="release-nudge-layer"
    @click.self="dismissReleaseNudge"
  >
    <div class="release-nudge-card">
      <div class="release-nudge-kicker">本次更新重點</div>
      <h3>系統有新功能，2 分鐘看完就上手</h3>
      <p>我們把最近更新整理成簡單說明，你可以快速知道這版多了哪些功能。</p>
      <div class="release-nudge-actions">
        <button type="button" class="release-nudge-btn release-nudge-btn-primary" @click="openReleaseNotesFromNudge">
          立即查看
        </button>
        <button type="button" class="release-nudge-btn" @click="dismissReleaseNudge">稍後再看</button>
      </div>
    </div>
  </div>

  <Transition name="brand-overlay">
    <div
      v-if="brandOverlayVisible"
      :class="['brand-idle-layer', `brand-idle-layer--${brandOverlayMode}`]"
      role="presentation"
      @click="dismissBrandOverlay"
    >
      <div class="brand-idle-card" aria-live="polite">
        <div class="brand-idle-logo-wrap">
          <span class="brand-idle-ring brand-idle-ring--outer"></span>
          <span class="brand-idle-ring brand-idle-ring--inner"></span>
          <img :src="logoUrl" alt="全真一對一 Logo" class="brand-idle-logo" onerror="this.style.display='none'" />
        </div>
        <div class="brand-idle-copy">
          <strong>{{ brandOverlayMode === 'intro' ? '歡迎回來' : '全真一對一' }}</strong>
          <span>{{ brandOverlayMode === 'intro' ? '正在進入教務管理系統' : '系統待機中，點一下即可繼續' }}</span>
        </div>
      </div>
    </div>
  </Transition>

  <Transition name="onboarding-launch">
    <div
      v-if="onboardingLaunchOpen"
      class="onboarding-launch-layer"
      role="dialog"
      aria-modal="true"
      aria-labelledby="onboarding-launch-title"
      @click.self="deferRoleOnboarding"
    >
      <div class="onboarding-launch-card" @click.stop>
        <div class="onboarding-launch-art" aria-hidden="true">
          <span class="onboarding-launch-spark onboarding-launch-spark--one">✦</span>
          <span class="onboarding-launch-spark onboarding-launch-spark--two">✦</span>
          <img :src="onboardingMissionSceneUrl" alt="" class="onboarding-launch-scene" />
        </div>
        <div class="onboarding-launch-content">
          <span class="onboarding-launch-kicker">{{ onboardingPromptIsResume ? '接續你的任務' : onboardingPromptMission.eyebrow }}</span>
          <h2 id="onboarding-launch-title">{{ onboardingPromptIsResume ? '要繼續上次的任務嗎？' : onboardingPromptMission.title }}</h2>
          <p>{{ onboardingPromptIsResume ? '你上次停在中途，現在可以從原本的位置繼續。' : onboardingPromptMission.description }}</p>
          <div v-if="onboardingPromptEngagement" class="onboarding-launch-rank">
            <div class="onboarding-launch-rank-head">
              <span>目前軍階</span>
              <span class="material-symbols-outlined" aria-hidden="true">military_tech</span>
            </div>
            <EngagementRankStrip :engagement="onboardingPromptEngagement" :reduced-motion="onboardingReducedMotion" :overlay-z-index="2147483200" />
            <p>{{ onboardingPromptMission.rankNote }}</p>
          </div>
          <div class="onboarding-launch-progress" aria-label="新手任務清單">
            <div class="onboarding-launch-progress-head">
              <span>任務路線</span>
              <strong>{{ onboardingPromptSteps.length }} 個關鍵步驟</strong>
            </div>
            <ol class="onboarding-launch-checklist">
              <li
                v-for="(step, index) in onboardingPromptSteps"
                :key="step.id"
                :aria-current="index === onboardingPromptStartIndex ? 'step' : undefined"
                :class="{ 'is-current': index === onboardingPromptStartIndex, 'is-done': index < onboardingPromptStartIndex }"
              >
                <span class="onboarding-check-icon material-symbols-outlined" aria-hidden="true">
                  {{ index < onboardingPromptStartIndex ? 'check_circle' : step.icon }}
                </span>
                <span><small>第 {{ index + 1 }} 站</small>{{ step.title }}</span>
              </li>
            </ol>
          </div>
          <div class="onboarding-launch-actions">
            <button type="button" class="guide-tour-btn" @click="deferRoleOnboarding">稍後再看</button>
            <button v-if="onboardingPromptIsResume" type="button" class="guide-tour-btn" @click="restartRoleOnboarding">從頭開始</button>
            <button type="button" class="guide-tour-btn guide-tour-btn-primary" @click="beginPendingRoleOnboarding">
              {{ onboardingPromptIsResume ? '繼續導覽' : '開始導覽' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Transition>

  <aside v-if="guideTour.isOpen.value && guideTour.isPracticing.value" class="onboarding-coach" aria-label="進行中的新手任務">
    <img :src="learningCompanionUrl" alt="" />
    <div>
      <span class="onboarding-launch-kicker">實作中 · {{ guideTour.progressText.value }}</span>
      <strong>{{ guideTour.currentStep.value?.title }}</strong>
      <button type="button" class="guide-tour-btn" @click="guideTour.resumeStep">查看提示／繼續任務</button>
    </div>
  </aside>

  <div v-if="guideTour.isOpen.value && !guideTour.isPracticing.value" class="guide-tour-popover-layer" @click.self="guideTour.mode.value === 'onboarding' ? guideTour.practiceStep() : guideTour.skipTour()">
    <div
      ref="guidePopoverRef"
      :class="['guide-tour-popover', `placement-${guideTour.effectivePlacement.value || 'bottom'}`]"
      :style="guideTour.popoverStyle.value"
      role="dialog"
      aria-modal="true"
      aria-labelledby="guide-tour-title"
      tabindex="-1"
      @click.stop
    >
      <div class="guide-tour-popover-head">
        <div class="guide-tour-head-title">
          <span v-if="guideTour.currentStep.value?.icon" class="guide-tour-icon material-symbols-outlined" aria-hidden="true">{{ guideTour.currentStep.value.icon }}</span>
          <div>
            <span v-if="guideTour.mode.value === 'onboarding'" class="guide-tour-mission-label">任務 {{ guideTour.stepIndex.value + 1 }} / {{ guideTour.steps.value.length }}</span>
            <strong id="guide-tour-title">{{ guideTour.currentStep.value?.title }}</strong>
          </div>
        </div>
        <button type="button" class="guide-tour-close" @click.stop="guideTour.skipTour" aria-label="關閉導覽"><span class="material-symbols-outlined">close</span></button>
      </div>
      <div v-if="guideTour.mode.value === 'onboarding'" class="guide-tour-trail" :aria-label="`已走過 ${guideTour.stepIndex.value} 個步驟，共 ${guideTour.steps.value.length} 個`">
        <span v-for="(_, index) in guideTour.steps.value" :key="index" :class="{ 'is-done': index < guideTour.stepIndex.value, 'is-current': index === guideTour.stepIndex.value }" />
      </div>
      <p class="guide-tour-popover-text" aria-live="polite">{{ guideTour.currentStep.value?.description }}</p>
      <div v-if="guideTour.mode.value === 'onboarding' && guideTour.currentStep.value?.objective" class="guide-tour-objective">
        <span class="material-symbols-outlined" aria-hidden="true">flag</span>
        <div>
          <strong>這一步的目標</strong>
          <span>{{ guideTour.currentStep.value.objective }}</span>
        </div>
      </div>
      <p v-if="guideTour.mode.value === 'onboarding' && guideTour.currentStep.value?.completionPrompt" class="guide-tour-completion-prompt">
        {{ guideTour.currentStep.value.completionPrompt }}
      </p>
      <button v-if="guideTour.mode.value === 'onboarding'" type="button" class="guide-tour-btn guide-tour-practice" @click="guideTour.practiceStep">
        開始這一步 · 收起提示
      </button>
      <div v-if="guideTour.mode.value === 'onboarding'" class="guide-tour-checklist">
        <div class="guide-tour-checklist-head">
          <span>任務進度</span>
          <strong>{{ guideTour.progressText.value }}</strong>
        </div>
        <ol>
          <li
            v-for="(step, index) in guideTour.steps.value"
            :key="step.id || index"
            :class="{ 'is-current': index === guideTour.stepIndex.value, 'is-done': index < guideTour.stepIndex.value }"
          >
            <span class="material-symbols-outlined" aria-hidden="true">{{ index < guideTour.stepIndex.value ? 'check_circle' : step.icon }}</span>
            <span>{{ step.title }}</span>
          </li>
        </ol>
      </div>
      <div class="guide-tour-dots">
        <span
          v-for="(_, i) in guideTour.steps.value"
          :key="i"
          :class="['guide-tour-dot', { active: i === guideTour.stepIndex.value }]"
        />
      </div>
      <div class="guide-tour-popover-foot">
        <button type="button" class="guide-tour-btn" @click="guideTour.skipTour">跳過教學</button>
        <div class="guide-tour-actions">
          <button type="button" class="guide-tour-btn" :disabled="!guideTour.hasPrev.value" @click="guideTour.prevStep">上一步</button>
          <button
            type="button"
            class="guide-tour-btn guide-tour-btn-primary"
            @click="guideTour.nextStep"
          >{{ guideTour.hasNext.value ? '我完成了，下一步' : '完成任務' }}</button>
        </div>
      </div>
    </div>
    <AtToast />
  </div>

  <Transition name="onboarding-complete">
    <div v-if="onboardingCompletionVisible" class="onboarding-complete-layer" role="status" aria-live="polite">
      <div class="onboarding-complete-card">
        <div class="onboarding-complete-burst" aria-hidden="true">✓</div>
        <img :src="learningCompanionUrl" alt="" class="onboarding-complete-art" />
        <span class="onboarding-launch-kicker">任務完成</span>
        <h2>{{ roleLabel }}新手教學完成</h2>
        <p>你已經掌握最常用的工作路線，接下來可以直接開始今天的任務。</p>
        <div v-if="onboardingCompletionEngagement" class="onboarding-complete-rank">
          <EngagementRankStrip :engagement="onboardingCompletionEngagement" :reduced-motion="onboardingReducedMotion" :overlay-z-index="2147483200" />
          <span>{{ onboardingPromptMission.rankNote }}</span>
        </div>
        <button type="button" class="guide-tour-btn guide-tour-btn-primary" @click="dismissOnboardingCompletion">開始工作</button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { computed, defineAsyncComponent, nextTick, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { supabase } from './supabase';
import {
  branches,
  loadBranches,
  loadBranchesForDirector,
  getDefaultBranchId,
  resolveSavedBranchChoice,
} from './lib/useBranches';
import { usePageGuideTour } from './lib/usePageGuideTour';
import {
  ROLE_ONBOARDING_VERSION,
  getRoleOnboardingMission,
  getRoleOnboardingSteps,
  isOnboardingRole,
  onboardingStartIndex,
  readOnboardingState,
  shouldAutoStartOnboarding,
  writeOnboardingState,
} from './lib/roleOnboarding';
import { useUpdateChecker } from './composables/useUpdateChecker';
import { lockScroll, unlockScroll, forceUnlockScroll } from './lib/useScrollLock';
import logoUrl from './assets/logo.png';
import learningCompanionUrl from './assets/alltrue-learning-companion.png';
import teacherMissionSceneUrl from './assets/onboarding/teacher-daily-closeout-scene.png';
import directorMissionSceneUrl from './assets/onboarding/director-daily-control-scene.png';

// Pages — lazy-loaded per route for code splitting (reduces initial bundle size)
import Login from './pages/Login.vue';
import ParentPortal from './pages/ParentPortal.vue';

const AdmissionInquiriesPage = defineAsyncComponent(() => import('./pages/AdmissionInquiriesPage.vue'));
const StudentsList          = defineAsyncComponent(() => import('./pages/StudentsList.vue'));
const LearningRecordsPage   = defineAsyncComponent(() => import('./pages/LearningRecordsPage.vue'));
const AssessmentPage        = defineAsyncComponent(() => import('./pages/AssessmentPage.vue'));
const QuestionBankPage      = defineAsyncComponent(() => import('./pages/QuestionBankPage.vue'));
const SmartCalendar         = defineAsyncComponent(() => import('./pages/SmartCalendar.vue'));
const DirectorDashboard     = defineAsyncComponent(() => import('./pages/DirectorDashboard.vue'));
const LineIntegration       = defineAsyncComponent(() => import('./pages/LineIntegration.vue'));
const BindingManagementPage = defineAsyncComponent(() => import('./pages/BindingManagementPage.vue'));
const BindingConflictReviewPage = defineAsyncComponent(() => import('./pages/BindingConflictReviewPage.vue'));
const BindingHealthDashboard = defineAsyncComponent(() => import('./pages/BindingHealthDashboard.vue'));
const CourseManagement      = defineAsyncComponent(() => import('./pages/CourseManagement.vue'));
const ClassroomManagement   = defineAsyncComponent(() => import('./pages/ClassroomManagement.vue'));
const SubjectSettingsPage   = defineAsyncComponent(() => import('./pages/SubjectSettingsPage.vue'));
const TeachersList          = defineAsyncComponent(() => import('./pages/TeachersList.vue'));
const AttendancePage        = defineAsyncComponent(() => import('./pages/AttendancePage.vue'));
const SubjectUnitsPage      = defineAsyncComponent(() => import('./pages/SubjectUnitsTimelinePage.vue'));
const TuitionCollectionPage = defineAsyncComponent(() => import('./pages/TuitionCollectionPage.vue'));
const TuitionReportPage     = defineAsyncComponent(() => import('./pages/TuitionReportPage.vue'));
const ParttimePayrollPage   = defineAsyncComponent(() => import('./pages/ParttimePayrollPage.vue'));
const TeacherEligibilityPage = defineAsyncComponent(() => import('./pages/TeacherEligibilityPage.vue'));
const DirectorAccountsPage  = defineAsyncComponent(() => import('./pages/DirectorAccountsPage.vue'));
const BranchManagementPage  = defineAsyncComponent(() => import('./pages/BranchManagementPage.vue'));
const BranchHealthBoard     = defineAsyncComponent(() => import('./pages/BranchHealthBoard.vue'));
const NotificationsCenter   = defineAsyncComponent(() => import('./pages/NotificationsCenter.vue'));
const ProfileCenterPage     = defineAsyncComponent(() => import('./pages/ProfileCenterPage.vue'));
const ChatPage              = defineAsyncComponent(() => import('./pages/ChatPage.vue'));
const BugReportsPage        = defineAsyncComponent(() => import('./pages/BugReportsPage.vue'));
const TeacherHomePage       = defineAsyncComponent(() => import('./pages/TeacherHomePage.vue'));
const ScheduleDiscrepancyPage = defineAsyncComponent(() => import('./pages/ScheduleDiscrepancyPage.vue'));
const ReleaseNotesPage      = defineAsyncComponent(() => import('./pages/ReleaseNotesPage.vue'));
const NightlyReconcilePanel  = defineAsyncComponent(() => import('./pages/NightlyReconcilePanel.vue'));
const DuplicateSessionReviewPage = defineAsyncComponent(() => import('./pages/DuplicateSessionReviewPage.vue'));
import AmbientMusicPlayer from './components/AmbientMusicPlayer.vue';
import EngagementRankStrip from './components/EngagementRankStrip.vue';
import BugReportLauncher from './components/BugReportLauncher.vue';
import PinLockModal from './components/PinLockModal.vue';
import AtToast from './components/AtToast.vue';
import { fetchChatUnreadCount } from './lib/chatApi';
import { buildInboxDeepLinkQuery, inboxScopeKey, mergeInboxCountState, parseInboxCount, parseInboxDeepLinkSearch, resolveAuthorizedBranchId } from './lib/actionInboxContract.js';
import perfFlags from './lib/perfFlags';
import { playTeacherUiSfx } from './lib/teacherUiSfx';
import { recordTeacherVisitToday } from './lib/teacherLoginStreak';
import { clearAllDraftsByTeacher } from './lib/learningRecordDrafts';
import { latestReleaseVersionForRole } from './lib/releaseNotes';
import {
  shouldShowPinModal,
  shouldBlurLock,
  PIN_UNLOCK_TTL_MS,
  PIN_IDLE_LOCK_MS,
} from './lib/pinGate';
import { getMobileTabItems, getNavigationGroups } from './lib/navigationRegistry';
import { resolveActiveAfterProfileLoad } from './lib/resolveActiveAfterProfileLoad';
import { createDashboardReturnContext } from './lib/dashboardReturnContext';
import { isUserEngagementRankDisplayEnabled } from './lib/userEngagementDisplay';

// Detect standalone parent portal access via URL hash, query param, or LIFF context
const liffParentOverride = ref(false);
const isStandaloneParent = computed(() => {
  const hash = window.location.hash;
  const params = new URLSearchParams(window.location.search);
  return hash === '#/parent' || params.get('parent') === '1' || liffParentOverride.value;
});
const isStandaloneAdmission = computed(() => {
  const hash = window.location.hash;
  const params = new URLSearchParams(window.location.search);
  return hash === '#/admissions' || params.get('admissions') === '1';
});

// Auto-detect LIFF environment: only when truly opened inside LINE app via LIFF URL
(function detectLiffParent() {
  try {
    const params = new URLSearchParams(window.location.search);
    const hash = window.location.hash;
    const alreadyParent = hash === '#/parent' || params.get('parent') === '1';
    const hasLiffParam = params.has('liff.state') || params.has('liff.id') || hash.includes('liff.state');
    const isLineInApp = /Line/i.test(navigator.userAgent);
    if ((hasLiffParam || isLineInApp) && !alreadyParent) {
      window.location.hash = '#/parent';
      liffParentOverride.value = true;
    }
    // If hash is #/parent but NOT in LINE and no parent token saved, clear it
    // so regular browser visitors see the normal login page
    if (hash === '#/parent' && !isLineInApp && !hasLiffParam && !localStorage.getItem('parent_portal_token')) {
      window.location.hash = '';
    }
  } catch (e) { /* ignore */ }
})();

const session = ref(null);
const userProfile = ref(null);
const loading = ref(true);
const guideTour = usePageGuideTour();
const guidePopoverRef = ref(null);
const hadAppSessionBeforeLoad = (() => {
  try {
    return Boolean(localStorage.getItem('alltrue_session'));
  } catch {
    return true;
  }
})();
let onboardingAutoStarted = false;
const onboardingLaunchOpen = ref(false);
const onboardingPromptSteps = ref([]);
const onboardingPromptState = ref(null);
const onboardingCompletionVisible = ref(false);
const focusBugId = ref(null);
const onboardingPromptIsResume = computed(() => onboardingPromptState.value?.status === 'in_progress');
const onboardingPromptStartIndex = computed(() => onboardingStartIndex(
  onboardingPromptState.value,
  onboardingPromptSteps.value.length,
));
const onboardingPromptMission = computed(() => getRoleOnboardingMission(role.value));
const onboardingMissionSceneUrl = computed(() => (
  role.value === 'teacher' ? teacherMissionSceneUrl : directorMissionSceneUrl
));
const onboardingPromptEngagement = computed(() => (
  isUserEngagementRankDisplayEnabled() ? userProfile.value?.engagement ?? null : null
));
const onboardingCompletionEngagement = computed(() => (
  onboardingCompletionVisible.value ? onboardingPromptEngagement.value : null
));
const onboardingReducedMotion = computed(() => prefersReducedMotion());
const releaseNudgeOpen = ref(false);
const releaseNudgeVersion = ref('');
const RELEASE_NOTES_SEEN_KEY = 'alltrue_release_notes_seen';
const brandOverlayMode = ref('idle');
const brandOverlayVisible = ref(false);
let brandIdleTimer = null;
let brandIntroTimer = null;
const BRAND_IDLE_DESKTOP_MS = 90 * 1000;
const BRAND_IDLE_MOBILE_MS = 180 * 1000;
const BRAND_INTRO_MS = 2400;
const BRAND_INTRO_SEEN_KEY = 'alltrue_brand_intro_seen_token';

const brandOverlayAllowed = computed(() =>
  Boolean(session.value)
  && !isStandaloneParent.value
  && !isPasswordChangeLocked.value
  && !guideTour.isOpen.value
  && !onboardingLaunchOpen.value
  && !showMoreMenu.value
  && (isDirector.value || isTeacher.value)
);

function prefersReducedMotion() {
  return typeof window !== 'undefined'
    && window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
}

function isCoarsePointer() {
  return typeof window !== 'undefined'
    && window.matchMedia?.('(pointer: coarse)')?.matches;
}

function activeElementIsEditing() {
  if (typeof document === 'undefined') return false;
  const el = document.activeElement;
  if (!el) return false;
  const tag = String(el.tagName || '').toLowerCase();
  return tag === 'input' || tag === 'textarea' || tag === 'select' || Boolean(el.isContentEditable);
}

function clearBrandTimers() {
  if (brandIdleTimer) {
    clearTimeout(brandIdleTimer);
    brandIdleTimer = null;
  }
  if (brandIntroTimer) {
    clearTimeout(brandIntroTimer);
    brandIntroTimer = null;
  }
}

function scheduleBrandIdleOverlay() {
  if (brandIdleTimer) {
    clearTimeout(brandIdleTimer);
    brandIdleTimer = null;
  }
  if (releaseNudgeOpen.value) return;
  if (!brandOverlayAllowed.value || prefersReducedMotion()) return;
  const delay = isCoarsePointer() ? BRAND_IDLE_MOBILE_MS : BRAND_IDLE_DESKTOP_MS;
  brandIdleTimer = window.setTimeout(() => {
    if (!brandOverlayAllowed.value || activeElementIsEditing()) {
      scheduleBrandIdleOverlay();
      return;
    }
    brandOverlayMode.value = 'idle';
    brandOverlayVisible.value = true;
  }, delay);
}

function showBrandIntroOverlay() {
  if (!brandOverlayAllowed.value) {
    scheduleBrandIdleOverlay();
    return;
  }
  brandOverlayMode.value = 'intro';
  brandOverlayVisible.value = true;
  if (brandIntroTimer) clearTimeout(brandIntroTimer);
  brandIntroTimer = window.setTimeout(() => {
    brandOverlayVisible.value = false;
    scheduleBrandIdleOverlay();
  }, BRAND_INTRO_MS);
}

function triggerBrandIntroOncePerSessionToken() {
  try {
    const token = String(session.value?.access_token || '');
    if (!token) return;
    const seenToken = sessionStorage.getItem(BRAND_INTRO_SEEN_KEY) || '';
    if (seenToken === token) return;
    sessionStorage.setItem(BRAND_INTRO_SEEN_KEY, token);
    showBrandIntroOverlay();
  } catch (_) {
    showBrandIntroOverlay();
  }
}

function dismissBrandOverlay() {
  brandOverlayVisible.value = false;
  scheduleBrandIdleOverlay();
}

function onBrandActivity() {
  if (brandOverlayMode.value === 'idle' && brandOverlayVisible.value) {
    brandOverlayVisible.value = false;
  }
  scheduleBrandIdleOverlay();
}

function markReleaseNotesSeen() {
  if (!releaseNudgeVersion.value) return;
  try {
    localStorage.setItem(RELEASE_NOTES_SEEN_KEY, releaseNudgeVersion.value);
  } catch (_) { /* ignore */ }
}

function dismissReleaseNudge() {
  releaseNudgeOpen.value = false;
  markReleaseNotesSeen();
}

function openReleaseNotesFromNudge() {
  releaseNudgeOpen.value = false;
  markReleaseNotesSeen();
  setActivePage('release-notes');
}

/** Draggable “?” guide FAB — persisted in localStorage (mouse + touch via Pointer Events). */
const GUIDE_FAB_W = 46;
const GUIDE_FAB_H = 46;
const GUIDE_FAB_MARGIN = 8;
const GUIDE_FAB_STORAGE_KEY = 'alltrue_guide_fab_pos';

function clampGuideFabPos(x, y) {
  if (typeof window === 'undefined') {
    return { x, y };
  }
  const maxX = window.innerWidth - GUIDE_FAB_W - GUIDE_FAB_MARGIN;
  const maxY = window.innerHeight - GUIDE_FAB_H - GUIDE_FAB_MARGIN;
  return {
    x: Math.min(Math.max(GUIDE_FAB_MARGIN, x), Math.max(GUIDE_FAB_MARGIN, maxX)),
    y: Math.min(Math.max(GUIDE_FAB_MARGIN, y), Math.max(GUIDE_FAB_MARGIN, maxY)),
  };
}

/** Pin FAB to left / right / top / bottom by shortest distance from button center to viewport edge. */
function snapGuideFabToNearestEdge(x, y) {
  if (typeof window === 'undefined') {
    return { x, y };
  }
  const { x: cx0, y: cy0 } = clampGuideFabPos(x, y);
  const iw = window.innerWidth;
  const ih = window.innerHeight;
  const maxX = iw - GUIDE_FAB_W - GUIDE_FAB_MARGIN;
  const maxY = ih - GUIDE_FAB_H - GUIDE_FAB_MARGIN;
  const cx = cx0 + GUIDE_FAB_W / 2;
  const cy = cy0 + GUIDE_FAB_H / 2;
  const dLeft = cx;
  const dRight = iw - cx;
  const dTop = cy;
  const dBottom = ih - cy;

  let edge = 'left';
  let minD = dLeft;
  if (dRight < minD) {
    minD = dRight;
    edge = 'right';
  }
  if (dTop < minD) {
    minD = dTop;
    edge = 'top';
  }
  if (dBottom < minD) {
    edge = 'bottom';
  }

  switch (edge) {
    case 'left':
      return { x: GUIDE_FAB_MARGIN, y: Math.min(Math.max(GUIDE_FAB_MARGIN, cy0), maxY) };
    case 'right':
      return { x: maxX, y: Math.min(Math.max(GUIDE_FAB_MARGIN, cy0), maxY) };
    case 'top':
      return { x: Math.min(Math.max(GUIDE_FAB_MARGIN, cx0), maxX), y: GUIDE_FAB_MARGIN };
    case 'bottom':
      return { x: Math.min(Math.max(GUIDE_FAB_MARGIN, cx0), maxX), y: maxY };
    default:
      return { x: cx0, y: cy0 };
  }
}

function loadGuideFabPos() {
  try {
    const raw = localStorage.getItem(GUIDE_FAB_STORAGE_KEY);
    if (raw) {
      const p = JSON.parse(raw);
      if (typeof p.x === 'number' && typeof p.y === 'number') {
        return snapGuideFabToNearestEdge(p.x, p.y);
      }
    }
  } catch (_) { /* ignore */ }
  if (typeof window === 'undefined') {
    return { x: 0, y: 0 };
  }
  return snapGuideFabToNearestEdge(
    window.innerWidth - GUIDE_FAB_MARGIN - GUIDE_FAB_W,
    window.innerHeight - GUIDE_FAB_MARGIN - GUIDE_FAB_H
  );
}

function saveGuideFabPos(pos) {
  try {
    localStorage.setItem(GUIDE_FAB_STORAGE_KEY, JSON.stringify(pos));
  } catch (_) { /* ignore */ }
}

const guideFabPos = ref(typeof window !== 'undefined' ? loadGuideFabPos() : { x: 0, y: 0 });
const guideFabDragging = ref(false);
const guideFabStyle = computed(() => ({
  left: `${guideFabPos.value.x}px`,
  top: `${guideFabPos.value.y}px`,
  right: 'auto',
  bottom: 'auto',
}));

let guideFabPointerId = null;
let guideFabDragStartClient = { x: 0, y: 0 };
let guideFabDragStartPos = { x: 0, y: 0 };
let guideFabDragDidMove = false;
let guideFabSuppressClick = false;
const GUIDE_FAB_DRAG_THRESHOLD_PX = 6;

function onGuideFabPointerDown(e) {
  if (e.pointerType === 'mouse' && e.button !== 0) {
    return;
  }
  guideFabPointerId = e.pointerId;
  guideFabDragDidMove = false;
  guideFabDragStartClient = { x: e.clientX, y: e.clientY };
  guideFabDragStartPos = { ...guideFabPos.value };
  guideFabDragging.value = true;
  e.currentTarget.setPointerCapture?.(e.pointerId);
}

function onGuideFabPointerMove(e) {
  if (guideFabPointerId !== e.pointerId) {
    return;
  }
  const dx = e.clientX - guideFabDragStartClient.x;
  const dy = e.clientY - guideFabDragStartClient.y;
  if (Math.abs(dx) > GUIDE_FAB_DRAG_THRESHOLD_PX || Math.abs(dy) > GUIDE_FAB_DRAG_THRESHOLD_PX) {
    guideFabDragDidMove = true;
  }
  guideFabPos.value = clampGuideFabPos(guideFabDragStartPos.x + dx, guideFabDragStartPos.y + dy);
}

function onGuideFabPointerUp(e) {
  if (guideFabPointerId !== e.pointerId) {
    return;
  }
  try {
    e.currentTarget.releasePointerCapture?.(e.pointerId);
  } catch (_) { /* ignore */ }
  guideFabPointerId = null;
  guideFabDragging.value = false;
  const didDragFab = guideFabDragDidMove;
  guideFabDragDidMove = false;
  if (didDragFab) {
    guideFabSuppressClick = true;
    guideFabPos.value = snapGuideFabToNearestEdge(guideFabPos.value.x, guideFabPos.value.y);
    saveGuideFabPos(guideFabPos.value);
  }
}

function onGuideFabClick(e) {
  if (guideFabSuppressClick) {
    guideFabSuppressClick = false;
    e.preventDefault();
    e.stopPropagation();
    return;
  }
  startGuideTour();
}

function onWindowResizeGuideFab() {
  if (typeof window === 'undefined') {
    return;
  }
  guideFabPos.value = snapGuideFabToNearestEdge(guideFabPos.value.x, guideFabPos.value.y);
}

const active = ref('director');
const dashboardReturnContext = ref(null);
const currentBranch = ref(null); // Will be set after branches load
const learningTargetRecordId = ref(null);
const learningTargetSession = ref(null);
const learningFeedbackFocusToken = ref(0);

// Sidebar collapse state (desktop)
const sidebarCollapsed = ref(localStorage.getItem('sidebar_collapsed') === 'true');

// ===== 主題模式（日間 / 夜間 / 系統） =====
const THEME_KEY = 'app_color_scheme';
const themeOptions = [
  { value: 'light',  icon: 'light_mode', label: '日間' },
  { value: 'dark',   icon: 'dark_mode', label: '夜間' },
  { value: 'system', icon: 'desktop_windows', label: '系統' },
];
const themePreference = ref(localStorage.getItem(THEME_KEY) || 'system');

function getSystemDark() {
  return window.matchMedia('(prefers-color-scheme: dark)').matches;
}
function applyTheme(pref) {
  const dark = pref === 'dark' || (pref === 'system' && getSystemDark());
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
}
function setTheme(pref) {
  themePreference.value = pref;
  localStorage.setItem(THEME_KEY, pref);
  applyTheme(pref);
}

// 監聽系統主題變化（當使用者選「系統」時即時跟隨）
const systemThemeMq = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;
function onSystemThemeChange() {
  if (themePreference.value === 'system') applyTheme('system');
}
applyTheme(themePreference.value);
function toggleSidebarCollapsed() {
  sidebarCollapsed.value = !sidebarCollapsed.value;
  localStorage.setItem('sidebar_collapsed', sidebarCollapsed.value);
  if (isTeacher.value) {
    playTeacherUiSfx('tap');
  }
}

// Mobile bottom nav: 5 tabs + More
const showMoreMenu = ref(false);
const showSidebarMore = ref(false);
const mobileTabItems = computed(() => {
  return getMobileTabItems(role.value);
});
const mobileTabPages = computed(() => new Set(mobileTabItems.value.filter(t => t.page !== 'more').map(t => t.page)));
const initialTeacherIdForNav = ref(null);
const studentFocusIdForNav = ref(null);
const studentFocusCourseIdForNav = ref(null);
const studentFocusIntentForNav = ref('');
const calendarResetToken = ref(0);
const calendarInitialIntent = ref('');
const calendarInitialStudentId = ref(null);
const calendarInitialCourseId = ref(null);
const calendarInitialDate = ref('');
const tuitionInitialTab = ref('');
const tuitionInitialStudentId = ref(null);
const tuitionInitialCourseId = ref(null);
const courseMgmtFocusStudentId = ref(null);
const courseMgmtFocusStudentName = ref('');
const bindingMgmtFocusStudentName = ref('');
const unreadNotificationCount = ref(0);
const urgentNotificationCount = ref(0);
const inboxNeedsAttentionCount = ref(0);
const inboxUrgentTotal = ref(0);
const inboxCountScopeKey = ref('');
const directorFocusWorkflowId = ref(null);
const directorFocusSection = ref(null);
const profileFocusTab = ref(null);
const badgeByType = ref({});
let unreadPollingTimer = null;
let chatBadgePollingTimer = null;
let feedbackBadgePollingTimer = null;
let _badgePollingPaused = false;

function _startBadgePolling() {
  _stopBadgePolling();
  _badgePollingPaused = false;

  const notifInterval = perfFlags.NOTIFICATION_POLL_INTERVAL;
  const badgeInterval = perfFlags.BADGE_POLL_INTERVAL;
  const feedbackInterval = perfFlags.FEEDBACK_BADGE_POLL_INTERVAL;
  const pauseOnHidden = perfFlags.PAUSE_POLLING_ON_HIDDEN;

  unreadPollingTimer = window.setInterval(() => {
    if (pauseOnHidden && document.visibilityState !== 'visible') return;
    if (_badgePollingPaused) return;
    refreshUnreadNotifications();
  }, notifInterval);

  chatBadgePollingTimer = window.setInterval(() => {
    if (pauseOnHidden && document.visibilityState !== 'visible') return;
    if (_badgePollingPaused) return;
    mergeChatUnreadBadge();
    mergeBugUnreadBadge();
    mergeDirectorPendingBadge();
    mergeScheduleDiscrepancyBadge();
  }, badgeInterval);

  feedbackBadgePollingTimer = window.setInterval(() => {
    if (pauseOnHidden && document.visibilityState !== 'visible') return;
    if (_badgePollingPaused) return;
    mergeParentFeedbackBadge();
    mergeTeacherLearningPendingBadge();
  }, feedbackInterval);
}

function _stopBadgePolling() {
  if (unreadPollingTimer) { clearInterval(unreadPollingTimer); unreadPollingTimer = null; }
  if (chatBadgePollingTimer) { clearInterval(chatBadgePollingTimer); chatBadgePollingTimer = null; }
  if (feedbackBadgePollingTimer) { clearInterval(feedbackBadgePollingTimer); feedbackBadgePollingTimer = null; }
}

function _onVisibilityChangeForPolling() {
  if (document.visibilityState === 'visible' && !_badgePollingPaused) {
    refreshUnreadNotifications();
  }
}


const unreadNotificationLabel = computed(() => {
  const n = inboxNeedsAttentionCount.value > 0 ? inboxNeedsAttentionCount.value : unreadNotificationCount.value;
  return n > 99 ? '99+' : String(n);
});
const unreadFeedbackCount = computed(() => Number(badgeByType.value.parent_feedback?.total || 0));

function getItemBadgeCount(item) {
  if (item?.page === 'notifications') {
    return inboxNeedsAttentionCount.value > 0 ? inboxNeedsAttentionCount.value : unreadNotificationCount.value;
  }
  if (!item?.badgeTypes?.length) return 0;
  return item.badgeTypes.reduce((sum, t) => sum + (badgeByType.value[t]?.total || 0), 0);
}
function isItemBadgeUrgent(item) {
  if (item?.page === 'notifications') return inboxUrgentTotal.value > 0; // red only for urgent_total
  if (!item?.badgeTypes?.length) return false;
  return item.badgeTypes.some((t) => (badgeByType.value[t]?.urgent || 0) > 0);
}

const currentGuidePage = computed(() => (isStandaloneParent.value ? 'parent' : active.value));
const buildTimeDisplay = computed(() => formatBuildTime(__APP_BUILD_TIME__));
const { updateAvailable, dismissed: updateDismissed, dismiss: dismissUpdate, reload: reloadForUpdate } = useUpdateChecker();

function startGuideTour() {
  if (isDirector.value || isTeacher.value) {
    if (guideTour.isOpen.value && guideTour.mode.value === 'onboarding') {
      if (guideTour.isPracticing.value) {
        guideTour.resumeStep();
        return;
      }
      return;
    }
    startRoleOnboarding({ force: true });
    return;
  }
  guideTour.startTour(currentGuidePage.value, { role: role.value });
}

function onboardingUserId() {
  return session.value?.user?.id || userProfile.value?.id || '';
}

function saveRoleOnboardingState(status, stepIndex) {
  writeOnboardingState({
    role: role.value,
    userId: onboardingUserId(),
    status,
    stepIndex,
    version: ROLE_ONBOARDING_VERSION,
  });
}

function clearOnboardingPrompt() {
  onboardingLaunchOpen.value = false;
  onboardingPromptSteps.value = [];
  onboardingPromptState.value = null;
}

function beginPendingRoleOnboarding() {
  const steps = onboardingPromptSteps.value;
  const state = onboardingPromptState.value;
  clearOnboardingPrompt();
  return launchRoleOnboarding({ steps, state });
}

function restartRoleOnboarding() {
  const steps = onboardingPromptSteps.value;
  clearOnboardingPrompt();
  return launchRoleOnboarding({ steps, force: true });
}

function deferRoleOnboarding() {
  const state = onboardingPromptState.value;
  saveRoleOnboardingState('deferred', state?.status === 'in_progress' ? state.stepIndex : 0);
  onboardingAutoStarted = true;
  clearOnboardingPrompt();
}

function dismissOnboardingCompletion() {
  onboardingCompletionVisible.value = false;
  const homePage = isTeacher.value ? 'teacher-home' : 'director';
  if (active.value !== homePage) {
    setActivePage(homePage);
  }
}

function launchRoleOnboarding({ steps = getRoleOnboardingSteps(role.value), state = null, force = false } = {}) {
  const initialIndex = force ? 0 : onboardingStartIndex(state, steps.length);
  if (active.value !== steps[initialIndex]?.page) {
    setActivePage(steps[initialIndex].page);
  }
  const started = guideTour.startOnboarding(steps, {
    initialIndex,
    onNavigate: (page) => {
      if (active.value !== page) setActivePage(page);
    },
    onProgress: (index) => saveRoleOnboardingState('in_progress', index),
    onComplete: (_step, index) => {
      saveRoleOnboardingState('completed', index);
      onboardingCompletionVisible.value = true;
    },
    onSkip: (_step, index) => saveRoleOnboardingState('skipped', index),
  });
  if (started) {
    onboardingAutoStarted = true;
    saveRoleOnboardingState('in_progress', guideTour.stepIndex.value);
  }
  return started;
}

function startRoleOnboarding({ force = false } = {}) {
  if (
    !session.value
    || isStandaloneParent.value
    || isPasswordChangeLocked.value
    || !isOnboardingRole(role.value)
    || guideTour.isOpen.value
  ) return false;

  const state = readOnboardingState({ role: role.value, userId: onboardingUserId() });
  if (!force && !shouldAutoStartOnboarding({
    state,
    firstLogin: !hadAppSessionBeforeLoad,
    version: ROLE_ONBOARDING_VERSION,
  })) return false;

  const steps = getRoleOnboardingSteps(role.value);
  if (!force) {
    onboardingPromptSteps.value = steps;
    onboardingPromptState.value = state;
    onboardingLaunchOpen.value = true;
    onboardingAutoStarted = true;
    return true;
  }
  document.querySelector('.account-menu')?.removeAttribute('open');
  onboardingPromptSteps.value = steps;
  onboardingPromptState.value = state;
  onboardingLaunchOpen.value = true;
  return true;
}

function isAutomatedBrowserSession() {
  return typeof navigator !== 'undefined' && Boolean(navigator.webdriver);
}

function maybeAutoStartOnboarding() {
  if (onboardingAutoStarted || onboardingLaunchOpen.value || !session.value || isPasswordChangeLocked.value) return;
  if (!isOnboardingRole(role.value) || isStandaloneParent.value) return;
  // UI Smoke hits production with WebDriver; skip auto launch so overlays do not
  // block nav/tab clicks (same rationale as release-nudge automated skip).
  if (isAutomatedBrowserSession()) return;
  nextTick(() => {
    nextTick(() => startRoleOnboarding());
  });
}

function onNavigateToSchedule({ teacherId, target }) {
  if (isPasswordChangeLocked.value) {
    active.value = 'profile';
    return;
  }
  initialTeacherIdForNav.value = teacherId ?? null;
  if (target === 'calendar') {
    calendarResetToken.value += 1;
    active.value = 'calendar';
  }
  else active.value = 'course-mgmt';
}

function applyDeepLinkFromUrl() {
  try {
    const { page, section, workflowId, branchId } = parseInboxDeepLinkSearch(window.location.search);
    if (branchId) {
      const safe = resolveAuthorizedBranchId(branchId, branches.value.map((b) => b.id), { allowAny: role.value === 'super_admin' });
      if (safe) currentBranch.value = safe;
    }
    if (page === 'notifications' || page === 'director') active.value = page;
    if (page === 'director' || workflowId) {
      directorFocusSection.value = section || (workflowId ? 'exception-workflows' : null);
      directorFocusWorkflowId.value = workflowId;
      if (workflowId) active.value = 'director';
    }
  } catch { /* ignore */ }
}

function onPopStateDeepLink() {
  applyDeepLinkFromUrl();
}

function normalizeNavigationId(value) {
  const id = Number(value);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}

function clearCalendarNavigationContext() {
  calendarInitialStudentId.value = null;
  calendarInitialCourseId.value = null;
  calendarInitialDate.value = '';
}

function clearTuitionNavigationContext() {
  tuitionInitialStudentId.value = null;
  tuitionInitialCourseId.value = null;
  tuitionInitialTab.value = '';
}

function onNavigateFromNotifications(payload = {}) {
  const {
    target,
    recordId,
    studentId,
    courseId,
    date,
    focus,
    section,
    workflowId,
    intent,
    studentName,
    teacherId,
  } = payload;
  if (isPasswordChangeLocked.value) {
    active.value = 'profile';
    return;
  }
  if (!target) return;
  dashboardReturnContext.value = createDashboardReturnContext({ fromPage: active.value, target });
  if (target === 'calendar') {
    calendarResetToken.value += 1;
    calendarInitialIntent.value = intent || '';
    calendarInitialStudentId.value = normalizeNavigationId(studentId);
    calendarInitialCourseId.value = normalizeNavigationId(courseId);
    calendarInitialDate.value = typeof date === 'string' ? date.slice(0, 10) : '';
  } else {
    calendarInitialIntent.value = '';
    clearCalendarNavigationContext();
  }
  if (target === 'tuition-collect') {
    tuitionInitialTab.value = intent || '';
    tuitionInitialStudentId.value = normalizeNavigationId(studentId);
    tuitionInitialCourseId.value = normalizeNavigationId(courseId);
  } else {
    clearTuitionNavigationContext();
  }
  if (target === 'learning' && recordId) {
    learningTargetRecordId.value = Number(recordId);
  } else {
    learningTargetRecordId.value = null;
  }
  if (target === 'students') {
    const normalizedStudentId = Number(studentId);
    const normalizedCourseId = Number(courseId);
    studentFocusIdForNav.value = Number.isSafeInteger(normalizedStudentId) && normalizedStudentId > 0
      ? normalizedStudentId
      : null;
    studentFocusCourseIdForNav.value = Number.isSafeInteger(normalizedCourseId) && normalizedCourseId > 0
      ? normalizedCourseId
      : null;
    studentFocusIntentForNav.value = typeof intent === 'string' ? intent : '';
  } else {
    studentFocusIdForNav.value = null;
    studentFocusCourseIdForNav.value = null;
    studentFocusIntentForNav.value = '';
  }
  if (target === 'course-mgmt') {
    courseMgmtFocusStudentId.value = normalizeNavigationId(studentId);
    courseMgmtFocusStudentName.value = typeof studentName === 'string' ? studentName.trim() : '';
    if (teacherId != null && teacherId !== '') {
      initialTeacherIdForNav.value = normalizeNavigationId(teacherId);
    }
  } else {
    clearCourseMgmtNavigationContext();
  }
  if (target === 'binding-management') {
    bindingMgmtFocusStudentName.value = typeof studentName === 'string'
      ? studentName.trim()
      : '';
  } else {
    bindingMgmtFocusStudentName.value = '';
  }
  if (target === 'learning' && focus === 'feedback') {
    learningFeedbackFocusToken.value += 1;
  }
  if (target === 'director') {
    directorFocusSection.value = section || 'exception-workflows';
    directorFocusWorkflowId.value = workflowId ? Number(workflowId) : null;
    try {
      const q = buildInboxDeepLinkQuery({
        page: 'director',
        section: directorFocusSection.value,
        workflowId: directorFocusWorkflowId.value,
        branchId: currentBranch.value,
      });
      // Preserve unrelated query keys.
      const cur = new URLSearchParams(window.location.search);
      for (const [k, v] of q.entries()) cur.set(k, v);
      if (!directorFocusWorkflowId.value) cur.delete('workflow_id');
      window.history.pushState({ page: 'director', workflowId: directorFocusWorkflowId.value }, '', `${window.location.pathname}?${cur}${window.location.hash || ''}`);
    } catch { /* ignore */ }
  } else {
    directorFocusSection.value = null;
    directorFocusWorkflowId.value = null;
  }
  profileFocusTab.value = (target === 'profile' && section === 'notifications') ? 'notifications' : null;
  active.value = target;
}

function onNavigateFromCourseManagement(payload) {
  if (typeof payload === 'string') {
    active.value = payload;
    return;
  }
  onNavigateFromNotifications(payload || {});
}

function clearStudentNavigationContext() {
  studentFocusIdForNav.value = null;
  studentFocusCourseIdForNav.value = null;
  studentFocusIntentForNav.value = '';
}

function clearCourseMgmtNavigationContext() {
  courseMgmtFocusStudentId.value = null;
  courseMgmtFocusStudentName.value = '';
}

let skipTeacherNavSfxOnce = false;

function onNavigateLearningFromTeacherHome(payload = {}) {
  const targetBranchId = Number(payload?.branchId || 0);
  if (targetBranchId > 0) {
    currentBranch.value = targetBranchId;
  }
  if (payload?.listOnly) {
    learningTargetRecordId.value = null;
    learningTargetSession.value = null;
  } else {
    learningTargetRecordId.value = payload?.recordId || null;
    // 帶上目標分校：補填提醒可跨分校，currentBranch 的 prop 更新與 target 的 watcher
    // 可能同 tick 競態；讓 LearningRecordsPage 用此分校查課次，避免查無該堂。(#54 / #82)
    learningTargetSession.value = payload?.classSessionId
      ? {
          classSessionId: payload.classSessionId,
          sessionDate: payload.sessionDate,
          branchId: targetBranchId > 0 ? targetBranchId : null,
        }
      : null;
  }
  if (payload?.focus === 'awaiting_reply' || payload?.focus === 'feedback') {
    learningFeedbackFocusToken.value += 1;
  }
  const isTaskJump = isTeacher.value
    && !payload?.listOnly
    && Boolean(payload?.classSessionId || payload?.recordId);
  if (isTaskJump) {
    playTeacherUiSfx('action');
    skipTeacherNavSfxOnce = true;
  }
  setActivePage('learning');
}

function openSubmittedBug(bugId) {
  focusBugId.value = bugId || null;
  setActivePage('bugs');
}

function clearBugNavigationContext() {
  focusBugId.value = null;
}

function setActivePage(page) {
  closeSidebarMore(false);
  closeMoreMenu(false);
  dashboardReturnContext.value = null;
  if (page !== 'students') clearStudentNavigationContext();
  if (page !== 'calendar') clearCalendarNavigationContext();
  if (page !== 'tuition-collect') clearTuitionNavigationContext();
  if (page !== 'course-mgmt') clearCourseMgmtNavigationContext();
  if (page !== 'bugs') clearBugNavigationContext();
  const prev = active.value;
  if (isPasswordChangeLocked.value && page !== 'profile') {
    active.value = 'profile';
    if (isTeacher.value && prev !== 'profile') {
      if (skipTeacherNavSfxOnce) {
        skipTeacherNavSfxOnce = false;
      } else {
        playTeacherUiSfx('nav');
      }
    }
    return;
  }
  if (page === 'calendar') {
    calendarResetToken.value += 1;
  }
  active.value = page;
  if (isTeacher.value && page !== prev) {
    if (skipTeacherNavSfxOnce) {
      skipTeacherNavSfxOnce = false;
    } else {
      playTeacherUiSfx('nav');
    }
  } else if (skipTeacherNavSfxOnce && page === prev) {
    skipTeacherNavSfxOnce = false;
  }
  if (page === 'release-notes') {
    markReleaseNotesSeen();
  }
  if (page === 'learning') {
    mergeParentFeedbackBadge();
    mergeTeacherLearningPendingBadge();
    if (isTeacher.value) {
      window.dispatchEvent(new CustomEvent('alltrue-teacher-learning-progress-refresh'));
    }
  }
  if (page === 'teacher-home' && isTeacher.value) {
    window.dispatchEvent(new CustomEvent('alltrue-teacher-learning-progress-refresh'));
  }
}

function returnToDashboard() {
  setActivePage('director');
}

function isNavItemDisabled(page) {
  return isPasswordChangeLocked.value && page !== 'profile';
}

function onUnreadChange(count) {
  if (typeof count === 'number') { unreadNotificationCount.value = Number(count || 0); return; }
  if (count && typeof count === 'object') {
    unreadNotificationCount.value = Number(count.unread ?? 0);
    if (count.urgentTotal != null) {
      inboxUrgentTotal.value = Number(count.urgentTotal || 0);
      urgentNotificationCount.value = inboxUrgentTotal.value;
    } else {
      urgentNotificationCount.value = Number(count.urgent ?? 0);
    }
    if (count.needsAttention != null) inboxNeedsAttentionCount.value = Number(count.needsAttention || 0);
  }
}

// Prefer role from session (set by backend at login); profile API only returns teachers, so directors/super_admin would get wrong role otherwise
const role = computed(() => session.value?.user?.role ?? userProfile.value?.role ?? 'student');
const isDirector = computed(() => role.value === 'director' || role.value === 'admin' || role.value === 'super_admin');
const isTeacher = computed(() => role.value === 'teacher');

const isPasswordChangeLocked = computed(() => {
  const fromSession = session.value?.user?.must_change_password;
  const fromProfile = userProfile.value?.must_change_password;
  return Boolean(fromSession || fromProfile);
});

// ── #769 敏感頁 PIN 二次驗證（Phase B 前端 gate）─────────────────────────
// 受保護 active 頁（後端 require_pin 為真正邊界，此處為 UX gate）。
// 純判定邏輯與常數集中於 lib/pinGate.js（有單元測試）。
const pinUnlocked = ref(false);
const pinSoftSkip = ref(false);             // soft：本 session 未設 PIN 者選擇略過
let pinUnlockTimer = null;
let pinIdleTimer = null;
let pinHiddenAt = 0;

// 進受保護頁、未解鎖、未 soft 略過 → 掛 PinLockModal 並擋住內容。
const pinModalActive = computed(() => shouldShowPinModal({
  page: active.value,
  role: role.value,
  hasSession: Boolean(session.value),
  passwordLocked: isPasswordChangeLocked.value,
  unlocked: pinUnlocked.value,
  softSkip: pinSoftSkip.value,
}));

function onPinUnlocked() {
  pinUnlocked.value = true;
  pinSoftSkip.value = false;
  if (pinUnlockTimer) clearTimeout(pinUnlockTimer);
  pinUnlockTimer = window.setTimeout(() => { pinUnlocked.value = false; }, PIN_UNLOCK_TTL_MS);
  schedulePinIdleLock();
}

function onPinSkip() {
  pinSoftSkip.value = true;
}

function onPinDismiss() {
  // 不可停在受保護內容上 → 退回安全頁。
  active.value = isDirector.value ? 'director' : 'calendar';
}

async function lockPinNow() {
  const wasUnlocked = pinUnlocked.value;
  pinUnlocked.value = false;
  pinSoftSkip.value = false;
  if (pinUnlockTimer) { clearTimeout(pinUnlockTimer); pinUnlockTimer = null; }
  if (pinIdleTimer) { clearTimeout(pinIdleTimer); pinIdleTimer = null; }
  if (!wasUnlocked) return;
  // 清後端 pin_verified_until（fire-and-forget）。
  try {
    const token = session.value?.access_token;
    if (!token) return;
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    await fetch(`${baseUrl}/v1/me/pin/lock`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      keepalive: true,
    });
  } catch (_) { /* ignore */ }
}

function schedulePinIdleLock() {
  if (pinIdleTimer) { clearTimeout(pinIdleTimer); pinIdleTimer = null; }
  if (!pinUnlocked.value) return;
  pinIdleTimer = window.setTimeout(() => { lockPinNow(); }, PIN_IDLE_LOCK_MS);
}

function onPinActivity() {
  if (pinUnlocked.value) schedulePinIdleLock();
}

function onPinVisibilityChange() {
  if (typeof document === 'undefined') return;
  if (document.visibilityState === 'hidden') {
    pinHiddenAt = Date.now();
  } else if (shouldBlurLock(pinUnlocked.value, pinHiddenAt, Date.now())) {
    lockPinNow();
    pinHiddenAt = 0;
  } else {
    pinHiddenAt = 0;
  }
}

watch(
  () => ({
    uid: session.value?.user?.id,
    teacher: isTeacher.value,
    locked: isPasswordChangeLocked.value,
  }),
  ({ uid, teacher, locked }) => {
    if (!uid || !teacher || locked) return;
    try {
      recordTeacherVisitToday();
    } catch {
      /* ignore */
    }
  },
  { immediate: true },
);
const roleLabel = computed(() => {
  if (role.value === 'super_admin') return '超級管理員 Super Admin';
  if (isDirector.value) return '主任 Director';
  if (isTeacher.value) return '老師 Teacher';
  return role.value;
});
const avatarLetter = computed(() => {
  const name = userProfile.value?.username || session.value?.user?.name || 'U';
  return name.charAt(0).toUpperCase();
});
const avatarUrl = computed(() => userProfile.value?.avatar_url || '');

const sidebarGroupOpen = ref({});
const sidebarNavGroups = computed(() => getNavigationGroups(role.value, { admissionsEnabled: perfFlags.ADMISSIONS_FUNNEL_V1 }));
const sidebarPrimaryGroups = computed(() => sidebarNavGroups.value.filter(group => group.primary !== false));
const sidebarMoreGroups = computed(() => sidebarNavGroups.value.filter(group => group.primary === false));
const activeInSidebarMore = computed(() => sidebarMoreGroups.value.some(
  group => group.items.some(item => item.page === active.value),
));
const sidebarMoreBadgeCount = computed(() => sidebarMoreGroups.value.reduce(
  (sum, group) => sum + group.items.reduce((groupSum, item) => groupSum + getItemBadgeCount(item), 0),
  0,
));

function toggleSidebarMore() {
  if (showSidebarMore.value) {
    closeSidebarMore();
    return;
  }
  showSidebarMore.value = true;
}

function closeSidebarMore(restoreFocus = true) {
  const wasOpen = showSidebarMore.value;
  showSidebarMore.value = false;
  if (restoreFocus && wasOpen) {
    nextTick(() => document.querySelector('#sidebar-more-trigger')?.focus());
  }
}

function toggleMoreMenu() {
  if (showMoreMenu.value) {
    closeMoreMenu();
    return;
  }
  showMoreMenu.value = true;
}

function closeMoreMenu(restoreFocus = true) {
  const wasOpen = showMoreMenu.value;
  showMoreMenu.value = false;
  if (restoreFocus && wasOpen) {
    nextTick(() => document.querySelector('#mobile-more-trigger')?.focus());
  }
}

function isSidebarGroupOpen(group) {
  return Object.prototype.hasOwnProperty.call(sidebarGroupOpen.value, group.key)
    ? sidebarGroupOpen.value[group.key]
    : group.defaultOpen !== false;
}

function onSidebarGroupToggle(key, event) {
  sidebarGroupOpen.value = { ...sidebarGroupOpen.value, [key]: event.target.open };
}

/** 底欄「更多」：加總未固定在底欄的選項之未讀（含主任收件匣）。 */
const moreMenuBadgeCount = computed(() => {
  let sum = 0;
  for (const group of sidebarNavGroups.value) {
    for (const item of group.items) {
      if (!mobileTabPages.value.has(item.page)) {
        sum += getItemBadgeCount(item);
      }
    }
  }
  return sum;
});

function getMobileTabBadgeCount(tab) {
  if (tab?.page === 'more') return moreMenuBadgeCount.value;
  return getItemBadgeCount(tab);
}

/** 「更多」抽屜內單一列的 badge（收件匣用 needs_attention，非單純未讀）。 */
function getMoreSheetItemBadgeCount(item) {
  return getItemBadgeCount(item);
}

const teacherBranches = computed(() => {
  const ids = userProfile.value?.branch_ids;
  if (!ids || ids.length === 0) return [];
  const idSet = new Set(ids.map(Number));
  return branches.value.filter(b => idSet.has(b.id));
});

function ensureTeacherBranch() {
  if (!isTeacher.value) return;
  const allowed = teacherBranches.value;
  if (allowed.length > 0) {
    const allowedIds = new Set(allowed.map(b => b.id));
    if (currentBranch.value != null && allowedIds.has(currentBranch.value)) return;
    const preferred = allowed[0].id;
    currentBranch.value = preferred;
    localStorage.setItem('app_branch', String(preferred));
  } else if (branches.value.length > 0) {
    if (currentBranch.value != null && branches.value.some(b => b.id === currentBranch.value)) return;
    currentBranch.value = branches.value[0].id;
    localStorage.setItem('app_branch', String(branches.value[0].id));
  }
}

// When director/super_admin: load branches from authenticated /api/v1/campuses and set currentBranch
async function ensureDirectorBranches() {
    const s = session.value;
    if (!s?.user) return;
    const r = s.user.role ?? userProfile.value?.role;
    if (r !== 'director' && r !== 'admin' && r !== 'super_admin') return;

    let list = [];
    if (s?.access_token) list = await loadBranchesForDirector(s.access_token);
    // Fallback for super_admin: use public branches if /campuses fails or returns empty
    if (list.length === 0 && (r === 'super_admin' || r === 'admin')) {
        await loadBranches();
        list = branches.value;
    }
    if (list.length > 0) {
        branches.value = list;
        const savedBranch = localStorage.getItem('app_branch');
        const resolved = resolveSavedBranchChoice(savedBranch, list);
        if (resolved != null) {
            currentBranch.value = resolved;
            if (savedBranch != null && String(resolved) !== String(savedBranch).trim()) {
                localStorage.setItem('app_branch', String(resolved));
            }
        } else {
            currentBranch.value = list[0].id;
        }
    }
}

// Auth Listener
onMounted(async () => {
    // 系統主題監聽
    systemThemeMq?.addEventListener('change', onSystemThemeChange);
    window.addEventListener('popstate', onPopStateDeepLink);

    // Branches and session are independent: start both so a slow public branch
    // request does not delay auth/profile initialization.
    const branchesPromise = loadBranches();
    const sessionPromise = supabase.auth.getSession();
    const { data } = await sessionPromise;
    await branchesPromise;

    // Restore saved branch or use first branch as default
    const savedBranch = localStorage.getItem('app_branch');
    const resolved = resolveSavedBranchChoice(savedBranch, branches.value);
    if (resolved != null) {
        currentBranch.value = resolved;
        if (savedBranch != null && String(resolved) !== String(savedBranch).trim()) {
            localStorage.setItem('app_branch', String(resolved));
        }
    } else {
        currentBranch.value = getDefaultBranchId();
    }

    session.value = data.session;

    if (session.value) {
        await fetchProfile(session.value.user.id);
        await ensureDirectorBranches();
        triggerBrandIntroOncePerSessionToken();
    }
    loading.value = false;

    supabase.auth.onAuthStateChange(async (_event, _session) => {
        session.value = _session;
        if (_session) {
            await fetchProfile(_session.user.id);
            await ensureDirectorBranches();
            triggerBrandIntroOncePerSessionToken();
        } else {
            userProfile.value = null;
        }
    });

    _startBadgePolling();
    document.addEventListener('visibilitychange', _onVisibilityChangeForPolling);
    await refreshUnreadNotifications();
    if (session.value && isDirector.value) applyDeepLinkFromUrl();

    window.addEventListener('resize', onWindowResizeGuideFab);
    window.addEventListener('alltrue-refresh-badges', onRefreshBadgesEvent);
    ['pointerdown', 'keydown', 'wheel', 'touchstart', 'scroll'].forEach((eventName) => {
      window.addEventListener(eventName, onBrandActivity, { passive: true });
      window.addEventListener(eventName, onPinActivity, { passive: true });
    });
    document.addEventListener('visibilitychange', onPinVisibilityChange);
    scheduleBrandIdleOverlay();
});

const fetchProfile = async (_uid) => {
    const token = session.value?.access_token;
    if (!token) {
        userProfile.value = null;
        return;
    }

    try {
        const res = await fetch('/api/v1/me', {
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json',
            },
        });

        if (res.status === 401) {
            await supabase.auth.signOut();
            session.value = null;
            userProfile.value = null;
            return;
        }

        if (!res.ok) {
            userProfile.value = null;
            return;
        }

        const me = await res.json();
        const mustChangePassword = Boolean(me?.must_change_password);
        userProfile.value = {
            id: me.id,
            username: me.name,
            email: me.email,
            phone: me.phone || '',
            avatar_url: me.avatar_url || '',
            role: me.role,
            branch_ids: Array.isArray(me.campuses) ? me.campuses : [],
            must_change_password: mustChangePassword,
            engagement: me.engagement ?? null,
        };

        if (session.value?.user) {
          session.value.user.must_change_password = mustChangePassword;
          localStorage.setItem('alltrue_session', JSON.stringify(session.value));
        }

        // Profile refresh must not yank the user back to role home after they
        // already navigated (login → onAuthStateChange /me race). Only seed
        // bootstrap / role-mismatch landings via resolveActiveAfterProfileLoad.
        const nextActive = resolveActiveAfterProfileLoad({
          role: me.role,
          mustChangePassword,
          currentActive: active.value,
        });
        if (nextActive !== active.value) active.value = nextActive;
        if (mustChangePassword) return;
        if (me.role === 'teacher') {
            ensureTeacherBranch();
        } else if (me.role === 'director' || me.role === 'admin' || me.role === 'super_admin') {
            applyDeepLinkFromUrl();
        }
    } catch {
        userProfile.value = null;
    }
};

const handleLoginSuccess = async ({ user, profile }) => {
    // Session is already set by supabase.auth (signInWithPassword stores it)
    const { data } = await supabase.auth.getSession();
    session.value = data.session;
    userProfile.value = profile ?? null;

    const mustChangePassword = Boolean(profile?.must_change_password ?? session.value?.user?.must_change_password);
    if (session.value?.user) {
      session.value.user.must_change_password = mustChangePassword;
      localStorage.setItem('alltrue_session', JSON.stringify(session.value));
    }

    if (mustChangePassword) active.value = 'profile';
    else if ((profile?.role ?? session.value?.user?.role) === 'teacher') active.value = 'teacher-home';
    else if ((profile?.role ?? session.value?.user?.role) === 'director' || session.value?.user?.role === 'super_admin') {
      active.value = 'director'; applyDeepLinkFromUrl();
    }
    await ensureDirectorBranches();
    ensureTeacherBranch();
    triggerBrandIntroOncePerSessionToken();
};

const onProfileUpdated = async (updated) => {
  const currentActive = active.value;
  if (session.value?.user) {
    if (updated?.name) session.value.user.name = updated.name;
    if (updated?.email) session.value.user.email = updated.email;
    if (typeof updated?.must_change_password === 'boolean') {
      session.value.user.must_change_password = updated.must_change_password;
    }
    localStorage.setItem('alltrue_session', JSON.stringify(session.value));
  }
  if (updated?.avatar_url && userProfile.value) {
    userProfile.value.avatar_url = updated.avatar_url;
  }
  await fetchProfile(session.value?.user?.id);
  if (currentActive === 'profile') {
    active.value = 'profile';
  }
};

const onPasswordChangeComplete = async () => {
  if (session.value?.user) {
    session.value.user.must_change_password = false;
    localStorage.setItem('alltrue_session', JSON.stringify(session.value));
  }
  await fetchProfile(session.value?.user?.id);
  if (!isPasswordChangeLocked.value) {
    if (isTeacher.value) active.value = 'teacher-home';
    else if (isDirector.value) active.value = 'director';
  }
};

const logout = async () => {
    const uid = session.value?.user?.id;
    if (uid) clearAllDraftsByTeacher(uid);
    await supabase.auth.signOut();
};

watch(showMoreMenu, (open) => {
  if (open) lockScroll();
  else unlockScroll();
  if (open) {
    nextTick(() => document.querySelector('#mobile-more-sheet')?.focus());
  }
});

watch(showSidebarMore, (open) => {
  if (!open) return;
  nextTick(() => document.querySelector('#sidebar-more-panel')?.focus());
});

watch(currentBranch, (value, previous) => {
  localStorage.setItem('app_branch', value);
  if (previous != null && value !== previous) {
    unreadNotificationCount.value = 0;
    urgentNotificationCount.value = 0;
    inboxNeedsAttentionCount.value = 0;
    inboxUrgentTotal.value = 0;
    inboxCountScopeKey.value = '';
  }
  refreshUnreadNotifications();
});

watch([active, isStandaloneParent], async ([p]) => {
  const onboardingContinues = guideTour.mode.value === 'onboarding';
  guideTour.handlePageChange();
  // #143 防護：切換頁面時強制清除任何殘留的 scroll lock（body position:fixed/overflow:hidden）
  // 與行動版選單，避免某頁洩漏的鎖讓下一頁看起來被灰白遮罩蓋住、無法點選。
  showMoreMenu.value = false;
  showSidebarMore.value = false;
  if (!onboardingContinues) forceUnlockScroll();
  if (p !== 'bugs' || !session.value?.access_token || !currentBranch.value) return;
  if (role.value !== 'super_admin') return;
  try {
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const res = await fetch(`${baseUrl}/v1/bugs/mark-inbox-seen`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${session.value.access_token}`,
        Accept: 'application/json',
      },
    });
    if (res.ok) {
      await refreshUnreadNotifications();
    }
  } catch { /* ignore */ }
});

watch(guidePopoverRef, (el) => {
  guideTour.setPopoverElement(el);
});

watch([session, role], () => {
  if (!session.value) {
    guideTour.closeTour();
    onboardingAutoStarted = false;
    clearOnboardingPrompt();
    dismissOnboardingCompletion();
  }
  refreshUnreadNotifications();
  maybeAutoStartOnboarding();
});

watch(isPasswordChangeLocked, (locked) => {
  if (locked) {
    guideTour.closeTour();
    active.value = 'profile';
  }
});

watch([session, role, isPasswordChangeLocked], () => {
  if (!session.value || isPasswordChangeLocked.value) {
    releaseNudgeOpen.value = false;
    return;
  }
  if (!isDirector.value && !isTeacher.value) {
    releaseNudgeOpen.value = false;
    return;
  }
  const latestVersion = latestReleaseVersionForRole(role.value);
  releaseNudgeVersion.value = latestVersion;
  if (!latestVersion) {
    releaseNudgeOpen.value = false;
    return;
  }
  const seenVersion = localStorage.getItem(RELEASE_NOTES_SEEN_KEY) || '';
  // Playwright / WebDriver sessions hit production UI Smoke; skip the modal so
  // pointer-event layers do not block nav/tab clicks (force-click never runs Vue).
  const automated = typeof navigator !== 'undefined' && Boolean(navigator.webdriver);
  releaseNudgeOpen.value = !automated && seenVersion !== latestVersion;
});

watch(brandOverlayAllowed, (allowed) => {
  if (!allowed) {
    brandOverlayVisible.value = false;
    if (brandIdleTimer) {
      clearTimeout(brandIdleTimer);
      brandIdleTimer = null;
    }
    return;
  }
  scheduleBrandIdleOverlay();
});

function onRefreshBadgesEvent() {
  refreshUnreadNotifications();
}

onBeforeUnmount(() => {
  systemThemeMq?.removeEventListener('change', onSystemThemeChange);
  window.removeEventListener('popstate', onPopStateDeepLink);
  guideTour.closeTour();
  clearOnboardingPrompt();
  dismissOnboardingCompletion();
  _stopBadgePolling();
  document.removeEventListener('visibilitychange', _onVisibilityChangeForPolling);
  window.removeEventListener('resize', onWindowResizeGuideFab);
  window.removeEventListener('alltrue-refresh-badges', onRefreshBadgesEvent);
  ['pointerdown', 'keydown', 'wheel', 'touchstart', 'scroll'].forEach((eventName) => {
    window.removeEventListener(eventName, onBrandActivity);
    window.removeEventListener(eventName, onPinActivity);
  });
  document.removeEventListener('visibilitychange', onPinVisibilityChange);
  if (pinUnlockTimer) clearTimeout(pinUnlockTimer);
  if (pinIdleTimer) clearTimeout(pinIdleTimer);
  clearBrandTimers();
});

async function mergeBugUnreadBadge() {
  if (!session.value?.access_token || (!isDirector.value && !isTeacher.value) || !currentBranch.value || isPasswordChangeLocked.value) {
    const next = { ...badgeByType.value };
    delete next.bugs;
    badgeByType.value = next;
    return;
  }
  try {
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const params = new URLSearchParams({ branch_id: String(currentBranch.value) });
    const res = await fetch(`${baseUrl}/v1/bugs/unread-badge?${params}`, {
      headers: {
        Authorization: `Bearer ${session.value.access_token}`,
        Accept: 'application/json',
      },
    });
    if (!res.ok) throw new Error('bug unread-badge failed');
    const json = await res.json();
    const n = Number(json.unread_count || 0);
    const next = { ...badgeByType.value };
    next.bugs = { total: n, urgent: n > 0 ? n : 0 };
    badgeByType.value = next;
  } catch {
    const next = { ...badgeByType.value };
    delete next.bugs;
    badgeByType.value = next;
  }
}

async function refreshUnreadNotifications() {
  if (!session.value?.access_token || !currentBranch.value) {
    unreadNotificationCount.value = 0;
    urgentNotificationCount.value = 0;
    inboxNeedsAttentionCount.value = 0;
    badgeByType.value = {};
    await mergeBugUnreadBadge();
    await mergeChatUnreadBadge();
    await mergeDirectorPendingBadge();
    return;
  }

  if (isDirector.value) {
    const scope = inboxScopeKey(currentBranch.value);
    try {
      const baseUrl = import.meta.env.VITE_API_BASE || '/api';
      const params = new URLSearchParams({ branch_id: String(currentBranch.value) });
      const hdrs = { Authorization: `Bearer ${session.value.access_token}`, Accept: 'application/json' };
      const [notifRes, inboxRes] = await Promise.all([
        fetch(`${baseUrl}/v1/notifications/unread-count?${params}`, { headers: hdrs }),
        fetch(`${baseUrl}/v1/action-inbox/count?${params}`, { headers: hdrs }),
      ]);
      if (notifRes.status === 401 || inboxRes.status === 401) {
        await supabase.auth.signOut();
        session.value = null;
        await mergeBugUnreadBadge();
        await mergeChatUnreadBadge();
        return;
      }
      if (!notifRes.ok) throw new Error('unread-count request failed');
      const json = await notifRes.json();
      unreadNotificationCount.value = Number(json.unread_count || 0);
      urgentNotificationCount.value = Number(json.urgent_unread_count || 0);
      badgeByType.value = json.by_type || {};

      if (!inboxRes.ok) throw new Error('action-inbox count failed');
      const c = parseInboxCount(await inboxRes.json());
      unreadNotificationCount.value = Number(c.notificationsUnread || 0);
      inboxNeedsAttentionCount.value = Number(c.badgeTotal || 0);
      inboxUrgentTotal.value = Number(c.urgentTotal || 0);
      inboxCountScopeKey.value = scope;
    } catch {
      const prev = { notificationsUnread: unreadNotificationCount.value, casesUnresolved: 0, casesOverdue: 0, casesDueSoon: 0, casesCandidateReady: 0, urgentTotal: inboxUrgentTotal.value, badgeTotal: inboxNeedsAttentionCount.value };
      const merged = mergeInboxCountState(prev, null, { failed: true, scopeKey: scope, prevScopeKey: inboxCountScopeKey.value || null });
      unreadNotificationCount.value = merged.notificationsUnread;
      inboxNeedsAttentionCount.value = merged.badgeTotal;
      inboxUrgentTotal.value = merged.urgentTotal;
      if (merged.scopeInvalidated) inboxCountScopeKey.value = scope;
    }
  } else {
    unreadNotificationCount.value = 0;
    urgentNotificationCount.value = 0;
    badgeByType.value = {};
  }

  await mergeBugUnreadBadge();
  await mergeChatUnreadBadge();
  await mergeDirectorPendingBadge();
  await mergeTeacherAttendanceBadge();
  await mergeScheduleDiscrepancyBadge();
  await mergeParentFeedbackBadge();
  await mergeTeacherLearningPendingBadge();
}

async function mergeTeacherLearningPendingBadge() {
  if (!session.value?.access_token || !isTeacher.value || !currentBranch.value || isPasswordChangeLocked.value) {
    const next = { ...badgeByType.value };
    delete next.teacher_learning_pending;
    badgeByType.value = next;
    return;
  }
  try {
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const params = new URLSearchParams({ branch_id: String(currentBranch.value) });
    const res = await fetch(`${baseUrl}/v1/me/learning-pending-summary?${params}`, {
      headers: {
        Authorization: `Bearer ${session.value.access_token}`,
        Accept: 'application/json',
      },
    });
    if (!res.ok) throw new Error('learning pending summary failed');
    const json = await res.json();
    const total = Number(json.total || 0);
    const next = { ...badgeByType.value };
    if (total > 0) {
      next.teacher_learning_pending = { total, urgent: total };
    } else {
      delete next.teacher_learning_pending;
    }
    badgeByType.value = next;
  } catch {
    const next = { ...badgeByType.value };
    delete next.teacher_learning_pending;
    badgeByType.value = next;
  }
}

async function mergeParentFeedbackBadge() {
  if (!session.value?.access_token || (!isDirector.value && !isTeacher.value) || !currentBranch.value || isPasswordChangeLocked.value) {
    const next = { ...badgeByType.value };
    delete next.parent_feedback;
    badgeByType.value = next;
    return;
  }
  try {
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const params = new URLSearchParams();
    if (isDirector.value && currentBranch.value) {
      params.set('branch_id', String(currentBranch.value));
    }
    const qs = params.toString();
    const res = await fetch(`${baseUrl}/v1/me/unread-feedback-count${qs ? `?${qs}` : ''}`, {
      headers: {
        Authorization: `Bearer ${session.value.access_token}`,
        Accept: 'application/json',
      },
    });
    if (!res.ok) throw new Error('parent feedback unread count failed');
    const json = await res.json();
    const n = Number(json.count || 0);
    const next = { ...badgeByType.value };
    if (n > 0) {
      next.parent_feedback = { total: n, urgent: n };
    } else {
      delete next.parent_feedback;
    }
    badgeByType.value = next;
  } catch {
    const next = { ...badgeByType.value };
    delete next.parent_feedback;
    badgeByType.value = next;
  }
}

async function mergeScheduleDiscrepancyBadge() {
  if (!session.value?.access_token || !isDirector.value || !currentBranch.value || isPasswordChangeLocked.value) {
    const next = { ...badgeByType.value };
    delete next.schedule_discrepancy;
    badgeByType.value = next;
    return;
  }
  try {
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const params = new URLSearchParams({ branch_id: String(currentBranch.value) });
    const res = await fetch(`${baseUrl}/v1/schedule-discrepancies/summary?${params}`, {
      headers: {
        Authorization: `Bearer ${session.value.access_token}`,
        Accept: 'application/json',
      },
    });
    if (!res.ok) throw new Error('schedule-discrepancy summary failed');
    const json = await res.json();
    const n = Number(json.pending || 0);
    const next = { ...badgeByType.value };
    if (n > 0) {
      next.schedule_discrepancy = { total: n, urgent: n };
    } else {
      delete next.schedule_discrepancy;
    }
    badgeByType.value = next;
  } catch {
    const next = { ...badgeByType.value };
    delete next.schedule_discrepancy;
    badgeByType.value = next;
  }
}

async function mergeTeacherAttendanceBadge() {
  if (!session.value?.access_token || !isTeacher.value || !currentBranch.value || isPasswordChangeLocked.value) {
    if (isTeacher.value) {
      const next = { ...badgeByType.value };
      delete next.attendance;
      badgeByType.value = next;
    }
    return;
  }
  try {
    const d = new Date();
    const today = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const qs = new URLSearchParams({ start: today, end: today, per_page: '500' });
    const res = await fetch(`${baseUrl}/v1/class-sessions?${qs}`, {
      headers: { Authorization: `Bearer ${session.value.access_token}`, Accept: 'application/json' },
    });
    if (!res.ok) throw new Error('teacher attendance badge failed');
    const json = await res.json();
    const rows = Array.isArray(json?.data) ? json.data : (Array.isArray(json) ? json : []);
    const pending = rows.filter(r => String(r?.status || '').toLowerCase() === 'scheduled').length;
    const next = { ...badgeByType.value };
    next.attendance = { total: pending, urgent: pending > 0 ? pending : 0 };
    badgeByType.value = next;
  } catch {
    const next = { ...badgeByType.value };
    delete next.attendance;
    badgeByType.value = next;
  }
}

async function mergeDirectorPendingBadge() {
  if (!session.value?.access_token || role.value !== 'super_admin' || isPasswordChangeLocked.value) {
    const next = { ...badgeByType.value };
    delete next.director_pending;
    badgeByType.value = next;
    return;
  }
  try {
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const res = await fetch(`${baseUrl}/v1/directors/pending`, {
      headers: {
        Authorization: `Bearer ${session.value.access_token}`,
        Accept: 'application/json',
      },
    });
    if (!res.ok) throw new Error('directors pending failed');
    const data = await res.json();
    const list = Array.isArray(data) ? data : [];
    const n = list.length;
    const next = { ...badgeByType.value };
    next.director_pending = { total: n, urgent: n > 0 ? n : 0 };
    badgeByType.value = next;
  } catch {
    const next = { ...badgeByType.value };
    delete next.director_pending;
    badgeByType.value = next;
  }
}

async function mergeChatUnreadBadge() {
  if (!session.value?.access_token || (!isDirector.value && !isTeacher.value) || !currentBranch.value || isPasswordChangeLocked.value) {
    const next = { ...badgeByType.value };
    delete next.chat;
    badgeByType.value = next;
    return;
  }
  try {
    const data = await fetchChatUnreadCount(String(currentBranch.value));
    const n = Number(data.unread_count || 0);
    const next = { ...badgeByType.value };
    next.chat = { total: n, urgent: n > 0 ? n : 0 };
    badgeByType.value = next;
  } catch {
    const next = { ...badgeByType.value };
    delete next.chat;
    badgeByType.value = next;
  }
}

watch(active, (p) => {
  if (p !== 'bugs') return;
  if (!session.value?.access_token || !currentBranch.value || isPasswordChangeLocked.value) return;
  if (!isDirector.value && !isTeacher.value) return;
  mergeBugUnreadBadge();
});

watch(active, (p) => {
  if (p !== 'director-accounts') return;
  if (!session.value?.access_token || role.value !== 'super_admin') return;
  mergeDirectorPendingBadge();
});

watch(active, (p) => {
  if (p !== 'teachers') return;
  if (!session.value?.access_token || !currentBranch.value || isPasswordChangeLocked.value) return;
  refreshUnreadNotifications();
});

function formatBuildTime(rawIso) {
  const source = String(rawIso || '').trim();
  if (!source) return 'unknown';
  // 純日期字串（YYYY-MM-DD）直接回傳，避免 new Date() 解析成 UTC 午夜
  // 再轉本地時間後偏移 +8 小時變成「早上 8:00」的問題
  if (/^\d{4}-\d{2}-\d{2}$/.test(source)) return source;
  const date = new Date(source);
  if (Number.isNaN(date.getTime())) return source;
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  const hh = String(date.getHours()).padStart(2, '0');
  const mm = String(date.getMinutes()).padStart(2, '0');
  return `${y}-${m}-${d} ${hh}:${mm}`;
}
</script>

<style scoped>
/* 主內容頂列左側：建置時間固定可見（桌機／手機） */
.build-stamp-bar {
  flex-shrink: 0;
  align-self: center;
  max-width: min(200px, 38vw);
  font-size: 10px;
  line-height: 1.3;
  color: #94a3b8;
  letter-spacing: 0.02em;
  user-select: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-role-hint {
  font-size: 0.7rem;
  color: #2e7d32;
  margin-top: 2px;
}
.user-avatar-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center center;
  display: block;
}
.nav-no-role-hint {
  padding: 12px 20px;
  font-size: 12px;
  color: #90a4ae;
  text-align: center;
}

.password-lock-card {
  margin-bottom: 12px;
  border: 1px solid #ffcc80;
  background: #fff8e1;
  color: #8d4e00;
}

.release-nudge-layer {
  position: fixed;
  inset: 0;
  z-index: 10020;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  padding: 16px;
}

.release-nudge-card {
  width: min(460px, 100%);
  border-radius: 14px;
  background: var(--modal-bg);
  border: 1px solid var(--border);
  box-shadow: 0 22px 48px rgba(15, 23, 42, 0.28);
  padding: 18px;
}

.release-nudge-kicker {
  display: inline-block;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.05em;
  color: var(--accent);
  margin-bottom: 6px;
}

.release-nudge-card h3 {
  margin: 0 0 8px;
  font-size: 20px;
  color: var(--text);
}

.release-nudge-card p {
  margin: 0;
  color: var(--text-light);
  line-height: 1.6;
}

.release-nudge-actions {
  margin-top: 14px;
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

.release-nudge-btn {
  border: 1px solid var(--border);
  background: var(--card-bg);
  color: var(--text);
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 13px;
  cursor: pointer;
}

.release-nudge-btn-primary {
  border-color: var(--accent);
  background: var(--accent);
  color: #fff;
}

.brand-idle-layer {
  position: fixed;
  inset: 0;
  z-index: 10040;
  display: grid;
  place-items: center;
  padding: 24px;
  background:
    radial-gradient(circle at 50% 42%, rgba(255, 179, 0, 0.16), transparent 32%),
    rgba(15, 23, 42, 0.48);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  cursor: pointer;
}

.brand-idle-layer--intro {
  background:
    radial-gradient(circle at 50% 42%, rgba(255, 179, 0, 0.3), transparent 38%),
    rgba(15, 23, 42, 0.28);
  animation: brandIntroBackdropFlash 2.4s ease-out both;
}

.brand-idle-layer--intro .brand-idle-logo-wrap {
  width: 196px;
  height: 196px;
  animation: brandIntroOrbPulse 2.4s cubic-bezier(0.18, 1, 0.32, 1) both;
}

.brand-idle-layer--intro .brand-idle-logo {
  width: 132px;
  height: 132px;
  animation: brandIntroLogoPunch 2.4s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.brand-idle-layer--intro .brand-idle-copy strong {
  font-size: 26px;
}

.brand-idle-layer--intro .brand-idle-copy {
  animation: brandIntroTextRise 2.4s cubic-bezier(0.2, 1, 0.3, 1) both;
}

.brand-idle-card {
  display: grid;
  justify-items: center;
  gap: 16px;
  color: #fff;
  text-align: center;
  user-select: none;
}

.brand-idle-logo-wrap {
  position: relative;
  width: 168px;
  height: 168px;
  display: grid;
  place-items: center;
  isolation: isolate;
  animation: brandFloat 5.5s ease-in-out infinite;
}

.brand-idle-ring {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  border: 1px solid rgba(255, 255, 255, 0.42);
  box-shadow: 0 0 30px rgba(255, 179, 0, 0.18);
}

.brand-idle-ring--outer {
  animation: brandRingPulse 3.8s ease-in-out infinite;
}

.brand-idle-ring--inner {
  inset: 16px;
  border-color: rgba(255, 179, 0, 0.42);
  animation: brandRingPulse 3.8s ease-in-out infinite reverse;
}

.brand-idle-logo {
  position: relative;
  z-index: 1;
  width: 118px;
  height: 118px;
  border-radius: 50%;
  object-fit: cover;
  transform: scale(1.08);
  box-shadow: 0 18px 52px rgba(0, 0, 0, 0.24), 0 0 0 8px rgba(255, 255, 255, 0.88);
  animation: brandLogoBreathe 3.4s ease-in-out infinite;
}

.brand-idle-logo-wrap::after {
  content: '';
  position: absolute;
  inset: 18px;
  border-radius: 50%;
  background: linear-gradient(110deg, transparent 22%, rgba(255,255,255,0.42) 48%, transparent 72%);
  transform: translateX(-72%) rotate(12deg);
  animation: brandLogoShine 4.8s ease-in-out infinite;
  z-index: 2;
  pointer-events: none;
  mix-blend-mode: screen;
}

.brand-idle-logo-wrap::before {
  content: '';
  position: absolute;
  inset: -12px;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.55);
  opacity: 0;
  transform: scale(0.65);
  z-index: 0;
  pointer-events: none;
}

.brand-idle-layer--intro .brand-idle-logo-wrap::before {
  animation: brandIntroShockwave 1.1s cubic-bezier(0.22, 1, 0.36, 1) 0.12s both;
}

.brand-idle-layer--intro .brand-idle-logo-wrap::after {
  animation: brandIntroShineBurst 2.4s ease-out both;
}

.brand-idle-copy {
  display: grid;
  gap: 4px;
  text-shadow: 0 2px 12px rgba(0, 0, 0, 0.32);
}

.brand-idle-copy strong {
  font-size: 22px;
  letter-spacing: 0.04em;
}

.brand-idle-copy span {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.82);
}

.brand-overlay-enter-active,
.brand-overlay-leave-active {
  transition: opacity 0.32s ease;
}

.brand-overlay-enter-active .brand-idle-card,
.brand-overlay-leave-active .brand-idle-card {
  transition: transform 0.32s ease, opacity 0.32s ease;
}

.brand-overlay-enter-from,
.brand-overlay-leave-to {
  opacity: 0;
}

.brand-overlay-enter-from .brand-idle-card,
.brand-overlay-leave-to .brand-idle-card {
  opacity: 0;
  transform: translateY(10px) scale(0.96);
}

@keyframes brandFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-8px); }
}

@keyframes brandRingPulse {
  0%, 100% { transform: scale(0.94); opacity: 0.5; }
  50% { transform: scale(1.04); opacity: 0.92; }
}

@keyframes brandLogoBreathe {
  0%, 100% { transform: scale(1.06); }
  50% { transform: scale(1.12); }
}

@keyframes brandLogoShine {
  0%, 42% { transform: translateX(-78%) rotate(12deg); opacity: 0; }
  54% { opacity: 0.85; }
  70%, 100% { transform: translateX(78%) rotate(12deg); opacity: 0; }
}

@keyframes brandIntroBackdropFlash {
  0% {
    background:
      radial-gradient(circle at 50% 40%, rgba(255, 232, 187, 0.8), transparent 22%),
      rgba(10, 14, 25, 0.06);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
  }
  18% {
    background:
      radial-gradient(circle at 50% 42%, rgba(255, 194, 84, 0.52), transparent 35%),
      rgba(11, 18, 32, 0.2);
  }
  100% {
    background:
      radial-gradient(circle at 50% 42%, rgba(255, 179, 0, 0.3), transparent 38%),
      rgba(15, 23, 42, 0.28);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
  }
}

@keyframes brandIntroOrbPulse {
  0% { transform: scale(0.52); filter: brightness(1.2) saturate(1.1); }
  16% { transform: scale(1.32); filter: brightness(1.4) saturate(1.2); }
  36% { transform: scale(0.86); filter: brightness(1.1) saturate(1.05); }
  56% { transform: scale(1.08); }
  100% { transform: scale(1); filter: brightness(1) saturate(1); }
}

@keyframes brandIntroLogoPunch {
  0% { transform: scale(0.42); opacity: 0; }
  14% { transform: scale(1.62); opacity: 1; }
  30% { transform: scale(0.82); }
  46% { transform: scale(1.26); }
  62% { transform: scale(1.02); }
  100% { transform: scale(1.08); opacity: 1; }
}

@keyframes brandIntroShockwave {
  0% { transform: scale(0.62); opacity: 0.95; }
  100% { transform: scale(1.56); opacity: 0; }
}

@keyframes brandIntroShineBurst {
  0% { transform: translateX(-115%) rotate(12deg); opacity: 0; }
  10% { opacity: 1; }
  34% { transform: translateX(108%) rotate(12deg); opacity: 0.95; }
  52% { opacity: 0; }
  100% { transform: translateX(118%) rotate(12deg); opacity: 0; }
}

@keyframes brandIntroTextRise {
  0% { opacity: 0; transform: translateY(12px) scale(0.96); }
  28% { opacity: 1; transform: translateY(0) scale(1.02); }
  100% { opacity: 1; transform: translateY(0) scale(1); }
}

@media (prefers-reduced-motion: reduce) {
  .brand-idle-logo-wrap,
  .brand-idle-ring,
  .brand-idle-logo,
  .brand-idle-logo-wrap::before,
  .brand-idle-logo-wrap::after {
    animation: none;
  }
}

@media (pointer: coarse) {
  .brand-idle-logo-wrap {
    width: 142px;
    height: 142px;
  }
  .brand-idle-logo {
    width: 100px;
    height: 100px;
  }
}

.sidebar-nav button:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.nav-group {
  border: 0;
  border-radius: 0;
  background: transparent;
  overflow: hidden;
}

.nav-group + .nav-group {
  margin-top: 14px;
}

.nav-group-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 10px 5px;
  list-style: none;
  cursor: pointer;
  user-select: none;
  border-bottom: 0;
}

.nav-group-summary::-webkit-details-marker {
  display: none;
}

.nav-group-title {
  font-size: 10px;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.07em;
}

.nav-group-chevron {
  font-size: 12px;
  color: #94a3b8;
  transition: transform 0.2s ease;
}

.nav-group[open] .nav-group-chevron {
  transform: rotate(180deg);
}

.nav-group-list {
  display: grid;
  gap: 3px;
  padding: 2px 0 0;
}

.nav-group-summary:focus-visible,
.sidebar-nav button:focus-visible,
.sidebar-collapse-btn:focus-visible,
.branch-btn:focus-visible,
.theme-btn:focus-visible,
.account-menu-trigger:focus-visible,
.account-menu-btn:focus-visible,
.account-menu-shortcuts summary:focus-visible {
  outline: 2px solid var(--ds-primary-soft);
  outline-offset: 2px;
}

.account-menu-divider {
  height: 1px;
  margin: 4px 4px 2px;
  background: var(--border);
}

.account-menu-tools {
  display: grid;
  gap: 6px;
  padding: 4px 4px 2px;
}

.account-menu-tools-label {
  color: var(--text-light);
  font-size: 11px;
  font-weight: 700;
}

.account-menu-tools .theme-buttons {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.account-menu-tools .theme-btn {
  min-width: 0;
  padding: 7px 4px;
}

.theme-btn-icon {
  font-size: 17px;
  line-height: 1;
}

.account-menu-shortcuts {
  margin: 2px 4px 0;
  border-top: 0;
}

.account-menu-shortcuts summary {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 4px;
  color: var(--text-light);
  font-size: 11px;
  cursor: pointer;
  list-style: none;
}

.account-menu-shortcuts summary::-webkit-details-marker { display: none; }
.account-menu-shortcuts summary .material-symbols-outlined { font-size: 16px; }
.account-menu-shortcuts[open] summary { color: var(--text); }

.nav-icon {
  width: 22px;
  min-width: 22px;
  text-align: center;
  flex-shrink: 0;
  font-size: 18px;
  line-height: 1;
}

.nav-label {
  min-width: 0;
}

.pin-gate-placeholder {
  max-width: 40rem;
  margin: 2rem auto;
  padding: 20px 18px;
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  background: var(--ds-canvas);
  box-shadow: var(--ds-shadow-1);
}
.pin-gate-placeholder-title {
  margin: 0 0 8px;
  font-size: 16px;
  font-weight: 700;
  color: var(--ds-ink);
}
.pin-gate-placeholder-body {
  margin: 0;
  font-size: 13px;
  line-height: 1.5;
  color: var(--ds-ink-secondary);
}

.main-topbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 10px;
  margin-bottom: 10px;
}

.main-topbar-spacer {
  flex: 1;
}

.dashboard-return-button {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 32px;
  padding: 6px 10px;
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-pill);
  background: var(--ds-canvas);
  color: var(--ds-ink);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}

.dashboard-return-button:hover,
.dashboard-return-button:focus-visible {
  border-color: var(--ds-primary);
  color: var(--ds-primary-deep);
}

.account-menu {
  position: relative;
  z-index: 20;
}

.account-menu-trigger {
  list-style: none;
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--topbar-bg);
  padding: 6px 10px 6px 6px;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
}

.account-menu-trigger::-webkit-details-marker {
  display: none;
}

.account-avatar {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  background: var(--ds-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--ds-on-primary);
  font-weight: 700;
  font-size: 13px;
  flex-shrink: 0;
}

.account-avatar-image {
  width: 100%;
  height: 100%;
  border-radius: inherit;
  object-fit: cover;
}

.account-meta {
  min-width: 0;
  text-align: left;
}

.account-name {
  color: var(--text);
  font-size: 13px;
  font-weight: 600;
  max-width: 140px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-role {
  color: var(--ds-ink-mute);
  font-size: 11px;
  line-height: 1.25;
}

.account-menu-chevron {
  color: var(--ds-ink-mute);
  font-size: 18px;
  transition: transform 0.2s ease;
}

.account-menu[open] .account-menu-chevron {
  transform: rotate(180deg);
}

.account-menu-panel {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  min-width: 188px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--modal-bg);
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.16);
  padding: 6px;
  display: grid;
  gap: 4px;
}

.account-menu-btn {
  border: 0;
  background: transparent;
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  border-radius: 8px;
  padding: 8px 10px;
  color: var(--text);
  font-size: 13px;
}

.account-menu-btn:hover {
  background: var(--input-bg);
}

.account-menu-btn .material-symbols-outlined {
  font-size: 18px;
}

.account-menu-btn-danger {
  color: var(--ds-danger);
}

.account-menu-btn-danger:hover {
  background: var(--ds-danger-wash);
}

.standalone-parent-shell {
  position: relative;
}

.global-guide-btn {
  position: fixed;
  width: 46px;
  height: 46px;
  border-radius: 50%;
  border: none;
  background: var(--ds-primary);
  color: var(--ds-on-primary);
  font-size: 22px;
  font-weight: 700;
  cursor: grab;
  touch-action: none;
  box-shadow: var(--ds-shadow-2);
  z-index: 10010;
  will-change: transform;
  transition:
    left 0.48s cubic-bezier(0.22, 1, 0.32, 1),
    top 0.48s cubic-bezier(0.22, 1, 0.32, 1),
    transform 0.2s ease,
    box-shadow 0.2s ease;
}
@media (pointer: coarse) {
  .global-guide-btn {
    transition:
      left 0.35s cubic-bezier(0.22, 1, 0.32, 1),
      top 0.35s cubic-bezier(0.22, 1, 0.32, 1),
      box-shadow 0.2s ease;
  }
}

.global-guide-btn:hover:not(.is-dragging) {
  transform: translateY(-2px);
}

.global-guide-btn.is-dragging {
  cursor: grabbing;
  transform: none;
  transition: none;
}

/* Keep the draggable guide control clear of the fixed mobile bottom nav. */
@media (max-width: 640px) {
  .global-guide-btn {
    top: auto !important;
    right: 8px !important;
    bottom: calc(56px + env(safe-area-inset-bottom, 0px) + 8px) !important;
    left: auto !important;
  }
}

.guide-tour-popover-layer {
  position: fixed;
  inset: 0;
  z-index: 2147483000;
  pointer-events: auto;
}

.onboarding-coach {
  position: fixed;
  left: 20px;
  bottom: calc(76px + env(safe-area-inset-bottom, 0px));
  z-index: 900;
  display: flex;
  align-items: center;
  gap: 12px;
  width: min(320px, calc(100vw - 40px));
  padding: 12px 16px;
  border: 1px solid var(--ds-hairline);
  border-radius: 20px;
  background: var(--ds-canvas);
  box-shadow: 0 8px 24px rgba(0, 55, 112, .12);
}
.onboarding-coach img { width: 48px; height: 56px; object-fit: contain; }
.onboarding-coach > div { display: grid; gap: 6px; }
.onboarding-coach strong { font-size: 13px; color: var(--ds-ink); }
.onboarding-coach .onboarding-launch-kicker { font-size: 11px; margin: 0; }
.guide-tour-practice { margin: 0 16px 12px; border-color: var(--ds-primary); flex-shrink: 0; }
.guide-tour-trail { display: flex; gap: 5px; margin: 0 16px; }
.guide-tour-trail span { height: 5px; flex: 1; border-radius: 9px; background: var(--ds-hairline); transition: background .2s; }
.guide-tour-trail .is-done { background: var(--ds-success); }
.guide-tour-trail .is-current { background: var(--ds-primary); }
.onboarding-launch-checklist li { position: relative; }
.onboarding-launch-checklist li:not(:last-child)::after { content: ''; position: absolute; left: 23px; top: 40px; bottom: -10px; width: 2px; background: var(--ds-hairline); }
.onboarding-launch-checklist li > span:last-child { display: grid; gap: 3px; }
.onboarding-launch-checklist small { font-size: 10px; color: var(--ds-ink-mute); font-weight: 500; }

.guide-tour-popover {
  position: fixed;
  width: min(360px, calc(100vw - 24px));
  max-width: calc(100vw - 32px);
  background: var(--ds-canvas);
  border-radius: 14px;
  box-shadow: 0 14px 38px rgba(0, 0, 0, 0.35);
  border: 1px solid var(--ds-primary-wash);
  pointer-events: auto;
  overflow: auto;
  isolation: isolate;
  display: flex;
  flex-direction: column;
}

.guide-tour-popover::after {
  content: '';
  position: absolute;
  width: 10px;
  height: 10px;
  background: var(--ds-canvas);
  border-left: 1px solid var(--ds-primary-wash);
  border-top: 1px solid var(--ds-primary-wash);
  transform: rotate(45deg);
}

.guide-tour-popover.placement-bottom::after {
  top: -6px;
  left: clamp(20px, 16%, 42px);
}

.guide-tour-popover.placement-top::after {
  bottom: -6px;
  left: clamp(20px, 16%, 42px);
  transform: rotate(225deg);
}

.guide-tour-popover.placement-left::after {
  right: -6px;
  top: clamp(20px, 28%, 48px);
  transform: rotate(135deg);
}

.guide-tour-popover.placement-right::after {
  left: -6px;
  top: clamp(20px, 28%, 48px);
  transform: rotate(-45deg);
}

.guide-tour-popover.placement-center::after {
  display: none;
}

.guide-tour-popover-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 14px 8px;
  border-bottom: 1px solid var(--ds-canvas-soft);
}

.guide-tour-head-title {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.guide-tour-head-title > div {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.guide-tour-mission-label {
  color: var(--ds-primary-deep, var(--ds-primary));
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.04em;
}

.guide-tour-icon {
  font-size: 18px;
  line-height: 1;
  flex-shrink: 0;
}

.guide-tour-popover-head strong {
  font-size: 14px;
  color: var(--ds-ink);
}

.guide-tour-close {
  border: 0;
  background: transparent;
  color: var(--ds-ink-mute);
  cursor: pointer;
  font-size: 14px;
  flex-shrink: 0;
}

.guide-tour-popover-text {
  margin: 0;
  padding: 12px 14px 8px;
  font-size: 13px;
  line-height: 1.6;
  color: var(--ds-ink);
  overflow-y: auto;
}

.guide-tour-objective {
  display: flex;
  gap: 8px;
  margin: 0 14px 10px;
  padding: 10px 11px;
  border: 1px solid var(--ds-primary-wash);
  border-radius: 10px;
  background: var(--ds-primary-wash);
  color: var(--ds-ink);
  font-size: 12px;
  line-height: 1.5;
}

.guide-tour-objective > .material-symbols-outlined {
  flex: 0 0 auto;
  color: var(--ds-primary-deep, var(--ds-primary));
  font-size: 17px;
}

.guide-tour-objective > div {
  display: grid;
  gap: 2px;
}

.guide-tour-objective strong {
  font-size: 11px;
}

.guide-tour-completion-prompt {
  margin: 0 14px 10px;
  color: var(--ds-ink-mute);
  font-size: 11px;
  line-height: 1.5;
}

.onboarding-launch-layer,
.onboarding-complete-layer {
  position: fixed;
  inset: 0;
  z-index: 2147482990;
  display: grid;
  place-items: center;
  padding: 20px;
  background: color-mix(in srgb, var(--ds-ink) 58%, transparent);
}

.onboarding-launch-card {
  display: grid;
  grid-template-columns: minmax(240px, 1fr) minmax(0, 1.2fr);
  width: min(760px, 100%);
  max-height: min(700px, calc(100vh - 40px));
  overflow: auto;
  border: 1px solid var(--ds-primary-wash);
  border-radius: 24px;
  background: linear-gradient(135deg, var(--ds-canvas), var(--ds-surface-1, var(--ds-canvas)));
  box-shadow: 0 24px 72px color-mix(in srgb, var(--ds-ink) 32%, transparent);
}

.onboarding-launch-art {
  position: relative;
  display: grid;
  place-items: center;
  min-height: 300px;
  overflow: hidden;
  background: linear-gradient(155deg, var(--ds-primary-wash), var(--ds-canvas));
}

.onboarding-launch-art::before {
  content: '';
  position: absolute;
  width: 190px;
  height: 190px;
  border-radius: 50%;
  background: color-mix(in srgb, var(--ds-primary) 16%, transparent);
  animation: onboarding-orb-pulse 3s ease-in-out infinite;
}

.onboarding-launch-art img {
  position: relative;
  z-index: 1;
  width: min(72%, 220px);
  max-height: 270px;
  object-fit: contain;
  filter: drop-shadow(0 14px 16px color-mix(in srgb, var(--ds-ink) 20%, transparent));
  animation: onboarding-companion-float 3.6s ease-in-out infinite;
}

.onboarding-launch-art img.onboarding-launch-scene {
  width: 100%;
  height: 100%;
  max-height: none;
  object-fit: contain;
  object-position: center;
  filter: none;
  animation: none;
}

.onboarding-launch-spark {
  position: absolute;
  z-index: 2;
  color: var(--ds-accent, var(--ds-primary));
  font-size: 24px;
  animation: onboarding-sparkle 2.2s ease-in-out infinite;
}

.onboarding-launch-spark--one { top: 22%; left: 17%; }
.onboarding-launch-spark--two { right: 15%; bottom: 25%; animation-delay: 0.7s; }

.onboarding-launch-content {
  padding: 32px 34px 28px;
}

.onboarding-launch-kicker {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 3px 9px;
  border-radius: 999px;
  background: var(--ds-primary-wash);
  color: var(--ds-primary-deep, var(--ds-primary));
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
}

.onboarding-launch-content h2,
.onboarding-complete-card h2 {
  margin: 12px 0 8px;
  color: var(--ds-ink);
  font-size: clamp(22px, 3vw, 30px);
  line-height: 1.25;
}

.onboarding-launch-content > p,
.onboarding-complete-card > p {
  margin: 0;
  color: var(--ds-ink-mute);
  font-size: 14px;
  line-height: 1.7;
}

.onboarding-launch-rank {
  display: grid;
  gap: 7px;
  margin-top: 18px;
  padding: 12px 14px;
  border: 1px solid var(--ds-hairline);
  border-radius: 14px;
  background: var(--ds-canvas-soft);
}

.onboarding-launch-rank-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: var(--ds-ink-mute);
  font-size: 11px;
  font-weight: 700;
}

.onboarding-launch-rank-head .material-symbols-outlined {
  color: var(--ds-primary-deep, var(--ds-primary));
  font-size: 18px;
}

.onboarding-launch-rank .ers {
  gap: 5px 8px;
}

.onboarding-launch-rank > p {
  margin: 0;
  color: var(--ds-ink-mute);
  font-size: 11px;
  line-height: 1.5;
}

.onboarding-launch-progress {
  margin-top: 22px;
  padding: 14px;
  border: 1px solid var(--ds-canvas-soft);
  border-radius: 14px;
  background: color-mix(in srgb, var(--ds-canvas-soft) 45%, transparent);
}

.onboarding-launch-progress-head,
.guide-tour-checklist-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  color: var(--ds-ink-mute);
  font-size: 11px;
}

.onboarding-launch-progress-head strong,
.guide-tour-checklist-head strong {
  color: var(--ds-primary-deep, var(--ds-primary));
}

.onboarding-launch-checklist,
.guide-tour-checklist ol {
  display: grid;
  gap: 8px;
  margin: 12px 0 0;
  padding: 0;
  list-style: none;
}

.onboarding-launch-checklist li,
.guide-tour-checklist li {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  color: var(--ds-ink-mute);
  font-size: 12px;
  line-height: 1.35;
}

.onboarding-launch-checklist li.is-current,
.guide-tour-checklist li.is-current {
  color: var(--ds-ink);
  font-weight: 700;
}

.onboarding-launch-checklist li.is-done,
.guide-tour-checklist li.is-done {
  color: var(--ds-primary-deep, var(--ds-primary));
}

.onboarding-check-icon,
.guide-tour-checklist .material-symbols-outlined {
  flex: 0 0 auto;
  color: var(--ds-primary);
  font-size: 17px;
}

.onboarding-launch-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 22px;
}

.guide-tour-checklist {
  margin: 0 14px;
  padding: 10px 12px;
  border-radius: 12px;
  background: color-mix(in srgb, var(--ds-canvas-soft) 50%, transparent);
}

.guide-tour-checklist ol { gap: 5px; margin-top: 8px; }
.guide-tour-checklist li { font-size: 11px; }

.onboarding-complete-layer { z-index: 2147483100; }

.onboarding-complete-card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  width: min(420px, 100%);
  padding: 30px 28px 26px;
  overflow: hidden;
  border: 1px solid var(--ds-primary-wash);
  border-radius: 24px;
  background: var(--ds-canvas);
  box-shadow: 0 24px 72px color-mix(in srgb, var(--ds-ink) 34%, transparent);
  text-align: center;
}

.onboarding-complete-card h2 { font-size: 24px; }
.onboarding-complete-card .guide-tour-btn { margin-top: 20px; }

.onboarding-complete-art {
  width: 120px;
  height: 120px;
  object-fit: contain;
  animation: onboarding-complete-pop 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.onboarding-complete-rank {
  display: grid;
  justify-items: center;
  gap: 7px;
  margin-top: 18px;
  padding: 10px 14px;
  border-radius: 14px;
  background: var(--ds-canvas-soft);
}

.onboarding-complete-rank > span {
  color: var(--ds-ink-mute);
  font-size: 11px;
}

.onboarding-complete-burst {
  position: absolute;
  top: 20px;
  right: 24px;
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--ds-primary);
  color: var(--ds-on-primary, white);
  font-size: 20px;
  font-weight: 800;
  animation: onboarding-burst 0.6s 0.15s ease-out both;
}

.onboarding-launch-enter-active,
.onboarding-launch-leave-active,
.onboarding-complete-enter-active,
.onboarding-complete-leave-active {
  transition: opacity 0.22s ease;
}

.onboarding-launch-enter-active .onboarding-launch-card,
.onboarding-launch-leave-active .onboarding-launch-card,
.onboarding-complete-enter-active .onboarding-complete-card,
.onboarding-complete-leave-active .onboarding-complete-card {
  transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.22s ease;
}

.onboarding-launch-enter-from,
.onboarding-launch-leave-to,
.onboarding-complete-enter-from,
.onboarding-complete-leave-to { opacity: 0; }
.onboarding-launch-enter-from .onboarding-launch-card,
.onboarding-launch-leave-to .onboarding-launch-card,
.onboarding-complete-enter-from .onboarding-complete-card,
.onboarding-complete-leave-to .onboarding-complete-card { transform: translateY(14px) scale(0.98); opacity: 0; }

@keyframes onboarding-companion-float {
  0%, 100% { transform: translateY(2px) rotate(-1deg); }
  50% { transform: translateY(-8px) rotate(1deg); }
}

@keyframes onboarding-orb-pulse {
  0%, 100% { transform: scale(0.92); opacity: 0.7; }
  50% { transform: scale(1.08); opacity: 1; }
}

@keyframes onboarding-sparkle {
  0%, 100% { transform: scale(0.75) rotate(0deg); opacity: 0.45; }
  50% { transform: scale(1.15) rotate(18deg); opacity: 1; }
}

@keyframes onboarding-complete-pop {
  from { transform: scale(0.55) rotate(-8deg); opacity: 0; }
  to { transform: scale(1) rotate(0); opacity: 1; }
}

@keyframes onboarding-burst {
  from { transform: scale(0) rotate(-18deg); }
  to { transform: scale(1) rotate(0); }
}

@media (max-width: 640px) {
  .onboarding-launch-layer,
  .onboarding-complete-layer { align-items: end; padding: 10px; }
  .onboarding-launch-card { grid-template-columns: 1fr; max-height: calc(100vh - 20px); border-radius: 20px; }
  .onboarding-launch-art { min-height: 150px; }
  .onboarding-launch-art img { width: 110px; max-height: 150px; }
  .onboarding-launch-art img.onboarding-launch-scene { width: 100%; height: 100%; max-height: none; }
  .onboarding-launch-content { padding: 22px 20px 20px; }
  .onboarding-launch-actions { justify-content: stretch; }
  .onboarding-launch-actions .guide-tour-btn { flex: 1; }
  .onboarding-complete-card { padding: 26px 20px 22px; }
}

@media (prefers-reduced-motion: reduce) {
  .onboarding-launch-enter-active,
  .onboarding-launch-leave-active,
  .onboarding-complete-enter-active,
  .onboarding-complete-leave-active,
  .onboarding-launch-enter-active .onboarding-launch-card,
  .onboarding-launch-leave-active .onboarding-launch-card,
  .onboarding-complete-enter-active .onboarding-complete-card,
  .onboarding-complete-leave-active .onboarding-complete-card,
  .guide-tour-trail span { transition: none; }
  .onboarding-launch-art::before,
  .onboarding-launch-art img,
  .onboarding-launch-spark,
  .onboarding-complete-art,
  .onboarding-complete-burst { animation: none; }
}

.guide-tour-dots {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 6px;
  padding: 4px 14px 0;
}

.guide-tour-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--ds-canvas-soft);
  transition: background 0.2s, transform 0.2s;
}

.guide-tour-dot.active {
  background: var(--ds-primary);
  transform: scale(1.3);
}

.guide-tour-popover-foot {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 8px;
  padding: 10px 14px 14px;
}

.guide-tour-actions {
  display: flex;
  gap: 8px;
}

.guide-tour-btn {
  border: 1px solid var(--ds-canvas-soft);
  background: var(--ds-canvas);
  color: var(--ds-ink);
  border-radius: 8px;
  padding: 6px 10px;
  cursor: pointer;
  font-size: 12px;
}

.guide-tour-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.guide-tour-btn-primary {
  border-color: var(--ds-cta);
  background: var(--ds-cta);
  color: var(--ds-on-cta);
}

@media (max-width: 640px) {
  .nav-group {
    display: flex;
    gap: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
  }

  .nav-group + .nav-group {
    margin-top: 0;
  }

  .nav-group-summary {
    display: none;
  }

  .nav-group-list {
    display: flex;
    gap: 0;
    padding: 0;
  }

  .main-topbar {
    margin-bottom: 6px;
  }

  .build-stamp-bar {
    max-width: 24vw;
  }

  .dashboard-return-button {
    max-width: 44vw;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .account-menu-trigger {
    padding: 5px 8px 5px 5px;
  }

  .account-name {
    max-width: 102px;
  }

  .guide-tour-popover-foot {
    flex-direction: column;
    align-items: stretch;
  }

  .guide-tour-actions {
    justify-content: flex-end;
  }
}

/* ===== 快捷鍵提示 ===== */
.shortcut-hint {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid rgba(148, 163, 184, 0.15);
}

.shortcut-hint__toggle {
  list-style: none;
  cursor: pointer;
  font-size: 12px;
  color: var(--text-light, #94a3b8);
  padding: 4px 2px;
  user-select: none;
  display: flex;
  align-items: center;
  gap: 4px;
}

.shortcut-hint__toggle::-webkit-details-marker { display: none; }

.shortcut-hint__toggle:hover {
  color: var(--text, #334155);
}

.shortcut-hint__list {
  list-style: none;
  padding: 6px 4px 2px;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.shortcut-hint__list li {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--text-light, #94a3b8);
}

.shortcut-hint__list span {
  color: var(--text, #475569);
  margin-left: 2px;
}

.shortcut-hint__list kbd {
  display: inline-block;
  padding: 1px 5px;
  font-size: 10px;
  font-family: inherit;
  background: rgba(148, 163, 184, 0.12);
  border: 1px solid rgba(148, 163, 184, 0.3);
  border-radius: 4px;
  color: var(--text, #475569);
  line-height: 1.6;
  white-space: nowrap;
}

/* ===== 主題切換 ===== */
.theme-switcher {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid rgba(148, 163, 184, 0.16);
}

.theme-switcher-label {
  font-size: 10px;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin-bottom: 6px;
  font-weight: 600;
}

.theme-buttons {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 4px;
}

.theme-buttons-collapsed {
  grid-template-columns: 1fr;
}

.theme-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 6px 4px;
  border-radius: 8px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(148, 163, 184, 0.08);
  color: #94a3b8;
  font-size: 11px;
  cursor: pointer;
  transition: all 0.2s;
  font-family: inherit;
  white-space: nowrap;
}

.theme-btn:hover {
  background: rgba(148, 163, 184, 0.18);
  color: #e2e8f0;
}

.theme-btn.active {
  background: rgba(14, 165, 233, 0.22);
  border-color: rgba(125, 211, 252, 0.45);
  color: #7dd3fc;
  font-weight: 600;
}

.theme-btn-label {
  font-size: 11px;
}

/* ── Update banner ── */
.update-banner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 99999;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 10px 18px;
  background: var(--ds-primary);
  color: var(--ds-on-primary);
  font-size: 14px;
  font-weight: 500;
  box-shadow: var(--ds-shadow-1);
}

.update-banner-icon {
  font-size: 20px;
}

.update-banner-text {
  flex-shrink: 1;
}

.update-banner-btn {
  background: var(--ds-canvas);
  color: var(--ds-primary-deep);
  border: none;
  border-radius: 6px;
  padding: 5px 16px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.15s;
}

.update-banner-btn:hover {
  background: var(--ds-primary-wash);
}

.update-banner-close {
  background: none;
  border: none;
  color: rgba(255, 255, 255, 0.8);
  font-size: 20px;
  line-height: 1;
  cursor: pointer;
  padding: 0 4px;
  margin-left: 4px;
  transition: color 0.15s;
}

.update-banner-close:hover {
  color: #fff;
}

.update-banner-enter-active {
  transition: transform 0.35s ease, opacity 0.35s ease;
}
.update-banner-leave-active {
  transition: transform 0.25s ease, opacity 0.25s ease;
}
.update-banner-enter-from {
  transform: translateY(-100%);
  opacity: 0;
}
.update-banner-leave-to {
  transform: translateY(-100%);
  opacity: 0;
}
</style>
