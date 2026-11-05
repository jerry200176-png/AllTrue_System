# Daily progress UX evidence — 2026-08-28

## Decision

Ship a small V1 on the director home page: show one honest daily course
progress signal and one next-action CTA. This is a task-first operations
pattern, not a points, streak, or reward system. The V1 is frontend-only and
does not change attendance, billing, scheduling, authorization, or API
contracts.

## Local evidence and constraints

- `frontend/src/pages/DirectorDashboard.vue` already owns the director's
  `todaySchedules`, `attendedCount`, and severity-sorted `dashboardPrimaryTasks`.
- The existing page has a focused `今天` view and a progressively disclosed
  `完整營運` view. The new card is placed in the focused view so the first
  decision remains visible without loading secondary reports.
- `attendedCount / todaySchedules.length` is the only progress denominator used
  in V1. Unknown operational work is not counted as completed work.
- `frontend/src/lib/dailyWorkProgress.js` clamps malformed counters and keeps a
  zero-total response as an honest empty state.

## Comparable evidence

### Duolingo — behavior and product principle

- Official product write-up: [Improving the streak](https://blog.duolingo.com/improving-the-streak/).
  Duolingo reports separating the one-action streak requirement from the daily
  goal. The transferable principle is to make the daily target understandable
  and achievable without making a larger goal a prerequisite for continuity.
- Official product principles:
  [Take the long view](https://blog.duolingo.com/product-principles/).
  The relevant adaptation is a low-pressure, repeatable daily loop; AllTrue
  must not copy consumer-game rewards into a school operations surface.
- Live capture: `https://www.duolingo.com/`, public unauthenticated landing page,
  rendered HTML via Cloudflare Browser Run, captured 2026-08-28 18:44 +08:00.
  The page visibly leads with short benefit statements, a clear primary CTA,
  and a separate motivation section. This is public marketing behavior only;
  it is not evidence of Duolingo's authenticated home implementation.

### GitHub Primer — progress semantics

- [Primer progress bar guidance](https://primer.github.io/design/components/)
  describes progress as a representation of a whole and pairs it with visible
  text explaining the units. [Primer React source](https://github.com/primer/react/blob/b1117811cebfb9463f20fe76f77cdf13917ae6b2/packages/react/src/ProgressBar/ProgressBar.tsx)
  and its [tests](https://github.com/primer/react/blob/b1117811cebfb9463f20fe76f77cdf13917ae6b2/packages/react/src/ProgressBar/ProgressBar.test.tsx)
  were checked at commit `b1117811cebfb9463f20fe76f77cdf13917ae6b2`; the repo
  is MIT licensed. The useful pattern is explicit `aria-valuenow`, min/max,
  visible labeling, and tests for the accessible contract.

### GitLab Pajamas — loading and accessibility boundary

- [Pajamas progress bar guidance](https://design.gitlab.com/components/progress-bar/)
  distinguishes a completion bar from loading UI, requires visible units, and
  recommends a labeled progressbar role. The maintained source was checked at
  GitLab `gitlab-org/gitlab-ui` `main` commit
  `8660f9fec8cb516908ea705c6a91d21c74895564`: the component is under
  `src/components/base/progress_bar/progress_bar.vue` and its tests under
  `progress_bar.spec.js`. GitLab's repository license is MIT. AllTrue keeps
  loading skeletons separate from the progressbar for the same reason.

## Adopted / rejected patterns

| Pattern | Decision | AllTrue adaptation |
|---|---|---|
| One daily target with immediate feedback | Adopt | `今日課務進度`, completed/total, percent, and a completion message |
| Independent streak or gamified reward | Reject for V1 | No streak is invented; business work is not scored as a game |
| One next action | Adopt | Reuses the already severity-sorted first director task and existing safe navigation |
| Progress bar without units | Reject | Visible count and `aria-valuenow`/`aria-valuemax` are both rendered |
| Spinner/skeleton presented as completion | Reject | Loading has its own status state and never displays `0 / 0` as a result |
| Large new design system dependency | Reject | Existing Vue 3 and AllTrue `--ds-*` tokens only; no copied code |

## Acceptance and rollback

- Director focus view renders the card from already loaded schedule state.
- `2 / 5` renders 40%, clamps over-counts, and exposes progressbar values to
  assistive technology.
- Empty schedules say there are no scheduled courses; loading does not look
  complete; the next-action button emits the existing dashboard task.
- `npm run lint:no-undef`, the focused helper/component tests, and `npm run
  build` must pass before review.
- Rollback is a normal revert of the frontend commit; there is no migration,
  API write, or production data mutation.

## Follow-up hypothesis

After deployment, measure `today-progress-card` visibility and next-action
activation by role/viewport, but do not add a streak or cross-role score until
the product owner defines the business meaning of “daily completion” and an
independent review confirms that the metric cannot hide overdue billing,
attendance, or parent cases.
