import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { usePageGuideTour } from '../../lib/usePageGuideTour';
import { getRoleOnboardingSteps } from '../../lib/roleOnboarding';
import { forceUnlockScroll, lockScroll, unlockScroll } from '../../lib/useScrollLock';

let wrapper;
let tour;
beforeEach(() => {
  vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
  Element.prototype.scrollIntoView = vi.fn();
  wrapper = mount({ setup() { tour = usePageGuideTour(); return () => null; } });
});
afterEach(() => {
  wrapper.unmount();
  forceUnlockScroll();
  document.body.innerHTML = '';
  vi.restoreAllMocks();
});

describe('hands-on role missions', () => {
  it('highlights a page anchor that arrives after async navigation', async () => {
    tour.startOnboarding(getRoleOnboardingSteps('teacher'));
    expect(document.querySelector('.guide-tour-highlighted')).toBeNull();
    document.body.insertAdjacentHTML('beforeend', '<section data-guide="teacher-home-today"></section>');
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(document.querySelector('.guide-tour-highlighted')?.dataset.guide).toBe('teacher-home-today');
  });

  it.each(['director', 'teacher'])('%s preserves the step while practicing every mission', async (role) => {
    const steps = getRoleOnboardingSteps(role);
    const completed = vi.fn();
    const progress = vi.fn();
    const navigate = vi.fn((page) => {
      const step = steps.find((entry) => entry.page === page);
      document.body.innerHTML = `<button data-guide="${step.target.match(/"(.+)"/)[1]}">操作</button>`;
    });
    navigate(steps[0].page);
    tour.startOnboarding(steps, { onNavigate: navigate, onProgress: progress, onComplete: completed });
    await nextTick();
    for (let index = 0; index < steps.length; index += 1) {
      expect(tour.stepIndex.value).toBe(index);
      tour.practiceStep();
      expect(document.body.style.position).toBe('');
      expect(document.querySelector('.guide-tour-highlighted')).toBeNull();
      const operation = vi.fn();
      document.querySelector('button').addEventListener('click', operation);
      document.querySelector('button').click();
      expect(operation).toHaveBeenCalledOnce();
      tour.handlePageChange();
      await nextTick();
      expect(document.querySelector('.guide-tour-highlighted')).toBeNull();
      expect(completed).not.toHaveBeenCalled();
      await tour.resumeStep();
      expect(tour.stepIndex.value).toBe(index);
      expect(document.querySelector('.guide-tour-highlighted')).not.toBeNull();
      await tour.nextStep();
    }
    expect(completed).toHaveBeenCalledOnce();
    expect(progress.mock.calls.map(([index]) => index)).toEqual([1, 2, 3]);
    expect(tour.isOpen.value).toBe(false);
    expect(document.body.style.position).toBe('');
  });

  it('does not release another modal scroll lock while practicing or closing', async () => {
    lockScroll();
    tour.startOnboarding(getRoleOnboardingSteps('teacher'));
    tour.practiceStep();
    tour.closeTour();
    expect(document.body.style.position).toBe('fixed');
    unlockScroll();
    expect(document.body.style.position).toBe('');
    await nextTick();
  });

  it('Escape preserves onboarding progress and ignores Escape during page interaction', async () => {
    const skip = vi.fn();
    tour.startOnboarding(getRoleOnboardingSteps('teacher'), { initialIndex: 2, onSkip: skip });
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(tour.isPracticing.value).toBe(true);
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(tour.stepIndex.value).toBe(2);
    expect(tour.isOpen.value).toBe(true);
    expect(skip).not.toHaveBeenCalled();
    await nextTick();
  });

  it('resumes from mid-progress initial index and completes properly', async () => {
    const steps = getRoleOnboardingSteps('director');
    const completed = vi.fn();
    const progress = vi.fn();
    const navigate = vi.fn((page) => {
      const step = steps.find((entry) => entry.page === page);
      document.body.innerHTML = `<button data-guide="${step.target.match(/"(.+)"/)[1]}">操作</button>`;
    });
    navigate(steps[2].page);
    tour.startOnboarding(steps, {
      initialIndex: 2,
      onNavigate: navigate,
      onProgress: progress,
      onComplete: completed,
    });
    await nextTick();

    expect(tour.stepIndex.value).toBe(2);
    expect(tour.progressText.value).toBe('3 / 4');
    await tour.nextStep();
    expect(tour.stepIndex.value).toBe(3);
    await tour.nextStep();
    expect(completed).toHaveBeenCalledOnce();
    expect(tour.isOpen.value).toBe(false);
  });
});
