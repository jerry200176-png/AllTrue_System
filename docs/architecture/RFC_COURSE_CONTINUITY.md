# RFC: Course Continuity — 合約關聯與統一課程視圖

> **Status**: Draft（產品／架構決策包；**非** production execute）  
> **Issue**: [#1382](https://github.com/jerry200176-png/AllTrue_System/issues/1382)  
> **Date**: 2026-07-22  
> **Codename**: Course Continuity／課程延續

---

## 1. Problem

主任為同一學生建立「下一期／續報／試聽／平行課」時，系統只有：

- 加購（延續同一 `StudentClass`），或  
- generic force-create（另開一筆 `StudentClass`，無關聯、無審計語意）

結果：

- 多張合約在 UI 上像無關課程 → 續報決策成本高  
- future `ClassSession` 可能在舊約＋新約同時 materialize（#1130 歷史重複的來源之一）  
- 財務／評量／出席仍綁在各合約上，但主任沒有「群組視圖」

**原則**：合併**視圖與操作流程**，**不**物理合併財務、堂次、評量或歷史合約列。

---

## 2. Non-goals

| 不做 | 原因 |
|------|------|
| 實體 merge 兩筆 `StudentClass`（方案 D） | 破壞 billing／LR／attendance 歸戶；rollback 難 |
| 自動執行 #1130／#1134 keep/drop | 歷史修復另案；需 Founder GO |
| Entitlement pooling（跨約加總堂數） | 不同 rate_unit／package 不可自動加總 |
| 搬移 Payment／Invoice／Receipt／LR | MVP 只做關聯，不做歷史搬移 |

---

## 3. Architecture options

| | A. Group + members（**推薦**） | B. StudentClass parent／continuity id | C. Read-model heuristic | D. Physical merge |
|--|--|--|--|--|
| Billing correctness | ✅ 各合約保留自己的發票／付款 | ✅ 同左 | ⚠️ 無寫入真相 | ❌ 合併 Charge／Paid 易錯 |
| Session ownership | ✅ session 仍單綁一個 SC；routing 看 authoritative member | ⚠️ parent 語意易與 Stop／期間混淆 | ⚠️ 無 authoritative write path | ❌ 必須改寫 ClassSession.StudentClassID |
| LR／attendance history | ✅ 不搬 | ✅ 不搬 | ✅ 不搬 | ❌ 必須 re-home |
| Query complexity | MED（join group） | LOW–MED | HIGH（每次重算） | LOW（但資料已毀） |
| Migration risk | LOW（新表） | MED（回填 parent） | LOW | **HIGH** |
| Rollback | ✅ unlink members | ⚠️ null parent | ✅ | ❌ 幾乎不可逆 |
| Auditability | ✅ member.decision_reason／created_by | ⚠️ 需另表 | ❌ | ⚠️ 合併當下一次 |
| UX | ✅ 群組視圖自然 | ⚠️ 樹狀但難表達 parallel | ⚠️ 不穩定 | 表面簡單、底層危險 |
| Extensibility | ✅ renewal／replacement／trial／parallel | ⚠️ 單 parent 難表達多邊 | ❌ | ❌ |

### Decision

**採用 A**：`course_contract_groups` + `course_contract_group_members`。

**拒絕 D**：除非未來有獨立 Founder GO 的資料合併專案，且附完整 billing／LR／session 遷移計畫——不在本 epic。

B 可作過渡捷徑，但 parallel／trial 多對多與 audit 欄位最終仍會長成 A；一次到位較省。  
C 可作 **suggest-only** 輔助（高置信 cohort），不可作為 write-path 真相。

---

## 4. Schema contract（draft）

```sql
CREATE TABLE course_contract_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  campus_id INT NOT NULL,
  subject_id INT NULL,
  label VARCHAR(128) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ccg_student_campus_subject_active (student_id, campus_id, subject_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_contract_group_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  student_class_id BIGINT UNSIGNED NOT NULL,
  relation_type ENUM('original','renewal','replacement','trial','parallel') NOT NULL,
  effective_from DATE NULL,
  sequence INT NOT NULL DEFAULT 0,
  decision_reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ccgm_student_class (student_class_id),
  KEY idx_ccgm_group_seq (group_id, sequence),
  CONSTRAINT fk_ccgm_group FOREIGN KEY (group_id) REFERENCES course_contract_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Rules**

- `student_class_id` **unique** — 一合約至多屬於一個 group  
- 禁止跨學生／跨校區加入同一 group（API + DB check）  
- `replacement` 必須有 `effective_from`  
- unlink = soft delete member row 或 `unlinked_at`（實作階段再定；必須可逆且不刪 SC）

---

## 5. API contract（draft）

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/course-contract-groups?student_id=&branch_id=` | 群組列表（含 members 摘要：期間、剩餘、付款狀態） |
| POST | `/api/v1/course-contract-groups` | 建立 group + 初始 members |
| POST | `/api/v1/course-contract-groups/{id}/members` | 加入 renewal／replacement／trial／parallel |
| DELETE | `/api/v1/course-contract-groups/{id}/members/{member_id}` | 解除關聯（不刪合約） |
| GET | `/api/v1/course-contract-groups/suggestions?student_id=` | 高置信 auto-suggest only |

Auth：`role:director|admin|super_admin` + `require_campus`。  
Response **不**把內部 SC ID 當主任決策主欄位（可 drill-down 技術詳情）。

---

## 6. Materialization routing（與 #957／#1043）

| 規則 | 說明 |
|------|------|
| Authoritative member | 對每個 calendar day，group 內至多一個 member 可產生 future session（`replacement` 看 `effective_from`；`renewal` 在舊約 EndDate 之後） |
| Stop 舊約 future | 進入 replacement／renewal 生效後，舊約 future scheduled 由既有 close／reconcile 路徑停止；**禁止**僅靠 frontend dedupe |
| Race | DB unique slot（#957）+ enrollment／materialize 同 transaction 檢查 group authoritative member |
| Parallel | `relation_type=parallel` 明確允許同生不同 slot；availability／substitute 用 **student-level collision** guard，但不得取消合法 parallel |
| Trial | 可掛 group 為 `trial`；可與正式課重疊（#1379） |

---

## 7. MVP UX

### 7.1 建課決策（與 #1381 銜接）

偵測到可能相關合約時：加購／下一期續報並串接／替換＋生效日／獨立平行／試聽／取消。  
每選項顯示：是否新合約、堂數歸屬、future 從哪張產生、舊約是否 active、是否要生效日。

### 7.2 統一課程視圖

一「課程群組」：目前合約、前／下一期、各自付款／已用／剩餘／期間；歷史預設收合；可 drill-down 原合約。

### 7.3 Auto-suggest 門檻（高置信）

同學生 ∧ 同校區 ∧ 同科 ∧ non-package ∧ 相容計費 ∧ 明確時間先後 ∧ 無 attended 重疊 ∧ 無 unresolved billing divergence。  
其餘只顯示「可能相關」，必須人工決定。

---

## 8. Cohort discovery

唯讀 SQL 與指標定義見：[`course-continuity-cohort-discovery.sql`](./course-continuity-cohort-discovery.sql)  
執行結果（PII-free）記入：[`course-continuity-cohort-results.md`](./course-continuity-cohort-results.md)

**⛔** 結果不得含姓名、電話、請假原因或其他 PII。

---

## 9. Test matrix（實作 PR 必覆蓋）

Backend：renewal group、replacement effective_from、trial link、parallel、跨學生拒絕、campus scope、duplicate membership、idempotent retry、concurrency、unlink、payment／LR 不變、future materialize 只走 authoritative、count／hour／monthly／package guard。

Frontend：主任不需理解 SC ID；選項文案；無 generic force；390px；back／reload；grouped view；不錯誤加總 entitlement。

E2E：續報 → 關聯 → future 只在新約 → 舊約歷史／付款不變 → unlink 不刪資料 → parallel 可合法存在 → 跨校拒絕。

---

## 10. Founder GO gates

| 動作 | 需要 GO？ |
|------|-----------|
| Merge RFC／docs | 否（T0） |
| Schema migrate 空表 | 建議標註；低風險但仍走 deploy migrate |
| Historical backfill 自動連結 | **是** |
| #1130 repair execute | **是**（既有 gate） |
| 任何 Payment／LR 搬移 | **是**（本 epic 不做） |

---

## 11. Open questions

1. `replacement` 生效當日的 session 歸屬（舊／新／人工）？  
2. package 成員是否允許進入 Continuity group（MVP 建議排除）？  
3. suggest API 是否寫入「已忽略」以免重複打擾？
