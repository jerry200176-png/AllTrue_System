# ADR-005 — Scheduling Uses Multiple Task Surfaces with Named Command Boundaries

> **Status:** Accepted（Founder-approved 2026-07-27；merged via #1461）。  
> **Date:** 2026-07-27  
> **Scope:** 排課／代課／調課／恢復正班／合約老師變更的 **write boundary** 與多入口治理。  
> **Related:** `ADR_004_atomic_reschedule_boundary.md`（調課原子交易）、`docs/AI_REGRESSION_LESSONS.md` §R73／§R74／§R83。  
> **Implementation:** `RestoreContractTeacher` — `RestoreContractTeacherService` + `POST /api/v1/class-sessions/{id}/restore-contract-teacher` + `frontend/src/lib/schedulingCommands.js`（首 slice）。

## Context

AllTrue 的主任工作起點不同，因此排課相關操作可從多個畫面進入：

| Task surface | 使用者心智 | 適合的操作 |
|---|---|---|
| `StudentsList` | 替這位學生開課 | 建立 enrollment／student course |
| `SmartCalendar` | 處理這個時間／這一堂 | 單堂代課、調課、取消、恢復正班、時段衝突 |
| `CourseManagement` | 管理這份課程／合約 | 合約老師、堂數、續報、課程狀態、帳務關聯 |

前端已有共用 UI（如 `UniversalClassScheduler`），但各入口仍可能各自推導 domain truth（例如「正班老師是誰」）、組裝不同 payload，或漏接同一套錯誤攔截。2026-07-27 案例：課程管理「排回主課老師」可用，行事曆因把畫面上的有效授課老師（代課）誤當正班而失敗——問題不是「多入口」，而是「多入口各自回答 domain 問題」。

業界對齊（一句）：Jira／Linear／Google Calendar／Salesforce 都是 **many task surfaces + one authoritative resource／validation on write**；不是「每個畫面一個 API」，也不是「砍成單一畫面」。

## Decision

### D1. 保留三個 task surfaces

不砍成單一「排課中心」。一般排課／代課／調課維持多入口 UX。

**刻意單一 privileged surface（不擴散到三入口）的操作**另屬維運／安全範疇，不在本 ADR 實作範圍內，例如：大量堂次修復、扣堂／帳務校正、跨分校資料移轉、production repair、身分與權限調整。

### D2. 每個 mutation 對應具名 domain command

Canonical write commands（名稱為決策；HTTP 路徑可後續對齊）：

| Command | 意圖 |
|---|---|
| `CreateStudentCourse` | 建立學生課程／enrollment |
| `AssignSubstitute` | 指派單堂代課老師 |
| `RestoreContractTeacher` | 單堂恢復為合約正班老師 |
| `RescheduleSession` | 單堂調課（時間／日期；見 ADR-004） |
| `ChangeContractTeacher` | 變更課程合約老師 |

### D3. 禁止 generic mutation

禁止（含等價命名）作為排課寫入邊界：

- `UpdateSchedule`／`SaveClass`／`ChangeTeacher(mode)` 這類需前端猜語意的萬用 mutation
- 由頁面組裝任意 payload 直打 model／legacy generic endpoint 作為新功能預設路徑

既有相容路徑可暫時保留，但新行為與修復必須走具名 command；legacy 須標為 deprecated 並在 slice 完成時不可達。

### D4. Command input 規則（避免過度僵硬）

**規則：** Command 只接受「完成使用者意圖所必要的 target values」；**不接受**後端可自行取得的 current／derived domain state 作為寫入依據。

| 可接受（使用者明確選擇的目標） | 不可接受（系統既有／可推導真相） |
|---|---|
| `AssignSubstitute.substitute_teacher_id` | 前端回傳的「目前正班老師 id」作為 restore 依據 |
| `ChangeContractTeacher.new_contract_teacher_id` | 前端回傳的 effective teacher 當作 contract teacher |
| `RescheduleSession` 的目標時段 | 前端推導的 campus ownership 取代後端校驗 |
| `expected_version`（樂觀鎖／concurrency） | 前端拼出的「mode=restore｜substitute｜contract」萬用欄 |

因此：

- `RestoreContractTeacher({ session_id, expected_version })` **不得**接受 `teacher_id`——恢復對象是後端 `StudentClass.TeacherID`。
- `ChangeContractTeacher`／`AssignSubstitute` **必須**接受對應的 target teacher id——那是本次意圖，不是推導既有真相。

Read model 仍可暴露 `contract_teacher_id`／`effective_teacher_id` 供顯示；**不得**把這些 derived 欄位原樣當成 restore／權限決策的 write input。

### D5. 後端 authoritative handler 職責

每個具名 command 的 handler（application service）負責：

- 讀取 current contract teacher 與 effective teacher
- 權限與多校區隔離（`CampusID`／`branch_id`）
- 續報／狀態／衝堂等攔截
- concurrency（如 `expected_version`）
- audit event
- domain error mapping（穩定 error code，供三入口一致呈現）

Controller 只做驗證、授權轉譯與錯誤輸出；不在 controller／Vue 內重新發明 domain 判斷。

### D6. 前端 client 邊界

- 所有 scheduling **mutation** 經由單一 typed domain client（暫名 `schedulingCommands`；實作細節屬 follow-up PR）。
- 可共用 action shell／loading／error presentation／slot UI。
- **不**建立巨大共用 `schedulerService` 把所有 domain 邏輯塞進一個 Vue／JS 模組。
- 頁面不得自行計算 contract teacher 再回傳給 restore 類 command。

## First implementation slice（ADR 接受後才開 PR）

只做 **`RestoreContractTeacher`** 作為第一個 vertical slice。

**完成標準（DoD）：**

1. Handler + 測試通過  
2. Calendar 與 CourseManagement 都改用同一 command  
3. Legacy restore／「傳正班 teacher_id」寫入路徑移除或不可達  
4. Cross-surface conformance：同一 fixture 兩入口最終 teacher／session／audit／error 語意一致  
5. 不藉機重構其他 scheduling commands

## Non-scope（本 ADR／首 slice 明確不做）

- 不砍三入口  
- 不重寫 `UniversalClassScheduler`  
- 不一次遷移所有 scheduling endpoints  
- 不建立完整 CQRS framework  
- 本 ADR PR 不改 production runtime／不 deploy 業務行為  
- 不藉機重構帳務、續報或權限系統  

## Consequences

- 多入口 UX 合法；多套 domain 語意不合法。  
- AI／多 agent 協作時，PR 停止條件明確：頁面自算 contract teacher、繞過 domain client、同操作不同 payload schema、只測單頁可按 → 拒絕合併。  
- 與 ADR-004 相容：`RescheduleSession` 已是具名原子邊界；本 ADR 把同族命令治理擴到代課／恢復／改約／開課。  

## Verification（文件階段）

- 本檔合入 `main` 後，INDEX 可發現；後續 `RestoreContractTeacher` PR 必須引用本 ADR。  
- 實作 PR 的 conformance／handler 測試清單見 First implementation slice DoD。  

## Rollback

文件決策回滾：revert 本 ADR commit 並更新 INDEX。不涉及 schema／runtime。
