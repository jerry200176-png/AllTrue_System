# Bug Fix Plan — 代課/調課 UX 防呆與回復路徑

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | UX 防呆不足 / 回復路徑缺失 |
| 根因摘要 | 單堂代課後，系統只有「換另一位代課老師」入口，沒有明確「回正班老師」入口；正班老師又被候選清單排除，導致使用者搜尋不到。 |
| 錯誤行為 | 主任想把游家豫 4/28 課程從陳章華改回黃芝琳時，選項與搜尋都找不到黃芝琳。 |
| 預期行為 | 使用者能在該堂上下文內看到目前老師、正班老師、代課狀態，並可一鍵回復正班老師。 |
| 影響範圍 | 課程管理單堂操作、代課 Modal、`POST /api/v1/class-sessions/{id}/substitute`。 |
| B1 偵查來源 | 候選 1 已確認：正班老師被 UI 排除且後端同老師 422，缺少清除代課路徑。 |

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 代課/調課 UX 防呆與回復路徑 |
| 日期 | 2026-04-28 |
| 狀態 | Local verified；待 PR/CI/部署 |
| 目標角色 | 主任 / 行政 |
| 關聯 Bug | 大安分校游家豫 4/28 單堂老師被代課後無法搜尋正班老師 |

## 2. 業務背景與影響

代課與調課是高頻且容易被臨時更正的行政流程。若系統只提供「往前改」而沒有「回復」入口，使用者會用搜尋或改課程主檔等 workaround，增加誤改未來課程、誤通知家長、老師點名錯誤的風險。

修復後預期行為：主任可以在單堂視角理解「目前授課老師」與「課程正班老師」的差異，並用明確入口清除單堂代課。

## 3. 範圍

In Scope：
- 單堂代課 Modal 顯示「目前老師 / 正班老師 / 回正班老師」語意。
- 後端支援同一 API 以正班老師 ID 清除該堂代課 exception。
- 代課後的 schedules / LearningRecord / open Notification 狀態一致。
- Regression test 覆蓋「代課後回正班老師」。

Out of Scope：
- 不重設課程主檔 `StudentClass.TeacherID`。
- 不改整批老師請假代課流程。
- 不改堂數扣除、繳費、薪資計算規則。
- 不新增 DB 欄位或 migration。

## 4. RACI

| 工作 | R | A | C | I |
|---|---|---|---|---|
| B2 修復 | AI Agent | AI Agent | 使用者 | 主任/老師 |
| UX review | AI Agent | AI Agent | 使用者 | 主任/老師 |
| 測試驗證 | AI Agent | AI Agent | 使用者 | - |
| 上線決策 | 使用者 | 使用者 | AI Agent | 主任/老師 |

## 4b. Dependencies

無 DB/migration 依賴。需走 feature branch → PR → CI → merge → deploy.yml，自動部署後驗 health/version。

## 5. Acceptance Criteria

### AC-001：代課後可回正班老師
- 主任對一堂已代課課程開啟單堂操作，系統顯示「回正班老師」。
- 按下後，該堂回復課程正班老師，代課 schedules exception 被清除。

### AC-002：正班老師不再被誤當作搜尋不到
- 代課 Modal 說明正班老師不是一般代課候選，而是獨立回復動作。
- 搜尋不到時不能讓使用者誤以為老師資料不存在。

### AC-003：資料一致性
- 若該堂有 active LearningRecord，TeacherID 回復正班老師。
- 若有 open substitute Notification，標記 resolved，避免家長/主任看到未結束的代課事件。

### AC-004：防回歸
- `SubstituteTeacherTest` 必須覆蓋「先代課，再用正班老師 ID 回復」。

## 6. 功能需求 FR

- FR-001：代課 Modal 必須區分「目前授課老師」與「課程正班老師」。
- FR-002：當目前老師不等於正班老師時，顯示上下文內「回正班老師」入口。
- FR-003：後端 `POST /class-sessions/{id}/substitute` 收到正班老師 ID 時，應清除該堂代課 exception，而不是 422。
- FR-004：回復操作必須同步 LearningRecord.TeacherID 與代課通知狀態。
- FR-005：回復操作不得改動課程主檔或其他日期堂次。

## 7. 非功能需求 NFR

- NFR-001：操作仍為單堂範圍，交易內完成，不引入跨堂掃描。
- NFR-002：UI 文案使用主任可理解語言，不出現 `schedules` / exception 等內部詞。
- NFR-003：不增加 production deploy 手動步驟。

## 8. 技術方向

後端：
- `ClassSessionController::substitute()`：將「同老師 422」改為 restore-original branch，先解析現有代課 schedules，再清除 exception。
- 新增私有方法封裝回復邏輯，保持既有代課寫入流程不被拉長。

前端：
- `useSessionEditFlow.js`：保存單堂目前 `teacher_id`。
- `CourseManagement.vue`：傳入 `current_teacher_id/current_teacher_name` 與正班老師資訊。
- `SubstituteTeacherPickerModal.vue`：顯示「回正班老師」 contextual recovery action。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-04-28 | 用同一 substitute API 接正班老師 ID 代表清除代課 | 新增 `/restore-original-teacher` endpoint | 前端改動小，後端仍能集中處理授課老師變更語意。 |
| 2026-04-28 | 回復入口放在代課 Modal 內 | 只靠 Toast undo | 老師已自行解決代表錯誤可能發現較晚，不能只依賴短時間 Undo。 |
| 2026-04-28 | 不把正班老師塞回一般候選清單 | 讓搜尋可以找到正班老師 | 正班老師不是「代課候選」，應是另一種動作，避免語意混亂。 |

## 9. 資安與存取控制

觸發原因：角色/權限邊界與學生課程資料。

STRIDE：
- Spoofing：沿用既有 Bearer token + role middleware。
- Tampering：僅 director/admin/super_admin 可操作，且仍檢查學生分校在 operator 管理範圍。
- Repudiation：寫入 audit log 與 LearningRecordTeacherChange。
- Information Disclosure：不新增學生 PII 回傳。
- DoS：單堂操作，不新增批次掃描。
- Elevation of Privilege：不放寬 teacher 角色操作權。

## 10. QA 驗收

Happy Path：
- 已代課單堂 → 回正班老師 → class-sessions 顯示正班老師。

Edge：
- 無 LearningRecord 也可回復。
- 已有 LearningRecord 時 TeacherID 回復。
- 無代課 schedules 時按回復不應破壞資料。

Error：
- 非管理角色不可操作。
- 跨分校 director 不可操作別分校學生課程。

Revert-proof：
- `git stash` 回復後，新增 regression test 應回到 422 並失敗。

## 11. 上線與維運

- 無 migration。
- 前端有 deployable diff，需 PR CI green → merge → deploy.yml 自動部署。
- Deploy 後驗證 `/api/v1/health` 與 `version.json`。
- Rollback：用 `git revert <commit>` 建立回復 PR；無 DB rollback。

## 12. 優先級

P2。不是系統掛掉，但會導致主任誤判老師資料不存在，且可能誤改課程主檔或錯誤通知。

執行 Agent：
- `[UX]`：確認文案與入口。
- `[DEV]`：完成後端/前端。
- `[TEST]`：PHPUnit + frontend build。
- `[REVIEW]`：檢查多校區與資料一致性。
- `[DOCS]`：CHANGELOG。

## 13. 風險 / 假設 / 開放問題

外部參考：
- NN/g User Control and Freedom：使用者需要清楚的 emergency exit、Undo/Back/Cancel 來回到前一狀態。
- NN/g Complex Applications Heuristics：複雜應用尤其需要 visibility、error prevention、recognize/diagnose/recover。
- Enterprise UX 複雜工作流模式：使用 progressive disclosure、impact preview、contextual confirmation，避免把複雜度丟給使用者記憶。
- Reversible actions framework：短期 Undo 適合剛做錯；較晚發現的錯誤需要 restore/recovery action。

風險：
- 若某些資料把調課與代課混在同一組 `schedules` exception，清除代課時可能也清掉同日調課資訊；需用 regression test 覆蓋「合併代課+換時」另案。
- 若已核准評量與薪資統計已被使用，回復 TeacherID 會影響歸屬；目前這是預期修正，但需在 UX 上提示。

開放問題：
- 「回正班老師」是否要二次確認並顯示影響：「會清除本堂代課、評量老師回正班、不影響其他堂」？
- SmartCalendar 也有代課入口，是否同步加同樣 UX？

## 14. Definition of Done

- [x] FR-003：`vendor/bin/phpunit tests/Feature/SubstituteTeacherTest.php` 回傳 OK。
- [x] 前端：`cd frontend && npm run build` 成功。
- [x] REVIEW：確認 restore branch 仍檢查角色與分校。
- [x] UX：代課 Modal 顯示目前老師/正班老師與回復入口，不需搜尋正班老師。
- [x] DOCS：`docs/CHANGELOG.md` 有 2026-04-28 fix(substitute) 條目。
- [ ] OPS：PR merge 後 deploy workflow success，health 200，version 更新。

## Exit Checklist

- [x] B1 根因已確認。
- [x] 已查外部 UX 原則。
- [x] 已定義 AC/FR/QA/風險。
- [x] 本地驗證已完成：PHPUnit 單檔與前端 build 通過。
- [ ] 待 PR/CI/OPS：建立獨立修復 PR，CI 綠後 merge 並驗證 deploy/health/version。
