# Incident Report — 2026-04-10：分校選單爆增 / 學生資料消失 / 全 API 500

**日期**：2026-04-10  
**嚴重度**：高（所有 API 500、學生／老師／課程無法讀取）  
**影響時間**：約 3 小時（前端分校顯示問題持續較久）  
**系統**：AllTrue 補習班管理系統（daan.lifenet.com.tw）

---

## 一、事故時序

| 時間 | 事件 |
|------|------|
| 事發前 | 分校切換選單出現 20 間分校（預期 8 間） |
| 修復過程中 | `branches.json` 被改為假 ID（id 1=興隆），與 DB 不符，導致學生/課程查詢帶錯 `CampusID` |
| 同日稍後 | 所有 API 回傳 HTTP 500，登入失敗 |
| 修復後 | 資料確認完整（260 學生、51 使用者、70 課程），恢復正常 |

---

## 二、根本原因分析（3 個獨立問題）

### 問題 1：分校選單顯示 20 間

**原因**：`frontend/src/lib/useBranches.js` 的 `mergeWithDefaults()` 用 `code || name` 當 Map key：
- API 回傳資料（無 code）→ key 為中文校名「興隆分校」
- 內建預設（有 code）→ key 為英文 code「xinglong」
- 兩者被判定為**不同筆**，20 間預設全被加入清單

**另一原因**：`backend/public/branches.json` 仍是舊的 20 筆清單，API 失敗時 fallback 直接顯示全部。

### 問題 2：學生/老師/課程資料消失（實際未刪除）

**原因**：修復問題 1 時，誤將 `branches.json` 改為假 ID（id 1=興隆、id 2=新店…），但資料庫的真實對應是：

| 真實 DB `Campus.id` | 校名 |
|---|---|
| 17 | 興隆分校 |
| 9 | 新店分校 |
| 15 | 大安分校 |
| 16 | 木柵分校 |
| 3 | 大直分校 |
| 4 | 汐止分校 |
| 2 | 東湖分校 |
| 1 | 內湖分校 |

前端以假 id=1（興隆）查 `CampusID=1`（DB 內湖，0 學生），畫面空白。

### 問題 3：全 API HTTP 500

**原因**：`backend/bootstrap/cache/services.php` 快取過期，仍記錄 `NunoMaduro\Collision\CollisionServiceProvider`（dev 套件），但該 class 不在 vendor 中。Laravel 啟動時嘗試載入拋出 `Error`，導致**所有請求均 500**。

---

## 三、修復步驟

### 修復問題 1 & 2（分校 ID 錯誤）

1. 查資料庫確認真實 `Campus.id`：
   ```sql
   SELECT id, name FROM Campus ORDER BY id;
   SELECT CampusID, COUNT(*) FROM Student GROUP BY CampusID;
   ```

2. 用真實 ID 更新 `backend/public/branches.json`：
   ```json
   [
     {"id": 17, "name": "興隆分校", "code": "xinglong"},
     {"id": 9,  "name": "新店分校", "code": "xindian"},
     ...
   ]
   ```

3. 更新 `frontend/src/lib/useBranches.js` 的 `DEFAULT_BRANCHES` 使用真實 ID。

4. 修正 `mergeWithDefaults()`：API 有資料時**僅保留後端白名單的 8 間**，依校名比對過濾。

5. 重新 deploy：`cd frontend && npm run deploy`

### 修復問題 3（全 API 500）

```bash
rm -f backend/bootstrap/cache/services.php \
       backend/bootstrap/cache/packages.php \
       backend/bootstrap/cache/config.php
```

驗證：
```bash
curl -sk https://daan.lifenet.com.tw/api/v1/branches
# 預期回傳 JSON 陣列，HTTP 200
```

---

## 四、預防措施（已實施）

| 措施 | 說明 |
|------|------|
| `branches.json` 只含 8 間且 ID 正確 | 與 DB `Campus.id` 對齊 |
| `useBranches.js` 白名單過濾 | `mergeWithDefaults` 依 `OFFICIAL_BRANCH_NAME_SET` 過濾，多餘分校不進入清單 |
| `DEFAULT_BRANCHES` 使用真實 DB ID | fallback 時也不會帶錯 `CampusID` |
| 舊 localStorage 相容 | `LEGACY_DEFAULT_ID_TO_NAME` + `resolveSavedBranchChoice` 將舊存的 ID 以校名比對自動修正 |

---

## 五、關鍵教訓

1. **修改分校相關設定前，必須先查 `Campus.id`**，不可臆測 ID。
2. **`branches.json` 是最後備援，其 ID 必須與資料庫一致**。
3. **任何 composer 安裝/更新後，或 API 突然全 500，第一步清 bootstrap cache**：
   ```bash
   rm -f backend/bootstrap/cache/{services,packages,config}.php
   ```
4. **「資料消失」≠ 資料被刪除**，先查 DB 筆數，再查 `branch_id` 是否對應正確 `CampusID`。
