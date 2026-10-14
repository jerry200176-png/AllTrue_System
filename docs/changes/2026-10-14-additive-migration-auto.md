## 2026-10-14 — feat(governance): additive-only migrations deploy as T2 (#4081)
<!-- release-notes: silent_ship=silent-2026-10-14-additive-migration-auto -->
<!-- silent-reason: 只改部署分級規則（哪些 migration 要 Founder 批准），使用者畫面與資料都不變。 -->
- Governance only, no staff-visible change. A PR whose migrations are all new files that only add tables, nullable/defaulted columns or plain indexes now classifies as T2 and deploys after green CI. Drop/rename/change/key/data-writing migrations still need Founder approval (Founder 2026-10-14 (三)④).
