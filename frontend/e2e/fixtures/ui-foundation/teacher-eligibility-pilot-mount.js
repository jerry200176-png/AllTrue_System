const [{ createApp, h }, styles, module] = await Promise.all([
  import('vue'),
  import('../../../src/styles.css'),
  import('../../../src/pages/TeacherEligibilityPage.vue'),
]);

void styles;
const PageComponent = module.default;
const teacher = {
  teacher_id: 701,
  teacher_name: '王老師',
  overall_status: 'qualifies',
  review_required: false,
  settlement: {
    regular_subject_count: 4,
    tutoring_trial_subject_count: 2,
    payroll_subject_count: 6,
    one_to_three_count: 3,
    subject_count_bonus: 1200,
    one_to_three_bonus: 800,
    multiplier_pct: 105,
    weighted_bonus_amount: 2100,
    total_payout: 36000,
    payout_is_draft: false,
    adjustments: [{ label: '行政加給', amount: 500 }],
    multiplier_parts: [{ key: 'base', label: '基本', pct: 100 }],
  },
  components: {
    weekly_16_segments: { status: 'qualifies', metrics: { regular_segments: 18, trial_segments: 2, total_segments: 20, meets_16_segments: true, course_sessions: [] } },
    subject_count_bonus: { status: 'qualifies', amount: 1200 },
  },
};

const payload = {
  teachers: [teacher],
  period: { start: '2026-09-01', end: '2026-09-30' },
  policy_version: '115.07',
  branch_subject_total: 12,
  lock: { status: 'draft' },
};

createApp({
  name: 'TeacherEligibilityPilotMount',
  setup() {
    return () => h(PageComponent, { branchId: 1, userRole: 'director' });
  },
}).mount('#app');

document.documentElement.dataset.pilotPage = 'teacher-eligibility';
document.documentElement.dataset.pilotReady = '1';
