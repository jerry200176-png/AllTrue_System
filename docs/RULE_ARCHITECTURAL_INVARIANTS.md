# 架構性不變式登記本（Architectural Invariants Registry）

> **這份文件跟 `TECH_DEBT.md` 不一樣**：`TECH_DEBT.md` 追蹤的是「單一、可清償」的技術債項目。
> 這份文件追蹤的是**同一種形狀會反覆出現、修一次不會讓它消失**的架構級不變式——
> 每個不變式被違反過至少兩次（不同模組、不同時間），代表根因不是「這一處寫錯」，
> 而是「這個系統原本就沒有結構性地保證它」。
>
> 起源：2026-07-28 架構稽核備忘（比對 `docs/AI_REGRESSION_LESSONS.md` 84+ 條紀錄與大廠架構模式）。
> **新發現一個不變式被違反 → 先查這裡有沒有對應分類；沒有就新增一類，不要只當單點 bug 修掉。**

---

## 使用方式

發現一個 bug 時，先問：**這個 bug 的根因，是不是「某個規則靠每個呼叫端自己記得遵守」？**

- 是 → 找到下面對應的不變式分類（或新增一類），把這次的實例記進去，並優先考慮**結構性修法**（model event / 共用 policy service / 單一讀取投影），而不是只在這一個呼叫點打補丁。
- 不是（單純邏輯錯誤、單次事故）→ 記到 `AI_REGRESSION_LESSONS.md` 或 `TECH_DEBT.md` 就好，不需要在這裡開新分類。

---

## Pattern A — 衍生欄位靠呼叫端記得算

**不變式**：任何「算出來的值」（不是使用者直接輸入的值），只能有一個地方負責重算並寫入；讀取端/其他呼叫端不可各自維護一份重算邏輯。

**為什麼會破**：Laravel/PHP 沒有內建的 computed property 機制，如果不刻意設計，衍生值的寫入邏輯很自然地會散落在每個「可能需要更新它」的 controller/service 裡——一開始只有一處，後來每加一個新的寫入路徑，開發者（人或 AI）都要「記得」也去更新這個衍生值，忘記一次就產生分歧。

| 實例 | 狀態 | PR/Issue | 修法 |
|---|---|---|---|
| `ClassSession.IsContractException`（契約例外旗標） | ✅ 結構性修復 | #1487（R83 點修）→ #1489（R84 根治） | 搬進 `ClassSessionObserver::updating()`，任何存檔且時間欄位有變動都自動重算；`creating` 刻意排除（見下方「已知邊界」） |
| `StudentClass.RemainingSessions`（剩餘堂數） | ✅ 清除一份死碼重複實作 | TD-060 / #1490 | 刪除 `ClassSessionController::recalculateSessionCounters()`（零呼叫者、非分鐘感知），確認權威路徑 `SessionDeductionService::recomputeCounters()` 已完整涵蓋 |
| `LearningRecord.VoidedAt` 是否可自動復活 | ✅ 收斂為單一 policy | R55 補強 / #1491 | 抽出 `LearningRecordResurrectionPolicy::isEligibleForResurrect()`，`store()`（reactive）與 `restoreVoidedLearningRecord()`（proactive）共用同一份判斷 |
| `StudentClassController::index()` 讀取端「observed remaining sessions」自我修復公式 | ⏳ 已標記、未處理 | 見架構報告 Pattern A 建議 | 讀取時二次推算，跟持久化的權威值可能不一致；風險較低（不落地），但值得未來收斂成共用函式 |

**已知邊界（設計決策，不是缺口）**：R84 的 Observer 刻意只掛在 `updating`（已存在的堂次被移動），不掛 `creating`——因為一筆新建堂次「時間跟契約不符」是語意歧義的（可能是主任刻意加課，也可能是待覆核的漂移資料），只有呼叫端知道意圖，搬進 model 層反而會誤判。**這是本檔案存在的意義**：不是每個不變式都該無腦收斂到最強的形式，收斂前要先確認呼叫端之間的差異是不是刻意的。

---

## Pattern B — 主檔狀態轉換沒有在同一交易內串連依賴聚合

**不變式**：主檔（`StudentClass`）的生命週期狀態轉換（停用、結算、續期、補請假）必須在同一次操作內，把所有依賴它的下游資料（未來 `ClassSession`、`schedules`、名額、尾端補課）一起對齊。

**為什麼會破**：沒有一個「聚合根」物件擁有這些轉換規則，導致同一種轉換（例如「補請假」）在不同 endpoint 各自重新實作一次，且實作細節會不知不覺地分岔。

| 實例 | 狀態 | 備註 |
|---|---|---|
| `Stop=1` / 老師 suspended / 月結結算後未對齊未來堂次 | 部分修復 | F1 家族，見 `AI_REGRESSION_LESSONS.md` §復發家族 |
| `ScheduleController::retroLeave()` 與 `ClassSessionController::handleRetroLeaveTransition()` 補請假邏輯重複 | 🆕 已發現，未修復 | TD-069（2026-07-28）——兩處尾端補課策略實際不同（`tryExtendOnLeave` vs `CourseLeaveCascadeService::appendTailAfterLeave`），consolidation 前需先做行為盤點，不可直接合併 |
| #957 ClassSession materialization 統一 epic | 規劃中 | 建議明確定名為「`StudentClass` 生命週期聚合」，而非零散 fix 集合 |

---

## Pattern C — 同一份事實被每個畫面各自合併

**不變式**：同一組底層資料（`StudentClass` 規則 + `ClassSession` 實際堂次 + `schedules` 例外）只能有一個合併/投影邏輯，所有畫面/API 都呼叫同一份，不可各自重新推導。

| 實例 | 狀態 | 備註 |
|---|---|---|
| 智慧行事曆週檢視合併 | ✅ 已有正解 | `calendarOccurrenceMerge.js`，強制規則見 R25b/G-007，`npm run test:calendar` 守門 |
| 繳費狀態（`Paid` vs Invoice 雙真相） | 未收斂 | F7 家族 |
| 月結續期金額/堂次 | 未收斂 | F2 家族 |

---

## Pattern D — 前端 API 契約跑在後端前面

**不變式**：前端不可呼叫一個後端從未合併進 main 的路由；新契約（error code、新 endpoint）必須後端先行或同 PR 一起上。

| 實例 | 狀態 | 備註 |
|---|---|---|
| #1197 `BatchInvoiceModal`/`OverdueBucketsPanel` 呼叫 `/invoices/batch-preview`、`/invoices/batch`、`/invoices/overdue-summary` | 已知孤兒，未清償 | TD-067 |
| CI 契約檢查（前端路徑 vs `route:list` diff） | ✅ 已新增（advisory） | #1493——刻意先 advisory，等 TD-067 清乾淨後可仿 PHPStan 的 baseline 畢業模式升級為 blocking |

---

## Pattern E — 授權／多校隔離靠每個新 endpoint 自己記得掛

**不變式**：任何新 API endpoint 存取跨校資料，都必須經過統一的 campus 範圍檢查；不可靠每個 controller 自己記得寫 `require_campus` 或 raw query 加 `WHERE CampusID`。

| 實例 | 狀態 | 備註 |
|---|---|---|
| R60（新路由須落在 `role`+`require_campus` 群組內） | 規則已存在 | 靠 code review 把關，非結構強制 |
| Y5（`DB::table()` raw query 不受 Eloquent global scope 保護） | 規則已存在 | 同上 |
| 建議：`CampusScopedQuery` base trait 或 lint 規則 | 未實作 | 架構報告建議，尚未排入 |

---

## 度量

跟 `AI_REGRESSION_LESSONS.md` 的「復發率」精神一致：這份登記本的健康指標是——**每個 Pattern 底下「未修復」實例的數量是否隨時間下降**，而不是「有沒有新增更多分類」。分類數量增加是正常的（代表稽核越做越細），但同一分類底下實例數持續累積且不處理，代表這類根因正在被放著不管。
