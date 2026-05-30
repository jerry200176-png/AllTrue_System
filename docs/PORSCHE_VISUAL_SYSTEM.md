# AllTrue Porsche-Inspired Visual System

> ⛔ **SUPERSEDED（2026-05-30 起）。** 視覺系統已改採 **Stripe-inspired**，唯一真相來源為 [`docs/RULE_DESIGN_SYSTEM.md`](RULE_DESIGN_SYSTEM.md)。
> 本文件僅保留作歷史參考；新頁面與精修一律照 `RULE_DESIGN_SYSTEM.md`，`--porsche-*` token 現已 alias 到 `--ds-*`。

> Scope: front-end UI visual language for AllTrue web app pages and components.
> Goal: make AllTrue feel like a premium operations product with the restraint and precision of Porsche's official web experience, without copying Porsche branding or assets.

## 1. Intent

AllTrue's premium UI direction is **light-first, precise, quiet, and performance-oriented**.

Use Porsche as inspiration for:

- restrained color usage;
- strong typography hierarchy;
- frosted light surfaces;
- fine borders and grid discipline;
- subtle motion;
- high contrast for important information.

Do not use Porsche logos, imagery, typefaces, product names, or brand marks.

## 2. Design Principles

1. Light-first: default surfaces are white, off-white, and soft gray.
2. Dark is an accent: use dark graphite for text, primary CTA, or a small hero detail, not full-page black HUDs.
3. Limited color: black/white/gray carry most of the page; amber, blue, red, and green only communicate state.
4. Precision over spectacle: prefer 1px borders, clean spacing, and tabular numbers over neon glow.
5. Motion must be functional: hover lift, border emphasis, and loading shimmer are allowed; radar, infinite rotation, and heavy pulsing are not.
6. Brand consistency beats page novelty: new pages should reuse the same hero, metric, panel, row, badge, and button language.

## 3. Color Tokens

Use `frontend/src/styles.css` `--porsche-*` tokens for shared styling.

| Token | Use |
|---|---|
| `--porsche-ink` | primary text, primary CTA, precision line |
| `--porsche-ink-soft` | secondary text |
| `--porsche-surface` | main white surface |
| `--porsche-surface-muted` | gray/frosted page surface |
| `--porsche-border` | normal fine border |
| `--porsche-border-strong` | hover/focus border |
| `--porsche-amber` | billing, warning, deadline, attention |
| `--porsche-blue` | info, navigation, neutral action |
| `--porsche-red` | destructive or urgent state only |
| `--porsche-green` | completed/healthy state only |

## 4. Component Language

### Hero

Use for page identity and page-level context.

- Light frosted background.
- Large, dark title.
- Small uppercase kicker.
- Optional date/status panel.
- Very subtle grid texture is allowed.
- Avoid large dark blocks unless the whole page requires a dramatic landing moment.

### Metric Tile

Use for counts, ratios, and status summaries.

- White/frosted tile.
- Strong tabular number.
- Small uppercase label.
- A 2-3px bottom line can indicate category.
- Keep hover subtle.

### Work Panel

Use for dashboard sections and operational cards.

- White/frosted surface.
- Fine border.
- One top status line.
- Header icon should be monochrome unless state requires color.
- Rows inside the panel should be separated as small tiles, not dense plain table rows.

### Row

Use for schedules, payments, notifications, evaluations, and student/course lists.

- White row tile on light panel.
- Rounded 12-16px.
- Fine border.
- Hover: slight lift and stronger border.
- State should be shown with badge or thin line, not full-row neon.

### Badge

Use for compact state.

- Pill shape.
- Border included.
- Color only for semantic state:
  - amber: warning/payment/deadline;
  - red: destructive/urgent;
  - blue: info/navigation;
  - green: healthy/completed.

### Buttons

Use restrained CTA hierarchy:

- Primary: dark graphite pill.
- Secondary: white pill with gray border.
- Destructive: red pill.
- Avoid multicolor gradients except very rare marketing-style surfaces.

## 5. Forbidden Patterns

- Full-page black "game HUD" treatment.
- Neon borders everywhere.
- Radar circles, rotating rings, or constant motion.
- More than two accent colors in one component.
- Heavy glow as the main hierarchy.
- Hard-coded new color palettes when a `--porsche-*` token exists.
- Page-specific visual experiments that do not map back to this document.

## 6. Rollout Order

Use this order when upgrading pages:

1. `DirectorDashboard` and `CourseManagement` as reference pages.
2. `StudentsList`.
3. `TeachersList`.
4. `TuitionCollectionPage`.
5. `AttendancePage`.
6. `LearningRecordsPage`.

Each page upgrade should be a small PR with:

- local `npm run build`;
- PR CI green;
- merge;
- deploy success;
- `/api/v1/health` OK;
- `/version.json` updated when frontend changes are deployed.
