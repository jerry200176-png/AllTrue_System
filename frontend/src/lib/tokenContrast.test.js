import { readFileSync } from 'node:fs';
// @vitest-environment node
import { wcagContrast } from 'culori';
import { describe, expect, it } from 'vitest';

// WCAG AA text-contrast guard for design tokens. Values are read from styles.css, not hardcoded.
const css = readFileSync('src/styles.css', 'utf8');

function tokens(selector) {
  const start = css.indexOf(`${selector} {`);
  const body = css.slice(start, css.indexOf('\n}', start));
  return Object.fromEntries([...body.matchAll(/--([\w-]+):\s*(#[0-9a-fA-F]{3,8})\b/g)].map((m) => [m[1], m[2]]));
}

const themes = { light: tokens(':root'), dark: tokens('[data-theme="dark"]') };
// [foreground token, background token]
const TEXT_PAIRS = [
  ['ds-ink', 'ds-canvas'],
  ['ds-ink', 'ds-canvas-soft'],
  ['ds-ink-secondary', 'ds-canvas'],
  ['ds-ink-mute', 'ds-canvas'],
  ['ds-ink-mute', 'ds-canvas-soft'],
  ['ds-ink-mute', 'ds-surface-2'],
  ['ds-primary-text', 'ds-canvas'],
  ['ds-primary-text', 'ds-canvas-soft'],
  ['ds-info', 'ds-canvas'],
  ['ds-info', 'ds-canvas-soft'],
  ['ds-on-brand', 'ds-primary-soft'],
];

describe('design token text contrast >= 4.5 (WCAG AA)', () => {
  for (const [theme, t] of Object.entries(themes)) {
    for (const [fg, bg] of TEXT_PAIRS) {
      it(`${theme}: ${fg} on ${bg}`, () => {
        expect(t[fg], `${fg} missing`).toBeTruthy();
        expect(t[bg], `${bg} missing`).toBeTruthy();
        expect(wcagContrast(t[fg], t[bg])).toBeGreaterThanOrEqual(4.5);
      });
    }
  }
});

it('light: ds-info on ds-info-wash (status pills) >= 4.5', () => {
  expect(wcagContrast(themes.light['ds-info'], themes.light['ds-info-wash'])).toBeGreaterThanOrEqual(4.5);
});

// Structural guard: any rule painting the warm soft fill must also set the on-brand foreground
// (orange or inherited text on that fill fails AA in both themes).
it('warm soft fills always set color: var(--ds-on-brand)', async () => {
  const { globSync } = await import('node:fs');
  const files = [...globSync('src/**/*.vue'), 'src/styles.css'];
  const bad = [];
  for (const f of files) {
    for (const m of readFileSync(f, 'utf8').matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
      if (/background(-color)?\s*:\s*var\(--(primary-light|ds-primary-soft)\b/.test(m[2])
        && !/(^|[;\s])color\s*:\s*var\(--ds-on-brand\)/.test(m[2])) bad.push(`${f}: ${m[1].trim().slice(-60)}`);
    }
  }
  expect(bad).toEqual([]);
});
