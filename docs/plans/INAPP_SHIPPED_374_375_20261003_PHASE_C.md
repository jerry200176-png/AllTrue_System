# Scoped Phase-C for shipped in-app 374, 375 (2026-10-03)

Two fixed per-ID records in the existing single-target writer, bound to exact
merge commits. No gate, actor, permission or writer change. Mechanically R3/T3
(protected workflow path); Founder GO 2026-10-03.

| In-app | Issue | Basis | rev (merge) | Deploy run | Reply claims |
|---|---|---|---|---|---|
| 374 | #3464 | PR #3465 (+ #3470) | `aab8dbcb8f966f4f72e813695c76c8bb1edec55b` | 37116877962 | Students trial button → Course Management conversion; purchase/renew/preview reject trials; monthly and package trials show a notice |
| 375 | #3467 | PR #3466 | `2c6839e31953343ad9ed2be2b5d067ed4f69f090` | 37116877962 | /api errors render JSON; 補卡 shows the real reason (≥2 chars, Chinese messages) |

Evidence: CI tests on each PR + production `deployment.json` backend_sha =
frontend_build_sha = `eecbf09c5cf1d7ecbfbcf98f393496cf01e35a6f` (both revs are
ancestors), health 200. No production UI check; replies say so. Reporter
acceptance pending.
