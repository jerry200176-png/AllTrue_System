<template>
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
      </div>
    </aside>

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
          v-if="getItemBadgeCount(tab) > 0"
          class="mob-tab-badge"
        >{{ getItemBadgeCount(tab) > 99 ? '99+' : getItemBadgeCount(tab) }}</span>
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
      <div v-if="isPasswordChangeLocked" class="card password-lock-card">
        為了帳號安全，請先到「右上角帳號選單 > 個人管理 > 安全性」完成初始密碼修改後再繼續使用系統。
      </div>
      <DirectorDashboard v-if="!isPasswordChangeLocked && isDirector && active === 'director'" :branch-id="currentBranch" @navigate="onNavigateFromNotifications" />
      <NotificationsCenter
        v-if="!isPasswordChangeLocked && isDirector && active === 'notifications'"
        :branch-id="currentBranch"
        @navigate="onNavigateFromNotifications"
        @unread-change="onUnreadChange"
      />
      <SmartCalendar v-if="!isPasswordChangeLocked && active === 'calendar'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" :initial-teacher-id="initialTeacherIdForNav" :reset-week-token="calendarResetToken" @clear-initial-teacher="initialTeacherIdForNav = null" />
      <StudentsList v-if="!isPasswordChangeLocked && isDirector && active === 'students'" :branch-id="currentBranch" />
      <TeachersList v-if="!isPasswordChangeLocked && isDirector && active === 'teachers'" :branch-id="currentBranch" @navigate-to-schedule="onNavigateToSchedule" />
      <CourseManagement v-if="!isPasswordChangeLocked && isDirector && active === 'course-mgmt'" :branch-id="currentBranch" :initial-teacher-id="initialTeacherIdForNav" @clear-initial-teacher="initialTeacherIdForNav = null" />
      <ClassroomManagement v-if="!isPasswordChangeLocked && isDirector && active === 'classroom'" :branch-id="currentBranch" />
      <SubjectUnitsPage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'subject-units'" :branch-id="currentBranch" :user-role="role" />

      <AttendancePage v-if="!isPasswordChangeLocked && (isDirector || isTeacher) && active === 'attendance'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" />
      <LearningRecordsPage v-if="!isPasswordChangeLocked && active === 'learning'" :branch-id="currentBranch" :user-role="role" :user-id="session.user.id" />
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
        <strong>{{ guideTour.currentStep.value?.title }}</strong>
        <button type="button" class="guide-tour-close" @click.stop="guideTour.closeTour">✕</button>
      </div>
      <p class="guide-tour-popover-text">{{ guideTour.currentStep.value?.description }}</p>
      <div class="guide-tour-popover-foot">
        <span class="guide-tour-progress">{{ guideTour.progressText.value }}</span>
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
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { supabase } from './supabase';
import {
  branches,
  loadBranches,
  loadBranchesForDirector,
  getDefaultBranchId,
  resolveSavedBranchChoice,
} from './lib/useBranches';
import { usePageGuideTour } from './lib/usePageGuideTour';
import logoUrl from './assets/logo.png';

// Pages
import Login from './pages/Login.vue';

import StudentsList from './pages/StudentsList.vue';
import LearningRecordsPage from './pages/LearningRecordsPage.vue';
import SmartCalendar from './pages/SmartCalendar.vue';
import DirectorDashboard from './pages/DirectorDashboard.vue';
import ParentPortal from './pages/ParentPortal.vue';
import LineIntegration from './pages/LineIntegration.vue';
import CourseManagement from './pages/CourseManagement.vue';
import ClassroomManagement from './pages/ClassroomManagement.vue';
import TeachersList from './pages/TeachersList.vue';
import AttendancePage from './pages/AttendancePage.vue';
import SubjectUnitsPage from './pages/SubjectUnitsPage.vue';
import DirectorAccountsPage from './pages/DirectorAccountsPage.vue';
import NotificationsCenter from './pages/NotificationsCenter.vue';
import ProfileCenterPage from './pages/ProfileCenterPage.vue';

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

// Sidebar collapse state (desktop)
const sidebarCollapsed = ref(localStorage.getItem('sidebar_collapsed') === 'true');
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
      { page: 'attendance', label: '出勤', icon: 'fact_check' },
      { page: 'more', label: '更多', icon: 'apps' },
    ];
  }
  if (isTeacher.value) {
    return [
      { page: 'calendar', label: '行事曆', icon: 'calendar_today' },
      { page: 'attendance', label: '出勤', icon: 'fact_check' },
      { page: 'learning', label: '評量', icon: 'assignment' },
      { page: 'subject-units', label: '統計', icon: 'calculate' },
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

const unreadNotificationLabel = computed(() => (unreadNotificationCount.value > 99 ? '99+' : String(unreadNotificationCount.value)));

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

function onNavigateFromNotifications({ target }) {
  if (isPasswordChangeLocked.value) {
    active.value = 'profile';
    return;
  }
  if (!target) return;
  if (target === 'calendar') {
    calendarResetToken.value += 1;
  }
  active.value = target;
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
      systemItems.push({ page: 'director-accounts', label: '主任審核', icon: 'admin_panel_settings' });
    }
    return [
      {
        key: 'operations',
        title: '營運總覽',
        defaultOpen: true,
        items: [
          { page: 'director', label: '總覽儀表板', icon: 'dashboard' },
          { page: 'notifications', label: '通知中心', icon: 'notifications' },
        ],
      },
      {
        key: 'academic-core',
        title: '教務核心',
        defaultOpen: true,
        items: [
          { page: 'calendar', label: '班級行事曆 / 課表', icon: 'calendar_today' },
          { page: 'course-mgmt', label: '課程管理', icon: 'menu_book', badgeTypes: ['tuition'] },
          { page: 'students', label: '學生管理', icon: 'groups' },
          { page: 'teachers', label: '老師管理', icon: 'badge', badgeTypes: ['pending_teachers'] },
          { page: 'classroom', label: '教室管理', icon: 'meeting_room' },
        ],
      },
      {
        key: 'attendance-records',
        title: '考勤與評量',
        defaultOpen: true,
        items: [
          { page: 'attendance', label: '出缺勤管理', icon: 'fact_check', badgeTypes: ['pending_swipe'] },
          { page: 'learning', label: '學習評量表', icon: 'assignment', badgeTypes: ['learning_review'] },
          { page: 'subject-units', label: '科目數統計', icon: 'calculate' },
        ],
      },
      {
        key: 'system',
        title: '系統管理',
        defaultOpen: true,
        items: systemItems,
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
          { page: 'calendar', label: '班級行事曆 / 課表', icon: 'calendar_today' },
          { page: 'attendance', label: '出缺勤管理', icon: 'fact_check' },
          { page: 'learning', label: '課表與評量', icon: 'assignment' },
          { page: 'subject-units', label: '科目數統計', icon: 'calculate' },
        ],
      },
    ];
  }

  return [];
});

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

    unreadPollingTimer = window.setInterval(() => {
        refreshUnreadNotifications();
    }, 60000);
    await refreshUnreadNotifications();

    window.addEventListener('resize', onWindowResizeGuideFab);
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

        if (me.role === 'teacher') active.value = 'learning';
        else if (me.role === 'director' || me.role === 'super_admin') active.value = 'director';
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
    else if ((profile?.role ?? session.value?.user?.role) === 'teacher') active.value = 'learning';
    else if ((profile?.role ?? session.value?.user?.role) === 'director' || session.value?.user?.role === 'super_admin') active.value = 'director';
    await ensureDirectorBranches();
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
    if (isTeacher.value) active.value = 'learning';
    else if (isDirector.value) active.value = 'director';
  }
};

const logout = async () => {
    await supabase.auth.signOut();
};

watch(currentBranch, (value) => {
  localStorage.setItem('app_branch', value);
  refreshUnreadNotifications();
});

watch([active, isStandaloneParent], () => {
  guideTour.closeTour();
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

onBeforeUnmount(() => {
  guideTour.closeTour();
  if (unreadPollingTimer) {
    clearInterval(unreadPollingTimer);
    unreadPollingTimer = null;
  }
  window.removeEventListener('resize', onWindowResizeGuideFab);
});

async function refreshUnreadNotifications() {
  if (!session.value?.access_token || !isDirector.value || !currentBranch.value) {
    unreadNotificationCount.value = 0;
    urgentNotificationCount.value = 0;
    return;
  }

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
}

function formatBuildTime(rawIso) {
  const source = String(rawIso || '').trim();
  if (!source) return 'unknown';
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
  border-radius: 50%;
  object-fit: cover;
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
  border: 1px solid #dbe3ec;
  border-radius: 12px;
  background: #fff;
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
  color: #0f172a;
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
  border: 1px solid #dbe3ec;
  border-radius: 12px;
  background: #fff;
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
  color: #334155;
  font-size: 13px;
}

.account-menu-btn:hover {
  background: #f8fafc;
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
  transition:
    left 0.48s cubic-bezier(0.22, 1, 0.32, 1),
    top 0.48s cubic-bezier(0.22, 1, 0.32, 1),
    transform 0.2s ease,
    box-shadow 0.2s ease;
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
}

.guide-tour-popover-text {
  margin: 0;
  padding: 12px 14px;
  font-size: 13px;
  line-height: 1.6;
  color: #455a64;
  overflow-y: auto;
}

.guide-tour-popover-foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding: 10px 14px 14px;
}

.guide-tour-progress {
  font-size: 12px;
  color: #78909c;
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
</style>
