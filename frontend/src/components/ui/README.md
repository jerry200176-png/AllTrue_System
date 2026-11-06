# `components/ui` (shadcn-vue)

AllTrue's permanent component template: [shadcn-vue](https://www.shadcn-vue.com) (MIT; Reka UI + Tailwind v4), plain JS.
Rules: [`docs/RULE_DESIGN_SYSTEM.md`](../../../../docs/RULE_DESIGN_SYSTEM.md) section 0. Tokens and Tailwind setup: `src/ui.css`.
The `At*` components in `../design-system` are legacy and retire page by page.

Add a component: `npx shadcn-vue@latest add <name>`, then pin any new dependency to an exact version.

## Third-party pieces

- `number-ticker/` is adapted from [Inspira UI](https://github.com/unovue/inspira-ui) `NumberTicker` (MIT, Copyright (c) 2024-2026 rahulv.dev): plain JS, `tw:` prefix, count-up through `motion-v`, reduced-motion safe. The MIT header is kept in the file.
- `motion-v` (MIT, pinned in `package.json`) drives the count-up and the card enter on pages that use it.
