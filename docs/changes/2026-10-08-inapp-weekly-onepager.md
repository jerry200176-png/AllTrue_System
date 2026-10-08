## 2026-10-08 — feat(ops): 每週一 09:00 自動開／更新「in-app 週報 YYYY-Www」GitHub issue（Founder 2A）
<!-- release-notes: silent_ship=silent-2026-10-08-inapp-weekly-onepager -->
<!-- silent-reason: 只新增給創辦人看的內部週報 issue（只列回報編號、不含人名），教職員看到的畫面與版本更新內容完全不變。 -->
- `bug-sla-weekly-report.yml` 的正式站唯讀查詢多輸出本週新進／已修好／已結案／重開、各年齡層還開著、超過處理時限的「編號清單」；新增 `issue` job 用 `scripts/inapp-weekly-onepager.py` 以白話中文開或更新當週 issue（同週重跑只更新，不重開）。
- 三大問題類別取自對應 GitHub issue 的 `area:*` 標籤；公開 repo，內容只有編號與標籤名稱。
