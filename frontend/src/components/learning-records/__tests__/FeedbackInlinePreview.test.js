import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import FeedbackInlinePreview from '../FeedbackInlinePreview.vue';

const record = {
  id: 2496,
  parent_feedback: {
    content: '請問老師曼庭這星期的上課狀況還可以嗎？',
    updated_at: '2026-07-26T12:19:00Z',
  },
};

describe('FeedbackInlinePreview', () => {
  it('does not render when closed', () => {
    const w = mount(FeedbackInlinePreview, { props: { record, open: false } });
    expect(w.find('.lr-feedback-inline-preview').exists()).toBe(false);
  });

  it('shows the parent feedback content and a reply affordance when open', () => {
    const w = mount(FeedbackInlinePreview, { props: { record, open: true } });
    expect(w.text()).toContain('請問老師曼庭這星期的上課狀況還可以嗎？');
    // Regression guard for in-app bug #210 / GitHub #1476:
    // teachers reported no way to reply from this preview — it must offer a reply action.
    expect(w.text()).toContain('回覆家長');
  });

  it('emits reply with the record when the reply button is clicked', async () => {
    const w = mount(FeedbackInlinePreview, { props: { record, open: true } });
    await w.find('.lr-feedback-inline-preview__reply-btn').trigger('click');
    expect(w.emitted('reply')).toHaveLength(1);
    expect(w.emitted('reply')[0]).toEqual([record]);
  });
});
