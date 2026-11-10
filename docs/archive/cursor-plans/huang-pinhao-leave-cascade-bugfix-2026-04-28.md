# 黃品皓 4/28 請假未順延 Bug Fix Plan

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯錯誤 / 資料一致性缺口 |
| 根因摘要 | 請假應以後端 `CourseLeaveCascadeService` 為唯一寫入路徑，但 `SmartCalendar.vue` 仍保留 API 失敗後直接寫 `schedules` 的 fallback，可能產生「有請假 exception、沒有 ClassSession 順延/補堂」的半套資料。 |
| 錯誤行為 | 大安分校黃品皓 4/28 顯示已請假，但行事曆/課表沒有一致呈現，課程管理只顯示到第 7 堂並提示「有請假堂次尚未補課」。 |
| 預期行為 | 請假成功後，該堂保留並標記 `leave`，後續堂次順延，系統自動補出第 8 堂；行事曆、課表、課程管理使用同一份 ClassSession 結果。 |
| 影響範圍 | 主任與老師查看行事曆/課表、課程管理堂次詳情、堂數制課程的請假/補課一致性。 |
| B1 偵查來源 | 本計畫整合 B1：後端 cascade 已存在；前端行事曆請假入口有 fallback 直接寫 `schedules`；歷史曾有黃品皓 `StudentClass.ID=64` 類似「請假不占額度但未補堂」資料修復。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 請假自動順延與第 N 堂補建修復 |
| 版本 / 日期 | v1.0 / 2026-04-28 |
| 狀態 | Draft |
| 嚴重度 | P1 |
| 目標角色 | 大安主任、授課老師 |
| 關聯 Bug | 大安分校黃品皓 2026-04-28 請假後未自動補第 8 堂 |

## 2. 業務背景與影響

堂數制課程買 8 堂就必須交付 8 堂有效課。請假是合法跳過當日，不應消耗購買額度，也不應讓主任手動補課。若系統只提示「尚未補課」但沒有自動順延，主任會失去對課表與堂數的信任，老師也可能漏點名或漏填評量。

修復後預期行為：任何從行事曆或課程管理建立的請假，都必須走同一個後端 transactional cascade，請假完成後立即可在課程管理看到補出的最後一堂，行事曆可看到請假堂與順延後的未來堂次。

## 3. 範圍

**In Scope**
- 修復 `SmartCalendar.vue` 請假 API 失敗後直接寫 `schedules` 的 fallback，避免新半套資料。
- 補 regression test 覆蓋請假後有效堂次數仍等於購買堂數。
- 檢查/補強後端請假 cascade 對 8 堂制課程的第 8 堂補建。
- 提供既有異常資料的安全修復方案，目標案例為黃品皓 2026-04-28。
- 確認課程管理與行事曆都讀 `ClassSession` 作為顯示依據。

**Out of Scope**
- 不改繳費/續課提醒規則。
- 不改 `SessionDeductionService` 的扣堂規則，除非 regression test 證明同步計數有錯。
- 不新增 DB 欄位或 migration，除非 DEV 偵查發現無法避免。
- 不改月結制課程請假邏輯。
- 不做 production 直接手改；資料修復須走安全腳本/PR/CI/部署流程。

## 4. RACI

| 角色 | R/A/C/I |
|---|---|
| AI Agent - Product / Bug Owner | A |
| AI Agent - DEV | R |
| AI Agent - TEST | R |
| AI Agent - REVIEW | R |
| AI Agent - DOCS | R |
| AI Agent - OPS | R |
| 使用者 | I |

## 4b. Dependencies

- 無前置 PR 依賴。
- 需使用 GitHub Actions 執行 PHPUnit；不得在 Pi production 跑測試。
- 若需要修復既有資料，必須先確認資料修復不直接碰 production DB，走 PR merge 後的安全路徑。

## 5. Acceptance Criteria

### AC-001：一般請假自動補足購買堂數
- AC-001-a：8 堂制課程建立 8 筆 `ClassSession` 後，對第 7 堂請假，系統回傳成功。
- AC-001-b：DB 中該課程保留 1 筆 `leave`，且非請假/取消的有效堂次仍為 8 筆。
- AC-001-c：最後一堂自動補到原課程固定星期/時段的下一個可用日期。

### AC-002：行事曆請假不得產生半套資料
- AC-002-a：`POST /api/v1/schedules` 成功時，行事曆重新載入後可見 `leave` 與順延結果。
- AC-002-b：`POST /api/v1/schedules` 失敗時，前端顯示錯誤並停止，不得 fallback 直接寫 `schedules`。

### AC-003：課程管理提示消失
- AC-003-a：請假 cascade 成功後，課程管理 `effectiveSessionCount(course) === purchased`。
- AC-003-b：課程管理不再顯示「有請假堂次尚未補課」。

### AC-004：既有異常資料可修復
- AC-004-a：對已存在 `leave` 但有效堂次少於購買堂數的課程，修復路徑可補足缺少堂次。
- AC-004-b：修復不得重複補堂；重跑同一修復流程後不新增第二筆相同尾堂。

## 6. 功能需求 FR

- FR-001：所有請假入口必須使用後端 API 寫入，且成功才視為請假完成。
- FR-002：前端不得在 API 失敗時直接寫 `schedules`，避免繞過 cascade。
- FR-003：後端請假 cascade 必須維持「請假不占購買額度」口徑，補足購買堂數。
- FR-004：`ClassSession` 是行事曆、課程詳情與堂數警示的顯示 single source of truth。
- FR-005：既有半套資料修復必須 idempotent，不得重複補課。

## 7. 非功能需求 NFR

此 bug 主要是邏輯與資料一致性，不是效能型 bug。仍需維持既有查詢上限：課程管理與行事曆不得移除 `start/end/per_page` 範圍限制。

## 8. 技術方向

- 後端：檢查 `ScheduleController::store`、`ScheduleController::leaveBySession`、`CourseLeaveCascadeService`、`StudentClassController::extendSessionsIfNeeded`。
- 前端：修正 `SmartCalendar.vue` 請假失敗處理；確認 `CourseManagement.vue` 已正確用 API 回傳的 `class_sessions` 更新本地狀態。
- 測試：優先補 `ScheduleLeaveCascadeTest` 或新增專門 regression test，覆蓋 8 堂、4/28、請假後補第 8 堂。
- 資料修復：若現有黃品皓資料已是半套狀態，使用後端既有 cascade/補洞邏輯修復，禁止直接手改 production 表。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-28 | 保留「請假自動順延並補足最後一堂」產品規則 | 只顯示提醒讓主任手動補課 | 堂數制買幾堂交付幾堂，手動補課會增加行政負擔且易漏。 |
| 2026-04-28 | 移除前端直接寫 `schedules` fallback | API 失敗時仍寫 exception row | fallback 會繞過後端 transaction，是半套資料的主要風險。 |
| 2026-04-28 | 以 `ClassSession` 作為顯示權威 | 行事曆合併 `schedules` 作為權威 | 點名、評量、課程詳情都依賴 `ClassSession`，統一來源較不易漂移。 |

## 9. 資安與存取控制

本修復涉及學生姓名、分校課表與請假紀錄，屬 PII/角色邊界相關。

**SEC 審查重點**
- S：沿用 Bearer token，不接受未登入請假。
- T：請假 API 必須 validate `student_course_id`、`branch_id`、日期與角色分校範圍。
- R：請假/資料修復應保留 `schedules` 與 `ClassSession.Note` 稽核訊號。
- I：錯誤訊息不得洩漏其他分校學生資料。
- D：維持批次/分頁上限，不新增無上限查詢。
- E：老師/主任不可越分校修改其他分校課程。

## 10. QA 驗收

**Happy Path**
- 8 堂制課程請假第 7 堂，自動補第 8 堂。
- 從行事曆請假後，課程管理立即顯示補出的尾堂。

**Edge**
- 請假日期已有 `leave`，重送不得重複補堂。
- 請假堂已有核准評量時，應拒絕一般請假，提示使用補請假流程。
- 多星期/多時段課程順延時，補堂須符合原課程時段。

**Error**
- API 失敗時，前端顯示錯誤，不寫入 `schedules` fallback。

**Revert-proof 驗證**
- `git stash` 或 revert 修復後，新增 regression test 至少 1 case failure，確認測試真的覆蓋 bug。

## 11. 上線與維運

- 無預期 migration。
- 前端有變更，必須走 feature branch → PR → CI green → merge → `deploy.yml` 自動部署。
- 後端測試只在 GitHub Actions 跑。
- Deploy 後驗證 `/api/v1/health`。
- 若有資料修復，先以只讀查詢確認受影響 course，再用 idempotent 修復路徑處理；禁止在 Pi 手動 tinker 直接改 production。
- Rollback：無 migration 時可 revert PR；若資料修復已補堂，rollback code 不應刪除已補合法堂次，需另開資料審核。

## 12. 優先級

| 項目 | 優先級 | 執行 Agent |
|---|---|---|
| Regression test：請假後有效堂次仍等於購買堂數 | P1 | [TEST] |
| 移除行事曆 fallback 直接寫 `schedules` | P1 | [DEV] |
| 既有黃品皓資料修復路徑 | P1 | [DEV]/[OPS] |
| CHANGELOG / AI regression lesson | P2 | [DOCS] |
| Review 分校隔離與資料一致性 | P1 | [REVIEW] |

## 13. 風險 / 假設 / 開放問題

**業界參考（WebSearch 2026-04-28）**
- Class scheduling 系統通常要求 real-time calendar sync、conflict detection、single source of truth，避免人工 reconciliation。
- Cancellation/reschedule automation 的常見做法是立即同步行事曆、通知相關人員，並提供清楚規則；不應讓失敗寫入隱性半套資料。
- 大型系統會保留人工 override，但會透過審核/稽核路徑，而不是前端 fallback 隱式寫資料。

**風險**
- 若既有資料已多次手動補課，盲目重跑 cascade 可能超排；修復前需用 effective count 檢查。
- 若 API fallback 曾被用來繞過其他資料問題，移除 fallback 後會暴露原本被吞掉的錯誤；這是正確行為，但需讓錯誤訊息可理解。

**假設**
- 黃品皓 4/28 是堂數制課程，購買堂數為 8。
- 該堂沒有已核准評量；若已有核准評量，應走補請假/沖回流程。

**開放問題**
- [AI-RESOLVABLE] DEV 階段需用只讀查詢確認黃品皓當前 `StudentClass.ID`、`ClassSession` 與 `schedules` 狀態。

## 14. Definition of Done

- [ ] FR-001/FR-003：驗證方式：GitHub Actions PHPUnit 中新增請假 cascade regression test，預期有效堂次數等於購買堂數。
- [ ] FR-002：驗證方式：前端 diff 顯示 `SmartCalendar.vue` 不再 fallback 直接寫 `schedules`；API 失敗路徑只顯示錯誤。
- [ ] FR-004：驗證方式：`CourseManagement.vue` 與 `SmartCalendar.vue` 的顯示仍透過 `fetchClassSessions` 取得 `ClassSession`。
- [ ] FR-005：驗證方式：idempotent 修復測試或腳本 dry-run 輸出顯示重跑不新增重複尾堂。
- [ ] Revert-proof：驗證方式：revert 修復後，新增 regression test 至少 1 case failure。
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含 2026-04-28 fix 條目。
- [ ] Health check：驗證方式：deploy 後 `curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 HTTP 200 且 `status=ok`。
