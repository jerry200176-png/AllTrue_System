import { clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// Tailwind utilities are `tw:`-prefixed (see src/ui.css) so they never collide with legacy global CSS classes.
const twMerge = extendTailwindMerge({ prefix: 'tw' });

export function cn(...inputs) {
  return twMerge(clsx(inputs));
}
