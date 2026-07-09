# #190 歷史帳務 — 技術選項（不含業務建議）

> **狀態**：待財務／營運業務決策 — **禁止修改 Invoice / 帳務紀錄**  
> **調查依據**：[`190-reconciliation-report.md`](190-reconciliation-report.md)  
> **REP 標準**：[`GUIDE_RELEASE_EXECUTION_PACKAGE.md`](../GUIDE_RELEASE_EXECUTION_PACKAGE.md)

---

## 1. 問題摘要（工程事實）

6 筆 `StudentClass`（週日 date-mode、`SessionCount=0`、`Charge=0`）因 #957 物化缺口導致契約堂數／金額未寫入。

| SC | 學生 | Invoice | 備註 |
|----|------|---------|------|
| 1695, 1696, 2026, 2027 | 洪子勛 | #690, #691（NT$0 未付） | 人工 void Payment 7/7；Memo「2科共6堂 $6000」 |
| 1331 | 陳顥昀 | 無 | |
| 1539 | 周允妍 | 無 | 週日 slot 從未物化 |

**工程不判斷**：應以「契約堂數 A」還是「6 月實際出席 B」計費 — 見 reconciliation §計算口徑。

---

## 2. 選項 A — 僅修 StudentClass 欄位（不動 Invoice）

### 技術步驟

1. 依修復後演算法計算 `SessionCount`、`Charge`（週日 slot 展開）
2. `UPDATE StudentClass SET SessionCount=?, Charge=? WHERE ID IN (...)`
3. 不建立、不修改 `Invoice` / `Payment`
4. 帳務人工依新 Charge 決定是否補開帳單

### 優點

- 不觸及已存在帳務流水
- 回滾簡單（還原 SC 兩欄）
- 與 G-009「雙真相」衝突風險最低

### 風險

- UI 顯示已繳／未繳可能與 Invoice 不一致，直到帳務補開
- 洪子勛 #690/#691 仍顯示 NT$0，需人工處理

### 稽核意涵

- 契約主檔更正，無 Invoice audit trail 變更
- 適合「帳單尚未定稿」情境

### 業務影響

- 主任在課程管理看到正確堂數／應收
- 家長入口繳費狀態可能仍顯示舊 Invoice 金額，直到帳務跟進

---

## 3. 選項 B — Amend 既有 Invoice（#690 / #691）

### 技術步驟

1. 先執行選項 A 的 StudentClass 寫回
2. 對 Invoice #690、#691 調整 `Amount` / line items 至目標金額（各 NT$3,000 或帳務指定）
3. 保留原 Invoice ID；新增 `Payment` 或 adjustment memo
4. **不** void 重建（避免斷裂付款連結）

### 優點

- 單一 Invoice 延續，編號不變
- 與 Memo「6堂 $6000」敘述可對齊

### 風險

- 需確認 amend API／欄位是否支援（須讀 `InvoiceController` 與帳務 SOP）
- 若曾 void Payment，audit 鏈較複雜
- 錯誤 amend 難以自動還原

### 稽核意涵

- Invoice 金額變更有 trail（建議 `is_internal_note` 記錄原因）
- 財務需保留 7/7 void 紀錄說明

### 業務影響

- 家長可見正確應繳金額
- 需主任／帳務確認金額口徑（A vs B）

---

## 4. 選項 C — Void 並重建 Invoice

### 技術步驟

1. 選項 A 寫回 StudentClass
2. `Void` #690、#691
3. 依 2026-06 billing period 重新 `createInvoice`（2 科或合併 1 張）
4. 重新關聯 Payment（若有）

### 優點

- 帳單金額與新契約完全一致
- 適合「原帳單完全無效」敘事

### 風險

- Invoice 編號變更，家長／會計對帳需人工對照
- Void 後重建若與 `Paid` flag 互動，可能觸發 G-009 邊界
- 實作與測試成本高於 B

### 稽核意涵

- 完整 void + 新單 trail
- 外部報表需兩張單對應一期

### 業務影響

- 家長可能看到作廢單 + 新單，需溝通
- 會計科目期間歸屬需確認

---

## 5. 選項 D — 僅修無 Invoice 的 SC（1331、1539、1695、1696）

### 技術步驟

1. 寫回 SC 1331、1539、1695、1696 的 `SessionCount` / `Charge`
2. **不**處理 SC 2026、2027 與 #690/#691
3. 洪子勛 6 月帳務整案留待業務決策

### 優點

- 範圍最小、風險最低
- 可先解周允妍、陳顥昀、5 月 partial 契約

### 風險

- 洪子勛 6 月問題未解，in-app #190 可能無法完全關閉
- 同一學生部分 SC 已修、部分未修，易造成困惑

### 稽核意涵

- 分批修復需文件記錄剩餘項

### 業務影響

- 部分學生體驗改善，洪子勛需後續批次

---

## 6. 選項 E — 不修改歷史，僅防止再發（#957 D1+）

### 技術步驟

1. 部署 #957 物化修復 + D1 unique index（進行中）
2. 不 retroactive 修改上述 6 SC
3. 新週日 date-mode 課程正確計費

### 優點

- 零帳務風險
- 符合「先止漏再補洞」策略

### 風險

- 歷史 6 筆永遠錯誤，報表／堂數不一致
- 客訴與 in-app #190 可能持續

### 稽核意涵

- 無帳務變更
- 需書面記錄「已知歷史差異」

### 業務影響

- 財務須以人工調整或線下處理洪子勛等案例

---

## 7. 工程前置（所有含 SC 寫回的選項）

| 步驟 | 說明 |
|------|------|
| 唯讀對帳 | 已完成 — `190-reconciliation-report.md` |
| 修復腳本 | 待業務選項後實作 `repair:190-sunday-billing --dry-run` |
| 測試 | Factory 週日 date-mode + CI |
| REP | 選項確定後另附 execution package |

**時段漂移**（5/31 實際 15:00 vs 契約 13:00）：任何選項皆須主任確認保留「實際授課紀錄」或「契約 slot」— 非純帳務問題。

---

## 8. 決策所需業務輸入

1. 6 月計費以 **契約堂數 A** 還是 **實際出席 B**（洪子勛為 3 堂/科 × $1000）？
2. #690/#691 應 amend、void 重建、或維持 NT$0 另開？
3. 是否分批（選項 D）或一次處理 6 SC？
4. 5/31 時段漂移列是否納入本次修復？

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-09 | 技術選項初版（無業務建議，待財務決策） |
