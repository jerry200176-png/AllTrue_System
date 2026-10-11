## 2026-10-11 — chore(ops): 新增唯讀診斷 occurrence_identity_shadow (排課身分 P2)
<!-- release-notes: silent_ship=silent-2026-10-11-occurrence-identity-shadow-probe -->
<!-- silent-reason: 只新增內部唯讀診斷選項，不改任何排課行為或資料，教職員看不到差異 -->
- `production-case-dump` 多一個 `occurrence_identity_shadow`：每校只讀回已蓋章、P3 可無歧義補章、有歧義、#1995 碰撞組的數量（只有 ID 與計數）。
