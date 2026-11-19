## 2026-11-18 — feat(billing): 更正帳單對大安、新店、木柵主任開放
<!-- release-notes: staff_update=staff-2026-11-18-bill-correction -->

- `billing.bill_correction_campus_ids` 設為大安、新店、木柵（Founder 2026-11-18 「都先照妳推薦」）。主任可在學生帳務的帳單列按「更正帳單」；原因必填，總部看得到紀錄。
- 帳務列表只在該分校才回 `can_correct`；列表上「例外作廢」的收據檢查改用帳單備註的暗示（不多查資料庫），真正的檢查仍在作廢時。
- 回復：把分校清單改回 `[]`。

Release-Impact: user-visible
