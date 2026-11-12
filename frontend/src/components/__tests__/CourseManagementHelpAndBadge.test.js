import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import fs from 'node:fs';
import path from 'node:path';
import CourseManagement from '../../pages/CourseManagement.vue';
import { REVIEW_REQUIRED_LABEL, REVIEW_REQUIRED_SHORT_LABEL } from '../../lib/courseMoneyState.js';

// In-app #538 (help panel confined to the header's left column, 5 cards in a 4-column grid)
// and #539 (review-required payment badge printed a 15-character sentence that wrapped to 3 lines).
const fixture = vi.hoisted(() => ({ courses: [] }));
vi.mock('../../supabase', () => ({ supabase: { auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'synthetic-help-badge' } } })) } } }));
vi.mock('../../lib/subjectsApi', () => ({ fetchSubjectOptions: vi.fn(async () => []) }));
vi.mock('../../lib/authedFetch', () => ({
  getAccessToken: vi.fn(async () => 'synthetic-help-badge'),
  authedFetch: vi.fn(async (url) => ({ ok: true, json: async () => String(url).startsWith('/api/v1/student-classes?')
    ? { data: fixture.courses, total: fixture.courses.length, current_page: 1, last_page: 1 }
    : { data: [] } })),
}));
const course = (id, extra = {}) => ({
  id, student_id: 700 + id, student_name: `合成學生${id}`, teacher_name: `合成老師${id}`, subject: 'english',
  first_class_date: '2026-09-01', status: 'active', ScheduleMode: 'count', payment_type: 'session',
  sessions_purchased: 8, SessionCount: 8, sessions_used: 3, remaining_sessions: 5,
  payment_status: 'unpaid', ...extra,
});
let wrapper;
async function open() {
  wrapper = shallowMount(CourseManagement, { props: { branchId: 15, userRole: 'director' },
    global: { stubs: { Card: false, Badge: false, Button: false, Primitive: false, ActionMenu: false, TextSizeToggle: false } } });
  await flushPromises();
  return wrapper.vm.$.setupState;
}
beforeEach(() => {
  fixture.courses = [course(31, { payment_status: 'review_required' }), course(32, { payment_status: 'paid' })];
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: [], by_class: {} }) })));
});
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); });

describe('#538 page help spans the header', () => {
  it('the help disclosure is a direct child of the header, not nested in the intro column', async () => {
    await open();
    const help = wrapper.get('[data-testid="course-help"]').element;
    expect(help.parentElement.classList.contains('course-workbench__header')).toBe(true);
    expect(wrapper.get('.course-workbench__intro').element.contains(help)).toBe(false);
    expect(wrapper.get('[data-guide="course-mgmt-header"]').element).toBe(help.parentElement);
  });

  it('the opened help takes the full header row and the closed icon stays beside the tools', () => {
    const src = fs.readFileSync(path.resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
    expect(src).toMatch(/\.course-workbench__help\[open\]\s*\{[^}]*flex:\s*0 0 100%/);
    expect(src).not.toMatch(/\.course-workbench__help\[open\]\s*\{[^}]*grid-column/);
  });

  it('the summary grid takes its column counts from summaryGridColumns, not a fixed 4', async () => {
    const s = await open();
    const grid = wrapper.get('[data-testid="course-lens-summary"]');
    const cards = grid.findAll('.course-lens-metric');
    expect(cards.length).toBe(s.courseLensMetrics.length);
    const style = grid.element.style;
    expect(Number(style.getPropertyValue('--summary-cols-wide'))).toBe(cards.length); // 5 cards: one row on desktop
    expect(Number(style.getPropertyValue('--summary-cols-mid'))).toBe(Math.ceil(cards.length / 2));
    const src = fs.readFileSync(path.resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
    expect(src).not.toMatch(/\.course-lens-summary\s*\{[^}]*repeat\(4,/);
  });
});

describe('#539 payment badge is a short chip with the sentence kept accessible', () => {
  it('review-required row prints the short label, keeps the full sentence in title and aria-label', async () => {
    await open();
    const badge = wrapper.get('.payment-status-and-action[data-course-id="31"] .payment-status-badge');
    expect(badge.text()).toBe(REVIEW_REQUIRED_SHORT_LABEL);
    expect([...REVIEW_REQUIRED_SHORT_LABEL].length).toBeLessThanOrEqual(6);
    expect(badge.attributes('aria-label')).toBe(REVIEW_REQUIRED_LABEL);
    expect(badge.attributes('title')).toContain(REVIEW_REQUIRED_LABEL);
  });

  it('other statuses keep their wording', async () => {
    await open();
    expect(wrapper.get('.payment-status-and-action[data-course-id="32"] .payment-status-badge').text()).toBe('已收');
  });
});

// Class-level guard: a page that shows the course payment badge must print it through shortPaymentLabel,
// so the long review sentence cannot reappear as chip text on another page.
describe('payment badge text goes through the one short-label helper', () => {
  const pagesDir = path.resolve(__dirname, '../../pages');
  const files = fs.readdirSync(pagesDir).filter((f) => f.endsWith('.vue'));
  const users = files.filter((f) => /coursePaymentBadge/.test(fs.readFileSync(path.join(pagesDir, f), 'utf8')));

  it('finds the pages that render coursePaymentBadge', () => {
    expect(users).toEqual(expect.arrayContaining(['CourseManagement.vue', 'StudentsList.vue']));
  });

  it.each(users)('%s imports shortPaymentLabel and never prints the raw badge label as text', (f) => {
    const src = fs.readFileSync(path.join(pagesDir, f), 'utf8');
    expect(src).toMatch(/\bshortPaymentLabel\b/);
    expect(src, 'chip text must use the short label; the sentence belongs in title / aria-label').not.toMatch(/>\s*\{\{\s*paymentStatusButtonLabel\(/);
  });
});
