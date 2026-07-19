# Design enforcement — Phase 2 minimum contract (AllTrue)

Authority: [`docs/RULE_DESIGN_SYSTEM.md`](../../RULE_DESIGN_SYSTEM.md) · pointer: [`/design.md`](../../../design.md)

## Already enforced in CI / scripts

| Check | Command / path | What it catches |
|-------|----------------|-----------------|
| Raw hex debt | `scripts/design-hex-count.sh`, `scripts/check-no-raw-hex.sh` | New hex outside tokens |
| Token baseline | `docs/design-hex-baseline.json` | Regression vs baseline |

## PR checklist (human / Agent)

- [ ] Campus / SC context visible where student or schedule data appears
- [ ] Student and teacher identity unambiguous in Chinese labels
- [ ] Schedule / reschedule side-effects stated before confirm
- [ ] Billing / entitlement changes never silent
- [ ] Destructive actions require explicit confirm + consequence text
- [ ] Touch targets usable on mobile admin (≥44px where interactive)
- [ ] Loading / empty / error states present for the touched journey
- [ ] No native `alert()` / `prompt()` for product flows
- [ ] Screenshots: desktop + mobile for user-facing changes
- [ ] Does not claim UX win from color/radius/shadow-only diffs

## Not required this phase

Large visual-regression grids or screenshot-similarity gates — ROI unclear; prefer Playwright smoke + checklist evidence.
