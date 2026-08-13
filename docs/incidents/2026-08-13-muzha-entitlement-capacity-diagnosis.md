# 木柵額度轉移容量診斷（唯讀）

狀態：執行工具待合併後由 GitHub Actions 產生 production evidence artifact。

本診斷只涵蓋學生 `374`、`373`，來源批次 `1681`、`1682`，候選目標批次
`2649`、`2606`，以及待轉移堂次 `23157`、`27156`。工具只執行 `SELECT`，
不提供任意 ID 輸入，也不執行額度轉移或其他 production mutation。

產出的 90 天 artifact 必須用來回答：

1. 目標批次的八筆容量承諾是否符合實際排課。
2. 來源與目標 `SubjectID` 差異屬於資料錯誤、合法課程替換或 UI 顯示問題。
3. 是否存在尚未反映於 `UsedSessions`／`RemainingSessions` 的 scheduled 預約。
4. 是否存在同學生、同來源科目且有容量的合法替代批次；只提出 mapping，不自行選擇。

結果未確認前，不得重新執行 production entitlement transfer apply。
