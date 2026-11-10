## 2026-11-07 — chore(ops): POP re-keys old monthly bills to their first-lesson month (in-app #514)
<!-- release-notes: staff_update=staff-2026-11-07-billing-period-rekey -->
- 新增 POP 作業 `billing-period-rekey-20261107`（Founder 核准後才執行）：全校區整張月結帳單的 `billing_period` 若不等於週期第一堂課的月份就改正；只動期別、同帳單收款申報的期別與帳單編號標記，金額與狀態不變。先 dry-run 看各校區／各月份數量。
