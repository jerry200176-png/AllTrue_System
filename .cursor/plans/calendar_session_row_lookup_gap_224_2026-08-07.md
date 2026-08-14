# Bug Fix Plan: Calendar single-session actions unavailable for manually-booked off-template sessions (in-app #224)

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯錯誤（同日多筆 ClassSession 時的比對條件過嚴，非 Query 條件缺欄位、非權限邊界） |
| 根因摘要 | `frontend/src/pages/SmartCalendar.vue::findSessionRowForCell()`（約行 1114-1140）比對「這個行事曆格子對應哪一筆 `ClassSession`」時，先用 `course.start_time`（課程契約預設時段）過濾同日的 rows，只有在 `course.is_exception === true`（調課例外）時才退回「同日任一筆」。逐堂手動排課（#211，`ManualSessionBookingService`）允許新堂次的 `start_time` 是使用者自由輸入、不必等於課程契約時段；這類堂次 `is_exception` 為 `false`，於是比對落空、回傳 `null`。 |
| 錯誤行為 | 該堂已成功寫入 `ClassSession`（`resolveAllCourseGridTimesForDate()` 渲染邏輯本身沒有這個限制，方塊仍會正確畫在行事曆上），但 `findSessionRowForCell()` 找不到對應 row，導致依賴它的「🚫 取消本堂」按鈕（`canCancelSelectedSession`）整顆消失、點名/評量角標也不顯示。使用者點開「單堂檢視」只看到「調課」「換代課老師」「刪除整門課」，沒有任何清楚對應「這一堂」的取消/刪除動作，且沒有任何錯誤訊息解釋為什麼——體驗上就是「無法移動或刪除」。 |
| 預期行為 | 只要該日期有已物化、非取消的 `ClassSession` row（無論其時間是否等於課程契約預設時段），`findSessionRowForCell()` 都應該回傳它，讓「取消本堂」「點名/評量角標」等單堂操作對逐堂手動排課的堂次和一般堂次一樣可用。 |
| 影響範圍 | `director`／`super_admin` 在智慧行事曆對「逐堂手動排課」建立、且時段不等於課程契約預設時段的堂次操作單堂取消；點名與評量角標顯示。不影響：契約時段本身的堂次（原本就會 exact match 成功）、調課例外（`is_exception=true` 早已有退回邏輯）、任何後端扣堂／帳務邏輯（純前端顯示/互動層 bug，未觸碰 `ClassSession`/`StudentClass` 寫入路徑）。 |
| **歷史比對** | 首發於 #211（2026-08-02 上線的逐堂手動排課功能）之後；屬於「新功能上線後，既有的『這個格子對應哪一筆 row』比對邏輯沒有跟著更新」——與同一份 codebase 裡已存在、且已正確處理同類問題的 `frontend/src/lib/classSessionPick.js::resolveSessionIdForSubstitute()`（同日先 exact-time match、找不到才退回 `pickBestSessionRow(sameDateRows)`）行為不一致。對應**GitHub #1041**「Consolidate frontend session pick/dedupe logic (classSessionPick vs page-local copies)」——`findSessionRowForCell` 正是 #1041 點名要收斂、卻尚未收斂的 page-local 副本之一。非既有 F1–F6 復發家族成員；本次順帶把它與家族清單的關聯記錄進 `AI_REGRESSION_LESSONS.md`，作為未來的前例。 |
| **根因層級** | 架構設計缺口——「這個行事曆格子對應哪一筆 ClassSession」在同一份程式碼裡有兩套獨立比對邏輯（渲染路徑 `resolveAllCourseGridTimesForDate` 寬鬆、互動路徑 `findSessionRowForCell` 嚴格），且已有一套正確、有測試的共用實作（`classSessionPick.js`）卻沒被複用。5 Whys：使用者點不到取消鈕 → 因為 `canCancelSelectedSession` 為 false → 因為 `findSessionRowForCell` 回傳 null → 因為比對條件要求 row.start_time 等於 course.start_time → 因為新功能（逐堂手動排課）允許 row 時間偏離課程預設，但這個比對函式沒有跟著更新 → 因為專案裡「哪一筆 row 對應這個格子」缺乏單一權威實作（此即 #1041 尚待完成的收斂）。 |
| **大廠參考** | Google Calendar Events API 對「單一堂次」用穩定的 `recurringEventId` + `originalStartTime`（原始依重複規則應該開始的時間）維持身分識別，即使該堂次被改到別的時間（`start`/`end` 變了），`originalStartTime` 仍指向它在序列中的位置，讓「找到這一堂」不會因為它被搬動過而找不到（[Recurring events, Google Calendar API](https://developers.google.com/workspace/calendar/api/guides/recurringevents)）。本專案的對應做法：`findSessionRowForCell` 應優先以「同日 + 已物化 row 存在」作為身分依據（如 `classSessionPick.js` 已經做的），而不是用課程契約預設時間反推「這一格應該是哪個時間」——後者在堂次時間可自由偏離契約時段時就會失效，正是本次症狀的成因。 |
| B1 偵查來源 | 本計畫獨立完成 B1（讀 `SmartCalendar.vue` 拖曳/單堂 modal 邏輯、`CalendarSessionEditModal.vue` 按鈕條件、`ManualSessionBookingService.php`、`classSessionPick.js`、`calendarOccurrenceMerge.js` 渲染路徑、`docs/sop/MANUAL_OCCURRENCE_SCHEDULING.md`），並比對 GitHub #1282/#1582/#1605（同區域近期修復）與 #1041（尚未收斂的已知技術債）。未能取得 in-app #224 對應的 production `ClassSession`/`StudentClass` 實際資料列（cloud session 無 SSH，唯讀 dump workflow 只涵蓋 bug_reports 系統表，不含業務表；見 `docs/sop/BUG_INTAKE_TO_PRODUCTION.md`），故本根因為「程式碼靜態分析 + 症狀完全吻合」而非「逐列核對 production 資料庫確認」，已如實標註。 |

## 1. 文件資訊

- 功能名稱：智慧行事曆單堂操作（取消本堂 / 點名評量角標）
- 版本：v1（無資料庫結構變更）
- 狀態：Draft → 待實作
- 嚴重度：P1
- 目標角色：director、super_admin（`isTeacher` 已被既有條件排除，教師本來就看不到這些按鈕）
- 關聯 Bug：in-app #224、GitHub #1671（分診記錄）、GitHub #1041（技術債前例）

## 2. 業務背景與影響

主任在智慧行事曆對「逐堂手動排課」新增的一堂課，若當初填的開始時間跟這門課平常的預設時段不同，事後想取消/處理這一堂時，畫面上看不到「取消本堂」按鈕、也沒有任何提示告訴他為什麼——體感就是「這堂課沒辦法動」。修復後預期行為：只要這一堂已經成功建立在系統裡，不論時間是否跟課程預設一樣，都能正常取消、且點名/評量角標正常顯示。

## 3. 範圍

**In Scope：**
- `frontend/src/lib/classSessionPick.js`：新增/調整一個可回傳「同日最佳比對 row」的共用函式（供 SmartCalendar 呼叫，理由與現有 `resolveSessionIdForSubstitute` 一致，避免第三份重複邏輯）。
- `frontend/src/pages/SmartCalendar.vue`：`findSessionRowForCell()` 改為呼叫上述共用函式，行為與 `resolveAllCourseGridTimesForDate()`／`resolveSessionIdForSubstitute()` 一致（同日 exact-time 優先，找不到才退回同日任一筆非取消 row）。
- 對應單元測試（revert-proof）。

**Out of Scope：**
- 不修改 `ManualSessionBookingService.php` 或任何後端扣堂/寫入邏輯。
- 不修改 `resolveAllCourseGridTimesForDate()`（渲染路徑本來就正確）。
- 不修改 `is_exception=true`（調課例外）分支的既有行為（已經是正確的退回邏輯）。
- 不處理 GitHub #1041 全部範圍（其他 page-local 比對副本），僅收斂本次牽涉到的 `findSessionRowForCell`。
- 不新增「刪除本堂」（相對於既有「取消本堂」）這個新動作——本次修復讓既有「取消本堂」對逐堂手動排課堂次可用即可對應症狀；是否需要額外的「刪除」語意留待另有明確需求時再議，避免本次範圍蔓延到帳務/歷史紀錄保留政策。

## 4. RACI

R/A：AI Agent（Claude）。人類（jerry）最多 I（被告知：GitHub issue #1671 更新 + 上線後 Phase C 回報）。

## 4b. Dependencies

無前置 PR／migration 依賴。純前端檔案異動，不涉及 schema。

## 5. Acceptance Criteria

### AC-001：逐堂手動排課、時段偏離課程預設時段的堂次可以被找到並取消
- AC-001-a：課程契約 `start_time` 為 18:00，某日期有一筆已物化、非取消狀態、`start_time=17:00` 的 `ClassSession`；director 開啟該格「單堂檢視」，`canCancelSelectedSession` 應為 `true`，「🚫 取消本堂」按鈕顯示。
- AC-001-b：同一堂次點名/評量角標（`rollCallBadge`/`evalBadge`）應能依據該筆 row 正確顯示，不因時間偏離課程預設而消失。

### AC-002：既有行為不回歸
- AC-002-a：課程契約時段本身的堂次（row.start_time === course.start_time）比對結果與修復前相同（exact match 優先，不受影響）。
- AC-002-b：調課例外（`course.is_exception === true`）的既有退回邏輯結果與修復前相同。
- AC-002-c：當日只有已取消（status=cancelled）的 row 時，`findSessionRowForCell`／新共用函式沿用既有（`is_exception` 分支原本就有的）行為回傳該 row 本身；不可操作性由既有下游各自的狀態檢查負責（`canCancelSelectedSession` 已排除 `cancelled`/`voided`、`rollCallBadge` 已顯示「取消」角標、`evalBadge` 已排除 `LEAVE_STATUSES`）——本次修復不改變、也不需要改變這層既有職責分工。

## 6. 功能需求 FR

- FR-001：`classSessionPick.js` 新增/調整之共用函式修復後，對「同日多筆候選、無 exact-time 命中」的情境一律呼叫既有 `pickBestSessionRow()` 做退回選擇（狀態優先序沿用既有 `SESSION_STATUS_PRIORITY`），不得引入新的優先序規則。
- FR-002：`findSessionRowForCell()` 修復後對「同日僅有 cancelled 狀態 row」的情境維持回傳該 row（對齊 AC-002-c，與既有 `is_exception` 分支行為一致，可操作性由下游狀態檢查把關）。

## 7. 非功能需求 NFR

不適用——純前端顯示/互動邏輯修正，非效能瓶頸相關；無需效能量測。

## 8. 技術方向

- 在 `frontend/src/lib/classSessionPick.js` 新增一個回傳完整 row（而非只回傳 id）的比對函式，與既有 `resolveSessionIdForSubstitute()` 共用比對規則（exact-time 優先、退回 `pickBestSessionRow(sameDateRows)`），避免重複實作第三份同類邏輯。
- 修改 `frontend/src/pages/SmartCalendar.vue::findSessionRowForCell()`：改為呼叫上述新函式取得同日候選 rows 後決定回傳值；移除「只有 `course.is_exception` 才退回」的限制，讓一般（非例外）逐堂手動排課堂次也能走到退回分支。
- 取捨理由：不直接讓 `resolveSessionIdForSubstitute()` 改回傳整個 row（會改變既有呼叫端已依賴的 number-or-null 回傳型別，牽動既有呼叫點與測試），改為新增一個並存的函式並讓兩者共用內部比對邏輯，風險最小。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-08-07 | 在 `classSessionPick.js` 新增回傳完整 row 的函式，`SmartCalendar.vue` 呼叫它 | (a) 直接放寬 `findSessionRowForCell` 內部條件，不抽共用函式 | (a) 會讓 #1041 要收斂的重複邏輯又多一份且沒有測試覆蓋；抽到 `classSessionPick.js` 才能單元測試、且與 `resolveSessionIdForSubstitute` 對齊，同時呼應 #1041 的既定技術方向 |
| 2026-08-07 | 不新增「刪除本堂」新動作，只修「取消本堂」的可用性 | 同時新增獨立於「取消」的「刪除」語意 | 帳務/歷史紀錄保留政策（見 CLAUDE.md 對「取消」「已上課歷史」的既有規則）非本次症狀直接相關，且會擴大變更面到帳務語意，超出本次已驗證的根因範圍 |

## 9. 資安與存取控制

不適用——純前端顯示/互動修正，未變更任何 auth / middleware / PII / 角色邊界；既有 `!session.isTeacher` 等角色門檻不變。

## 10. QA 驗收

- Happy Path：AC-001-a / AC-001-b。
- Edge：AC-002-a（契約時段堂次不受影響）、AC-002-b（調課例外不受影響）、AC-002-c（僅取消狀態不誤判）。
- Error：候選 rows 為空陣列／`undefined` 時函式需回傳 `null`，不得拋例外。

### Revert-proof 驗證
- [ ] `git stash` 後重跑 `classSessionPick.test.js` 新增的 case，至少 1 case fail（確認測試真的鎖住了本次修復的行為，而非誤綠）。

## 11. 上線與維運

- 部署步驟：merge 到 `main` → `deploy.yml` 自動建置前端並部署（純前端變更）。
- Migration：無。
- Observability：無新增告警；若之後仍有同類回報可再查 `bug_report_comments`。
- 回滾方案：`git revert` 該 PR 之 merge commit，重新部署；純前端顯示邏輯修正，回滾風險低，預估 <10 分鐘含建置時間。

## 12. 優先級

P1；執行 Agent：`[DEV]`。

## 13. 風險 / 假設 / 開放問題

- 已查本專案 `docs/AI_REGRESSION_LESSONS.md`（無直接同名 §Rxx 命中，屬首發）、GitHub（#1041 為既有已知同類技術債，#1282/#1582/#1605 為同區域近期修復但非同一函式）。
- `WebSearch` 已完成：Google Calendar Events API 的 `recurringEventId` + `originalStartTime` 身分識別模式（見 §0 大廠參考欄），佐證「身分應獨立於目前顯示時間」的修法方向。
- 假設：in-app #224 描述的「張進鴻8/8的17-19沒有陸逸的課」情境，與本次修復的 symptom class（逐堂手動排課堂次因時間偏離契約時段而無法操作）相符；因無法在本 session 內取得該筆 production 資料逐列核對（見 §0 B1 偵查來源），此為根因假設而非逐列驗證的結論，上線後將以回報者驗收（Phase C reporter-verify）作為最終確認信號。
- 開放問題：若回報者驗收後回報「問題仍存在」，需回到 B1 重新調查（可能是本假設之外的其他原因，例如換代課老師流程的容量判斷，已由 #1582 涵蓋但可能有殘留資料需另案處理）。

## 14. Definition of Done

- [ ] AC-001-a／AC-001-b／AC-002-a／AC-002-b／AC-002-c：驗證方式：`npm run test -- classSessionPick` 全綠且新增 case 涵蓋各情境
- [ ] Revert-proof：驗證方式：`git stash && npm run test -- classSessionPick`，新增 case 至少 1 failure
- [ ] CHANGELOG：驗證方式：`git diff docs/CHANGELOG.md` 含當日新增條目
- [ ] AI_REGRESSION_LESSONS：驗證方式：`git diff docs/AI_REGRESSION_LESSONS.md` 新增本次「首發無前例」紀錄並連結 GitHub #1041
- [ ] Health check：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok",...}` HTTP 200
- [ ] version.json：驗證方式：部署後 `version.json` 之 commit hash 對齊 merge commit
- [ ] In-app Bug：驗證方式：production `bug_reports.id=224`.`status=resolved` 且 `bug_report_comments` 有公開留言請回報者驗收；見 `CHAT_BUG_SYSTEM.md` §3.7 Phase C
