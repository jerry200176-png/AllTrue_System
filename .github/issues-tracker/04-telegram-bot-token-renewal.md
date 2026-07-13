# [DevOps] @BotFather 撤銷並重發 @Alltrue_Daan_Bot token

## 要做什麼
聯絡 Telegram 的 @BotFather，撤銷 @Alltrue_Daan_Bot 的現有 token，重新發一組新的。舊 token 可能已外洩。

## 為什麼 sandbox 做不到
需要真人操作 Telegram 對話，與 @BotFather 互動（/revoke → /token）。本 AI agent 無法操作 Telegram BotFather。

## 誰來做
**CEO**（擁有 Telegram 帳號，能與 @BotFather 對話的人）

## 背景
- 這是安全最佳實踐 — 每當 token 可能外洩時應立即撤換
- 操作方式：打開 Telegram → 搜尋 @BotFather → 傳送 `/revoke` → 選擇 @Alltrue_Daan_Bot → 再傳 `/token` 取得新 token
- 耗時：約 2 分鐘
