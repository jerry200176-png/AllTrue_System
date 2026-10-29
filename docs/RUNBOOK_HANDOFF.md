# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-10-29
Source SHA: a5dfc4741 (origin/main at write time)
Owner: coordinator（每個 milestone、PR merge、deploy 後更新；完成的項目直接刪除）

**規則**：本檔超過 7 天 → 視為 STALE，先對 GitHub（issue / PR / `gh run list`）驗證再用；新 SHA 勝舊 SHA；
與記憶（MemPalace、個人 auto-memory）衝突時，git / GitHub 為準。只放指標，不複製內文。

## 進行中（live state）
| 項目 | 狀態 | 去哪看 |
|---|---|---|
| #4455 package ledger | round 1 repair 已跑（52 rows）；round 2（over-debit repair）待 dry-run → Founder approve | issue #4455；`docs/changes/2026-10-29-package-ledger-overdebit-repair-4455.md`；`docs/incidents/RM-PACKAGE-LEDGER-NET-REPAIR-4455.md` |
| #3868 合約 Charge 更正 | 2569：8000→4000；3288：8800→1100（Rate × 堂數）；POP op 待 dry-run → Founder approve；之後 #4582 才可 merge | issue #3868；`docs/changes/2026-10-29-pop-contract-charge-correction-3868.md`；`docs/incidents/RM-CONTRACT-CHARGE-CORRECTION-3868.md` |
| #4623 deploy newest green main | Founder 已批准、已 queue，待 merge | PR #4623 |

## Deploy / POP 常見坑
- **Release train 停手**：有舊 deploy run 卡在「waiting for approval」→ train 回報「another deploy run is already waiting」。先 force-cancel 舊 run，再重新 dispatch train。`.github/workflows/deploy.yml`
- **POP in-app 每日上限**：每個 operation 每 Asia/Taipei 日 20 份 report（以 report 計，不是 request）；超過時 dry-run 會過但不會執行，00:00 Taipei 後用新的 request_id 後綴重送。`docs/pop/adr/ADR-POP-015-standing-policy-inapp-lifecycle.md`
- **issueOwnsReport / SourceRef**：`github_issue_url` 必須是 open 且非 duplicate 的 issue，且 owner 或 github-actions[bot] 的內文／留言含 `SourceRef … alltrue:bug_report:<id>`；auto-intake 鏡像被關成 duplicate 會失敗 → 改用 canonical issue。`backend/app/Operations/Inapp/InappReportResolveEvidence.php`
- **fix 要連回 issue**：fix PR 標題或內文需有 `#<issue>` 或 `in-app #<bug>`（可編輯已 merge 的 PR body 補上）。
- **smoke-after-deploy**：smoke run 必須在 deploy job 的 `completed_at` 之後才算數。
- 完整流程：`docs/changes/2026-10-22-pop-inapp-resolve-after-deploy.md`、`docs/sop/BUG_INTAKE_TO_PRODUCTION.md`、`docs/CHAT_BUG_SYSTEM.md`。

## 找東西
問題 → 文件的對照在 `docs/INDEX.md` 的「Takeover recall」。
