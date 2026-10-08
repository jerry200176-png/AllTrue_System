## 2026-10-08 — ci(deploy): R3 ranges always wait for the Founder reviewer
<!-- release-notes: silent_ship=silent-2026-10-08-deploy-r3-always-reviewer -->
<!-- silent-reason: 只改部署核准流程，產品畫面不變 -->
- deploy.yml no longer turns an awaiting-activation (R3) range into auto-founder-go when every PR has a `Founder GO` line: agents share the owner token, so the line is not proof of a human (security review, #3797). R0-R2 ranges still activate on their own; R3 rides the hourly train with one approval.
