/**
 * Deterministic mount for page-level Playwright visual evidence.
 * Renders real NotificationsCenter / StudentsList Vue SFCs (not a restyled HTML clone).
 * Session must be written before importing modules that read alltrue_session at init.
 */
const params = new URLSearchParams(window.location.search);
const page = params.get('page') || 'inbox';
const mode = params.get('mode') || 'normal';
const role = params.get('role') || (page === 'teacher' ? 'teacher' : 'director');

localStorage.setItem(
  'alltrue_session',
  JSON.stringify({
    access_token: 'e2e-foundation-token',
    token: 'e2e-foundation-token',
    user: {
      id: 9001,
      role,
      name: role === 'teacher' ? 'E2E Teacher' : 'E2E Director',
      must_change_password: false,
    },
  }),
);
localStorage.setItem('app_branch', '1');
localStorage.setItem('notifications_sound_enabled', '0');

const { initTextSize } = await import('../../../src/composables/useTextSize.js');
initTextSize(); // reads alltrue_text_size, like src/main.js

const [{ createApp, h, ref }, styles] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/ui.css'),
]);

const pageModules = {
  app: () => import('../../../src/App.vue'),
  inbox: () => import('../../../src/pages/NotificationsCenter.vue'),
  students: () => import('../../../src/pages/StudentsList.vue'),
  director: () => import('../../../src/pages/DirectorDashboard.vue'),
  tuition: () => import('../../../src/pages/TuitionCollectionPage.vue'),
  learning: () => import('../../../src/pages/LearningRecordsPage.vue'),
  course: () => import('../../../src/pages/CourseManagement.vue'),
  calendar: () => import('../../../src/pages/SmartCalendar.vue'),
  discrepancy: () => import('../../../src/pages/ScheduleDiscrepancyPage.vue'),
  'tuition-report': () => import('../../../src/pages/TuitionReportPage.vue'),
  teacher: () => import('../../../src/pages/TeacherHomePage.vue'),
  teachers: () => import('../../../src/pages/TeachersList.vue'),
  attendance: () => import('../../../src/pages/AttendancePage.vue'),
  classroom: () => import('../../../src/pages/ClassroomManagement.vue'),
  'course-manager': () => import('../../../src/components/course-management/CourseManager.vue'),
  'course-edit': () => import('../../../src/components/CourseEditForm.vue'),
  parent: () => import('../../../src/pages/ParentPortal.vue'),
  login: () => import('../../../src/pages/Login.vue'),
  admissions: () => import('../../../src/pages/AdmissionInquiriesPage.vue'),
  profile: () => import('../../../src/pages/ProfileCenterPage.vue'),
  'question-bank': () => import('../../../src/pages/QuestionBankPage.vue'),
  chat: () => import('../../../src/pages/ChatPage.vue'),
  'line-integration': () => import('../../../src/pages/LineIntegration.vue'),
  'subject-settings': () => import('../../../src/pages/SubjectSettingsPage.vue'),
  'branch-management': () => import('../../../src/pages/BranchManagementPage.vue'),
  'truefit-fixture': () => import('../../../src/pages/TrueFitPaperFixturePage.vue'),
  'truefit-app': () => import('../../../src/pages/TrueFitApp.vue'),
  'release-notes': () => import('../../../src/pages/ReleaseNotesPage.vue'),
  'branch-health': () => import('../../../src/pages/BranchHealthBoard.vue'),
  'binding-conflicts': () => import('../../../src/pages/BindingConflictReviewPage.vue'),
  'binding-health': () => import('../../../src/pages/BindingHealthDashboard.vue'),
};
const loadPage = pageModules[page] || pageModules.inbox;
const PageComponent = (await loadPage()).default;
const Ui = page === 'course-manager' && mode === 'confirm' ? await import('../../../src/components/ui/dialog/index.js') : null;
const EditForm = page === 'course-manager' ? (await import('../../../src/components/CourseEditForm.vue')).default : null;

void styles;

createApp({
  name: 'UiFoundationPilotMount',
  setup() {
    if (page === 'app') {
      return () => h(PageComponent);
    }
    if (page === 'truefit-app') {
      return () => h(PageComponent, { token: 'e2e-foundation-token', branchId: 1 });
    }
    if (page === 'course-manager') {
      // Fixture data only (#4918): CourseManager drawer with a fixed set of September 2026 lessons.
      const tab = ref(params.get('tab') || 'overview');
      const d = (date, extra = {}) => ({ id: Number(date.slice(-2)), date, startTime: '19:00', start_time: '19:00', ...extra });
      const units = [d('2026-09-01'), d('2026-09-08'), d('2026-09-15'), d('2026-09-17', { id: 217 }), d('2026-09-22'), d('2026-09-24', { isProjected: true, kind: 'projected', id: 0 }), d('2026-09-29', { isProjected: true, kind: 'projected', id: 0 })];
      const label = (_c, date) => (date === '2026-09-17' ? '請假' : date < '2026-09-15' ? '已上' : '');
      const drawer = () => h(PageComponent, {
        course: { id: 7, student_name: '測試學生甲', subject: '數學', teacher_name: '測試老師', class_type: 'one_on_one', status: 'active', payment_type: 'session', room_name: 'A' },
        tab: tab.value, 'onUpdate:tab': (t) => { tab.value = t; },
        studentName: '測試學生甲', subjectLabel: '國中數學', classTypeLabel: '一對一', statusLabel: '進行中',
        scheduleSummary: '每週二 19:00–20:00', remainingLabel: '剩 5 堂', nextSessionLabel: '9/15（二）19:00', paymentLabel: '已繳費',
        overviewNeeds: mode === 'needs' ? [{ id: 'n1', title: '續課提醒', detail: '剩 5 堂，快用完了。', action: 'purchase', actionLabel: '續課' }] : [],
        actionModel: { primary: { id: 'leave', label: '請假／調課' }, groups: [{ id: 'more', label: '更多', items: [{ id: 'substitute', label: '代課' }] }] },
        canMoveLesson: () => true, calendarEnabled: true, createEnabled: true, canQuickAdd: true, canClose: true, isSessionMode: true,
        sessionUnits: units, cancelledUnits: [], pendingMakeups: [],
        formatSessionChipDate: (u) => `${u.date.slice(5).replace('-', '/')}（${'日一二三四五六'[new Date(`${u.date}T12:00:00`).getDay()]}）19:00`,
        getSessionStateClass: () => '', getSessionStateLabel: label, getSessionNumber: () => null, sessionRowKey: (u) => `${u.date}-${u.id}`,
        isUserNote: () => false, formatMakeupDate: () => '', purchaseLabel: '續課', onClose: () => {},
      }, { settings: () => h(EditForm, { modelValue: { class_type: 'one_on_one', subject: 'Math', teacher_id: 3000, rate_per_30min: 1500, rate_unit: 'session', sessions_purchased: 8, remaining_sessions: 5, payment_type: 'session' }, subjects: [{ value: 'Math', label: '數學' }], teachers: [{ id: 3000, name: '測試老師' }], showRemaining: true }) });
      if (mode !== 'confirm') return drawer;
      // A confirm (ui Dialog) opened over the open drawer: it must stack above it (in-app #518 review).
      return () => [drawer(), h(Ui.Dialog, { open: true }, () => h(Ui.DialogContent, { showCloseButton: false }, () => [
        h(Ui.DialogHeader, null, () => [h(Ui.DialogTitle, null, () => '暫停課程？'), h(Ui.DialogDescription, null, () => '確認後會立即更新課程狀態')]),
        h(Ui.DialogFooter, null, () => h('button', { type: 'button', 'data-testid': 'confirm-cancel' }, '取消')),
      ]))];
    }
    if (page === 'course-edit') {
      return () => h(PageComponent, {
        modelValue: { class_type: mode === 'paid' ? 'one_on_one' : 'tutoring', rate_per_30min: 1500, rate_unit: 'session', sessions_purchased: 8 },
        subjects: [{ value: 'Math', label: '數學' }],
        teachers: [],
      });
    }
    if (page === 'students') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'director') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'tuition') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'learning') {
      return () => h(PageComponent, { branchId: 1, userRole: role, userId: 9001 });
    }
    if (page === 'course') {
      return () => h(PageComponent, {
        branchId: 1,
        onNavigate: (payload) => {
          window.__pilotLastNavigation = payload;
        },
      });
    }
    if (page === 'calendar') {
      return () => h(PageComponent, { branchId: 1, userRole: role, userId: 9001 });
    }
    if (page === 'discrepancy') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'teacher') {
      return () => h(PageComponent, {
        branchId: 1,
        userId: 9001,
        userRole: 'teacher',
        teacherBranchIds: [1],
        unreadFeedbackCount: mode === 'empty' ? 0 : 1,
        onNavigate: (payload) => {
          window.__pilotLastNavigation = payload;
        },
      });
    }
    if (page === 'teachers') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'attendance') {
      return () => h(PageComponent, {
        branchId: 1,
        userId: 9001,
        userRole: role,
      });
    }
    if (page === 'classroom') {
      return () => h(PageComponent, { branchId: 1 });
    }
    if (page === 'parent') {
      return () => h(PageComponent, { standalone: true });
    }
    if (page === 'profile') {
      return () => h(PageComponent, {
        token: 'e2e-foundation-token',
        initialTab: mode === 'security' ? 'security' : 'profile',
      });
    }
    if (page === 'question-bank') {
      return () => h(PageComponent, { branchId: 1, userRole: role });
    }
    if (page === 'admissions') {
      const searchParams = new URLSearchParams(window.location.search);
      const branchIdParam = searchParams.get('branch');
      const branchId = branchIdParam ? Number(branchIdParam) : 1;
      return () => h(PageComponent, { branchId, token: 'e2e-foundation-token', standalone: mode === 'public', enabled: true });
    }
    if (page === 'subject-settings') {
      return () => h(PageComponent, { branchId: 1, userRole: role });
    }
    if (page === 'branch-management') {
      return () => h(PageComponent, { branchId: 1, token: 'e2e-foundation-token', standalone: mode === 'public', enabled: true });
    }
    if (page === 'branch-health') {
      return () => h(PageComponent, { token: 'e2e-branch-health-token' });
    }
    if (page === 'binding-conflicts') {
      return () => h(PageComponent, { branchId: 1, userRole: role });
    }
    if (page === 'binding-health') {
      return () => h(PageComponent, { branchId: 1, userRole: role });
    }
    if (page === 'release-notes') {
      return () => h(PageComponent, { userRole: role });
    }
    if (page === 'chat') {
      return () => h(PageComponent, { branchId: 1, userId: 9001, userRole: 'director' });
    }
    return () => h(PageComponent, { branchId: 1 });
  },
}).mount('#app');

document.documentElement.dataset.pilotPage = page;
document.documentElement.dataset.pilotReady = '1';
