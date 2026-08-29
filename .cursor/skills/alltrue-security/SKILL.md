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

1. 讀 `module-security.mdc` STRIDE 速查
2. 確認新路由在 `role` + `require_campus` 群組內（R60）
3. 檢查：token/PII 不可進 URL query、log、公開留言
4. 多校區：每個 query 帶 `CampusID` / `branch_id`
5. 依 `docs/governance/RISK_BASED_MERGE_POLICY.md` 分級：T2 需 independent review；T3 可準備實作但在 protected action 前停下等 Founder GO
6. 可選：啟動 `review-security` subagent

## 4. Forbidden actions

- ⛔ 新增無認證公開端點（含 `?dev_token=`）
- ⛔ 為測試在 production 開洞
- ⛔ 跳過 SEC phase 直接 merge T3 變更

## 5. AllTrue-specific rules

- 家長登入：姓名+手機 / LINE；`ParentSession` TokenHash
- 測試帳號：`.cursor/.local/test-credentials.md`（勿 commit）
- 個資法：最小蒐集、刪除權 — 見 LEGAL 角色說明

## 6. Exit criteria

- [ ] STRIDE 六維度有結論（RED/YELLOW/GREEN）
- [ ] T3/protected boundary 已停止並準備 Founder evidence；T2 已完成 independent review
- [ ] 無 token/PII 洩漏路徑
