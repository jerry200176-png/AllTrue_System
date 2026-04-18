# 課表出入回報系統 QA 驗收清單

_對照 PRD：`.cursor/plans/課表出入回報系統.md` 第 10 節功能驗收 + 第 5b 節 UI/UX 規格_

---

## 一、角色與前置準備

| 角色 | 建議帳號 |
|------|---------|
| 超級管理員 (S) | `super@test` |
| 分校主任 (A) | `dir1@test`（分校 1）, `dir2@test`（分校 2） |
| 老師 (T) | `tea1@test`（分校 1）, `tea2@test`（分校 1）|

前置資料：
- 分校 1 已有 `Campus.messaging_channel_token` 與 `Campus.staff_line_group_id` 設定（或手動設定以測試 LINE 通知）。
- 老師 `tea1@test` 今日有至少一堂待點名的課。
- 另開一筆狀態為 `scheduled` 的堂次。

---

## 二、功能驗收 (FR-001 ~ FR-011)

### FR-001 老師可回報特定堂次的課表出入
- [ ] 登入 `tea1@test`，開啟「出缺勤管理」。
- [ ] 任一待點名的堂次列出「回報出入」按鈕。
- [ ] 點擊後 Modal 展開，顯示日期／時段／科目／學生 4 個欄位的 read-only 摘要。
- [ ] 選擇任一類型（如「學生未到」），填寫備註送出。
- [ ] 顯示成功 toast「已送出回報」，Modal 自動關閉。
- [ ] 同一列出現「已回報・待處理」徽章。

### FR-002 LINE 推播通知主任
- [ ] 在送出回報後 5 秒內，分校 1 員工 LINE 群組收到推播訊息。
- [ ] 訊息包含：分校名稱、老師姓名、出入類型、堂次日期時段、備註摘要。
- [ ] 若故意清空 `Campus.messaging_channel_token`，再送出一筆：頁面仍回 201，LINE 未推播，後端 log 記錄 `schedule_discrepancy.line_push_skipped_no_token`。

### FR-003 防止同堂次重複回報
- [ ] 對同一堂次再次點「回報出入」→ Modal 開啟但切換為唯讀檢視，顯示已建立的紀錄與狀態。
- [ ] 後端觀察：`schedule_discrepancies` 表對該 `class_session_id` 只有 1 筆 `pending/acknowledged`。

### FR-004 老師可回報「此課不在系統中」
- [ ] 在 `出缺勤管理` 頁面底部的「有課不在列表中？點此回報」連結開啟 Modal（`mode=missing`）。
- [ ] 類型欄自動鎖定為「此課不在系統中」。
- [ ] 需填：科目、學生姓名、時段（必填），日期預設今天。
- [ ] 缺少任一項時，送出按鈕 disabled；直接繞過 API → 後端回 422。
- [ ] 正常送出 → 201，列表（老師端 /my）可見到該筆。

### FR-005 reporter_id 後端注入（防竄改）
- [ ] 用工具（curl/Postman）帶 `tea1@test` 的 token，但 body 加 `"reporter_id": 99999`。
- [ ] API 回 201，但資料庫該筆 `reporter_id` 仍為 `tea1@test.id`，不是 99999。

### FR-006 主任端列表與篩選
- [ ] 登入 `dir1@test`，左側導航可見「課表回報管理」（若有 pending 會顯示 badge 數字）。
- [ ] 預設開啟「待處理」tab，列出分校 1 所有 pending 紀錄。
- [ ] 切換「處理中 / 已解決」tab，資料正確；未看到已歸檔資料。

### FR-007 狀態流程：pending → acknowledged
- [ ] 對任一筆點「標記已確認」→ toast「已標記為處理中」。
- [ ] 該筆立即從「待處理」list 移除，切到「處理中」tab 可看到。
- [ ] `schedule_discrepancies` 紀錄 `status=acknowledged`, `acknowledged_by=dir1`, `acknowledged_at=now`。

### FR-008 狀態流程：→ resolved 需處理說明 ≥ 10 字
- [ ] 展開任一筆 pending/acknowledged，填處理說明 < 10 字：按鈕 disabled，無法送出。
- [ ] 填寫 10 字以上後按鈕啟用；送出 → toast「已標記為已修正」。
- [ ] 若強制以空字串打 API → 回 422 `resolution_note` 錯誤訊息。

### FR-009 已結案的紀錄不可回頭修改
- [ ] 對 `resolved` 紀錄打 `PUT …/{id}` with `status=acknowledged` → 回 409 並附 `current_status`。

### FR-010 儀表板摘要卡片
- [ ] 主任首頁「課表回報」卡片顯示待處理數字。
- [ ] 待處理 > 0 時卡片邊框變警示色。
- [ ] 點卡片任一處進入管理頁且 tab=pending。
- [ ] 無 pending 時顯示「目前沒有未處理回報」提示文字。

### FR-011 老師可撤銷尚未確認的回報
- [ ] 老師端在自己送出的 pending 紀錄 Modal 中看到「撤銷回報」按鈕。
- [ ] 點擊 → 確認彈窗 → OK → toast「已撤銷」，徽章消失，主任端列表也同步消失。
- [ ] 主任若先按「標記已確認」，老師端再點撤銷 → 回 409「主任已確認，無法撤銷」。
- [ ] 另一位老師無法撤銷他人的紀錄（回 403）。

### 跨校／資安
- [ ] `dir2@test` 用 `branch_id=1` 打列表 API → 回 403。
- [ ] `dir2@test` 用 `branch_id=2` 打 summary → 不會看到分校 1 的紀錄計數。
- [ ] 老師在校 2，試圖用 `branch_id=1` 送出回報 → 403。
- [ ] 帶 `class_session_id` 屬於別校 → 403。

### 資料保留
- [ ] 執行 `php artisan schedule-discrepancies:archive`：超過 12 個月的 resolved/withdrawn 紀錄 `archived_at` 被填入。
- [ ] 管理頁與 summary 預設排除已封存紀錄。

---

## 三、UI/UX 驗收（第 5b 節）

### 3.1 回報 Modal
- [ ] Radio 以「大卡片」形式呈現（每卡 ≥44px 高，間距清楚）。
- [ ] 選取時邊框與背景變警示色。
- [ ] 備註框右下顯示 `x/200` 字數計數器；達上限時變紅。
- [ ] 手機版 Modal 從底部滑入（半全螢幕），底部按鈕全寬。
- [ ] 送出按鈕在送出中顯示 spinner 與「送出中…」字樣。

### 3.2 儀表板卡片
- [ ] 載入中顯示 skeleton loader（3 條灰階長條）。
- [ ] 成功載入後顯示具體數字；有 pending 時邊框左側警示色。
- [ ] 無 pending 時顯示「目前沒有未處理回報」小提示。
- [ ] 整張卡片可點擊，hover 有細微陰影。

### 3.3 管理頁
- [ ] Tabs 高度 ≥ 44px，active tab 底線顏色 primary。
- [ ] 空狀態有對應 Material Symbol icon（task_alt / hourglass / flag_circle）。
- [ ] 錯誤狀態顯示紅色圖示 + 重試按鈕。
- [ ] 行動裝置自動切換為卡片式排版（`@media (max-width:768px)`）。
- [ ] 處理說明字數計數器達 10 字時變綠色表示可送出。

### 3.4 Toast 通知
- [ ] 成功 toast 綠底 + check_circle icon，顯示 3 秒自動消失。
- [ ] 錯誤 toast 紅底 + error icon。
- [ ] 行動裝置 toast 佔 full-width（左右 12px margin）。

### 3.5 可及性／觸控
- [ ] 所有可互動元素 min-height ≥ 44px（WCAG 2.5.5 建議）。
- [ ] Radio / 按鈕有 `aria-label` 或可見文字標籤。
- [ ] Modal 使用 `role="dialog"` `aria-modal="true"`。
- [ ] 鍵盤：Esc 可關閉 Modal（若未送出中），Tab 可循環聚焦。

---

## 四、效能 (NFR)
- [ ] 送出回報 API p95 < 500ms（Chrome DevTools Network 量測 10 次）。
- [ ] 主任列表頁首次載入（50 筆）< 1.5s。
- [ ] LINE push 若失敗，重試最多 3 次，最終失敗寫入 `schedule_discrepancy.line_push_failed` log 但不影響 API 回應。

---

## 五、回歸/已知風險
- [ ] `AttendancePage.vue` 點名流程正常：切換日期、點名、請假、LINE 通知皆不受本功能影響。
- [ ] Super admin 跨分校切換仍可正常檢視、不會洩漏資料。
- [ ] 系統整體無 console error。
