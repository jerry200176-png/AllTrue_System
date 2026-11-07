// True when the user asked the OS/browser for less motion. Every motion component checks this first.
export const prefersReducedMotion = () =>
  typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
