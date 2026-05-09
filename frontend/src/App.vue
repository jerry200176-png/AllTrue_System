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
  <div v-if="isStandaloneParent" class="standalone-parent-shell">
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
    <span>載入中...</span>
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
      <button class="sidebar-collapse-btn" @click="toggleSidebarCollapsed" :title="sidebarCollapsed ? '展開側欄' : '收起側欄'">
        <span class="material-symbols-outlined">{{ sidebarCollapsed ? 'chevron_right' : 'chevron_left' }}</span>
      </button>

      <nav class="sidebar-nav" data-guide="app-sidebar-nav">
        <template v-if="sidebarNavGroups.length > 0">
          <details v-for="group in sidebarNavGroups" :key="group.key" class="nav-group" :open="group.defaultOpen !== false">
            <summary class="nav-group-summary" v-show="!sidebarCollapsed">
              <span class="nav-group-title">{{ group.title.replace(/^[A-Z]\s*組：\s*/i, '') }}</span>
              <span class="nav-group-chevron">▾</span>
            </summary>
            <div class="nav-group-list">
              <button
                v-for="item in group.items"
                :key="item.page"
                @click="setActivePage(item.page)"
                :class="{ active: active === item.page }"
                :disabled="isNavItemDisabled(item.page)"
                :title="sidebarCollapsed ? item.label : ''"
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

        <!-- 電腦快捷鍵提示（主任限定，側欄展開時顯示） -->
        <details v-if="!sidebarCollapsed" class="shortcut-hint">
          <summary class="shortcut-hint__toggle">⌨️ 快捷鍵提示</summary>
          <ul class="shortcut-hint__list">
            <li><kbd>Win</kbd>+<kbd>Shift</kbd>+<kbd>S</kbd> <span>截圖</span></li>
            <li><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>R</kbd> <span>更新網頁</span></li>
            <li><kbd>Ctrl</kbd>+<kbd>C</kbd> <span>複製</span></li>
            <li><kbd>Ctrl</kbd>+<kbd>V</kbd> <span>貼上</span></li>
          </ul>
        </details>

        <!-- 主題切換 -->
        <div class="theme-switcher" :title="sidebarCollapsed ? '切換顯示模式' : ''">
          <div class="theme-switcher-label" v-show="!sidebarCollapsed">顯示模式</div>
          <div class="theme-buttons" :class="{ 'theme-buttons-collapsed': sidebarCollapsed }">
            <button
              v-for="opt in themeOptions"
              :key="opt.value"
              :class="['theme-btn', { active: themePreference === opt.value }]"
              :title="opt.label"
              @click="setTheme(opt.value)"
            >
              <span>{{ opt.icon }}</span>
              <span v-show="!sidebarCollapsed" class="theme-btn-label">{{ opt.label }}</span>
            </button>
          </div>
        </div>
      </div>
    </aside>

    <!-- Bug Report Launcher (floating button, all staff pages) -->
    <BugReportLauncher
      v-if="session && !isStandaloneParent && (isDirector || isTeacher)"
      :branch-id="currentBranch"
      :current-page-key="active"
    />

    <!-- Mobile Bottom Nav (5 tabs + More) -->
    <nav class="mobile-bottom-nav" v-if="session && !isStandaloneParent">
      <button
        v-for="tab in mobileTabItems"
        :key="tab.page"
        :class="['mob-tab', { active: tab.page === 'more' ? showMoreMenu : active === tab.page }]"
        @click="tab.page === 'more' ? (showMoreMenu = !showMoreMenu) : (setActivePage(tab.page), showMoreMenu = false)"
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
    <div class="more-overlay" v-if="showMoreMenu" @click="showMoreMenu = false"></div>
    <div class="more-sheet" :class="{ open: showMoreMenu }">
      <div class="more-sheet-handle" @click="showMoreMenu = false"></div>
      <div class="more-sheet-title">更多功能</div>
      <div v-for="group in sidebarNavGroups" :key="group.key" class="more-group">
        <div class="more-group-label">{{ group.title }}</div>
        <div class="more-group-items">
          <button
            v-for="item in group.items.filter(i => !mobileTabPages.has(i.page))"
            :key="item.page"
            :class="['more-item', { active: active === item.page }]"
            @click="setActivePage(item.page); showMoreMenu = false"
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
    </div>

    <!-- Main Content -->
    <div class="main-content">
      <div class="main-topbar">
        <span
          class="build-stamp-bar"
          :title="`部署時間 ${buildTimeDisplay}`"
        >建置 {{ buildTimeDisplay }}</span>
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
            <button type="button" class="account-menu-btn account-menu-btn-danger" @click="logout">
              <span class="material-symbols-outlined" aria-hidden="true">logout</span>
              <span>登出系統</span>
            </button>
          </div>
        </details>
      </div>
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
      <DirectorDashboard v-if="!isPasswordChangeLocked && isDirector && active === 'director'" :branch-id="currentBranch" :unread-feedback-count="unreadFeedbackCount" @navigate="onNavigateFromNotifications" />
      <NotificationsCenter
        v-if="!isPasswordChangeLocked && isDirector && active === 'notifications'"
        :branch-id="currentBranch"
        @navigate="onNavigateFromNotifications"
        @unread-change="onUnreadChange"
      />
      <SmartCalendar v-if="!isPasswordChangeLocked && active === 'calendar'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" :initial-teacher-id="initialTeacherIdForNav" :reset-week-token="calendarResetToken" @clear-initial-teacher="initialTeacherIdForNav = null" />
      <StudentsList v-if="!isPasswordChangeLocked && isDirector && active === 'students'" :branch-id="currentBranch" />
      <TuitionCollectionPage v-if="!isPasswordChangeLocked && isDirector && active === 'tuition-collect'" :branch-id="currentBranch" />
      <TuitionReportPage v-if="!isPasswordChangeLocked && isDirector && active === 'tuition-report'" :branch-id="currentBranch" />
      <ParttimePayrollPage v-if="!isPasswordChangeLocked && isDirector && active === 'parttime-payroll'" :branch-id="currentBranch" :user-role="role" />
      <TeachersList v-if="!isPasswordChangeLocked && isDirector && active === 'teachers'" :branch-id="currentBranch" @navigate-to-schedule="onNavigateToSchedule" />
      <CourseManagement v-if="!isPasswordChangeLocked && isDirector && active === 'course-mgmt'" :branch-id="currentBranch" :initial-teacher-id="initialTeacherIdForNav" @clear-initial-teacher="initialTeacherIdForNav = null" @navigate="active = $event" />
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
        @navigate="setActivePage($event)"
        @navigate-learning="onNavigateLearningFromTeacherHome"
      />
      <AttendancePage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'attendance'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" />
      <LearningRecordsPage v-if="!isPasswordChangeLocked && active === 'learning'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" :target-record-id="learningTargetRecordId" :target-session="learningTargetSession" @feedback-read="refreshUnreadNotifications" />
      <ProfileCenterPage
        v-if="(isTeacher || isDirector) && active === 'profile'"
        :token="session?.access_token ?? ''"
        :force-password-change="isPasswordChangeLocked"
        :initial-tab="isPasswordChangeLocked ? 'security' : 'profile'"
        @profile-updated="onProfileUpdated"
        @password-change-complete="onPasswordChangeComplete"
      />
      <ParentPortal v-if="!isPasswordChangeLocked && active === 'parent'" />
      <LineIntegration v-if="!isPasswordChangeLocked && isDirector && active === 'line-integration'" :branch-id="currentBranch" />
      <DirectorAccountsPage v-if="!isPasswordChangeLocked && role === 'super_admin' && active === 'director-accounts'" :token="session?.access_token ?? ''" />
      <ChatPage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'chat'" :branch-id="currentBranch" :user-id="session?.user?.id" :avatar-url="avatarUrl" :super-admin="role === 'super_admin'" :user-role="role" />
      <BugReportsPage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'bugs'" :branch-id="currentBranch" :user-role="role" />
      <ScheduleDiscrepancyPage v-if="!isPasswordChangeLocked && isDirector && active === 'schedule-discrepancy'" :branch-id="currentBranch" />

      <!-- 身分無法辨識時顯示說明，避免登入後一片空白 -->
      <div v-if="!isDirector && !isTeacher" class="card" style="max-width: 480px; margin: 2rem auto; padding: 2rem; text-align: center;">
        <h2 style="margin-bottom: 1rem;">⚠️ 無法顯示功能</h2>
        <p style="color: var(--text-light); margin-bottom: 1rem;">您的帳號尚未設定為「主任」或「老師」身分，因此無法顯示操作選單。</p>
        <p style="font-size: 0.9rem; color: var(--text-light);">請聯繫系統管理員，在後台將您的帳號身分設為主任或老師後再登入。</p>
        <p style="margin-top: 1.5rem; font-size: 0.85rem;">目前辨識到的身分：<strong>{{ role || '（未取得）' }}</strong></p>
      </div>
    </div>

  </div>

  <div v-if="guideTour.isOpen.value" class="guide-tour-popover-layer" @click.self="guideTour.closeTour">
    <div
      ref="guidePopoverRef"
      :class="['guide-tour-popover', `placement-${guideTour.effectivePlacement.value || 'bottom'}`]"
      :style="guideTour.popoverStyle.value"
      @click.stop
    >
      <div class="guide-tour-popover-head">
        <div class="guide-tour-head-title">
          <span v-if="guideTour.currentStep.value?.icon" class="guide-tour-icon">{{ guideTour.currentStep.value.icon }}</span>
          <strong>{{ guideTour.currentStep.value?.title }}</strong>
        </div>
        <button type="button" class="guide-tour-close" @click.stop="guideTour.closeTour">✕</button>
      </div>
      <p class="guide-tour-popover-text">{{ guideTour.currentStep.value?.description }}</p>
      <div class="guide-tour-dots">
        <span
          v-for="(_, i) in guideTour.steps.value"
          :key="i"
          :class="['guide-tour-dot', { active: i === guideTour.stepIndex.value }]"
        />
      </div>
      <div class="guide-tour-popover-foot">
        <div class="guide-tour-actions">
          <button type="button" class="guide-tour-btn" :disabled="!guideTour.hasPrev.value" @click="guideTour.prevStep">上一步</button>
          <button
            type="button"
            class="guide-tour-btn guide-tour-btn-primary"
            @click="guideTour.nextStep"
          >{{ guideTour.hasNext.value ? '下一步' : '完成' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, defineAsyncComponent, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { supabase } from './supabase';
import {
  branches,
  loadBranches,
  loadBranchesForDirector,
  getDefaultBranchId,
  resolveSavedBranchChoice,
} from './lib/useBranches';
import { usePageGuideTour } from './lib/usePageGuideTour';
import { useUpdateChecker } from './composables/useUpdateChecker';
import { lockScroll, unlockScroll } from './lib/useScrollLock';
import logoUrl from './assets/logo.png';

// Pages — lazy-loaded per route for code splitting (reduces initial bundle size)
import Login from './pages/Login.vue';
import ParentPortal from './pages/ParentPortal.vue';

const StudentsList          = defineAsyncComponent(() => import('./pages/StudentsList.vue'));
const LearningRecordsPage   = defineAsyncComponent(() => import('./pages/LearningRecordsPage.vue'));
const SmartCalendar         = defineAsyncComponent(() => import('./pages/SmartCalendar.vue'));
const DirectorDashboard     = defineAsyncComponent(() => import('./pages/DirectorDashboard.vue'));
const LineIntegration       = defineAsyncComponent(() => import('./pages/LineIntegration.vue'));
const CourseManagement      = defineAsyncComponent(() => import('./pages/CourseManagement.vue'));
const ClassroomManagement   = defineAsyncComponent(() => import('./pages/ClassroomManagement.vue'));
const SubjectSettingsPage   = defineAsyncComponent(() => import('./pages/SubjectSettingsPage.vue'));
const TeachersList          = defineAsyncComponent(() => import('./pages/TeachersList.vue'));
const AttendancePage        = defineAsyncComponent(() => import('./pages/AttendancePage.vue'));
const SubjectUnitsPage      = defineAsyncComponent(() => import('./pages/SubjectUnitsPage.vue'));
// BillingList removed — replaced by TuitionReportPage (當月學收)
const TuitionCollectionPage = defineAsyncComponent(() => import('./pages/TuitionCollectionPage.vue'));
const TuitionReportPage     = defineAsyncComponent(() => import('./pages/TuitionReportPage.vue'));
const ParttimePayrollPage   = defineAsyncComponent(() => import('./pages/ParttimePayrollPage.vue'));
const DirectorAccountsPage  = defineAsyncComponent(() => import('./pages/DirectorAccountsPage.vue'));
const NotificationsCenter   = defineAsyncComponent(() => import('./pages/NotificationsCenter.vue'));
const ProfileCenterPage     = defineAsyncComponent(() => import('./pages/ProfileCenterPage.vue'));
const ChatPage              = defineAsyncComponent(() => import('./pages/ChatPage.vue'));
const BugReportsPage        = defineAsyncComponent(() => import('./pages/BugReportsPage.vue'));
const TeacherHomePage       = defineAsyncComponent(() => import('./pages/TeacherHomePage.vue'));
const ScheduleDiscrepancyPage = defineAsyncComponent(() => import('./pages/ScheduleDiscrepancyPage.vue'));
import AmbientMusicPlayer from './components/AmbientMusicPlayer.vue';
import BugReportLauncher from './components/BugReportLauncher.vue';
import { fetchChatUnreadCount } from './lib/chatApi';
import perfFlags from './lib/perfFlags';
import { clearAllDraftsByTeacher } from './lib/learningRecordDrafts';

// Detect standalone parent portal access via URL hash, query param, or LIFF context
const liffParentOverride = ref(false);
const isStandaloneParent = computed(() => {
  const hash = window.location.hash;
  const params = new URLSearchParams(window.location.search);
  return hash === '#/parent' || params.get('parent') === '1' || liffParentOverride.value;
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
const currentBranch = ref(null); // Will be set after branches load
const learningTargetRecordId = ref(null);
const learningTargetSession = ref(null);

// Sidebar collapse state (desktop)
const sidebarCollapsed = ref(localStorage.getItem('sidebar_collapsed') === 'true');

// ===== 主題模式（日間 / 夜間 / 系統） =====
const THEME_KEY = 'app_color_scheme';
const themeOptions = [
  { value: 'light',  icon: '☀️', label: '日間' },
  { value: 'dark',   icon: '🌙', label: '夜間' },
  { value: 'system', icon: '💻', label: '系統' },
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
}

// Mobile bottom nav: 5 tabs + More
const showMoreMenu = ref(false);
const mobileTabItems = computed(() => {
  if (isDirector.value) {
    return [
      { page: 'director', label: '儀表板', icon: 'dashboard' },
      { page: 'calendar', label: '行事曆', icon: 'calendar_today' },
      { page: 'students', label: '學生', icon: 'groups' },
      { page: 'attendance', label: '出勤', icon: 'fact_check', badgeTypes: ['pending_swipe', 'attendance'] },
      { page: 'more', label: '更多', icon: 'apps' },
    ];
  }
  if (isTeacher.value) {
    return [
      { page: 'teacher-home', label: '工作台', icon: 'space_dashboard' },
      { page: 'attendance', label: '出勤', icon: 'fact_check' },
      { page: 'learning', label: '評量', icon: 'assignment', badgeTypes: ['teacher_learning_pending', 'parent_feedback'] },
      { page: 'chat', label: '聊天', icon: 'forum', badgeTypes: ['chat'] },
      { page: 'more', label: '更多', icon: 'apps' },
    ];
  }
  return [];
});
const mobileTabPages = computed(() => new Set(mobileTabItems.value.filter(t => t.page !== 'more').map(t => t.page)));
const initialTeacherIdForNav = ref(null);
const calendarResetToken = ref(0);
const unreadNotificationCount = ref(0);
const urgentNotificationCount = ref(0);
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


const unreadNotificationLabel = computed(() => (unreadNotificationCount.value > 99 ? '99+' : String(unreadNotificationCount.value)));
const unreadFeedbackCount = computed(() => Number(badgeByType.value.parent_feedback?.total || 0));

function getItemBadgeCount(item) {
  if (!item?.badgeTypes?.length) return 0;
  return item.badgeTypes.reduce((sum, t) => sum + (badgeByType.value[t]?.total || 0), 0);
}
function isItemBadgeUrgent(item) {
  if (!item?.badgeTypes?.length) return false;
  return item.badgeTypes.some((t) => (badgeByType.value[t]?.urgent || 0) > 0);
}

const currentGuidePage = computed(() => (isStandaloneParent.value ? 'parent' : active.value));
const buildTimeDisplay = computed(() => formatBuildTime(__APP_BUILD_TIME__));
const { updateAvailable, dismissed: updateDismissed, dismiss: dismissUpdate, reload: reloadForUpdate } = useUpdateChecker();

function startGuideTour() {
  guideTour.startTour(currentGuidePage.value, { role: role.value });
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

function onNavigateFromNotifications({ target, recordId }) {
  if (isPasswordChangeLocked.value) {
    active.value = 'profile';
    return;
  }
  if (!target) return;
  if (target === 'calendar') {
    calendarResetToken.value += 1;
  }
  if (target === 'learning' && recordId) {
    learningTargetRecordId.value = Number(recordId);
  } else {
    learningTargetRecordId.value = null;
  }
  active.value = target;
}

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
    learningTargetSession.value = payload?.classSessionId
      ? { classSessionId: payload.classSessionId, sessionDate: payload.sessionDate }
      : null;
  }
  setActivePage('learning');
}

function setActivePage(page) {
  if (isPasswordChangeLocked.value && page !== 'profile') {
    active.value = 'profile';
    return;
  }
  if (page === 'calendar') {
    calendarResetToken.value += 1;
  }
  active.value = page;
  if (page === 'learning') {
    mergeParentFeedbackBadge();
    mergeTeacherLearningPendingBadge();
  }
}

function isNavItemDisabled(page) {
  return isPasswordChangeLocked.value && page !== 'profile';
}

function onUnreadChange(count) {
  if (typeof count === 'number') {
    unreadNotificationCount.value = Number(count || 0);
    urgentNotificationCount.value = 0;
    return;
  }
  if (count && typeof count === 'object') {
    unreadNotificationCount.value = Number(count.unread ?? 0);
    urgentNotificationCount.value = Number(count.urgent ?? 0);
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

const sidebarNavGroups = computed(() => {
  if (isDirector.value) {
    const systemItems = [
      { page: 'line-integration', label: '家長 LINE 通知', icon: 'chat' },
    ];
    if (role.value === 'super_admin') {
      systemItems.push({
        page: 'director-accounts',
        label: '主任審核',
        icon: 'admin_panel_settings',
        badgeTypes: ['director_pending'],
      });
    }
    return [
      {
        key: 'overview',
        title: '總覽與通訊',
        defaultOpen: true,
        items: [
          { page: 'director', label: '總覽儀表板', icon: 'dashboard' },
          { page: 'notifications', label: '通知中心', icon: 'notifications' },
          { page: 'chat', label: '內部聊天', icon: 'forum', badgeTypes: ['chat'] },
        ],
      },
      {
        key: 'teaching',
        title: '排課與教學',
        defaultOpen: true,
        items: [
          { page: 'calendar', label: '班級行事曆 / 課表', icon: 'calendar_today' },
          { page: 'course-mgmt', label: '課程管理', icon: 'menu_book', badgeTypes: ['tuition'] },
          { page: 'attendance', label: '出缺勤管理', icon: 'fact_check', badgeTypes: ['pending_swipe', 'attendance'] },
          { page: 'schedule-discrepancy', label: '課表回報管理', icon: 'flag', badgeTypes: ['schedule_discrepancy'] },
          { page: 'learning', label: '學習評量表', icon: 'assignment', badgeTypes: ['learning_review', 'parent_feedback'] },
        ],
      },
      {
        key: 'people',
        title: '人員管理',
        defaultOpen: true,
        items: [
          { page: 'students', label: '學生管理', icon: 'groups' },
          { page: 'teachers', label: '老師管理', icon: 'badge', badgeTypes: ['pending_teachers'] },
        ],
      },
      {
        key: 'finance',
        title: '財務收費',
        defaultOpen: true,
        items: [
          { page: 'tuition-collect', label: '帳務中心', icon: 'payments' },
          { page: 'tuition-report', label: '當月學收', icon: 'bar_chart' },
          { page: 'subject-units', label: '科目數統計', icon: 'calculate' },
          { page: 'parttime-payroll', label: '兼職薪資', icon: 'account_balance_wallet' },
        ],
      },
      {
        key: 'settings',
        title: '系統設定',
        defaultOpen: false,
        items: [
          { page: 'classroom', label: '教室管理', icon: 'meeting_room' },
          { page: 'subject-settings', label: '科目管理', icon: 'library_books' },
          ...systemItems,
          { page: 'bugs', label: 'Bug 回報', icon: 'bug_report', badgeTypes: ['bugs'] },
        ],
      },
    ];
  }

  if (isTeacher.value) {
    return [
      {
        key: 'teaching',
        title: '教學工作',
        defaultOpen: true,
        items: [
          { page: 'teacher-home', label: '教學工作台', icon: 'space_dashboard' },
          { page: 'attendance', label: '出缺勤管理', icon: 'fact_check', badgeTypes: ['attendance'] },
          { page: 'learning', label: '課表與評量', icon: 'assignment', badgeTypes: ['teacher_learning_pending', 'parent_feedback'] },
          { page: 'calendar', label: '班級行事曆', icon: 'calendar_today' },
          { page: 'subject-units', label: '科目數統計', icon: 'calculate' },
          { page: 'chat', label: '內部聊天', icon: 'forum', badgeTypes: ['chat'] },
          { page: 'bugs', label: 'Bug 回報', icon: 'bug_report', badgeTypes: ['bugs'] },
        ],
      },
    ];
  }

  return [];
});

/** 底欄「更多」：加總未固定在底欄的選項之未讀（含通知中心）。 */
const moreMenuBadgeCount = computed(() => {
  let sum = 0;
  for (const group of sidebarNavGroups.value) {
    for (const item of group.items) {
      if (!mobileTabPages.value.has(item.page)) {
        sum += getItemBadgeCount(item);
        if (item.page === 'notifications') {
          sum += unreadNotificationCount.value;
        }
      }
    }
  }
  return sum;
});

function getMobileTabBadgeCount(tab) {
  if (tab?.page === 'more') return moreMenuBadgeCount.value;
  return getItemBadgeCount(tab);
}

/** 「更多」抽屜內單一列的未讀數（通知中心用全域未讀）。 */
function getMoreSheetItemBadgeCount(item) {
  let n = getItemBadgeCount(item);
  if (item?.page === 'notifications') n += unreadNotificationCount.value;
  return n;
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

    // Load branches from API (public endpoint, no auth needed)
    await loadBranches();

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

    const { data } = await supabase.auth.getSession();
    session.value = data.session;

    if (session.value) {
        await fetchProfile(session.value.user.id);
        await ensureDirectorBranches();
    }
    loading.value = false;

    supabase.auth.onAuthStateChange(async (_event, _session) => {
        session.value = _session;
        if (_session) {
            await fetchProfile(_session.user.id);
            await ensureDirectorBranches();
        } else {
            userProfile.value = null;
        }
    });

    _startBadgePolling();
    document.addEventListener('visibilitychange', _onVisibilityChangeForPolling);
    await refreshUnreadNotifications();

    window.addEventListener('resize', onWindowResizeGuideFab);
    window.addEventListener('alltrue-refresh-badges', onRefreshBadgesEvent);
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
        };

        if (session.value?.user) {
          session.value.user.must_change_password = mustChangePassword;
          localStorage.setItem('alltrue_session', JSON.stringify(session.value));
        }

        if (mustChangePassword) {
          active.value = 'profile';
          return;
        }

        if (me.role === 'teacher') {
            active.value = 'teacher-home';
            ensureTeacherBranch();
        } else if (me.role === 'director' || me.role === 'super_admin') {
            active.value = 'director';
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
    else if ((profile?.role ?? session.value?.user?.role) === 'director' || session.value?.user?.role === 'super_admin') active.value = 'director';
    await ensureDirectorBranches();
    ensureTeacherBranch();
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
});

watch(currentBranch, (value) => {
  localStorage.setItem('app_branch', value);
  refreshUnreadNotifications();
});

watch([active, isStandaloneParent], async ([p]) => {
  guideTour.closeTour();
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
  refreshUnreadNotifications();
});

watch(isPasswordChangeLocked, (locked) => {
  if (locked) {
    active.value = 'profile';
  }
});

function onRefreshBadgesEvent() {
  refreshUnreadNotifications();
}

onBeforeUnmount(() => {
  systemThemeMq?.removeEventListener('change', onSystemThemeChange);
  guideTour.closeTour();
  _stopBadgePolling();
  document.removeEventListener('visibilitychange', _onVisibilityChangeForPolling);
  window.removeEventListener('resize', onWindowResizeGuideFab);
  window.removeEventListener('alltrue-refresh-badges', onRefreshBadgesEvent);
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
    badgeByType.value = {};
    await mergeBugUnreadBadge();
    await mergeChatUnreadBadge();
    await mergeDirectorPendingBadge();
    return;
  }

  if (isDirector.value) {
    try {
      const baseUrl = import.meta.env.VITE_API_BASE || '/api';
      const params = new URLSearchParams({ branch_id: String(currentBranch.value) });
      const res = await fetch(`${baseUrl}/v1/notifications/unread-count?${params}`, {
        headers: {
          Authorization: `Bearer ${session.value.access_token}`,
          Accept: 'application/json',
        },
      });
      if (res.status === 401) {
        await supabase.auth.signOut();
        session.value = null;
        await mergeBugUnreadBadge();
        await mergeChatUnreadBadge();
        return;
      }
      if (!res.ok) throw new Error('unread-count request failed');
      const json = await res.json();
      unreadNotificationCount.value = Number(json.unread_count || 0);
      urgentNotificationCount.value = Number(json.urgent_unread_count || 0);
      badgeByType.value = json.by_type || {};
    } catch {
      unreadNotificationCount.value = 0;
      urgentNotificationCount.value = 0;
      badgeByType.value = {};
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

.sidebar-nav button:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.nav-group {
  border: 1px solid rgba(148, 163, 184, 0.24);
  border-radius: 12px;
  background: rgba(15, 23, 42, 0.28);
  overflow: hidden;
}

.nav-group + .nav-group {
  margin-top: 12px;
}

.nav-group-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 12px;
  list-style: none;
  cursor: pointer;
  user-select: none;
  border-bottom: 1px solid rgba(148, 163, 184, 0.18);
}

.nav-group-summary::-webkit-details-marker {
  display: none;
}

.nav-group-title {
  font-size: 11px;
  font-weight: 700;
  color: #cbd5e1;
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
  padding: 6px;
}

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
  background: linear-gradient(135deg, #f97316, #fb923c);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
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
  color: #64748b;
  font-size: 11px;
  line-height: 1.25;
}

.account-menu-chevron {
  color: #64748b;
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
  color: #b91c1c;
}

.account-menu-btn-danger:hover {
  background: #fef2f2;
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
  background: linear-gradient(135deg, #ff9800, #ff6f00);
  color: #fff;
  font-size: 22px;
  font-weight: 700;
  cursor: grab;
  touch-action: none;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
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

.guide-tour-popover-layer {
  position: fixed;
  inset: 0;
  z-index: 2147483000;
  pointer-events: auto;
}

.guide-tour-popover {
  position: fixed;
  width: min(360px, calc(100vw - 24px));
  max-width: calc(100vw - 32px);
  background: #ffffff;
  border-radius: 14px;
  box-shadow: 0 14px 38px rgba(0, 0, 0, 0.35);
  border: 1px solid #ffe0b2;
  pointer-events: auto;
  overflow: hidden;
  isolation: isolate;
  display: flex;
  flex-direction: column;
}

.guide-tour-popover::after {
  content: '';
  position: absolute;
  width: 10px;
  height: 10px;
  background: #fff;
  border-left: 1px solid #ffe0b2;
  border-top: 1px solid #ffe0b2;
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
  border-bottom: 1px solid #f3f3f3;
}

.guide-tour-head-title {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.guide-tour-icon {
  font-size: 18px;
  line-height: 1;
  flex-shrink: 0;
}

.guide-tour-popover-head strong {
  font-size: 14px;
  color: #263238;
}

.guide-tour-close {
  border: 0;
  background: transparent;
  color: #607d8b;
  cursor: pointer;
  font-size: 14px;
  flex-shrink: 0;
}

.guide-tour-popover-text {
  margin: 0;
  padding: 12px 14px 8px;
  font-size: 13px;
  line-height: 1.6;
  color: #455a64;
  overflow-y: auto;
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
  background: #cfd8dc;
  transition: background 0.2s, transform 0.2s;
}

.guide-tour-dot.active {
  background: #ff9800;
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
  border: 1px solid #cfd8dc;
  background: #fff;
  color: #455a64;
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
  border-color: #ff9800;
  background: #ff9800;
  color: #fff;
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
  background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%);
  color: #fff;
  font-size: 14px;
  font-weight: 500;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.18);
}

.update-banner-icon {
  font-size: 20px;
}

.update-banner-text {
  flex-shrink: 1;
}

.update-banner-btn {
  background: #fff;
  color: #2563eb;
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
  background: #e0edff;
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
