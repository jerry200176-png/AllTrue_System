import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const css = readFileSync(resolve(__dirname, '../../../styles.css'), 'utf8');
const z = (name) => Number(css.match(new RegExp(`--ds-z-${name}:\\s*(\\d+)`))[1]);
const src = (rel) => readFileSync(resolve(__dirname, '..', rel), 'utf8');

describe('overlay stack (in-app #518 review: confirm hidden behind the drawer)', () => {
  it('orders drawer < dialog < confirm < popover < toast', () => {
    const order = ['overlay', 'modal', 'confirm', 'popover', 'toast'].map(z);
    expect(order).toEqual([...order].sort((a, b) => a - b));
    expect(new Set(order).size).toBe(order.length);
  });
  it('primitives read the tokens, never a literal z-50 / 1200', () => {
    for (const [f, t] of [['dialog/DialogContent.vue', 'modal'], ['dialog/DialogOverlay.vue', 'modal'], ['sheet/SheetContent.vue', 'modal'],
      ['alert-dialog/AlertDialogContent.vue', 'confirm'], ['dropdown-menu/DropdownMenuContent.vue', 'popover'], ['select/SelectContent.vue', 'popover'], ['tooltip/TooltipContent.vue', 'popover']]) {
      const s = src(f);
      expect(s, f).toContain(`var(--ds-z-${t})`);
      expect(s, f).not.toContain('tw:z-50');
    }
    expect(readFileSync(resolve(__dirname, '../../course-management/CourseManager.vue'), 'utf8')).toContain('z-index:var(--ds-z-overlay)');
  });
});
