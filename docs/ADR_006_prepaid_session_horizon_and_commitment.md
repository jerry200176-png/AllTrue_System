# ADR-006 — Prepaid Session Horizon：Schedule Commitment × Materialization × Pool Coverage

> **Status:** **Accepted — tools implemented / merged through Phase 3A; production not activated**  
> （**Not production-ready / not runtime-enabled / not operationally accepted**）  
> **Founder review:** ACCEPT WITH AMENDMENTS（2026-07-28；PR #1468）；runtime acceptance AMENDMENTS 2026-07-28  
> **Date:** 2026-07-28  
> **Type:** Architecture + product decision package + implementation pointers  
> **Related:** #1062 Track A（`docs/runbooks/1062-track-a-pcr.md`）、`ADR_005_scheduling_named_command_boundaries.md`、G-010、F4／#1465、`ForwardSessionGenerator`、`ClassSessionMaterializationService`

> **2026-09-04 product amendment:** For multi-subject shared packages, the
> shared-pool shortage behavior in §10.2 is superseded by §10.5 below. Future
> plans are non-exclusive projections; they may be materialized as planned
> `ClassSession` rows without ledger deduction or cross-subject blocking.

---

## 0. 本文件完成狀態（強制）

| 可宣稱 | 不可宣稱 |
|---|---|
| Accepted；Phase 0–3A **工具已實作並 merge**（report／preview／ensure default-off／shadow／coverage planner） | Production-ready／runtime enabled／operationally accepted |
| Founder 四題已拍板（§16）；dormant ≠ auto Ensure | Phase 0 production 數字已產出並 ops 驗收 |
| Ensure production hard-block + feature flag default off | Ensure／coverage／Kernel 已 production 啟用 |
| `StudentClass` = **v1 adapter（條件式）**，非永久 SSOT | `session_coverages` migration 已 merge／已 migrate（見 #1483，需 GO） |
| 下一技術範圍 = 驗收修正 + Phase 3B merge-ready（仍需 GO） | production DB write、扣堂、繳費、coverage mutation、migrate --force |

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
| **FRP** | Fixed recurring prepaid | 預付 + **`explicit_commitment`** + remaining／pool>0 | Rolling materialize（目標 A）；缺堂 = MF |
| **FLP** | Flexible prepaid | 有餘額／pool，刻意無固定 Commitment（`INFO_FLEXIBLE_NO_COMMITMENT`） | 快速 booking／開堂；**不是**異常 |
| **SPM** | Shared-pool member course | `PackageID` 成員；消耗走 pool | Pool-level coverage；成員 UI 不顯示 pool balance |
| **MF** | Materialization failure | **`explicit_commitment`** 且未來 7／28 天應有 occurrence 但無非取消 `ClassSession` | Ensure／reconcile；**不含** legacy_inferred |
| **ES** | Entitlement shortage | 未來應排堂次數 > pool available | Preview 顯示 uncovered；Ensure → `BLOCK_POOL_SHORTAGE` |
| **SC** | Schedule conflict | 應建立但撞老師／學生同時段或其他合約 | reason code + exception queue；fail closed，不猜 |

另保留既有 #1062／#1152 語意：

- **Active stranded（FSG）**：可能落在 `explicit_commitment` **或** `legacy_inferred_candidate`——Phase 0 必須切開；僅前者可進自動 Ensure  
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

1. **不得**把「近 6 堂多數決 cadence」升格為 `explicit_commitment` 或永久 SSOT；最多標為 `legacy_inferred_candidate`。  
2. **不得**在 generator 內呼叫扣堂／改 `RemainingSessions`／改 payment。  
3. **不得**對 dormant（#1152）、`commitment_conflict`、或欄位不完整的課 `best-effort` 猜時段。  
4. **不得**按「pool 剩 N → 每個 member 各排 N」。  
5. **不得**為了讓 rolling 滿水位，在衝突時改寫已存在的 leave／substitute／調課 instance。  
6. **不得**在本決策包／Phase 0 階段把 `--execute` 排進 Kernel 或對 production 啟用。  
7. **不得**另開第二條 insert 路徑繞過 `upsertSlot`。  
8. **不得**在 Phase 1 Ensure 建立 **uncovered** scheduled `ClassSession`（coverage lifecycle 尚未存在）。  
9. **不得**在 shared-pool entitlement shortage 時 partial write。

---

## 6. Schedule Commitment 載體：先評估既有資料，不預設新表

### 6.1 候選載體（Phase 0 必須裁決）

| 候選 | 現況 | 可承擔 Commitment？ |
|---|---|---|
| **A. `StudentClass` 契約欄**（`StartDate`/`EndDate`、`week`/`time`、`week1–6`/`time1–6`、`TeacherID`、`SubjectID`、`ScheduleMode`、`PackageID`、`Stop`、`RemainingSessions`…） | 合約／排課意圖主要落點 | **Founder：條件式批准作 v1 Commitment adapter**；**不是**永久 SSOT；僅 `explicit_commitment` 子集可進 Phase 1 Ensure |
| **B. `schedules` 表** | 多用於例外：leave／substitute／extra 補課等 | **不適**當 recurring commitment SSOT；可當 exception／單次 booking 補充 |
| **C. History-inferred cadence**（FSG 現況） | 近 ≤6 堂多數決 weekday+start+end | **僅** `legacy_inferred_candidate` signal；Phase 0 統計、Phase 1 供主任確認；**不得**直接 rolling／Ensure |
| **D. 新 domain table**（例如 `schedule_commitments`） | 無 | 見 §6.5 觸發條件；本包不寫 migration |

### 6.2 Phase 0 對載體的裁決問題（必須回答）

1. 活躍預付課中，屬 `explicit_commitment`／`legacy_inferred_candidate`／`commitment_conflict` 的比例？  
2. `explicit_commitment` 子集是否足以支撐 Phase 1 Ensure 試點？  
3. Shared package 成員是否各有獨立 recurrence，或需 pool-level expansion？  
4. Flexible prepaid（`INFO_FLEXIBLE_NO_COMMITMENT`）數量——確認屬合法狀態而非資料缺陷  
5. 是否已觸發 §6.5 任一「必須新表」條件？若否，Phase 1 維持 StudentClass adapter  

### 6.3 Founder 載體結論（2026-07-28）

> **條件式批准** Phase 1 使用 `StudentClass` 作 **v1 Commitment adapter**。  
> **不批准**其為永久 Commitment SSOT；**現在不新增欄位／表**。  
> History cadence **不得**升格為正式 Commitment。

### 6.4 v1 adapter：`commitment_snapshot`／`commitment_fingerprint`（必做）

即使不新增表，每次 `PreviewSessionHorizon`／`EnsureSessionHorizon` **必須**計算並寫入 provenance（Ensure 成功建立之 session；Preview 回傳 DTO）：

| 欄位 | 用途 |
|---|---|
| `commitment_snapshot` | 當次解析後的規範化契約內容（供審計閱讀） |
| `commitment_fingerprint` | 穩定 hash，回答「這筆 session 依哪一版契約產生」 |

Fingerprint **至少**涵蓋（含 normalization／schema version）：

- `StudentClassID`
- relevant `week`/`time` 與 `week1–6`/`time1–6` pairs
- `StartDate`／`EndDate`
- teacher、subject、campus
- `ScheduleMode`、`PackageID`、`Stop`
- `schema_version`／normalization version

目的：主任日後改 `week/time` 後，仍能證明舊 session 當初依據的 Commitment 內容——**不是**第二套 SSOT。

**Phase 1 Ensure 允許條件**（皆須成立，否則不得自動寫入）：

- 時段可唯一解析  
- 老師、科目、分校完整  
- `StartDate`／`EndDate`／`Stop` 語意一致  
- 合約欄未與近期 history 衝突（= `explicit_commitment`）  
- shared pool 關係可唯一解析（否則 `SKIP_AMBIGUOUS_POOL`／`BLOCK_*`）  
- 不需要 effective-dated 多版本 recurrence  
- 不需要獨立 per-commitment horizon policy（horizon 走 server policy，§8.7）

### 6.5 觸發最小 `schedule_commitments` schema 的條件

Phase 0 或 Phase 1 若需要以下**任一**能力，再另提最小 schema + DBA gate（本包不寫）：

- effective-dated schedule versions  
- 未來某日才生效的換時段  
- 多段 pause／resume  
- 與 `StudentClass` lifecycle 不同的 commitment lifecycle  
- 每個 commitment 獨立 horizon policy  
- pool binding 歧義無法在 adapter 內唯一解析  
- 同一課程存在多個不能由 `week1–6` 安全表達的承諾

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
- `commitment_ref`（v1 = `StudentClassID` + adapter class）  
- `commitment_snapshot`／`commitment_fingerprint`（§6.4；**必填**）  
- `occurrence_key`  
- `branch_id`／campus  
- `pool_id`（若有；可空）  
- `coverage_intent`：Preview 可標 `covered`｜`uncovered`；Ensure Phase 1 **僅**寫入原標 `covered` 且通過 pool gate 者  
- `command` + 時間戳  

### 8.5 Reason codes（穩定字串；Phase 0 報告與 Phase 1 共用）

**層級約定**

| 前綴 | 含義 |
|---|---|
| `OK_*` | occurrence 可建立或已存在 |
| `INFO_*` | 合法狀態資訊（非錯誤） |
| `LEGACY_*` | 需主任確認的 legacy 候選；不得自動 Ensure／rolling |
| `SKIP_*` | 單一 occurrence 跳過（其餘 occurrence 仍可繼續評估） |
| `BLOCK_*` | **command-level** 安全阻擋：整批 no-write，禁止 partial write |
| `FAIL_*` | 權限／隔離失敗 |

| Code | 含義 |
|---|---|
| `OK_PLAN` | 將建立／可建立（且 Preview 標 covered；Ensure 才可寫） |
| `OK_EXISTS` | 已有非取消 ClassSession（idempotent no-op） |
| `OK_PREVIEW_UNCOVERED` | Preview 專用：occurrence 在 horizon 內但 pool 無法覆蓋（**不**表示可 Ensure） |
| `INFO_FLEXIBLE_NO_COMMITMENT` | 合法 FLP：有餘額／pool、刻意無固定 Commitment |
| `BLOCK_COMMITMENT_INCOMPLETE` | 本應可解析固定課，但合約欄缺老師／科目／分校／時段等 |
| `BLOCK_COMMITMENT_CONFLICT` | 合約欄與 history 不一致，或多組合約欄互相矛盾 |
| `LEGACY_INFERRED_CANDIDATE` | 合約欄缺失，但近期 history 有穩定 cadence；僅統計／主任確認 |
| `SKIP_NOT_COUNT_MODE` | 非預付 count 路徑 |
| `SKIP_STOPPED` | 合約 Stop／終止 |
| `SKIP_DORMANT` | 活躍窗外（#1152） |
| `SKIP_MISSING_TEACHER` | 無法唯一確定老師 |
| `SKIP_MISSING_SUBJECT` | 缺科目 |
| `SKIP_MISSING_BRANCH` | 缺分校／campus |
| `SKIP_MISSING_SLOT` | 缺時段 |
| `SKIP_AMBIGUOUS_POOL` | 多個 eligible pool，禁止自動選 |
| `BLOCK_POOL_SHORTAGE` | Shared-pool ES：**command-level**；Ensure 對該 pool scope 整批 no-write（見 §10.2） |
| `SKIP_CROSS_SC_CONFLICT` | 同學生他約同時段 |
| `SKIP_TEACHER_CONFLICT` | 老師同時段衝突（若檢測） |
| `SKIP_OUT_OF_HORIZON` | 超出 through_date |
| `SKIP_PAST_END_DATE` | 超過合約 `EndDate` |
| `SKIP_REMAINING_CAP` | 非 shared 過渡 cap（單課 remaining）；shared 路徑改走 pool gate |
| `FAIL_BRANCH_ISOLATION` | 呼叫者無權該校 |

**已廢止（不得再使用）：** `SKIP_NO_COMMITMENT`、`SKIP_POOL_SHORTAGE`（改由上表拆分／升級）。

每個 skipped／blocked／info 項 **必須**有 code；禁止只回「成功建立 N 堂」而吞掉 skip。`BLOCK_*` 不得降級成普通 `SKIP_*`。

### 8.6 Branch isolation

- 所有讀寫以課程所屬學生 `CampusID`（或等價）為準。  
- Preview／Ensure 的 `branch_id` filter 與 #1062 command 一致。  
- 跨校 commitment／pool：**403／FAIL_BRANCH_ISOLATION**，不得降級為 skip 後仍部分寫入他校。

### 8.7 Rolling horizon policy（v1）

Founder 批准 **28 天**為 v1 **server-side default**，**不是**永久 domain invariant，也**不**寫入每筆 `StudentClass`。

| 規則 | 定義 |
|---|---|
| Default | `through_date = Asia/Taipei today + 28 days`（**inclusive**） |
| Command intent | client 可傳「補齊至某日」；**不得**自定 recurrence truth |
| Server 仍受 | `EndDate`、`Stop`、branch permission、commitment validity（僅 `explicit_commitment` 可 Ensure）、entitlement／pool coverage gate、collision checks |
| Phase 0 指標 | 產出 **7 天與 28 天**缺口即可；暫不引入 14／60／90 多組政策 |

---

## 9. Phase 0 — 唯讀分類與指標（本包要求；執行另 PR）

### 9.1 約束

- **只讀** production／replica／核准之唯讀探針；**不寫** production DB  
- 不改扣堂、繳費、ledger  
- 不 activate generator execute  
- 輸出：分類計數 + reason 分布 + FSG 能力矩陣（可 JSON／markdown artifact）

### 9.2 報告必須回答的七題

1. **有正餘額且屬 `explicit_commitment`，但未來 7／28 天缺 ClassSession 的數量**（MF；**不得**把 `legacy_inferred_candidate` 算進此題）  
2. **有正餘額但無可用 explicit commitment 的拆分數量**：`INFO_FLEXIBLE_NO_COMMITMENT` vs `BLOCK_COMMITMENT_INCOMPLETE` vs `LEGACY_INFERRED_CANDIDATE` vs `BLOCK_COMMITMENT_CONFLICT`（禁止再合併成單一「沒有 Commitment」）  
3. **Shared pool 下「未來排課需求」與「pool coverage」差額**（ES；**pool 層**彙總；列出若 Ensure 會觸發 `BLOCK_POOL_SHORTAGE` 的 pool 數）  
4. **無法生成／阻擋的原因分布**（§8.5；含 `BLOCK_*`／`INFO_*`／`LEGACY_*`）  
5. **目前 `ForwardSessionGenerator` 能處理與不能處理的案例**（對照 §5；並標出 FSG plan 集合落在三類 Commitment 的哪一類）  
6. **哪些案例若自動生成會涉及猜測**老師、科目、分校、時段或 pool（= 禁止自動集合：`commitment_conflict`、incomplete、ambiguous pool、legacy-only 等）  
7. **最近人工補排頻率與距離上課時間**（近似：未來窗內新建 `ClassSession` 且非 `#1062` Note／非系統來源的建立時間 vs `SessionDate`+`StartTime`；定義寫進報告 header）

### 9.3 建議分類桶（與 §4／§9.4 對齊）

| Bucket | 對應 |
|---|---|
| `explicit_healthy` | `explicit_commitment` + 7／28 天 session 充足 |
| `explicit_materialization_gap` | `explicit_commitment` + MF（**唯一**可進 Phase 1 Ensure 候選的缺口桶） |
| `legacy_inferred_candidate` | 合約欄缺失 + history 穩定 cadence |
| `commitment_conflict` | 合約↔history 或欄位自相矛盾 |
| `commitment_incomplete` | 固定課語意不足（缺老師／科目／分校／時段等） |
| `flexible_no_commitment` | 合法 FLP（`INFO_FLEXIBLE_NO_COMMITMENT`） |
| `shared_pool_shortage` | SPM + ES（shared package 以 renewal warning 呈現；不得阻擋其他科目預排） |
| `conflict_blocked` | SC |
| `fsg_eligible_active_stranded` | 現行 Track A 會 `plan` 的集合（需再切三類） |
| `fsg_skipped_*` | 現行 skip reason 映射到 §8.5 |
| `dormant_1152` | #1152 |

### 9.4 Commitment 判定（Founder 修正後操作定義）

**原則：** 顯式合約欄是 **authoritative candidate**；history 只負責 **驗證**與提供 **legacy signal**；兩者衝突 → **fail closed**，人工裁決。  
**禁止：** 「合約欄 **或** FSG history 任一成立 ⇒ 明確 Commitment」（舊 §9.4 已廢止）。

報告須公佈規則版本號。三類如下：

| 類別 | 判定 | Phase 0 | Phase 1 Ensure | Rolling automation（≥Phase 2） |
|---|---|---|---|---|
| **`explicit_commitment`** | 合約欄完整且唯一可解析（weekday+start 等）；Teacher／Subject／Campus 完整；`StartDate`/`EndDate`/`Stop` 一致；**未**與近期 history 衝突 | 計入 MF 題 1 | **可**（另受 pool gate） | **可**（另 GO） |
| **`legacy_inferred_candidate`** | 合約欄缺失／不可唯一解析，但近期 history 有穩定 cadence（FSG 同款：近 ≤6 堂 ≥2 同 weekday+start+end） | 統計；對應 `LEGACY_INFERRED_CANDIDATE` | **不可**自動；僅供主任確認後的人工／確認流（另設計） | **不可** |
| **`commitment_conflict`** | 合約欄與 history 不一致，或多組合約欄互相矛盾 | 統計；對應 `BLOCK_COMMITMENT_CONFLICT` | **不可** | **不可** |

補充：

- 合法彈性包堂 → `INFO_FLEXIBLE_NO_COMMITMENT`／bucket `flexible_no_commitment`（**不是** conflict，也**不是** MF）。  
- 本應固定卻缺欄 → `BLOCK_COMMITMENT_INCOMPLETE`（**不是** FLP）。  
- **不得**把 `legacy_inferred_candidate` 算進「有明確 Commitment 的 MF」，以免高估可自動修復範圍。

### 9.5 Phase 0 Exit

- 七題皆有數字或「無法量測 + 原因」  
- 三類 Commitment 計數齊備；MF 僅含 `explicit_commitment`  
- 載體建議：v1 adapter 試點範圍 vs 觸發 §6.5 新表  
- **仍不**寫 production、不實作 Phase 1 Ensure runtime  

**Implementation pointer（code；非 production evidence）：**

- Command：`php artisan sessions:report-prepaid-horizon-phase0 {--branch_id=} {--as-of=} {--json|--summary}`  
- Services：`App\Services\Scheduling\*`（classifier／expander／reporter）  
- Synthetic sample：`docs/artifacts/adr006-phase0-sample-report.json`  
- Production 數字 artifact 需在唯讀環境執行 command 後另存；**預設只交付可跑的 read-only 工具與 CI 測試。**

---

## 10. Phase 1 — Preview／補齊：只定義行為，不實作

### 10.1 `PreviewSessionHorizon`

**Input（intent only）：** `student_class_id`（v1 adapter）、可選 `through_date`（預設 §8.7）  
**Behavior：**

- 分類 Commitment（§9.4）；回傳 `commitment_snapshot`／`commitment_fingerprint`  
- `explicit_commitment`：展開 occurrences → 逐筆 code（含 covered／uncovered）  
- `legacy_inferred_candidate`：回傳 `LEGACY_INFERRED_CANDIDATE` + 建議 cadence（**不**假裝可 Ensure）  
- `commitment_conflict`／incomplete：`BLOCK_COMMITMENT_*`  
- 合法 FLP：`INFO_FLEXIBLE_NO_COMMITMENT`  
- Shared pool：**必須**在 pool 層顯示例如「未來 28 天預計 8 堂／可覆蓋 6／尚未覆蓋 2」；uncovered 用 `OK_PREVIEW_UNCOVERED`  
- **不**在成員課程顯示 pool 剩餘 N 堂  
- 宣告：**不會建立 session、不會扣堂**

**Output：** dry-run DTO；HTTP endpoint 非本階段必要。

**Implementation pointer（Phase 1A；read-only）：**

- Service：`App\Services\Scheduling\PreviewSessionHorizonService`
- Command：`php artisan sessions:preview-horizon {student_class_id} {--through=} {--as-of=} {--branch_id=} {--summary}`
- **不**寫 ClassSession；Ensure／production activation 另 PR + GO

### 10.2 `EnsureSessionHorizon`（行為契約；本包／下一 PR 皆不啟用 production 寫入）

> **Shared package 例外：** 本節原有的 shared-pool shortage gate 已由 §10.5
> supersede。shared package 的未來預排可物化為 recurring／scheduled，超過
> ledger 剩餘只回傳 renewal warning；本節的 `BLOCK_POOL_SHORTAGE`、整批
> no-write 與禁止建立 uncovered session 僅適用 standalone entitlement。

前置：僅 `explicit_commitment` + §6.4 允許條件。

**Standalone entitlement shortage（ES）：**

| 動作 | 行為 |
|---|---|
| Preview | **允許且要求**顯示全部 covered／uncovered 明細 |
| Ensure | 對該 **standalone scope** command **整批 no-write**；回傳 **`BLOCK_POOL_SHORTAGE`** |
| 禁止 | partial write；把 ES 當成普通 occurrence `SKIP_*`；建立 uncovered scheduled `ClassSession` |

**為何不做 covered-prefix（先建前 N 堂）：** Phase 1 尚無 pool-level expansion + deterministic ordering + transaction／locking + hold lifecycle + concurrency tests；兩 member 並行 Ensure 可能搶同一批餘額。Covered-prefix **留到 Phase 3+**。

**其他 Ensure 規則：**

- 與 Preview 同一 expansion 函數；Ensure 僅物化 Preview 標為 **covered** 且非 block 的 `OK_PLAN`  
- 經 `upsertSlot`；重跑 → 0 新增  
- Provenance 必含 fingerprint／snapshot  
- 衝突／歧義 → skip／block + code，不猜  
- Production 啟用另需：實作 PR + 測試 + owner／CEO GO（延續 #1062 PCR）

**Implementation pointer（Phase 1B；default-off）：**

- Service：`App\Services\Scheduling\EnsureSessionHorizonService`
- Command：`php artisan sessions:ensure-horizon {student_class_id} {--through=} {--as-of=} {--branch_id=} {--execute} {--summary}`
- Feature flag：`FEATURE_ENSURE_SESSION_HORIZON`（default **false**）；`--execute` 在 **production 硬擋**（`PRODUCTION_EXECUTE_REQUIRES_GO`）
- Dry-run 為預設；standalone ES → `BLOCK_POOL_SHORTAGE` 整批 no-write；shared package
  依 §10.5 允許預排並提示 renewal warning；寫入僅經 `ClassSessionMaterializationService::upsertSlot`

### 10.3 UI 文案方向（非實作）

> 未來 28 天預計 8 堂，可由方案覆蓋 6 堂，尚未覆蓋 2 堂。  
> ［預覽］→ shared package 仍可預排，但必須明確告知超排 2 堂並提示續約／加購；standalone entitlement shortage 則回傳阻擋原因。

成員課程：**「本課程已排 N；其中 M 由共用方案覆蓋」**；禁止「本課程剩餘 pool N 堂」。

### 10.4 Phase 1 Exit（定義完成標準）

- Command 輸入／輸出／error／reason codes／ES atomic block 已寫進本 ADR  
- 與 ADR-005 client 邊界相容  
- **無** production code／migration／UI 在本決策包 PR 內落地

### 10.5 2026-09-04 多科共用方案堂數模型修正（supersedes shared shortage gate）

本次需求將 shared package 分為 `purchased_entitlement`（購買總堂數）、
`actual_consumed`（正式扣堂 ledger 淨消耗）與 `future_planned_sessions`（未來預排投影）；
不新增 quota framework，也不按科目切額度。多科共用同一 entitlement，未來 recurring／
scheduled 可建立但不寫 ledger、不阻擋其他科；超過 remaining 時回傳 `renewal_warning`、
超排數及續約／加購文案，允許排課且不得靜默放行。群組方案同一日期＋開始時間只計一個
實體時段，`leave_requested` 仍算預排意圖；取消、刪除、轉課、減購、單科與既有資料沿用
原 lifecycle／ledger 規則，本修正不做 production data repair 或 migration。
 

---

## 11. 後續 Phase（僅路線圖；非本包承諾時程）

| Phase | 內容 | 閘門 |
|---|---|---|
| 2 | A：rolling materializer shadow → 自動寫入 | Phase 0 證據 + Preview／Ensure 實作綠 + GO |
| 3 | Pool-level coverage lifecycle | 不重寫 consumption；只標記 |
| 4 | B：點名 fallback（嚴格唯一性條件） | 否則升級主任 |
| 5 | 高風險整合（attendance orchestration、hold→consume、leave transfer） | 另 SEC／owner gate |

**Phase 2 shadow tool（read-only）：** `sessions:shadow-horizon`。

**Phase 3A planner（read-only／default-off；無 coverage DB 寫入）：**

- States：`none` → `held` → `consumed`｜`released`（`SessionCoverageStateMachine`）
- Plan：`AllocateSessionCoveragePlanner`／`ReleaseSessionCoveragePlanner`／`PoolCoveragePlanService`
- Command：`php artisan sessions:plan-coverage {package_id}`
- **仍需 Founder GO：** coverage 持久化 migration merge／execute、coverage 寫入、consumption 整合、Kernel 啟用

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
- [ ] 狀態列區分「工具已 merge」vs「production 未啟用／未 ops 驗收」  
- [ ] 無 Ensure／coverage／Kernel production activation 被誤標為完成  
- [ ] §16 Founder decisions 與正文一致  
- [ ] 與 #1062 PCR、ADR-005、G-010、F4 連結可點  

**Rollback**

- revert 相關 implementation commits；migration 未進 main 前無 schema 回滾需求

---

## 16. Founder decisions（2026-07-28 — ACCEPT WITH AMENDMENTS）

| # | 題目 | 決策 |
|---|---|---|
| 1 | Commitment 判定 | **批准並修正分類**：合約欄 = authoritative candidate；history = 驗證／legacy signal；衝突 fail closed。三類 = `explicit_commitment`／`legacy_inferred_candidate`／`commitment_conflict`。Legacy **不得**計入「有明確 Commitment 的 MF」，也不得直接 Ensure／rolling。 |
| 2 | Rolling horizon 28 天 | **批准為 v1 server-side default**（Asia/Taipei today+28 inclusive）；非永久 invariant；不寫入每筆 `StudentClass`；client 可傳 through_date，不得自定 recurrence truth。Phase 0 產出 7／28 天缺口即可。 |
| 3 | Shared pool ES | **Preview 必須可顯示 uncovered**；shared package **允許建立未來預排／scheduled**，但必須回傳 `renewal_warning`、超排堂數與續約／加購文案；不得阻擋另一科目預排。Standalone shortage 仍依 §10.2 回傳 `BLOCK_POOL_SHORTAGE`。 |
| 4 | `StudentClass` 載體 | **條件式批准 v1 adapter**，**不批准**永久 SSOT；現不新增欄位。Ensure 僅 `explicit_commitment` + §6.4 條件（**含非 dormant**）。必算 `commitment_snapshot`／`commitment_fingerprint`。觸發新表條件見 §6.5。 |

**實作進度（2026-07-28）：** Phase 0–3A 工具已 merge；Ensure／coverage／migration **production 未啟用**。  
**仍需 Founder GO：** #1483 migration merge／migrate、coverage 寫入、Ensure production flag、Kernel。

---

## 17. 最終陳述

AllTrue 預付／共用方案的合理目標架構是：

**Schedule Commitment → rolling ClassSession materialization → pool-level coverage → Attendance → Consumption ledger**

不是：

**Entitlement balance → 猜測未來課程 → 直接扣堂**

本文件狀態：**Accepted — tools implemented/merged through Phase 3A; production not activated**。  
仍：**not production-ready / not runtime-enabled / not operationally accepted**。

Phase 3B `session_coverages` 與任何 production activation 另需 Founder GO。
