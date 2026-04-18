# 課表出入回報系統 — Code Review

日期：2026-04-17
審查範圍：PR 變動之所有後端與前端程式碼。

---

## 聚焦 1：FR-003 重複回報守門 (duplicate guard)

**檔案**：`backend/app/Http/Controllers/ScheduleDiscrepancyController.php::store()` L.72–85

```php
$existing = ScheduleDiscrepancy::query()
    ->where('class_session_id', $classSessionId)
    ->whereIn('status', [STATUS_PENDING, STATUS_ACKNOWLEDGED])
    ->orderBy('id', 'desc')
    ->first();
if ($existing) {
    return response()->json(['duplicate' => true, 'existing' => ...], 200);
}
```

**審查意見**

- ✅ 狀態過濾正確：`pending` 與 `acknowledged` 視為「有效占位」，`resolved`/`withdrawn` 不在內，表示已結案者老師可再提新回報。符合 PRD FR-003 設計。
- ✅ `orderBy id desc` 取最新一筆，避免多筆孤兒資料造成誤判。
- ⚠️ _輕微競爭條件_：兩位老師「同時」對同一 session 送出 → 皆通過 select → 兩筆 insert。
  - **風險評級**：LOW — 同一堂次通常只有一位老師點名；且 UI 有樂觀回饋提示。
  - **緩解**：若實際觀察到雙寫，可加 unique composite index `(class_session_id, status)` where status IN ('pending','acknowledged')。現階段不必過度設計，已在殘餘風險清單。
- ✅ 僅在 `class_session_id` 非空時執行（missing_session 本就不 dedupe）。

**結論**：Approve。

---

## 聚焦 2：LINE Push 失敗降級

**檔案**：`backend/app/Services/ScheduleDiscrepancyNotifier.php`

**審查意見**

- ✅ 缺少 token 或 group_id：直接略過並記 info log，不拋例外。
- ✅ HTTP 呼叫包在 try/catch；非 2xx 視狀態碼決定是否重試（4xx 除 429 外不重試；5xx/429 退避重試最多 3 次）。
- ✅ 呼叫時機：`dispatch(...)->afterResponse()`，API 回應不受影響；`fireNotification` 再包一層 try/catch 防 dispatch 本身拋例外。
- ✅ 日誌分級：`info` 用於略過、`warning` 用於 4xx/exception、`error` 用於終極失敗。
- ✅ `timeout(8)` 明確超時，不會卡住 worker。
- ⚠️ _觀察項_：`sleep()` 在 afterResponse queue 會阻塞該 worker；最壞 1+2=3 秒。對單機 Pi 可接受。若未來改 queue worker，建議改用 `retry` + `sleep` 或 Laravel Queue retries。記於 PRD 第 7 節 NFR 風險。

**結論**：Approve。

---

## 聚焦 3：存取控制完整性

**檔案**：routes/api.php、Controller、Middleware

**審查意見**

- ✅ 所有端點經 `require_campus` + 角色 middleware。
  - 建／讀 /my／active-for-session／withdraw：`role:director,teacher`
  - list／summary／updateStatus：`role:director`（含 admin/super_admin）
- ✅ Controller 再次於 `resolveCampusScope()` 檢查 `branch_id` 是否在使用者 campus_ids 中，不符直接 `abort(403)`；防止 middleware 漏檢。
- ✅ `canAccessBranch()` 用於 `store()` 中同樣邏輯。
- ✅ `withdraw()` 除了 middleware 外，再檢查 `reporter_id === userId`，確保老師不能撤別人的。
- ✅ `updateStatus()` 重複檢查 `role in [director, admin, super_admin]`，defense-in-depth。
- ✅ `resolveUserId()` 來源只看 `auth_user` attribute（由 middleware 寫入），不接受 body。
- ⚠️ `activeForSession` 不驗證該 `class_session_id` 是否在 user 的 campus。
  - **風險**：理論上老師 A 可用 B 校的 session id 查回報內容（若巧合同 id）。
  - **實際影響**：回傳只有 discrepancy（備註、類型、狀態），不含家長/學生全量資料；且 user 需要先知道一個 valid session id。
  - **建議**：未來加一行 `branch_id` 檢查即可。
  - **目前處置**：列為 LOW 風險殘餘，未在此 PR 修正；可在下一輪迭代納入。

**結論**：Approve with follow-up note。

---

## 其他觀察

- `formatOne`/`formatListRow` 兩個格式化函式重覆但用途不同（一個吃 Model，一個吃 stdClass join row），非 DRY 但可讀性高，可接受。
- Model `ScheduleDiscrepancy` 只有常數與 fillable，業務邏輯放 Controller/Service 而非 Model，符合專案既有慣例。
- 前端 `scheduleDiscrepanciesApi.js` 共用 token 讀取、錯誤包裝邏輯與專案其他 API 客戶端一致。
- Vue 組件都做了送出中 disable、撤銷 confirm、10 字 guard，UI 層與後端 guard 雙保險。

---

## 最終結論

✅ **Approved for merge / deploy.** 已知 LOW-risk 殘餘項記錄於 PRD 風險表，不阻擋上線。
