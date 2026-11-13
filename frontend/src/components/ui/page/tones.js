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

// PageShell's classes as a string, for a page whose root element must stay a plain element (tests mount it shallow, KeepAlive needs one root).
export const pageShell = 'tw:mx-auto tw:flex tw:w-full tw:max-w-7xl tw:flex-col tw:gap-4 tw:px-[calc(var(--ds-space-4)-10px)] tw:pb-24 tw:text-tpl-body tw:text-foreground tw:md:px-0 tw:md:pb-10';

// Tabs under the page header (PAGE_TEMPLATE §2): a hairline underline row; the selected tab has the ink underline.
export const pageTabs = Object.freeze({
  list: 'tw:flex tw:gap-6 tw:overflow-x-auto tw:border-b tw:border-border',
  tab: (active) => ['tw:-mb-px tw:inline-flex tw:h-12 tw:shrink-0 tw:cursor-pointer tw:items-center tw:border-0 tw:border-b-2 tw:bg-transparent tw:px-1 tw:text-tpl-body tw:font-semibold tw:whitespace-nowrap tw:outline-none tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50', active ? 'tw:border-foreground tw:text-foreground' : 'tw:border-transparent tw:text-tpl-secondary tw:hover:text-foreground'],
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

// Dense label/value facts inside a list row (PAGE_TEMPLATE §5): the label sits above the value, facts wrap between each other,
// the phone shows two per row. `sub` is the second line under a value.
export const factRow = Object.freeze({
  label: 'tw:mb-1 tw:block tw:text-tpl-caption tw:font-medium tw:text-tpl-secondary',
  value: 'tw:text-tpl-body tw:font-semibold tw:text-foreground',
  sub: 'tw:mt-0.5 tw:block tw:text-tpl-caption tw:font-normal tw:text-tpl-secondary',
});

// Native-button row actions (44px pills) for rows whose markup must stay a plain `button`: primary = ink pill, secondary = hairline outline.
const rowButtonBase = 'tw:inline-flex tw:min-w-11 tw:cursor-pointer tw:items-center tw:justify-center tw:rounded-full tw:border-0 tw:px-4 tw:text-tpl-caption tw:font-semibold tw:outline-none tw:focus-visible:ring-3 tw:focus-visible:ring-ring/50';
const rowButton = `${rowButtonBase} tw:h-11 tw:shrink-0 tw:whitespace-nowrap`;
const rowWrapBase = `${rowButtonBase} tw:max-w-full tw:min-h-11 tw:py-2 tw:text-left tw:whitespace-normal tw:bg-card tw:shadow-[inset_0_0_0_1px_var(--ds-hairline)] tw:hover:bg-muted`;
export const rowAction = Object.freeze({
  primary: `${rowButton} tw:bg-primary tw:text-primary-foreground`,
  secondary: `${rowButton} tw:bg-card tw:text-foreground tw:shadow-[inset_0_0_0_1px_var(--ds-hairline)] tw:hover:bg-muted`,
  // A secondary action whose words are a sentence (堂數待對帳 查看對帳明細): at least 44px, wraps instead of pushing the page sideways.
  secondaryWrap: `${rowWrapBase} tw:text-foreground`,
  // The same wrapped button in the danger text colour (堂數待對帳). Two text colours in one class list do not merge (the later rule in the
  // stylesheet wins), so the colour is the only difference between the two sets.
  dangerWrap: `${rowWrapBase} tw:text-[var(--ds-danger-text)]`,
  // The ⋯ menu trigger (icon-sized).
  menu: 'tw:size-11 tw:min-h-11 tw:min-w-11 tw:rounded-full tw:border-0 tw:bg-card tw:p-0 tw:text-foreground tw:shadow-[inset_0_0_0_1px_var(--ds-hairline)] tw:hover:bg-muted',
});

// Course contract rows (課程查找): one grid template shared by the column header and every row so they line up.
// Phone: 2 columns (facts side by side). 768-1023: 6 columns (name + actions on top, three facts under). 1024+: the 5-column table.
export const contractGrid = Object.freeze({
  head: 'tw:hidden tw:lg:grid tw:lg:grid-cols-[minmax(0,1.4fr)_minmax(0,.9fr)_minmax(6.5rem,.7fr)_minmax(9.5rem,.8fr)_minmax(14rem,1.2fr)] tw:items-center tw:gap-x-4 tw:h-11 tw:px-5 tw:bg-muted tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary',
  row: 'tw:grid tw:min-w-0 tw:grid-cols-2 tw:items-start tw:gap-x-4 tw:gap-y-3 tw:md:grid-cols-6 tw:lg:grid-cols-[minmax(0,1.4fr)_minmax(0,.9fr)_minmax(6.5rem,.7fr)_minmax(9.5rem,.8fr)_minmax(14rem,1.2fr)]',
  subject: 'tw:col-span-2 tw:min-w-0 tw:md:col-span-4 tw:lg:col-span-1',
  fact: 'tw:min-w-0 tw:md:col-span-2 tw:lg:col-span-1',
  factWide: 'tw:col-span-2 tw:min-w-0 tw:md:col-span-2 tw:lg:col-span-1',
  actions: 'tw:col-span-2 tw:min-w-0 tw:md:col-span-2 tw:md:col-start-5 tw:md:row-start-1 tw:md:justify-self-end tw:lg:col-auto tw:lg:row-auto tw:lg:justify-self-stretch',
  label: 'tw:lg:sr-only',
});

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
  // One fact that must not break inside (a date range, a contract ref, a status in a run of facts): wrap between facts, never within one.
  fact: 'tw:inline-block tw:whitespace-nowrap',
  // A money line under a value (應繳, 估算): tabular, one line, secondary colour. `amountPending` = not billed yet (warning text),
  // `amountEstimate` = an estimate, lighter weight. Red stays for money that is owed.
  amount: 'tw:block tw:text-tpl-caption tw:font-semibold tw:tabular-nums tw:whitespace-nowrap tw:text-tpl-secondary',
  amountPending: 'tw:block tw:text-tpl-caption tw:font-semibold tw:tabular-nums tw:whitespace-nowrap tw:text-[var(--ds-warning-text)]',
  amountEstimate: 'tw:block tw:text-tpl-caption tw:font-normal tw:tabular-nums tw:whitespace-nowrap tw:text-tpl-secondary',
  total: 'tw:h-14tw:border-0 tw:bg-muted tw:px-3 tw:py-2 tw:md:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-bold tw:text-foreground',
  phoneHide: 'tw:max-md:hidden',
  phoneOnly: 'tw:md:hidden',
});
