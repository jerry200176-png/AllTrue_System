import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { resolvePageGuideSteps } from './pageGuideConfig';
import { lockScroll, unlockScroll } from './useScrollLock';

const HIGHLIGHT_CLASS = 'guide-tour-highlighted';
const BODY_OPEN_CLASS = 'guide-tour-open';

const DIALOG_WIDTH = 360;
const VIEWPORT_PADDING = 16;
const POPOVER_GAP = 14;
const FALLBACK_POPOVER_HEIGHT = 240;
const FALLBACK_POPOVER_WIDTH = 320;

export function usePageGuideTour() {
  const isOpen = ref(false);
  const steps = ref([]);
  const stepIndex = ref(0);
  const popoverStyle = ref({});
  const effectivePlacement = ref('bottom');
  const highlightedElement = ref(null);
  const popoverElement = ref(null);

  const currentStep = computed(() => steps.value[stepIndex.value] || null);
  const hasPrev = computed(() => stepIndex.value > 0);
  const hasNext = computed(() => stepIndex.value < steps.value.length - 1);
  const progressText = computed(() => {
    if (!steps.value.length) return '';
    return `${stepIndex.value + 1} / ${steps.value.length}`;
  });

  const onScrollOrResize = () => {
    updatePopoverPosition();
  };

  const onEscape = (event) => {
    if (event.key === 'Escape') closeTour();
  };

  function removeHighlight() {
    if (highlightedElement.value) {
      highlightedElement.value.classList.remove(HIGHLIGHT_CLASS);
      highlightedElement.value = null;
    }
  }

  function clearAllHighlights() {
    if (typeof document === 'undefined') return;
    document.querySelectorAll(`.${HIGHLIGHT_CLASS}`).forEach((node) => {
      node.classList.remove(HIGHLIGHT_CLASS);
    });
  }

  function cleanupListeners() {
    window.removeEventListener('resize', onScrollOrResize);
    window.removeEventListener('scroll', onScrollOrResize, true);
    window.removeEventListener('keydown', onEscape);
  }

  function ensureListeners() {
    window.addEventListener('resize', onScrollOrResize);
    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('keydown', onEscape);
  }

  function getTargetElement(step) {
    if (!step?.target) return null;
    return document.querySelector(step.target);
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(value, max));
  }

  function calcPopoverStyle(rect, placement = 'bottom') {
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;

    const maxWidth = Math.max(220, Math.min(DIALOG_WIDTH, viewportWidth - VIEWPORT_PADDING * 2));
    const measuredWidth = popoverElement.value?.offsetWidth || FALLBACK_POPOVER_WIDTH;
    const measuredHeight = popoverElement.value?.offsetHeight || FALLBACK_POPOVER_HEIGHT;
    const width = Math.max(220, Math.min(measuredWidth, maxWidth));
    const height = Math.max(120, measuredHeight);

    if (!rect) {
      effectivePlacement.value = 'center';
      return {
        top: '50%',
        left: '50%',
        transform: 'translate(-50%, -50%)',
        width: `${maxWidth}px`,
        maxHeight: `${Math.max(180, viewportHeight - VIEWPORT_PADDING * 2)}px`,
      };
    }

    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;
    const preferred = ['top', 'bottom', 'left', 'right'].includes(placement) ? placement : 'bottom';
    const fallbackOrder = {
      top: ['top', 'bottom', 'right', 'left'],
      bottom: ['bottom', 'top', 'right', 'left'],
      left: ['left', 'right', 'top', 'bottom'],
      right: ['right', 'left', 'top', 'bottom'],
    };

    const candidates = fallbackOrder[preferred].map((candidatePlacement) => {
      let left = 0;
      let top = 0;
      if (candidatePlacement === 'top') {
        left = centerX - width / 2;
        top = rect.top - height - POPOVER_GAP;
      } else if (candidatePlacement === 'bottom') {
        left = centerX - width / 2;
        top = rect.bottom + POPOVER_GAP;
      } else if (candidatePlacement === 'left') {
        left = rect.left - width - POPOVER_GAP;
        top = centerY - height / 2;
      } else {
        left = rect.right + POPOVER_GAP;
        top = centerY - height / 2;
      }

      const overflowX = Math.max(0, VIEWPORT_PADDING - left) + Math.max(0, left + width - (viewportWidth - VIEWPORT_PADDING));
      const overflowY = Math.max(0, VIEWPORT_PADDING - top) + Math.max(0, top + height - (viewportHeight - VIEWPORT_PADDING));
      const score = overflowX + overflowY;
      return { candidatePlacement, left, top, score, fits: score <= 0 };
    });

    const chosen = candidates.find((c) => c.fits) || candidates.sort((a, b) => a.score - b.score)[0];
    const clampedLeft = clamp(chosen.left, VIEWPORT_PADDING, viewportWidth - VIEWPORT_PADDING - width);
    const maxAllowedTop = Math.max(VIEWPORT_PADDING, viewportHeight - VIEWPORT_PADDING - Math.min(height, viewportHeight - VIEWPORT_PADDING * 2));
    const clampedTop = clamp(chosen.top, VIEWPORT_PADDING, maxAllowedTop);

    effectivePlacement.value = chosen.candidatePlacement;
    return {
      top: `${clampedTop}px`,
      left: `${clampedLeft}px`,
      transform: 'translate(0, 0)',
      width: `${width}px`,
      maxHeight: `${Math.max(180, viewportHeight - VIEWPORT_PADDING * 2)}px`,
    };
  }

  async function syncCurrentStep() {
    removeHighlight();
    const step = currentStep.value;
    if (!step) {
      popoverStyle.value = calcPopoverStyle(null);
      return;
    }

    const element = getTargetElement(step);
    if (element) {
      highlightedElement.value = element;
      highlightedElement.value.classList.add(HIGHLIGHT_CLASS);
      highlightedElement.value.scrollIntoView({ block: 'center', inline: 'nearest' });
      await nextTick();
      const rect = highlightedElement.value.getBoundingClientRect();
      popoverStyle.value = calcPopoverStyle(rect, step.placement || 'bottom');
      return;
    }

    popoverStyle.value = calcPopoverStyle(null);
  }

  function closeTour() {
    isOpen.value = false;
    steps.value = [];
    stepIndex.value = 0;
    popoverStyle.value = {};
    removeHighlight();
    clearAllHighlights();
    cleanupListeners();
    unlockScroll();
    if (typeof document !== 'undefined') {
      document.body.classList.remove(BODY_OPEN_CLASS);
    }
  }

  function startTour(pageKey, context = {}) {
    closeTour();
    const rawSteps = resolvePageGuideSteps(pageKey, context);
    const availableSteps = rawSteps.filter((step) => {
      if (!step?.target) return true;
      return Boolean(document.querySelector(step.target));
    });

    if (!availableSteps.length) return false;

    steps.value = availableSteps;
    stepIndex.value = 0;
    isOpen.value = true;
    lockScroll();
    document.body.classList.add(BODY_OPEN_CLASS);
    ensureListeners();
    syncCurrentStep();
    return true;
  }

  function updatePopoverPosition() {
    if (!isOpen.value) return;
    const step = currentStep.value;
    if (!step) return;
    const element = getTargetElement(step);
    popoverStyle.value = calcPopoverStyle(element?.getBoundingClientRect() || null, step.placement || 'bottom');
  }

  function setPopoverElement(el) {
    popoverElement.value = el || null;
    updatePopoverPosition();
  }

  function nextStep() {
    if (!hasNext.value) {
      closeTour();
      return;
    }
    stepIndex.value += 1;
    syncCurrentStep();
  }

  function prevStep() {
    if (!hasPrev.value) return;
    stepIndex.value -= 1;
    syncCurrentStep();
  }

  function hasGuideForPage(pageKey, context = {}) {
    const rawSteps = resolvePageGuideSteps(pageKey, context);
    return rawSteps.some((step) => !step?.target || document.querySelector(step.target));
  }

  onBeforeUnmount(() => {
    closeTour();
  });

  // Recover from stale classes if the previous session ended unexpectedly.
  if (typeof document !== 'undefined') {
    document.body.classList.remove(BODY_OPEN_CLASS);
    clearAllHighlights();
  }

  return {
    isOpen,
    steps,
    stepIndex,
    currentStep,
    popoverStyle,
    effectivePlacement,
    hasPrev,
    hasNext,
    progressText,
    setPopoverElement,
    startTour,
    closeTour,
    nextStep,
    prevStep,
    hasGuideForPage,
  };
}

