// #4150: the backend sends payment_status 'free' for free-gift contracts (StudentClassController,
// via BillingPayableResolver). Every course-level payment badge reads one shared helper, so a
// free course is never 未繳, never gets an owing call to action, and an unknown status is never 未繳.
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { flushPromises, shallowMount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));

import * as M from '../../lib/courseMoneyState.js';
import { isCourseSettled } from '../../lib/paymentStatus.js';
import { closeCourseNoRenew } from '../../lib/closeCourseNoRenew.js';
import StudentsList from '../../pages/StudentsList.vue';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pageSource = (name) => readFileSync(resolve(__dirname, `../../pages/${name}.vue`), 'utf8');
const UNPAID = '未繳';

const course = (overrides) => ({ id: 7101, class_type: 'one_on_one', payment_type: 'session', ...overrides });

// [case, course overrides, expected label, expected tone, expected next action]
const CASES = [
  ['paid', { payment_status: 'paid' }, '已收', 'settled', 'billing'],
  ['free', { payment_status: 'free' }, M.FREE_GIFT_LABEL, 'free', 'billing'],
  ['partial', { payment_status: 'partial' }, '繳了一部分', 'owing', 'report_payment'],
  ['unpaid', { payment_status: 'unpaid' }, UNPAID, 'owing', 'report_payment'],
  ['pending_report', { payment_status: 'pending_report' }, '已回報付款，待你核對入帳', 'review', 'view_report'],
  ['amount_review', { payment_status: 'amount_review' }, '金額待確認', 'review', 'billing'],
  ['review_required', { payment_status: 'review_required' }, M.REVIEW_REQUIRED_LABEL, 'review', 'billing'],
  ['waived', { payment_status: 'paid', closed_reason: 'waived' }, M.WAIVED_LABEL, 'settled', 'billing'],
  ['unknown status', { payment_status: 'something_new' }, M.UNKNOWN_STATUS_LABEL, 'neutral', 'billing'],
];

describe('#4150 coursePaymentBadge: one verdict for every server status', () => {
  it.each(CASES)('%s', (_name, overrides, label, tone, nextAction) => {
    expect(M.coursePaymentBadge(course(overrides))).toEqual({ label, tone, nextAction });
  });

  it.each(['free', 'something_new', 'amount_review', 'review_required'])('%s is never 未繳 and never an owing call to action', (payment_status) => {
    for (const monthlyPeriod of [false, true]) {
      const badge = M.coursePaymentBadge(course({ payment_status }), { monthlyPeriod });
      expect(badge.label).not.toBe(UNPAID);
      expect(badge.tone).not.toBe('owing');
      expect(badge.nextAction).not.toBe('report_payment');
    }
  });

  it('keeps the existing precedence: tutoring anomaly, tutoring, waived, legacy flag, loading', () => {
    expect(M.coursePaymentBadge(course({ class_type: 'tutoring', tutoring_billing_anomaly: true, payment_status: 'unpaid' })))
      .toEqual({ label: '帳務資料需修正', tone: 'anomaly', nextAction: 'none' });
    expect(M.coursePaymentBadge(course({ class_type: 'tutoring', payment_status: 'unpaid' })))
      .toEqual({ label: '無須繳費', tone: 'no_payment', nextAction: 'none' });
    expect(M.coursePaymentBadge(course({ payment_status: 'paid', legacy_paid_flag_only: true })).label).toBe(M.LEGACY_FLAG_UNVERIFIED_LABEL);
    expect(M.coursePaymentBadge(course({ payment_status: '' }))).toEqual({ label: M.PAYMENT_STATUS_LOADING_LABEL, tone: 'neutral', nextAction: 'billing' });
    expect(M.coursePaymentBadge(course({ payment_status: 'paid', _noncanonical: true })).label).toBe(M.PAYMENT_STATUS_LOADING_LABEL);
  });

  it('shows the monthly period label only when asked (課程查找)', () => {
    const monthly = course({ payment_status: 'unpaid', monthly_payment: { billing_period: '2026-10', payment_status: 'paid' } });
    expect(M.coursePaymentBadge(monthly).label).toBe(UNPAID);
    expect(M.coursePaymentBadge(monthly, { monthlyPeriod: true })).toEqual({ label: '2026-10 已收', tone: 'owing', nextAction: 'report_payment' });
    const review = course({ payment_status: 'paid', monthly_payment: { billing_period: '2026-10', review_required: true } });
    expect(M.coursePaymentBadge(review, { monthlyPeriod: true })).toEqual({ label: M.REVIEW_REQUIRED_LABEL, tone: 'review', nextAction: 'billing' });
  });

  // Codex P2 on #4204: the server's free/gift verdict outranks the monthly period label (its NT$0 period reads 已收 or review).
  it.each([
    ['paid period', { billing_period: '2026-10', payment_status: 'paid' }],
    ['review period', { billing_period: '2026-10', review_required: true }],
  ])('a monthly free/gift course stays 免費／贈送 over a %s', (_name, monthly_payment) => {
    const badge = M.coursePaymentBadge(course({ payment_status: 'free', payment_type: 'monthly', monthly_payment }), { monthlyPeriod: true });
    expect(badge).toEqual({ label: M.FREE_GIFT_LABEL, tone: 'free', nextAction: 'billing' });
  });

  it('TUITION_STATUS_CONFIG knows free with a non-owing style', () => {
    expect(M.TUITION_STATUS_CONFIG.free).toEqual({ label: M.FREE_GIFT_LABEL, cls: 'st-paid' });
  });
});

describe('#4150 isCourseSettled matches BillingPayableResolver (paid or free)', () => {
  it.each([['paid', true], ['free', true], ['FREE', true], ['partial', false], ['unpaid', false], ['pending_report', false], ['amount_review', false]])('%s -> %s', (payment_status, settled) => {
    expect(isCourseSettled({ payment_status })).toBe(settled);
  });
  it('unknown stays null', () => {
    expect(isCourseSettled({ payment_status: 'free', _noncanonical: true })).toBeNull();
    expect(isCourseSettled({})).toBeNull();
  });
});

describe('#4150 closedPaymentState (closed-course money verdict)', () => {
  it.each([
    ['amended, paid', { closed_reason: 'contract_amended', payment_status: 'paid' }, 'settled'],
    ['amended, free', { closed_reason: 'contract_amended', payment_status: 'free' }, 'settled'],
    ['amended, unpaid', { closed_reason: 'contract_amended', payment_status: 'unpaid' }, 'pending'],
    ['amended, noncanonical fallback row', { closed_reason: 'contract_amended', payment_status: 'paid', _noncanonical: true }, 'unknown'],
    ['amended, no status', { closed_reason: 'contract_amended' }, 'unknown'],
    ['settled_pending', { closed_reason: 'settled_pending', payment_status: 'paid' }, 'pending'],
    ['settled', { closed_reason: 'settled', payment_status: 'paid' }, null],
    ['open course', { payment_status: 'unpaid' }, null],
  ])('%s', (_name, row, state) => {
    expect(M.closedPaymentState(row)).toBe(state);
  });
});

describe('#4150 close-course warning', () => {
  async function confirmText(payment_status) {
    // The confirm is declined, so no request is sent.
    const confirms = [];
    await closeCourseNoRenew({
      course: { id: 42, subject: '英文', remaining_sessions: 0, payment_status },
      studentName: '學生',
      getRemainingSessions: (c) => c.remaining_sessions,
      getSubjectLabel: (s) => s,
      isCourseSettled,
      reloadCourses: async () => ({ ok: true }),
      confirmImpl: (message) => { confirms.push(message); return false; },
      alertImpl: () => {},
    });
    return confirms[0];
  }
  it('a free course is not warned as unpaid', async () => {
    expect(await confirmText('free')).not.toContain('目前尚未完成繳費');
  });
  it('an unpaid course still is', async () => {
    expect(await confirmText('unpaid')).toContain('目前尚未完成繳費');
  });
});

describe('#4150 both pages use the shared badge helper', () => {
  it.each(['StudentsList', 'CourseManagement'])('%s', (name) => {
    const source = pageSource(name);
    expect(source).toMatch(/coursePaymentBadge\(course(, \{ monthlyPeriod: true \})?\)/);
    expect(source).not.toContain("? status : 'unpaid'].label");
  });
  it('CourseManagement amended-contract rule reads isCourseSettled, not payment_status !== paid', () => {
    expect(pageSource('CourseManagement')).not.toMatch(/contract_amended' && \w+\.payment_status !== 'paid'/);
  });
  // Codex P2 on #4204: a `_noncanonical` amended row (list API failed) has no verdict, so it is never 已結算 / 已結案.
  it('CourseManagement reads closedPaymentState at all three closed-course sites, and 已結算 waits for a verdict', () => {
    const source = pageSource('CourseManagement');
    expect(source).not.toContain("'contract_amended' && isCourseSettled(");
    expect(source.match(/closedPaymentState\((c|hc)\) === 'unknown'/g)).toHaveLength(3);
    expect(source).toContain(`v-else-if="closedPaymentState(hc) === 'unknown'" class="tag tag-history tag-history--pending">{{ PAYMENT_STATUS_LOADING_LABEL }}</span>`);
  });
  it('StudentsList attention uses the shared notice statuses', () => {
    const source = pageSource('StudentsList');
    expect(source).not.toContain("['overdue', 'unpaid', 'pending']");
    expect(source).toContain('isPaymentNoticeStatus(paymentStatus)');
  });
});

describe('#4150 StudentsList renders a free-gift course without 未繳', () => {
  async function mountWith(overrides) {
    const row = {
      id: 7001, subject: 'Math', subject_name: '數學', class_type: 'one_on_one', payment_type: 'session', status: 'active',
      sessions_purchased: 8, remaining_sessions: 6, teacher_name: '合成老師', branch_name: '合成分校', room_name: '合成教室',
      days_of_week: [1], start_time: '16:00', duration_hours: 2, ...overrides,
    };
    vi.stubGlobal('fetch', vi.fn(async (input) => {
      const url = String(input);
      const body = url.includes('/student-classes') ? { data: [row] }
        : url.includes('/students?') ? { data: [{ id: 2001, name: '合成學生', status: 'active' }], total: 1 }
          : url.includes('/class-sessions') ? { byClass: { 7001: [] } } : { data: [] };
      return { ok: true, json: async () => body };
    }));
    const wrapper = shallowMount(StudentsList, { props: { branchId: 1 } });
    await flushPromises();
    await wrapper.find('.student-row').trigger('click');
    await flushPromises();
    return wrapper;
  }

  it.each([
    ['free', M.FREE_GIFT_LABEL, '進行中'],
    ['something_new', M.UNKNOWN_STATUS_LABEL, '進行中'],
    ['partial', '繳了一部分', '付款待確認'],
    ['unpaid', UNPAID, '付款待確認'],
  ])('%s', async (payment_status, label, attention) => {
    const wrapper = await mountWith({ payment_status });
    try {
      const card = wrapper.find('.student-course-card');
      expect(card.find('.student-course-card__payment').text()).toBe(label);
      expect(card.find('.payment-status-badge').text()).toBe(label);
      expect(wrapper.find('.student-course-picker__status').text()).toBe(attention);
      if (payment_status === 'free' || payment_status === 'something_new') {
        expect(card.find('.payment-status-badge').classes()).not.toContain('primary');
        expect(card.text()).not.toContain('登記繳費回報');
        expect(card.text()).not.toContain('先確認付款狀態');
      }
    } finally {
      wrapper.unmount();
      vi.unstubAllGlobals();
    }
  });
});
