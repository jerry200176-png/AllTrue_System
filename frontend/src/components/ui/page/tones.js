// Status tones and table cell classes for template pages (docs/design/PAGE_TEMPLATE.md §5–§6).
// Colours are --ds-* tokens only: success = done/paid, warning = needs a look, danger = owed/overdue, neutral = everything else.
export const toneBadge = Object.freeze({
  success: 'tw:bg-[var(--ds-success-wash)] tw:text-[var(--ds-success-text)]',
  warning: 'tw:bg-[var(--ds-warning-wash)] tw:text-[var(--ds-warning-text)]',
  danger: 'tw:bg-[var(--ds-danger-wash)] tw:text-[var(--ds-danger-text)]',
  neutral: 'tw:bg-secondary tw:text-tpl-secondary',
});

export const toneText = Object.freeze({
  success: 'tw:text-[var(--ds-success-text)]',
  warning: 'tw:text-[var(--ds-warning-text)]',
  danger: 'tw:text-[var(--ds-danger-text)]',
  neutral: 'tw:text-foreground',
});

// The 今日工作台 card surface (same values as `.tpl-card` in ui.css, as utilities): glass fill, hairline, 24px radius, soft shadow.
export const surface = Object.freeze({
  card: 'tw:rounded-3xl tw:border tw:border-solid tw:border-[var(--tpl-line)] tw:bg-[var(--tpl-glass-bg)] tw:shadow-tpl-card tw:backdrop-blur-[18px]',
});

// Control sizes on top of ui/button: page-level actions are 48px, in-row actions 44px (both >= the 44px touch floor).
export const control = Object.freeze({
  page: 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none',
  pageIcon: 'tw:size-12 tw:px-0 tw:shadow-none',
  row: 'tw:h-11 tw:px-4 tw:text-[length:var(--tpl-text-caption)] tw:font-semibold tw:shadow-none',
});

// The global `th` / `td` rules in styles.css (uppercase, 12px, grey fill) are overridden here on purpose.
const head = 'tw:h-11 tw:border-b tw:border-border tw:bg-muted tw:px-3 tw:py-0 tw:text-tpl-caption tw:md:px-4 tw:font-semibold tw:tracking-normal tw:text-tpl-secondary tw:normal-case tw:whitespace-nowrap';
const cell = 'tw:h-14 tw:border-b tw:border-border tw:px-3 tw:py-2 tw:md:px-4 tw:text-[length:var(--tpl-text-body)] tw:text-foreground';

export const dataTable = Object.freeze({
  table: 'tw:text-[length:var(--tpl-text-body)]',
  head,
  numHead: `${head} tw:text-right`,
  cell,
  // A person's name stays on one line; the column takes the width it needs.
  name: `${cell} tw:font-semibold tw:whitespace-nowrap`,
  num: `${cell} tw:text-right tw:whitespace-nowrap tw:tabular-nums`,
  // Second line inside a cell (subject, teacher, amount still owed). It may wrap; the name above it may not.
  sub: 'tw:block tw:text-tpl-caption tw:font-normal tw:whitespace-normal tw:text-tpl-secondary',
  total: 'tw:h-14 tw:border-0 tw:bg-muted tw:px-3 tw:py-2 tw:md:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-bold tw:text-foreground',
  phoneHide: 'tw:max-md:hidden',
  phoneOnly: 'tw:md:hidden',
});
