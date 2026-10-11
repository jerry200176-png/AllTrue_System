# Phase-C batch 2026-10-11 (in-app 389, 355, 354, 346)

| In-app | Issue | Fix PR | rev (merge) | Deploy run |
|---|---|---|---|---|
| 389 | #3832 | #3833 | `d0793e1b99cd5d941034d6a58ea3dafc1d4309f5` | 37780068906 |
| 355 | #3209 | #3835 | `d83eb91107bd1c365ac15707f722bd20c5f5f739` | 37778982075 |
| 354 | #3210 | #3835 | `d83eb91107bd1c365ac15707f722bd20c5f5f739` | 37778982075 |
| 346 | #3214 | #3835 | `d83eb91107bd1c365ac15707f722bd20c5f5f739` | 37778982075 |

Both merges are ancestors of production 5cf483d2f (read-only checked). In-app status was `triaged` for all four. In-app #387 got its shipped follow-up through bug-followup-comment (closed-as-logged), not this file.
Dispatch one ID at a time if auto-dispatch does not fire; verify each in-app status is `resolved` (F22).
Recovery: reopen path; if evidence is wrong, resolved -> in_progress plus a correction comment.
