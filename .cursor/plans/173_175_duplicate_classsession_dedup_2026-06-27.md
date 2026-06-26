# Bug Fix Plan — #173/#175 續報重疊產生重複 ClassSession（F1/F3）

> ⚠️ 本計畫待 CEO **B1 批准**後才實作（`bug-fix-plan.mdc` §0 / CHAT_BUG_SYSTEM §3.7 B1）。屬高風險（全校排課/扣堂/薪資）。

## 0. 根因確認（Root Cause）
| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯錯誤（堂次生成未跨課程去重）+ 狀態收尾缺口 |
| 根因摘要 | 舊課結束(EndDate)後殘留未來堂次（F1）；續報新課 + 堂次補登各自為同一實體時段生成 ClassSession，無「同 student+date+time 跨課程」去重（F3）|
| 錯誤行為 | 同一堂實體課出現兩筆 ClassSession（#173：王光熙 6/10 19:00 = #11292 舊課114 / #16951 新課2076），各補一筆 LearningRecord → 評量重複/「未填」、堂數/薪資雙計（#175：陳品承 6/13 17:00 同型）|
| 預期行為 | 同 student+date+time 在系統中僅一筆有效 ClassSession；課程結案後不殘留 EndDate 之後 scheduled 堂次 |
| 影響範圍 | 角色 director/teacher/parent；資料 ClassSession/LearningRecord/堂數/薪資；API 建課、ensure-past、行事曆 |
| **歷史比對** | F1（#151/#427/#99/§R32/§R59）+ F3（#148/#497/#344/§R22/§R23）；#805 重疊建課 guard(commit e95771b) 只在建課時 warn 且可 force；#170/#168/#920 同型；in-app #173/#175 |
| B1 偵查來源 | 本計畫整合 B1：唯讀 SQL（ClassSession #11292/#16951、StudentClass 114/2076 EndDate）+ ensurePastRecords 程式追蹤 |

## 1. 文件資訊
功能：排課堂次生成/結案收尾｜狀態：待批准｜嚴重度：P1｜角色：director/teacher/parent｜關聯 in-app #173/#175、GitHub #932/#933、家族 F1/F3、#805/#920

## 2. 業務背景與影響
續報（舊課未結束就建新課、日期重疊）造成同一堂課被算兩次：老師端/行政端堂數對不齊、評量重複或顯示未填、科目數與薪資口徑不一致。修復後：同一實體時段只有一筆堂次，三端一致。

## 3. 範圍
- In Scope：(a) 堂次生成（建課/補登/ensure-past）對「同 student+同日+同時段」跨課程去重；(b) 課程結案/過 EndDate 時清理殘留未來 scheduled 堂次。
- Out of Scope：不動收費金額/invoice、不動 #805 建課 guard 既有 warn、不動 calendarOccurrenceMerge 前端合併（F5 另案）、不動既有已上且已填評量之內容。

## 4. RACI
R/A = AI Agent；人類 I（CEO B1 批准 + 資料清理核准）。

## 4b. Dependencies
無 migration（除非加唯一性約束，見 §8）；資料清理腳本為獨立後置步驟（人工備份後執行）。

## 5. Acceptance Criteria
### AC-001：跨課程同時段不重複生成
- AC-001-a：學生已有 6/10 19:00 有效 ClassSession，於另一（重疊）課程生成同日同時段堂次時，系統不建立第二筆（reuse 或拒絕）。
- AC-001-b：不同日/不同時段仍正常生成（反向驗證）。
### AC-002：結案收尾
- AC-002-a：課程 settled / 過 EndDate 後，EndDate 之後的 scheduled 堂次不再出現於行事曆/補登（已上 attended 堂次保留）。

## 6. 功能需求 FR
- FR-001：堂次生成層加入「同 StudentID + SessionDate + StartTime 已存在有效 ClassSession」檢查，命中則不重複建立。
- FR-002：結案/EndDate 收尾清理 EndDate 之後 scheduled 堂次（保留 attended/已填）。
- FR-003：ensure-past 對重複 LR 之放大不再發生（依 FR-001 後，同時段僅一筆 session → 一筆 LR）。

## 7. 非功能需求 NFR
不適用（純邏輯/資料一致性）；惟去重查詢須有 (StudentID,SessionDate,StartTime) 索引支援，避免新 N+1。

## 8. 技術方向
檔案/方法：`EnrollmentService`（建課堂次生成）、`LearningRecordController::ensurePastRecords`（補登）、堂次生成共用點（如 session 生成 service）、結案流程（StudentClass 結案/settle）。取捨：去重以「DB 唯一性約束 + 條件式 insert（業界 idempotent MERGE/SETNX 模式）」為強保證，或先以應用層檢查（風險低、可漸進）。Decision 見 8b。

## 8b. Decision Log
2026-06-27：傾向「應用層去重（FR-001）先行 + 後續評估 (StudentID,SessionDate,StartTime) 唯一索引」——理由：直接加唯一約束需先清理歷史重複（業界共識：既有非 idempotent pipeline 導入 idempotency 必先 audit + 去重歷史資料），風險高；應用層檢查可立即止血並以回歸測試守護。

## 9. 資安與存取控制
不適用（不涉 auth/PII；branch 範圍檢查不變）。

## 10. QA 驗收
- Happy：重疊續報不生重複堂次；正常排課不受影響。
- Edge：例外堂/補課（IsContractException）、代課 reschedule、跨月。
- Error：並發建課（race）以 DB 約束或交易保護。
### Revert-proof 驗證
- [ ] 新增測試重現「舊課結束+續報重疊→6/10 兩筆 session」，套用 FR-001 後僅一筆；git stash 修復後該測試 fail。

## 11. 上線與維運
PR → CI(self-hosted) → merge → deploy.yml。**阻塞**：Actions hosted minutes（D1）+ B1 批准（D3）。資料清理：獨立腳本，主任備份後執行（草案見 #932 留言）。回滾：應用層去重可 `git revert`；資料清理不可逆故先備份。

## 12. 優先級
P1。`[DEV]`+`[TEST]`+（資料清理）`[OPS]`。

## 13. 風險 / 假設 / 開放問題
- 業界做法（WebSearch 2026-06）：防重複＝idempotency 設計（MERGE/條件式 INSERT、唯一鍵、SETNX/DB 約束防 race），且「為既有非 idempotent 流程導入時，須先 audit 既有重複並去重歷史資料」。
- 假設：同 student+date+time 至多一堂為業務真相（需 CEO 確認是否存在合法同時段雙課）。
- 開放問題：歷史既有重複（如 #11292/#16951）保留哪一筆＝業務判斷（草案：保留歸屬進行中課程者）。

## 14. Definition of Done
- [ ] FR-001/002/003：對應測試綠（命令：`vendor/bin/phpunit --filter <NewDedupTest>`）
- [ ] Revert-proof：git stash 後新測試 fail
- [ ] CHANGELOG + AI_REGRESSION（F1/F3 不變式 + 本案）
- [ ] Health（部署後）ok（⛔ 待 D1）
- [ ] in-app #173/#175 resolved + 公開回覆（⛔ 待部署 + 資料清理）
