# PRD：行事曆／課表載入效能 Epic（calendar-performance）

> Status: PLAN（待使用者批准進 DEV）｜Owner: AI（多 agent）｜Date: 2026-06-01
> 風險：**高**（觸碰 G-007 occurrence merge、TD-058 代課解析、扣堂相鄰邏輯）→ 改動需 golden 快照 + CI 綠 + 使用者批准

## 1. 文件資訊
- 來源：使用者回報「最大的問題是行事曆/課表載入速度很慢」。
- 對標：Cal.com（open source 排程軟體）效能實踐、FullCalendar 大量重複事件渲染。
- 既有債務對應：TD-018（/class-sessions 慢查詢）、TD-058（代課 correlated subquery 去索引化）。

## 2. 目標 / KPI
- `/api/v1/class-sessions` p95 從 1–3.5s → **< 800ms（SLO）**，目標 < 500ms。
- 行事曆「換週/換日」互動：從每次全量 3-request waterfall → **已載入視窗內 < 100ms（命中快取，0 net request）**。
- 不得改變 occurrence 合併結果、代課老師解析、扣堂行為（byte-identical 輸出，golden 快照保護）。

## 3. 範圍
- In：SmartCalendar 載入路徑（前端 fetch 編排 + 快取）、`ClassSessionController::index` 查詢、`ScheduleController`/`StudentClassController` 視窗化、occurrence merge 記憶體化。
- Out：行事曆 UI 視覺改版、補課演算法語意變更、扣堂規則變更、Laravel 升級。

## 4. 診斷（已完成，read-only 調查 + 業界對標）

### 4.1 負載路徑
- Mount/換分校 → `reloadCalendarData()`（5 loader 平行，OK）。
- **換週/換日 → 只重跑 `loadCourses()`**，但其內部是 **3 個 await 串行 waterfall**：
  1. `GET /student-classes`（`fetchAllPages` 200/頁、concurrency 4、**無日期視窗**，最多 ~10k 列）→ 阻塞
  2. `GET /schedules?per_page=2000`（±21 天視窗）
  3. `GET /class-sessions?per_page=2000`（±21 天視窗）
- 之後 `mergeWeekCalendarOccurrences` 在 `filteredCourses` computed 合併（G-007 保護路徑）。

### 4.2 瓶頸排名（證據）
| # | 瓶頸 | 證據 | 影響 |
|---|---|---|---|
| 1 | `/class-sessions` 代課 correlated subquery `MAX(sub2.id)` + `DATE()/SUBSTRING()` 包裹欄位 → 索引 `idx_sched_course_date_time_status` 失效 | `ClassSessionController.php:112-121`；TD-058 | 主查詢 1–3.5s 主因 |
| 2 | 換週/換日全量重跑 waterfall、無 client 快取、`student-classes` 無視窗 | `SmartCalendar.vue:2027/2095/2147`、watch `:3493` | 每次導覽都付全量延遲 |
| 3 | `whereDate('cs.SessionDate')` 包欄位 → range 掃描去索引 | `ClassSessionController.php:236,240` | 範圍掃描變慢 |
| 4 | GET 上 write-on-read 物化（teacher 熱路徑）| `:91-93,344-441`（#546 已批次化，殘留少量 SELECT/INSERT）| 已緩解 |
| 5 | merge `O(courses × 7 × exceptions)`，每次 reactive 變更重算 | `calendarOccurrenceMerge.js:103-208` | 中；G-007 高風險 |

### 4.3 業界對標（Cal.com / FullCalendar）
- **分頁 + delta 狀態**，不傳全量陣列（PR #28155/#28156，team 700+ host）。
- **虛擬化**（@tanstack/react-virtual）避免大量事件 re-render。
- **消滅 N+1**（`findForSlots` 7s → 修；`getTeamSchedule` 20s→2s）。
- **O(n²) → Map/排序陣列/two-pointer**；**CI 加效能基準**擋回歸。
- FullCalendar：用 imperative API 批次更新，避免靠 state re-render。

## 5. 方案（分階段，風險遞增）

### Phase 1 — 前端編排（低風險，無查詢語意變更）
- 1a. **平行化 waterfall**：`student-classes` 與 `schedules` 並行（`class-sessions` 仍依賴 course ids，第二棒）。
- 1b. **視窗快取**：以 `{branchId, schedStart, schedEnd}` 為 key 快取三端點結果；換週/換日落在已抓視窗時不重抓。**任何 mutation（建課/請假/調課/點名/刪除）後強制 invalidate**（防 G-007 課程消失類 staleness）。
- 1c. 換週/換日不重抓 `student-classes`（相鄰週幾乎不變；僅換分校/mutation 才重抓）。
- 驗收：Playwright/手動量測換週 net request = 0（命中）；merge 輸出不變。

### Phase 2 — 後端索引友善（中風險，語意 byte-identical，需測試）
- 2a. `whereDate(cs.SessionDate)` → 裸欄位 range（`>= start AND < end+1day`），命中索引。
- 2b. eager-load 殘留 lookup（若 EXPLAIN 顯示仍有）。
- 驗收：新增 characterization 測試鎖定輸出；EXPLAIN 前後對比；CI 綠。

### Phase 3 — TD-058 代課查詢重寫（高風險，golden 快照先行）
- 3a. **先建 golden-output 快照測試**（多代課/HH:MM:SS 遺留坑/同日多堂）鎖定現行代課解析。
- 3b. correlated `MAX(sub2.id)` → 單一 derived-table join（鏡像 `lr`/`si`）；評估正規化 `HH:MM` 移除 `SUBSTRING()`。
- 驗收：golden 快照 byte-identical；EXPLAIN 無 correlated subquery；p95 < 800ms。

### Phase 4 —（選配，後續）merge 記憶體化 + CI 效能基準
- 預先把 exceptions 以 `date|courseId|start` 建索引一次，取代重複 `.some/.find`。
- CI 加 query-count / 時間基準擋回歸（對標 Cal.com）。

## 8. 技術方向（禁 code）
- 不改 occurrence 合併「結果」；只改「怎麼取得資料」與「查詢計畫」。
- 代課解析以 golden 快照保護，先測後改。

## 9. 資安
- 不新增端點、不放寬權限；快取僅存於前端記憶體，依分校 key 隔離，避免跨校資料殘留（換分校必清）。

## 10. QA 驗收
- 既有 1137 PHPUnit + `npm run test:calendar`（G-007）全綠。
- 新增：class-sessions characterization/golden 測試、代課解析 golden 快照。
- 手動：四分校換週/換日、代課堂、請假/調課堂顯示正確且快。

## 12. 優先級
P1（直接命中使用者體感）。建議排期：本週 Phase 1（前端）→ Phase 2（後端索引）→ 下週 Phase 3（TD-058，需 golden 先行）。

## 13. 風險
- Staleness（Phase 1 快取）→ 嚴格 mutation-invalidate + 換分校清空。
- 代課回歸（Phase 3）→ golden 快照 + EXPLAIN 雙閘。
- 高風險模組（G-007/扣堂相鄰）→ 每階段 CI 綠 + 使用者批准才 merge。

## 14. DoD
- KPI 達標（p95 < 800ms、換週命中 0 request）。
- golden/characterization 測試入庫、CI 綠、health OK。
- TECH_DEBT TD-018/TD-058 更新狀態；CHANGELOG 記錄；不擴散到非範圍檔案。
