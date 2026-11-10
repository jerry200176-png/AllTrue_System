---
name: alltrue-security
description: >-
  AllTrue 安全審查 SOP。觸及 auth、PII、RFID、LINE webhook、家長入口、
  新公開端點、繳費/個資欄位時啟用。STRIDE + AllTrue 紅線。
---

# AllTrue Security

## 1. Purpose

在 T3 變更進入 production 前，識別 auth/PII/注入/跨校區洩漏風險。

## 2. When to activate

- 修改 `AuthController`、middleware `role:*` / `require_campus`
- RFID `POST /api/v1/swipe-rfid`、LINE webhook
- 新增 API 路由、家長入口、PII 欄位
- `ParentPortal`、`ParentSession`

## 3. Required workflow

1. 走 STRIDE 逐項掃：

   | 威脅 | 要問的 | 本專案常見點 |
   |---|---|---|
   | Spoofing 偽造身分 | Token 有驗嗎？角色能偽造嗎？ | Sanctum Bearer Token；teacher 不可冒充 director |
   | Tampering 竄改 | 輸入有 validate 嗎？能改到越權欄位嗎？ | `$request->validate()`；`$fillable` 限制 |
   | Repudiation 否認 | 關鍵操作有 log 嗎？ | catch 區要 `Log::warning()`，不可 silent |
   | Information Disclosure 洩漏 | 錯誤訊息帶 stack trace 嗎？ | `APP_DEBUG=false`；API 只回 `message` |
   | Denial of Service | 有 rate limit、批次上限嗎？ | `RateLimiter`；匯出分頁、日期範圍有上限 |
   | Elevation of Privilege 提權 | 每支 route 都套 middleware 嗎？ | `auth:sanctum` + `role:*` |

2. 確認新路由在 `role` + `require_campus` 群組內（R60）
3. 檢查：token/PII 不可進 URL query、log、公開留言
4. 多校區：每個 query 帶 `CampusID` / `branch_id`
5. HIGH 風險 → 停線等使用者批准
6. 可選：啟動 `review-security` subagent

## 4. Forbidden actions

- ⛔ 新增無認證公開端點（含 `?dev_token=`）
- ⛔ 為測試在 production 開洞
- ⛔ 跳過 SEC phase 直接 merge T3 變更

## 5. AllTrue-specific rules

- 家長登入：姓名+手機 / LINE；`ParentSession` TokenHash
- 測試帳號：`.cursor/.local/test-credentials.md`（勿 commit）
- 個資法：最小蒐集、刪除權
- 必查清單：
  - 每個 POST/PATCH body 都有 `$request->validate()`；RFID 欄位只收英數字
  - 學生資料的 delete/patch 只有 `director` / `super_admin` 可做；`teacher` 讀不到其他校區的出缺勤
  - 軟刪除用 `VoidedAt`；物理刪除限 `super_admin` 且要二次確認
  - 匯出 XLSX 不含密碼、Token、內部 ID

## 6. Exit criteria

- [ ] STRIDE 六維度有結論（RED/YELLOW/GREEN）
- [ ] HIGH 已清空或已明確延期 + 使用者批准
- [ ] 無 token/PII 洩漏路徑
