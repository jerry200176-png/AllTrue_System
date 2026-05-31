# Bug Fix Plan — #142 §1 / GH#596：請假無法直接取消

> 來源：in-app bug #142（中平分校，super_admin 回報）§1；GitHub issue #596
> 類型：Bug Fix（走 `bug-fix-plan.mdc` 輕量流程）｜Risk Tier：T2（觸及 leave cascade / 順延，但有既存安全護欄）
> 日期：2026-05-31

---

## 0. 根因（Root Cause）

主任反映「請假之後沒有辦法直接取消」。實際追查：

- 取消請假唯一入口 `ScheduleController::undoLeave()`（line 426）有一個 **30 秒時間窗**：
  `LEAVE_UNDO_WINDOW_SECONDS = 30`（line 21），逾時回 `422 undo_window_expired`（line 451-457）。
- 這個 30 秒窗其實只是前端「請假後跳出 undo toast」的反悔窗口，**不是真正的「取消請假」功能**。一旦超過 30 秒，主任就再也沒有取消入口 → 完全符合回報症狀。

**關鍵發現（降低風險）**：真正的安全護欄在 `CourseLeaveCascadeService::undoLeaveCascade()`（line 252-263）：
若請假日之後已有 `attended/completed/late/present/absent/leave_adjusted` 的堂次，會丟出
「後續堂次已出現已上課/補請假等狀態，無法自動撤銷」。
→ **撤銷安全性由 cascade 服務的「下游堂次狀態」把關，與「距建立幾秒」無關**。因此 30 秒窗對 director 而言是多餘且有害的限制。

**前端根因已確認（2026-05-31）**：
- `CourseManagement.vue:2146-2160` — 請假成功後唯一的取消入口是 **暫時性 undo toast 按鈕**（`onUndo` → `undo-leave`），toast（約 30 秒）消失後**沒有任何常駐的「取消請假」入口**。
- `AttendancePage.vue:2070` — 經 `leave-by-session`（ClassSession-based）請假，回應**可能完全沒有 undo 路徑**。

→ 結論：#142 §1 是 **功能缺口（沒有常駐的取消請假入口）**，需 **前端 + 後端**：
1. 後端：放寬 `undoLeave` 30 秒窗（安全交給 cascade 下游護欄）；並提供能對 `leave-by-session` 型請假取消的路徑。
2. 前端：在課表/堂次詳情新增**常駐「取消請假」按鈕**（對 leave 狀態堂次顯示），呼叫取消端點。
→ Risk Tier 上修為 **T2（前後端契約）**，前端需瀏覽器驗證；非單行修復。

---

## 1. 文件資訊
- 影響模組：`backend/app/Http/Controllers/ScheduleController.php`（`undoLeave`）、`CourseLeaveCascadeService`
- 關聯：in-app #142、GH#596
- 對齊業界做法：取消應為**可逆狀態變更**而非時間窗 undo；還原時須重新驗證相依（重掛堂次、回復順延尾堂）。參考 Enterprise Health「reverse cancellation」、DayPilot UndoService（reversible state model）。

## 2. 目標 / KPI
- 主任在任何時間都能取消一筆「一般請假」，只要該請假之後沒有已上課/已處理的堂次。
- 取消後：請假堂次回復為 `scheduled`、自動順延的尾堂正確回收、`RemainingSessions`/end_date 還原（與現有 cascade 行為一致）。

## 3. 範圍
- **In**：放寬/移除 director（含 admin/super_admin）取消一般請假的 30 秒窗；確保 cascade 安全護欄為唯一守門；補回歸測試。
- **Out**：補請假（retro-leave）、補課（extra/makeup）取消（已有 `cancelMakeup`）、跨分校排課（§2）。

## 4. RACI / Dependencies
- DEV：後端。無前端契約變更（前端「取消請假」按鈕若僅在 30 秒內顯示，需同步放寬顯示條件 → 列為前端跟進子任務）。
- 依賴：`CourseLeaveCascadeService::undoLeaveCascade` 既有行為（不改）。

## 5. User Stories + AC
- US：身為主任，我在學生請假後（不論多久），只要後續還沒上課，就能取消這次請假並讓課表恢復原狀。
- **AC1**：對一筆 31 秒前建立的一般請假呼叫取消 → `200`，請假堂次回 `scheduled`，順延尾堂被回收。
- **AC2**：若請假日之後已有 `attended` 堂次 → 取消被拒，回明確訊息（沿用 cascade 例外）。
- **AC3**：非 leave 狀態 / 非 director 角色 / 跨分校 → 維持既有 403/422 行為。
- **AC4**（前端跟進）：「取消請假」操作入口不再因 30 秒過期而消失。

## 6. FR
- FR1：移除 `undoLeave` 中 `LEAVE_UNDO_WINDOW_SECONDS` 過期判斷（line 450-457），保留 role/campus/status 守門。
- FR2：取消的安全性完全交給 `undoLeaveCascade` 的下游鎖定堂次護欄。
- FR3（B1 後決定）：若 leave 儲存模型不一致，補一條以 ClassSession leave 為對象的取消路徑或統一查找。

## 7. NFR
- 交易內 `lockForUpdate`（既有），避免併發雙撤銷。

## 8. 技術方向（禁 code）
- 最小變更：刪除時間窗分支；常數可保留供前端 toast 用途（或標記僅 UI 用）。
- 不動 cascade 演算法，只改「誰能呼叫、何時能呼叫」。

## 8b. Decision Log
- **D1**：取消改為可逆狀態操作（業界對齊），安全性以「下游堂次是否已上課」判定，而非建立時間 → 採用。
- **D2**：不新增獨立 endpoint，沿用 `undoLeave`，降低前端契約面變更（除非 B1 發現儲存模型需另開路徑）。

## 9. 資安
- 維持 `role:director|admin|super_admin` + `require_campus` 守門；super_admin 不受 campus 限制（既有）。無新增 PII/公開端點。

## 10. QA 驗收（PHPUnit，CI 跑）
- `LeaveUndoBeyondWindowTest`：AC1 / AC2 / AC3 三案。Factory 建課 + 堂次（注意 Y1 NOT NULL、Y2 未來堂次 23:00）。

## 11. 上線維運
- 後端 only（除前端按鈕顯示條件跟進）。merge 後 `deploy.yml` 自動部署；smoke：建一般請假 → 隔 >30s → 取消 → 課表回復。

## 12. 優先級
- P1（主任日常操作被卡，無 workaround；但非全站故障）。

## 13. 風險（已 WebSearch）
- R1：移除時間窗後，使用者誤取消較舊請假 → 緩解：cascade 下游護欄 + 取消為可逆（可重新請假）。
- R2：leave 儲存模型不一致導致部分請假仍無法取消 → B1 先確認，必要時 FR3。
- R3：順延尾堂回收邏輯對「舊請假」是否成立 → 由 `undoLeaveCascade` 既有護欄與測試覆蓋驗證。

## 14. DoD（AI 可驗證）
- [ ] B1 確認 leave 儲存模型涵蓋面
- [ ] 新增失敗測試（RED，證明逾窗無法取消）
- [ ] 移除時間窗後測試 GREEN（AC1-3）
- [ ] CI 全綠
- [ ] 前端「取消請假」顯示條件跟進子任務（或另開 issue）
- [ ] PR merge → deploy → smoke
- [ ] in-app #142 §1 + GH#596 回寫；`CHANGELOG` + 必要時 `AI_REGRESSION_LESSONS`
