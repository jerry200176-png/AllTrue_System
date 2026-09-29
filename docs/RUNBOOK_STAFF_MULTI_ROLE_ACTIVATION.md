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

> **重要：選人時盡量選「只隸屬單一分校」的人。**
> 開啟後，試點的人只看得到你指定的那一間分校。如果他原本在好幾間分校都有核准，**那幾間會突然消失（範圍縮小）**。
> 系統不會默默縮小：授權時會擋下，要你把「他原本的分校清單」抄進確認字串才放行（見步驟 3）；開啟時還要再勾一次「acknowledge_narrowing」（見步驟 4）。

## 步驟（照順序）

### 步驟 1：preflight（只讀，不用確認字串）

- action：`preflight`，其他欄位留空 → Run workflow。
- 打開執行結果 → 看 log（或下載 artifact `staff-multirole-preflight-…`）。
- 記下 `production-head=` 後面那串 40 個字元 = **expected_head_sha**（步驟 4 要用）。
- 確認 `effective-config-value=false`、`health-status=ok`、`grants-table-exists=true`。

### 步驟 2：看差異報告（最重要）

log 裡「role-resolution diff」區段：

- `diff-changed=` 開了開關後身分會變動的帳號數（**停用／停權帳號也會列出**，標 `inactive`，因為他們的登入憑證可能還在）。
- 每個會變的帳號一行：`diff-user id=… before=角色/老師id/[分校] after=…`（只有 id，沒有姓名電話）。
- **現在還沒授權任何人，應該是 `diff-changed=0`、`diff-unexpected=0`、`diff-result=OK`。**
- 若 `diff-unexpected` 不是 0：**停**，把 log 交給工程處理，不要往下。常見原因：某位老師沒有任何分校（開了之後 teacher id 會變空）。

### 步驟 3a：grant_pilot_dry_run（排練，不會寫入任何資料）

- action：`grant_pilot_dry_run`，填 user_id、campus_id，confirm 留空 → Run（**不需要核准**）。
- 看最後一行 `pilot-result=`：
  - `DRY-RUN-OK`：可以進行步驟 3b。會列出每個權限「would-create（將新增）」。
  - `REFUSED reason=…`：照原因處理。**若是 `campus-narrowing-not-acknowledged`，log 會印 `expected-confirm-suffix=:narrow_from=1,2,3`，步驟 3b 要把這段接在確認字串後面。**
- 這是第一次實際跑這支腳本，先排練可以確認 SSH、資料表、人選都正確。

### 步驟 3b：grant_pilot（真的授權）

- action：`grant_pilot`
- user_id：人的 id；campus_id：分校 id
- confirm：`GRANT_PILOT:user=<user_id>:campus=<campus_id>`（例：`GRANT_PILOT:user=12:campus=3`）
  - **分校會縮小時（排練有提示）**：後面加 `:narrow_from=<他原本所有分校，由小到大用逗號>`，例：`GRANT_PILOT:user=12:campus=3:narrow_from=1,3,5`。抄錯就會被拒絕。
  - **旗標已經是開的時候**（正常不會，見下）：後面再加 `:flag=on`，否則會被拒絕。
- Run → 到 Review deployments 按 Approve。
- 成功會看到 `grant capability=director created`、`grant capability=teacher created`、`pilot-result=SUCCESS`。重跑不會重複（冪等）。寫入與稽核紀錄是**同一個交易**：稽核寫不進去就整筆取消，並顯示 `pilot-result=FAILED`。
- **此時旗標仍是關的，沒有任何人的登入行為改變。**
- 再跑一次 **preflight**：現在 `diff-dual-holders=1`、`diff-dual-user-ids=` 應該就是你選的人；他的那行會標 `expected-dual`，若分校範圍縮小會多標 `NARROWED`；其餘仍 `diff-unexpected=0`。

### 步驟 4：enable（正式打開）

- action：`enable`
- expected_head_sha：步驟 1 記下的 40 字元
- **expected_pilot_user_id：試點的人的 user id**。系統會檢查「同時擁有老闆＋老師授權的人」剛好就只有這一位；多一個、少一個都拒絕。
- **acknowledge_narrowing：** 只有在 preflight 看到 `NARROWED` 時才勾成 true，代表你知道他的分校會變少。沒縮小就別勾。
- confirm：`ENABLE_STAFF_MULTI_ROLE_V1:<expected_head_sha>`（冒號後面貼同一串 SHA）
- Run → Approve。
- 系統會自動：確認 production 版本一致 → 再算一次差異（有非試點的人會變就直接拒絕）→ 備份 `.env`（含 checksum，備份失敗就停）→ 改一行 → `optimize` + opcache reset → 驗證實際設定為 true → health → 完整登入 smoke（最長 5 分鐘）。**任何一步失敗、甚至連線中斷，都會自動還原備份，還原後會再驗證一次；還原也失敗會印 `ROLLBACK-FAILED`，這時立刻找工程處理。**
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
   - **如果旗標目前是開的**（還沒 disable 就想撤銷）：會被拒絕，除非確認字串尾巴加 `:flag=on`。建議永遠「先 disable、再 revoke」。
3. **撤銷後，試點的人必須先登出再重新登入**（手機／網頁都要），**然後才可以再次 enable**。原因：他的畫面可能還記著「老師／老闆」模式；權限撤銷後舊模式會被伺服器拒絕。App 遇到這種「模式已失效」的 403 會自動清掉舊模式並重試一次，但仍請他重新登入確認。

## 給執行者：工作流的防護（為什麼它不會把 production 搞壞）

- 所有會改東西的步驟：備份失敗就停（`REFUSED-BACKUP-FAILED`）；備份與 `.env` checksum 必須一致；每個關鍵指令都有明確檢查，不是「跑完就當成功」。
- 失敗自動還原：還原後重新驗證「`.env` checksum、實際 config 值、health」，三者都對才算還原成功，否則 `ROLLBACK-FAILED`。
- smoke 有逾時（300 秒），逾時視同失敗並還原。
- 密碼類參數走步驟 env，不直接寫進遠端指令。
- 不要在 Pi 上執行 `config:clear`（incident B）；工作流只用 `php artisan optimize` + opcache reset。
- 所有動作都留 artifact 稽核檔（保留 90 天）；grant／revoke 另寫一筆 `staff.capability.pilot.*` 安全稽核事件（只存雜湊，無個資）。
- 本流程**不**做雙帳號合併、不改 TeacherID、不撤銷 session（另案 R3）。
