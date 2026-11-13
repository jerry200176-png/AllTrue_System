## 2026-11-13 — fix(scheduling): 「合約期間外」標籤 + 觀察用盤點 (in-app #536, #5198)
<!-- release-notes: staff_update=staff-2026-11-13-lesson-outside-contract-period-label -->
- 把已上的課改派到開始日較晚的合約時，系統照舊允許，也不會移動合約開始日（Founder 決定 #5198 選項 A）。課程管理的日期晶片現在會在這種堂次旁顯示「合約期間外」，滑過去說明「這堂課的日期早於合約開始日，已歸到這份合約」。
- 這是讀取時推導的顯示，不新增欄位、不寫資料、不影響堂數與扣堂；`IsContractException`（不在每週固定時段）的意思不變。
- 後端新增唯讀盤點：夜間 `bugs:verify-reproductions` 多一個只觀察的條件 `lesson_before_contract_start`（計數），並可用 `sessions:census-before-contract-start` 依分校列出堂次編號。補標既有資料不需要，因為標籤是推導的。
