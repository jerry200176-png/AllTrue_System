import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AtSubmitButton from '../AtSubmitButton.vue';

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
  return { promise, resolve, reject };
}

function mountButton(action, props = {}) {
  return mount(AtSubmitButton, {
    props: { action, successText: '已儲存', errorText: '未儲存', pendingText: '儲存中…', ...props },
    slots: { default: '儲存' },
  });
}

const activeFace = (w) => w.find('.at-submit__face.is-on').text();
const live = (w) => w.find('[aria-live="polite"]');

describe('AtSubmitButton', () => {
  afterEach(() => vi.useRealTimers());

  it('stays pending (busy, not re-submittable) until the action settles', async () => {
    const call = deferred();
    const action = vi.fn(() => call.promise);
    const w = mountButton(action);
    const button = w.find('button');
    expect(activeFace(w)).toBe('儲存');

    await button.trigger('click');
    expect(button.attributes('aria-busy')).toBe('true');
    expect(button.attributes('aria-disabled')).toBe('true');
    expect(activeFace(w)).toContain('儲存中…');
    await button.trigger('click');
    await flushPromises();
    expect(action).toHaveBeenCalledTimes(1);

    call.resolve(true);
    await flushPromises();
    expect(button.attributes('aria-busy')).toBeUndefined();
  });

  it('shows success only after the action resolves true, then returns to idle', async () => {
    vi.useFakeTimers();
    const w = mountButton(() => Promise.resolve(true));
    await w.find('button').trigger('click');
    await flushPromises();
    expect(activeFace(w)).toContain('已儲存');
    expect(live(w).exists()).toBe(true);
    expect(w.find('button').classes()).toContain('at-submit--success');

    vi.advanceTimersByTime(1600);
    await flushPromises();
    expect(activeFace(w)).toBe('儲存');
  });

  it('shows the error face when the action resolves false or throws', async () => {
    const failed = mountButton(() => Promise.resolve(false));
    await failed.find('button').trigger('click');
    await flushPromises();
    expect(activeFace(failed)).toContain('未儲存');

    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const thrown = mountButton(() => Promise.reject(new Error('500')));
    await thrown.find('button').trigger('click');
    await flushPromises();
    expect(activeFace(thrown)).toContain('未儲存');
    spy.mockRestore();
  });

  it('never claims success when the action does not return true', async () => {
    const w = mountButton(() => Promise.resolve(undefined));
    await w.find('button').trigger('click');
    await flushPromises();
    expect(activeFace(w)).toBe('儲存');
    expect(w.find('button').classes()).not.toContain('at-submit--success');
  });

  it('does not run the action while disabled and forwards button props', async () => {
    const action = vi.fn(() => Promise.resolve(true));
    const w = mountButton(action, { disabled: true, variant: 'danger', shape: 'rect' });
    const button = w.find('button');
    expect(button.attributes('disabled')).toBeDefined();
    expect(button.classes()).toEqual(expect.arrayContaining(['at-btn--danger', 'at-btn--rect']));
    await button.trigger('click');
    expect(action).not.toHaveBeenCalled();
  });

  it('runs an action once when two buttons share it (desktop bar + mobile sticky bar)', async () => {
    const call = deferred();
    const action = vi.fn(() => call.promise);
    const bar = mountButton(action);
    const sticky = mountButton(action);
    await bar.find('button').trigger('click');
    await sticky.find('button').trigger('click');
    await flushPromises();
    expect(action).toHaveBeenCalledTimes(1);
    expect(sticky.find('button').attributes('aria-busy')).toBe('true');

    call.resolve(true);
    await flushPromises();
    expect(activeFace(bar)).toContain('已儲存');
    expect(activeFace(sticky)).toContain('已儲存');
  });

  it('a button remounted mid-run (tab switch) shows pending and cannot start a second run', async () => {
    const call = deferred();
    const action = vi.fn(() => call.promise);
    const first = mountButton(action);
    await first.find('button').trigger('click');
    first.unmount();

    const remounted = mountButton(action);
    await flushPromises();
    expect(remounted.find('button').attributes('aria-busy')).toBe('true');
    await remounted.find('button').trigger('click');
    expect(action).toHaveBeenCalledTimes(1);

    call.resolve(true);
    await flushPromises();
    expect(activeFace(remounted)).toContain('已儲存');
    await remounted.find('button').trigger('click');
    await flushPromises();
    expect(action).toHaveBeenCalledTimes(2);
  });

  it('renders a single <button> root so page styles and classes reach it', () => {
    const w = mount(AtSubmitButton, { props: { action: () => true }, attrs: { class: 'page-cta' }, slots: { default: '送出' } });
    expect(w.element.tagName).toBe('BUTTON');
    expect(w.classes()).toEqual(expect.arrayContaining(['at-btn', 'at-submit', 'page-cta']));
  });

  it('parent-owned saving: shows pending from the prop and blocks clicks and form submits while pending', async () => {
    const onClick = vi.fn();
    const onSubmit = vi.fn((e) => e.preventDefault());
    const host = {
      components: { AtSubmitButton },
      props: ['pending'],
      setup: () => ({ onClick, onSubmit }),
      template: '<form @submit="onSubmit"><AtSubmitButton type="submit" :pending="pending" pending-text="送出中…" @click="onClick">確認</AtSubmitButton></form>',
    };
    const w = mount(host, { props: { pending: false }, attachTo: document.body });
    const button = w.find('button');
    expect(activeFace(w)).toBe('確認');
    expect(w.text()).not.toContain('已完成');
    button.element.click();
    expect(onClick).toHaveBeenCalledTimes(1);
    expect(onSubmit).toHaveBeenCalledTimes(1);

    await w.setProps({ pending: true });
    expect(button.attributes('aria-busy')).toBe('true');
    expect(activeFace(w)).toContain('送出中…');
    button.element.click();
    expect(onClick).toHaveBeenCalledTimes(1);
    expect(onSubmit).toHaveBeenCalledTimes(1);

    await w.setProps({ pending: false });
    expect(activeFace(w)).toBe('確認');
    expect(button.attributes('aria-busy')).toBeUndefined();
    w.unmount();
  });
});
