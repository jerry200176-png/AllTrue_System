## 2026-10-28 — feat(admin): 用編號找合約 lookup for super_admin (#3868)
<!-- release-notes: silent_ship=silent-2026-10-28-superadmin-contract-lookup -->
<!-- silent-reason: super_admin-only support tool -->
- 個人資料 → 安全性（超級管理員才看得到）新增「用編號找合約」：貼上合約編號（StudentClass.ID，一次最多 50 個），顯示分校、學生、科目、老師、期間、目前總費用、單價×堂數與備註，並可直接打開該學生的帳務。工程人員只需在 GitHub 寫編號，不必寫姓名。
- `GET /api/v1/admin/student-classes/lookup?ids=…`：super_admin + 已改密碼才可用，唯讀；每次請求寫一筆稽核（`student_class_lookup.read`，只存雜湊參照與筆數）。金額沿用既有規則（`StudentClass::currentContractCharge`、單價×堂數、`ContractMoneyVerdict`），沒有新規則。
- 順手把 `currentContractCharge`（原在 AccountingController）與單價×堂數（原在 TriageBillingSnapshotController）搬到 `StudentClass` 共用，行為不變。
