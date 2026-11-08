## 2026-11-07 — feat(billing): 帳單清單「匯出 Excel」（in-app #520 / #5099）
<!-- release-notes: staff_update=staff-2026-11-07-billing-export-excel -->
- 帳單清單的按鈕由「匯出 CSV」改為「匯出 Excel」：伺服器用同一份 `/alerts/tuition` 資料，只匯出畫面上目前的篩選、搜尋與分頁看到的那幾列（僅檢視，不能匯入）。新增 `POST /api/v1/accounting/bills/export`（僅主任、限本校區，與清單同權限），一次最多 2000 筆，每次匯出寫一筆稽核紀錄（人、篩選、筆數，不含姓名）。檔名 `帳務-<校區代號>-<YYYYMMDD>.xlsx`。

### Local audit metadata follow-up (2026-11-08)

- Only the `billing.export` controller call normalizes its audit `filter`: preserve the nine keys in BillList.vue (`todo`, `confirm`, `owing`, `issue`, `review`, `paid`, `waived`, `all`, `overdue`); write constant `other` for any other validated string. Request validation, API responses, Excel cells, calculations, and shared SecurityAuditEvent sanitization are unchanged.
- Synthetic export regressions exercise every recognized key plus unknown strings, compare response contract and exported cells, check one audit event per request, and confirm the fixture contract remains unchanged. Separate checks preserve required/string/max-20 validation. The regression fails on the original controller because it logs the unknown string verbatim.
- Ubuntu verification uses lock-matched Composer dependencies in a temporary source snapshot, a separate network namespace, and a temporary MariaDB instance with networking disabled and a dedicated Unix socket. Tests load only the snapshot and synthetic data, without project environment files. Rollback: revert the audit-filter follow-up commit.
