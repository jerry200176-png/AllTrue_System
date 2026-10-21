## 2026-10-21 — feat(billing): 啟用約定費用更正（僅限白名單課程）
<!-- release-notes: staff_update=staff-2026-10-21-agreed-fee-correction-activate -->

- Founder 2026-10-21 選定新莊帳務修正走 A 路線：`billing.agreed_fee_correction_enabled` 改為程式內 `true`（仍不讀環境變數）。
- API 只對 `agreed-fee-allowlist-activation.yml` 設定的校區／課程白名單開放；白名單為空時一律 404。部署順序：#4283 schema 上線 → 設定白名單 → 本 PR 上線（白名單流程在開關為 true 時拒絕寫入）。
- 回滾：改回 `false`，或以 `clear_allowlist` 清空白名單。不修改既有帳務資料；每筆更正仍需主任／超級管理員預覽後確認。
