## 2026-11-19 — feat(scheduling): 試聽暫留的後端（#5499 第一步，in-app #575）

- 新表 `trial_holds`（additive，只新增），新 API 三支：`GET/POST /api/v1/trial-holds`、`POST /api/v1/trial-holds/{id}/cancel`。只開給主任和 super_admin，而且只能操作自己授權的分校，老師必須屬於該分校。
- 暫留不建學生、課程、點名、扣堂或帳單。它在衝堂檢查裡算成「一個試聽席位」，所以沿用現有規則：同時段只能有一個試聽，一對一的課不能疊上去，一對三時段占一個位子。
- 會算進暫留的地方：依日期的衝堂檢查（`buildTeacherDateOccupancyEntries`）、改課程與轉課的循環檢查（`collectConcreteRecurringOverlaps`）、找空堂和跨分校忙碌（`SubstituteService`）。
- 取消暫留只改狀態、保留紀錄，時段立刻空出來。
- 這個 PR 沒有畫面；行事曆顯示與「轉成正式試聽」在下一步。表裡沒有資料時，現有排課行為完全不變。回復方式：revert 這個 PR（表留著不影響）。

Release-Impact: internal
