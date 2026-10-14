## 2026-10-14 — feat(governance): billing/login fixes with focused tests and Codex review deploy as T2
<!-- release-notes: silent_ship=silent-2026-10-14-billing-login-standing-t2 -->
<!-- silent-reason: 只改部署分級規則（哪些帳務／登入修正要 Founder 批准上線），使用者畫面與資料都不變。 -->
- Governance only, no staff-visible change. Billing/login code that stays inside the narrow standing exception, names each changed class in a changed backend test, and has a Codex review on its final head with every thread resolved now classifies as T2 and deploys after green CI. Anything else (migrations, identity/permission/token boundaries, data or privacy effects, CI workflows, governance docs) still needs Founder approval (Founder 2026-10-14 (三), #3961).
