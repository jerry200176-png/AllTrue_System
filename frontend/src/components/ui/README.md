# `components/ui` (shadcn-vue)

AllTrue's permanent component template: [shadcn-vue](https://www.shadcn-vue.com) (MIT; Reka UI + Tailwind v4), plain JS.
Rules: [`docs/RULE_DESIGN_SYSTEM.md`](../../../../docs/RULE_DESIGN_SYSTEM.md) section 0. Tokens and Tailwind setup: `src/ui.css`.
The `At*` components in `../design-system` are legacy and retire page by page.

Add a component: `npx shadcn-vue@latest add <name>`, then pin any new dependency to an exact version.

## Third-party pieces

- `lucide-vue-next` (icons used by template pages) is ISC licensed, pinned in `package.json`.
- `../motion/` is the copy-paste motion kit (`NumberTicker`, `ShineBorder`, `BlurFade`; Inspira UI, MIT; CSS/rAF, no dependency). Each file header names the upstream path and commit.
