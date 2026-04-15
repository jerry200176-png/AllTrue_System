# GitHub Copilot／在 github.com 上協作的 AI

在**建議、產生或審查**本倉庫程式碼前，請依序閱讀下列檔案（路徑皆為儲存庫根目錄相對路徑）：

1. **`AGENTS.md`** — 專案給 AI 的守則與 First-read 清單  
2. **`docs/AI_REGRESSION_LESSONS.md`** — **防再犯**：已修過的產品／實作缺口，避免重複 regression（含 **2026-04-15 老師註冊／主任待審**：`directors/pending` 須過濾 `User.type`（勿只靠 `UserCampus.Approved`）；`Teacher` 寫入須處理 `(CampusID, T_Name)` 衝突；含 **2026-04-15 側欄 `pending_teachers`**：`unread-count` 依 **`UserCampus.Approved`**，**不等於** **`TeachersList`「待審核」**（**`User.status=pending`**）；`PUT profiles` 設 **`active`** 須同步 **`Approved`**；含 **2026-04-14 智慧排課誤標取消**：同格 `cancelled+scheduled` 禁止 `.find()` 第一筆，需共用優先序解析器；含 **2026-04-13 催繳名單／`tuition-slip`**：`TuitionCollectionPage` 與 `alerts/tuition` 同源、已繳不產圖、無 Invoice 勿偽裝帳單編號；含 **2026-04-13 調課後評量表消失**：請假 cascade 作廢 LR + `reschedule-session` 改日已上 → `ensurePastRecords` 須 un-void、`leave→attended` 須恢復 LR，勿改回「有作廢列就永遠跳過」；含 **2026-04-12 請假與學習評量**：不可只依 `VoidedAt`，須保留 `excludeLeaveSessionPendingReview` 與 `ensurePastRecords` 對請假堂的排除；含 **2026-04-12 老師教學工作台（TeacherHome）**：跨分校週課表合併、`mergeTeacherAttendanceBadge`、預設 `active=teacher-home`、deploy 同輪）  
3. **`docs/DIRECTOR_PAYMENT_ALERT_RULES.md`** — 若變更涉及主任「繳費／續課提醒」或 `AlertController::tuition`；**邏輯變更前必問使用者**（見該檔「變更管制」）  
4. **`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`** — 聊天／Bug／頭像：**已實作細節與禁止回歸**（改動前必讀）  
5. **`docs/CHANGELOG.md`**（**請先看檔案最上方**數則日期條目）、**`docs/CHAT_BUG_SYSTEM.md`** — 近期變更速覽與檔案索引

人類協作者請一併閱讀：**`CONTRIBUTING.md`**（含 GitHub／新同事 onboarding 與老師端工作台提示）。

使用 **Claude Code** 時，根目錄 **`CLAUDE.md`** 為對應的專案指引摘要。

## Commit SOP（Copilot / github.com AI 亦適用）

- 以「完整子功能」為 commit 單位；不要把不相干變更打包在同一筆。
- commit 前需確認程式碼可執行，並完成必要的基本檢查（如 build / lint / 型別檢查）。
- 若變更範圍有現成測試腳本，至少通過基本測試後再提交。
- 不要為單一檔案的拼字修正或純格式微調單獨建立 commit，除非其本身是獨立工作項目。
