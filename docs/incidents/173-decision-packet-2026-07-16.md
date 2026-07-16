# in-app #173 — 決策包（唯讀 · 2026-07-16）

> **狀態**：等待主任二選一；**尚未**改任何歷史堂次／評量／收費。  
> **識別**：in-app #173（campus_id=9）。Compare／CEO 回報勿寫學生姓名。  
> **對照**：既有 dry-run 列 #12（`docs/incidents/189-191-dryrun-report.md`）曾建議保留 SC#114、cancel session#16951 — 本包依**現行**合約與後續排課重核建議。

## 1. 兩門課程（舊 vs 續報新）

| | 舊課 SC#114 | 續報新課 SC#2076 |
|---|---|---|
| 科目／老師 | SubjectID 69／TeacherID 67 | 同左 |
| 合約區間 | 2026-04-08 → 2026-06-02 | 2026-06-03 → 2026-08-12 |
| 狀態 | Stop=1（已停／結束） | Stop=0（進行中） |
| 堂數帳 | SessionCount 8／Used 8／Remaining **0** | SessionCount 8／Used 7／Remaining **1** |
| 收費 | Charge 12000、Paid=1 | Charge 12000、Paid=1 |
| 帳單 | Invoice #137（IssueDate 2026-04-08，paid 12000，已對帳） | Invoice #936（IssueDate 2026-06-24，paid 12000，已對帳） |
| 備註來源 | Memo 含同一收據號（現金繳費） | Memo 同收據號（續報沿用） |
| 建立／續報關係 | 先建立的完整包堂 | 舊課 EndDate 次日開的續報包；**後續實際排課走這門** |

另：同學生尚有 SC#996、SC#2396（單日／零收費或短約），與 6/10 19:00 重疊無關；SC#2396 僅 6/24 一堂，對應回報中「行政新建」情境之一，**本決策不處理**。

## 2. 2026-06-10 19:00–21:00 兩筆堂次

| | Session #11292（掛舊課） | Session #16951（掛新課） |
|---|---|---|
| StudentClassID | 114 | 2076 |
| 教師 | TeacherID 67 | 同左 |
| 時間 | 2026-06-10 19:00–21:00 | 同左 |
| 出席狀態 | attended | completed |
| 評量／學習紀錄 | learning_record_id **8883**，status **approved** | learning_record_id **9959**，status **approved** |
| 扣堂意涵 | 舊課 Used 已滿 8/8；此堂在 EndDate(6/2) **之後**仍存在 | 計入新課 Used 7/8 之一 |

→ 同一時段、同一老師，兩門正式課各有一筆「已出席／完成」＋已核准評量＝**雙計風險**。

## 3. 扣堂／計費／開單／對帳

| 項目 | 事實 |
|---|---|
| 舊課帳單 | Invoice #137 paid＋reconciled（2026-04）— **合約級已結清** |
| 新課帳單 | Invoice #936 paid＋reconciled（2026-06-24）— **續報已結清** |
| 6/10 堂次 | **無**獨立「單堂發票」；影響在包堂 Used／Remaining 與評量綁定 |
| 對帳 | 兩張 Invoice 皆已 `reconciled_at`；本次修正**不應**改 Invoice 金額／作廢歷史款 |

## 4. 後續課程實際沿用哪一側

- SC#114：2026-06-11 起 **0** 堂未來課  
- SC#2076：2026-06-11 起仍有多堂 attended／scheduled／leave（進行中主線）  

→ **營運主線 = 續報新課 SC#2076**。

## 5. 若各自「保留」的影響（不刪列）

| 選擇 | 資料影響 | 財務影響 |
|---|---|---|
| **A. 保留舊課 #11292**，新課 #16951 標重複／不計費 | 需把 #16951 改為 cancelled（或同等不計），並處理 LR#9959（作廢或改綁）；新課 Used 可能需 −1（Remaining +1）。舊課已 Stop 且 Remaining=0，主線仍在新課 → 行政／老師端易再混亂 | 不改 Invoice；可能**退回**新課 1 堂額度 |
| **B. 保留新課 #16951**，舊課 #11292 標被取代／不計費 | 需把 #11292 改為 cancelled（或同等不計），並處理 LR#8883；舊課已結束，對未來排課影響小 | 不改 Invoice；舊課帳已結清，通常**不必**動款 |

兩者皆：**禁止實體 DELETE**；保留原始列＋狀態變更＋audit note。

## 6. 建議

**建議選 B**（保留續報新課 #16951；舊課 #11292 標被取代／不計費）。

理由（Fact）：
1. 舊課合約已於 6/2 結束且 Stop=1；6/10 堂在舊課上屬越界殘留。  
2. 續報新課為現行排課／評量主線。  
3. 財務兩包皆已付清；B 較少動到進行中包堂餘額。  

（先前 dry-run 建議 A／cancel #16951 與「後續沿用新課」不一致，故本包改建議 B。）

## 7. 修正方案／回滾／稽核軌跡（待主任選定後才執行）

1. **Backup**：執行前 `mysqldump` 相關 `ClassSession`／`LearningRecord`／`StudentClass` 列（表級或列級）→ `backups/emergency/`。  
2. **修正（非刪）**：將「不保留側」session `Status` → `cancelled`（或專用 duplicate/superseded 狀態若已存在）；對應 LR：`VoidedAt` 或同等作廢，**保留原文**。  
3. **可選餘額校正**：僅當 Selected 方案需要且經核准時，調整 Used／Remaining（寫 change log）。  
4. **Audit**：internal note 寫 in-app #173、選項 A/B、前後 JSON、執行者、時間。  
5. **回滾**：用備份列還原 Status／VoidedAt／Used／Remaining；Invoice／Payment **不動**故財務回滾面小。  
6. **驗證**：該日 19:00 僅一筆有效 attended／completed；老師／行政／家長口徑一致；新課 Remaining 符合預期。

## 8. 主任二選一（請回覆 A 或 B）

- **A.** 保留舊課紀錄（session #11292），將新課重疊堂（#16951）標記為重複／不計費  
- **B.** 保留續報新課紀錄（session #16951），將舊課重疊堂（#11292）標記為被取代／不計費  

選定前：**零寫入**。選定後：依 §7 做可稽核、可回滾修正。
