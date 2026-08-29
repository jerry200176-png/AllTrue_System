# Attendance → LearningRecord 一致性邊界

**狀態**：Implementation slice（2026-08-28）  
**範圍**：出缺勤、ClassSession、LearningRecord、主任評量營運指標

## 決策

`ClassSession` 是堂次與出勤狀態的權威來源。當堂次狀態代表學生有到課
（`attended`、`late`、`completed`、試聽／輔導／補課等 `AttendanceStatus`
中 `requires_log=true` 的狀態）時，系統必須在同一個出勤寫入流程內建立或恢復
一筆 active、`pending` 的 `LearningRecord`。此操作必須可重複執行，並受
`LearningRecord.ClassSessionID` 唯一鍵保護。

`absent`、請假家族、`cancelled`、尚未開始的 `scheduled` 不會建立評量表。
取消／請假流程仍負責作廢既有 live 評量；堂次恢復成有到課狀態時才依既有
復原政策恢復該筆資料。

## 寫入與修復

- 手動點名、RFID、待刷卡配對與主任直接調整堂次狀態共用同一個
  `LearningRecordBackfillService` 建立規則。
- 夜間 `learning-records:backfill-missing` 只掃描已過時間且屬於
  `requires_log` 的實體堂次；它是既有資料的冪等修復，不是另一套業務規則。
- 讀取端的填寫率分母同樣使用 `AttendanceStatus::requiresLogSessionStatuses()`。
  取消、請假、缺席不應因歷史殘留而增加老師待辦。

## 主任工作台契約

主任總覽首屏的「教學品質追蹤」固定顯示於總覽導覽後，清楚分開：

1. **缺評量表**：已上課但沒有 active `LearningRecord`，屬系統資料異常。
2. **待完成評量**：評量表已建立，但尚未有有效進度文字，屬老師待辦。
3. **需主任跟進老師**：以樣本數與完成率判斷，供主任安排提醒／協助，不作公開排名。

每一列同時呈現「已填／應填」與上述兩種未完成原因；空狀態、錯誤狀態與
下一步均使用白話文字。評量卡的唯一下一步是進入評量審核頁，避免主任在
日曆、課程與帳務頁之間自行猜測來源。

## 非本次範圍

本切片不改變 `ClassSession`／`schedules` 的儲存模型、不將診斷型
`Assessment` 混入課堂評量，也不以 dashboard GET 請求直接寫入歷史資料。
歷史資料修復仍由受治理的 nightly／POP 工作執行並保留 post-check 證據。
