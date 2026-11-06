---
name: web-design-guidelines
description: Review UI code against the Vercel Web Interface Guidelines (accessibility, focus, forms, motion, typography, performance). Use for the per-page design QA step or when asked to review UI, check accessibility or audit a page.
---

Read `guidelines.md` in this folder: the rules plus the output format, pinned to upstream `vercel-labs/web-interface-guidelines@434b7f91364665f2f733b310ec54809bf8f37937` (MIT, `LICENSE`). Don't fetch the unpinned upstream copy. Then review the named files against every rule and report `file:line` findings in the format it gives.

## AllTrue (local addition)

- The stack is Vue 3 + shadcn-vue (`tw:` prefix), not React. Translate React-specific rules to their Vue equivalents: `onKeyDown` → `@keydown`, `<Link>` → `<router-link>`/`<a>`.
- Brand and type: ink black #0B0B0C, gold #F7D917 / #F2C40F, amber #E9A51A, red only for danger. Minimum 16px body text, touch targets ≥44px.
- The single source is `docs/design/UI_FOUNDATION_MIGRATION_SEQUENCING.md`.
