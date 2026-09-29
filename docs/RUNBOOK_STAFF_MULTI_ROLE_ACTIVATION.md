---
owner: platform
review_cycle: quarterly
last_reviewed: 2026-09-30
---

# RUNBOOK — 教職員「一個帳號、雙角色」試點：啟用與回滾（in-app #299）

> **REFERENCE ONLY — NO DECISION OR EXECUTION AUTHORITY.**
> 決策：Founder（2026-09-30 決定先在**一個分校**試點）。議題：GitHub #2908。
> 執行路徑：`.github/workflows/staff-multirole-activation.yml`（唯一啟用／回滾路徑）。
> 這會改變登入後的身分／權限判斷（T3），所以每一步都要人工核准，不要跳步。

## 白話說明（先看這段）

- 現在：老闆兼老師的人要兩個帳號（一個 D、一個 T）。
- 新功能：同一個帳號可以「切換」成老闆或老師。開關叫 `STAFF_MULTI_ROLE_V1`，目前是**關**。
- 開關是**全系統**的，但只有你「特別授權」的人才會有切換功能。授權 = 在資料庫寫兩筆紀錄（老闆權限 + 老師權限，綁一個分校）。
- 其他所有人開了開關之後**什麼都不會變**。`preflight` 會先幫你檢查這件事，有人會變就不讓你開。

## 你需要的東西

1. GitHub 網頁 → 本專案 → **Actions** → 左邊清單點 **Staff Multi-Role Activation (Founder-gated)** → 右邊 **Run workflow**。
2. 要試點的人的 **User id**（數字）和 **分校 id**（Campus id，數字）。這個人必須：帳號類型是 D 或 T、狀態啟用、在該分校已核准。
3. 有「production-activation」核准權限（會跳出 Review deployments，按 Approve）。

> 建議選人：只隸屬**單一分校**的老闆兼老師。若他在多個分校都有核准，開啟後他的分校範圍會縮成你指定的那一間（執行時會印警告）。

## 步驟（照順序）

### 步驟 1：preflight（只讀，不用確認字串）

- action：`preflight`，其他欄位留空 → Run workflow。
- 打開執行結果 → 看 log（或下載 artifact `staff-multirole-preflight-…`）。
- 記下 `production-head=` 後面那串 40 個字元 = **expected_head_sha**（步驟 4 要用）。
- 確認 `effective-config-value=false`、`health-status=ok`、`grants-table-exists=true`。

### 步驟 2：看差異報告（最重要）

log 裡「role-resolution diff」區段：

- `diff-changed=` 開了開關後身分會變動的帳號數。
- 每個會變的帳號一行：`diff-user id=… before=角色/老師id/[分校] after=…`（只有 id，沒有姓名電話）。
- **現在還沒授權任何人，應該是 `diff-changed=0`、`diff-unexpected=0`、`diff-result=OK`。**
- 若 `diff-unexpected` 不是 0：**停**，把 log 交給工程處理，不要往下。常見原因：某位老師沒有任何分校（開了之後 teacher id 會變空）。

### 步驟 3：grant_pilot（授權試點的人）

- action：`grant_pilot`
- user_id：填人的 id；campus_id：填分校 id
- confirm：`GRANT_PILOT:user=<user_id>:campus=<campus_id>`（例：`GRANT_PILOT:user=12:campus=3`）
- Run → 到 Review deployments 按 Approve。
- 成功會看到 `grant capability=director created`、`grant capability=teacher created`、`pilot-result=SUCCESS`。重跑不會重複（冪等）。
- **此時旗標仍是關的，沒有任何人的登入行為改變。**
- 再跑一次 **preflight**：現在 `diff-dual-holders=1`；若該人的分校範圍有縮小，會看到一行 `expected-dual`，其餘仍 `diff-unexpected=0`。

### 步驟 4：enable（正式打開）

- action：`enable`
- expected_head_sha：步驟 1 記下的 40 字元
- confirm：`ENABLE_STAFF_MULTI_ROLE_V1:<expected_head_sha>`（冒號後面貼同一串 SHA）
- Run → Approve。
- 系統會自動：確認 production 版本一致 → 再算一次差異（有非試點的人會變就直接拒絕）→ 備份 `.env`（含 checksum）→ 改一行 → `optimize` + opcache reset → 驗證實際設定為 true → health → 完整登入 smoke。**任何一步失敗都會自動還原備份。**
- 成功訊息：`enable-result=SUCCESS`。
- 開頭若看到 `REFUSED-…`：什麼都沒動，照訊息處理即可。

### 步驟 5：在 App 驗證

1. 用試點帳號登入 → 應該看得到「老闆 / 老師」切換。
2. 切到老師 → 只看到老師工作區；切到老闆 → 只看到老闆功能。
3. 用一個**沒被授權**的老師帳號和一個老闆帳號登入 → 畫面和以前完全一樣。
4. 有任何怪狀況 → 直接做下面的回滾。

## 回滾（隨時可做，先關再說）

1. **關閉旗標**：action `disable`，confirm `DISABLE_STAFF_MULTI_ROLE_V1` → Run → Approve。成功 `disable-result=SUCCESS`。所有人立刻回到舊的身分判斷（依帳號類型），授權紀錄留著但不生效。
2. **撤銷試點授權**（要完全清乾淨才做）：action `revoke_pilot`，user_id／campus_id 同步驟 3，confirm `REVOKE_PILOT:user=<user_id>:campus=<campus_id>`。這是「標記撤銷」不是刪除，紀錄保留。之後要重新授權再跑 `grant_pilot` 即可（會重新啟用同一筆）。

## 安全規則（給執行者）

- 不要在 Pi 上執行 `config:clear`（incident B）；工作流只用 `php artisan optimize` + opcache reset。
- 所有動作都留 artifact 稽核檔（保留 90 天）；grant／revoke 另寫一筆 `staff.capability.pilot.*` 安全稽核事件（只存雜湊，無個資）。
- 本流程**不**做雙帳號合併、不改 TeacherID、不撤銷 session（另案 R3）。
