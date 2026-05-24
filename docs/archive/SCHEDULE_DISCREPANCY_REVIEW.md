# 課表出入回報系統 — 審查總結

**PRD**：`.cursor/plans/課表出入回報系統.md` | **審查日期**：2026-04-17 | **結論**：✅ Approved

---

## 1. 資安審查（STRIDE）

| 威脅 | 控制措施 | 狀態 |
|------|----------|------|
| Spoofing（偽造身份）| Bearer token + `AttachAuthUser`；controller 以 `auth_user` 取 reporter_id，不接受 body | PASS |
| Tampering（竄改）| 跨校 `class_session_id` → 403；Pest test 覆蓋 | PASS |
| Repudiation（否認）| `acknowledged_by/_at, resolved_by/_at, withdrawn_at` 稽核欄位 | PASS |
| Info Disclosure | `require_campus` + `resolveCampusScope()` 強制範圍 | PASS |
| DoS | FR-003 duplicate guard + `per_page` 上限 100 | PARTIAL（建議未來加 rate limit）|
| Elevation | `role:director` middleware + controller 再次檢查 | PASS |

**殘餘風險**：`activeForSession` 未驗證 `class_session_id` 所屬 campus（LOW，下一輪修正）。

---

## 2. Code Review 重點

### FR-003 重複回報守門
- `whereIn('status', [pending, acknowledged])` 過濾正確，已結案者可再提新回報
- 輕微競爭條件（兩人同時提交同堂次）→ LOW 風險，可後續加 unique composite index

### LINE Push 失敗降級
- 缺 token/group_id → 略過並記 info log，不拋例外
- HTTP 錯誤：4xx（除 429）不重試；5xx/429 退避重試最多 3 次
- `dispatch()->afterResponse()`，不影響 API 回應；`timeout(8)` 防卡住

### 存取控制
- 所有端點經 `require_campus` + 角色 middleware
- Controller 再次於 `resolveCampusScope()` 檢查 `branch_id`
- `withdraw()` 額外檢查 `reporter_id === userId`

---

## 3. QA 驗收重點

### 功能驗收
- [ ] FR-001：老師回報特定堂次 → 成功 toast + 「已回報・待處理」徽章
- [ ] FR-002：LINE 推播 5 秒內送達；無 token 時靜默跳過
- [ ] FR-003：同堂次重複回報 → 切為唯讀檢視
- [ ] FR-004：「此課不在系統中」回報（mode=missing）
- [ ] FR-005：body 帶 `reporter_id: 99999` → DB 仍為實際用戶 ID
- [ ] FR-006：主任列表 + 篩選（待處理/處理中/已解決）
- [ ] FR-007/008：狀態流程 pending → acknowledged → resolved（需 ≥10 字說明）
- [ ] FR-009：已結案不可回頭（409）
- [ ] FR-010：儀表板摘要卡片 + 警示色
- [ ] FR-011：老師撤銷未確認回報；已確認則 409；他人回報 403

### 跨校/資安
- [ ] 別校主任讀資料 → 403
- [ ] 別校老師送回報 → 403
- [ ] 超過 12 月 resolved/withdrawn → archive 後預設排除

### UI/UX
- 回報 Modal：大卡片 Radio（≥44px）、字數計數器、手機版底部滑入
- 管理頁：Tabs ≥44px、空狀態 icon、行動裝置卡片排版
- Toast：成功綠底 3 秒消失、錯誤紅底
- 可及性：min-height ≥44px、aria-label、role="dialog"、Esc 關閉

### 效能
- [ ] 送出 API p95 < 500ms
- [ ] 主任列表首次載入（50 筆）< 1.5s

### 回歸
- [ ] 點名流程不受影響
- [ ] Super admin 跨分校切換正常

---

## 4. 授權矩陣

| 端點 | teacher | director | super_admin |
|------|---------|----------|-------------|
| POST /schedule-discrepancies | ✓（自己校區）| ✓ | ✓ |
| GET .../my | ✓（本人）| ✓ | ✓ |
| POST .../withdraw | ✓（自己）| ✓ | ✓ |
| GET /schedule-discrepancies | ✗ | ✓（本校）| ✓ |
| GET .../summary | ✗ | ✓（本校）| ✓ |
| PUT .../{id} | ✗ | ✓（本校）| ✓ |
