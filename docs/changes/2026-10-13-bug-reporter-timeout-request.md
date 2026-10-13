## 2026-10-13 — ops: 14 天逾時結案可由 request file 觸發（雲端可用）
<!-- release-notes: silent_ship=silent-2026-10-13-bug-reporter-timeout-request -->
<!-- silent-reason: 只新增維運觸發路徑，結案規則與回報者看到的回覆都沒變。 -->
- `bug-reporter-timeout.yml` 新增 push 觸發：merge `operations/closeout/bug-reporter-timeout.request.yml`（每個 `request_id` 只跑一次）可跑 `auto-dry-run`，或 `reviewed-apply` 只關審過且仍符合資格的 ID（`--auto --reviewed-batch`，可超過每輪 20 筆上限；不符資格者略過並列出）。無人值守的 `auto` 仍維持上限。
