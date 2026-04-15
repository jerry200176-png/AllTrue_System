let lockCount = 0;
let savedScrollY = 0;

/**
 * Prevent background scroll when modal/overlay/fullscreen is open.
 * Uses a reference-counted lock so nested overlays work correctly.
 * iOS Safari requires position:fixed + top offset to truly prevent scroll.
 */
export function lockScroll() {
  lockCount++;
  if (lockCount === 1) {
    savedScrollY = window.scrollY;
    document.body.style.position = 'fixed';
    document.body.style.top = `-${savedScrollY}px`;
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.overflow = 'hidden';
  }
}

export function unlockScroll() {
  if (lockCount <= 0) return;
  lockCount--;
  if (lockCount === 0) {
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.overflow = '';
    window.scrollTo(0, savedScrollY);
  }
}

export function forceUnlockScroll() {
  lockCount = 0;
  document.body.style.position = '';
  document.body.style.top = '';
  document.body.style.left = '';
  document.body.style.right = '';
  document.body.style.overflow = '';
}
