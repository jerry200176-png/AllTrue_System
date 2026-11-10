# Bug Fix Plan — #539（in-app #131）

## 0) 根因（B1）
- 月結新增課程在「日期區間 + 固定星期」無交集時，後端會回傳 422（`errors.end_date`）。
- 前端 `createUniversalClassSchedule()` 目前把 validation errors 直接組成 `field: message` 格式，`UniversalClassScheduler` 再 `alert()`，因此使用者看到技術欄位名 `end_date: ...`。

## 1) 目標
- 使用者不再看到 `end_date:` 這類內部欄位名。
- 同一情境改為清楚的白話引導，告知可調整結束日或固定星期。

## 2) 範圍
- In scope:
  - `frontend/src/lib/universalSchedulerApi.js`：錯誤訊息正規化。
  - `frontend/src/components/UniversalClassScheduler.vue`：送出前 guard 文字一致化。
  - `frontend/src/lib/universalSchedulerApi.test.js`：回歸測試。
  - `frontend/package.json`：加入測試腳本並納入 build 前檢查。
- Out of scope:
  - 調整後端排課核心邏輯。
  - 更動資料庫 schema。

## 3) 驗收標準（AC）
1. 無交集情境下，UI 不再顯示 `end_date:`。
2. 顯示白話訊息：期間內無符合固定星期的排課日，請調整日期範圍或上課星期。
3. 既有成功排課流程不受影響。
4. 新增前端測試可覆蓋此錯誤映射。

## 4) 實作方向（最小修復）
- API 錯誤處理增加 field-label 映射與特例訊息轉換。
- submit guard 文字與 API 失敗提示用語一致，避免同一錯誤出現兩套描述。

## 5) 風險與回滾
- 風險低（訊息層調整 + guard 文案）。
- 回滾：revert PR 即可，無 migration。
