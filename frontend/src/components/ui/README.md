# `components/ui` (shadcn-vue)

AllTrue's permanent component template: [shadcn-vue](https://www.shadcn-vue.com) (MIT; Reka UI + Tailwind v4), plain JS.
Rules: [`docs/RULE_DESIGN_SYSTEM.md`](../../../../docs/RULE_DESIGN_SYSTEM.md) section 0. Tokens and Tailwind setup: `src/ui.css`.
The `At*` components in `../design-system` are legacy and retire page by page.

Add a component: `npx shadcn-vue@latest add <name>`, then pin any new dependency to an exact version.

## Third-party pieces

- `lucide-vue-next` (icons used by template pages) is ISC licensed, pinned in `package.json`.
- `../motion/` is the copy-paste motion kit (`NumberTicker`, `ShineBorder`, `BlurFade`; Inspira UI, MIT; CSS/rAF, no dependency). Each file header names the upstream path and commit.
## Overlay and data parts (K1b, #4989)
Direction (Founder 2026-11-07, 高級簡約): hairline borders, soft `bg-foreground/30` overlay, motion 200 ms or less, none under `prefers-reduced-motion`.

- `select/`: Reka Select (trigger, content, item, group, label, separator, scroll buttons); keyboard and typeahead from Reka.
- `table/`: styled plain `table`, `thead`, `tbody`, `tr`, `th`, `td`, `caption` only; no `@tanstack/vue-table`.
- `dropdown-menu/`: Reka menu with items, groups, labels, separators and shortcuts (sub-menus, radio and checkbox items are added when a page needs them).
- `sheet/`: side panel (`side` = right, left, top, bottom) built on the Reka Dialog.
- `dialog/`: modal Dialog with focus trap, Esc, `DialogTitle` and `DialogDescription` for the aria name.
- `alert-dialog/`: destructive confirm; `AlertDialogCancel` and `AlertDialogAction` (`variant="destructive"`), no outside-click close.
