# ADR-006 — Prepaid Session Horizon：Schedule Commitment × Materialization × Pool Coverage

> **Status:** Decision package **ready for review**（非 Accepted／非 implemented／非 production-ready）  
> **Date:** 2026-07-28  
> **Type:** Architecture + product decision package（文件決策；本 PR **零 runtime／零 production DB 寫入**）  
> **Related:** #1062 Track A（`docs/runbooks/1062-track-a-pcr.md`）、`ADR_005_scheduling_named_command_boundaries.md`、G-010、F4／#1465、`ForwardSessionGenerator`、`ClassSessionMaterializationService`

---

## 0. 本文件完成狀態（強制）

| 可宣稱 | 不可宣稱 |
|---|---|
| Decision package ready for review | Implemented / fixed / production-ready |
| Phase 0／Phase 1 **定義**完成 | Phase 0 報告已產出數字 |
| 與 #1062／ADR-005／G-010／F4 對齊的邊界已寫清 | CEO GO、production activation、Kernel 排程已啟用 |
| Schedule Commitment **語意**已定義；是否新表 **待 Phase 0 結論** | 已決定新增 domain table／已寫 migration |

---

## 1. 背景與問題重述

### 1.1 現場痛點（user-facing）

老師／主任的認知：學生今天要來、也已付費，為何不能直接點名？

系統卻暴露 implementation detail：有 entitlement，但沒有 `ClassSession` row，必須先到別處「補排」才能點名。

### 1.2 錯誤模型（禁止）

```
Entitlement balance（還有 N 堂）→ 猜測未來課程 → 直接扣堂
```

### 1.3 正確模型（本決策採納）

```
Schedule Commitment → ClassSession materialization（rolling horizon）
  → pool-level coverage → Attendance → Consumption ledger
```

對齊業界：credit／package **支付** booking，不自己創造 booking（ClassPass／Bookeo／Acuity 同類）。

### 1.4 三種不可混為「請補排」的狀態

| # | 狀態 | 正確產品反應 |
|---|---|---|
| 1 | 有 entitlement + 有明確固定排課意圖，但 materialization 漏掉 | 系統錯誤／reconcile（materialization failure） |
| 2 | 有 entitlement，但從未確定時間／老師／科目 | 需要 booking intent；**不是**資料錯誤 |
| 3 | 已有排課意圖，但 shared pool 不足以覆蓋所有未來堂次 | pool coverage shortage（續課／分配決策） |

---

## 2. 對齊既有權威

| 權威 | 本決策如何對齊 |
|---|---|
| **G-010** | 行事曆／點名真相 = 已物化 `ClassSession`；count-mode 缺向前生成 = 系統性缺口；不得把「某天課少」一律當 bug |
| **#1062 Track A PCR** | `ForwardSessionGenerator` + `sessions:generate-forward` 是既有引擎；本決策把它重新框進 Commitment／horizon／coverage，**不**在本包啟用 production execute |
| **ADR-005** | 具名 command、前端只傳 intent target、後端 authoritative handler；本包新增的 horizon commands 服從同一邊界 |
| **F4／#1465** | 成員課程 UI **不**顯示方案池剩餘；package under → info；count projected **不**呼叫 `ensure-projected`；物化 affordance 僅 `ScheduleMode=date`。本決策把「為何不能靠餘額猜堂」升成 domain 規則 |

---

## 3. 四層 Domain（語意定義）

本節定義 **語意層**，不預設每層都有獨立資料表。

### 3.1 Entitlement / Credit Pool

**回答：** 還有多少可使用權利？誰可用？適用哪些服務？

| 必答 | 說明 |
|---|---|
| pool owner / tenant（分校） | campus／branch scope |
| purchased grants／adjustments／expiration | 購買與調整 |
| eligible member courses | 哪些 `StudentClass` 可消耗此池 |
| consumed／reserved／available | 消耗、保留、可用 |

**規則：** Shared package 的 entitlement SSOT 在 **pool**。成員課程只回答「是否 eligible」，**不**擁有獨立 pool balance（F4）。

### 3.2 Schedule Commitment / Booking Intent

**回答：** 我們已承諾在什麼條件下提供哪些課？

最少語意欄位（邏輯，非強制 schema）：

- 學生／成員課程（`StudentClassID`）
- 科目、分校、合約老師（或 assignment policy）
- recurrence 或單次 booking（weekday + start/end，或具體日期）
- effective start／pause／terminate
- 綁定的 entitlement pool（可為 null：非 package）
- `auto_maintain_horizon`（是否自動維持未來 ClassSession）
- materialization horizon 政策（建議預設 28 天）

**規則：** 購買堂數 **不**隱含建立 Commitment。固定預付第一次設好後，續課應延長 coverage，不必每次重填時段。

### 3.3 Projected Occurrence（衍生，非 SSOT）

從 Commitment 推導的候選時段（可即時計算）。

**用途：** dry-run、預覽、缺堂偵測、materialization candidate。  
**禁止：** 作為 attendance／billing／leave／substitute 的真相來源；禁止建成與 `ClassSession` 平行的第二套 ID 空間（反模式 C）。

### 3.4 Materialized ClassSession

**回答：** 實際可調課、請假、代課、點名的那一堂是什麼？

建議每堂保留 provenance（見 §8）：

- commitment 參照（邏輯 ID；實體載體待 Phase 0）
- occurrence identity
- generation source／actor／command
- branch
- original vs current start
- entitlement pool reference（若有）
- coverage status（若已進入 Phase 3；本包不實作）

### 3.5 Session Coverage → Attendance → Consumption（後續層；本包僅定界）

| 層 | 回答 | 本包 |
|---|---|---|
| Coverage | 此堂由哪個 pool 覆蓋？held／consumed／released… | **定義需要**；實作 ≥ Phase 3 |
| Attendance | 人有沒有來？ | non-scope 改動 |
| Consumption | 是否扣堂？ledger event | **禁止**本包改動 |

**規則：** Materialization ≠ Consumption。建立 `ClassSession` **不得**直接扣堂。

---

## 4. 案例分類（產品必須分開處理）

| 代號 | 名稱 | 定義 | 正確反應 |
|---|---|---|---|
| **FRP** | Fixed recurring prepaid | `ScheduleMode=count`（或等價預付）+ 有效固定時段 Commitment + remaining／pool>0 | Rolling materialize（目標 A）；缺堂 = materialization failure |
| **FLP** | Flexible prepaid | 有餘額／pool，但無固定 Commitment、亦無下一次 booking | 快速 booking／開堂；**不是**異常 |
| **SPM** | Shared-pool member course | `PackageID` 成員；消耗走 pool | Pool-level coverage；成員 UI 不顯示 pool balance |
| **MF** | Materialization failure | 有 Commitment，未來 7／28 天應有 occurrence 但無非取消 `ClassSession` | Reconcile／EnsureSessionHorizon；屬系統债 |
| **ES** | Entitlement shortage | 未來已排／應排堂次數 > pool available | 續課／分配決策；不是「缺 session」文案 |
| **SC** | Schedule conflict | 應建立但撞老師／學生同時段或其他合約 | reason code + exception queue；fail closed，不猜 |

另保留既有 #1062／#1152 語意：

- **Active stranded**：近期有上課史、remaining>0、無未來堂、且 cadence 可確認 → Track A 候選  
- **Dormant**：超過活躍窗 → 主任決策（#1152），**禁止**自動生成

---

## 5. 既有 `ForwardSessionGenerator`：可重用／缺口／不得擴張的假設

### 5.1 可重用（應保留為寫入與安全基礎）

| 能力 | 位置 | 決策 |
|---|---|---|
| 單一寫入權威 `ClassSessionMaterializationService::upsertSlot` | materializer | **必須**繼續作為唯一 production create 路徑 |
| Idempotency：`(StudentClassID, SessionDate, StartTime HH:MM)` + #957 unique | materializer | Horizon commands 必須同 key |
| Dry-run 預設；production execute 需閘門 | `sessions:generate-forward` | Phase 0／1 不啟用 execute |
| 不猜時段：無 confirmed cadence → skip | `planCourse` | 保留 fail-closed 精神 |
| Cross-SC 同時段衝突 skip + log | `cross_sc_slot_conflict` | 納入 reason codes |
| Note 標記可稽核／可回收 | `系統向前生成 #1062` | 後續 provenance 應比 Note 字串更結構化，但過渡期可並存 |
| Horizon cap（目前週數／remaining） | plan | 改以「維持至 through_date」語意進化，仍需 cap |

### 5.2 缺口（相對本決策目標）

| 缺口 | 說明 |
|---|---|
| Commitment 來源 | 從「近 6 堂 history 多數決」推 cadence，**不是**顯式 Schedule Commitment；與合約 `week/time`／`week1–6` 未統一 |
| 只處理「完全無未來堂」 | `hasUpcoming` 即 skip；**不**維持 rolling 28 天滿水位（有 1 堂未來也会停） |
| 無 pool-level allocator | 以單課 `RemainingSessions` cap；shared package 可能錯用成員餘額語意 |
| 無 preview command／UI | 僅 artisan dry-run 文字 |
| 無 structured reason codes | skip reason 為自由文字 |
| 無 occurrence identity 獨立於 row | 依賴 upsert 自然鍵 |
| 無 coverage lifecycle | 生成即 scheduled，不區分 covered／uncovered |
| 不分 FRP／FLP | 無 commitment 的彈性包堂與「缺物化」混在 stranded 集合外圍 |
| Teacher／Subject／Campus | 建立時多依賴 course row／materializer 預設；plan 輸出未顯式稽核四要素完整性 |

### 5.3 不得直接擴張的假設（紅線）

1. **不得**把「近 6 堂多數決 cadence」升級成永久 Schedule Commitment SSOT，卻不經 Phase 0 驗證其與合約欄位一致性。  
2. **不得**在 generator 內呼叫扣堂／改 `RemainingSessions`／改 payment。  
3. **不得**對 dormant（#1152）或 cadence 不確認的課 `best-effort` 猜時段。  
4. **不得**按「pool 剩 N → 每個 member 各排 N」。  
5. **不得**為了讓 rolling 滿水位，在衝突時改寫已存在的 leave／substitute／調課 instance。  
6. **不得**在本決策包階段把 `--execute` 排進 Kernel 或對 production 啟用。  
7. **不得**另開第二條 insert 路徑繞過 `upsertSlot`。

---

## 6. Schedule Commitment 載體：先評估既有資料，不預設新表

### 6.1 候選載體（Phase 0 必須裁決）

| 候選 | 現況 | 可承擔 Commitment？ |
|---|---|---|
| **A. `StudentClass` 契約欄**（`week`/`time`、`week1–6`/`time1–6`、`TeacherID`、`ScheduleMode`、`PackageID`、`Stop`…） | 合約／排課意圖主要落點；月結與部分 count 課使用 | **可能**作為 FRP commitment 主載體，若 Phase 0 證明欄位完整率足夠、且與真實上課 cadence 一致 |
| **B. `schedules` 表** | 多用於例外：leave／substitute／extra 補課等；與 snake_case 新表並存 | **不適**當 recurring commitment SSOT；可當 exception／單次 booking 補充 |
| **C. History-inferred cadence**（FSG 現況） | 從 `ClassSession` 統計 | 只宜作 **fallback signal** 或 Phase 0 對帳，不宜當長期 SSOT |
| **D. 新 domain table**（例如 `schedule_commitments`） | 無 | **僅當** A+B 無法表達 pause、horizon policy、pool 綁定、多段 recurrence 時，才提最小 schema |

### 6.2 Phase 0 對載體的裁決問題（必須回答）

1. 活躍預付課中，`week/time` 或 `week1–6` 完整且與近 6 堂實際 cadence 一致的比例？  
2. 不一致時，以合約欄還是 history 為準會傷害現場？（預設：**fail closed → 人工／主任**，不自動選邊）  
3. Shared package 成員是否共享同一 recurrence，或各有各的 Commitment？  
4. Flexible prepaid 是否應允許「只有 pool、沒有 Commitment」長期存在？（本決策：**允許**，屬 FLP）  
5. 若 A 可覆蓋 ≥ 目標族群（建議：活躍 FRP 的可機器生成子集），則 **不新增表**，以 read-model／command 把 `StudentClass` 契約欄 **詮釋為** Commitment。  
6. 若 A 無法表達必要語意（例如多段有效期、explicit `auto_maintain_horizon`、pool 綁定歧義），再提 **最小** schema 增量 + `down()` 風險（另 DBA gate；本包不寫 migration）。

### 6.3 本包結論（刻意不鎖死）

> **不預先承諾新增 domain table。**  
> Schedule Commitment 先是 **必備語意**；實體載體由 Phase 0 證據在 A／D 之間選擇（B／C 不得單獨當 SSOT）。

---

## 7. 產品方案定位（回顧；本包只鎖 Phase 0–1）

| 方案 | 角色 | 本包 |
|---|---|---|
| **A** Rolling horizon 自動物化 | 目標主方案 | 定義；實作 ≥ Phase 2 |
| **B** 點名時「建立本次並繼續」 | 安全網 | 定義邊界；實作 ≥ Phase 4 |
| **C** 純虛擬 projected 當主真相 | **不採用** | 禁止 |
| **D** 一鍵 preview／補齊 | 過渡／containment | Phase 1 **只定義 command**，不實作 |

---

## 8. Authoritative handlers、Idempotency、Provenance、Reason codes、Branch isolation

### 8.1 具名 commands（ADR-005 擴充候選；名稱穩定）

| Command | Intent | Phase |
|---|---|---|
| `PreviewSessionHorizon` | 預覽將建立／跳過的 occurrences | 1（定義）→ 實作另 PR |
| `EnsureSessionHorizon` | 將 Commitment 物化至 `through_date`（idempotent） | 1 定義；寫入實作另 PR + GO |
| `CreateSessionFromCommitment` | 單筆 occurrence → `ClassSession` | 被上兩者呼叫 |
| `AllocateSessionCoverage` / `ReleaseSessionCoverage` | pool coverage lifecycle | ≥ Phase 3 |
| `RecordAttendance` / `ConsumeCoveredEntitlement` | 點名／扣堂 | **本包 non-scope** |

前端／脚本只傳：

- `student_class_id` 或 `schedule_commitment_ref`（載體決定後）
- `through_date` 或 `horizon_days`
- 使用者明確選擇（若多 pool 歧義——但本包禁止自動選，見 non-scope）

**禁止**前端傳：自算剩餘堂數、自選 pool balance、自推既有 session 狀態、自算老師／分校真相。

### 8.2 Authoritative handler 職責

單一 application service（建議演進自 `ForwardSessionGenerator`，而非平行第二引擎）負責：

1. 解析 Commitment（或判定 FLP／缺 commitment）  
2. 展開 projected occurrences（純函數、可單測）  
3. 權限 + **branch isolation**（`Student.CampusID`／`auth_campus_ids`；跨校 fail closed）  
4. Collision／cross-SC／缺欄位檢查 → reason codes  
5. Dry-run 報告或經 `upsertSlot` 寫入  
6. Audit／provenance  
7. **不**呼叫扣堂、繳費、ledger

### 8.3 Idempotency key

| 層 | Key |
|---|---|
| Row create | `(StudentClassID, SessionDate, StartTime HH:MM)` — 與 `upsertSlot`／#957 一致 |
| Occurrence（邏輯） | `(commitment_ref, occurrence_date, start_hhmm)`；重跑 Ensure 必須 0 新增 |
| Command | `(command_name, commitment_ref, through_date, request_id?)` 供審計；不替代 row key |

### 8.4 Provenance（每筆新建 ClassSession）

最少記錄（欄位或 structured Note／audit 表，實作另定）：

- `generation_source`：`preview_apply`｜`ensure_horizon`｜`forward_generator_1062`｜`manual_add`｜…  
- `actor`：user id 或 `system:reconcile`  
- `commitment_ref`  
- `occurrence_key`  
- `branch_id`／campus  
- `pool_id`（若有；可空）  
- `command` + 時間戳  

### 8.5 Reason codes（穩定字串；Phase 0 報告與 Phase 1 preview 共用）

| Code | 含義 |
|---|---|
| `OK_PLAN` | 將建立／可建立 |
| `OK_EXISTS` | 已有非取消 ClassSession（idempotent no-op） |
| `SKIP_NO_COMMITMENT` | 有餘額但無可用 Commitment（FLP 或資料不足） |
| `SKIP_NOT_COUNT_MODE` | 非預付 count 路徑 |
| `SKIP_STOPPED` | 合約 Stop／終止 |
| `SKIP_DORMANT` | 活躍窗外（#1152） |
| `SKIP_NO_CADENCE` | 無法確認 weekday+time（現 FSG） |
| `SKIP_INSUFFICIENT_HISTORY` | 歷史不足以推導（若仍用 history fallback） |
| `SKIP_MISSING_TEACHER` | 無法唯一確定老師 |
| `SKIP_MISSING_SUBJECT` | 缺科目 |
| `SKIP_MISSING_BRANCH` | 缺分校／campus |
| `SKIP_MISSING_SLOT` | 缺時段 |
| `SKIP_AMBIGUOUS_POOL` | 多個 eligible pool，禁止自動選 |
| `SKIP_POOL_SHORTAGE` | ES：可建 session 但 coverage 不足（preview 可標 uncovered，不假裝有餘額） |
| `SKIP_CROSS_SC_CONFLICT` | 同學生他約同時段 |
| `SKIP_TEACHER_CONFLICT` | 老師同時段衝突（若檢測） |
| `SKIP_OUT_OF_HORIZON` | 超出 through_date |
| `SKIP_REMAINING_CAP` | 受單課 remaining 或政策 cap（過渡期；shared 應升 pool 規則） |
| `FAIL_BRANCH_ISOLATION` | 呼叫者無權該校 |

每個 skipped occurrence **必須**有 code；禁止只回「成功建立 N 堂」而吞掉 skip。

### 8.6 Branch isolation

- 所有讀寫以課程所屬學生 `CampusID`（或等價）為準。  
- Preview／Ensure 的 `branch_id` filter 與 #1062 command 一致。  
- 跨校 commitment／pool：**403／FAIL_BRANCH_ISOLATION**，不得降級為 skip 後仍部分寫入他校。

---

## 9. Phase 0 — 唯讀分類與指標（本包要求；執行另 PR）

### 9.1 約束

- **只讀** production／replica／核准之唯讀探針；**不寫** production DB  
- 不改扣堂、繳費、ledger  
- 不 activate generator execute  
- 輸出：分類計數 + reason 分布 + FSG 能力矩陣（可 JSON／markdown artifact）

### 9.2 報告必須回答的七題

1. **有正餘額且有明確 Commitment，但未來 7／28 天缺 ClassSession 的數量**（MF；按分校／是否 package 切）  
2. **有正餘額但沒有可用 Commitment 的數量**（FLP 或資料不足；與 MF 分開）  
3. **Shared pool 下「未來排課需求」與「pool coverage」差額**（ES；pool 層彙總，不拆成假 member balance）  
4. **無法生成的原因分布**（§8.5 reason codes）  
5. **目前 `ForwardSessionGenerator` 能處理與不能處理的案例**（對照 §5）  
6. **哪些案例若自動生成會涉及猜測**老師、科目、分校、時段或 pool（列出禁止自動集合）  
7. **最近人工補排頻率與距離上課時間**（近似：未來窗內新建 `ClassSession` 且非 `#1062` Note／非系統來源的建立時間 vs `SessionDate`+`StartTime`；定義寫進報告 header）

### 9.3 建議分類桶（與 §4 對齊）

| Bucket | 對應 |
|---|---|
| `frp_healthy` | FRP + 7／28 天內 session 充足 |
| `frp_materialization_gap` | FRP + MF |
| `flp_no_commitment` | 正餘額 + 無 Commitment |
| `shared_pool_shortage` | SPM + ES |
| `conflict_blocked` | SC |
| `ambiguous_or_guess_required` | 缺老師／科目／分校／時段／多 pool |
| `fsg_eligible_active_stranded` | 現行 Track A 會 `plan` 的集合 |
| `fsg_skipped_*` | 現行 skip reason 映射到 §8.5 |
| `dormant_1152` | #1152 |

### 9.4 Commitment 判定（Phase 0 操作定義；可修訂）

**明確 Commitment（暫訂，需在報告中公佈命中規則版本）：**

- 合約未 Stop，且  
- （合約 `week/time` 或任一 `weekN/timeN` 可解析出唯一 weekday+start）**或**（FSG 同款 confirmed cadence：近 6 堂 ≥2 同 weekday+HH:MM），且  
- TeacherID、StudentID→CampusID、科目可解析  

若合約欄與 history cadence **衝突** → 歸 `ambiguous_or_guess_required`，不得算入可自動生成。

### 9.5 Phase 0 Exit

- 七題皆有數字或「無法量測 + 原因」  
- 載體裁決建議：A 足夠／需 D 最小 schema／需先修資料  
- **仍不**寫 production、不實作 Phase 1 UI

---

## 10. Phase 1 — Preview／補齊：只定義行為，不實作

### 10.1 `PreviewSessionHorizon`

**Input（intent only）：** `student_class_id`（或 commitment_ref）、`through_date`  
**Behavior：**

- 解析 Commitment；失敗 → 結構化錯誤（FLP vs missing fields）  
- 展開 occurrences → 逐筆 reason code  
- 彙總：will_create／already_exists／skipped_by_code  
- Shared pool：在 **pool 層**附 coverage 預估（covered／uncovered）；**不**在成員上顯示「剩餘 N 堂」  
- 宣告：**不會扣堂**

**Output：** dry-run DTO（供未來主任 UI／artisan）；本包不實作 endpoint。

### 10.2 `EnsureSessionHorizon`（行為契約；本包不啟用寫入）

- 與 Preview **同一 expansion／同一 reason 規則**  
- 僅對 `OK_PLAN` 呼叫 `upsertSlot`  
- 重跑 → 0 新增  
- 衝突／歧義 → skip + code，不以 best-effort 猜  
- Provenance 必填  
- Production 啟用另需：測試 + CEO／owner GO（延續 #1062 PCR 精神）

### 10.3 UI 文案方向（非實作）

> 未來 28 天預計 4 堂，已建立 1 堂，缺 3 堂。  
> ［預覽並補齊］→ 列出將建立／跳過原因 → 確認後才 Ensure。

成員課程：**「本課程已排 N；其中 M 由共用方案覆蓋」**；禁止「本課程剩餘 pool N 堂」。

### 10.4 Phase 1 Exit（定義完成標準）

- Command 輸入／輸出／error／reason codes 已寫進本 ADR  
- 與 ADR-005 client 邊界相容  
- **無** production code／migration／UI 在本決策包 PR 內落地  

---

## 11. 後續 Phase（僅路線圖；非本包承諾時程）

| Phase | 內容 | 閘門 |
|---|---|---|
| 2 | A：rolling materializer shadow → 自動寫入 | Phase 0 證據 + Preview／Ensure 實作綠 + GO |
| 3 | Pool-level coverage lifecycle | 不重寫 consumption；只標記 |
| 4 | B：點名 fallback（嚴格唯一性條件） | 否則升級主任 |
| 5 | 高風險整合（attendance orchestration、hold→consume、leave transfer） | 另 SEC／owner gate |

---

## 12. Non-scope（本決策包 + Phase 0／1 定義階段）

- 不改扣堂（`SessionDeductionService`／approval 扣減等）  
- 不改繳費／Invoice／Payment  
- 不改 consumption ledger  
- 不自動選 ambiguous pool  
- 不寫 production DB（含 generator `--execute`）  
- 不做 production activation／Kernel 排程啟用  
- 不把 projected session 建成第二套 truth  
- 不把成員課程顯示成擁有 pool balance  
- 不刪除並重建既有未來 ClassSession 以「重套模板」  
- 不在本包新增 migration／domain table（僅允許 Phase 0 **建議**最小增量）  
- 不實作點名頁 fallback（B）與 coverage 寫入（Phase 3+）

---

## 13. 反模式（禁止）

1. pool 剩 N → 每個 member 各排 N  
2. 成員 UI 顯示 pool 剩餘為「本課程剩餘」  
3. ClassSession 建立即扣堂  
4. 點名 silently invent session 並扣款  
5. 只靠 entitlement 推導時間／老師／分校／科目  
6. 所有預付課都當固定 recurring（忽略 FLP）  
7. projected 與 ClassSession 兩套不穩定 identity  
8. 改 recurrence 後刪光重建未來堂（摧毀 leave／調課／代課）  
9. 夜間 job 對不確定資料 best-effort 猜測  
10. 忽略 skipped／conflicted 卻回報成功  
11. 前端計算 pool coverage 或決定扣哪個 pool  
12. 「未來沒堂次」一律當資料錯誤（未先分 FLP／MF／ES／SC／paused）

---

## 14. 建議 Target UX（產品原則；非已上線）

- **FRP：** 設定一次固定時段 + 自動維持未來 28 天 ClassSession；續課加 entitlement；老師直接點名  
- **SPM：** 方案頁 pool-level：剩餘／已排／已覆蓋／未覆蓋；成員只顯示本課已排與覆蓋數  
- **FLP：** 餘額在、無未來堂 = 正常；用快速 booking  
- **異常：** Commitment 缺失／MF／ES／Conflict 分流文案與主任 queue  

---

## 15. 驗證與回滾（文件階段）

**Verification**

- [ ] 本檔合入 `main` 後 INDEX 可發現  
- [ ] 狀態列僅「ready for review」  
- [ ] 無 deployable runtime 行為變更（docs-only）  
- [ ] 與 #1062 PCR、ADR-005、G-010、F4 連結可點  

**Rollback**

- revert 本 ADR commit + 還原 INDEX／CHANGELOG 一行；無 schema／runtime

---

## 16. Review 時請 Founder／Reviewer 拍板的問題

1. Phase 0 Commitment 操作定義（§9.4）是否接受「合約欄優先、與 history 衝突則人工」？  
2. Rolling horizon 預設 28 天是否鎖定？  
3. Shared pool 在 Preview 階段是否允許建立 `uncovered` ClassSession，或 ES 時整批停止？  
4. Phase 0 通過後，是否允許 **不新增表** 直接把 `StudentClass` 契約欄當 Commitment 載體進入 Phase 1 實作？  

---

## 17. 最終陳述

AllTrue 預付／共用方案的合理目標架構是：

**Schedule Commitment → rolling ClassSession materialization → pool-level coverage → Attendance → Consumption ledger**

不是：

**Entitlement balance → 猜測未來課程 → 直接扣堂**

本文件是 **decision package ready for review**。  
Phase 0 唯讀報告與 Phase 1 command 實作均需另 PR；皆不在本包宣告完成。
