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

## 網站畫面改了但現場還是舊的？

前端修改後需要**重新建置並複製到後端靜態目錄**才會在正式網址生效。工程師在專案內慣用：

```bash
cd frontend && npm run deploy
```

（等同 `vite build` + 複製到 `backend/public`。）  
若只有改後端 PHP／資料庫，通常不必跑 deploy，但改完仍建議重新整理瀏覽器並確認 API 正常。

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

詳細流程、被拒絕 push 時怎麼辦：[`docs/GITHUB_SYNC_WORKFLOW.md`](GITHUB_SYNC_WORKFLOW.md)。

**注意**：請勿將資料庫備份、`.env` 密鑰、使用者上傳的私密檔案推上 GitHub；`.gitignore` 已排除常見敏感路徑。

---

## 變更紀錄寫在哪？

[`docs/CHANGELOG.md`](CHANGELOG.md) — 近期上線或合併的重要行為變更。

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
| 變更紀錄 | [`docs/CHANGELOG.md`](CHANGELOG.md) |
