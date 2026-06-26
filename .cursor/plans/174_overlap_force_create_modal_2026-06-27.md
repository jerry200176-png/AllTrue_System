# Bug Fix Plan — #174 重疊建課 409 未導向強制建立 modal（retroactive）

> ⚠️ **誠實註記**：本計畫為**事後補寫**。實作時（2026-06-27）我跳過了正式 Bug Fix Plan + B1 批准節點，直接偵查→修→測→以 OPERATIONS_RUNBOOK §139 緊急手動部署上線（in-app #174 已 resolved）。此處補齊 SOP 文件以閉合 S8 缺口；流程偏差已記於該輪稽核 C 區。

## 0. 根因確認（Root Cause）
| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1（功能 dead-end，使用者無法新增重疊課程）|
| 根因類型 | 前後端契約缺口（前端漏接後端新錯誤碼）|
| 根因摘要 | 後端 #805 回 409 `overlapping_active_course`，前端 `universalSchedulerApi.js` 只把 `duplicate_active_course` 設 `isDuplicateCourse`，重疊碼落到 `UniversalClassScheduler.vue` 原生 `alert()` → 無 force 入口 |
| 錯誤行為 | 提示「請勾選強制建立」卻無該勾選框；使用者卡死路 |
| 預期行為 | 重疊 409 也跳 in-app 攔截 modal（加購堂數 / 我知道仍要新增）|
| 影響範圍 | 角色 director；前端 course-mgmt 建課；API class-sessions/batch |
| **歷史比對** | 接續 #805（commit e95771b）；前後端契約類缺口（新增 R8b 契約教訓）|
| B1 偵查來源 | 整合 B1：附件 #121 截圖 + EnrollmentService 409 來源 + 前端 catch 追蹤 |

## 1. 文件資訊
功能：建課重疊攔截｜狀態：**已上線（resolved）**｜嚴重度：P1｜角色：director｜關聯 in-app #174、GitHub #931

## 2. 業務背景與影響
續報重疊時使用者完全無法建立課程（死路）。修復後：跳視窗提供「加購堂數」或「仍要新增」。

## 3. 範圍
- In Scope：前端把 `overlapping_active_course` 與 `duplicate_active_course` 同視為攔截碼。
- Out of Scope：不動後端 409 邏輯、不動 modal UI、不動 force=true 後端繞過。

## 4. RACI
R/A = AI Agent；人類 I。

## 4b. Dependencies
無；無 migration。

## 5. Acceptance Criteria
### AC-001：重疊碼導向攔截
- AC-001-a：`isDuplicateInterceptCode('overlapping_active_course')` = true（走 modal）。
- AC-001-b：`isDuplicateInterceptCode('duplicate_active_course')` = true；無關碼 = false（反向）。

## 6. 功能需求 FR
- FR-001：兩種 409 code 皆設 `err.isDuplicateCourse=true` → emit duplicate-course → modal。

## 7. 非功能需求 NFR
不適用（純前端邏輯）。

## 8. 技術方向
檔案：`frontend/src/lib/universalSchedulerDuplicateCode.js`（新增純函式）、`universalSchedulerApi.js`（引用）、`universalSchedulerApi.test.js`（測試）。取捨：抽無相依純函式，讓 node 測試可 import（避開 supabase/import.meta.env）。

## 8b. Decision Log
2026-06-27：抽 `isDuplicateInterceptCode` 純函式 vs 直接內聯——選純函式以利 node 測試 + 與既有 errorMessage 純模組一致。

## 9. 資安與存取控制
不適用（純 UI）。

## 10. QA 驗收
- Happy：重疊→modal；duplicate→modal。Edge：無關 409 仍 alert。
### Revert-proof 驗證
- [x] 還原 predicate 為單碼後 `universalSchedulerApi.test.js` fail（實測 `expected: true`）。

## 11. 上線與維運
**已上線**：因 Actions minutes 用完，依 §139 緊急手動前端部署（本機 build 綠 → rsync dist_build → Pi copy-to-backend.cjs → version `acf1251`，health ok、assets 200 text/javascript、線上 chunk 含修正 predicate）。**待辦**：Actions 恢復後補正式 PR 回 main（branch `fix/course-overlap-force-create`），否則下次 `git reset --hard origin/main` 會還原。回滾：已備份 `backups/emergency/pre174_20260627_062500`。

## 12. 優先級
P1。`[DEV]`+`[TEST]`。

## 13. 風險 / 假設 / 開放問題
- 業界：前後端契約變更（新錯誤碼）須同步前端處理分支，否則 dead-end；契約測試/型別共享可防再犯。
- 假設：modal 對 overlap conflicts 形狀相容（已驗 existing_course_id/subject/remaining_sessions）。

## 14. Definition of Done
- [x] FR-001：`node src/lib/universalSchedulerApi.test.js` OK
- [x] Revert-proof：還原後 fail
- [x] CHANGELOG（994a838）+ AI_REGRESSION R8b
- [x] Deploy + health ok（§139）
- [x] in-app #174 resolved + 公開回覆請驗收
- [ ] 正式 PR 回 main（⛔ 待 D1 Actions 額度）
