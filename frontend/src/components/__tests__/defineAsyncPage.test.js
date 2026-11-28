import { flushPromises, mount } from '@vue/test-utils';
import { KeepAlive, defineComponent, h, onActivated, onMounted, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineAsyncPage } from '../defineAsyncPage.js';

const skeleton = '[data-testid="at-skeleton"]';
const content = '[data-testid="page"]';
const Page = { setup: () => () => h('main', { 'data-testid': 'page' }, 'Page') };

// Mount a host so queries follow Vue when its async wrapper replaces its root node.
function mountPage(AsyncPage, options) {
  return mount({ render: () => h(AsyncPage) }, options);
}

function resolvesAfter(ms) {
  return () => new Promise((resolve) => setTimeout(() => resolve({ default: Page }), ms));
}

describe('async page skeleton', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('skips the skeleton when the page loads in 200 ms', async () => {
    const wrapper = mountPage(defineAsyncPage(resolvesAfter(200)));
    expect(wrapper.find(skeleton).exists()).toBe(false);

    await vi.advanceTimersByTimeAsync(199);
    expect(wrapper.find(skeleton).exists()).toBe(false);
    expect(wrapper.find(content).exists()).toBe(false);

    await vi.advanceTimersByTimeAsync(1);
    expect(wrapper.find(skeleton).exists()).toBe(false);
    expect(wrapper.find(content).exists()).toBe(true);

    await vi.advanceTimersByTimeAsync(400);
    expect(wrapper.find(skeleton).exists()).toBe(false);
    wrapper.unmount();
  });

  it('keeps the skeleton for 300 ms when the page loads in 310 ms', async () => {
    const wrapper = mountPage(defineAsyncPage(resolvesAfter(310)));
    await vi.advanceTimersByTimeAsync(299);
    expect(wrapper.find(skeleton).exists()).toBe(false);

    await vi.advanceTimersByTimeAsync(1);
    expect(wrapper.get(skeleton).classes()).not.toContain('at-skeleton--delayed');

    await vi.advanceTimersByTimeAsync(299);
    expect(wrapper.find(skeleton).exists()).toBe(true);
    expect(wrapper.find(content).exists()).toBe(false);

    await vi.advanceTimersByTimeAsync(11);
    expect(wrapper.find(skeleton).exists()).toBe(false);
    expect(wrapper.find(content).exists()).toBe(true);
    wrapper.unmount();
  });

  it('clears the show timer when navigating away from a pending page', async () => {
    let resolvePage;
    const loader = vi.fn(() => new Promise((resolve) => { resolvePage = resolve; }));
    const AsyncPage = defineAsyncPage(loader);
    const wrapper = mountPage(AsyncPage);
    await vi.advanceTimersByTimeAsync(100);
    expect(vi.getTimerCount()).toBe(1);

    wrapper.unmount();
    expect(vi.getTimerCount()).toBe(0);
    resolvePage(Page);
    await flushPromises();

    const reopened = mountPage(AsyncPage);
    expect(reopened.find(content).exists()).toBe(true);
    expect(reopened.find(skeleton).exists()).toBe(false);
    expect(loader).toHaveBeenCalledTimes(1);
    reopened.unmount();
  });

  it('preserves the mounted page when using KeepAlive', async () => {
    const mounted = vi.fn();
    const activated = vi.fn();
    const loader = vi.fn().mockResolvedValue(defineComponent({
      setup() {
        onMounted(mounted);
        onActivated(activated);
        return () => h(Page);
      },
    }));
    const AsyncPage = defineAsyncPage(loader);
    const active = ref(true);
    const wrapper = mount({
      setup: () => () => h(KeepAlive, () => active.value ? h(AsyncPage) : null),
    });
    await flushPromises();
    expect(wrapper.find(content).exists()).toBe(true);

    expect(activated).toHaveBeenCalledTimes(1);
    active.value = false;
    await flushPromises();
    expect(wrapper.find(content).exists()).toBe(false);

    active.value = true;
    await flushPromises();
    expect(wrapper.find(content).exists()).toBe(true);
    expect(wrapper.find(skeleton).exists()).toBe(false);
    expect(activated).toHaveBeenCalledTimes(2);
    expect(mounted).toHaveBeenCalledTimes(1);
    expect(loader).toHaveBeenCalledTimes(1);
    wrapper.unmount();
  });

  it('reports a failed load through Vue and can retry on remount', async () => {
    const error = new Error('chunk failed to load');
    const loader = vi.fn().mockRejectedValueOnce(error).mockResolvedValueOnce(Page);
    const errorHandler = vi.fn();
    const AsyncPage = defineAsyncPage(loader);
    const wrapper = mountPage(AsyncPage, { global: { config: { errorHandler } } });

    await flushPromises();
    expect(errorHandler.mock.calls[0][0]).toBe(error);
    expect(vi.getTimerCount()).toBe(0);
    wrapper.unmount();

    const retry = mountPage(AsyncPage);
    await flushPromises();
    expect(retry.find(content).exists()).toBe(true);
    expect(loader).toHaveBeenCalledTimes(2);
    retry.unmount();
  });

  it('lets a visible skeleton finish its minimum display when the load fails', async () => {
    const error = new Error('chunk failed to load after showing the skeleton');
    const loader = () => new Promise((_, reject) => setTimeout(() => reject(error), 310));
    const errorHandler = vi.fn();
    const wrapper = mountPage(defineAsyncPage(loader), { global: { config: { errorHandler } } });

    await vi.advanceTimersByTimeAsync(300);
    expect(wrapper.find(skeleton).exists()).toBe(true);

    await vi.advanceTimersByTimeAsync(299);
    expect(wrapper.find(skeleton).exists()).toBe(true);
    expect(errorHandler).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(11);
    expect(wrapper.find(skeleton).exists()).toBe(false);
    expect(wrapper.find(content).exists()).toBe(false);
    expect(errorHandler.mock.calls[0][0]).toBe(error);
    wrapper.unmount();
  });
});
