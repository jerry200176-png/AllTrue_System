# AllTrue 補習班管理系統

AllTrue 是一套補習班教務與營運整合系統，目標是把「學生、課程、排課、點名、繳費、評量、家長通知」放在同一個平台，降低人工操作與資料錯漏。

## 核心功能

- **學生與課程管理**：建立學生、課程主約、加購堂數、課程狀態追蹤
- **智慧排課**：固定週期排課、請假、調課、補課、教室配置
- **出缺勤與 RFID**：櫃台點名、刷卡記錄、缺勤補登
- **財務與帳單**：剩餘堂數、應收與繳費狀態
- **學習評量流程**：老師填寫、主任審核、歷程保存
- **家長入口**：查詢課程、評量、繳費訊息

## 角色使用情境

- **主任 / 行政**：建立學生課程、調度排課、審核評量、追蹤營運
- **老師**：查看課表、上課紀錄、填寫評量
- **櫃台**：點名、RFID 綁定、請假/補登處理
- **家長**：查看孩子學習與繳費資訊

## 技術棧

- **Frontend**：Vue 3 + Vite
- **Backend**：Laravel 8 (PHP)
- **DB**：MySQL（主要）/ PostgreSQL（Docker）
- **Deploy**：`frontend` build 後同步到 `backend/public`

## 目錄說明

- `frontend/`：前端頁面與元件
- `backend/`：Laravel API 與商業邏輯
- `docs/`：操作與維運文件、SOP、事故紀錄
- `scripts/`：部署與工具腳本

## 協作分支與同步

- GitHub 協作主分支：`jerry-sync-main`
- 本機常用分支：`main`（追蹤 `origin/jerry-sync-main`）

一鍵同步（add + commit + push）：

```bash
./scripts/git-sync.sh "feat: 說明這次改動"
```

## 新加入協作者先看

1. `AI_QUICKSTART.md`（AI/工程師快速理解專案）
2. `docs/GITHUB_SYNC_WORKFLOW.md`（GitHub 協作流程）
3. `docs/OPERATIONS_RUNBOOK.md`（SOP + 避坑）
4. `docs/INCIDENT_2026-04-10_GITHUB_AND_SITE_ROLLBACK.md`（歷史事故）

