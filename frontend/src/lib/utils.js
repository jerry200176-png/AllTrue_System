import { clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// Tailwind utilities are `tw:`-prefixed (see src/ui.css) so they never collide with legacy global CSS classes.
// The template type tokens (ui.css `--text-tpl-*`) are font sizes: without this, tailwind-merge reads `text-tpl-caption` as a colour and drops it when a colour class follows.
const twMerge = extendTailwindMerge({ prefix: 'tw', extend: { theme: { text: ['tpl-caption', 'tpl-body', 'tpl-list', 'tpl-unit', 'tpl-page', 'tpl-num-sm', 'tpl-num'] } } });

export function cn(...inputs) {
  return twMerge(clsx(inputs));
}
