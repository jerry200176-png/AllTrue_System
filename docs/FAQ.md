# AllTrue 專案常見問題（FAQ）

給**主任、行政、老師、工程師、維運**的快速對照。技術細節請再點內文連結。

---

## 這套系統是做什麼的？

**AllTrue** 是補習班教務與營運整合平台：學生與課程、智慧排課、點名與 RFID、帳單與繳費、學習評量、家長入口、通知等，盡量在同一套流程裡完成，減少表格與口頭轉述造成的錯漏。

更完整的產品說明見根目錄 [`README.md`](../README.md)。

---

## 我是新同事／新工程師，要先看什麼？

1. [`CONTRIBUTING.md`](../CONTRIBUTING.md) — 協作方式與 AI 工具對照  
2. [`AI_QUICKSTART.md`](../AI_QUICKSTART.md) — 專案與目錄速覽  
3. [`docs/AI_REGRESSION_LESSONS.md`](AI_REGRESSION_LESSONS.md) — **防再犯**（暫停課程、繳費提醒、評量待審等已知坑）  
4. [`docs/OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md) — 維運與日常 SOP  
5. [`docs/GITHUB_SYNC_WORKFLOW.md`](GITHUB_SYNC_WORKFLOW.md) — 怎麼把改動同步到 GitHub  

若你要改**主任儀表板繳費提醒**或相關 API，必讀 [`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`](DIRECTOR_PAYMENT_ALERT_RULES.md)。

---

## 分校是什麼？為什麼畫面左上角要選分校？

系統支援多校區。使用者登入後會以**目前選定的分校**篩選學生、課程、排課等資料，避免跨校誤看。若選錯分校，列表會變少或看起來「沒資料」—請先確認左上角分校是否正確。

---

## 老師登入後預設會進哪裡？

自 **2026-04-12** 起，**老師**帳號登入後預設開啟 **教學工作台**（側欄與手機底欄亦稱「工作台」）：可快速看到**今日待點名**、**評量待辦**（待審／需修改等摘要），以及**本週跨自己所有分校**的課表合併一覽（每筆標示分校）。實際點名請到「**出缺勤管理**」；完整填寫或編輯評量請到「**課表與評量**」。手機底欄另有「出勤」「評量」「行事曆」捷徑；科目數統計在「更多」內。

---

## 網站畫面改了但現場還是舊的？

前端修改後需要由 `deploy.yml` **重新建置並複製到後端靜態目錄**才會在正式網址生效。正常流程是 feature branch → PR → CI 綠 → merge → deploy.yml 自動部署。

若現場仍看到舊版，先確認 deploy workflow 成功與 `backend/public/version.json` 已更新，再請使用者 Ctrl+Shift+R 強制重新整理。手動 `npm run deploy` 只限 CI/deploy 掛掉的緊急修復流程。

---

## 登入後一直說過期、或 API 回 401？

- 確認瀏覽器未封鎖 `localStorage`（本系統用 `localStorage.alltrue_session` 存登入狀態）。  
- 可嘗試**登出後再登入**。  
- 後端 token 有**有效期限**（實作以伺服器設定為準），長時間未操作後需重新登入屬正常。

---

## 學生／課程很多時，系統會不會變慢或漏資料？

已針對「大分校」做載入策略調整（例如課程管理**分頁**、智慧排課**完整載入課程**但分段請求、請假調課**依日期範圍**等）。  
給主任／老闆的**白話說明**請看：[`docs/DIRECTOR_SCALING_FAQ.md`](DIRECTOR_SCALING_FAQ.md)。

---

## 聊天、Bug 回報、頭像誰能改？會不會改壞？

聊天與 Bug 模組有**角色權限矩陣**（例如 Bug 狀態僅限 super_admin 等）。改動前請先讀：

- [`docs/CHAT_BUG_SYSTEM.md`](CHAT_BUG_SYSTEM.md)  
- [`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`](AI_HANDOFF_CHAT_BUG_AVATAR.md)（較完整的手冊與禁止回歸項）

---

## GitHub 要怎麼更新？主分支是哪一支？

協作主分支為 **`jerry-sync-main`**（見 [`README.md`](../README.md) 說明）。本機常用 `main` 追蹤遠端。

建議使用專案內一鍵腳本（會 `git add`、commit、push）：

```bash
cd /home/admin   # 或你的 clone 根目錄
./scripts/git-sync.sh "feat: 簡短說明這次改動"
```

若你目前在本機分支 **`main`**，腳本會把改動推到遠端的 **`jerry-sync-main`**（與 README 協作約定一致）。

詳細流程、被拒絕 push 時怎麼辦：[`docs/GITHUB_SYNC_WORKFLOW.md`](GITHUB_SYNC_WORKFLOW.md)。

**注意**：請勿將資料庫備份、`.env` 密鑰、使用者上傳的私密檔案推上 GitHub；`.gitignore` 已排除常見敏感路徑。

---

## 變更紀錄寫在哪？

[`docs/CHANGELOG.md`](CHANGELOG.md) — 近期上線或合併的重要行為變更。

---

## 上完課、已繳費，為什麼主任總覽還出現在繳費提醒？

堂數制下，**已繳費但剩 0～2 堂**的課程會被視為「續課／加購提醒」，仍列在「繳費／續課提醒」中。若學生**不再續報**，主任可在「課程管理」或「學生課程」找到該筆，按**「結案（不續報）」**，課程即不再出現在提醒清單（實質與暫停課程相同）。加購新批次時，舊的 0 堂已繳課程會**自動結案**。

完整規則見 [`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`](DIRECTOR_PAYMENT_ALERT_RULES.md)。

---

## 昨天忘了點名，可以補嗎？

可以。出缺勤管理頁下方有**「待補點名（已結束節次）」**區塊（主任／櫃檯可見），預設列出最近 7 天已結束但尚未有出缺勤紀錄的堂次。你可以：

1. 調整起始／結束日期，按「查詢」載入該範圍內的待補堂次。
2. 在列表中選擇狀態（到班／遲到／缺席／請假），按「補登」即完成。
3. 補登後的扣堂、請假順延等行為與「今日點名」完全一致。

**注意**：若某堂的出缺勤已被作廢（voided），該堂次會重新出現在待補列表中，可再次補登。老師僅能補登自己的課程。

---

## 出缺勤列表的「科目」為什麼是「—」？

常見原因有三類：

1. **課程主檔與簽到上的科目 id 都無法對到目前的 `Subject` 表**（含舊資料殘留 id）。後端已用簽到快照 **fallback** 顯示名稱，並提供 migration 映射舊 id；**新環境部署後請執行** `php artisan migrate`（見 [`docs/CHANGELOG.md`](CHANGELOG.md) 2026-04-12 一節）。
2. **僅有「請假堂次」、尚無任何簽到列**的補充列：仍只讀 **學生課程上的科目**，請在學生課程／課程管理補齊 **`StudentClass.SubjectID`**。
3. 若仍異常，請附：**分校、`GET /api/v1/attendance?date=…` 回傳片段**（勿貼 token），供工程師對照。

---

## 還是找不到答案？

1. 先搜 [`docs/`](.) 目錄內其他 md。  
2. 營運／當機流程：[`docs/OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md)。  
3. 開 issue 或內部管道時，盡量附：**分校、帳號角色、操作步驟、畫面截圖或 API 狀態碼**。

---

## 文件索引（精簡）

| 主題 | 文件 |
|------|------|
| 專案總覽 | [`README.md`](../README.md) |
| 協作與 AI 入口 | [`CONTRIBUTING.md`](../CONTRIBUTING.md) |
| 防再犯／已知坑 | [`docs/AI_REGRESSION_LESSONS.md`](AI_REGRESSION_LESSONS.md) |
| 繳費提醒規則 | [`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`](DIRECTOR_PAYMENT_ALERT_RULES.md) |
| 大分校／效能說明（給非技術） | [`docs/DIRECTOR_SCALING_FAQ.md`](DIRECTOR_SCALING_FAQ.md) |
| GitHub 同步 | [`docs/GITHUB_SYNC_WORKFLOW.md`](GITHUB_SYNC_WORKFLOW.md) |
| 維運 SOP | [`docs/OPERATIONS_RUNBOOK.md`](OPERATIONS_RUNBOOK.md) |
| 聊天／Bug | [`docs/CHAT_BUG_SYSTEM.md`](CHAT_BUG_SYSTEM.md) |
| 變更紀錄 | [`docs/CHANGELOG.md`](CHANGELOG.md)（含出缺勤科目、請假順延等） |
