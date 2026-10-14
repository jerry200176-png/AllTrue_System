## 2026-10-14 — fix(reconcile): 夜間堂數對帳面板可看到超過 200 筆的全部差異（#4044）
<!-- release-notes: silent_ship=silent-2026-10-14-reconcile-all-rows -->
<!-- silent-reason: 夜間堂數對帳面板只有超級管理員看得到；主任與老師的畫面不變 -->
- `reconcile:nightly` 另寫一份 `nightly-reconcile-rows-<日期>.json`，存全部差異列（只有編號、堂數與分類，沒有姓名）；摘要報告仍只放前 200 筆。
- 新增超級管理員專用 `GET /api/v1/admin/reconcile/latest/rows`（分頁，每頁最多 200 筆，姓名在讀取時才補上）。
- 面板在差異超過 200 筆時提供「載入全部 N 筆明細」，載完後篩選與排序涵蓋全部差異；舊報告沒有完整明細時會說明下一次夜間核對後才看得到。
- 只讀，不改任何堂數或資料。
