## 2026-10-13 — docs(governance): EVIDENCE_CONTRACT 回報者驗證逾時改成跟程式常數一致
<!-- release-notes: silent_ship=silent-2026-10-13-evidence-contract-timeouts -->
<!-- silent-reason: 只改治理文件與 CI 文件檢查，沒有任何使用者可見或執行期變更。 -->
- `docs/governance/EVIDENCE_CONTRACT.md` §Reporter-verify timeout 原本寫「7 天、手動、不排程」，已過時；改為寫明每日自動執行（`CloseStaleResolvedBugsCommand::AUTO_DAYS` 14 天、`AUTO_MAX_PER_RUN` 20 筆上限）與手動最低天數（`BugReportService::REPORTER_TIMEOUT_MIN_DAYS` 7 天）。
- 新增 `scripts/tests/test_evidence_contract_timeouts.py`，從 PHP 原始碼讀常數，文件不一致就失敗；已接進 ci.yml 既有的 Python 測試步驟。
- #3918 附件內容取回（從正式環境抓圖檔）依 Founder 決定不做（option B）。Founder 2026-10-13 核准。
