## 2026-10-12 — docs: 結案關卡指南改用現行唯讀驗證與發布路徑
<!-- release-notes: silent_ship=silent-2026-10-12-closure-gate-doc-align -->
<!-- silent-reason: 只改內部文件，沒有使用者可見變更。 -->
- `docs/GUIDE_BUG_CLOSURE_GATE.md` Gate 3 改用公開 HTTPS `version.json`／`deployment.json`／health（移除直接 SSH 與 `curl -k`），業務抽樣改走唯讀 `production-case-dump`；Gate 6 回滾改指向現行 workflow／POP 流程，與 `alltrue-release` SKILL、AGENTS.md 一致。Refs #3763
