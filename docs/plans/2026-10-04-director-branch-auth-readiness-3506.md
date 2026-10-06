# #3506 Director branch authorization readiness

## Goal and acceptance

After director login, a branch-scoped page mounts only after authenticated
`/api/v1/campuses` succeeds and the selected branch belongs to that response.
An empty or failed response keeps those pages unmounted and shows a bounded
retry state; a campus request that never completes fails after 25 seconds.
Routine access-token refresh for the same user/role retains the current page
while revalidating the authenticated campus list; a changed or failed scope
fails closed. No backend authorization, production data, credentials, or
deploy workflow changes are in scope.

## Evidence and boundary

- Base: `59daf9a39f1b66d6b6063f185f203955ec882a8f`.
- Current login sets `session` and `active` before awaiting
  `ensureDirectorBranches()`. The dashboard mounts with a public branch ID,
  requests tuition, and refetches after the authorized branch replaces it.
- Exact-head UI Smoke run `37182835854` observed tuition HTTP 403 then 200 in
  all five director dashboard viewports. The log lacks branch IDs and response
  bodies, so the precise source of each 403 remains unknown.
- Product boundary: the authenticated campus response governs director page
  mounting. The backend continues to reject unauthorized branch access.
- For `super_admin`, a successful authenticated `/campuses` returns all active
  campuses (`CampusController::index`); this preserves their normal branch
  access. The old fallback used public `/branches` on failure, which cannot
  establish that the token or list is authorized. During an outage, a valid
  super admin's branch-scoped pages now pause until retry succeeds; their
  branch-independent administration pages remain accessible.
- Risk: R3/T3 (identity/authorization timing). Prepare PR and evidence, then
  stop before protected merge or production activation.

## Steps and verification

1. Add a small branch authorization state contract and guard director
   branch-scoped page mounting during login, auth refresh, and failed campus
   loads. Preserve navigation state and show retry on failure.
2. Add deterministic regression coverage for delayed `/campuses` with an
   unauthorized public default, successful authorized branch resolution, and
   empty/failing/timed-out campus responses. Assert no tuition or rooms request before
   authorization resolves. Cover role switching into director mode, badge
   polling, and routine same-context token refresh without losing page state.
3. Run focused unit/integration checks, lint/build if available, diff review,
   and exact-head PR CI. Coordinate with #3511 owner only if shared smoke
   contracts must change. Production UI Smoke runs against the deployed app,
   so a pre-merge run cannot prove this unmerged product fix.

## Stop points

- Stop if a proposed solution widens backend access or requires production
  identity/data changes.
- Do not merge, deploy, or close #3506 before the R3/T3 Founder decision and
  production verification.
