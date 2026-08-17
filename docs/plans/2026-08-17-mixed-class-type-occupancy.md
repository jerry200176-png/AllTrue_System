---
owner: jerry (CEO)
status: Draft plan — Phase 0 docs only; DEV in follow-up PR Refs #1889
review_cycle: as-needed
last_reviewed: 2026-08-17
---

# Bug Fix Plan：混班型同時段不得用較嚴上限蓋掉一對三空位

**GitHub:** [#1889](https://github.com/jerry200176-png/AllTrue_System/issues/1889)  
**In-app:** #238 剩餘（幽靈佔用已由 #1885／PR #1886／§R114 結案）  
**Risk-Class（實作 PR）：** R2  
**本 PR：** R0 docs only

---

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯錯誤（容量聚合）＋ UX 文案 |
| 根因摘要 | 同一老師同一時段的 unique 學生數被拿去跟「該格出現過的最嚴班型容量」或「任一重疊列 remaining=0」比，一對二已滿就把一對三也打成已滿。 |
| 錯誤行為 | 15:00 已有 1 位一對三 + 1 位一對二時，徽章 `2/2` 已滿、代課卡已滿、加一對三被擋。主任把已滿讀成其他分校已排。 |
| 預期行為 | Unique 學生數對「即將加入／被代課的班型」算剩餘。混班時一對三仍可再收至 3；一對二滿了就不能再加一對二。佔用全在目前分校時，文案寫本分校人數，不寫他校。 |
| 影響範圍 | 主任行事曆日檢視徽章與加課檢查；代課老師選擇；`SubstituteService` 容量佔用 API。不改扣堂、繳費、教室容量。 |
| **歷史比對** | #1885／§R114 已修同日二次調課殘影（F1）。本缺口未修。同型：#935／in-app #171（同分校已滿被讀成他校）；#1582／in-app #214（一對三假已滿，只修 unique student）；#557／#364（有空位顯示已滿）。家族 **F4**（一對三／容量呈現混淆）＋讀側名額（F1 延伸、§R72 容量路徑）。Sentry：非 crash，N/A。MemPalace：無獨立混班型決策，命中扣堂／一對三呈現舊文。 |
| **根因層級** | **UX＋容量模型缺口**（不是一次性資料錯）。5 Whys：已滿 ← 任一 1v2 列 remaining=0 ← 剩餘用該列班型容量算 ← 同格混班沒有「依即將加入班型」的剩餘 ← 產品把不同班型當同一格座位池卻用最嚴上限。 |
| **大廠參考** | Calendly group events：一個時段一個 invitee limit，並**禁止重疊的不同 event type**（[community](https://community.calendly.com/how-do-i-40/how-to-create-a-group-event-with-1-time-slot-1051)）；顯示 remaining spots（[help](https://calendly.com/help/group-event-type-overview)）。Cal.com Offer Seats：`seatsPerTimeSlot` 對**該 event type 的 attendees** 數，滿了 409 `BookingSeatsFull`（[createNewSeat.ts @ f7b2f276](https://github.com/calcom/cal.com/blob/f7b2f276/packages/features/bookings/lib/handleSeats/create/createNewSeat.ts)）。**不抄「禁止混班」**——正式站 8/20 已有一對二+一對三同格。要抄的是：剩餘座位數對**正在操作的班型**算，不要用別的班型的滿席去蓋掉。 |
| B1 偵查來源 | 本計畫整合：queue dump `32019817568`、detail `32020028704`、availability `32019802711`、#1885 occupancy dump `32020430778`。 |

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 行事曆／代課混班型時段容量 |
| 版本 | 2026-08-17 |
| 狀態 | Phase 0 計畫（本 PR）；實作 follow-up Refs #1889 |
| 嚴重度 | P1 |
| 目標角色 | 分校主任（行事曆排課、代課選人） |
| 關聯 Bug | in-app #238 剩餘；GitHub #1889；前案 #1885／#1582／#935 |

---

## 2. 業務背景與影響

大直主任回報老師週四在大直卻顯示已滿／其他分校已排。查證：老師綁大直+新店，8/20 佔用全在大直。下午 5 點後 unique 人數確實滿，應維持已滿。15:00 混班才是假已滿。

**修復後預期行為：** 混班時徽章與加課／代課對一對三仍顯示可再收；一對二滿了只擋一對二。本分校佔用寫本分校人數。幽靈殘影維持 §R114，不回退。

---

## 3. 範圍

**In**
- `getSlotOccupancy` 徽章 count/max/tooltip
- `checkConflict` 不得用既有一對二上限擋住加入一對三
- 代課選擇卡：依被代課班型算 remaining，禁止「任一 remaining=0 ⇒ 已滿」
- 後端容量佔用：時段 unique 學生；各班型 remaining = 班型容量 − unique
- 佔用分校＝目前分校時的文案
- PHPUnit + 前端測試

**Out**
- §R114／`StaleScheduleExceptionFilter` 二次調課殘影
- 禁止混班、拆班、改 ClassType 主檔
- TD-076 schema、扣堂、繳費、教室容量、跨分校 422 訊息本體（僅前端誤標）
- 重開 in-app #238 為 `new`（已 Phase-C `resolved`；用 follow-up 留言指到 #1889）

---

## 4. RACI

| 工作 | R | A | C | I |
|---|---|---|---|---|
| 計畫／實作／測試／PR／merge／deploy／in-app follow-up | AI Agent | AI Agent | — | Founder（本計畫已依建議開單） |

人類不阻擋 R0 docs merge；實作 R2 依 solo-mode 自審＋required checks。

## 4b. Dependencies

無 migration。必須 **不回退** §R114 測試。實作 PR 以本檔合入後的 `origin/main` 開 `fix/` 分支。

---

## 5. Acceptance Criteria

### AC-001：混班徽章
- AC-001-a：主任看 15:00、unique=2（1v2+1v3），徽章不是已滿 `2/2`，一對三顯示還可再收 1
- AC-001-b：unique=3 或同格有一對一，徽章已滿（反向）

### AC-002：加課
- AC-002-a：上述 15:00 再加一對三第 3 人，系統允許
- AC-002-b：再加一對二第 3 人，系統拒絕

### AC-003：代課選擇
- AC-003-a：被代課班型一對三、unique=2，該老師不是已滿
- AC-003-b：被代課班型一對二、unique=2，該老師已滿（反向）

### AC-004：文案
- AC-004-a：佔用 campus 全等於目前分校時，不出現「其他分校已排」語意
- AC-004-b：佔用 campus 與目前分校不同時，才標他校有課（既有跨校 422 不變）

### AC-005：R114 不回退
- AC-005-a：`test_same_day_second_reschedule_leftover_scheduled_is_not_busy` 與 `weeklyTemplateOccupiesHour` 仍綠

---

## 5b. UI／UX

Ops 密度畫面。徽章維持 `count/max` chip，不改成行銷風。Tooltip 可寫「本分校 2 位：一對二已滿，一對三可再收 1」。Primary CTA 不增加。

---

## 6. 功能需求 FR

- **FR-001：** 時段佔用人數＝該老師該時段重疊的 **unique 學生**（#1582 不變式保留）。
- **FR-002：** 班型 T 的剩餘＝`capacity(T) − unique`（不小於 0）。即將加入／被代課用這個剩餘，不用別的班型的剩餘。
- **FR-003：** 一對一仍上限 1（同格有一對一則不能再加任何人）。
- **FR-004：** 老師實體上限仍 3（`TEACHER_SLOT_ABSOLUTE_MAX`）。
- **FR-005：** 徽章 max＝同格班型容量的合理顯示（有一對三且無一對一時 max=3；只有一對二 max=2），count 不 clamp 成較嚴班型而把剩餘藏掉。
- **FR-006：** 本分校佔用文案不暗示他校。
- **FR-007：** R13 無 ClassSession 的補課 scheduled 仍佔用；R114 殘影仍不佔用。

---

## 7. 非功能需求 NFR

非效能 bug。NFR 不適用於延遲數字。實作不得讓 availability 對每位老師多打一輪 N+1（沿用現有一次 busy_slots）。

---

## 8. 技術方向

涉及（方法名，無程式碼）：

- `SubstituteService::collectTeacherBusySlotsWithCapacity`：改為時段聚合 unique 學生後，再為各班型填 remaining；或維持逐列但 remaining 一律用「unique vs 該列班型」。禁止逐列用「只算與自己同班型的人數」。
- `SubstituteTeacherPickerModal` 的 `enriched`：衝突＝被代課班型 remaining≤0，不是任一重疊列 remaining≤0。
- `SmartCalendar.vue`：`getSlotOccupancy`、`checkConflict` 的 `blockedBy`（現況用既有課程班型上限擋新課）。
- 測試：`AvailabilityCapacityTest` 增混班案例；`SubstituteTeacherPickerModal.test.js`；抽出徽章純函式則單測（避免只改 Vue 無 revert-proof）。
- 文件：實作 PR 寫 §R115；CHANGELOG 教職員可感知 → `staff_update`。

**治標 vs 治本：** 本包是讀側容量語意治標。治本混班政策（是否允許 1v2+1v3 同格）屬產品，正式站已存在故本包承認混班。幽靈列治本仍是 TD-076，**本包不碰**。

## 8b. Decision Log

| 日期 | 選擇 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-08-17 | 依即將加入／被代課班型算剩餘 | 禁止混班（Calendly） | 8/20 已有混班；禁混班會擋現況合法課 |
| 2026-08-17 | 徽章顯示 unique／較寬班型上限＋分項 tooltip | 只改代課、不動徽章 | 主任是看行事曆徽章說已滿 |
| 2026-08-17 | 不重開 #238 | 把 #238 拉回 in_progress | 殘影已驗收軌道；剩餘另開 #1889，follow-up 留言 |
| 2026-08-17 | 實作另 PR | 本 PR 直接改 production | 本指令是開計畫；行為變更走 R2 + 測試先行 |

---

## 9. 資安與存取控制

不改 auth。佔用 API 仍分校隔離。錯誤／tooltip **不列出他校學生姓名**（既有跨校 422 只含 campus_id 與時段）。`[REVIEW]` 資安審查：不觸發（無 PII 新欄、無角色擴大）。Code review 仍必做。

---

## 10. QA 驗收

**Happy：** 15:00 混班加一對三第 3 人；徽章可再收 1。  
**Edge：** 只有一對三 2 人；只有一對二 2 人；一對一+任何人；unique=3。  
**Error：** 加一對二第 3 人被拒，訊息寫本分校已達一對二上限。  
**R114：** 二次調課殘影測試全綠。

### Revert-proof 驗證
- [ ] git stash 後重跑新增測試，各新增 case 至少 1 failure

---

## 11. 上線與維運

無 migration。實作 PR merge → `deploy.yml` → `version.json` SHA。Rollback＝revert 該 SHA。Observability：無新 metric；回歸靠測試。預估 rollback < 5 分鐘。

---

## 12. 優先級

P1。執行 Agent：`[DEV]`／`[TEST]`／`[REVIEW]`／`[DOCS]`／`[OPS]`。In-app follow-up：`[DOCS]`（#238 已 resolved，只留言不改狀態）。

---

## 13. 風險／假設／開放問題

**本專案：** §R72／§R114 佔用讀側；#1582 unique student；F4 一對三呈現。  
**業界：** Calendly 一格一種 group limit、禁止重疊 event type。Cal.com 對單一 event type 數 attendees vs `seatsPerTimeSlot`。  
**開源：** Cal.com `createNewSeat.ts`（AGPL，**只引模式不抄碼**）。學校課表產生器（OR-Tools／GA）把「老師不可分身」與「教室容量」分成兩條硬限制，對齊「實體在場」vs「此班型還能再收誰」。  
**風險：** 改 `blockedBy` 後，現場若其實不想讓一對三併進已有一對二的格子，會變得「系統允許、主任不想」。假設：8/20 混班是合法現況，允許一對三再收 1 符合主任「不應已滿」的回報。若 Founder 要禁混班，另開產品單，不在 #1889。

---

## 14. Definition of Done

- [ ] FR-001～007：驗證方式：實作 PR 的 PHPUnit `AvailabilityCapacityTest` 混班案例 + 前端 picker／徽章測試全綠
- [ ] AC-001～005：驗證方式：同上；`StaleScheduleExceptionBusyTest` 二次調課案例仍綠
- [ ] Revert-proof：驗證方式：`git stash` 後重跑新增測試，各新增 case 至少 1 failure
- [ ] CHANGELOG（實作 PR）：驗證方式：`git diff docs/CHANGELOG.md` 含當日條目與 `staff_update` 或本計畫 silent_ship
- [ ] Health check（實作 PR 部署後）：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok",...}` HTTP 200；`version.json` hash＝merge SHA
- [ ] In-app：驗證方式：#238 維持 `resolved`；公開 follow-up 含 `https://github.com/jerry200176-png/AllTrue_System/issues/1889`；實作上線後再一則請驗收混班格
- [ ] 本 Phase 0：驗證方式：`node scripts/docs-integrity-check.mjs --strict` 通過；Presubmit CHECK 4A silent_ship id 存在

---

## Todos

| 類別 | Agent | 狀態 |
|---|---|---|
| 本 PR：計畫＋INDEX＋silent CHANGELOG | `[DOCS]` | 本工作樹 |
| GitHub #1889 | `[DOCS]` | 已開 |
| in-app #238 follow-up（不改狀態） | `[DOCS]` | docs PR 後 dispatch |
| 後端容量聚合 | `[DEV]` | 實作 PR |
| 前端徽章／衝突／代課卡 | `[DEV]` | 實作 PR |
| Regression + revert-proof | `[TEST]` | 實作 PR |
| Code review 對照 FR | `[REVIEW]` | 實作 PR |
| §R115 + staff_update | `[DOCS]` | 實作 PR |
| deploy + health | `[OPS]` | 實作 PR |
| Migration | `[DEV]` | 不適用 |
