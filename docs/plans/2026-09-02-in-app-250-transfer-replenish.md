# Bug Fix Plan — in-app #250 transferred sessions leave the new contract short

status: B1 confirmed — implementation in progress  
last_reviewed: 2026-09-02  
GitHub tracking issue: pending Phase A  
In-app report: #250; campus #9; page `course-mgmt`; severity medium.

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | 業務流程邏輯缺口 |
| 根因摘要 | `transferSessions` 只轉移已物化的已上課堂次與其扣堂資料，沒有在來源合約仍保有原 `SessionCount` 時補回被移走的排程堂次。 |
| 錯誤行為 | 新合約原有的 7/18、7/25 堂次被轉回舊合約後，原合約仍是 8 堂額度，但新合約沒有補足兩個未來排程。 |
| 預期行為 | 轉移成功後，來源合約的固定排程應以未被移轉的現有堂次為基礎，只在尾端補足至原合約承諾堂數；不可在已被舊合約使用的歷史日期重建重複堂次。 |
| 影響範圍 | 堂數制、非手動 occurrence、可移轉已上課堂次的合約轉移 API；不改已上課紀錄的所有權規則、金額或付款狀態。 |
| **歷史比對** | 命中 #1901/#1902 的合約堂次移轉流程、#2140 的堂次補建脈絡，以及 F1/F4／§R21、§R26、§R121、§R127；本案是既有轉移後補排不變式的缺口，不是單純 UI 顯示錯誤。 |
| **根因層級** | 架構／流程設計缺口；5 Whys 結論：轉移 API 被設計成只搬紀錄的 interim workaround，沒有同時維護「額度承諾 ↔ 排程物化」不變式，因此轉出堂次後來源合約的 schedule commitment 會縮短。 |
| **大廠參考** | Google Calendar recurring instances 使用 `recurringEventId + originalStartTime` 維持 occurrence 身分，即使 instance 被移動仍可精確追蹤；本修復採較小範圍的等價不變式：轉移不重建已移出的原日期，而只向排程尾端補足。參考：[Google Calendar recurring events](https://developers.google.com/workspace/calendar/api/guides/recurringevents)、[Google Calendar event instances](https://developers.google.com/workspace/calendar/api/concepts/events-calendars)、[Cal.com recurring double-booking issue](https://github.com/calcom/cal.diy/issues/22801)。 |
| B1 偵查來源 | Production read-only Attendance Case Diagnose runs `33617604992`, `33617718275`, `33617784570`; Student Session Diagnose runs `33617390106`, `33617473799`; current code and `StudentClassTransferSessionsTest`. |

## 1. 文件資訊

- 功能名稱：轉移已上課堂次後維持來源合約排程承諾
- 版本：2026-09-02
- 狀態：implementation in progress
- 目標角色：主任／具合約權限的操作人員
- 關聯 Bug：in-app #250；#1901/#1902、#2140、§R127

## 2. 業務背景與影響

合約堂數代表仍應可使用的排課額度。已上課堂次轉到前一份合約後，來源合約仍保有原購買堂數；若不補回排程，課程管理會只出現部分堂次，主任無法依新合約完成後續排課。

修復後，轉移仍保留既有已上課、評量、點名與扣堂台帳的一致性，並把缺少的堂次排在固定排課序列尾端，不製造與舊合約同日同時段的歷史重複。

## 3. 範圍

### In Scope

- 在既有 `transfer-sessions` 成功交易後，對來源的堂數制、自動週期合約計算尾端缺口。
- 回傳補建堂次數，供操作人員知道轉移是否同時補回排程。
- 後端回歸測試，涵蓋已上課轉出、補足、重複歷史日期排除與手動 occurrence 不補建。

### Out of Scope

- 不改 production 現有 #250 的課程、堂次、評量、點名或帳務資料。
- 不修改 `SessionCount`、`Charge`、付款、發票或扣堂台帳的權威規則。
- 不放寬跨學生、跨科目、跨分校、權限或目標時段衝突檢查。
- 不處理共用方案、月結課程、自動資料修復或既有歷史孤兒堂次。
- 不改前端以外的課程管理顯示契約；前端只呈現既有 API 回覆。

## 4. RACI

| 角色 | 責任 |
|---|---|
| R | AI Agent：程式修復、測試、CI、PR、部署驗證 |
| A | AI Agent：本次 bounded change 的技術決策與證據鏈 |
| C | 無；若遇產品契約或帳務規則變更則停止並請 Founder 決策 |
| I | Founder／產品負責人：收到 PR、部署與阻塞報告 |

## 4b. Dependencies

- 前置：既有轉移／恢復端點、`StudentClassTransferSessionsTest`、`extendSessionsIfNeeded`；無 migration。
- 不依賴任何付款、帳務或 production data repair permission。
- #249 的 Founder billing mapping 與 #247 的缺失原始 payload 不影響本 bounded fix。

## 5. Acceptance Criteria

### AC-001：已上課堂次轉出後補足來源合約

- AC-001-a：來源合約原承諾 8 堂、轉出 2 堂後，API 保留 8 堂欄位並在排程尾端補建 2 堂。
- AC-001-b：轉移後來源合約的已上課紀錄、評量、點名與扣堂台帳仍屬同一來源合約的現有紀錄集合，不產生第二筆已上課紀錄。

### AC-002：不重建被轉出的歷史日期

- AC-002-a：被轉出的日期即使位於來源固定排課序列，補排也不得在該日期／時段新增來源堂次。
- AC-002-b：補排從來源現有最後排程之後開始，並使用原固定星期／時間。

### AC-003：安全邊界

- AC-003-a：手動 occurrence、停用來源或非堂數制來源完成轉移後，不自動補建堂次。
- AC-003-b：轉移驗證失敗或目標時段衝突時，來源、目標與所有關聯紀錄維持原狀。

## 6. 功能需求 FR

- FR-001：轉移成功後，來源堂數制自動週期合約必須以原 `SessionCount` 為排程目標。
- FR-002：補排只能從來源現有堂次的尾端向後建立，並尊重固定排課 weekday／time 與取消／請假占位規則。
- FR-003：補排不得建立已被轉移堂次相同日期／開始時間的來源堂次。
- FR-004：轉移 response 必須回傳補排摘要；沒有補排時回傳零或明確的跳過原因。
- FR-005：原有轉移的授權、學生／科目一致性、狀態、唯一時段與台帳同步規則保持不變。

## 7. 非功能需求

不適用於效能最佳化；本次為單筆合約轉移的交易一致性修復。補排使用既有物化服務，不能引入跨合約全量掃描或未受控 production write。

## 8. 技術方向

- `StudentClassController::transferSessions`：在既有關聯紀錄與台帳轉移完成後呼叫受限的來源尾端補排流程，並加入 response 摘要。
- `StudentClassTransferSessionsTest`：加入 #250 形狀的 8 堂／轉出 2 堂回歸案例與安全邊界案例。
- `docs/AI_REGRESSION_LESSONS.md`：新增本次轉移後 schedule commitment 不變式。
- 取捨：沿用現有週期展開與 `ClassSessionMaterializationService`，避免另造第二套日期計算；刻意不使用一般缺口填補，以免重建已轉出的歷史日期。

## 8b. Decision Log

| 日期 | 替代方案 | 選擇理由 |
|---|---|---|
| 2026-09-02 | 只補前端「未排滿」提示 | 拒絕：只遮蔽資料／排程缺口，無法讓主任完成後續排課。 |
| 2026-09-02 | 呼叫一般 `extendSessionsIfNeeded` 填歷史缺口 | 拒絕：會把已轉出的日期視為來源缺口，可能與舊合約產生跨合約重複。 |
| 2026-09-02 | 轉移後只向尾端補足 | 採用：維持原合約額度，避免回填已被另一合約承接的日期。 |

## 9. 資安與存取控制

本次不更改權限邊界。既有來源／目標合約授權、同學生／同科目限制、唯一時段檢查與交易 rollback 必須維持；補排只使用已通過授權的來源合約，不讀取或輸出額外 PII。

## 10. QA 驗收

- Happy path：轉出兩筆 attended，確認來源補足兩筆 future scheduled，關聯資料與台帳 owner 一致。
- Edge：轉出後來源已有歷史缺口、取消／請假占位、最後一堂在未來；確認只尾端補排且不重複。
- Error：目標時段衝突、無權限、手動 occurrence；確認 422／跳過且資料不變。

### Revert-proof 驗證

- [ ] 先以修改後測試通過；再暫存修改並重跑新增測試，至少一個 case 必須 failure；恢復修改後再次通過。

## 11. 上線與維運

- 部署：feature branch → required CI → PR merge → `deploy.yml` 自動部署。
- Migration：無。
- Observability：保留既有 schedule audit 與 response 摘要；不新增 PII log。
- 回滾：revert merge commit，重新部署上一個 production revision；預估 ≤30 分鐘，既有已建立堂次不由回滾自動刪除，需另走受控資料修復。

## 12. 優先級

- P2；執行 Agent：AI Agent `[DEV]` / `[TEST]` / `[REVIEW]` / `[OPS]` / `[DOCS]`。

## 13. 風險／假設／開放問題

- 假設：#250 的兩筆被轉出堂次代表來源新合約仍應維持原 8 堂排程承諾；這由 production read-only records 與回報描述共同支持。
- 風險：原合約固定排課若與其他資料衝突，補排必須沿用既有物化衝突處理，不得強制寫入。
- 開放問題：現有 #250 production rows 是否需要人工補排，屬資料修復／業務操作，不在本 PR 自動執行；若需修復，另走 allowlist dry-run 與 Founder/主任確認。
- 業界參考已於 B1 查證：Google Calendar 用穩定 occurrence identity 管理移動後 instance；Cal.com 的 recurring double-booking issue 顯示只依目前 slot 而不保留 occurrence identity 會造成重複。此 PR 先補足現有模型可安全表達的尾端承諾，長期 occurrence identity 仍應留在既有架構議題。

## 14. Definition of Done

- [ ] FR-001–FR-005：`php artisan test --filter=StudentClassTransferSessionsTest` 全部通過。
- [ ] Revert-proof：stash 後新增 #250 case 至少一個 failure，恢復後全部通過。
- [ ] Regression lesson：`rg -n 'R133|transfer.*tail|轉移.*補排' docs/AI_REGRESSION_LESSONS.md` 命中新增不變式。
- [ ] CI：GitHub required checks 全部成功，未降低 branch protection 或移除測試。
- [ ] Deployment：merge 後 `deploy.yml` 成功，`/api/v1/health` HTTP 200 且 `/version.json` 與 main revision 對齊。
- [ ] In-app Bug：部署後才可走 Phase C；目前 #250 尚未宣稱 resolved，須等待公開回覆與回報者驗收。
