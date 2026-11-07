import { describe, expect, it } from 'vitest';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import ParentHome from '../ParentHome.vue';
import { pickNextLesson, pickAmountDue, pickLatestFeedback } from '../../../lib/parentHome';

const sess = (o) => ({ id: 1, Status: 'scheduled', SessionDate: '2026-11-08', StartTime: '15:00', Subject: '英文', is_past_or_started: false, ...o });

describe('pickNextLesson', () => {
  it('picks the first live session with date, weekday, time and days left', () => {
    const r = pickNextLesson([sess({ id: 1, Status: 'cancelled' }), sess({ id: 2 }), sess({ id: 3, SessionDate: '2026-11-09' })], '2026-11-06');
    expect(r).toMatchObject({ dateLabel: '11/8', weekday: '日', time: '15:00', subject: '英文', daysLeft: 2, canLeave: true });
    expect(r.session.id).toBe(2);
  });
  it('skips an already-started lesson and picks the next one', () => {
    const r = pickNextLesson([sess({ id: 1, SessionDate: '2026-11-06', is_past_or_started: true }), sess({ id: 2, SessionDate: '2026-11-06', StartTime: '20:00' })], '2026-11-06');
    expect(r.session.id).toBe(2);
  });
  it('is null when nothing is upcoming, and hides leave when started or read-only', () => {
    expect(pickNextLesson([], '2026-11-06')).toBeNull();
    expect(pickNextLesson([sess({ SessionDate: '2026-11-01' })], '2026-11-06')).toBeNull();
    expect(pickNextLesson([sess({ is_past_or_started: true })], '2026-11-08')).toBeNull();
    expect(pickNextLesson([sess()], '2026-11-06', { actionsEnabled: false }).canLeave).toBe(false);
    expect(pickNextLesson([sess({ Status: 'leave_requested' })], '2026-11-06').leaveRequested).toBe(true);
  });
});

describe('pickAmountDue', () => {
  it('sums unpaid invoices net of payments and picks the earliest due date', () => {
    const inv = [
      { StudentClassID: 1, Status: 'unpaid', TotalAmount: 7200, DueDate: '2026-11-10', payments: [] },
      { StudentClassID: 2, Status: 'partial', TotalAmount: 3000, DueDate: '2026-11-05', payments: [{ Amount: 1000, Method: 'cash' }] },
      { Status: 'paid', TotalAmount: 9999, payments: [{ Amount: 9999 }] },
    ];
    expect(pickAmountDue(inv, [])).toEqual({ amount: 9200, dueDateLabel: '11/5' });
  });
  it('stale-paid invoice with short payments is 確認中, never a lower amount or 都繳清了', () => {
    const stale = { Status: 'paid', TotalAmount: 10000, payments: [{ Amount: 4000, Method: 'cash' }] };
    expect(pickAmountDue([stale, { Status: 'unpaid', TotalAmount: 2000, payments: [] }], [])).toMatchObject({ amount: null, review: true });
    expect(pickAmountDue([stale], [])).toMatchObject({ review: true });
    expect(pickAmountDue([{ ...stale, payments: [{ Amount: 10000 }] }], [])).toBeNull();
  });
  it('an alerted course with no open bill (unbilled) is 確認中, matched by course, never a short total', () => {
    const bill = (cls, amt) => ({ StudentClassID: cls, Status: 'unpaid', TotalAmount: amt, DueDate: '2026-11-10', payments: [] });
    expect(pickAmountDue([bill(1, 7200)], [{ class_id: 1 }, { class_id: 2 }])).toMatchObject({ amount: null, review: true, dueDateLabel: '11/10' });
    expect(pickAmountDue([bill(1, 7200)], [{ class_id: 1 }])).toEqual({ amount: 7200, dueDateLabel: '11/10' });
    // two open bills on one course + an unbilled course: still 確認中 (reviewer case)
    expect(pickAmountDue([bill(1, 3000), bill(1, 3000)], [{ class_id: 1 }, { class_id: 2 }])).toMatchObject({ amount: null, review: true });
    // a stopped course's bill (no alert) + an unbilled course: still 確認中
    expect(pickAmountDue([bill(3, 1000)], [{ class_id: 2 }])).toMatchObject({ amount: null, review: true });
  });
  it('falls back to unknown amount from payment alerts, and null when all paid', () => {
    expect(pickAmountDue([], [{ class_id: 1 }])).toEqual({ amount: null, dueDateLabel: '' });
    expect(pickAmountDue([{ Status: 'paid', TotalAmount: 5 }], [])).toBeNull();
    expect(pickAmountDue(undefined, undefined)).toBeNull();
  });
});

describe('pickLatestFeedback', () => {
  const rec = (o) => ({ SessionDate: '2026-11-01', Subject: '數學', Comment: '因式分解進步很多', parent_feedback: null, ...o });
  it('prefers the newest record with an unread reply, else the latest record', () => {
    const unread = rec({ SessionDate: '2026-10-25', parent_feedback: { has_unread_reply: true, replies: [{ author_role: 'teacher', content: '請複習' }] } });
    expect(pickLatestFeedback([rec(), unread])).toMatchObject({ isNew: true, dateLabel: '10/25', text: '請複習' });
    expect(pickLatestFeedback([rec(), unread.parent_feedback && rec({ SessionDate: '2026-10-01' })])).toMatchObject({ isNew: false, dateLabel: '11/1', subject: '數學' });
    expect(pickLatestFeedback([])).toBeNull();
  });
});

describe('ParentHome empty and filled states', () => {
  const html = (home) => renderToString(createSSRApp(ParentHome, { home }));
  it('renders 都繳清了 and empty copy', async () => {
    const out = await html({ nextLesson: null, due: null, feedback: null });
    expect(out).toContain('都繳清了');
    expect(out).toContain('近期沒有排課');
    expect(out).toContain('還沒有回饋');
    expect(out).not.toContain('怎麼繳費');
  });
  it('renders each fact once with 未繳 chip and 還有 N 天', async () => {
    const out = await html({
      nextLesson: pickNextLesson([sess()], '2026-11-06'),
      due: { amount: 7200, dueDateLabel: '11/10' },
      feedback: { isNew: true, dateLabel: '11/1', subject: '數學', text: '好' },
    });
    expect(out).toContain('還有 2 天');
    expect(out.replace(/<[^>]+>/g, '')).toContain('$7,200'); // amount is wrapped in the NumberTicker span
    expect(out.match(/未繳/g)).toHaveLength(1);
    expect(out.match(/7,200/g)).toHaveLength(1);
    expect(out.match(/data-guide="parent-home-/g)).toHaveLength(3);
  });
});
