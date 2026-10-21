import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

vi.mock('../../../supabase', () => ({ supabase: { auth: { getSession: async () => ({ data: { session: null } }) } } }));

import CourseTransferModal from '../CourseTransferModal.vue';
import { transferPrefillFromBlockedEdit, formatTransferStartDate } from '../../../lib/courseTransferPrefill.js';

// in-app #409: a blocked 課程設定 save hands off to 轉課, prefilled.
const form = {
  subject: 'math', teacher_id: 7, start_time: '16:00', duration_hours: 2,
  days_of_week: [3], day_time_slots: [{ day: 5, start_time: '15:00', duration_hours: 1.5 }],
};
const blocked = (changes, extra = {}) => ({
  code: 'billing_contract_locked', suggested_actions: ['use_transfer'],
  transfer_hint: { start_date: '2026-10-07', changes }, ...extra,
});

describe('transferPrefillFromBlockedEdit', () => {
  it('maps use_transfer to a prefill with only the changed fields', () => {
    expect(transferPrefillFromBlockedEdit(blocked(['subject']), form)).toEqual({ start_date: '2026-10-07', subject: 'math' });
    expect(transferPrefillFromBlockedEdit(blocked(['teacher', 'slots']), form)).toEqual({
      start_date: '2026-10-07', teacher_id: 7, weekday: 5, time: '15:00', duration_minutes: 90,
      slots: [{ weekday: 5, time: '15:00', duration_minutes: 90 }],
    });
  });

  it('falls back to days_of_week + start_time when there are no per-day slots', () => {
    const p = transferPrefillFromBlockedEdit(blocked(['slots']), { ...form, day_time_slots: [] });
    expect(p).toEqual({ start_date: '2026-10-07', weekday: 3, time: '16:00', duration_minutes: 120,
      slots: [{ weekday: 3, time: '16:00', duration_minutes: 120 }] });
  });

  it('carries every edited weekly slot, not just the first', () => {
    const multi = { ...form, day_time_slots: [{ day: 1, start_time: '15:00', duration_hours: 1 }, { day: 4, start_time: '18:30', duration_hours: 2 }] };
    expect(transferPrefillFromBlockedEdit(blocked(['slots']), multi).slots).toEqual([
      { weekday: 1, time: '15:00', duration_minutes: 60 }, { weekday: 4, time: '18:30', duration_minutes: 120 },
    ]);
  });

  it('labels locked billing edits that 轉課 will not carry', () => {
    const err = blocked(['teacher'], { transfer_hint: { start_date: '2026-10-07', changes: ['teacher'], not_carried: ['SessionCount'] } });
    expect(transferPrefillFromBlockedEdit(err, form).not_carried).toEqual(['購買堂數']);
  });

  it('returns null without the use_transfer suggestion (monthly / settled courses keep today\'s message)', () => {
    expect(transferPrefillFromBlockedEdit({ code: 'subject_change_requires_transfer', suggested_actions: ['split_contract'] }, form)).toBeNull();
    expect(transferPrefillFromBlockedEdit({ code: 'billing_contract_locked' }, form)).toBeNull();
    expect(transferPrefillFromBlockedEdit(null, form)).toBeNull();
  });

  it('formats the cutover date for the button label', () => {
    expect(formatTransferStartDate('2026-10-07')).toBe('10/7');
    expect(formatTransferStartDate('')).toBe('');
  });
});

describe('CourseTransferModal prefill', () => {
  const props = { show: false, courseId: 1, subjects: [{ value: 'math', label: '數學' }], teachers: [{ id: 7, username: '老師' }] };

  it('opens with the carried-over values and a note', async () => {
    const wrapper = mount(CourseTransferModal, { props: { ...props, prefill: transferPrefillFromBlockedEdit(blocked(['subject', 'teacher', 'slots']), form) } });
    await wrapper.setProps({ show: true });
    expect(wrapper.find('#ct-start').element.value).toBe('2026-10-07');
    expect(wrapper.find('#ct-subject').element.value).toBe('math');
    expect(wrapper.find('#ct-teacher').element.value).toBe('7');
    expect(wrapper.find('#ct-weekday').element.value).toBe('5');
    expect(wrapper.find('#ct-time').element.value).toBe('15:00');
    expect(wrapper.find('[data-testid="transfer-prefill-note"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('submits every carried slot and warns about non-carried billing edits', async () => {
    const calls = [];
    globalThis.fetch = vi.fn(async (url, init) => { calls.push(JSON.parse(init.body)); return { ok: true, json: async () => ({ source_correction: {}, new_course: {} }) }; });
    const prefill = { start_date: '2026-10-07', not_carried: ['購買堂數'],
      slots: [{ weekday: 1, time: '15:00', duration_minutes: 60 }, { weekday: 4, time: '18:30', duration_minutes: 120 }],
      weekday: 1, time: '15:00', duration_minutes: 60 };
    const wrapper = mount(CourseTransferModal, { props: { ...props, prefill } });
    await wrapper.setProps({ show: true });
    expect(wrapper.find('[data-testid="transfer-multi-slots"]').text()).toContain('週四 18:30');
    expect(wrapper.find('[data-testid="transfer-not-carried"]').text()).toContain('購買堂數');
    await wrapper.findAll('.actions button')[1].trigger('click');
    await new Promise((r) => setTimeout(r, 0));
    expect(calls[0].slots).toHaveLength(2);
    wrapper.unmount();
  });

  it('opens blank from the ⋯ menu (no prefill)', async () => {
    const wrapper = mount(CourseTransferModal, { props });
    await wrapper.setProps({ show: true });
    expect(wrapper.find('#ct-start').element.value).toBe('');
    expect(wrapper.find('[data-testid="transfer-prefill-note"]').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('課程設定 save error wiring', () => {
  const page = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../../pages/CourseManagement.vue'), 'utf8');

  it('stores the prefill on the save error and offers the CTA in both edit surfaces', () => {
    expect(page).toContain('transferPrefill: transferPrefillFromBlockedEdit(err, form)');
    expect(page.match(/data-testid="open-course-transfer-prefilled"/g)).toHaveLength(2);
    expect(page).toContain(':prefill="courseTransferPrefill"');
  });
});
