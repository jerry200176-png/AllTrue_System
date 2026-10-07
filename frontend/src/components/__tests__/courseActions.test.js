import { describe, expect, it } from 'vitest';
import { courseActions } from '../../lib/courseActions.js';

const ids = (model) => model.groups.flatMap((g) => g.items.map((i) => i.id));
const session = { id: 1, status: 'active' };
const base = { isSession: true, canQuickAdd: true, canClose: true, purchaseLabel: '加購堂數' };

describe('courseActions — one primary, overflow grouped by intent', () => {
  it('defaults the primary to the fallback and groups 調動 / 帳務 / 結束 in that order', () => {
    const m = courseActions(session, { ...base, fallback: 'manage' });
    expect(m.primary).toEqual({ id: 'manage', label: '管理課程' });
    expect(m.groups.map((g) => g.label)).toEqual(['調動', '帳務', '結束']);
    expect(ids(m).slice(0, 6)).toEqual(['reschedule', 'substitute', 'manual-session', 'quick-add', 'transfer', 'duplicate']);
  });

  it('flag-off rows fall back to 編輯', () => {
    expect(courseActions(session, base).primary).toEqual({ id: 'edit', label: '編輯' });
  });

  it('paused course: 恢復 is primary and is not repeated in ⋯ (pause hidden too)', () => {
    const m = courseActions({ ...session, status: 'inactive' }, base);
    expect(m.primary.id).toBe('resume');
    expect(ids(m)).not.toContain('resume');
    expect(ids(m)).not.toContain('pause');
    expect(ids(m)[0]).toBe('edit');
  });

  it('waived courses cannot resume', () => {
    const m = courseActions({ ...session, status: 'inactive', closed_reason: 'waived' }, base);
    expect(m.primary.id).toBe('edit');
    expect(ids(m)).not.toContain('resume');
  });

  it('scheduling need beats renewal; renewal uses the caller label', () => {
    expect(courseActions(session, { ...base, needsScheduling: true, renewalDue: true }).primary)
      .toEqual({ id: 'manual-session', label: '排課' });
    const renew = courseActions(session, { ...base, renewalDue: true, purchaseLabel: '續報加購' });
    expect(renew.primary).toEqual({ id: 'purchase', label: '續報加購' });
    expect(ids(renew)).not.toContain('purchase');
  });

  it('scheduling items keep the legacy row/More split per course type', () => {
    const manual = courseActions(session, { ...base, isManualOccurrence: true });
    expect(manual.groups[0].items.find((i) => i.id === 'manual-session').label).toBe('＋新增下一堂');
    expect(ids(manual)).not.toContain('quick-add');
    const monthly = courseActions(session, { isMonthly: true });
    expect(monthly.groups[0].items.find((i) => i.id === 'manual-session').label).toBe('排月結');
    expect(monthly.groups[0].items.find((i) => i.id === 'monthly-session').label).toBe('新增月結堂次');
    expect(ids(courseActions(session, {}))).not.toContain('manual-session');
  });

  it('a session course that cannot quick-add keeps the item disabled with the reason', () => {
    const item = courseActions(session, { ...base, canQuickAdd: false, quickAddReason: '已無剩餘堂數' })
      .groups[0].items.find((i) => i.id === 'quick-add');
    expect(item).toMatchObject({ disabled: true, title: '已無剩餘堂數' });
  });

  it('optional billing items follow the caller capabilities', () => {
    const none = ids(courseActions(session, base));
    expect(none).not.toContain('payment-slip');
    expect(none).not.toContain('package-preview');
    expect(none).not.toContain('contract-revert');
    const all = ids(courseActions(session, { ...base, paymentNotice: true, packagePreview: true, contractAmended: true }));
    expect(all).toEqual(expect.arrayContaining(['payment-slip', 'package-preview', 'contract-revert']));
  });

  it('delete is always the last item, marked danger; close only when allowed', () => {
    const m = courseActions(session, base);
    const end = m.groups.at(-1).items;
    expect(end.at(-1)).toMatchObject({ id: 'delete', danger: true, confirm: true });
    expect(end.find((i) => i.id === 'close').confirm).toBe(true);
    expect(ids(courseActions(session, { ...base, canClose: false }))).not.toContain('close');
  });

  it('no empty groups and no duplicate ids', () => {
    const m = courseActions({ id: 2, status: 'active' }, {});
    expect(m.groups.every((g) => g.items.length > 0)).toBe(true);
    expect(new Set(ids(m)).size).toBe(ids(m).length);
  });
});
