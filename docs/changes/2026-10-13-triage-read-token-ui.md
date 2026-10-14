## 2026-10-13 — feat(security): 超級管理員「AI 分診唯讀金鑰」設定畫面
<!-- release-notes: silent_ship=silent-2026-10-13-triage-read-token-ui -->
<!-- silent-reason: 只有超級管理員在「個人資料 → 安全性」看得到；老師與主任的畫面完全不變。 -->
- 個人資料 → 安全性新增「AI 分診唯讀金鑰」區塊（僅超級管理員）：建立金鑰時只顯示一次並提供複製按鈕與警語，列表只列名稱、建立時間、最後使用、到期日，可撤銷。雲端分診改用 `ALLTRUE_TRIAGE_TOKEN` 直接唯讀讀取回報的做法寫入 `docs/sop/BUG_INTAKE_TO_PRODUCTION.md` 與 CLAUDE.md G-012。
