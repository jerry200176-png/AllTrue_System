import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AtCopyButton from '../AtCopyButton.vue';

afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); });

describe('AtCopyButton', () => {
  it('copies, shows check + 已複製 with a polite live region, then resets', async () => {
    vi.useFakeTimers();
    const writeText = vi.fn(async () => {});
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    const w = mount(AtCopyButton, { props: { text: 'abc' }, attrs: { 'data-testid': 'c' } });
    expect(w.get('[data-testid="c"]').text()).toContain('複製');
    await w.get('[data-testid="c"]').trigger('click');
    await flushPromises();
    expect(writeText).toHaveBeenCalledWith('abc');
    expect(w.get('[data-testid="c"]').text()).toContain('已複製');
    expect(w.get('.at-btn__icon').text()).toBe('check');
    expect(w.get('[aria-live="polite"]').text()).toBe('已複製');
    expect(w.emitted('copied')).toHaveLength(1);
    vi.advanceTimersByTime(1500);
    await flushPromises();
    expect(w.get('.at-btn__icon').text()).toBe('content_copy');
    expect(w.get('[aria-live="polite"]').text()).toBe('');
  });

  it('emits error and stays unchanged when the clipboard is refused', async () => {
    vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(async () => { throw new Error('no'); }) } });
    const w = mount(AtCopyButton, { props: { text: 'abc' } });
    await w.get('button').trigger('click');
    await flushPromises();
    expect(w.emitted('error')).toHaveLength(1);
    expect(w.get('.at-btn__icon').text()).toBe('content_copy');
  });
});
