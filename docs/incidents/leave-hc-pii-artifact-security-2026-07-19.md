# Leave-HC director pack — PII / artifact security check (2026-07-19)

**Issue:** [#1342](https://github.com/jerry200176-png/AllTrue_System/issues/1342)  
**Source pack run:** [`29686172773`](https://github.com/jerry200176-png/AllTrue_System/actions/runs/29686172773)

## Visibility & retention

| 項目 | 現況 | 判定 |
|------|------|------|
| GitHub repository | Private (`jerry200176-png/AllTrue_System`) | OK — redacted summary only in git |
| Actions artifact visibility | Repo collaborators with Actions read | OK for controlled ops; **not** public |
| Artifact name | `director-leave-hc-and-evidence` | Contains named CSV + `ops-review-key-map.json` |
| Retention (pack workflow) | Was 30d → **tighten to 14d** | Minimum necessary for director reply window |
| Download ACL | Same as private repo Actions | Ops-only practice; do not share zip externally |
| `ops-review-key-map.json` | Artifact only; never Issue/PR body | Must stay ops-private |

## Log / commit risk

| 檢查 | 結果 | 處置 |
|------|------|------|
| Actions log 印學生姓名？ | Dry-run lines 僅 `sc/cs/date/times`；pack summary 無姓名 | 維持；禁止 `cat` 姓名 CSV 進 log |
| 姓名版 CSV commit 進 repo？ | **否** — repo 僅 `leave-slot-hc-redacted-*.csv` | `.gitignore` 補強 |
| 主任填好 CSV commit／貼 Issue？ | **禁止**（SOP + tracker） | 違規 = 立即 purge + rotate if leaked |
| 暫存檔 | Pi `/tmp/director-leave-hc`、runner `out/` | Workflow 結束後 runner 銷毀；Pi 於 pack job 末尾 `rm -rf` |
| Audit 保留 | redacted counts、checksum、execution audit ID、approver label（非完整姓名 CSV） | 見 repair bundle |

## Rules (normative)

1. Repository **only** redacted summary / tracker counters.  
2. Named CSV **only** in Actions artifact (≤14d) or controlled ops handoff.  
3. Filled named CSV: never commit, never paste to Issue/PR.  
4. Never print student names or full CSV in Actions logs.  
5. `ops-review-key-map.json` never in public comments.  
6. After convert → repair, wipe local filled CSV + map copies.

## Residual risk

Private-repo collaborators can download artifacts. Acceptable for current ops size; if contractor access expands, move named packs to encrypted ops store and stop uploading names to Actions.
