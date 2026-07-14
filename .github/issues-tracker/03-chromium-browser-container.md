# [DevOps] 平台端部署 Chromium 瀏覽器容器（sandbox 修復前提）

## 要做什麼
在平台基礎設施端部署 Chromium 瀏覽器容器服務，讓 CubeLV AI agent 的 browser tool 能正常運作（目前三種執行路徑全部回傳 `no_local_executor`）。

## 為什麼 sandbox 做不到
這是平台基礎設施層級的問題，需要部署容器服務。本 AI agent 無法自行部署平台級的 Chromium 瀏覽器容器。

## 誰來做
**平台基礎設施團隊**（CubeLV 平台團隊）

## 背景
- 2026-07-13 已完整診斷，技術報告已產出
- 三條 browser 執行路徑全部失敗（桌面、手機、雲端）
- 測試工程師因此無法做 E2E 驗收
- 技術報告 NOTE id: `7844cd8ff1c787bd8265d793`
- CEO 報告 NOTE id: `233a45c8cc98a57498c133c5`

## 阻塞的任務
- 🔴 測試工程師 E2E 驗收（AllTrue In-app Bug）
- 🔴 瀏覽器 sandbox 相關的所有除錯與測試
