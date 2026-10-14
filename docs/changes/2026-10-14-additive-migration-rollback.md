## 2026-10-14 — feat(deploy): code rollback still runs when the range only has additive migrations
<!-- release-notes: silent_ship=silent-2026-10-14-additive-migration-rollback -->
<!-- silent-reason: 只改部署失敗時的自動回滾規則，使用者畫面與資料都不變。 -->
- Deploy only, no staff-visible change. If a deploy fails and every migration in the range is a new additive-only file (judged by the classifier already in production), `deploy.yml` now rolls the code back instead of holding with exit 12/13. New tables and columns stay; `migrate:rollback` is still never run (Founder 2026-10-14 (三)).
