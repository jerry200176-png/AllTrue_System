# Privacy Request SOP — 資料查詢／匯出／刪除／封存請求處理流程

> Addresses [#903](https://github.com/jerry200176-png/AllTrue_System/issues/903). Reference model: GDPR DSAR (Data Subject Access Request) handling. Companion: [`docs/security/PII_DATA_INVENTORY.md`](../security/PII_DATA_INVENTORY.md) for what data exists per table.

## 誰可能提出請求

- 家長／學生本人（透過分校 director，或未來的家長入口客服管道）
- 老師／員工（自己的員工資料）
- 已離校學生的家長（離校後仍可能要求刪除/封存）

## 請求類型與處理

### 1. 資料查詢／匯出（存取權）

| 步驟 | 動作 |
|---|---|
| 收到請求 | Director 或 super_admin 記錄請求人、學生/員工姓名、請求範圍、日期 |
| 撈資料 | 依 [`PII_DATA_INVENTORY.md`](../security/PII_DATA_INVENTORY.md) 表格，只撈該對象在對應表的列（`Student`/`StudentClass`/`StudentSingIn`/`payment_reports` 等），不撈其他學生的關聯資料 |
| 交付 | 匯出成人類可讀格式（PDF/CSV），透過既有安全管道（不透過未加密 email 附件寄送高敏感財務/RFID/LINE ID 欄位） |
| 記錄 | 在 super_admin 內部備註留下「已處理查詢請求，日期，交付方式」 |

### 2. 刪除請求

**先確認法定保存義務**：財務紀錄（`payment_reports`、`StudentClass.Charge`/`Pay` 相關）可能有稅務/會計法定保存年限，不可因請求而立即刪除——需先確認保存期限（見 `PII_DATA_INVENTORY.md` §5 未決事項）。

| 步驟 | 動作 |
|---|---|
| 可立即刪除 | 非財務、非法定保存的欄位（如 `notes`、`LineID`、`Notify_Token`、頭像）可依請求清除 |
| 需保留但去識別化 | 財務紀錄無法刪除時，優先考慮遮蔽姓名/聯絡方式，保留金額/日期供稅務稽核 |
| 不可單方面執行 | 涉及**修改 production 財務資料**，一律走 `DIRECTOR_PAYMENT_ALERT_RULES.md` 的既有禁止擅改原則——AI 不自行執行，需 director/owner 確認範圍後手動處理或開對應 PR |

### 3. 封存請求（離校學生/離職員工）

- `Student.enable` / `Teacher.Enable` 已有停用機制（非刪除）——封存 = 設為停用，保留歷史紀錄供帳務/出缺勤稽核，不從資料庫移除。
- 這是目前系統實際運作方式（非新建功能），此 SOP 只是把既有行為寫下來給請求處理流程對照。

## AI／Agent 處理界線

- **可以做**：協助撈取查詢/匯出所需資料（唯讀 SQL）、產出交付格式、記錄請求日誌。
- **不可以做**：未經 director/owner 明確授權，直接執行刪除或修改 production 資料列——即使請求本身合理，執行動作仍需人類確認範圍（避免刪錯關聯資料、或誤刪仍有法定保存義務的財務紀錄）。

## Acceptance against #903

- [x] 查詢/匯出/刪除/封存四類請求的處理流程已定義。
- [x] AI 處理界線明確（唯讀撈取可做，寫入刪除需人類確認）。
- [ ] 實際保存期限數字——依 `PII_DATA_INVENTORY.md` §5，待法務/會計輸入後補上，本 SOP 目前是流程框架，不是最終數字。
