# Phase-C batch 2026-10-11B (in-app 369, 341)

| In-app | Issue | Evidence | rev (merge) | Deploy run |
|---|---|---|---|---|
| 369 | #3356 | display fix #3333; stale invoice values aligned by POP apply (coordinator-reported run 37763817548, 2026-10-08, Founder-approved) | `781843b1273f3e1e52d6a7ec05f1d7bd804b3729` | 38140354682 |
| 341 | #3199 | no code defect; 3 past scheduled sessions cancelled by POP apply (coordinator-reported run 37760757645, 2026-10-08, Founder-approved); op PR #3818 | `f65ebe5b139a0808ebc7c835c25c9d611e41f02b` | 38140354682 |

Both revs are ancestors of production 2a713aa36. Explicit "issue" keys (#3861). In-app status was `triaged` for both.
Dispatch one ID at a time if auto-dispatch does not fire; verify each in-app status is `resolved`.
Recovery: resolved -> in_progress plus a correction comment.
